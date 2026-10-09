import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createHmac } from 'node:crypto';
import { Solosmartpay, verifyWebhook, SolosmartpayError } from '../index.js';

const ssp = new Solosmartpay({ token: 'ssp_test_token', baseUrl: 'https://pay.example.com/v1/', maxRetries: 0 });

test('cancelQris / cancelPayment send DELETE and unwrap data', async () => {
  const calls = [];
  globalThis.fetch = async (url, init) => { calls.push([url, init.method]); return new Response(JSON.stringify({ data: { id: 'x', status: 'expired' } }), { status: 200 }); };
  assert.equal((await ssp.cancelQris('x')).status, 'expired');
  await ssp.cancelPayment('y');
  assert.deepEqual(calls, [['https://pay.example.com/v1/qris/x', 'DELETE'], ['https://pay.example.com/v1/payments/y', 'DELETE']]);
});

test('createQris requires an idempotency key and surfaces API errors', async () => {
  assert.throws(() => ssp.createQris({ amount: 5000 }), /idempotencyKey/);
  globalThis.fetch = async () => new Response(JSON.stringify({ message: 'Referensi sudah dipakai' }), { status: 409 });
  await assert.rejects(ssp.createQris({ amount: 5000, idempotencyKey: 'order-0001' }), (e) => e instanceof SolosmartpayError && e.status === 409);
});

test('verifyWebhook accepts a valid signature and rejects tampering and replays', () => {
  const secret = 'whsec_test';
  const body = JSON.stringify({ id: 'evt_1', type: 'payment.paid', data: { reference: 'ORDER-1' } });
  const ts = String(Math.floor(Date.now() / 1000));
  const sig = 'sha256=' + createHmac('sha256', secret).update(`${ts}.${body}`).digest('hex');
  assert.equal(verifyWebhook(body, sig, ts, secret).id, 'evt_1');
  assert.throws(() => verifyWebhook(body + ' ', sig, ts, secret), /Signature/);
  const old = String(Math.floor(Date.now() / 1000) - 3600);
  const oldSig = 'sha256=' + createHmac('sha256', secret).update(`${old}.${body}`).digest('hex');
  assert.throws(() => verifyWebhook(body, oldSig, old, secret), /kedaluwarsa/);
});
