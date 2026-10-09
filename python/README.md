# Solosmartpay Python SDK

Python ≥ 3.8, standard library only. Server-side use only — never expose your `ssp_` token to clients.

```bash
pip install solosmartpay
```

```python
import os
from solosmartpay import Solosmartpay, SolosmartpayError

ssp = Solosmartpay(token=os.environ["SOLOSMARTPAY_TOKEN"], base_url="https://YOUR-SOLOSMARTPAY-DOMAIN/v1")

try:
    created = ssp.create_qris(50000, reference="ORDER-123", idempotency_key="order-123-create")
    print(ssp.get_qris(created["data"]["id"])["provisioning_status"])

    methods = ssp.payment_methods()
    bill = ssp.create_payment(150000, "BR", reference="ORDER-124", idempotency_key="order-124-create")
except SolosmartpayError as e:
    print(e.status, e, e.errors)
```

## Webhook (Flask)

```python
from flask import Flask, request
from solosmartpay import verify_webhook, SolosmartpayError

@app.post("/webhook/solosmartpay")
def webhook():
    try:
        event = verify_webhook(request.get_data(), request.headers.get("X-SoloSmartPay-Signature", ""),
                               request.headers.get("X-SoloSmartPay-Timestamp", ""), os.environ["SOLOSMARTPAY_WEBHOOK_SECRET"])
    except SolosmartpayError:
        return "", 401
    if event["type"] == "payment.paid":
        pass  # mark order event["data"]["reference"] paid — dedupe on event["id"]
    return "", 200
```

Provisioned creates return `202`, `data`, and `status_url`. Poll the URL until `data.provisioning_status` is `ready`; QRIS uses the same asynchronous provisioning flow as other channels. Reuse an `idempotency_key` only with the same request body.
