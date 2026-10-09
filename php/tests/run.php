<?php

// Dependency-free tests: php tests/run.php
spl_autoload_register(function (string $class) {
    $path = __DIR__.'/../src/'.str_replace('\\', '/', substr($class, strlen('Solosmartpay\\'))).'.php';
    if (str_starts_with($class, 'Solosmartpay\\') && is_file($path)) {
        require $path;
    }
});

use Solosmartpay\Client;
use Solosmartpay\Models\Invoice;
use Solosmartpay\Models\Payment;
use Solosmartpay\Models\PaymentMethod;
use Solosmartpay\Models\Qris;
use Solosmartpay\Models\WebhookEvent;
use Solosmartpay\SolosmartpayException;
use Solosmartpay\Webhook;

$failures = 0;
function check(string $name, bool $ok): void
{
    global $failures;
    echo ($ok ? "  ok   " : "  FAIL ").$name."\n";
    $failures += $ok ? 0 : 1;
}
function throws(callable $fn, string $needle = ''): bool
{
    try {
        $fn();
    } catch (SolosmartpayException $e) {
        return $needle === '' || str_contains($e->getMessage(), $needle);
    }

    return false;
}

$invoice = fn (array $over = []) => array_merge([
    'id' => 'abc-123', 'reference' => 'ORDER-1', 'status' => 'pending', 'provisioning_status' => 'queued',
    'channel' => 'qris', 'method' => 'qris', 'amount' => 50000, 'unique_code' => 127, 'total_amount' => 50127,
    'fee' => 350, 'net_amount' => 49650, 'expires_at' => '2026-09-27T15:15:00+07:00', 'paid_at' => null,
    'created_at' => '2026-09-27T15:00:00+07:00', 'checkout_url' => null, 'embed_url' => null, 'basic_url' => null,
    'qris_string' => null, 'qris_image' => null, 'payment_url' => null,
], $over);

$calls = [];
$respond = function (array $queue) use (&$calls) {
    return function (string $method, string $url, array $headers, ?string $body) use (&$queue, &$calls) {
        $calls[] = compact('method', 'url', 'headers', 'body');
        [$status, $json] = array_shift($queue) ?? [500, ['message' => 'queue empty']];

        return [$status, json_encode($json), ''];
    };
};
$client = fn (array $queue) => new Client('ssp_test', 'https://pay.example.com/v1/', 5, 1, $respond($queue));

echo "QRIS\n";
$ssp = $client([
    [202, ['data' => $invoice(), 'status_url' => 'https://pay.example.com/v1/qris/abc-123']],
    [200, ['data' => $invoice()]],
    [200, ['data' => $invoice(['provisioning_status' => 'ready', 'embed_url' => 'https://pay.example.com/pay/abc-123/embed', 'checkout_url' => 'https://pay.example.com/pay/abc-123', 'qris_image' => 'data:image/png;base64,AA', 'qris_string' => '0002'])]],
]);
$qris = $ssp->qris->create(50000, 'order-1-create', 'ORDER-1');
check('create returns a Qris model', $qris instanceof Qris && $qris instanceof Invoice);
check('create keeps status_url and fields', $qris->statusUrl === 'https://pay.example.com/v1/qris/abc-123' && $qris->totalAmount === 50127 && $qris->uniqueCode === 127);
check('create sends idempotency key + JSON body', in_array('Idempotency-Key: order-1-create', $calls[0]['headers'], true) && json_decode($calls[0]['body'], true) === ['amount' => 50000, 'reference' => 'ORDER-1']);
check('base url trailing slash is trimmed', $calls[0]['url'] === 'https://pay.example.com/v1/qris');
check('not ready while queued', ! $qris->isReady() && $qris->isPending() && $qris->embedUrl === null);
$ready = $ssp->qris->waitUntilReady($qris, 5, 1);
check('waitUntilReady polls until embedUrl is filled', $ready->isReady() && $ready->embedUrl !== null && $ready->qrisImage === 'data:image/png;base64,AA' && count($calls) === 3);
check('dates are DateTimeImmutable', $ready->expiresAt instanceof DateTimeImmutable && $ready->paidAt === null);

