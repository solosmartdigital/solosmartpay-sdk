<?php

namespace Solosmartpay\Resources;

use InvalidArgumentException;
use Solosmartpay\Client;
use Solosmartpay\Models;
use Solosmartpay\SolosmartpayException;

/**
 * Sandbox helpers: `POST /v1/sandbox/payments/{id}/simulate`.
 *
 * A sandbox token (ssp_test_…) creates test payments only: no money, no KYC, and nothing reaches your balance or
 * reports. Create the payment as usual, then decide how it ends:
 *
 *   $ssp = new Solosmartpay\Client('ssp_test_…', 'https://your-domain/v1');
 *   $qris = $ssp->qris->create(50000, 'order-1-create');
 *   $ssp->sandbox->pay($qris);            // fires payment.paid (flagged sandbox) at your webhook
 */
final class Sandbox
{
    public const RESULTS = ['paid', 'failed', 'expired'];

    public function __construct(private Client $client)
    {
    }

    /** Decide how a pending sandbox payment ends. Throws a 409 when it already finished. */
    public function simulate(Models\Invoice|string $payment, string $result): Models\Invoice
    {
        if (! $this->client->isSandbox()) {
            throw new SolosmartpayException('Simulasi hanya bisa dengan token sandbox (ssp_test_…).');
        }
        if (! in_array($result, self::RESULTS, true)) {
            throw new InvalidArgumentException('Hasil simulasi harus salah satu dari: '.implode(', ', self::RESULTS).'.');
        }

        $id = $payment instanceof Models\Invoice ? $payment->id : $payment;

        return Models\Invoice::fromArray($this->client->request('POST', '/sandbox/payments/'.rawurlencode($id).'/simulate', ['result' => $result]));
    }

    public function pay(Models\Invoice|string $payment): Models\Invoice
    {
        return $this->simulate($payment, 'paid');
    }

    public function fail(Models\Invoice|string $payment): Models\Invoice
    {
        return $this->simulate($payment, 'failed');
    }

    public function expire(Models\Invoice|string $payment): Models\Invoice
    {
        return $this->simulate($payment, 'expired');
    }
}
