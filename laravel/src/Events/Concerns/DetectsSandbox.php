<?php

namespace SolosmartDigital\Solosmartpay\Events\Concerns;

/** For webhook events that carry an `$invoice` and the raw `$event` payload. */
trait DetectsSandbox
{
    /**
     * True when the delivery was caused by a sandbox payment (a token starting with ssp_test_).
     * Never fulfil a real order from it.
     */
    public function isSandbox(): bool
    {
        return ($this->event['sandbox'] ?? false) === true || $this->invoice->isSandbox();
    }
}
