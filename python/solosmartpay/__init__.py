"""Solosmartpay API client (standard library only). Server-side use only.

    ssp = Solosmartpay(token=os.environ["SOLOSMARTPAY_TOKEN"], base_url="https://your-domain/v1")

    qris = ssp.qris.create(50000, idempotency_key="order-123-create", reference="ORDER-123")  # Qris
    qris = ssp.qris.wait_until_ready(qris)                                                   # embed_url / qris_image filled

    bill = ssp.payments.create(150000, "BR", idempotency_key="order-124-create")             # Payment
    methods = ssp.methods.all()                                                              # [PaymentMethod]

The flat helpers (create_qris(), get_qris(), ...) still return raw dicts.
"""

from __future__ import annotations

import hashlib
import hmac
import json
import time
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass, field
from datetime import datetime
from typing import Any, Callable, Dict, List, Optional, Tuple, Union

__all__ = [
    "Solosmartpay", "SolosmartpayError", "Invoice", "Qris", "Payment", "PaymentMethod", "WebhookEvent",
    "verify_webhook", "parse_webhook", "VERSION",
]
VERSION = "1.3.0"

# transport(method, url, headers, body) -> (status, response_bytes); raise OSError for network failures.
Transport = Callable[[str, str, Dict[str, str], Optional[bytes]], Tuple[int, bytes]]


class SolosmartpayError(Exception):
    """Raised for any non-2xx API response or transport failure."""

    def __init__(self, message: str, status: int = 0, errors: Optional[Dict[str, List[str]]] = None):
        super().__init__(message)
        self.status = status
        self.errors = errors or {}


def _date(value: Any) -> Optional[datetime]:
    if not value:
        return None
    return datetime.fromisoformat(str(value).replace("Z", "+00:00"))


def _text(data: Dict[str, Any], key: str) -> Optional[str]:
    value = data.get(key)
    return None if value in (None, "") else str(value)


@dataclass
class Invoice:
    """An invoice. Money is integer Rupiah, dates are datetimes. ``raw`` is the untouched API payload."""

    STATUS_PENDING = "pending"
    STATUS_PAID = "paid"
    STATUS_EXPIRED = "expired"
    STATUS_CANCELLED = "cancelled"

    id: str
    reference: Optional[str]
    status: str
    provisioning_status: Optional[str]
    channel: Optional[str]
    method: Optional[str]
    amount: int
    unique_code: Optional[int]
    total_amount: int
    fee: int
    net_amount: int
    expires_at: Optional[datetime]
    paid_at: Optional[datetime]
    created_at: Optional[datetime]
    checkout_url: Optional[str]
    #: Pass to the browser's openCheckout() / SoloSmartPay.open().
    embed_url: Optional[str]
    basic_url: Optional[str]
    redirect_url: Optional[str]
    error_code: Optional[str]
    #: Set on create: poll it (or call wait_until_ready) until the invoice is ready.
    status_url: Optional[str] = None
    raw: Dict[str, Any] = field(default_factory=dict, repr=False)
    #: True for a test payment made with a sandbox token (ssp_test_…): no real money moves.
    sandbox: bool = False
    #: Virtual account number (sandbox returns a sample one).
    va_number: Optional[str] = None

    @classmethod
    def _base(cls, data: Dict[str, Any], status_url: Optional[str]) -> Dict[str, Any]:
        unique = data.get("unique_code")
        return dict(
            id=str(data.get("id", "")), reference=_text(data, "reference"), status=str(data.get("status", "")),
            provisioning_status=_text(data, "provisioning_status"), channel=_text(data, "channel"),
            method=_text(data, "method"), amount=int(data.get("amount") or 0),
            unique_code=None if unique is None else int(unique), total_amount=int(data.get("total_amount") or 0),
            fee=int(data.get("fee") or 0), net_amount=int(data.get("net_amount") or 0),
            expires_at=_date(data.get("expires_at")), paid_at=_date(data.get("paid_at")),
            created_at=_date(data.get("created_at")), checkout_url=_text(data, "checkout_url"),
            embed_url=_text(data, "embed_url"), basic_url=_text(data, "basic_url"),
            redirect_url=_text(data, "redirect_url"), error_code=_text(data, "error_code"),
            status_url=status_url, raw=data, sandbox=data.get("sandbox") is True, va_number=_text(data, "va_number"),
        )

    @classmethod
    def from_dict(cls, data: Dict[str, Any], status_url: Optional[str] = None) -> "Invoice":
        return cls(**cls._base(data, status_url))

    def is_sandbox(self) -> bool:
        return self.sandbox

    def is_pending(self) -> bool:
        return self.status == self.STATUS_PENDING

    def is_paid(self) -> bool:
        return self.status == self.STATUS_PAID

    def is_expired(self) -> bool:
        return self.status == self.STATUS_EXPIRED

    def is_cancelled(self) -> bool:
        return self.status == self.STATUS_CANCELLED

    def provisioning_failed(self) -> bool:
        return self.provisioning_status == "failed"

    def is_ready(self) -> bool:
        """True once the customer can be sent to checkout (``embed_url`` / ``checkout_url`` are filled)."""
        return self.embed_url is not None or self.provisioning_status == "ready"


