# Solosmartpay for WooCommerce

Accept QRIS, bank Virtual Account, e-wallet, and retail payments in WooCommerce through [Solosmartpay](https://solosmartpay.com). The customer picks the method on the Solosmartpay payment page; WooCommerce marks the order paid from a signed webhook.

| | |
|---|---|
| WordPress | 6.2+ |
| WooCommerce | 7.0+ (classic checkout and the Checkout block; HPOS compatible) |
| PHP | 7.4+ |
| Currency | IDR only |
| Dependencies | none |

## Install

1. Download `solosmartpay-woocommerce.zip` (or build it, see below).
2. WordPress admin → **Plugins → Add New → Upload Plugin** → upload the zip → **Activate**.

## Set up

In the Solosmartpay **Developer Hub**:

1. **API Tokens** → create a token with scopes `payments:write` and `read`. Add your server's IP to the token's allowlist.
2. **Webhooks** → add the URL shown on the plugin settings page (it looks like `https://your-shop.com/wc-api/solosmartpay/`). It must be HTTPS on port 443 and publicly reachable. Copy the webhook secret.

In WordPress: **WooCommerce → Settings → Payments → Solosmartpay**:

| Setting | Value |
|---|---|
| Enable | on |
| Base URL API | `https://solosmartpay.com/v1` (or your own domain + `/v1`) |
| API Token | the `ssp_…` token |
| Webhook Secret | the webhook secret |

Use the **webhook.test** button in the Developer Hub to check the connection: the delivery should return `200`.

## How it works

```
Checkout → POST /v1/payments (method "all") → redirect to checkout_url → customer pays
                                                                  ↓
                          webhook payment.paid → order marked paid (stock reduced, emails sent)
```

| Event | What the plugin does |
|---|---|
| Place order | Creates the invoice, order becomes **On hold**, cart is emptied, customer is redirected. |
| Customer retries | The pending invoice is reused. If the order total changed, the old invoice is cancelled and a new one is created. |
| `payment.paid` | Checks the amount against the order total, then `payment_complete()`. A mismatch leaves the order **On hold** with a note. |
| `invoice.expired` / `invoice.cancelled` | A pending or on-hold order is **Cancelled**. |
| `invoice.failed` | A pending or on-hold order is **Failed**. |
| Order cancelled in WooCommerce | The pending invoice is cancelled at Solosmartpay (best effort). |

Security: the webhook is rejected unless `X-SoloSmartPay-Signature` matches `HMAC_SHA256(secret, timestamp + "." + raw_body)` (constant-time compare, 300 second tolerance); event ids are de-duplicated for 24 hours; the redirect after payment is never trusted as proof of payment.

## Limits

- Currency must be IDR. Order total must be between Rp1 and Rp50.000.000.
- One gateway with `method: "all"`. The customer chooses QRIS, VA, e-wallet, or retail on the Solosmartpay page.
- `redirect_url` is only sent when the shop runs on HTTPS (Solosmartpay requires `https://`).

## Troubleshooting

| Symptom | Check |
|---|---|
| Gateway missing at checkout | Currency is IDR, token and webhook secret are filled in, gateway enabled. |
| "Pembayaran tidak bisa dibuat" | Turn on **Log debug**, then WooCommerce → Status → Logs → `solosmartpay`. `403` usually means the server IP is not in the token allowlist; `401` means a wrong token; `422` often means KYC is not approved yet. |
| Order stays On hold after paying | The webhook is not arriving or is rejected: confirm the URL and secret, and that the site is reachable over HTTPS. |

## Build the zip

```bash
./bin/build-zip.sh        # writes dist/solosmartpay-woocommerce.zip
```

## Tests

```bash
php tests/signature-test.php          # webhook signature checks, no WordPress needed
```

`tests/e2e/` holds an end-to-end suite that runs inside a real WordPress + WooCommerce install against a mock Solosmartpay API (see `tests/e2e/README.md`).

## License

MIT
