<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Solosmartpay\Client;
use Solosmartpay\SolosmartpayException;
use Solosmartpay\Webhook;

// composer require solosmartpay/solosmartpay-php
// .env: SOLOSMARTPAY_TOKEN=ssp_...  SOLOSMARTPAY_BASE_URL=https://YOUR-DOMAIN/v1  SOLOSMARTPAY_WEBHOOK_SECRET=...
// config/services.php: 'solosmartpay' => ['token' => env('SOLOSMARTPAY_TOKEN'), 'base_url' => env('SOLOSMARTPAY_BASE_URL'), 'webhook_secret' => env('SOLOSMARTPAY_WEBHOOK_SECRET')]
class SolosmartpayController extends Controller
{
    private function client(): Client
    {
        return new Client(config('services.solosmartpay.token'), config('services.solosmartpay.base_url'));
    }

    /** POST /orders/{order}/pay: create the invoice. The token never leaves the server. */
    public function create(Order $order): JsonResponse
    {
        try {
            // Same Idempotency-Key + same body = same invoice, so a double click is safe.
            // For virtual account / e-wallet use: $this->client()->payments->create($order->total, 'BR', ...) or method 'all'.
            $qris = $this->client()->qris->create($order->total, "order-{$order->id}-create", "ORDER-{$order->id}");
        } catch (SolosmartpayException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status ?: 502);
        }

        $order->update(['solosmartpay_id' => $qris->id]);

        return response()->json(['id' => $qris->id], 202);
    }

    /** GET /orders/{order}/pay/status: the browser polls this until embed_url is ready. */
    public function status(Order $order): JsonResponse
    {
        $qris = $this->client()->qris->get($order->solosmartpay_id);

        return response()->json([
            'status' => $qris->status,
            'provisioning_status' => $qris->provisioningStatus,
            'embed_url' => $qris->embedUrl,
        ]);
    }

    /** POST /webhooks/solosmartpay: the only trustworthy proof of payment. Exclude it from CSRF. */
    public function webhook(Request $request)
    {
        try {
            $event = Webhook::parse(
                $request->getContent(),
                (string) $request->header('X-SoloSmartPay-Signature'),
                (string) $request->header('X-SoloSmartPay-Timestamp'),
                config('services.solosmartpay.webhook_secret'),
            );
        } catch (SolosmartpayException) {
            return response('invalid signature', 401);
        }

        // Deliveries are at-least-once: handle each event id once.
        if ($event->isPaid() && Cache::add("ssp:event:{$event->id}", 1, now()->addDay())) {
            $order = Order::where('solosmartpay_id', $event->data->id)->first();

            if ($order && $order->total === $event->data->amount) {
                $order->markPaid();
            }
        }

        return response()->noContent();
    }
}
