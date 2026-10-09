<?php

namespace Solosmartpay\Resources;

use Solosmartpay\Client;
use Solosmartpay\Models;

/** QRIS invoices: `POST/GET/DELETE /v1/qris`. */
final class Qris
{
    use WaitsForCheckout;

    public function __construct(private Client $client)
    {
    }

    /**
     * Create a QRIS invoice. Provisioning is asynchronous: call waitUntilReady() before using the QR or embedUrl.
     * The same $idempotencyKey with the same body returns the same invoice; a different body is a 409.
     */
    public function create(int $amount, string $idempotencyKey, ?string $reference = null, ?string $redirectUrl = null): Models\Qris
    {
        $response = $this->client->request('POST', '/qris', array_filter([
            'amount' => $amount, 'reference' => $reference, 'redirect_url' => $redirectUrl,
        ], fn ($v) => $v !== null), $idempotencyKey, true);

        return Models\Qris::fromArray($response['data'], $response['status_url'] ?? null);
    }

    public function get(string $id): Models\Qris
    {
        return Models\Qris::fromArray($this->client->request('GET', '/qris/'.rawurlencode($id)));
    }

    /** Cancel a pending invoice. Idempotent; throws 409 once paid or expired. */
    public function cancel(string $id): Models\Qris
    {
        return Models\Qris::fromArray($this->client->request('DELETE', '/qris/'.rawurlencode($id)));
    }

    /** Poll until the checkout can be shown. Throws if provisioning fails or the timeout passes. */
    public function waitUntilReady(Models\Qris|string $qris, int $timeoutSeconds = 30, int $intervalMs = 1000): Models\Qris
    {
        return $this->poll($qris, fn (string $id) => $this->get($id), $timeoutSeconds, $intervalMs);
    }
}
