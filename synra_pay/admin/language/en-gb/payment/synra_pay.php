<?php
// Heading
$_['heading_title']          = 'SynraPay — Credit / Debit card';

// Text
$_['text_extension']         = 'Extensions';
$_['text_success']           = 'Success: You have modified the SynraPay payment extension!';
$_['text_edit']              = 'Edit SynraPay';
$_['text_credentials']       = 'SynraPay credentials';
$_['text_credentials_help']  = 'All of these come from the SynraPay Merchant Portal: sign in, pick the store, open <strong>Developers</strong>. Each environment (Develop / Sandbox / Live) has its own portal and its own keys — use the keys from the portal that matches the Environment chosen here.';
$_['text_webhook']           = 'Webhook';
$_['text_webhook_help']      = 'Merchant Portal → Developers → <strong>Webhooks</strong> → <em>Update Webhook</em> → paste this as the Endpoint URL with <strong>All events</strong> subscribed. The token in it is unique to this store and was generated on install.';
$_['text_origin_help']       = 'Hosted session binds each card session to this website. Make sure this origin is registered on your SynraPay merchant (Developers → Integrations), or the card form is refused with ORIGIN_NOT_REGISTERED.';
$_['text_statuses']          = 'Order statuses';
$_['text_develop']           = 'Develop (dev-api.synrapay.com)';
$_['text_sandbox']           = 'Sandbox (testing)';
$_['text_live']              = 'Live (production)';
$_['text_all_zones']         = 'All Zones';
$_['text_keep']              = 'Leave blank to keep the saved value';

// Entry
$_['entry_status']           = 'Status';
$_['entry_sort_order']       = 'Sort Order';
$_['entry_geo_zone']         = 'Geo Zone';
$_['entry_environment']      = 'Environment';
$_['entry_merchant_id']      = 'Merchant ID (x-merchant-id)';
$_['entry_api_key']          = 'API Key (signature key)';
$_['entry_fallback_phone']   = 'Fallback customer phone';
$_['entry_webhook_key']      = 'Webhook signing secret';
$_['entry_x_api_key_name']   = 'Webhook API-Key header name';
$_['entry_x_api_key']        = 'Webhook API-Key value';
$_['entry_order_status']     = 'Paid order status';
$_['entry_pending_status']   = 'Awaiting-confirmation status';
$_['entry_failed_status']    = 'Failed order status';
$_['entry_webhook_url']      = 'Your webhook URL';
$_['entry_origin']           = 'Your checkout origin';

// Help
$_['help_merchant_id']       = 'Developers → API Keys → section 1 "Request Signing" → Merchant ID.';
$_['help_api_key']           = 'Developers → API Keys → section 1 "Request Signing" → API Signature Key. Signs every request to SynraPay; it lives on your server only and is never sent to the browser.';
$_['help_fallback_phone']    = 'SynraPay requires a phone number on every order. When the customer\'s telephone is missing or invalid this number is sent instead so the card form still loads (digits with country code, e.g. 971500000000). Leave empty to require a valid customer phone.';
$_['help_webhook_key']       = 'Developers → Webhooks → Configuration → Signing secret (shown once when you click Generate new secret). Every webhook carries x-synrapay-signature = HMAC-SHA512 of "timestamp.body" with this secret; a webhook that does not verify is refused with 401.';
$_['help_x_api_key']         = 'Developers → API Keys → section 2 "Webhook Verification". Optional: leave both fields empty to verify webhooks by signature only.';
$_['help_order_status']      = 'Set when SynraPay reports the payment CAPTURED or AUTHORIZED (stock is subtracted at that moment).';
$_['help_pending_status']    = 'Set when the card frame reported the payment approved but SynraPay is not final yet; the webhook or the hourly re-check cron then moves the order to Paid or Failed.';
$_['help_failed_status']     = 'Set only when an awaiting-confirmation order turns out declined. An order the customer never paid stays as a missing order (status 0), like any abandoned OpenCart checkout.';

// Error
$_['error_permission']       = 'Warning: You do not have permission to modify the SynraPay payment extension!';
$_['error_merchant_id']      = 'Merchant ID is required!';
$_['error_api_key']          = 'API Key is required!';
$_['error_fallback_phone']   = 'Fallback phone must be 8–15 digits including the country code!';
