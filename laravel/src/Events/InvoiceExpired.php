<?php

namespace SolosmartDigital\Solosmartpay\Events;

use SolosmartDigital\Solosmartpay\Data\Invoice;
use SolosmartDigital\Solosmartpay\Events\Concerns\DetectsSandbox;

/** Webhook `invoice.expired`. The payer did not pay in time. */
final class InvoiceExpired
{
    use DetectsSandbox;

    public const TYPE = 'invoice.expired';

    /** @param array<string, mixed> $event the full webhook payload: id, type, created_at, data */
    public function __construct(public readonly Invoice $invoice, public readonly array $event)
    {
    }
}
