# Solosmartpay SDKs

| Language | Folder | Requirements |
|---|---|---|
| PHP | [`php/`](php) | PHP 8.1+, ext-curl |
| JavaScript / Node.js | [`node/`](node) | Node 18+ (Bun, Deno), ESM + TypeScript types. Includes `@solosmartpay/node/browser` for the popup checkout (Laravel + Vite example inside). |
| Python | [`python/`](python) | Python 3.8+ |
| Go | [`go/`](go) | Go 1.21+ |

All SDKs have no third-party dependencies and expose the same operations:

| Operation | Endpoint | Token scope |
|---|---|---|
| create or provision QRIS | `POST /v1/qris` | `qris:write` |
| get QRIS | `GET /v1/qris/{id}` | `read` |
| list payment methods | `GET /v1/payment-methods` | `read` |
| create bank VA / e-wallet bill | `POST /v1/payments` | `payments:write` |
| get bill | `GET /v1/payments/{id}` | `read` |
| cancel pending QRIS / bill (PHP, JavaScript) | `DELETE /v1/qris/{id}`, `DELETE /v1/payments/{id}` | `qris:write` / `payments:write` |
| verify webhook | — | webhook signing secret |

Common behaviour:
- Auth header `X-API-Token: ssp_…`. Keep tokens on your server.
- Creation requires `Idempotency-Key`. Provisioned invoices return `202`, `data`, and top-level `status_url`; poll that URL until `data.provisioning_status` is `ready` before using payment details. QRIS follows the same asynchronous provisioning flow as other channels; no quote or confirmation step is required.
- Reads and idempotent writes are retried on network errors, 429, and 5xx.
- Webhooks: `X-SoloSmartPay-Signature = "sha256=" + HMAC_SHA256(secret, timestamp + "." + raw_body)`; requests older than 5 minutes are rejected; deduplicate on the event `id`.
