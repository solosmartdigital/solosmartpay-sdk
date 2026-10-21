<?php

namespace SolosmartDigital\Solosmartpay\Data;

use Illuminate\Support\Carbon;

/** A QRIS invoice or a bank VA / e-wallet bill, as returned by the API and carried by webhooks. */
final class Invoice
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_FAILED = 'failed';

    public function __construct(
        public readonly string $id,
        public readonly ?string $reference,
        public readonly string $status,
        public readonly string $channel,
        public readonly ?string $method,
        public readonly int $amount,
        public readonly ?int $uniqueCode,
        public readonly int $totalAmount,
        public readonly int $fee,
        public readonly int $netAmount,
        public readonly ?string $provisioningStatus,
        public readonly ?string $errorCode,
        public readonly ?Carbon $expiresAt,
        public readonly ?Carbon $paidAt,
        public readonly ?Carbon $createdAt,
        public readonly ?string $checkoutUrl,
        public readonly ?string $basicUrl,
        public readonly ?string $embedUrl,
        public readonly ?string $paymentUrl,
        public readonly ?string $qrisString,
        public readonly ?string $qrisImage,
        public readonly ?string $redirectUrl,
        public readonly ?string $statusUrl,
        /** @var array<string, mixed> the unmodified `data` object from the API */
        public readonly array $raw,
        /** True for a test payment made with a sandbox token (ssp_test_…): no real money moves. */
        public readonly bool $sandbox = false,
        /** Virtual account number (sandbox returns a sample one). */
        public readonly ?string $vaNumber = null,
    ) {
    }

    /** @param array<string, mixed> $data the API's `data` object */
    public static function fromArray(array $data, ?string $statusUrl = null): self
    {
        $date = static fn ($v) => filled($v) ? Carbon::parse($v) : null;
        $int = static fn ($v) => $v === null ? null : (int) $v;

        return new self(
            id: (string) ($data['id'] ?? ''),
            reference: $data['reference'] ?? null,
            status: (string) ($data['status'] ?? self::STATUS_PENDING),
            channel: (string) ($data['channel'] ?? ''),
            method: $data['method'] ?? null,
            amount: (int) ($data['amount'] ?? 0),
            uniqueCode: $int($data['unique_code'] ?? null),
            totalAmount: (int) ($data['total_amount'] ?? 0),
            fee: (int) ($data['fee'] ?? 0),
            netAmount: (int) ($data['net_amount'] ?? 0),
            provisioningStatus: $data['provisioning_status'] ?? null,
            errorCode: $data['error_code'] ?? null,
            expiresAt: $date($data['expires_at'] ?? null),
            paidAt: $date($data['paid_at'] ?? null),
            createdAt: $date($data['created_at'] ?? null),
            checkoutUrl: $data['checkout_url'] ?? null,
            basicUrl: $data['basic_url'] ?? null,
            embedUrl: $data['embed_url'] ?? null,
            paymentUrl: $data['payment_url'] ?? null,
            qrisString: $data['qris_string'] ?? null,
            qrisImage: $data['qris_image'] ?? null,
            redirectUrl: $data['redirect_url'] ?? null,
            statusUrl: $statusUrl,
            raw: $data,
            sandbox: ($data['sandbox'] ?? false) === true,
            vaNumber: $data['va_number'] ?? null,
        );
    }

    public function isSandbox(): bool
    {
        return $this->sandbox;
    }

    public function isQris(): bool
    {
        return $this->channel === 'qris';
    }

    /** Payment details (QR, VA number, checkout URL) are only usable once provisioning has finished. */
    public function isReady(): bool
    {
        return $this->provisioningStatus === 'ready';
    }

    public function isProvisioning(): bool
    {
        return in_array($this->provisioningStatus, ['queued', 'processing'], true);
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

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw + ['status_url' => $this->statusUrl];
    }
}
