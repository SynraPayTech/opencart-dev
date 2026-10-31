<?php
namespace Opencart\Catalog\Model\Extension\SynraPay\Payment;

class SynraPay extends \Opencart\System\Engine\Model
{
    /** Offered at checkout? Same rules as the stock extensions (geo zone), plus TLS on Live. */
    public function getMethods(array $address = []): array
    {
        $this->load->language('extension/synra_pay/payment/synra_pay');

        if (!$this->config->get('payment_synra_pay_status')) {
            return [];
        }
        if ((string) $this->config->get('payment_synra_pay_merchant_id') === '' || (string) $this->config->get('payment_synra_pay_api_key') === '') {
            return [];
        }
        // Card data must travel over TLS: no card form on a plain-http Live store.
        if ($this->config->get('payment_synra_pay_environment') === 'live' && strpos((string) $this->config->get('config_url'), 'https://') !== 0) {
            return [];
        }
        if ($this->cart->hasSubscription()) {
            return [];
        }

        $status = true;
        $geo_zone_id = (int) $this->config->get('payment_synra_pay_geo_zone_id');
        if ($geo_zone_id && $this->config->get('config_checkout_payment_address')) {
            $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "zone_to_geo_zone` WHERE `geo_zone_id` = '" . $geo_zone_id . "' AND `country_id` = '" . (int) ($address['country_id'] ?? 0) . "' AND (`zone_id` = '" . (int) ($address['zone_id'] ?? 0) . "' OR `zone_id` = '0')");
            $status = $query->num_rows > 0;
        }
        if (!$status) {
            return [];
        }

        $option_data['synra_pay'] = [
            'code' => 'synra_pay.synra_pay',
            'name' => $this->language->get('text_title'),
        ];
        return [
            'code'       => 'synra_pay',
            'name'       => $this->language->get('text_title'),
            'option'     => $option_data,
            'sort_order' => $this->config->get('payment_synra_pay_sort_order'),
        ];
    }

    /* ── the link between an OpenCart order and its SynraPay Direct order ─────── */

    public function getLink(int $order_id): array
    {
        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "synra_pay_order` WHERE `order_id` = '" . $order_id . "'");
        return $query->num_rows ? $query->row : [];
    }

    /** By SynraPay's order_id or our merchant_order_id — the current one, or a superseded retry kept in history. */
    public function getLinkByRef(string $ref): array
    {
        $ref = $this->db->escape($ref);
        if ($ref === '') {
            return [];
        }
        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "synra_pay_order` WHERE `direct_order_id` = '" . $ref . "' OR `merchant_order_id` = '" . $ref . "' ORDER BY `date_modified` DESC LIMIT 1");
        if ($query->num_rows) {
            return $query->row;
        }
        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "synra_pay_order` WHERE `history` LIKE '%" . $ref . "%' ORDER BY `date_modified` DESC LIMIT 1");
        return $query->num_rows ? $query->row : [];
    }

    public function saveLink(int $order_id, array $data): void
    {
        $existing = $this->getLink($order_id);
        $cols = [];
        foreach (['direct_order_id', 'merchant_order_id', 'currency', 'fp', 'env', 'status', 'gateway_ref', 'history'] as $k) {
            if (array_key_exists($k, $data)) {
                $cols[] = "`" . $k . "` = '" . $this->db->escape((string) $data[$k]) . "'";
            }
        }
        if (array_key_exists('amount', $data)) {
            $cols[] = "`amount` = '" . (float) $data['amount'] . "'";
        }
        foreach (['attempts', 'checks'] as $k) {
            if (array_key_exists($k, $data)) {
                $cols[] = "`" . $k . "` = '" . (int) $data[$k] . "'";
            }
        }
        if (array_key_exists('awaiting_since', $data)) {
            $cols[] = "`awaiting_since` = " . ($data['awaiting_since'] === null ? 'NULL' : "'" . $this->db->escape((string) $data['awaiting_since']) . "'");
        }
        $cols[] = "`date_modified` = NOW()";
        if ($existing) {
            $this->db->query("UPDATE `" . DB_PREFIX . "synra_pay_order` SET " . implode(', ', $cols) . " WHERE `order_id` = '" . $order_id . "'");
        } else {
            $this->db->query("INSERT INTO `" . DB_PREFIX . "synra_pay_order` SET `order_id` = '" . $order_id . "', " . implode(', ', $cols) . ", `date_added` = NOW()");
        }
    }

    /** Orders the customer left while SynraPay was still settling: between 1 minute and 3 hours old. */
    public function getAwaiting(): array
    {
        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "synra_pay_order` WHERE `status` = 'pending_confirmation' AND `awaiting_since` IS NOT NULL AND `awaiting_since` < DATE_SUB(NOW(), INTERVAL 1 MINUTE) AND `awaiting_since` > DATE_SUB(NOW(), INTERVAL 3 HOUR) ORDER BY `awaiting_since` ASC LIMIT 50");
        return $query->rows;
    }
}
