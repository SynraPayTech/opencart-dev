<?php
namespace Opencart\Catalog\Controller\Extension\SynraPay\Payment;

/**
 * SynraPay for OpenCart 4 — hosted session (SynraPay's card fields on the
 * confirm step). Same logic as the WooCommerce plugin v2.1 / Shopify / Wix apps:
 *
 *   index()    the block injected into #checkout-payment (twig + inline JS)
 *   session()  Direct API create-order for the session's order (+ reuse) and
 *              hosted-session with origin = this store → clientToken
 *   confirm()  GET /direct/payment/{order_id}/status decides:
 *                CAPTURED/AUTHORIZED (no awaiting_mastercard) → paid status → checkout/success
 *                FAILED/… → the OpenCart order stays unconfirmed, the page opens a new Direct order
 *                frame said ok, SynraPay not final → awaiting-confirmation status → checkout/success,
 *                                                     settled by webhook() or recheck()
 *   webhook()  SynraPay's signed result (source of truth, idempotent)
 *   recheck()  cron action (hourly): orders left awaiting confirmation
 *
 * The card is typed into SynraPay's frame; this store never receives it. Only
 * the ORDER's status read here moves an OpenCart order; nothing the browser
 * says about the outcome is trusted.
 */
class SynraPay extends \Opencart\System\Engine\Controller
{
    const ROUTE = 'extension/synra_pay/payment/synra_pay';

