<?php
namespace Opencart\Admin\Controller\Extension\SynraPay\Payment;

/**
 * SynraPay for OpenCart 4 — admin side: the settings form, install / uninstall.
 * Settings live under the code `payment_synra_pay` (OpenCart convention).
 */
class SynraPay extends \Opencart\System\Engine\Controller
{
    const ROUTE = 'extension/synra_pay/payment/synra_pay';

    public function index(): void
    {
        $this->load->language(self::ROUTE);
        $this->document->setTitle($this->language->get('heading_title'));

        // Files replaced through Extensions → Installer do not re-run install(): bring an older
        // link table up to date here (adds missing columns only). Never blocks the settings page.
        try {
            $this->load->model(self::ROUTE);
            $this->model_extension_synra_pay_payment_synra_pay->upgrade();
        } catch (\Throwable $e) {
            $this->log->write('SynraPay: link table upgrade skipped: ' . $e->getMessage());
        }

        $data['breadcrumbs'] = [];
        $data['breadcrumbs'][] = [
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token']),
        ];
        $data['breadcrumbs'][] = [
            'text' => $this->language->get('text_extension'),
            'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment'),
        ];
        $data['breadcrumbs'][] = [
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link(self::ROUTE, 'user_token=' . $this->session->data['user_token']),
        ];

        $data['save'] = $this->url->link(self::ROUTE . '.save', 'user_token=' . $this->session->data['user_token']);
        $data['back'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment');

        foreach (['status', 'sort_order', 'geo_zone_id', 'environment', 'merchant_id', 'fallback_phone', 'x_api_key_name', 'order_status_id', 'pending_status_id', 'failed_status_id'] as $k) {
            $data['payment_synra_pay_' . $k] = $this->config->get('payment_synra_pay_' . $k);
        }
        if ($data['payment_synra_pay_environment'] === null || $data['payment_synra_pay_environment'] === '') {
            $data['payment_synra_pay_environment'] = 'develop';
        }
        if ($data['payment_synra_pay_x_api_key_name'] === null) {
            $data['payment_synra_pay_x_api_key_name'] = 'x-synrapay-key';
        }
        if ($data['payment_synra_pay_fallback_phone'] === null) {
            $data['payment_synra_pay_fallback_phone'] = '971500000000';
        }
        // Secrets are never echoed back; the form shows whether one is saved.
        $data['api_key_set']     = (string) $this->config->get('payment_synra_pay_api_key') !== '';
        $data['webhook_key_set'] = (string) $this->config->get('payment_synra_pay_webhook_key') !== '';
        $data['x_api_key_set']   = (string) $this->config->get('payment_synra_pay_x_api_key') !== '';

        $this->load->model('localisation/order_status');
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

        $this->load->model('localisation/geo_zone');
        $data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

        $token = (string) $this->config->get('payment_synra_pay_webhook_token');
        $catalog = defined('HTTP_CATALOG') ? HTTP_CATALOG : $this->config->get('config_url');
        $data['webhook_url'] = rtrim((string) $catalog, '/') . '/index.php?route=' . self::ROUTE . '.webhook&token=' . $token;
        $parts = parse_url((string) $catalog);
        $data['origin'] = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (!empty($parts['port']) ? ':' . (int) $parts['port'] : '');

        $data['header']      = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer']      = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view(self::ROUTE, $data));
    }

    public function save(): void
    {
        $this->load->language(self::ROUTE);

        $json = [];
        if (!$this->user->hasPermission('modify', self::ROUTE)) {
            $json['error']['warning'] = $this->language->get('error_permission');
        }

        $post = $this->request->post;
        if (empty($json)) {
            if (trim((string) ($post['payment_synra_pay_merchant_id'] ?? '')) === '') {
                $json['error']['merchant_id'] = $this->language->get('error_merchant_id');
            }
            // Blank secret fields keep what is saved (editSetting replaces every row of the code).
            foreach (['api_key', 'webhook_key', 'x_api_key'] as $secret) {
                if (trim((string) ($post['payment_synra_pay_' . $secret] ?? '')) === '') {
                    $post['payment_synra_pay_' . $secret] = (string) $this->config->get('payment_synra_pay_' . $secret);
                } else {
                    $post['payment_synra_pay_' . $secret] = trim((string) $post['payment_synra_pay_' . $secret]);
                }
            }
            if ($post['payment_synra_pay_api_key'] === '') {
                $json['error']['api_key'] = $this->language->get('error_api_key');
            }
            $phone = preg_replace('/\D/', '', (string) ($post['payment_synra_pay_fallback_phone'] ?? ''));
            if ($phone !== '' && (strlen($phone) < 8 || strlen($phone) > 15)) {
                $json['error']['fallback_phone'] = $this->language->get('error_fallback_phone');
            }
            $post['payment_synra_pay_fallback_phone'] = $phone;
            $post['payment_synra_pay_merchant_id'] = trim((string) $post['payment_synra_pay_merchant_id']);
            $post['payment_synra_pay_environment'] = in_array($post['payment_synra_pay_environment'] ?? '', ['develop', 'sandbox', 'live'], true) ? $post['payment_synra_pay_environment'] : 'develop';
            $post['payment_synra_pay_webhook_token'] = (string) $this->config->get('payment_synra_pay_webhook_token') ?: $this->newToken();
        }

        if (empty($json)) {
            $this->load->model('setting/setting');
            $this->model_setting_setting->editSetting('payment_synra_pay', $post);
            $json['success'] = $this->language->get('text_success');
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    public function install(): void
    {
        if (!$this->user->hasPermission('modify', 'extension/payment')) {
            return;
        }
        $this->load->model(self::ROUTE);
        $this->model_extension_synra_pay_payment_synra_pay->install();

        // A per-store webhook token, generated once; the webhook URL carries it.
        if ((string) $this->config->get('payment_synra_pay_webhook_token') === '') {
            $this->load->model('setting/setting');
            $this->model_setting_setting->editSetting('payment_synra_pay', [
                'payment_synra_pay_webhook_token'  => $this->newToken(),
                'payment_synra_pay_environment'    => 'develop',
                'payment_synra_pay_x_api_key_name' => 'x-synrapay-key',
                'payment_synra_pay_fallback_phone' => '971500000000',
                'payment_synra_pay_status'         => 0,
            ]);
        }

        // Hourly re-check of orders left "awaiting confirmation" (the webhook is the primary path).
        $this->load->model('setting/cron');
        if (method_exists($this->model_setting_cron, 'addCron') && !$this->model_setting_cron->getCronByCode('synra_pay')) {
            $this->model_setting_cron->addCron('synra_pay', 'SynraPay: re-check card payments awaiting confirmation', 'hour', self::ROUTE . '.recheck', true);
        }
    }

    public function uninstall(): void
    {
        if (!$this->user->hasPermission('modify', 'extension/payment')) {
            return;
        }
        $this->load->model(self::ROUTE);
        $this->model_extension_synra_pay_payment_synra_pay->uninstall();

        $this->load->model('setting/cron');
        if (method_exists($this->model_setting_cron, 'deleteCronByCode')) {
            $this->model_setting_cron->deleteCronByCode('synra_pay');
        }
    }

    private function newToken(): string
    {
        return bin2hex(random_bytes(16));
    }
}
