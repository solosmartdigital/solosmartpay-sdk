// Solosmartpay API client for Node.js 18+ (also Bun / Deno / server runtimes with fetch + node:crypto).
// Server-side only: never expose your ssp_ token in browser or mobile code.
import { createHmac, timingSafeEqual } from 'node:crypto';

export const VERSION = '1.2.0';

export class SolosmartpayError extends Error {
  constructor(message, status = 0, errors = {}) {
    super(message);
    this.name = 'SolosmartpayError';
    this.status = status;
    this.errors = errors;
  }
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

export class Solosmartpay {
  /**
   * @param {{ token: string, baseUrl: string, timeoutMs?: number, maxRetries?: number }} options
   */
  constructor({ token, baseUrl, timeoutMs = 20000, maxRetries = 2 }) {
    if (!token || !token.startsWith('ssp_')) throw new Error('Token Solosmartpay harus diawali "ssp_".');
    if (!baseUrl) throw new Error('baseUrl wajib diisi, contoh https://your-domain/v1');
    if (typeof window !== 'undefined') console.warn('[solosmartpay] Jangan pakai SDK ini di browser: token akan bocor.');
    this.token = token;
    this.baseUrl = baseUrl.replace(/\/+$/, '');
    this.timeoutMs = timeoutMs;
    this.maxRetries = maxRetries;
    this.qris = new QrisResource(this);
    this.payments = new PaymentsResource(this);
    this.methods = new PaymentMethodsResource(this);
  }

  /** Create and queue a QRIS invoice for provisioning. */
  createQris({ amount, reference, redirectUrl, idempotencyKey } = {}) {
    if (!idempotencyKey) throw new Error('idempotencyKey wajib diisi untuk pembuatan QRIS.');
    return this.request('POST', '/qris', { amount, reference, redirect_url: redirectUrl }, idempotencyKey, true);
  }

  getQris(id) {
    return this.request('GET', `/qris/${encodeURIComponent(id)}`);
  }

  /** Bank VA / e-wallet methods enabled for your store, with their fees. */
  paymentMethods() {
    return this.request('GET', '/payment-methods');
  }

  /** Queue a bank VA / e-wallet bill for provisioning. */
  createPayment({ amount, method, reference, redirectUrl, idempotencyKey } = {}) {
    if (!idempotencyKey) throw new Error('idempotencyKey wajib diisi untuk pembuatan pembayaran.');
    return this.request('POST', '/payments', { amount, method, reference, redirect_url: redirectUrl }, idempotencyKey, true);
  }

  getPayment(id) {
    return this.request('GET', `/payments/${encodeURIComponent(id)}`);
  }

  /** Cancel a pending QRIS invoice (idempotent; 409 once paid or expired). */
  cancelQris(id) {
    return this.request('DELETE', `/qris/${encodeURIComponent(id)}`);
  }

  /** Cancel a pending bank VA / e-wallet bill (idempotent; 409 once paid or expired). */
  cancelPayment(id) {
    return this.request('DELETE', `/payments/${encodeURIComponent(id)}`);
  }

  /** Low-level call used by the resource classes. @internal */
  // Retries network errors, 429 and 5xx only when the request is safe to repeat.
  async request(method, path, body, idempotencyKey, preserveEnvelope = false) {
    const idempotent = method === 'GET' || method === 'DELETE' || Boolean(idempotencyKey);
    const retryable = method === 'GET' || idempotent;
    const payload = body ? JSON.stringify(Object.fromEntries(Object.entries(body).filter(([, v]) => v != null))) : undefined;

    for (let attempt = 1; ; attempt++) {
      let res;
      try {
        res = await fetch(this.baseUrl + path, {
          method,
          body: payload,
          signal: AbortSignal.timeout(this.timeoutMs),
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-API-Token': this.token,
            ...(idempotencyKey ? { 'Idempotency-Key': idempotencyKey } : {}),
            'User-Agent': `solosmartpay-node/${VERSION}`,
          },
        });
      } catch (err) {
        if (retryable && attempt <= this.maxRetries) { await sleep(250 * 2 ** (attempt - 1)); continue; }
        throw new SolosmartpayError(`Tidak bisa terhubung ke Solosmartpay: ${err.message}`);
      }

      if ((res.status === 429 || res.status >= 500) && retryable && attempt <= this.maxRetries) {
        await sleep(250 * 2 ** (attempt - 1));
        continue;
      }

      const json = await res.json().catch(() => ({}));
      if (!res.ok) throw new SolosmartpayError(json.message || `HTTP ${res.status}`, res.status, json.errors || {});
      return preserveEnvelope ? json : (json.data ?? json);
    }
  }
}

const date = (value) => (value ? new Date(value) : null);
const text = (value) => (value === undefined || value === null || value === '' ? null : String(value));

