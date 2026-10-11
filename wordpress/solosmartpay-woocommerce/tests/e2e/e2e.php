<?php
// Run with: wp eval-file e2e.php  (inside the test WordPress)
$RUN = bin2hex(random_bytes(3));
function t($name, $cond, $extra = '') {
    static $f = 0;
    if ($name === '__done') { echo $f ? "\n$f FAILED\n" : "\nALL OK\n"; return; }
    $f += $cond ? 0 : 1;
    echo ($cond ? 'ok   ' : 'FAIL ') . $name . ($cond ? '' : "  <- $extra") . "\n";
}
function mock($path, $method = 'GET', $body = null) {
    $r = wp_remote_request('http://127.0.0.1:8891' . $path, ['method' => $method, 'body' => $body ? wp_json_encode($body) : null, 'headers' => ['Content-Type' => 'application/json']]);
    return json_decode(wp_remote_retrieve_body($r), true);
}
function webhook($event, $secret = 'whsec_test', $ts = null, $tamper = false) {
    $body = wp_json_encode($event); $ts = (string) ($ts ?: time());
    $sig = 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, $secret);
    $r = wp_remote_post('http://127.0.0.1:8890/?wc-api=solosmartpay', ['body' => $tamper ? $body . ' ' : $body, 'timeout' => 30,
        'headers' => ['Content-Type' => 'application/json', 'X-SoloSmartPay-Signature' => $sig, 'X-SoloSmartPay-Timestamp' => $ts]]);
    wp_cache_flush(); // the web process changed the order behind our in-process cache
    return [wp_remote_retrieve_response_code($r), json_decode(wp_remote_retrieve_body($r), true)];
}
function make_order($total_each = 50000, $qty = 1) {
    $p = new WC_Product_Simple(); $p->set_name('Kopi'); $p->set_regular_price($total_each); $p->set_manage_stock(true); $p->set_stock_quantity(10); $p->save();
    $o = wc_create_order(); $o->add_product($p, $qty); $o->set_payment_method('solosmartpay'); $o->set_billing_email('a@example.com'); $o->calculate_totals(); $o->save();
    return [$o, $p];
}

add_filter('pre_wp_mail', '__return_true');
mock('/_reset', 'GET');
update_option('woocommerce_solosmartpay_settings', ['enabled' => 'yes', 'title' => 'QRIS', 'description' => 'd', 'base_url' => 'http://127.0.0.1:8891', 'api_token' => 'ssp_test', 'webhook_secret' => 'whsec_test', 'debug' => 'yes']);
WC()->payment_gateways()->init();
$gw = WC()->payment_gateways()->payment_gateways()['solosmartpay'];

t('gateway registered', $gw instanceof SSP_WC_Gateway);
t('gateway available for IDR with credentials', $gw->is_available());
t('base url normalised', SSP_WC_Api::normalize_base_url('https://x.id/') === 'https://x.id/v1' && SSP_WC_Api::normalize_base_url('https://x.id/v1/') === 'https://x.id/v1');

// --- checkout
list($order, $product) = make_order(50000, 1);
$res = $gw->process_payment($order->get_id());
$order = wc_get_order($order->get_id());
$inv = $order->get_meta('_ssp_invoice_id');
t('process_payment succeeds and redirects to checkout_url', $res['result'] === 'success' && strpos($res['redirect'], 'http://127.0.0.1:8891/pay/') === 0, json_encode($res));
t('order is on-hold with invoice id', $order->has_status('on-hold') && $inv !== '', $order->get_status());
$state = mock('/_state'); $post = array_values(array_filter($state['log'], fn ($l) => $l['m'] === 'POST'))[0];
t('API got amount, method=all, reference, token, idempotency key', $post['body']['amount'] === 50000 && $post['body']['method'] === 'all' && $post['body']['reference'] === (string) $order->get_order_number() && $post['token'] === 'ssp_test' && preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $post['idem']) === 1, json_encode($post));
t('stock reserved on hold', (int) wc_get_product($product->get_id())->get_stock_quantity() === 9, (string) wc_get_product($product->get_id())->get_stock_quantity());