@dataclass
class Qris(Invoice):
    """A QRIS invoice. ``qris_image`` (PNG data URI) and ``qris_string`` stay None until ready and once not pending."""

    qris_string: Optional[str] = None
    qris_image: Optional[str] = None

    @classmethod
    def from_dict(cls, data: Dict[str, Any], status_url: Optional[str] = None) -> "Qris":
        return cls(**cls._base(data, status_url), qris_string=_text(data, "qris_string"), qris_image=_text(data, "qris_image"))


@dataclass
class Payment(Invoice):
    """A bank virtual account, e-wallet, or retail invoice."""

    #: Gateway page for e-wallet / retail. None for virtual accounts: pay those from the Solosmartpay checkout.
    payment_url: Optional[str] = None

    @classmethod
    def from_dict(cls, data: Dict[str, Any], status_url: Optional[str] = None) -> "Payment":
        return cls(**cls._base(data, status_url), payment_url=_text(data, "payment_url"))


@dataclass
class PaymentMethod:
    """A method enabled for your store (``GET /v1/payment-methods``)."""

    GROUP_VIRTUAL_ACCOUNT = "va"
    GROUP_EWALLET = "ewallet"
    GROUP_RETAIL = "retail"
    GROUP_PAYLATER = "paylater"

    code: str
    name: str
    group: str
    fee_type: str
    fee: float
    min_amount: int
    max_amount: Optional[int]
    raw: Dict[str, Any] = field(default_factory=dict, repr=False)

    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> "PaymentMethod":
        maximum = data.get("max_amount")
        return cls(code=str(data.get("code", "")), name=str(data.get("name", "")), group=str(data.get("group", "")),
                   fee_type=str(data.get("fee_type", "")), fee=float(data.get("fee") or 0),
                   min_amount=int(data.get("min_amount") or 0), max_amount=None if maximum is None else int(maximum), raw=data)

    def is_virtual_account(self) -> bool:
        return self.group == self.GROUP_VIRTUAL_ACCOUNT

    def is_ewallet(self) -> bool:
        return self.group == self.GROUP_EWALLET

    def is_retail(self) -> bool:
        return self.group == self.GROUP_RETAIL

    def accepts(self, amount: int) -> bool:
        """Whether ``amount`` is inside this method's limits."""
        return amount >= self.min_amount and (self.max_amount is None or amount <= self.max_amount)


@dataclass
class WebhookEvent:
    """A verified webhook delivery. Deliveries are at-least-once: deduplicate on ``id``."""

    PAYMENT_PAID = "payment.paid"
    INVOICE_EXPIRED = "invoice.expired"
    INVOICE_FAILED = "invoice.failed"
    INVOICE_CANCELLED = "invoice.cancelled"
    TEST = "webhook.test"

    id: str
    type: str
    created_at: Optional[datetime]
    data: Invoice
    raw: Dict[str, Any] = field(default_factory=dict, repr=False)
    #: True when the delivery comes from a sandbox payment: never mark a real order paid from it.
    sandbox: bool = False

    @classmethod
    def from_dict(cls, event: Dict[str, Any]) -> "WebhookEvent":
        payload = event.get("data")
        payload = payload if isinstance(payload, dict) else {}
        return cls(id=str(event["id"]), type=str(event["type"]), created_at=_date(event.get("created_at")),
                   data=Invoice.from_dict(payload), raw=event,
                   sandbox=event.get("sandbox") is True or payload.get("sandbox") is True)

    def is_paid(self) -> bool:
        return self.type == self.PAYMENT_PAID

    def is_sandbox(self) -> bool:
        return self.sandbox

    def is_test(self) -> bool:
        return self.type == self.TEST


