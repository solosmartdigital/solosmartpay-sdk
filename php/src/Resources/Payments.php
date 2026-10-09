<?php

namespace Solosmartpay\Resources;

use Solosmartpay\Client;
use Solosmartpay\Models;

/** Bank virtual account, e-wallet, and retail invoices: `POST/GET/DELETE /v1/payments`. */
final class Payments
{
    use WaitsForCheckout;

    public function __construct(private Client $client)
    {
    }

    /**
     * @param string $method a code from methods->all(), or "all" to let the customer choose at checkout
     */
    public function create(int $amount, string $method, string $idempotencyKey, ?string $reference = null, ?string $redirectUrl = null): Models\Payment
    {
        $response = $this->client->request('POST', '/payments', array_filter([
            'amount' => $amount, 'method' => $method, 'reference' => $reference, 'redirect_url' => $redirectUrl,
        ], fn ($v) => $v !== null), $idempotencyKey, true);

        return Models\Payment::fromArray($response['data'], $response['status_url'] ?? null);
    }

    public function get(string $id): Models\Payment
    {
        return Models\Payment::fromArray($this->client->request('GET', '/payments/'.rawurlencode($id)));
    }

    /** Cancel a pending invoice. Idempotent; throws 409 once paid or expired. */
    public function cancel(string $id): Models\Payment
    {
        return Models\Payment::fromArray($this->client->request('DELETE', '/payments/'.rawurlencode($id)));
    }

    /** Poll until the checkout can be shown. Throws if provisioning fails or the timeout passes. */
    public function waitUntilReady(Models\Payment|string $payment, int $timeoutSeconds = 30, int $intervalMs = 1000): Models\Payment
    {
        return $this->poll($payment, fn (string $id) => $this->get($id), $timeoutSeconds, $intervalMs);
    }
}