    public function index(): string
    {
        $this->load->language(self::ROUTE);

        $amount_text = '';
        if (isset($this->session->data['order_id'])) {
            $this->load->model('checkout/order');
            $order_info = $this->model_checkout_order->getOrder((int) $this->session->data['order_id']);
            if ($order_info) {
                $amount_text = $this->currency->format($order_info['total'], $order_info['currency_code'], $order_info['currency_value']);
            }
        }

        $env  = \SynraPayDirectClient::normEnv((string) $this->config->get('payment_synra_pay_environment'));
        $data = [
            'test'        => $env !== 'live',
            'button_pay'  => sprintf($this->language->get('button_pay'), $amount_text),
            'config_json' => json_encode([
                'sessionUrl' => $this->url->link(self::ROUTE . '.session', 'language=' . $this->config->get('config_language'), true),
                'confirmUrl' => $this->url->link(self::ROUTE . '.confirm', 'language=' . $this->config->get('config_language'), true),
                'sdkUrl'     => \SynraPayDirectClient::ENVS[$env]['sdk'],
                'i18n'       => [
                    'processing' => $this->language->get('text_processing'),
                    'confirming' => $this->language->get('text_confirming'),
                    'notReady'   => $this->language->get('text_not_ready'),
                    'incomplete' => $this->language->get('text_incomplete'),
                    'declined'   => $this->language->get('text_declined'),
                    'expired'    => $this->language->get('text_expired'),
                    'sdkFailed'  => $this->language->get('text_sdk_failed'),
                ],
            ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES),
        ];
        return $this->load->view(self::ROUTE, $data);
    }

    /* ── .session — the checkout page asks for card fields ─────────────────── */

    public function session(): void
    {
        $this->load->language(self::ROUTE);
        if (!$this->guard('session', 30)) {
            return;
        }

        $order_info = $this->currentOrder();
        if (!$order_info) {
            return;
        }
        $order_id = (int) $order_info['order_id'];

        $this->load->model(self::ROUTE);
        $link = $this->model_extension_synra_pay_payment_synra_pay->getLink($order_id);

        // Already placed (paid, or awaiting SynraPay's confirmation): straight to the success page.
        if ((int) $order_info['order_status_id'] !== 0) {
            if ($link && in_array($link['status'], ['paid', 'pending_confirmation'], true)) {
                $this->json(['ok' => false, 'error' => $this->language->get('error_already'), 'redirect' => $this->successUrl()], 409);
            } else {
                $this->json(['ok' => false, 'error' => $this->language->get('error_already')], 409);
            }
            return;
        }

        $client = $this->client();
        if (!$client->isConfigured()) {
            $this->json(['ok' => false, 'error' => $this->language->get('error_not_configured')], 500);
            return;
        }

        $customer = $this->customerPayload($order_info);
        if (is_string($customer)) {
            $this->json(['ok' => false, 'error' => $customer], 400);
            return;
        }
        list($amount, $currency) = $this->orderAmount($order_info);
        if ((float) $amount <= 0) {
            $this->json(['ok' => false, 'error' => $this->language->get('error_amount')], 400);
            return;
        }
        if (($link['attempts'] ?? 0) >= 8) {
            $this->json(['ok' => false, 'error' => $this->language->get('error_rate')], 429);
            return;
        }

        $fresh    = !empty($this->request->post['fresh']);
        $phone_fp = $customer['phone'] ?? ($customer['phoneCountryCode'] . $customer['phoneNumber']);
        $fp       = md5(implode('|', [$amount, $currency, $customer['email'], $phone_fp, $client->env()]));

        $direct_order_id   = $link['direct_order_id'] ?? '';
        $merchant_order_id = $link['merchant_order_id'] ?? '';
        $reusable = !$fresh && $direct_order_id !== '' && ($link['fp'] ?? '') === $fp && ($link['status'] ?? '') === 'created'
            && (time() - strtotime($link['date_modified'])) < 1500;

        if (!$reusable) {
            $merchant_order_id = $this->merchantOrderId($order_id);
            $store = trim(strip_tags(html_entity_decode((string) $this->config->get('config_name'), ENT_QUOTES, 'UTF-8')));
            $description = preg_replace('/[\x00-\x1F\x7F<>]/u', '', ($store !== '' ? $store : 'OpenCart') . ' order #' . $order_id);
            list($ok, $data, $err) = $client->createOrder([
                'merchant_order_id' => $merchant_order_id,
                'amount'            => $amount,
                'currency'          => $currency,
                'description'       => mb_substr($description, 0, 255),
                'customer'          => $customer,
            ]);
            if (!$ok || empty($data['order_id'])) {
                $this->log->write('SynraPay: create-order failed for order #' . $order_id . ': ' . ($ok ? 'no order_id' : $err));
                $this->json(['ok' => false, 'error' => $ok ? 'No order id returned.' : $err], 502);
                return;
            }
            $history = $link ? trim(($link['history'] ?? '') . ' ' . $link['direct_order_id'] . ' ' . $link['merchant_order_id']) : '';
            $direct_order_id = (string) $data['order_id'];
            if (!empty($data['merchant_order_id'])) {
                $merchant_order_id = (string) $data['merchant_order_id'];
            }
            $this->model_extension_synra_pay_payment_synra_pay->saveLink($order_id, [
                'direct_order_id'   => $direct_order_id,
                'merchant_order_id' => $merchant_order_id,
                'amount'            => $amount,
                'currency'          => $currency,
                'fp'                => $fp,
                'env'               => $client->env(),
                'status'            => 'created',
                'synrapay_transaction_id' => '',
                'history'           => mb_substr($history, -2000),
                'attempts'          => (int) ($link['attempts'] ?? 0) + 1,
            ]);
        }

        list($ok, $data, $err) = $client->hostedSession($direct_order_id, $this->requestOrigin());
        if (!$ok || empty($data['client_token'])) {
            // The order may be closed (paid, failed, expired): forget its fingerprint so the next call starts a new one.
            $this->model_extension_synra_pay_payment_synra_pay->saveLink($order_id, ['fp' => '']);
            $this->log->write('SynraPay: hosted-session failed on ' . $direct_order_id . ': ' . ($ok ? 'no client_token' : $err));
            $this->json(['ok' => false, 'error' => $ok ? 'No client token returned.' : $err, 'retry' => !$fresh], 502);
            return;
        }
        // The client token says which environment minted it (hs_live_ / hs_test_ / hs_dev_). It must be
        // the environment this store is set to, or Sandbox approvals would settle Live orders.
        $token_env = \SynraPayDirectClient::tokenEnv((string) $data['client_token']);
        if ($token_env !== '' && $token_env !== $client->env()) {
            $this->model_extension_synra_pay_payment_synra_pay->saveLink($order_id, ['fp' => '']);
            $this->log->write('SynraPay: ENVIRONMENT_MISMATCH — token is ' . $token_env . ' but the store is set to ' . $client->env());
            $this->json(['ok' => false, 'error' => $this->language->get('error_env')], 409);
            return;
        }

        $this->json([
            'ok'              => true,
            'clientToken'     => $data['client_token'],
            'sdkUrl'          => !empty($data['sdk_url']) ? $data['sdk_url'] : $client->sdkUrl(),
            'expiresAt'       => $data['expires_at'] ?? null,
            'sessionId'       => $data['session_id'] ?? null,
            'orderId'         => $direct_order_id,
            'merchantOrderId' => $merchant_order_id,
            'amount'          => $amount,
            'currency'        => $currency,
        ]);
    }

    /* ── .confirm — after fields.submit(), is the ORDER paid? ──────────────── */

    public function confirm(): void
    {
        $this->load->language(self::ROUTE);
        if (!$this->guard('confirm', 300)) {
            return;
        }

        $order_info = $this->currentOrder();
        if (!$order_info) {
            return;
        }
        $order_id = (int) $order_info['order_id'];

        $this->load->model(self::ROUTE);
        $link = $this->model_extension_synra_pay_payment_synra_pay->getLink($order_id);
        if (!$link || $link['direct_order_id'] === '') {
            $this->json(['ok' => false, 'status' => 'error', 'error' => $this->language->get('error_no_session')], 400);
            return;
        }
        if (in_array($link['status'], ['paid', 'pending_confirmation'], true)) {
            $this->json(['ok' => true, 'status' => 'paid', 'redirect' => $this->successUrl()]);
            return;
        }

        // frame_ok is a hint from the browser ("the frame said approved"). It only shortens the status
        // timeout and is honoured below ONLY when SynraPay's own status read shows a payment was attempted.
        $frame_ok = !empty($this->request->post['frame_ok']);
        list($ok, $data, $err) = $this->client()->status($link['direct_order_id'], $frame_ok ? 6 : 45);
        if (!$ok || !is_array($data)) {
            $this->json(['ok' => false, 'status' => 'pending', 'error' => $frame_ok ? '' : $err]);
            return;
        }
        $verdict = \SynraPayDirectClient::classify($data);
        $this->log->write('SynraPay: confirm #' . $order_id . ' → ' . ($data['status'] ?? '?') . (isset($data['confirmation']) ? ' (' . $data['confirmation'] . ')' : '') . ($frame_ok ? ' frame:ok' : ''));

        if ($verdict === 'paid') {
            if (!$this->markPaid($order_id, $order_info, $link, $data)) {
                $this->json(['ok' => false, 'status' => 'error', 'error' => $this->language->get('error_amount_mismatch')]);
                return;
            }
            $this->json(['ok' => true, 'status' => 'paid', 'redirect' => $this->successUrl()]);
            return;
        }
        if ($verdict === 'failed') {
            $reason = $data['error_message'] ?? ($data['reason'] ?? strtolower((string) $data['status']));
            // The customer is still on the page: the OpenCart order stays unconfirmed and the next
            // session() call opens a NEW Direct order for the retry.
            $this->model_extension_synra_pay_payment_synra_pay->saveLink($order_id, ['status' => 'failed']);
            $this->json(['ok' => false, 'status' => 'failed', 'error' => $reason]);
            return;
        }
        if ($frame_ok && \SynraPayDirectClient::evidence($data)) {
            // Approved at the gateway, final settlement pending at SynraPay (PENDING, or CAPTURED still
            // awaiting Mastercard's notification): do not keep the customer waiting. The order is placed
            // with the awaiting-confirmation status; webhook() or recheck() finish it.
            $this->load->model('checkout/order');
            $pending_status = (int) $this->config->get('payment_synra_pay_pending_status_id');
            if ($pending_status) {
                $this->model_checkout_order->addHistory($order_id, $pending_status, $this->language->get('text_pending_history'), false);
            }
            $this->model_extension_synra_pay_payment_synra_pay->saveLink($order_id, ['status' => 'pending_confirmation', 'awaiting_since' => date('Y-m-d H:i:s'), 'checks' => 0]);
            $this->json(['ok' => true, 'status' => 'paid', 'redirect' => $this->successUrl()]);
            return;
        }
        $this->json(['ok' => false, 'status' => 'pending']);
    }

    /* ── .webhook — SynraPay → store, signed; source of truth ───────────────── */

    public function webhook(): void
    {
        $this->load->language(self::ROUTE);
        $token = (string) ($this->request->get['token'] ?? '');
        $want  = (string) $this->config->get('payment_synra_pay_webhook_token');
        if ($want === '' || $token === '' || !hash_equals($want, $token)) {
            $this->plain(404, 'not found');
            return;
        }
        $raw  = (string) file_get_contents('php://input');
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            $this->plain(400, 'invalid payload');
            return;
        }
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        if (!$headers) {
            foreach ($this->request->server as $k => $v) {
                if (strpos($k, 'HTTP_') === 0) {
                    $headers[str_replace('_', '-', substr($k, 5))] = $v;
                }
            }
        }
        if (!\SynraPayDirectClient::verifyWebhook($raw, $headers, (string) $this->config->get('payment_synra_pay_webhook_key'), (string) $this->config->get('payment_synra_pay_x_api_key_name'), (string) $this->config->get('payment_synra_pay_x_api_key'))) {
            $this->log->write('SynraPay: webhook refused — signature did not verify');
            $this->plain(401, 'invalid signature');
            return;
        }

        $this->load->model(self::ROUTE);
        $link = [];
        foreach ([$body['transactionId'] ?? '', $body['order_id'] ?? '', $body['orderId'] ?? '', $body['merchant_order_id'] ?? ''] as $ref) {
            if ($ref !== '' && !$link) {
                $link = $this->model_extension_synra_pay_payment_synra_pay->getLinkByRef((string) $ref);
            }
        }
        if (!$link) {
            $this->plain(200, 'ok (unknown order)');
            return;
        }
        // A webhook about a superseded Direct order (an earlier declined try) must not settle the current one.
        $refs = [$body['transactionId'] ?? '', $body['order_id'] ?? '', $body['orderId'] ?? '', $body['merchant_order_id'] ?? ''];
        if (!in_array($link['direct_order_id'], $refs, true) && !in_array($link['merchant_order_id'], $refs, true)) {
            $this->plain(200, 'ok (superseded order)');
            return;
        }
        if ($link['status'] === 'paid') {
            $this->plain(200, 'ok');
            return;
        }
        $this->load->model('checkout/order');
        $order_info = $this->model_checkout_order->getOrder((int) $link['order_id']);
        if (!$order_info) {
            $this->plain(200, 'ok (order gone)');
            return;
        }
        $status   = strtolower((string) ($body['status'] ?? ''));
        $awaiting = isset($body['confirmation']) && $body['confirmation'] === 'awaiting_mastercard';
        if (!$awaiting && in_array($status, ['success', 'captured', 'authorized', 'paid'], true)) {
            // The webhook's transactionId IS the dashboard Transaction ID (one transaction id, equal to
            // the Direct order_id): no status read is needed for it. The signed body decides.
            $txn_from_body = trim((string) ($body['transactionId'] ?? ''));
            $this->markPaid((int) $link['order_id'], $order_info, $link, [
                'order_id'           => $link['direct_order_id'],
                'transaction_id'     => $txn_from_body !== '' ? $txn_from_body : (string) $link['direct_order_id'],
                'status'             => $status,
                'amount'             => $body['amount'] ?? null,
                'currency'           => $body['currency'] ?? null,
                'authorization_code' => $body['authorization_code'] ?? ($body['auth_code'] ?? ''),
            ]);
        } elseif (!$awaiting && in_array($status, ['failed', 'cancelled', 'canceled', 'expired', 'declined'], true)) {
            $this->markFailed((int) $link['order_id'], $link, $status, (string) ($body['error_message'] ?? ''));
        }
        $this->plain(200, 'ok');
    }

