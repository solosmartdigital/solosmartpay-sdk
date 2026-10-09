# Solosmartpay PHP SDK

PHP ≥ 8.1, no dependencies besides `ext-curl`.

```bash
composer require solosmartpay/solosmartpay-php
```

```php
use Solosmartpay\Client;
use Solosmartpay\SolosmartpayException;

$ssp = new Client(getenv('SOLOSMARTPAY_TOKEN'), 'https://YOUR-SOLOSMARTPAY-DOMAIN/v1');

try {
    $qris = $ssp->createQris(50000, 'order-123-create', 'ORDER-123');
    $invoice = $ssp->getQris($qris['data']['id']); // poll $qris['status_url'] until provisioning_status is ready

    $methods = $ssp->paymentMethods();
    $bill = $ssp->createPayment(150000, 'BR', 'order-124-create', 'ORDER-124');
    $ssp->cancelQris($qris['data']['id']); // cancel while still pending
} catch (SolosmartpayException $e) {
    error_log($e->status.' '.$e->getMessage());
}
```

## Webhook

```php
$event = Solosmartpay\Webhook::verify(
    file_get_contents('php://input'),
    $_SERVER['HTTP_X_SOLOSMARTPAY_SIGNATURE'] ?? '',
    $_SERVER['HTTP_X_SOLOSMARTPAY_TIMESTAMP'] ?? '',
    getenv('SOLOSMARTPAY_WEBHOOK_SECRET'),
);

if ($event['type'] === 'payment.paid') {
    // mark order $event['data']['reference'] as paid — dedupe on $event['id']
}
http_response_code(200);
```

Provisioned creates return `202`, `data`, and `status_url`. Poll the URL until `data.provisioning_status` is `ready`; QRIS follows the same asynchronous provisioning flow as other channels. Reuse an `Idempotency-Key` only with the same request body.
