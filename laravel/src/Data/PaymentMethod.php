<?php

namespace SolosmartDigital\Solosmartpay\Data;

/** A bank VA / e-wallet / retail method enabled for your store. */
final class PaymentMethod
{
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly string $group,
        public readonly string $feeType,
        public readonly float $fee,
        public readonly ?int $minAmount,
        public readonly ?int $maxAmount,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            code: (string) $data['code'],
            name: (string) ($data['name'] ?? $data['code']),
            group: (string) ($data['group'] ?? ''),
            feeType: (string) ($data['fee_type'] ?? 'flat'),
            fee: (float) ($data['fee'] ?? 0),
            minAmount: isset($data['min_amount']) ? (int) $data['min_amount'] : null,
            maxAmount: isset($data['max_amount']) ? (int) $data['max_amount'] : null,
        );
    }

    /** Fee for a given amount, in rupiah (percent methods are rounded up). */
    public function feeFor(int $amount): int
    {
        return $this->feeType === 'percent' ? (int) ceil($amount * $this->fee / 100) : (int) round($this->fee);
    }
}
