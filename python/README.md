# Solosmartpay Python SDK

Python ≥ 3.8, standard library only. Server-side use only: never expose your `ssp_` token to clients.

```bash
pip install solosmartpay
```

## Create, wait, show

```python
import os
from solosmartpay import Solosmartpay, SolosmartpayError

ssp = Solosmartpay(token=os.environ["SOLOSMARTPAY_TOKEN"], base_url="https://YOUR-SOLOSMARTPAY-DOMAIN/v1")

try:
    qris = ssp.qris.create(50000, idempotency_key="order-123-create", reference="ORDER-123")   # Qris
    qris = ssp.qris.wait_until_ready(qris)                                                     # polls until checkout is available

    qris.total_amount   # 50127 (amount + unique code)
    qris.embed_url      # give this to the browser: openCheckout(embedUrl)
    qris.qris_image     # PNG data URI for a custom UI
    qris.expires_at     # datetime
except SolosmartpayError as e:
    print(e.status, e, e.errors)
```

Same flow for virtual account, e-wallet, and retail:

```python
method = ssp.methods.find("BR")                      # Optional[PaymentMethod]
method.accepts(150000)                               # inside the method's limits?

bill = ssp.payments.create(150000, "BR", idempotency_key="order-124-create", reference="ORDER-124")  # or "all"
bill = ssp.payments.wait_until_ready(bill)

ssp.methods.all(group="ewallet")                     # list one group
ssp.qris.cancel(qris.id)                             # while still pending
```

| Class | What it is |
|---|---|
| `Invoice` | `id`, `reference`, `status`, `provisioning_status`, `amount`, `unique_code`, `total_amount`, `fee`, `net_amount`, `expires_at`, `paid_at`, `checkout_url`, `embed_url`, `status_url`, and `is_pending()`, `is_paid()`, `is_expired()`, `is_cancelled()`, `is_ready()` |
| `Qris` | adds `qris_string`, `qris_image` |
| `Payment` | adds `payment_url` (None for virtual accounts) |
| `PaymentMethod` | `code`, `name`, `group`, `fee_type`, `fee`, `min_amount`, `max_amount`, `accepts()` |
| `WebhookEvent` | `id`, `type`, `data` (an `Invoice`), `is_paid()` |

Every model keeps the untouched API payload in `.raw`.

## Webhook (Flask)

```python
from flask import Flask, request
from solosmartpay import parse_webhook, SolosmartpayError

@app.post("/webhook/solosmartpay")
def webhook():
    try:
        event = parse_webhook(request.get_data(), request.headers.get("X-SoloSmartPay-Signature", ""),
                              request.headers.get("X-SoloSmartPay-Timestamp", ""), os.environ["SOLOSMARTPAY_WEBHOOK_SECRET"])
    except SolosmartpayError:
        return "", 401
    if event.is_paid():
        pass  # match event.data.reference and event.data.amount to your order; dedupe on event.id
    return "", 200
```

`verify_webhook()` takes the same arguments and returns the decoded dict.

## Sandbox

A token starting with `ssp_test_` is a **sandbox token**: it creates test payments only (no money, no KYC, nothing reaches your balance or reports). Same endpoints, limits and response shape as production.

```python
ssp = Solosmartpay(token=os.environ["SOLOSMARTPAY_SANDBOX_TOKEN"], base_url="https://your-domain/v1")
ssp.is_sandbox                                  # True

qris = ssp.qris.create(50000, idempotency_key="order-1-create")
qris.is_sandbox()                               # True

ssp.sandbox.pay(qris)                           # payment.paid goes to your webhook, flagged sandbox
ssp.sandbox.fail(qris)                          # invoice.failed
ssp.sandbox.expire(qris)                        # invoice.expired
```

`ssp.sandbox` raises with a live token. Webhooks carry `sandbox: true`: check `event.is_sandbox()` and never mark a real order paid from one.

## Notes

- Creation returns `202`; use `wait_until_ready()` (or poll `qris.status_url`) before showing the checkout. Reuse an `idempotency_key` only with the same request body.
- The flat helpers (`create_qris()`, `get_qris()`, `cancel_qris()`, `create_payment()`, `get_payment()`, `cancel_payment()`, `payment_methods()`) still return raw dicts. The old `quote_id` argument is gone: the API no longer has a quote step.
- Tests without network: `Solosmartpay(token, base_url, transport=fn)`, then `python -m unittest discover -s tests`.
