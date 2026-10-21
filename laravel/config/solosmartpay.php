<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API token
    |--------------------------------------------------------------------------
    | Create it in the Solosmartpay dashboard under Developer > API Tokens. It starts with "ssp_".
    | Keep it on the server: never expose it to a browser or a mobile app.
    */
    'token' => env('SOLOSMARTPAY_TOKEN'),

    /*
    | API base URL, including /v1. The checkout (and embed.js) is served from the same origin.
    */
    'base_url' => env('SOLOSMARTPAY_BASE_URL', 'https://solosmartpay.com/v1'),

    /*
    | HTTP behaviour. Reads and idempotent writes are retried on network errors, 429 and 5xx.
    */
    'timeout' => (int) env('SOLOSMARTPAY_TIMEOUT', 20),
    'retries' => (int) env('SOLOSMARTPAY_RETRIES', 2),
    'retry_delay_ms' => 250, // doubles after every failed attempt

    /*
    | New invoices are provisioned asynchronously (HTTP 202). When true, createQris()/createPayment()
    | wait until the invoice is ready, up to ready_timeout seconds, so QR and checkout URLs are usable
    | straight away. If it is still not ready you get the invoice back anyway; call refresh() later.
    */
    'wait_until_ready' => (bool) env('SOLOSMARTPAY_WAIT_UNTIL_READY', true),
    'ready_timeout' => (int) env('SOLOSMARTPAY_READY_TIMEOUT', 10),

    /*
    |--------------------------------------------------------------------------
    | Webhook
    |--------------------------------------------------------------------------
    | The package registers POST {path} for you. Put the full URL
    | (https://your-app.test/solosmartpay/webhook) in Developer > API & Webhook and copy the signing
    | secret here. Verified events are dispatched as Laravel events; see the README.
    */
    'webhook' => [
        'enabled' => (bool) env('SOLOSMARTPAY_WEBHOOK_ENABLED', true),
        'secret' => env('SOLOSMARTPAY_WEBHOOK_SECRET'),
        'path' => env('SOLOSMARTPAY_WEBHOOK_PATH', 'solosmartpay/webhook'),
        // Extra middleware for the route (the signature check is always applied). Do not add "web"/CSRF.
        'middleware' => [],
        // Requests whose timestamp is older than this many seconds are rejected (replay protection).
        'tolerance' => 300,
        // Event ids are remembered this long so a redelivery never fires your listeners twice.
        'dedupe_ttl' => 60 * 60 * 24 * 7,
    ],
];
