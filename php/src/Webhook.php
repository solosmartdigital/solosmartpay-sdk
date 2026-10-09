<?php

namespace Solosmartpay;

/**
 * Verifies a webhook from Solosmartpay and returns the decoded event.
 *
 *   $event = Solosmartpay\Webhook::verify(
 *       file_get_contents('php://input'),
 *       $_SERVER['HTTP_X_SOLOSMARTPAY_SIGNATURE'] ?? '',
 *       $_SERVER['HTTP_X_SOLOSMARTPAY_TIMESTAMP'] ?? '',
 *       getenv('SOLOSMARTPAY_WEBHOOK_SECRET'),
 *   );
 *   if ($event['type'] === 'payment.paid') { ... mark order $event['data']['reference'] paid ... }
 *
 * Deduplicate on $event['id'] — deliveries are at-least-once.
 */
final class Webhook
{
    public static function verify(string $rawBody, string $signature, string $timestamp, string $secret, int $toleranceSeconds = 300): array
    {
        if ($secret === '') {
            throw new SolosmartpayException('Webhook secret kosong.');
        }
        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > $toleranceSeconds) {
            throw new SolosmartpayException('Timestamp webhook tidak valid atau kedaluwarsa (kemungkinan replay).');
        }

        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
        if (! hash_equals($expected, $signature)) {
            throw new SolosmartpayException('Signature webhook tidak valid.');
        }

        $event = json_decode($rawBody, true);
        if (! is_array($event) || ! isset($event['id'], $event['type'])) {
            throw new SolosmartpayException('Body webhook tidak valid.');
        }

        return $event;
    }
}