def _wait_until_ready(invoice_or_id: Union[Invoice, str], fetch: Callable[[str], Invoice], timeout: float, interval: float) -> Any:
    deadline = time.monotonic() + timeout
    current = fetch(invoice_or_id) if isinstance(invoice_or_id, str) else invoice_or_id
    while True:
        if current.provisioning_failed():
            raise SolosmartpayError("Tagihan gagal disiapkan%s." % (" (%s)" % current.error_code if current.error_code else ""))
        if current.is_ready() or not current.is_pending():
            return current
        if time.monotonic() >= deadline:
            raise SolosmartpayError("Tagihan belum siap setelah %d detik." % timeout)
        time.sleep(interval)
        current = fetch(current.id)


class QrisResource:
    """QRIS invoices: ``ssp.qris.create / get / cancel / wait_until_ready``."""

    def __init__(self, client: "Solosmartpay"):
        self._client = client

    def create(self, amount: int, idempotency_key: str, reference: Optional[str] = None,
               redirect_url: Optional[str] = None) -> Qris:
        """Create a QRIS invoice. Provisioning is asynchronous: call wait_until_ready() before using the QR or embed_url."""
        if not idempotency_key:
            raise ValueError("idempotency_key wajib diisi untuk pembuatan QRIS.")
        res = self._client._request("POST", "/qris", {"amount": amount, "reference": reference, "redirect_url": redirect_url},
                                    idempotency_key=idempotency_key, preserve_envelope=True)
        return Qris.from_dict(res["data"], res.get("status_url"))

    def get(self, invoice_id: str) -> Qris:
        return Qris.from_dict(self._client._request("GET", "/qris/" + urllib.parse.quote(invoice_id, safe="")))

    def cancel(self, invoice_id: str) -> Qris:
        """Cancel a pending invoice. Idempotent; HTTP 409 once paid or expired."""
        return Qris.from_dict(self._client._request("DELETE", "/qris/" + urllib.parse.quote(invoice_id, safe="")))

    def wait_until_ready(self, qris: Union[Qris, str], timeout: float = 30, interval: float = 1.0) -> Qris:
        return _wait_until_ready(qris, self.get, timeout, interval)


class PaymentsResource:
    """Bank VA, e-wallet, and retail invoices: ``ssp.payments.create / get / cancel / wait_until_ready``."""

    def __init__(self, client: "Solosmartpay"):
        self._client = client

    def create(self, amount: int, method: str, idempotency_key: str, reference: Optional[str] = None,
               redirect_url: Optional[str] = None) -> Payment:
        """``method`` is a code from ``ssp.methods.all()``, or "all" to let the customer choose at checkout."""
        if not idempotency_key:
            raise ValueError("idempotency_key wajib diisi untuk pembuatan pembayaran.")
        res = self._client._request("POST", "/payments", {"amount": amount, "method": method, "reference": reference,
                                                          "redirect_url": redirect_url},
                                    idempotency_key=idempotency_key, preserve_envelope=True)
        return Payment.from_dict(res["data"], res.get("status_url"))

    def get(self, invoice_id: str) -> Payment:
        return Payment.from_dict(self._client._request("GET", "/payments/" + urllib.parse.quote(invoice_id, safe="")))

    def cancel(self, invoice_id: str) -> Payment:
        """Cancel a pending invoice. Idempotent; HTTP 409 once paid or expired."""
        return Payment.from_dict(self._client._request("DELETE", "/payments/" + urllib.parse.quote(invoice_id, safe="")))

    def wait_until_ready(self, payment: Union[Payment, str], timeout: float = 30, interval: float = 1.0) -> Payment:
        return _wait_until_ready(payment, self.get, timeout, interval)


