<?php
// Text
$_['text_title']              = 'Credit / Debit card';
$_['text_description']        = 'Visa, Mastercard and American Express accepted. Secured by SynraPay.';
$_['text_test_mode']          = 'Test mode';
$_['text_secure']             = 'Your card details are entered in SynraPay\'s secure frame and are never seen by this store. Payments processed by SynraPay.';
$_['text_processing']         = 'Processing your payment…';
$_['text_confirming']         = 'Confirming your payment…';
$_['text_not_ready']          = 'The card form is still loading.';
$_['text_incomplete']         = 'Please complete your card details.';
$_['text_declined']           = 'Your payment was not completed. Please try again or use another card.';
$_['text_expired']            = 'The card session expired. A new one is loading…';
$_['text_sdk_failed']         = 'Could not load the card form. Please refresh the page.';
$_['text_paid_history']       = 'SynraPay: payment %s (hosted session). Transaction %s%s';
$_['text_pending_history']    = 'SynraPay: the card frame reported the payment approved; waiting for SynraPay\'s final confirmation (webhook or re-check) before the order is marked paid.';
$_['text_failed_history']     = 'SynraPay: payment %s — %s';
$_['text_mismatch_history']   = 'SynraPay: paid %s %s but the order total is %s %s — review before fulfilling.';

// Button
$_['button_pay']              = 'Pay %s';

// Error
$_['error_order']             = 'Your order could not be found. Please refresh the page.';
$_['error_payment_method']    = 'Payment method is incorrect!';
$_['error_refused']           = 'Request refused.';
$_['error_rate']              = 'Too many attempts. Please wait a few minutes and try again.';
$_['error_not_configured']    = 'SynraPay is not configured (missing Merchant ID or API key).';
$_['error_email']             = 'Enter a valid email address to load the card form.';
$_['error_phone']             = 'Enter a valid phone number to load the card form.';
$_['error_amount']            = 'There is nothing to pay for yet.';
$_['error_env']               = 'SynraPay: the API key does not belong to the selected environment. Check the extension settings.';
$_['error_no_session']        = 'No card session on this order.';
$_['error_amount_mismatch']   = 'The payment did not match this order. Please contact the store.';
$_['error_already']           = 'This order has already been placed.';
