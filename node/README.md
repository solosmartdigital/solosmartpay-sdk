# @solosmartpay/node

JavaScript client for the Solosmartpay API: QRIS, bank VA, e-wallet. No dependencies, ESM, TypeScript types.

| Entry | Where it runs | What it does |
|---|---|---|
| `@solosmartpay/node` | Node 18+, Bun, Deno | API client and webhook verification. Needs your `ssp_` token. |
| `@solosmartpay/node/browser` | Browser (Vite, webpack, plain ES modules) | Opens the popup checkout. No secrets. |

> Your `ssp_` token and webhook secret stay on the server. The browser only ever receives `embed_url`.

```bash
npm install @solosmartpay/node
```

## Pay flow in 3 steps

1. **Server** creates the invoice (`ssp.qris.create` or `ssp.payments.create`).
2. **Browser** opens the popup with `openCheckout(embedUrl)`.
3. **Server** marks the order paid from the `payment.paid` webhook (`parseWebhook`).

### 1. Server

```js
import { Solosmartpay } from '@solosmartpay/node';

const ssp = new Solosmartpay({ token: process.env.SOLOSMARTPAY_TOKEN, baseUrl: 'https://YOUR-DOMAIN/v1' });

let qris = await ssp.qris.create({ amount: 50000, reference: 'ORDER-123', idempotencyKey: 'order-123-create' });
qris = await ssp.qris.waitUntilReady(qris);   // polls until the checkout is available

qris.totalAmount;   // 50127 (amount + unique code)
qris.embedUrl;      // give this to the browser
qris.qrisImage;     // PNG data URI for a custom UI
qris.expiresAt;     // Date
```

Creation is asynchronous (`202`). `waitUntilReady` rejects if provisioning fails or `timeoutMs` (default 30000) passes.

Virtual account, e-wallet, and retail use the same flow:

```js
const method = await ssp.methods.find('BR');          // PaymentMethod | null
method?.accepts(150000);                              // inside the method's limits?

let bill = await ssp.payments.create({ amount: 150000, method: 'BR', reference: 'ORDER-124', idempotencyKey: 'order-124-create' }); // or 'all'
bill = await ssp.payments.waitUntilReady(bill);

await ssp.methods.all('ewallet');                     // list one group
await ssp.qris.cancel(qris.id);                       // while still pending
```

| Class | What it is |
|---|---|
| `Invoice` | `id`, `reference`, `status`, `provisioningStatus`, `amount`, `uniqueCode`, `totalAmount`, `fee`, `netAmount`, `expiresAt`, `paidAt`, `checkoutUrl`, `embedUrl`, `statusUrl`, and `isPending()`, `isPaid()`, `isExpired()`, `isCancelled()`, `isReady()` |
| `Qris` | adds `qrisString`, `qrisImage` |
| `Payment` | adds `paymentUrl` (null for virtual accounts) |
| `PaymentMethod` | `code`, `name`, `group`, `feeType`, `fee`, `minAmount`, `maxAmount`, `accepts()` |
| `WebhookEvent` | `id`, `type`, `data` (an `Invoice`), `isPaid()` |

Every model keeps the untouched API payload in `.raw`. The 1.0/1.1 flat helpers (`createQris`, `getQris`, `cancelQris`, `createPayment`, `getPayment`, `cancelPayment`, `paymentMethods`) still return raw snake_case data.

### 2. Browser

```js
import { openCheckout } from '@solosmartpay/node/browser';

await openCheckout(qris.embedUrl, {
  lang: 'id',
  onSuccess: (r) => (location.href = `/thanks?id=${r.publicId}`), // UX only
  onClose: ({ reason }) => console.log('closed', reason),
});
```

`openCheckout` loads `embed.js` from the same origin as `embed_url`, so there is no script tag to add. `onSuccess` runs in the shopper's browser and can be forged. Never use it as proof of payment.

### 3. Webhook (Express)

```js
import { parseWebhook } from '@solosmartpay/node';

app.post('/webhook/solosmartpay', express.raw({ type: 'application/json' }), (req, res) => {
  try {
    const event = parseWebhook(req.body, req.get('X-SoloSmartPay-Signature'), req.get('X-SoloSmartPay-Timestamp'), process.env.SOLOSMARTPAY_WEBHOOK_SECRET);
    if (event.isPaid()) {
      // match event.data.reference and event.data.amount to your order; dedupe on event.id
    }
    res.sendStatus(200);
  } catch {
    res.sendStatus(401);
  }
});
```

Reuse an `idempotencyKey` only with the same request body. A different body returns `409`.

## Laravel

Laravel creates the invoice in PHP; this package opens the popup from your Vite bundle.

```bash
composer require solosmartpay/solosmartpay-php   # server: create invoice, verify webhook
npm install @solosmartpay/node                    # browser: openCheckout
```

Working files are in [`examples/laravel`](examples/laravel):

| File | Purpose |
|---|---|
| `SolosmartpayController.php` | `create`, `status` (polled by the browser), and `webhook` actions |
| `checkout.js` | Vite entry: create invoice, wait for `embed_url`, `openCheckout` |
| `pay.blade.php` | Button, CSRF meta tag, routes, and the CSRF exclusion for the webhook |

Vue, React, Inertia, Livewire, and plain Blade all work the same way: import `openCheckout` wherever the Pay button lives.

## Development

```bash
npm test      # node --test, no dependencies
npm pack --dry-run
```