class SandboxResource:
    """Sandbox helpers: ``ssp.sandbox.simulate / pay / fail / expire``.

    A sandbox token (``ssp_test_…``) creates test payments only (no money, no KYC); these decide how one ends.
    """

    RESULTS = ("paid", "failed", "expired")

    def __init__(self, client: "Solosmartpay"):
        self._client = client

    def simulate(self, invoice: Union[Invoice, str], result: str) -> Invoice:
        """Decide how a pending sandbox payment ends. Raises a 409 error when it already finished."""
        if not self._client.is_sandbox:
            raise SolosmartpayError("Simulasi hanya bisa dengan token sandbox (ssp_test_…).")
        if result not in self.RESULTS:
            raise ValueError("Hasil simulasi harus salah satu dari: %s." % ", ".join(self.RESULTS))
        invoice_id = invoice if isinstance(invoice, str) else invoice.id
        return Invoice.from_dict(self._client._request("POST", "/sandbox/payments/%s/simulate" % urllib.parse.quote(invoice_id, safe=""), {"result": result}))

    def pay(self, invoice: Union[Invoice, str]) -> Invoice:
        return self.simulate(invoice, "paid")

    def fail(self, invoice: Union[Invoice, str]) -> Invoice:
        return self.simulate(invoice, "failed")

    def expire(self, invoice: Union[Invoice, str]) -> Invoice:
        return self.simulate(invoice, "expired")


class PaymentMethodsResource:
    """Methods enabled for your store: ``ssp.methods.all / find``."""

    def __init__(self, client: "Solosmartpay"):
        self._client = client

    def all(self, group: Optional[str] = None) -> List[PaymentMethod]:
        methods = [PaymentMethod.from_dict(row) for row in self._client._request("GET", "/payment-methods")]
        return methods if group is None else [m for m in methods if m.group == group]

    def find(self, code: str) -> Optional[PaymentMethod]:
        return next((m for m in self.all() if m.code.lower() == code.lower()), None)


