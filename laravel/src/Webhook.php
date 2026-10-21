<?php

namespace SolosmartDigital\Solosmartpay;

use SolosmartDigital\Solosmartpay\Exceptions\InvalidWebhookException;

/**
 * Signature scheme: X-SoloSmartPay-Signature = "sha256=" . HMAC_SHA256(secret, timestamp . "." . rawBody).
 */
final class Webhook
{
    /**
     * @return array<string, mixed> the decoded event: id, type, created_at, data
     *
     * @throws InvalidWebhookException
     */
    public static function verify(string $rawBody, string $signature, string $timestamp, string $secret, int $tolerance = 300): array
    {
        if ($secret === '') {
            throw new InvalidWebhookException('SOLOSMARTPAY_WEBHOOK_SECRET belum diisi.');
        }
        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > $tolerance) {
            throw new InvalidWebhookException('Timestamp webhook tidak valid atau kedaluwarsa (kemungkinan replay).');
        }
        if (! hash_equals(self::sign($rawBody, $secret, $timestamp), $signature)) {
            throw new InvalidWebhookException('Signature webhook tidak valid.');
        }

        $event = json_decode($rawBody, true);
        if (! is_array($event) || ! isset($event['id'], $event['type']) || ! is_array($event['data'] ?? null)) {
            throw new InvalidWebhookException('Body webhook tidak valid.');
        }

        return $event;
    }

    /** The signature header value for a body. Useful for testing your own webhook handling. */
    public static function sign(string $rawBody, string $secret, string|int $timestamp): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
    }
}
