<?php

namespace Solosmartpay\Models;

use DateTimeImmutable;
use JsonSerializable;

/**
 * An invoice returned by the API. QRIS and bank/e-wallet/retail invoices share these fields.
 * Money values are integer Rupiah. `raw` keeps the untouched API payload.
 */
class Invoice implements JsonSerializable
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    public const PROVISIONING_QUEUED = 'queued';
    public const PROVISIONING_PROCESSING = 'processing';
    public const PROVISIONING_READY = 'ready';
    public const PROVISIONING_FAILED = 'failed';
    public const PROVISIONING_SELECTION_REQUIRED = 'selection_required';

    /** @param array<string, mixed> $raw */
    final public function __construct(
        public readonly string $id,
        public readonly ?string $reference,
        public readonly string $status,
        public readonly ?string $provisioningStatus,
        public readonly ?string $channel,
        public readonly ?string $method,
        public readonly int $amount,
        public readonly ?int $uniqueCode,
        public readonly int $totalAmount,
        public readonly int $fee,
        public readonly int $netAmount,
        public readonly ?DateTimeImmutable $expiresAt,
        public readonly ?DateTimeImmutable $paidAt,
        public readonly ?DateTimeImmutable $createdAt,
        public readonly ?string $checkoutUrl,
        public readonly ?string $embedUrl,
        public readonly ?string $basicUrl,
        public readonly ?string $redirectUrl,
        public readonly ?string $errorCode,
        public readonly ?string $statusUrl,
        public readonly array $raw,
        /** True for a test payment made with a sandbox token (ssp_test_…): no real money moves. */
        public readonly bool $sandbox = false,
        /** Virtual account number (sandbox returns a sample one). */
        public readonly ?string $vaNumber = null,
    ) {
    }

    /** @param array<string, mixed> $data the `data` object of an API response */
    public static function fromArray(array $data, ?string $statusUrl = null): static
    {
        return new static(
            id: (string) ($data['id'] ?? ''),
            reference: self::string($data, 'reference'),
            status: (string) ($data['status'] ?? ''),
            provisioningStatus: self::string($data, 'provisioning_status'),
            channel: self::string($data, 'channel'),
            method: self::string($data, 'method'),
            amount: (int) ($data['amount'] ?? 0),
            uniqueCode: isset($data['unique_code']) ? (int) $data['unique_code'] : null,
            totalAmount: (int) ($data['total_amount'] ?? 0),
            fee: (int) ($data['fee'] ?? 0),
            netAmount: (int) ($data['net_amount'] ?? 0),
            expiresAt: self::date($data, 'expires_at'),
            paidAt: self::date($data, 'paid_at'),
            createdAt: self::date($data, 'created_at'),
            checkoutUrl: self::string($data, 'checkout_url'),
            embedUrl: self::string($data, 'embed_url'),
            basicUrl: self::string($data, 'basic_url'),
            redirectUrl: self::string($data, 'redirect_url'),
            errorCode: self::string($data, 'error_code'),
            statusUrl: $statusUrl,
            raw: $data,
            sandbox: ($data['sandbox'] ?? false) === true,
            vaNumber: self::string($data, 'va_number'),
        );
    }

    public function isSandbox(): bool
    {
        return $this->sandbox;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isExpired(): bool
    {
        return $this->status === self::STATUS_EXPIRED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function provisioningFailed(): bool
    {
        return $this->provisioningStatus === self::PROVISIONING_FAILED;
    }

    /** True once the customer can be sent to checkout (`embedUrl` / `checkoutUrl` are filled). */
    public function isReady(): bool
    {
        return $this->embedUrl !== null || $this->provisioningStatus === self::PROVISIONING_READY;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->raw;
    }

    /** @param array<string, mixed> $data */
    protected static function string(array $data, string $key): ?string
    {
        return isset($data[$key]) && $data[$key] !== '' ? (string) $data[$key] : null;
    }

    /** @param array<string, mixed> $data */
    protected static function date(array $data, string $key): ?DateTimeImmutable
    {
        return isset($data[$key]) && $data[$key] !== '' ? new DateTimeImmutable((string) $data[$key]) : null;
    }
}