    /* ── .recheck — cron: orders left awaiting confirmation ─────────────────── */

    public function recheck(): void
    {
        $this->load->language(self::ROUTE);
        $this->load->model(self::ROUTE);
        $this->load->model('checkout/order');
        $client = $this->client();
        if (!$client->isConfigured()) {
            return;
        }
        foreach ($this->model_extension_synra_pay_payment_synra_pay->getAwaiting() as $link) {
            $order_info = $this->model_checkout_order->getOrder((int) $link['order_id']);
            if (!$order_info) {
                continue;
            }
            list($ok, $data) = $client->status($link['direct_order_id'], 30);
            $this->model_extension_synra_pay_payment_synra_pay->saveLink((int) $link['order_id'], ['checks' => (int) $link['checks'] + 1]);
            if (!$ok || !is_array($data)) {
                continue;
            }
            $verdict = \SynraPayDirectClient::classify($data);
            $this->log->write('SynraPay: recheck #' . $link['order_id'] . ' → ' . ($data['status'] ?? '?') . ' ' . $verdict);
            if ($verdict === 'paid') {
                $this->markPaid((int) $link['order_id'], $order_info, $link, $data);
            } elseif ($verdict === 'failed' || (int) $link['checks'] >= 3) {
                $this->markFailed((int) $link['order_id'], $link, $verdict === 'failed' ? strtolower((string) $data['status']) : 'not confirmed', (string) ($data['error_message'] ?? ''));
            }
        }
    }