// --- retry reuses the same invoice
$posts_before = count(array_filter(mock('/_state')['log'], fn ($l) => $l['m'] === 'POST'));
$res2 = $gw->process_payment($order->get_id());
$posts_after = count(array_filter(mock('/_state')['log'], fn ($l) => $l['m'] === 'POST'));
t('retry reuses pending invoice (no new POST)', $res2['result'] === 'success' && $posts_after === $posts_before && wc_get_order($order->get_id())->get_meta('_ssp_invoice_id') === $inv);

// --- webhook security
$evt = fn ($id, $type, $data) => ['id' => $id . '_' . $RUN, 'type' => $type, 'created_at' => gmdate('c'), 'data' => $data];
$paid = fn ($amount) => ['id' => $inv, 'reference' => (string) $order->get_order_number(), 'status' => 'paid', 'amount' => $amount];
list($c) = webhook($evt('evt_bad1', 'payment.paid', $paid(50000)), 'whsec_test', null, true);
t('tampered body rejected with 401', $c === 401, (string) $c);
list($c) = webhook($evt('evt_bad2', 'payment.paid', $paid(50000)), 'wrong_secret');
t('wrong secret rejected with 401', $c === 401, (string) $c);
list($c) = webhook($evt('evt_bad3', 'payment.paid', $paid(50000)), 'whsec_test', time() - 1000);
t('replayed (old) timestamp rejected with 401', $c === 401, (string) $c);
t('order untouched after rejected webhooks', wc_get_order($order->get_id())->has_status('on-hold'));
$r = wp_remote_get('http://127.0.0.1:8890/?wc-api=solosmartpay');
t('GET on webhook URL gives 405', wp_remote_retrieve_response_code($r) === 405, (string) wp_remote_retrieve_response_code($r));

list($c, $j) = webhook($evt('evt_test1', 'webhook.test', ['message' => 'hi']));
t('webhook.test acknowledged', $c === 200 && $j['ok'] === true, json_encode([$c, $j]));

list($c) = webhook($evt('evt_amt', 'payment.paid', $paid(49000)));
$o = wc_get_order($order->get_id());
t('amount mismatch is NOT marked paid', $c === 200 && $o->has_status('on-hold'), $o->get_status());

list($c, $j) = webhook($evt('evt_paid', 'payment.paid', $paid(50000)));
$o = wc_get_order($order->get_id());
t('payment.paid marks order paid', $c === 200 && $o->is_paid() && $o->get_transaction_id() === $inv, $o->get_status() . ' ' . $o->get_transaction_id());
list($c, $j) = webhook($evt('evt_paid', 'payment.paid', $paid(50000)));
t('duplicate event id is ignored', $c === 200 && $j['message'] === 'duplicate', json_encode($j));
$notes = array_filter(wc_get_order_notes(['order_id' => $order->get_id()]), fn ($n) => strpos($n->content, 'pembayaran diterima') !== false);
t('only one paid note', count($notes) === 1, (string) count($notes));
t('stock reduced once', (int) wc_get_product($product->get_id())->get_stock_quantity() === 9);

// --- unknown invoice is acknowledged, not retried forever
list($c, $j) = webhook($evt('evt_unknown', 'payment.paid', ['id' => 'deadbeef', 'amount' => 1]));
t('unknown invoice acknowledged with 200', $c === 200 && $j['message'] === 'order not found', json_encode([$c, $j]));

// --- expiry via webhook cancels order without calling the API back
list($o2) = make_order(25000, 1);
$gw->process_payment($o2->get_id());
$o2 = wc_get_order($o2->get_id()); $inv2 = $o2->get_meta('_ssp_invoice_id');
$dels_before = count(array_filter(mock('/_state')['log'], fn ($l) => $l['m'] === 'DELETE'));
list($c) = webhook($evt('evt_exp', 'invoice.expired', ['id' => $inv2, 'amount' => 25000]));
$dels_after = count(array_filter(mock('/_state')['log'], fn ($l) => $l['m'] === 'DELETE'));
t('invoice.expired cancels the order', $c === 200 && wc_get_order($o2->get_id())->has_status('cancelled'), wc_get_order($o2->get_id())->get_status());
t('webhook-driven cancel does not call DELETE', $dels_after === $dels_before, "$dels_before -> $dels_after");

