import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createHmac } from 'node:crypto';
import { Solosmartpay, SolosmartpayError, Invoice, Qris, Payment, PaymentMethod, WebhookEvent, parseWebhook } from '../index.js';

const sandboxSsp = new Solosmartpay({ token: 'ssp_test_abc', baseUrl: 'https://pay.example.com/v1/', maxRetries: 0 });

const ssp = new Solosmartpay({ token: 'ssp_test', baseUrl: 'https://pay.example.com/v1/', maxRetries: 0 });
const invoice = (over = {}) => ({
  id: 'abc-123', reference: 'ORDER-1', status: 'pending', provisioning_status: 'queued', channel: 'qris', method: 'qris',
  amount: 50000, unique_code: 127, total_amount: 50127, fee: 350, net_amount: 49650,
  expires_at: '2026-09-27T15:15:00+07:00', paid_at: null, created_at: '2026-09-27T15:00:00+07:00',
  checkout_url: null, embed_url: null, basic_url: null, qris_string: null, qris_image: null, payment_url: null, ...over,
});
const ready = { provisioning_status: 'ready', embed_url: 'https://pay.example.com/pay/abc-123/embed', checkout_url: 'https://pay.example.com/pay/abc-123', qris_image: 'data:image/png;base64,AA' };

function mockFetch(queue) {
  const calls = [];
  globalThis.fetch = async (url, init) => {
    calls.push({ url, method: init.method, headers: init.headers, body: init.body });
    const [status, json] = queue.shift() ?? [500, { message: 'queue empty' }];
    return new Response(JSON.stringify(json), { status });
  };
  return calls;
}

test('qris.create returns a Qris with status_url, camelCase fields and dates', async () => {
  const calls = mockFetch([[202, { data: invoice(), status_url: 'https://pay.example.com/v1/qris/abc-123' }]]);
  const qris = await ssp.qris.create({ amount: 50000, reference: 'ORDER-1', idempotencyKey: 'order-1-create' });
  assert.ok(qris instanceof Qris && qris instanceof Invoice);
  assert.equal(qris.statusUrl, 'https://pay.example.com/v1/qris/abc-123');
  assert.equal(qris.totalAmount, 50127);
  assert.equal(qris.uniqueCode, 127);
  assert.ok(qris.expiresAt instanceof Date && qris.paidAt === null);
  assert.equal(calls[0].url, 'https://pay.example.com/v1/qris');
  assert.equal(calls[0].headers['Idempotency-Key'], 'order-1-create');
  assert.deepEqual(JSON.parse(calls[0].body), { amount: 50000, reference: 'ORDER-1' });
  assert.equal(qris.isReady(), false);
  assert.equal(JSON.stringify(qris), JSON.stringify(invoice()));
});

test('create requires an idempotency key', async () => {
  await assert.rejects(ssp.qris.create({ amount: 5000 }), /idempotencyKey/);
  await assert.rejects(ssp.payments.create({ amount: 5000, method: 'BR' }), /idempotencyKey/);
});

test('waitUntilReady polls until the checkout is available', async () => {
  const calls = mockFetch([[202, { data: invoice(), status_url: 'u' }], [200, { data: invoice() }], [200, { data: invoice(ready) }]]);
  const qris = await ssp.qris.create({ amount: 50000, idempotencyKey: 'order-1-create' });
  const done = await ssp.qris.waitUntilReady(qris, { intervalMs: 1 });
  assert.equal(done.isReady(), true);
  assert.equal(done.embedUrl, 'https://pay.example.com/pay/abc-123/embed');
  assert.equal(done.qrisImage, 'data:image/png;base64,AA');
  assert.equal(calls.length, 3);
});

test('waitUntilReady rejects on failed provisioning and on timeout', async () => {
  mockFetch([[200, { data: invoice({ provisioning_status: 'failed', error_code: 'provider_down' }) }]]);
  await assert.rejects(ssp.qris.waitUntilReady('abc-123', { intervalMs: 1 }), /provider_down/);
  mockFetch(Array.from({ length: 5 }, () => [200, { data: invoice() }]));
  await assert.rejects(ssp.qris.waitUntilReady('abc-123', { timeoutMs: 0, intervalMs: 1 }), (e) => e instanceof SolosmartpayError && /belum siap/.test(e.message));
});

test('cancel uses DELETE; API errors carry status and message', async () => {
  const calls = mockFetch([[200, { data: invoice({ status: 'cancelled' }) }], [409, { message: 'Tagihan sudah dibayar' }]]);
  assert.equal((await ssp.qris.cancel('abc-123')).isCancelled(), true);
  assert.equal(calls[0].method, 'DELETE');
  await assert.rejects(ssp.payments.cancel('abc-123'), (e) => e.status === 409 && e.message === 'Tagihan sudah dibayar');
});

