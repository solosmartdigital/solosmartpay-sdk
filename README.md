# Solosmartpay SDKs

| Language | Folder | Requirements |
|---|---|---|
| PHP (Laravel-ready) | [`php/`](php) | PHP 8.1+, ext-curl |
| JavaScript / Node.js | [`node/`](node) | Node 18+ (Bun, Deno), ESM + TypeScript types. Includes `@solosmartpay/node/browser` for the popup checkout (Laravel + Vite example inside). |
| Python | [`python/`](python) | Python 3.8+ |
| Go | [`go/`](go) | Go 1.21+ |
| WooCommerce (WordPress plugin) | [`wordpress/solosmartpay-woocommerce/`](wordpress/solosmartpay-woocommerce) | WordPress 6.2+, WooCommerce 7+, PHP 7.4+, IDR |

No third-party dependencies. The WooCommerce plugin is a ready-made integration (settings screen, hosted checkout redirect, signed webhook), not an SDK. Every SDK has the same shape: one client with three resources, typed models, and webhook verification.

| | PHP | JavaScript | Python | Go |
|---|---|---|---|---|
| QRIS | `$ssp->qris->create/get/cancel/waitUntilReady` | `ssp.qris.create/get/cancel/waitUntilReady` | `ssp.qris.create/get/cancel/wait_until_ready` | `ssp.Qris.Create/Get/Cancel/WaitUntilReady` |
| Bank VA / e-wallet / retail | `$ssp->payments->…` | `ssp.payments.…` | `ssp.payments.…` | `ssp.Payments.…` |
| Methods | `$ssp->methods->all/find` | `ssp.methods.all/find` | `ssp.methods.all/find` | `ssp.Methods.All/Find` |
| Models | `Invoice`, `Qris`, `Payment`, `PaymentMethod`, `WebhookEvent` | same | same | `Invoice` (`Qris` and `Payment` are aliases), `PaymentMethod`, `WebhookEvent` |
| Webhook | `Webhook::parse` / `verify` | `parseWebhook` / `verifyWebhook` | `parse_webhook` / `verify_webhook` | `VerifyWebhook` |

The flat helpers of 1.0 and 1.1 (`createQris`, `getQris`, `createPayment`, ...) still work and return raw API data.

| Operation | Endpoint | Token scope |
|---|---|---|
| create QRIS | `POST /v1/qris` | `qris:write` |
| get QRIS | `GET /v1/qris/{id}` | `read` |
| cancel pending QRIS | `DELETE /v1/qris/{id}` | `qris:write` |
| list payment methods | `GET /v1/payment-methods` | `read` |
| create bank VA / e-wallet / retail bill | `POST /v1/payments` | `payments:write` |
| get bill | `GET /v1/payments/{id}` | `read` |
| cancel pending bill | `DELETE /v1/payments/{id}` | `payments:write` |
| verify webhook | n/a | webhook signing secret |

Common behaviour:
- Auth header `X-API-Token: ssp_…`. Keep tokens on your server.
- Creation requires an idempotency key. The same key with the same body returns the same invoice; a different body is a `409`.
- Creation is asynchronous and returns `202` with `status_url`. `waitUntilReady` polls until the checkout can be shown (`embed_url` filled), and fails if provisioning fails or the timeout passes. QRIS and bank/e-wallet follow the same flow.
- Reads, cancels, and idempotent writes are retried on network errors, 429, and 5xx.
- Money is integer Rupiah. `status` is `pending`, `paid`, `expired`, or `cancelled`.
- Webhooks: `X-SoloSmartPay-Signature = "sha256=" + HMAC_SHA256(secret, timestamp + "." + raw_body)`; requests older than 5 minutes are rejected; deduplicate on the event `id`. Events: `payment.paid`, `invoice.expired`, `invoice.failed`, `invoice.cancelled`, `webhook.test`.
- Sandbox: a token starting with `ssp_test_` creates test payments only (no money, no KYC). `ssp.sandbox.pay / fail / expire` (`Sandbox.Pay` in Go) decide how one ends, and webhooks from it carry `sandbox: true` (`event.isSandbox()`). Never mark a real order paid from a sandbox event.