class Solosmartpay:
    """
    >>> ssp = Solosmartpay(token=os.environ["SOLOSMARTPAY_TOKEN"], base_url="https://your-domain/v1")
    >>> qris = ssp.qris.create(50000, idempotency_key="order-123-create", reference="ORDER-123")
    """

    def __init__(self, token: str, base_url: str, timeout: float = 20, max_retries: int = 2,
                 transport: Optional[Transport] = None):
        if not token or not token.startswith("ssp_"):
            raise ValueError('Token Solosmartpay harus diawali "ssp_".')
        if not base_url:
            raise ValueError("base_url wajib diisi, contoh https://your-domain/v1")
        self.token = token
        self.base_url = base_url.rstrip("/")
        self.timeout = timeout
        self.max_retries = max_retries
        self._transport = transport
        self.qris = QrisResource(self)
        self.payments = PaymentsResource(self)
        self.methods = PaymentMethodsResource(self)
        self.sandbox = SandboxResource(self)

    @property
    def is_sandbox(self) -> bool:
        """True for a sandbox token (ssp_test_…): its payments are test payments and never move money."""
        return self.token.startswith("ssp_test_")

    # Flat helpers: return raw API dicts.

    def create_qris(self, amount: int, reference: Optional[str] = None, redirect_url: Optional[str] = None,
                    idempotency_key: Optional[str] = None) -> Dict[str, Any]:
        """Create and queue a QRIS invoice. Returns ``{"data": ..., "status_url": ...}``."""
        if not idempotency_key:
            raise ValueError("idempotency_key wajib diisi untuk pembuatan QRIS.")
        return self._request("POST", "/qris", {"amount": amount, "reference": reference, "redirect_url": redirect_url},
                             idempotency_key=idempotency_key, preserve_envelope=True)

    def get_qris(self, invoice_id: str) -> Dict[str, Any]:
        return self._request("GET", "/qris/" + urllib.parse.quote(invoice_id, safe=""))

    def cancel_qris(self, invoice_id: str) -> Dict[str, Any]:
        return self._request("DELETE", "/qris/" + urllib.parse.quote(invoice_id, safe=""))

    def payment_methods(self) -> List[Dict[str, Any]]:
        """Bank VA / e-wallet methods enabled for your store, with their fees."""
        return self._request("GET", "/payment-methods")

    def create_payment(self, amount: int, method: str, reference: Optional[str] = None,
                       redirect_url: Optional[str] = None, idempotency_key: Optional[str] = None) -> Dict[str, Any]:
        """Queue a bank VA / e-wallet bill for provisioning."""
        if not idempotency_key:
            raise ValueError("idempotency_key wajib diisi untuk pembuatan pembayaran.")
        return self._request("POST", "/payments", {"amount": amount, "method": method, "reference": reference,
                                                      "redirect_url": redirect_url}, idempotency_key=idempotency_key,
                             preserve_envelope=True)

    def get_payment(self, invoice_id: str) -> Dict[str, Any]:
        return self._request("GET", "/payments/" + urllib.parse.quote(invoice_id, safe=""))

    def cancel_payment(self, invoice_id: str) -> Dict[str, Any]:
        return self._request("DELETE", "/payments/" + urllib.parse.quote(invoice_id, safe=""))

    def _send(self, method: str, url: str, headers: Dict[str, str], data: Optional[bytes]) -> Tuple[int, bytes]:
        if self._transport is not None:
            return self._transport(method, url, headers, data)
        req = urllib.request.Request(url, data=data, method=method, headers=headers)
        try:
            with urllib.request.urlopen(req, timeout=self.timeout) as resp:
                return resp.status, resp.read()
        except urllib.error.HTTPError as e:
            return e.code, e.read()
        except (urllib.error.URLError, TimeoutError) as e:
            raise OSError(str(e)) from None

    def _request(self, method: str, path: str, body: Optional[dict] = None, idempotency_key: Optional[str] = None,
                 preserve_envelope: bool = False) -> Any:
        # Retries network errors, 429 and 5xx only when the request is safe to repeat.
        retryable = method in ("GET", "DELETE") or bool(idempotency_key)
        data = None
        if body is not None:
            data = json.dumps({k: v for k, v in body.items() if v is not None}).encode()
        headers = {
            "Accept": "application/json",
            "Content-Type": "application/json",
            "X-API-Token": self.token,
            **({"Idempotency-Key": idempotency_key} if idempotency_key else {}),
            "User-Agent": "solosmartpay-python/" + VERSION,
        }

        attempt = 0
        while True:
            attempt += 1
            try:
                status, raw = self._send(method, self.base_url + path, headers, data)
            except OSError as e:
                if retryable and attempt <= self.max_retries:
                    time.sleep(0.25 * 2 ** (attempt - 1))
                    continue
                raise SolosmartpayError("Tidak bisa terhubung ke Solosmartpay: %s" % e) from None

            if (status == 429 or status >= 500) and retryable and attempt <= self.max_retries:
                time.sleep(0.25 * 2 ** (attempt - 1))
                continue
            try:
                payload = json.loads(raw or b"{}")
            except ValueError:
                payload = {}
            if not 200 <= status < 300:
                raise SolosmartpayError(payload.get("message") or "HTTP %d" % status, status, payload.get("errors"))
            return payload if preserve_envelope else payload.get("data", payload)


def verify_webhook(raw_body: Union[bytes, str], signature: str, timestamp: str, secret: str, tolerance_seconds: int = 300) -> Dict[str, Any]:
    """Verify a Solosmartpay webhook and return the event. Pass the RAW body. Dedupe on event["id"]."""
    if not secret:
        raise SolosmartpayError("Webhook secret kosong.")
    body = raw_body.decode() if isinstance(raw_body, bytes) else raw_body
    if not str(timestamp).isdigit() or abs(time.time() - int(timestamp)) > tolerance_seconds:
        raise SolosmartpayError("Timestamp webhook tidak valid atau kedaluwarsa (kemungkinan replay).")
    expected = "sha256=" + hmac.new(secret.encode(), ("%s.%s" % (timestamp, body)).encode(), hashlib.sha256).hexdigest()
    if not hmac.compare_digest(expected, signature or ""):
        raise SolosmartpayError("Signature webhook tidak valid.")
    event = json.loads(body)
    if not isinstance(event, dict) or "id" not in event or "type" not in event:
        raise SolosmartpayError("Body webhook tidak valid.")
    return event


def parse_webhook(raw_body: Union[bytes, str], signature: str, timestamp: str, secret: str, tolerance_seconds: int = 300) -> WebhookEvent:
    """Same checks as verify_webhook(), returned as a WebhookEvent."""
    return WebhookEvent.from_dict(verify_webhook(raw_body, signature, timestamp, secret, tolerance_seconds))
