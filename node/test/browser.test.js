import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';

function installDom({ failLoad = false } = {}) {
  const scripts = [];
  globalThis.window = globalThis;
  delete globalThis.SoloSmartPay;
  globalThis.document = {
    head: {
      appendChild(script) {
        scripts.push(script);
        script.remove = () => {};
        queueMicrotask(() => {
          if (failLoad) return script.onerror();
          globalThis.SoloSmartPay = { open(o) { globalThis.__opened = o; }, close() { globalThis.__closed = true; } };
          script.onload();
        });
      },
    },
    createElement: () => ({}),
  };
  return scripts;
}

beforeEach(() => { delete globalThis.__opened; delete globalThis.__closed; });

test('openCheckout loads embed.js from the embed_url origin and opens the modal', async () => {
  const scripts = installDom();
  const { openCheckout } = await import('../browser.js?a');
  const onSuccess = () => {};
  const handle = await openCheckout('https://pay.example.com/pay/abc/embed', { onSuccess, lang: 'id' });
  assert.equal(scripts.length, 1);
  assert.equal(scripts[0].src, 'https://pay.example.com/embed.js');
  assert.equal(globalThis.__opened.embedUrl, 'https://pay.example.com/pay/abc/embed');
  assert.equal(globalThis.__opened.onSuccess, onSuccess);
  assert.equal(globalThis.__opened.baseUrl, undefined);
  handle.close();
  assert.equal(globalThis.__closed, true);
});

test('loads embed.js only once and supports publicId + baseUrl', async () => {
  const scripts = installDom();
  const { openCheckout } = await import('../browser.js?b');
  await openCheckout({ publicId: 'abc', baseUrl: 'https://pay.example.com' });
  await openCheckout({ publicId: 'def', baseUrl: 'https://pay.example.com' });
  assert.equal(scripts.length, 1);
  assert.equal(globalThis.__opened.publicId, 'def');
});

test('rejects insecure or missing URLs', async () => {
  installDom();
  const { openCheckout } = await import('../browser.js?c');
  await assert.rejects(openCheckout('http://pay.example.com/pay/abc/embed'), /HTTPS/);
  await assert.rejects(openCheckout({ publicId: 'abc' }), /absolut/);
  await assert.rejects(openCheckout({ baseUrl: 'https://pay.example.com' }), /wajib/);
  await openCheckout('http://localhost:8000/pay/abc/embed'); // local dev is allowed
});

test('a failed script load can be retried', async () => {
  installDom({ failLoad: true });
  const { loadEmbed } = await import('../browser.js?d');
  await assert.rejects(loadEmbed('https://pay.example.com'), /gagal memuat/);
  installDom();
  await loadEmbed('https://pay.example.com');
});