    /* ── settling ────────────────────────────────────────────────────────────── */

    /** Paid at SynraPay: record it once. Returns false (and holds the order) on an amount mismatch. */
    private function markPaid(int $order_id, array $order_info, array $link, array $data): bool
    {
        $this->load->model('checkout/order');
        $this->load->model(self::ROUTE);
        if ($link['status'] === 'paid') {
            return true;
        }
        list($total, $currency) = $this->orderAmount($order_info);
        $paid_amount = isset($data['amount']) && $data['amount'] !== null ? number_format(round((float) $data['amount'], 2), 2, '.', '') : null;
        $paid_ccy    = !empty($data['currency']) ? strtoupper((string) $data['currency']) : null;
        if ($paid_amount !== null && ($paid_amount !== $total || ($paid_ccy && $paid_ccy !== $currency))) {
            $pending_status = (int) $this->config->get('payment_synra_pay_pending_status_id');
            $this->model_checkout_order->addHistory($order_id, $pending_status ?: (int) $order_info['order_status_id'], sprintf($this->language->get('text_mismatch_history'), $paid_ccy, $paid_amount, $currency, $total), false);
            $this->model_extension_synra_pay_payment_synra_pay->saveLink($order_id, ['status' => 'mismatch']);
            $this->log->write('SynraPay: amount mismatch on #' . $order_id . ': paid ' . $paid_ccy . ' ' . $paid_amount . ' vs ' . $currency . ' ' . $total);
            return false;
        }
        // The SynraPay merchant dashboard's Transaction ID = transaction_id (else the Direct order_id;
        // one transaction id, the same value). The Direct order_id stays in the link table as the key
        // for API calls. No gateway reference is stored or shown: it is internal to SynraPay.
        $txn_id    = \SynraPayDirectClient::transactionRef($data, (string) $link['direct_order_id']);
        $auth_code = \SynraPayDirectClient::authCode($data);
        $none      = $this->language->get('text_not_available');
        $comment = sprintf(
            $this->language->get('text_paid_history'),
            strtolower((string) ($data['status'] ?? 'captured')),
            $link['merchant_order_id'] !== '' ? $link['merchant_order_id'] : $none,
            $txn_id !== '' ? $txn_id : $none
        );
        if ($auth_code !== '') {
            $comment .= sprintf($this->language->get('text_paid_history_auth'), $auth_code);
        }
        $this->model_checkout_order->addHistory($order_id, (int) $this->config->get('payment_synra_pay_order_status_id'), $comment, true);
        if (method_exists($this->model_checkout_order, 'editTransactionId')) {
            $this->model_checkout_order->editTransactionId($order_id, $txn_id);
        }
        $this->model_extension_synra_pay_payment_synra_pay->saveLink($order_id, [
            'status'                  => 'paid',
            'synrapay_transaction_id' => mb_substr($txn_id, 0, 64),
            'auth_code'               => mb_substr($auth_code, 0, 32),
            'awaiting_since'          => null,
        ]);
        return true;
    }

