<?php
// Run: php tests/signature-test.php
define('SSP_WC_TESTING', true);
require __DIR__ . '/../includes/class-ssp-signature.php';

$fail = 0;
function check($name, $cond)
{
    global $fail;
    echo ($cond ? 'ok   ' : 'FAIL ') . $name . "\n";
    $fail += $cond ? 0 : 1;
}

$secret = 'whsec_test';
$now = 1790000000;
$body = '{"id":"evt_1","type":"payment.paid","data":{"id":"abc","amount":50000}}';
$sig = 'sha256=' . hash_hmac('sha256', $now . '.' . $body, $secret);

check('valid signature passes', SSP_WC_Signature::error($body, $sig, (string) $now, $secret, 300, $now) === null);
check('tampered body fails', SSP_WC_Signature::error($body . ' ', $sig, (string) $now, $secret, 300, $now) !== null);
check('wrong secret fails', SSP_WC_Signature::error($body, $sig, (string) $now, 'other', 300, $now) !== null);
check('missing sha256= prefix fails', SSP_WC_Signature::error($body, substr($sig, 7), (string) $now, $secret, 300, $now) !== null);
check('replay older than 300s fails', SSP_WC_Signature::error($body, $sig, (string) $now, $secret, 300, $now + 301) !== null);
check('299s old still passes', SSP_WC_Signature::error($body, $sig, (string) $now, $secret, 300, $now + 299) === null);
check('non numeric timestamp fails', SSP_WC_Signature::error($body, $sig, 'abc', $secret, 300, $now) !== null);
check('empty secret fails', SSP_WC_Signature::error($body, $sig, (string) $now, '', 300, $now) !== null);
check('empty signature fails', SSP_WC_Signature::error($body, '', (string) $now, $secret, 300, $now) !== null);

exit($fail ? 1 : 0);
