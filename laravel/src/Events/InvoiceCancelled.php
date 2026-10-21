<?php

namespace SolosmartDigital\Solosmartpay\Events;

use SolosmartDigital\Solosmartpay\Data\Invoice;
use SolosmartDigital\Solosmartpay\Events\Concerns\DetectsSandbox;

/** Webhook `invoice.cancelled`. The invoice was cancelled. */
final class InvoiceCancelled
{
    use DetectsSandbox;

    public const TYPE = 'invoice.cancelled';

    /** @param array<string, mixed> $event the full webhook payload: id, type, created_at, data */
    public function __construct(public readonly Invoice $invoice, public readonly array $event)
    {
    }
}
