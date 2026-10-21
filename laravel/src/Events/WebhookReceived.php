<?php

namespace SolosmartDigital\Solosmartpay\Events;

/** Fired for every verified, non-duplicate webhook, including event types this package has no class for. */
final class WebhookReceived
{
    /** @param array<string, mixed> $event id, type, created_at, data */
    public function __construct(public readonly array $event)
    {
    }

    /** True when the delivery was caused by a sandbox payment (a token starting with ssp_test_). */
    public function isSandbox(): bool
    {
        return ($this->event['sandbox'] ?? false) === true || ($this->event['data']['sandbox'] ?? false) === true;
    }

    public function type(): string
    {
        return (string) $this->event['type'];
    }
}
