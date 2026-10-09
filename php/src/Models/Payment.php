<?php

namespace Solosmartpay\Models;

/** A bank virtual account, e-wallet, or retail invoice. */
final class Payment extends Invoice
{
    /** Gateway page for e-wallet / retail. Null for virtual accounts: pay those from the Solosmartpay checkout. */
    public ?string $paymentUrl = null;

    public static function fromArray(array $data, ?string $statusUrl = null): static
    {
        $payment = parent::fromArray($data, $statusUrl);
        $payment->paymentUrl = isset($data['payment_url']) ? (string) $data['payment_url'] : null;

        return $payment;
    }
}