// --- cancelling in WooCommerce cancels the invoice
list($o3) = make_order(30000, 1);
$gw->process_payment($o3->get_id());
$o3 = wc_get_order($o3->get_id()); $inv3 = $o3->get_meta('_ssp_invoice_id');
$o3->update_status('cancelled', 'admin');
$state = mock('/_state');
t('admin cancel -> DELETE sent and invoice cancelled', $state['invoices'][$inv3]['status'] === 'cancelled', $state['invoices'][$inv3]['status']);

// --- changed total on retry: old invoice cancelled, new one created
list($o4) = make_order(40000, 1);
$gw->process_payment($o4->get_id());
$o4 = wc_get_order($o4->get_id()); $inv4 = $o4->get_meta('_ssp_invoice_id');
foreach ($o4->get_items() as $item) { $item->set_quantity(2); $item->set_subtotal(80000); $item->set_total(80000); $item->save(); }
$o4->calculate_totals(); $o4->save();
$r4 = $gw->process_payment($o4->get_id());
$o4 = wc_get_order($o4->get_id());
t('changed total -> new invoice with new amount', $r4['result'] === 'success' && $o4->get_meta('_ssp_invoice_id') !== $inv4, json_encode($r4));
$state = mock('/_state');
t('changed total -> old invoice cancelled', $state['invoices'][$inv4]['status'] === 'cancelled' && $state['invoices'][$o4->get_meta('_ssp_invoice_id')]['amount'] === 80000);

// --- failures surface as notices, not fatals
update_option('woocommerce_solosmartpay_settings', array_merge(get_option('woocommerce_solosmartpay_settings'), ['api_token' => 'wrong']));
WC()->payment_gateways()->init();
$gw = WC()->payment_gateways()->payment_gateways()['solosmartpay'];
list($o5) = make_order(10000, 1);
wc_clear_notices();
$r5 = $gw->process_payment($o5->get_id());
t('bad token -> failure result + customer notice', $r5['result'] === 'failure' && wc_notice_count('error') === 1, json_encode($r5));
t('bad token -> failure noted on order', (bool) array_filter(wc_get_order_notes(['order_id' => $o5->get_id()]), fn ($n) => strpos($n->content, 'gagal membuat tagihan') !== false));
$logs = glob(WC_LOG_DIR . '*solosmartpay*.log') ?: [];
$all = implode("\n", array_map('file_get_contents', $logs));
t('debug log exists and never contains the API token', $all !== '' && strpos($all, 'ssp_test') === false && strpos($all, 'whsec_test') === false, strlen($all) . ' bytes');

// --- availability rules
update_option('woocommerce_currency', 'USD'); WC()->payment_gateways()->init();
t('hidden when currency is not IDR', ! WC()->payment_gateways()->payment_gateways()['solosmartpay']->is_available());
update_option('woocommerce_currency', 'IDR');

// =================================================== hardening
update_option('woocommerce_solosmartpay_settings', ['enabled' => 'yes', 'title' => 'QRIS', 'description' => 'd', 'base_url' => 'http://127.0.0.1:8891', 'api_token' => 'ssp_test', 'webhook_secret' => 'whsec_test', 'debug' => 'yes']);
WC()->payment_gateways()->init();
$gw = WC()->payment_gateways()->payment_gateways()['solosmartpay'];

// secrets are never echoed into the settings page
ob_start(); $gw->generate_settings_html(); $html = ob_get_clean();
t('settings page does not contain the token or webhook secret', strpos($html, 'ssp_test') === false && strpos($html, 'whsec_test') === false);
t('blank secret keeps the stored value', $gw->validate_ssp_secret_field('api_token', '') === 'ssp_test' && $gw->validate_ssp_secret_field('api_token', '  ssp_new  ') === 'ssp_new');

// base url must be https (loopback allowed for development)
t('http to a public host is rejected', ! SSP_WC_Api::is_secure_url('http://evil.example/v1') && SSP_WC_Api::is_secure_url('https://solosmartpay.com/v1') && SSP_WC_Api::is_secure_url('http://127.0.0.1:8891/v1') && ! SSP_WC_Api::is_secure_url('ftp://x/v1') && ! SSP_WC_Api::is_secure_url('javascript:alert(1)'));
t('saving an http base url keeps the previous value', $gw->validate_base_url_field('base_url', 'http://evil.example') === 'http://127.0.0.1:8891');
$api = new SSP_WC_Api('https://solosmartpay.com/v1', 'tok');
t('checkout url must stay on the API host', $api->owns_url('https://solosmartpay.com/pay/abc') && ! $api->owns_url('https://evil.example/pay/abc') && ! $api->owns_url('https://solosmartpay.com.evil.example/pay') && ! $api->owns_url('javascript:alert(1)'));

