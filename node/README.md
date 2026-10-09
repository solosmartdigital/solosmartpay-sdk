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

1. **Server** creates the invoice (`createQris` or `createPayment`).
2. **Browser** opens the popup with `openCheckout(embed_url)`.
3. **Server** marks the order paid from the `payment.paid` webhook (`verifyWebhook`).

### 1. Server

```js
import { Solosmartpay } from '@solosmartpay/node';

const ssp = new Solosmartpay({ token: process.env.SOLOSMARTPAY_TOKEN, baseUrl: 'https://YOUR-DOMAIN/v1' });

const { data, status_url } = await ssp.createQris({ amount: 50000, reference: 'ORDER-123', idempotencyKey: 'order-123-create' });
const invoice = await ssp.getQris(data.id); // repeat until invoice.embed_url is not null
```

Creation is asynchronous: the response is `202`, and `embed_url` stays `null` until `provisioning_status` is `ready`. Poll `getQris` (or `status_url`) about once per second.

Other calls: `createPayment({ amount, method, ... })`, `getPayment`, `paymentMethods()`, `cancelQris(id)`, `cancelPayment(id)`.

### 2. Browser

```js
import { openCheckout } from '@solosmartpay/node/browser';

await openCheckout(invoice.embed_url, {
  lang: 'id',
  onSuccess: (r) => (location.href = `/thanks?id=${r.publicId}`), // UX only
  onClose: ({ reason }) => console.log('closed', reason),
});
```

`openCheckout` loads `embed.js` from the same origin as `embed_url`, so there is no script tag to add. `onSuccess` runs in the shopper's browser and can be forged. Never use it as proof of payment.

### 3. Webhook (Express)

```js
import { verifyWebhook } from '@solosmartpay/node';

app.post('/webhook/solosmartpay', express.raw({ type: 'application/json' }), (req, res) => {
  try {
    const event = verifyWebhook(req.body, req.get('X-SoloSmartPay-Signature'), req.get('X-SoloSmartPay-Timestamp'), process.env.SOLOSMARTPAY_WEBHOOK_SECRET);
    if (event.type === 'payment.paid') {
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
