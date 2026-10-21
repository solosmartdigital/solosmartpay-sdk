@props([
    'invoice',
    'label' => 'Bayar sekarang',
    'lang' => null,
    'onSuccess' => null,
])
@php($ssp = app(\SolosmartDigital\Solosmartpay\Solosmartpay::class))
@once
    <script src="{{ $ssp->origin() }}/embed.js" defer></script>
    <script>
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-solosmartpay-embed]');
            if (!btn) return;
            e.preventDefault();
            // embed.js blocked or not loaded yet: fall back to the full-page checkout.
            if (!window.SoloSmartPay) { window.location.href = btn.getAttribute('data-solosmartpay-checkout'); return; }
            window.SoloSmartPay.open({
                embedUrl: btn.getAttribute('data-solosmartpay-embed'),
                lang: btn.getAttribute('data-solosmartpay-lang') || undefined,
                onSuccess: function (payload) {
                    btn.dispatchEvent(new CustomEvent('solosmartpay:paid', { bubbles: true, detail: payload }));
                    var fn = btn.getAttribute('data-solosmartpay-on-success');
                    if (fn && typeof window[fn] === 'function') window[fn](payload);
                },
                onClose: function (info) {
                    btn.dispatchEvent(new CustomEvent('solosmartpay:closed', { bubbles: true, detail: info }));
                }
            });
        });
    </script>
@endonce
<button type="button"
    {{ $attributes->merge([
        'data-solosmartpay-embed' => $invoice->embedUrl,
        'data-solosmartpay-checkout' => $invoice->checkoutUrl,
        'data-solosmartpay-lang' => $lang,
        'data-solosmartpay-on-success' => $onSuccess,
    ]) }}
    @disabled(! $invoice->embedUrl)>{{ $slot->isEmpty() ? $label : $slot }}</button>
