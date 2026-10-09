<?php

namespace Solosmartpay;

use Solosmartpay\Resources\PaymentMethods;
use Solosmartpay\Resources\Payments;
use Solosmartpay\Resources\Qris;

/**
 * Solosmartpay API client.
 *
 *   $ssp = new Solosmartpay\Client(getenv('SOLOSMARTPAY_TOKEN'), 'https://your-solosmartpay-domain/v1');
 *
 *   $qris = $ssp->qris->create(50000, 'order-123-create', 'ORDER-123');   // Models\Qris
 *   $qris = $ssp->qris->waitUntilReady($qris);                            // embedUrl / qrisImage filled
 *
 *   $bill = $ssp->payments->create(150000, 'BR', 'order-124-create');     // Models\Payment
 *   $methods = $ssp->methods->all();                                      // Models\PaymentMethod[]
 *
 * Keep the token on your server: never ship it to browsers or mobile apps.
 * The older array-returning helpers (createQris(), getQris(), ...) still work.
 */
class Client
{
    public const VERSION = '1.2.0';

    public readonly Qris $qris;

    public readonly Payments $payments;

    public readonly PaymentMethods $methods;

    private string $baseUrl;

    /** @var (callable(string, string, array<int, string>, ?string): array{0: int, 1: string|false, 2: string})|null */
    private $transport;

    /**
     * @param (callable(string $method, string $url, array<int, string> $headers, ?string $body): array{0: int, 1: string|false, 2: string})|null $transport
     *        Replace the HTTP layer (tests, custom HTTP client). Return [status, body, error]. Default: cURL.
     */
    public function __construct(
        private string $token,
        string $baseUrl,
        private int $timeout = 20,
        private int $maxRetries = 2,
        ?callable $transport = null,
    ) {
        if (! str_starts_with($token, 'ssp_')) {
            throw new \InvalidArgumentException('Token Solosmartpay harus diawali "ssp_".');
        }
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->transport = $transport;
        $this->qris = new Qris($this);
        $this->payments = new Payments($this);
        $this->methods = new PaymentMethods($this);
    }

    /** Create and queue a QRIS invoice for provisioning. */
    public function createQris(int $amount, string $idempotencyKey, ?string $reference = null, ?string $redirectUrl = null): array
    {
        return $this->request('POST', '/qris', array_filter(['amount' => $amount, 'reference' => $reference, 'redirect_url' => $redirectUrl], fn ($v) => $v !== null), $idempotencyKey, true);
    }

    public function getQris(string $id): array
    {
        return $this->request('GET', '/qris/'.rawurlencode($id));
    }

    /** Cancel a pending QRIS invoice (idempotent; 409 once paid or expired). */
    public function cancelQris(string $id): array
    {
        return $this->request('DELETE', '/qris/'.rawurlencode($id));
    }

    /** Bank VA / e-wallet methods enabled for your store, with their fees. */
    public function paymentMethods(): array
    {
        return $this->request('GET', '/payment-methods');
    }

    /** Queue a bank VA / e-wallet bill for provisioning. */
    public function createPayment(int $amount, string $method, string $idempotencyKey, ?string $reference = null, ?string $redirectUrl = null): array
    {
        return $this->request('POST', '/payments', array_filter(['amount' => $amount, 'method' => $method, 'reference' => $reference, 'redirect_url' => $redirectUrl], fn ($v) => $v !== null), $idempotencyKey, true);
    }

    public function getPayment(string $id): array
    {
        return $this->request('GET', '/payments/'.rawurlencode($id));
    }

    /** Cancel a pending bank VA / e-wallet bill (idempotent; 409 once paid or expired). */
    public function cancelPayment(string $id): array
    {
        return $this->request('DELETE', '/payments/'.rawurlencode($id));
    }

    /**
     * Low-level call used by the resource classes.
     * Retries network errors, 429 and 5xx only when the request is safe to repeat.
     *
     * @internal
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, ?array $body = null, ?string $idempotencyKey = null, bool $preserveEnvelope = false): array
    {
        $retryable = in_array($method, ['GET', 'DELETE'], true) || ($idempotencyKey !== null && $idempotencyKey !== '');
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'X-API-Token: '.$this->token,
            ...($idempotencyKey ? ['Idempotency-Key: '.$idempotencyKey] : []),
            'User-Agent: solosmartpay-php/'.self::VERSION,
        ];
        $payload = $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR);
        $attempt = 0;

        while (true) {
            $attempt++;
            [$status, $raw, $error] = $this->send($method, $this->baseUrl.$path, $headers, $payload);

            $transient = $raw === false || $status === 429 || $status >= 500;
            if ($transient && $retryable && $attempt <= $this->maxRetries) {
                usleep((int) (250_000 * 2 ** ($attempt - 1)));

                continue;
            }

            if ($raw === false) {
                throw new SolosmartpayException('Tidak bisa terhubung ke Solosmartpay: '.$error);
            }

            $json = json_decode($raw, true);
            if ($status < 200 || $status >= 300) {
                throw new SolosmartpayException($json['message'] ?? "HTTP {$status}", $status, $json['errors'] ?? []);
            }

            return $preserveEnvelope ? $json : ($json['data'] ?? $json);
        }
    }

    /** @return array{0: int, 1: string|false, 2: string} */
    private function send(string $method, string $url, array $headers, ?string $payload): array
    {
        if ($this->transport !== null) {
            return ($this->transport)($method, $url, $headers, $payload);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $payload,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return [$status, $raw, $error];
    }
}
