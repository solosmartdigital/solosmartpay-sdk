// Browser helper for the Solosmartpay popup checkout (embed.js).
// Safe to bundle with Vite / webpack / esbuild: no Node APIs, no secrets.
// Create the invoice on YOUR server, send `embed_url` to the page, then call openCheckout(embed_url).

const loading = new Map();

function checkoutOrigin(value) {
  let url;
  try { url = new URL(value); } catch { throw new Error('Solosmartpay: embedUrl/baseUrl harus berupa URL absolut.'); }
  const local = url.hostname === 'localhost' || url.hostname === '127.0.0.1' || url.hostname.endsWith('.test');
  if (url.protocol !== 'https:' && !(local && url.protocol === 'http:')) {
    throw new Error('Solosmartpay: checkout harus dimuat lewat HTTPS.');
  }
  return url.origin;
}

/** Load `${origin}/embed.js` once and resolve with `window.SoloSmartPay`. */
export function loadEmbed(baseUrl) {
  if (typeof window === 'undefined' || typeof document === 'undefined') {
    return Promise.reject(new Error('Solosmartpay: loadEmbed hanya bisa dipakai di browser.'));
  }
  if (window.SoloSmartPay) return Promise.resolve(window.SoloSmartPay);

  const src = `${checkoutOrigin(baseUrl)}/embed.js`;
  if (!loading.has(src)) {
    loading.set(src, new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = src;
      script.async = true;
      script.onload = () => (window.SoloSmartPay ? resolve(window.SoloSmartPay) : reject(new Error('Solosmartpay: embed.js termuat tapi SoloSmartPay tidak tersedia.')));
      script.onerror = () => {
        loading.delete(src);
        script.remove();
        reject(new Error(`Solosmartpay: gagal memuat ${src}`));
      };
      document.head.appendChild(script);
    }));
  }
  return loading.get(src);
}

/**
 * Open the payment modal.
 *   openCheckout(invoice.embed_url, { onSuccess, onClose })
 *   openCheckout({ publicId, baseUrl: 'https://pay.example.com', onSuccess })
 * `onSuccess` is a UX hint only; confirm payment from the webhook on your server.
 */
export async function openCheckout(input, extra = {}) {
  const { baseUrl, ...opts } = typeof input === 'string' ? { embedUrl: input, ...extra } : { ...input, ...extra };
  const origin = checkoutOrigin(opts.embedUrl || baseUrl || '');
  if (!opts.embedUrl && !opts.publicId) throw new Error('Solosmartpay: embedUrl atau publicId wajib diisi.');

  const embed = await loadEmbed(origin);
  embed.open(opts);
  return { close: () => embed.close() };
}

/** Close the modal programmatically (no-op when nothing is open). */
export function closeCheckout() {
  if (typeof window !== 'undefined' && window.SoloSmartPay) window.SoloSmartPay.close();
}
