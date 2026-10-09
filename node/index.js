// Solosmartpay API client for Node.js 18+ (also Bun / Deno / server runtimes with fetch + node:crypto).
// Server-side only: never expose your ssp_ token in browser or mobile code.
import { createHmac, timingSafeEqual } from 'node:crypto';

export const VERSION = '1.1.0';

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
  }

  /** Create and queue a QRIS invoice for provisioning. */
  createQris({ amount, reference, redirectUrl, idempotencyKey } = {}) {
    if (!idempotencyKey) throw new Error('idempotencyKey wajib diisi untuk pembuatan QRIS.');
    return this.#request('POST', '/qris', { amount, reference, redirect_url: redirectUrl }, idempotencyKey, true);
  }

  getQris(id) {
    return this.#request('GET', `/qris/${encodeURIComponent(id)}`);
  }

  /** Bank VA / e-wallet methods enabled for your store, with their fees. */
  paymentMethods() {
    return this.#request('GET', '/payment-methods');
  }

  /** Queue a bank VA / e-wallet bill for provisioning. */
  createPayment({ amount, method, reference, redirectUrl, idempotencyKey } = {}) {
    if (!idempotencyKey) throw new Error('idempotencyKey wajib diisi untuk pembuatan pembayaran.');
    return this.#request('POST', '/payments', { amount, method, reference, redirect_url: redirectUrl }, idempotencyKey, true);
  }

  getPayment(id) {
    return this.#request('GET', `/payments/${encodeURIComponent(id)}`);
  }

  /** Cancel a pending QRIS invoice (idempotent; 409 once paid or expired). */
  cancelQris(id) {
    return this.#request('DELETE', `/qris/${encodeURIComponent(id)}`);
  }

  /** Cancel a pending bank VA / e-wallet bill (idempotent; 409 once paid or expired). */
  cancelPayment(id) {
    return this.#request('DELETE', `/payments/${encodeURIComponent(id)}`);
  }

  // Retries network errors, 429 and 5xx only when the request is safe to repeat.
  async #request(method, path, body, idempotencyKey, preserveEnvelope = false) {
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

export default Solosmartpay;
