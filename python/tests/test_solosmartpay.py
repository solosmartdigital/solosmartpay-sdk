import hashlib
import hmac
import json
import os
import sys
import time
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from solosmartpay import Invoice, Payment, PaymentMethod, Qris, Solosmartpay, SolosmartpayError, WebhookEvent, parse_webhook, verify_webhook  # noqa: E402


def invoice(**over):
    base = {
        "id": "abc-123", "reference": "ORDER-1", "status": "pending", "provisioning_status": "queued", "channel": "qris",
        "method": "qris", "amount": 50000, "unique_code": 127, "total_amount": 50127, "fee": 350, "net_amount": 49650,
        "expires_at": "2026-09-27T15:15:00+07:00", "paid_at": None, "created_at": "2026-09-27T15:00:00+07:00",
        "checkout_url": None, "embed_url": None, "basic_url": None, "qris_string": None, "qris_image": None, "payment_url": None,
    }
    base.update(over)
    return base


READY = {"provisioning_status": "ready", "embed_url": "https://pay.example.com/pay/abc-123/embed",
         "checkout_url": "https://pay.example.com/pay/abc-123", "qris_image": "data:image/png;base64,AA"}


def client(queue, calls=None, max_retries=1):
    queue = list(queue)

    def transport(method, url, headers, body):
        if calls is not None:
            calls.append({"method": method, "url": url, "headers": headers, "body": body})
        status, payload = queue.pop(0) if queue else (500, {"message": "queue empty"})
        return status, json.dumps(payload).encode()

    return Solosmartpay("ssp_test", "https://pay.example.com/v1/", max_retries=max_retries, transport=transport)


