<?php

use Illuminate\Support\Facades\Route;
use SolosmartDigital\Solosmartpay\Http\Controllers\WebhookController;
use SolosmartDigital\Solosmartpay\Http\Middleware\VerifyWebhookSignature;

// No "web" group on purpose: a server-to-server POST has no session or CSRF token.
Route::post(config('solosmartpay.webhook.path', 'solosmartpay/webhook'), WebhookController::class)
    ->middleware([...(array) config('solosmartpay.webhook.middleware', []), VerifyWebhookSignature::class])
    ->name('solosmartpay.webhook');
