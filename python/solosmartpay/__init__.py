"""Solosmartpay API client (standard library only). Server-side use only."""

from __future__ import annotations

import hashlib
import hmac
import json
import time
import urllib.error
import urllib.parse
import urllib.request
from typing import Any, Dict, List, Optional, Union

__all__ = ["Solosmartpay", "SolosmartpayError", "verify_webhook", "VERSION"]
VERSION = "1.0.0"


class SolosmartpayError(Exception):
    """Raised for any non-2xx API response or transport failure."""

    def __init__(self, message: str, status: int = 0, errors: Optional[Dict[str, List[str]]] = None):
        super().__init__(message)
        self.status = status
        self.errors = errors or {}


class Solosmartpay:
    """
    >>> ssp = Solosmartpay(token=os.environ["SOLOSMARTPAY_TOKEN"], base_url="https://your-domain/v1")
    >>> quote = ssp.create_qris(50000, idempotency_key="ORDER-123-quote")
    """

    def __init__(self, token: str, base_url: str, timeout: float = 20, max_retries: int = 2):
        if not token or not token.startswith("ssp_"):
            raise ValueError('Token Solosmartpay harus diawali "ssp_".')
        if not base_url:
            raise ValueError("base_url wajib diisi, contoh https://your-domain/v1")
        self.token = token
        self.base_url = base_url.rstrip("/")
        self.timeout = timeout
        self.max_retries = max_retries

    def create_qris(self, amount: int, reference: Optional[str] = None, quote_id: Optional[str] = None,
                    redirect_url: Optional[str] = None, idempotency_key: Optional[str] = None) -> Dict[str, Any]:
        """Create a QRIS quote, or provision a quote when ``quote_id`` is supplied."""
        if not idempotency_key:
            raise ValueError("idempotency_key wajib diisi untuk pembuatan QRIS.")
        return self._request("POST", "/qris", {"amount": amount, "reference": reference, "quote_id": quote_id,
                                                  "redirect_url": redirect_url}, idempotency_key=idempotency_key,
                             preserve_envelope=True)

    def get_qris(self, invoice_id: str) -> Dict[str, Any]:
        return self._request("GET", "/qris/" + urllib.parse.quote(invoice_id, safe=""))

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

    def _request(self, method: str, path: str, body: Optional[dict] = None, idempotency_key: Optional[str] = None,
                 preserve_envelope: bool = False) -> Any:
        # Retries network errors, 429 and 5xx only when the request is safe to repeat.
        retryable = method == "GET" or bool(idempotency_key)
        data = None
        if body is not None:
            data = json.dumps({k: v for k, v in body.items() if v is not None}).encode()

        attempt = 0
        while True:
            attempt += 1
            req = urllib.request.Request(
                self.base_url + path,
                data=data,
                method=method,
                headers={
                    "Accept": "application/json",
                    "Content-Type": "application/json",
                    "X-API-Token": self.token,
                    **({"Idempotency-Key": idempotency_key} if idempotency_key else {}),
                    "User-Agent": "solosmartpay-python/" + VERSION,
                },
            )
            try:
                with urllib.request.urlopen(req, timeout=self.timeout) as resp:
                    payload = json.loads(resp.read() or b"{}")
                    return payload if preserve_envelope else payload.get("data", payload)
            except urllib.error.HTTPError as e:
                if (e.code == 429 or e.code >= 500) and retryable and attempt <= self.max_retries:
                    time.sleep(0.25 * 2 ** (attempt - 1))
                    continue
                try:
                    payload = json.loads(e.read() or b"{}")
                except ValueError:
                    payload = {}
                raise SolosmartpayError(payload.get("message") or "HTTP %d" % e.code, e.code, payload.get("errors")) from None
            except (urllib.error.URLError, TimeoutError) as e:
                if retryable and attempt <= self.max_retries:
                    time.sleep(0.25 * 2 ** (attempt - 1))
                    continue
                raise SolosmartpayError("Tidak bisa terhubung ke Solosmartpay: %s" % e) from None


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
