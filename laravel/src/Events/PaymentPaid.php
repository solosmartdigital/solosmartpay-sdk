<?php

namespace SolosmartDigital\Solosmartpay\Events;

use SolosmartDigital\Solosmartpay\Data\Invoice;
use SolosmartDigital\Solosmartpay\Events\Concerns\DetectsSandbox;

/** Webhook `payment.paid`. Payment confirmed: the money is yours. Fulfil the order here. */
final class PaymentPaid
{
    use DetectsSandbox;

    public const TYPE = 'payment.paid';

    /** @param array<string, mixed> $event the full webhook payload: id, type, created_at, data */
    public function __construct(public readonly Invoice $invoice, public readonly array $event)
    {
    }
}