    /** Final decline. Only an order already placed (awaiting confirmation) changes status; an unpaid one stays unconfirmed. */
    private function markFailed(int $order_id, array $link, string $status, string $reason): void
    {
        $this->load->model('checkout/order');
        $this->load->model(self::ROUTE);
        if ($link['status'] === 'pending_confirmation') {
            $failed_status = (int) $this->config->get('payment_synra_pay_failed_status_id');
            if ($failed_status) {
                $this->model_checkout_order->addHistory($order_id, $failed_status, sprintf($this->language->get('text_failed_history'), $status, $reason !== '' ? $reason : $status), false);
            }
        }
        $this->model_extension_synra_pay_payment_synra_pay->saveLink($order_id, ['status' => 'failed', 'awaiting_since' => null]);
    }

    /* ── helpers ─────────────────────────────────────────────────────────────── */

    private function client(): \SynraPayDirectClient
    {
        return new \SynraPayDirectClient(
            (string) $this->config->get('payment_synra_pay_environment'),
            (string) $this->config->get('payment_synra_pay_merchant_id'),
            (string) $this->config->get('payment_synra_pay_api_key')
        );
    }

    /** The order this checkout session is paying for, or null after answering the error. */
    private function currentOrder(): ?array
    {
        if (!isset($this->session->data['order_id'])) {
            $this->json(['ok' => false, 'status' => 'error', 'error' => $this->language->get('error_order')], 400);
            return null;
        }
        if (!isset($this->session->data['payment_method']['code']) || $this->session->data['payment_method']['code'] !== 'synra_pay.synra_pay') {
            $this->json(['ok' => false, 'status' => 'error', 'error' => $this->language->get('error_payment_method')], 400);
            return null;
        }
        $this->load->model('checkout/order');
        $order_info = $this->model_checkout_order->getOrder((int) $this->session->data['order_id']);
        if (!$order_info) {
            $this->json(['ok' => false, 'status' => 'error', 'error' => $this->language->get('error_order')], 404);
            return null;
        }
        return $order_info;
    }

