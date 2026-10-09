<?php

namespace Solosmartpay\Models;

use JsonSerializable;

/** A bank VA / e-wallet / retail method enabled for your store (`GET /v1/payment-methods`). */
final class PaymentMethod implements JsonSerializable
{
    public const GROUP_VIRTUAL_ACCOUNT = 'va';
    public const GROUP_EWALLET = 'ewallet';
    public const GROUP_RETAIL = 'retail';
    public const GROUP_PAYLATER = 'paylater';

    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly string $group,
        public readonly string $feeType,
        public readonly float $fee,
        public readonly int $minAmount,
        public readonly ?int $maxAmount,
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            code: (string) ($data['code'] ?? ''),
            name: (string) ($data['name'] ?? ''),
            group: (string) ($data['group'] ?? ''),
            feeType: (string) ($data['fee_type'] ?? ''),
            fee: (float) ($data['fee'] ?? 0),
            minAmount: (int) ($data['min_amount'] ?? 0),
            maxAmount: isset($data['max_amount']) ? (int) $data['max_amount'] : null,
            raw: $data,
        );
    }

    public function isVirtualAccount(): bool
    {
        return $this->group === self::GROUP_VIRTUAL_ACCOUNT;
    }

    public function isEwallet(): bool
    {
        return $this->group === self::GROUP_EWALLET;
    }

    public function isRetail(): bool
    {
        return $this->group === self::GROUP_RETAIL;
    }

    /** Whether `$amount` is inside this method's limits. */
    public function accepts(int $amount): bool
    {
        return $amount >= $this->minAmount && ($this->maxAmount === null || $amount <= $this->maxAmount);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->raw;
    }
}
