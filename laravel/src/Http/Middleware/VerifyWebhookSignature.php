<?php

namespace SolosmartDigital\Solosmartpay\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use SolosmartDigital\Solosmartpay\Exceptions\InvalidWebhookException;
use SolosmartDigital\Solosmartpay\Webhook;
use Symfony\Component\HttpFoundation\Response;

class VerifyWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $event = Webhook::verify(
                $request->getContent(),
                (string) $request->header('X-SoloSmartPay-Signature', ''),
                (string) $request->header('X-SoloSmartPay-Timestamp', ''),
                (string) config('solosmartpay.webhook.secret', ''),
                (int) config('solosmartpay.webhook.tolerance', 300),
            );
        } catch (InvalidWebhookException $e) {
            // 400, not 401: nothing to authenticate against, and it must not be retried as if it were ours.
            return response()->json(['message' => $e->getMessage()], 400);
        }

        $request->attributes->set('solosmartpay.event', $event);

        return $next($request);
    }
}