/** An invoice returned by the API. QRIS and bank/e-wallet/retail invoices share these fields. Money is integer Rupiah. */
export class Invoice {
  static STATUS = Object.freeze({ PENDING: 'pending', PAID: 'paid', EXPIRED: 'expired', CANCELLED: 'cancelled' });
  static PROVISIONING = Object.freeze({ QUEUED: 'queued', PROCESSING: 'processing', READY: 'ready', FAILED: 'failed', SELECTION_REQUIRED: 'selection_required' });

  /** @param {Record<string, any>} data the `data` object of an API response */
  constructor(data, statusUrl = null) {
    this.raw = data;
    this.id = String(data.id ?? '');
    this.reference = text(data.reference);
    this.status = String(data.status ?? '');
    this.provisioningStatus = text(data.provisioning_status);
    this.channel = text(data.channel);
    this.method = text(data.method);
    this.amount = Number(data.amount ?? 0);
    this.uniqueCode = data.unique_code == null ? null : Number(data.unique_code);
    this.totalAmount = Number(data.total_amount ?? 0);
    this.fee = Number(data.fee ?? 0);
    this.netAmount = Number(data.net_amount ?? 0);
    this.expiresAt = date(data.expires_at);
    this.paidAt = date(data.paid_at);
    this.createdAt = date(data.created_at);
    this.checkoutUrl = text(data.checkout_url);
    this.embedUrl = text(data.embed_url);
    this.basicUrl = text(data.basic_url);
    this.redirectUrl = text(data.redirect_url);
    this.errorCode = text(data.error_code);
    this.statusUrl = statusUrl;
  }

  isPending() { return this.status === Invoice.STATUS.PENDING; }
  isPaid() { return this.status === Invoice.STATUS.PAID; }
  isExpired() { return this.status === Invoice.STATUS.EXPIRED; }
  isCancelled() { return this.status === Invoice.STATUS.CANCELLED; }
  provisioningFailed() { return this.provisioningStatus === Invoice.PROVISIONING.FAILED; }
  /** True once the customer can be sent to checkout (`embedUrl` / `checkoutUrl` are filled). */
  isReady() { return this.embedUrl !== null || this.provisioningStatus === Invoice.PROVISIONING.READY; }
  toJSON() { return this.raw; }
}

/** A QRIS invoice. `qrisImage` (PNG data URI) and `qrisString` stay null until ready, and once no longer pending. */
export class Qris extends Invoice {
  constructor(data, statusUrl = null) {
    super(data, statusUrl);
    this.qrisString = text(data.qris_string);
    this.qrisImage = text(data.qris_image);
  }
}

/** A bank virtual account, e-wallet, or retail invoice. */
export class Payment extends Invoice {
  constructor(data, statusUrl = null) {
    super(data, statusUrl);
    /** Gateway page for e-wallet / retail. Null for virtual accounts: pay those from the Solosmartpay checkout. */
    this.paymentUrl = text(data.payment_url);
  }
}

/** A method enabled for your store (`GET /v1/payment-methods`). */
export class PaymentMethod {
  static GROUP = Object.freeze({ VIRTUAL_ACCOUNT: 'va', EWALLET: 'ewallet', RETAIL: 'retail', PAYLATER: 'paylater' });

  constructor(data) {
    this.raw = data;
    this.code = String(data.code ?? '');
    this.name = String(data.name ?? '');
    this.group = String(data.group ?? '');
    this.feeType = String(data.fee_type ?? '');
    this.fee = Number(data.fee ?? 0);
    this.minAmount = Number(data.min_amount ?? 0);
    this.maxAmount = data.max_amount == null ? null : Number(data.max_amount);
  }

  isVirtualAccount() { return this.group === PaymentMethod.GROUP.VIRTUAL_ACCOUNT; }
  isEwallet() { return this.group === PaymentMethod.GROUP.EWALLET; }
  isRetail() { return this.group === PaymentMethod.GROUP.RETAIL; }
  /** Whether `amount` is inside this method's limits. */
  accepts(amount) { return amount >= this.minAmount && (this.maxAmount === null || amount <= this.maxAmount); }
  toJSON() { return this.raw; }
}

/** A verified webhook delivery. Deliveries are at-least-once: deduplicate on `id`. */
export class WebhookEvent {
  static TYPE = Object.freeze({ PAYMENT_PAID: 'payment.paid', INVOICE_EXPIRED: 'invoice.expired', INVOICE_FAILED: 'invoice.failed', INVOICE_CANCELLED: 'invoice.cancelled', TEST: 'webhook.test' });

  constructor(event) {
    this.raw = event;
    this.id = String(event.id);
    this.type = String(event.type);
    this.createdAt = date(event.created_at);
    this.data = new Invoice(event.data ?? {});
  }

  isPaid() { return this.type === WebhookEvent.TYPE.PAYMENT_PAID; }
  isTest() { return this.type === WebhookEvent.TYPE.TEST; }
}