// an unusable (http, public host) configuration never calls out
$s = get_option('woocommerce_solosmartpay_settings'); $s['base_url'] = 'http://evil.example/v1'; update_option('woocommerce_solosmartpay_settings', $s);
WC()->payment_gateways()->init(); $gw = WC()->payment_gateways()->payment_gateways()['solosmartpay'];
list($oh) = make_order(10000, 1); wc_clear_notices();
$before = count(mock('/_state')['log']);
$rh = $gw->process_payment($oh->get_id());
t('insecure base url -> failure, no request sent', $rh['result'] === 'failure' && count(mock('/_state')['log']) === $before);
$s['base_url'] = 'http://127.0.0.1:8891'; update_option('woocommerce_solosmartpay_settings', $s);
WC()->payment_gateways()->init(); $gw = WC()->payment_gateways()->payment_gateways()['solosmartpay'];

// non-integer totals are refused instead of being rounded
list($of) = make_order(10000.5, 1); wc_clear_notices();
$rf = $gw->process_payment($of->get_id());
t('fractional total refused', $rf['result'] === 'failure' && wc_notice_count('error') === 1, json_encode($rf));

// reference mismatch is not marked paid
list($or) = make_order(20000, 1);
$gw->process_payment($or->get_id());
$or = wc_get_order($or->get_id()); $invr = $or->get_meta('_ssp_invoice_id');
webhook($evt('evt_ref', 'payment.paid', ['id' => $invr, 'reference' => 'SOMEONE-ELSE', 'amount' => 20000]));
t('reference mismatch is NOT marked paid', wc_get_order($or->get_id())->has_status('on-hold'), wc_get_order($or->get_id())->get_status());

// concurrent deliveries of the same paid event are applied once
list($oc) = make_order(30000, 1);
$gw->process_payment($oc->get_id());
$oc = wc_get_order($oc->get_id()); $invc = $oc->get_meta('_ssp_invoice_id');
$body = wp_json_encode(['id' => 'evt_race_' . $RUN, 'type' => 'payment.paid', 'data' => ['id' => $invc, 'reference' => (string) $oc->get_order_number(), 'amount' => 30000]]);
$ts = (string) time(); $sig = 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, 'whsec_test');
$mh = curl_multi_init(); $hs = [];
for ($i = 0; $i < 4; $i++) {
    $ch = curl_init('http://127.0.0.1:8890/?wc-api=solosmartpay');
    curl_setopt_array($ch, [CURLOPT_POST => 1, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => 1, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-SoloSmartPay-Signature: ' . $sig, 'X-SoloSmartPay-Timestamp: ' . $ts]]);
    curl_multi_add_handle($mh, $ch); $hs[] = $ch;
}
do { curl_multi_exec($mh, $run); curl_multi_select($mh, 0.2); } while ($run > 0);
$codes = array_map(fn ($h) => curl_getinfo($h, CURLINFO_HTTP_CODE), $hs);
wp_cache_flush();
$paid_notes = array_filter(wc_get_order_notes(['order_id' => $oc->get_id()]), fn ($n) => strpos($n->content, 'pembayaran diterima') !== false);
t('4 parallel deliveries: order paid exactly once', wc_get_order($oc->get_id())->is_paid() && count($paid_notes) === 1, json_encode($codes) . ' notes=' . count($paid_notes));
t('parallel deliveries answered 200 or 409 only', count(array_diff($codes, [200, 409])) === 0, json_encode($codes));

// oversized body
$r = wp_remote_post('http://127.0.0.1:8890/?wc-api=solosmartpay', ['body' => str_repeat('a', 1100000), 'timeout' => 30]);
t('oversized webhook body gets 413', wp_remote_retrieve_response_code($r) === 413, (string) wp_remote_retrieve_response_code($r));

// non-string ids are rejected
list($c) = webhook(['id' => ['x'], 'type' => 'payment.paid', 'data' => []]);
t('malformed event ids rejected with 400', $c === 400, (string) $c);

t('__done', 1);
