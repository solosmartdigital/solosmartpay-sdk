// resources/js/checkout.js — npm install @solosmartpay/node, then add this file to vite.config.js `input`.
import { openCheckout } from '@solosmartpay/node/browser';

const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function waitForEmbedUrl(statusUrl) {
  for (let i = 0; i < 30; i++) {
    const res = await fetch(statusUrl, { headers: { Accept: 'application/json' } });
    const invoice = await res.json();
    if (invoice.embed_url) return invoice.embed_url;
    if (invoice.provisioning_status === 'failed') break;
    await sleep(1000);
  }
  throw new Error('Pembayaran belum siap, coba lagi.');
}

document.querySelectorAll('[data-pay-order]').forEach((button) => {
  button.addEventListener('click', async () => {
    const base = `/orders/${button.dataset.payOrder}/pay`;
    button.disabled = true;
    try {
      await fetch(base, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' } });
      const embedUrl = await waitForEmbedUrl(`${base}/status`);
      await openCheckout(embedUrl, {
        lang: 'id',
        onSuccess: () => { window.location.href = `/orders/${button.dataset.payOrder}/thanks`; }, // UX only; the webhook marks it paid
        onClose: () => { button.disabled = false; },
      });
    } catch (error) {
      alert(error.message);
      button.disabled = false;
    }
  });
});
