{{-- resources/views/orders/show.blade.php --}}
<head>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @vite('resources/js/checkout.js')
</head>

<button type="button" data-pay-order="{{ $order->id }}">Bayar Rp{{ number_format($order->total, 0, ',', '.') }}</button>

{{-- routes/web.php
Route::post('/orders/{order}/pay', [SolosmartpayController::class, 'create']);
Route::get('/orders/{order}/pay/status', [SolosmartpayController::class, 'status']);
Route::post('/webhooks/solosmartpay', [SolosmartpayController::class, 'webhook']);

bootstrap/app.php (Laravel 11+):
->withMiddleware(fn ($middleware) => $middleware->validateCsrfTokens(except: ['webhooks/solosmartpay']))
--}}
