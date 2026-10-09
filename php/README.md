# Solosmartpay PHP SDK

PHP ≥ 8.1, no dependencies besides `ext-curl`. Works in plain PHP and Laravel.

```bash
composer require solosmartpay/solosmartpay-php
```

## Create, wait, show

```php
use Solosmartpay\Client;
use Solosmartpay\SolosmartpayException;

$ssp = new Client(getenv('SOLOSMARTPAY_TOKEN'), 'https://YOUR-SOLOSMARTPAY-DOMAIN/v1');

try {
    $qris = $ssp->qris->create(50000, 'order-123-create', 'ORDER-123');   // Models\Qris
    $qris = $ssp->qris->waitUntilReady($qris);                            // polls until checkout is available

    $qris->totalAmount;   // 50127 (amount + unique code)
    $qris->embedUrl;      // give this to the browser: openCheckout(embedUrl)
    $qris->qrisImage;     // PNG data URI for a custom UI
    $qris->expiresAt;     // DateTimeImmutable
} catch (SolosmartpayException $e) {
    error_log($e->status.' '.$e->getMessage());
}
```

Same flow for virtual account, e-wallet, and retail:

```php
$method = $ssp->methods->find('BR');                 // ?Models\PaymentMethod
$method?->accepts(150000);                           // inside the method's limits?

$bill = $ssp->payments->create(150000, 'BR', 'order-124-create', 'ORDER-124');   // or 'all' to let the customer choose
$bill = $ssp->payments->waitUntilReady($bill);

$ssp->methods->all(PaymentMethod::GROUP_EWALLET);    // list one group
$ssp->qris->cancel($qris->id);                       // while still pending
```

| Class | What it is |
|---|---|
| `Client` | `->qris`, `->payments`, `->methods` |
| `Models\Invoice` | shared fields: `id`, `reference`, `status`, `provisioningStatus`, `amount`, `uniqueCode`, `totalAmount`, `fee`, `netAmount`, `expiresAt`, `paidAt`, `checkoutUrl`, `embedUrl`, `statusUrl`, and `isPending()`, `isPaid()`, `isExpired()`, `isCancelled()`, `isReady()` |
| `Models\Qris` | adds `qrisString`, `qrisImage` |
| `Models\Payment` | adds `paymentUrl` (null for virtual accounts) |
| `Models\PaymentMethod` | `code`, `name`, `group`, `feeType`, `fee`, `minAmount`, `maxAmount`, `accepts()` |
| `Models\WebhookEvent` | `id`, `type`, `data` (an `Invoice`), `isPaid()` |

Every model keeps the untouched API payload in `->raw` / `toArray()`.

## Webhook

```php
use Solosmartpay\Webhook;
use Solosmartpay\SolosmartpayException;

try {
    $event = Webhook::parse(
        file_get_contents('php://input'),
        $_SERVER['HTTP_X_SOLOSMARTPAY_SIGNATURE'] ?? '',
        $_SERVER['HTTP_X_SOLOSMARTPAY_TIMESTAMP'] ?? '',
        getenv('SOLOSMARTPAY_WEBHOOK_SECRET'),
    );
} catch (SolosmartpayException) {
    http_response_code(401);
    exit;
}

if ($event->isPaid()) {
    // match $event->data->reference and $event->data->amount to your order; dedupe on $event->id
}
http_response_code(200);
```

`Webhook::verify()` takes the same arguments and returns the decoded array.

## Notes

- Creation returns `202`; use `waitUntilReady()` (or poll `$qris->statusUrl`) before showing the checkout. Reuse an idempotency key only with the same request body.
- The 1.0/1.1 helpers (`createQris()`, `getQris()`, `cancelQris()`, `createPayment()`, `getPayment()`, `cancelPayment()`, `paymentMethods()`) still return raw arrays.
- Tests without network: `new Client($token, $baseUrl, transport: fn ($method, $url, $headers, $body) => [200, $json, ''])`, then run `php tests/run.php`.
- A complete Laravel example (controller, routes, Vite entry) is in [`../node/examples/laravel`](../node/examples/laravel).
