<?php

namespace Solosmartpay\Resources;

use Solosmartpay\Models\Invoice;
use Solosmartpay\SolosmartpayException;

/** @internal Shared polling for invoices that are provisioned asynchronously. */
trait WaitsForCheckout
{
    /**
     * @template T of Invoice
     * @param  T|string  $invoice
     * @param  callable(string): T  $fetch
     * @return T
     */
    private function poll(Invoice|string $invoice, callable $fetch, int $timeoutSeconds, int $intervalMs): Invoice
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $current = $invoice instanceof Invoice ? $invoice : $fetch($invoice);

        while (true) {
            if ($current->provisioningFailed()) {
                throw new SolosmartpayException('Tagihan gagal disiapkan'.($current->errorCode ? ' ('.$current->errorCode.')' : '').'.', 0);
            }
            if ($current->isReady() || ! $current->isPending()) {
                return $current;
            }
            if (microtime(true) >= $deadline) {
                throw new SolosmartpayException("Tagihan belum siap setelah {$timeoutSeconds} detik.", 0);
            }
            usleep($intervalMs * 1000);
            $current = $fetch($current->id);
        }
    }
}