// Polls until the checkout can be shown. Rejects when provisioning fails or the timeout passes.
async function waitUntilReady(invoiceOrId, fetchInvoice, { timeoutMs = 30000, intervalMs = 1000 } = {}) {
  const deadline = Date.now() + timeoutMs;
  let current = typeof invoiceOrId === 'string' ? await fetchInvoice(invoiceOrId) : invoiceOrId;
  for (;;) {
    if (current.provisioningFailed()) throw new SolosmartpayError(`Tagihan gagal disiapkan${current.errorCode ? ` (${current.errorCode})` : ''}.`);
    if (current.isReady() || !current.isPending()) return current;
    if (Date.now() >= deadline) throw new SolosmartpayError(`Tagihan belum siap setelah ${Math.round(timeoutMs / 1000)} detik.`);
    await sleep(intervalMs);
    current = await fetchInvoice(current.id);
  }
}

const compact = (body) => Object.fromEntries(Object.entries(body).filter(([, v]) => v != null));

/** QRIS invoices: `ssp.qris.create / get / cancel / waitUntilReady`. */
export class QrisResource {
  constructor(client) { this.client = client; }

  /** Create a QRIS invoice. Provisioning is asynchronous: call waitUntilReady() before using the QR or embedUrl. */
  async create({ amount, reference, redirectUrl, idempotencyKey } = {}) {
    if (!idempotencyKey) throw new Error('idempotencyKey wajib diisi untuk pembuatan QRIS.');
    const res = await this.client.request('POST', '/qris', compact({ amount, reference, redirect_url: redirectUrl }), idempotencyKey, true);
    return new Qris(res.data, res.status_url ?? null);
  }

  async get(id) { return new Qris(await this.client.request('GET', `/qris/${encodeURIComponent(id)}`)); }

  /** Cancel a pending invoice. Idempotent; 409 once paid or expired. */
  async cancel(id) { return new Qris(await this.client.request('DELETE', `/qris/${encodeURIComponent(id)}`)); }

  waitUntilReady(qrisOrId, options) { return waitUntilReady(qrisOrId, (id) => this.get(id), options); }
}

/** Bank virtual account, e-wallet, and retail invoices: `ssp.payments.create / get / cancel / waitUntilReady`. */
export class PaymentsResource {
  constructor(client) { this.client = client; }

  /** `method` is a code from `ssp.methods.all()`, or "all" to let the customer choose at checkout. */
  async create({ amount, method, reference, redirectUrl, idempotencyKey } = {}) {
    if (!idempotencyKey) throw new Error('idempotencyKey wajib diisi untuk pembuatan pembayaran.');
    const res = await this.client.request('POST', '/payments', compact({ amount, method, reference, redirect_url: redirectUrl }), idempotencyKey, true);
    return new Payment(res.data, res.status_url ?? null);
  }

  async get(id) { return new Payment(await this.client.request('GET', `/payments/${encodeURIComponent(id)}`)); }

  /** Cancel a pending invoice. Idempotent; 409 once paid or expired. */
  async cancel(id) { return new Payment(await this.client.request('DELETE', `/payments/${encodeURIComponent(id)}`)); }

  waitUntilReady(paymentOrId, options) { return waitUntilReady(paymentOrId, (id) => this.get(id), options); }
}

/** Methods enabled for your store: `ssp.methods.all / find`. */
export class PaymentMethodsResource {
  constructor(client) { this.client = client; }

  async all(group) {
    const methods = (await this.client.request('GET', '/payment-methods')).map((row) => new PaymentMethod(row));
    return group ? methods.filter((m) => m.group === group) : methods;
  }

  async find(code) {
    return (await this.all()).find((m) => m.code.toLowerCase() === String(code).toLowerCase()) ?? null;
  }
}

/**
 * Verify a Solosmartpay webhook and return the parsed event.
 * Pass the RAW request body (e.g. express.raw({ type: 'application/json' })).
 * Deliveries are at-least-once: deduplicate on `event.id`.
 */
export function verifyWebhook(rawBody, signature, timestamp, secret, toleranceSeconds = 300) {
  if (!secret) throw new SolosmartpayError('Webhook secret kosong.');
  const body = Buffer.isBuffer(rawBody) ? rawBody.toString('utf8') : String(rawBody);
  const ts = Number(timestamp);
  if (!/^\d+$/.test(String(timestamp)) || Math.abs(Date.now() / 1000 - ts) > toleranceSeconds) {
    throw new SolosmartpayError('Timestamp webhook tidak valid atau kedaluwarsa (kemungkinan replay).');
  }
  const expected = Buffer.from('sha256=' + createHmac('sha256', secret).update(`${timestamp}.${body}`).digest('hex'));
  const given = Buffer.from(String(signature || ''));
  if (expected.length !== given.length || !timingSafeEqual(expected, given)) {
    throw new SolosmartpayError('Signature webhook tidak valid.');
  }
  const event = JSON.parse(body);
  if (!event || !event.id || !event.type) throw new SolosmartpayError('Body webhook tidak valid.');
  return event;
}

/** Same checks as verifyWebhook(), returned as a WebhookEvent. */
export function parseWebhook(rawBody, signature, timestamp, secret, toleranceSeconds = 300) {
  return new WebhookEvent(verifyWebhook(rawBody, signature, timestamp, secret, toleranceSeconds));
}

export default Solosmartpay;