class SolosmartpayTest(unittest.TestCase):
    def setUp(self):
        time.sleep = lambda _s: None  # no real waiting in retries / polling

    def test_qris_create_returns_model(self):
        calls = []
        ssp = client([(202, {"data": invoice(), "status_url": "https://pay.example.com/v1/qris/abc-123"})], calls)
        qris = ssp.qris.create(50000, "order-1-create", reference="ORDER-1")
        self.assertIsInstance(qris, Qris)
        self.assertIsInstance(qris, Invoice)
        self.assertEqual((qris.total_amount, qris.unique_code, qris.status_url), (50127, 127, "https://pay.example.com/v1/qris/abc-123"))
        self.assertIsNotNone(qris.expires_at.tzinfo)
        self.assertIsNone(qris.paid_at)
        self.assertFalse(qris.is_ready())
        self.assertEqual(calls[0]["url"], "https://pay.example.com/v1/qris")
        self.assertEqual(calls[0]["headers"]["Idempotency-Key"], "order-1-create")
        self.assertEqual(json.loads(calls[0]["body"]), {"amount": 50000, "reference": "ORDER-1"})

    def test_idempotency_key_required(self):
        ssp = client([])
        with self.assertRaises(ValueError):
            ssp.qris.create(5000, "")
        with self.assertRaises(ValueError):
            ssp.payments.create(5000, "BR", "")

    def test_wait_until_ready(self):
        calls = []
        ssp = client([(202, {"data": invoice(), "status_url": "u"}), (200, {"data": invoice()}), (200, {"data": invoice(**READY)})], calls)
        qris = ssp.qris.wait_until_ready(ssp.qris.create(50000, "order-1-create"), interval=0)
        self.assertTrue(qris.is_ready())
        self.assertEqual((qris.embed_url, qris.qris_image, len(calls)), ("https://pay.example.com/pay/abc-123/embed", "data:image/png;base64,AA", 3))

    def test_wait_until_ready_failure_and_timeout(self):
        ssp = client([(200, {"data": invoice(provisioning_status="failed", error_code="provider_down")})])
        with self.assertRaisesRegex(SolosmartpayError, "provider_down"):
            ssp.qris.wait_until_ready("abc-123", interval=0)
        ssp = client([(200, {"data": invoice()})] * 5)
        with self.assertRaisesRegex(SolosmartpayError, "belum siap"):
            ssp.qris.wait_until_ready("abc-123", timeout=0, interval=0)

    def test_cancel_and_errors(self):
        calls = []
        ssp = client([(200, {"data": invoice(status="cancelled")}), (409, {"message": "Tagihan sudah dibayar"})], calls)
        self.assertTrue(ssp.qris.cancel("abc-123").is_cancelled())
        self.assertEqual(calls[0]["method"], "DELETE")
        with self.assertRaises(SolosmartpayError) as ctx:
            ssp.payments.cancel("abc-123")
        self.assertEqual((ctx.exception.status, str(ctx.exception)), (409, "Tagihan sudah dibayar"))

    def test_payments_and_methods(self):
        calls = []
        rows = [
            {"code": "BR", "name": "BRI VA", "group": "va", "fee_type": "flat", "fee": 4000, "min_amount": 10000, "max_amount": 5000000},
            {"code": "OV", "name": "OVO", "group": "ewallet", "fee_type": "percent", "fee": 1.5, "min_amount": 1000, "max_amount": None},
        ]
        ssp = client([
            (202, {"data": invoice(channel="bank_transfer", method="BR", payment_url="https://gw.example/pay/1", **READY), "status_url": "u"}),
            (200, {"data": rows}), (200, {"data": rows}), (200, {"data": []}),
        ], calls)
        payment = ssp.payments.create(150000, "BR", "order-2-create")
        self.assertIsInstance(payment, Payment)
        self.assertEqual((payment.method, payment.payment_url, payment.is_ready()), ("BR", "https://gw.example/pay/1", True))
        self.assertEqual(json.loads(calls[0]["body"]), {"amount": 150000, "method": "BR"})
        methods = ssp.methods.all()
        self.assertIsInstance(methods[0], PaymentMethod)
        self.assertTrue(methods[0].is_virtual_account() and methods[1].is_ewallet())
        self.assertTrue(methods[0].accepts(10000) and not methods[0].accepts(9999) and not methods[0].accepts(5000001) and methods[1].accepts(99999999))
        self.assertEqual(ssp.methods.find("br").name, "BRI VA")
        self.assertIsNone(ssp.methods.find("zz"))

    def test_retries(self):
        ssp = client([(503, {"message": "down"}), (200, {"data": invoice()})])
        self.assertEqual(ssp.get_qris("abc-123")["id"], "abc-123")
        ssp = client([(503, {"message": "down"}), (503, {"message": "down"})])
        with self.assertRaisesRegex(SolosmartpayError, "down"):
            ssp.get_qris("abc-123")

    def test_flat_helpers_and_token_check(self):
        ssp = client([(202, {"data": invoice(), "status_url": "u"}), (200, {"data": invoice(status="cancelled")})])
        self.assertEqual(ssp.create_qris(50000, idempotency_key="order-3-create")["status_url"], "u")
        self.assertEqual(ssp.cancel_qris("abc-123")["status"], "cancelled")
        with self.assertRaises(ValueError):
            Solosmartpay("bad", "https://x/v1")

    def test_webhook(self):
        secret = "whsec_test"
        body = json.dumps({"id": "evt_1", "type": "payment.paid", "created_at": "2026-09-27T15:03:12+07:00",
                           "data": invoice(status="paid", paid_at="2026-09-27T15:03:10+07:00")})
        ts = str(int(time.time()))
        sig = "sha256=" + hmac.new(secret.encode(), ("%s.%s" % (ts, body)).encode(), hashlib.sha256).hexdigest()
        event = parse_webhook(body, sig, ts, secret)
        self.assertIsInstance(event, WebhookEvent)
        self.assertTrue(event.is_paid() and event.data.is_paid() and event.data.reference == "ORDER-1")
        self.assertEqual(verify_webhook(body.encode(), sig, ts, secret)["id"], "evt_1")
        with self.assertRaisesRegex(SolosmartpayError, "Signature"):
            parse_webhook(body + " ", sig, ts, secret)
        old = str(int(time.time()) - 3600)
        old_sig = "sha256=" + hmac.new(secret.encode(), ("%s.%s" % (old, body)).encode(), hashlib.sha256).hexdigest()
        with self.assertRaisesRegex(SolosmartpayError, "kedaluwarsa"):
            parse_webhook(body, old_sig, old, secret)


if __name__ == "__main__":
    unittest.main()
