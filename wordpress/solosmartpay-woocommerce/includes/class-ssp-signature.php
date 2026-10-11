<?php
/**
 * Webhook signature check. Plain PHP on purpose (no WordPress calls) so it is
 * unit-testable on its own.
 *
 *   X-SoloSmartPay-Signature = "sha256=" + HMAC_SHA256(secret, timestamp + "." + raw_body)
 */

if (! defined('ABSPATH') && ! defined('SSP_WC_TESTING')) {
    exit;
}

final class SSP_WC_Signature
{
    /**
     * @return string|null null when the request is authentic, otherwise a short reason.
     */
    public static function error($raw_body, $signature, $timestamp, $secret, $tolerance = 300, $now = null)
    {
        if ($secret === '' || $secret === null) {
            return 'webhook secret is empty';
        }

        $timestamp = (string) $timestamp;
        if ($timestamp === '' || ! ctype_digit($timestamp)) {
            return 'invalid timestamp';
        }

        $now = $now === null ? time() : (int) $now;
        if (abs($now - (int) $timestamp) > $tolerance) {
            return 'timestamp outside tolerance (possible replay)';
        }

        $expected = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $raw_body, $secret);
        if (! hash_equals($expected, (string) $signature)) {
            return 'signature mismatch';
        }

        return null;
    }
}