echo "Failures, timeouts, cancel\n";
$ssp = $client([[200, ['data' => $invoice(['provisioning_status' => 'failed', 'error_code' => 'provider_down'])]]]);
check('waitUntilReady throws on failed provisioning', throws(fn () => $ssp->qris->waitUntilReady('abc-123', 5, 1), 'provider_down'));
$ssp = $client(array_fill(0, 5, [200, ['data' => $invoice()]]));
check('waitUntilReady times out', throws(fn () => $ssp->qris->waitUntilReady('abc-123', 0, 1), 'belum siap'));
$ssp = $client([[200, ['data' => $invoice(['status' => 'cancelled'])]]]);
$cancelled = $ssp->qris->cancel('abc-123');
check('cancel uses DELETE and returns the invoice', $calls[array_key_last($calls)]['method'] === 'DELETE' && $cancelled->isCancelled());
$ssp = $client([[409, ['message' => 'Tagihan sudah dibayar']]]);
$e = null;
try { $ssp->qris->cancel('abc-123'); } catch (SolosmartpayException $e) {}
check('API errors become SolosmartpayException with status', $e instanceof SolosmartpayException && $e->status === 409 && $e->getMessage() === 'Tagihan sudah dibayar');

echo "Payments + methods\n";
$ssp = $client([
    [202, ['data' => $invoice(['channel' => 'bank_transfer', 'method' => 'BR', 'provisioning_status' => 'ready', 'payment_url' => 'https://gw.example/pay/1', 'embed_url' => 'https://pay.example.com/pay/abc-123/embed']), 'status_url' => 'https://pay.example.com/v1/payments/abc-123']],
    [200, ['data' => [
        ['code' => 'BR', 'name' => 'BRI VA', 'group' => 'va', 'fee_type' => 'flat', 'fee' => 4000, 'min_amount' => 10000, 'max_amount' => 5000000],
        ['code' => 'OV', 'name' => 'OVO', 'group' => 'ewallet', 'fee_type' => 'percent', 'fee' => 1.5, 'min_amount' => 1000, 'max_amount' => null],
    ]]],
]);
$payment = $ssp->payments->create(150000, 'BR', 'order-2-create');
check('payments->create returns a Payment model', $payment instanceof Payment && $payment->method === 'BR' && $payment->paymentUrl === 'https://gw.example/pay/1' && $payment->isReady());
check('payment body has method and no null reference', json_decode($calls[array_key_last($calls)]['body'], true) === ['amount' => 150000, 'method' => 'BR']);
$all = $ssp->methods->all();
check('methods->all returns PaymentMethod models', count($all) === 2 && $all[0] instanceof PaymentMethod && $all[0]->isVirtualAccount() && $all[1]->isEwallet());
check('methods limits', $all[0]->accepts(10000) && ! $all[0]->accepts(9999) && ! $all[0]->accepts(5000001) && $all[1]->accepts(99999999));

echo "Retries and legacy helpers\n";
$ssp = $client([[503, ['message' => 'down']], [200, ['data' => $invoice()]]]);
check('GET retries once on 503', $ssp->getQris('abc-123')['id'] === 'abc-123');
$ssp = $client([[503, ['message' => 'down']], [503, ['message' => 'down']]]);
check('retries stop at maxRetries', throws(fn () => $ssp->getQris('abc-123'), 'down'));
$ssp = $client([[202, ['data' => $invoice(), 'status_url' => 'u']]]);
check('createQris still returns the raw envelope', $ssp->createQris(50000, 'order-3-create')['status_url'] === 'u');
check('token must start with ssp_', (function () { try { new Client('bad', 'https://x/v1'); } catch (InvalidArgumentException) { return true; } return false; })());

echo "Webhook\n";
$secret = 'whsec_test';
$body = json_encode(['id' => 'evt_1', 'type' => 'payment.paid', 'created_at' => '2026-09-27T15:03:12+07:00', 'data' => $invoice(['status' => 'paid', 'paid_at' => '2026-09-27T15:03:10+07:00'])]);
$ts = (string) time();
$sig = 'sha256='.hash_hmac('sha256', $ts.'.'.$body, $secret);
$event = Webhook::parse($body, $sig, $ts, $secret);
check('parse returns a WebhookEvent with an Invoice', $event instanceof WebhookEvent && $event->isPaid() && $event->data->isPaid() && $event->data->reference === 'ORDER-1' && $event->data->paidAt !== null);
check('verify still returns the array', Webhook::verify($body, $sig, $ts, $secret)['id'] === 'evt_1');
check('tampered body is rejected', throws(fn () => Webhook::parse($body.' ', $sig, $ts, $secret), 'Signature'));
$old = (string) (time() - 3600);
check('old timestamp is rejected', throws(fn () => Webhook::parse($body, 'sha256='.hash_hmac('sha256', $old.'.'.$body, $secret), $old, $secret), 'kedaluwarsa'));

echo $failures === 0 ? "\nAll passed\n" : "\n{$failures} failed\n";
exit($failures === 0 ? 0 : 1);