    /** @return array [amount(string, 2 dp, in the order's currency), currency] */
    private function orderAmount(array $order_info): array
    {
        $amount = round((float) $order_info['total'] * (float) $order_info['currency_value'], 2);
        return [number_format($amount, 2, '.', ''), strtoupper((string) $order_info['currency_code'])];
    }

    /** The customer in the Direct API's shape (email + phone required), or an error sentence. */
    private function customerPayload(array $order_info)
    {
        $email = strtolower(trim((string) $order_info['email']));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->language->get('error_email');
        }
        $customer = ['email' => $email];
        $split = \SynraPayDirectClient::splitPhone((string) $order_info['telephone'], '', (string) ($order_info['payment_iso_code_2'] ?? ''));
        if ($split) {
            $customer['phoneCountryCode'] = $split['phoneCountryCode'];
            $customer['phoneNumber']      = $split['phoneNumber'];
        } else {
            $fallback = preg_replace('/\D/', '', (string) $this->config->get('payment_synra_pay_fallback_phone'));
            if (strlen($fallback) < 8 || strlen($fallback) > 15) {
                return $this->language->get('error_phone');
            }
            $customer['phone'] = '+' . $fallback;
        }
        foreach (['firstName' => (string) ($order_info['payment_firstname'] ?: $order_info['firstname']), 'lastName' => (string) ($order_info['payment_lastname'] ?: $order_info['lastname'])] as $k => $v) {
            $v = trim($v);
            if (mb_strlen($v) >= 2 && mb_strlen($v) <= 120 && preg_match('/\p{L}/u', $v)) {
                $customer[$k] = $v;
            }
        }
        return $customer;
    }

    /** A merchant_order_id the Direct API accepts: letters, digits and single hyphens. */
    private function merchantOrderId(int $order_id): string
    {
        $site = strtoupper(substr(md5((string) $this->config->get('config_url')), 0, 6));
        $time = strtoupper(base_convert((string) (int) floor(microtime(true) * 1000), 10, 36));
        $rand = strtoupper(bin2hex(random_bytes(2)));
        return 'OC-' . $site . '-' . $order_id . '-' . $time . '-' . $rand;
    }

    /** scheme://host[:port] of this store, as the browser writes it in the Origin header. */
    private function siteOrigin(): string
    {
        $p = parse_url((string) $this->config->get('config_url'));
        $origin = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '');
        if (!empty($p['port'])) {
            $origin .= ':' . (int) $p['port'];
        }
        return $origin;
    }

    /** The checkout page's own Origin when it is this store (exact scheme and port), else the store origin. */
    private function requestOrigin(): string
    {
        $site = $this->siteOrigin();
        $hdr  = rtrim((string) ($this->request->server['HTTP_ORIGIN'] ?? ''), '/');
        if ($hdr !== '' && strtolower((string) parse_url($hdr, PHP_URL_HOST)) === strtolower((string) parse_url($site, PHP_URL_HOST))) {
            return $hdr;
        }
        return $site;
    }

    /**
     * The browser endpoints accept only requests this store's own pages make (Origin or Referer on
     * this host) and only so many per checkout session: each session call creates an order at
     * SynraPay, each confirm call reads one.
     */
    private function guard(string $bucket, int $max): bool
    {
        $host = strtolower((string) parse_url((string) $this->config->get('config_url'), PHP_URL_HOST));
        $from = '';
        if (!empty($this->request->server['HTTP_ORIGIN'])) {
            $from = (string) parse_url((string) $this->request->server['HTTP_ORIGIN'], PHP_URL_HOST);
        } elseif (!empty($this->request->server['HTTP_REFERER'])) {
            $from = (string) parse_url((string) $this->request->server['HTTP_REFERER'], PHP_URL_HOST);
        }
        if ($from === '' || strtolower($from) !== $host) {
            $this->json(['ok' => false, 'status' => 'error', 'error' => $this->language->get('error_refused')], 403);
            return false;
        }
        $now = time();
        $rl  = $this->session->data['synra_rl'][$bucket] ?? ['n' => 0, 'reset' => $now + 900];
        if ($now > $rl['reset']) {
            $rl = ['n' => 0, 'reset' => $now + 900];
        }
        $rl['n']++;
        $this->session->data['synra_rl'][$bucket] = $rl;
        if ($rl['n'] > $max) {
            $this->log->write('SynraPay: rate limit hit: ' . $bucket);
            $this->json(['ok' => false, 'status' => 'error', 'error' => $this->language->get('error_rate')], 429);
            return false;
        }
        return true;
    }

    private function successUrl(): string
    {
        return $this->url->link('checkout/success', 'language=' . $this->config->get('config_language'), true);
    }

    private function json(array $data, int $code = 200): void
    {
        if ($code !== 200) {
            $this->response->addHeader(($this->request->server['SERVER_PROTOCOL'] ?? 'HTTP/1.1') . ' ' . $code . ' ' . $this->statusText($code));
        }
        $this->response->addHeader('Content-Type: application/json');
        $this->response->addHeader('Cache-Control: no-store');
        $this->response->setOutput(json_encode($data));
    }

    private function plain(int $code, string $text): void
    {
        if ($code !== 200) {
            $this->response->addHeader(($this->request->server['SERVER_PROTOCOL'] ?? 'HTTP/1.1') . ' ' . $code . ' ' . $this->statusText($code));
        }
        $this->response->addHeader('Content-Type: text/plain');
        $this->response->setOutput($text);
    }

    private function statusText(int $code): string
    {
        $texts = [400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found', 409 => 'Conflict', 429 => 'Too Many Requests', 500 => 'Internal Server Error', 502 => 'Bad Gateway'];
        return $texts[$code] ?? 'Error';
    }
}

// The Direct API client is plain PHP outside OpenCart's autoloader.
require_once DIR_EXTENSION . 'synra_pay/system/library/synra_pay.php';
