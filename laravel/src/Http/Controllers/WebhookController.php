<?php

namespace SolosmartDigital\Solosmartpay\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use SolosmartDigital\Solosmartpay\Data\Invoice;
use SolosmartDigital\Solosmartpay\Events\InvoiceCancelled;
use SolosmartDigital\Solosmartpay\Events\InvoiceExpired;
use SolosmartDigital\Solosmartpay\Events\InvoiceFailed;
use SolosmartDigital\Solosmartpay\Events\PaymentPaid;
use SolosmartDigital\Solosmartpay\Events\WebhookReceived;
use Throwable;

class WebhookController
{
    /** How long an in-flight event is claimed before a retry may take over. */
    private const CLAIM_SECONDS = 120;

    private const EVENTS = [
        PaymentPaid::TYPE => PaymentPaid::class,
        InvoiceExpired::TYPE => InvoiceExpired::class,
        InvoiceFailed::TYPE => InvoiceFailed::class,
        InvoiceCancelled::TYPE => InvoiceCancelled::class,
    ];

    public function __invoke(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $event set by VerifyWebhookSignature */
        $event = $request->attributes->get('solosmartpay.event');

        // Deliveries are at-least-once. Claim the id first, but only briefly: if the process dies mid-way (timeout,
        // out of memory) the claim expires and the retry is processed instead of being dropped as a "duplicate".
        // It is promoted to the long dedupe window only after every listener has finished.
        $key = 'solosmartpay:webhook:'.$event['id'];
        if (! Cache::add($key, 1, self::CLAIM_SECONDS)) {
            return response()->json(['message' => 'duplicate']);
        }

        try {
            Event::dispatch(new WebhookReceived($event));

            if (isset(self::EVENTS[$event['type']])) {
                $class = self::EVENTS[$event['type']];
                Event::dispatch(new $class(Invoice::fromArray($event['data']), $event));
            }
        } catch (Throwable $e) {
            Cache::forget($key);

            throw $e;
        }

        Cache::put($key, 1, (int) config('solosmartpay.webhook.dedupe_ttl', 604800));

        return response()->json(['message' => 'ok']);
    }
}
