<?php

namespace Solosmartpay\Models;

use DateTimeImmutable;

/** A verified webhook delivery. Deliveries are at-least-once: deduplicate on `id`. */
final class WebhookEvent
{
    public const PAYMENT_PAID = 'payment.paid';
    public const INVOICE_EXPIRED = 'invoice.expired';
    public const INVOICE_FAILED = 'invoice.failed';
    public const INVOICE_CANCELLED = 'invoice.cancelled';
    public const TEST = 'webhook.test';

    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly ?DateTimeImmutable $createdAt,
        public readonly Invoice $data,
        public readonly array $raw,
        /** True when the delivery comes from a sandbox payment: never mark a real order paid from it. */
        public readonly bool $sandbox = false,
    ) {
    }

    /** @param array<string, mixed> $event decoded webhook body */
    public static function fromArray(array $event): self
    {
        return new self(
            id: (string) $event['id'],
            type: (string) $event['type'],
            createdAt: isset($event['created_at']) ? new DateTimeImmutable((string) $event['created_at']) : null,
            data: Invoice::fromArray(is_array($event['data'] ?? null) ? $event['data'] : []),
            raw: $event,
            sandbox: ($event['sandbox'] ?? ($event['data']['sandbox'] ?? false)) === true,
        );
    }

    public function isPaid(): bool
    {
        return $this->type === self::PAYMENT_PAID;
    }

    public function isSandbox(): bool
    {
        return $this->sandbox;
    }

    public function isTest(): bool
    {
        return $this->type === self::TEST;
    }
}