test('payments.create returns a Payment; methods list/find/accepts', async () => {
  const calls = mockFetch([
    [202, { data: invoice({ channel: 'bank_transfer', method: 'BR', ...ready, payment_url: 'https://gw.example/pay/1' }), status_url: 'u' }],
    [200, { data: [
      { code: 'BR', name: 'BRI VA', group: 'va', fee_type: 'flat', fee: 4000, min_amount: 10000, max_amount: 5000000 },
      { code: 'OV', name: 'OVO', group: 'ewallet', fee_type: 'percent', fee: 1.5, min_amount: 1000, max_amount: null },
    ] }],
    [200, { data: [{ code: 'BR', name: 'BRI VA', group: 'va', fee_type: 'flat', fee: 4000, min_amount: 10000, max_amount: 5000000 }] }],
  ]);
  const payment = await ssp.payments.create({ amount: 150000, method: 'BR', idempotencyKey: 'order-2-create' });
  assert.ok(payment instanceof Payment && payment.paymentUrl === 'https://gw.example/pay/1' && payment.isReady());
  assert.deepEqual(JSON.parse(calls[0].body), { amount: 150000, method: 'BR' });
  const all = await ssp.methods.all();
  assert.ok(all[0] instanceof PaymentMethod && all[0].isVirtualAccount() && all[1].isEwallet());
  assert.ok(all[0].accepts(10000) && !all[0].accepts(9999) && !all[0].accepts(5000001) && all[1].accepts(99999999));
  assert.equal((await ssp.methods.find('br')).name, 'BRI VA');
  assert.equal(await (async () => { mockFetch([[200, { data: [] }]]); return ssp.methods.find('zz'); })(), null);
});

test('parseWebhook returns a WebhookEvent and rejects tampering', () => {
  const secret = 'whsec_test';
  const body = JSON.stringify({ id: 'evt_1', type: 'payment.paid', created_at: '2026-09-27T15:03:12+07:00', data: invoice({ status: 'paid', paid_at: '2026-09-27T15:03:10+07:00' }) });
  const ts = String(Math.floor(Date.now() / 1000));
  const sig = 'sha256=' + createHmac('sha256', secret).update(`${ts}.${body}`).digest('hex');
  const event = parseWebhook(body, sig, ts, secret);
  assert.ok(event instanceof WebhookEvent && event.isPaid() && event.data.isPaid() && event.data.reference === 'ORDER-1');
  assert.throws(() => parseWebhook(body + ' ', sig, ts, secret), /Signature/);
});

test('legacy flat helpers still return raw API data', async () => {
  mockFetch([[202, { data: invoice(), status_url: 'u' }], [200, { data: invoice({ status: 'cancelled' }) }]]);
  assert.equal((await ssp.createQris({ amount: 50000, idempotencyKey: 'order-3-create' })).status_url, 'u');
  assert.equal((await ssp.cancelQris('abc-123')).status, 'cancelled');
});

test('ssp_test_ tokens are sandbox tokens and invoices carry the flag', async () => {
  assert.equal(sandboxSsp.isSandbox, true);
  assert.equal(ssp.isSandbox, false);
  mockFetch([[202, { data: invoice({ sandbox: true, va_number: '880812345678901' }), status_url: 'u' }]]);
  const created = await sandboxSsp.qris.create({ amount: 50000, idempotencyKey: 'sbx-create-1' });
  assert.equal(created.isSandbox(), true);
  assert.equal(created.vaNumber, '880812345678901');
  assert.equal(new Invoice(invoice()).isSandbox(), false);
});

test('sandbox.pay posts the result to the simulate endpoint', async () => {
  const calls = mockFetch([[200, { data: invoice({ sandbox: true, status: 'paid', paid_at: '2026-09-27T15:03:10+07:00' }) }]]);
  const paid = await sandboxSsp.sandbox.pay('abc-123');
  assert.equal(calls[0].method, 'POST');
  assert.equal(calls[0].url, 'https://pay.example.com/v1/sandbox/payments/abc-123/simulate');
  assert.deepEqual(JSON.parse(calls[0].body), { result: 'paid' });
  assert.ok(paid instanceof Invoice && paid.isPaid() && paid.isSandbox());
});

test('sandbox.simulate refuses live tokens and unknown results', async () => {
  await assert.rejects(ssp.sandbox.pay('abc-123'), (e) => e instanceof SolosmartpayError && /token sandbox/.test(e.message));
  await assert.rejects(sandboxSsp.sandbox.simulate('abc-123', 'maybe'), /paid, failed, expired/);
});

test('webhook events know when they come from the sandbox', () => {
  const secret = 'whsec_x';
  const make = (extra) => {
    const body = JSON.stringify({ id: 'evt_1', type: 'payment.paid', created_at: '2026-09-27T15:03:12+07:00', ...extra, data: invoice({ status: 'paid', ...(extra.sandbox ? { sandbox: true } : {}) }) });
    const ts = String(Math.floor(Date.now() / 1000));
    return parseWebhook(body, 'sha256=' + createHmac('sha256', secret).update(`${ts}.${body}`).digest('hex'), ts, secret);
  };
  assert.equal(make({ sandbox: true }).isSandbox(), true);
  assert.equal(make({}).isSandbox(), false);
});
