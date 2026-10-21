<?php

namespace SolosmartDigital\Solosmartpay;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use SolosmartDigital\Solosmartpay\Data\Invoice;
use SolosmartDigital\Solosmartpay\Data\PaymentMethod;
use SolosmartDigital\Solosmartpay\Exceptions\SolosmartpayException;

/**
 * Solosmartpay API client. Use it through the facade:
 *
 *   $invoice = Solosmartpay::createQris(50000, reference: 'ORDER-123');
 *   return redirect($invoice->checkoutUrl);
 */
class Solosmartpay
{
    public const VERSION = '1.1.0';

    /** What a sandbox payment can be forced into with simulate(). */
    public const SANDBOX_RESULTS = ['paid', 'failed', 'expired'];

    /** @param array<string, mixed> $config */
    public function __construct(private array $config)
    {
    }

    /**
     * Create a QRIS invoice. The total the payer must transfer includes a unique 3-digit code.
     *
     * @param  ?string  $idempotencyKey  Leave null: a stable key is derived from the request, so retrying the same
     *                                   order never creates a second invoice. Pass your own to override.
     * @param  ?bool  $wait  Wait until the invoice is ready (default: config `wait_until_ready`).
     */
    public function createQris(int $amount, ?string $reference = null, ?string $redirectUrl = null, ?string $idempotencyKey = null, ?bool $wait = null): Invoice
    {
        $body = $this->clean(['amount' => $amount, 'reference' => $reference, 'redirect_url' => $redirectUrl]);
        $invoice = $this->create('/qris', $body, $idempotencyKey ?? $this->deriveKey('qris', $body));

        return $this->maybeWait($invoice, $wait);
    }

    /**
     * Create a bank VA / e-wallet / retail bill.
     *
     * @param  string  $method  a code from methods(), or "all" to let the payer pick on the checkout page
     */
    public function createPayment(int $amount, string $method = 'all', ?string $reference = null, ?string $redirectUrl = null, ?string $idempotencyKey = null, ?bool $wait = null): Invoice
    {
        $body = $this->clean(['amount' => $amount, 'method' => $method, 'reference' => $reference, 'redirect_url' => $redirectUrl]);
        $invoice = $this->create('/payments', $body, $idempotencyKey ?? $this->deriveKey('payments', $body));

        return $this->maybeWait($invoice, $wait);
    }

    public function findQris(string $id): Invoice
    {
        return $this->fetch('/qris/'.rawurlencode($id));
    }

    public function findPayment(string $id): Invoice
    {
        return $this->fetch('/payments/'.rawurlencode($id));
    }

    /** Cancel a pending QRIS invoice. Idempotent; a paid or expired invoice raises a 409 SolosmartpayException. */
    public function cancelQris(string $id): Invoice
    {
        return $this->fetch('/qris/'.rawurlencode($id), 'DELETE');
    }

    public function cancelPayment(string $id): Invoice
    {
        return $this->fetch('/payments/'.rawurlencode($id), 'DELETE');
    }

    /** Reload an invoice from the API (works for both QRIS and bank/e-wallet invoices). */
    public function refresh(Invoice $invoice): Invoice
    {
        return $invoice->isQris() ? $this->findQris($invoice->id) : $this->findPayment($invoice->id);
    }

    /** Find an invoice by id without knowing its channel (tries QRIS first). Null when it does not exist. */
    public function find(string $id): ?Invoice
    {
        foreach (['findQris', 'findPayment'] as $lookup) {
            try {
                return $this->{$lookup}($id);
            } catch (SolosmartpayException $e) {
                if ($e->status !== 404) {
                    throw $e;
                }
            }
        }

        return null;
    }

    /**
     * Poll until provisioning has finished (or the invoice left "pending"). Returns the latest state even on timeout.
     */
    public function waitUntilReady(Invoice $invoice, ?int $timeout = null, float $interval = 1.0): Invoice
    {
        $deadline = microtime(true) + ($timeout ?? (int) ($this->config['ready_timeout'] ?? 10));

        while (! $invoice->isReady() && $invoice->isPending() && microtime(true) < $deadline) {
            usleep((int) ($interval * 1_000_000));
            $invoice = $this->refresh($invoice);
        }

        return $invoice;
    }

    /** @return Collection<int, PaymentMethod> methods enabled for your store, with fees */
    public function methods(): Collection
    {
        $response = $this->send('GET', '/payment-methods');

        return collect($response->json('data', []))->map(fn (array $m) => PaymentMethod::fromArray($m))->values();
    }

    /** True when SOLOSMARTPAY_TOKEN is a sandbox token (ssp_test_…): its payments are test payments and never move money. */
    public function isSandbox(): bool
    {
        return str_starts_with((string) ($this->config['token'] ?? ''), 'ssp_test_');
    }

    /**
     * Sandbox only: decide how a pending test payment ends ('paid', 'failed' or 'expired'). Your webhook then receives
     * the matching event flagged as sandbox. A live token, or a payment that already finished, raises SolosmartpayException.
     */
    public function simulate(Invoice|string $invoice, string $result): Invoice
    {
        if (! $this->isSandbox()) {
            throw new SolosmartpayException('Simulasi hanya bisa dengan token sandbox (ssp_test_…).');
        }
        if (! in_array($result, self::SANDBOX_RESULTS, true)) {
            throw new \InvalidArgumentException('Hasil simulasi harus salah satu dari: '.implode(', ', self::SANDBOX_RESULTS).'.');
        }

        $id = $invoice instanceof Invoice ? $invoice->id : $invoice;
        $response = $this->send('POST', '/sandbox/payments/'.rawurlencode($id).'/simulate', ['result' => $result]);

        return Invoice::fromArray((array) $response->json('data', []));
    }

    /** Verify the credentials with a cheap authenticated call. Throws SolosmartpayException when they are wrong. */
    public function ping(): int
    {
        return $this->methods()->count();
    }

    // ── internals ─────────────────────────────────────────────────────────

    private function create(string $path, array $body, string $idempotencyKey): Invoice
    {
        $response = $this->send('POST', $path, $body, $idempotencyKey);

        return Invoice::fromArray((array) $response->json('data', []), $response->json('status_url'));
    }

    private function fetch(string $path, string $method = 'GET'): Invoice
    {
        $response = $this->send($method, $path);

        return Invoice::fromArray((array) $response->json('data', []), $response->json('status_url'));
    }

    private function maybeWait(Invoice $invoice, ?bool $wait): Invoice
    {
        $wait ??= (bool) ($this->config['wait_until_ready'] ?? true);

        return $wait ? $this->waitUntilReady($invoice) : $invoice;
    }

    private function send(string $method, string $path, ?array $body = null, ?string $idempotencyKey = null): Response
    {
        // Safe to repeat: reads, and writes that carry an Idempotency-Key (the API returns the same invoice).
        $maxRetries = ($method === 'GET' || $idempotencyKey !== null) ? max(0, (int) ($this->config['retries'] ?? 2)) : 0;
        $delayMs = max(0, (int) ($this->config['retry_delay_ms'] ?? 250));
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $response = $this->http($idempotencyKey)->send($method, $this->url($path), $body === null ? [] : ['json' => $body]);
            } catch (ConnectionException $e) {
                if ($attempt <= $maxRetries) {
                    usleep($delayMs * 1000 * 2 ** ($attempt - 1));

                    continue;
                }

                throw new SolosmartpayException('Tidak bisa terhubung ke Solosmartpay: '.$e->getMessage());
            }

            if ($response->successful()) {
                return $response;
            }

            // Network trouble, rate limiting and server errors may pass; a 4xx will not change by asking again.
            if (($response->status() === 429 || $response->status() >= 500) && $attempt <= $maxRetries) {
                usleep($delayMs * 1000 * 2 ** ($attempt - 1));

                continue;
            }

            throw SolosmartpayException::fromResponse($response);
        }
    }

    private function http(?string $idempotencyKey): PendingRequest
    {
        $token = (string) ($this->config['token'] ?? '');
        if ($token === '') {
            throw new SolosmartpayException('SOLOSMARTPAY_TOKEN belum diisi. Buat token di dashboard (Developer > API Tokens) lalu isi di .env.');
        }
        if (! str_starts_with($token, 'ssp_')) {
            throw new SolosmartpayException('SOLOSMARTPAY_TOKEN tidak valid: token Solosmartpay diawali "ssp_".');
        }

        return Http::withHeaders(array_filter([
            'X-API-Token' => $token,
            'Idempotency-Key' => $idempotencyKey,
            'User-Agent' => 'solosmartpay-laravel/'.self::VERSION,
        ]))->acceptJson()->asJson()
            ->timeout((int) ($this->config['timeout'] ?? 20))
            ->connectTimeout(10);
    }

    private function url(string $path): string
    {
        return rtrim((string) ($this->config['base_url'] ?? ''), '/').$path;
    }

    /** @param array<string, mixed> $body */
    private function clean(array $body): array
    {
        return array_filter($body, fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Same order => same key => the API hands back the existing invoice instead of creating another.
     * Without a reference there is nothing stable to derive from, so each call is a new invoice.
     */
    private function deriveKey(string $operation, array $body): string
    {
        if (! isset($body['reference'])) {
            return 'ssp-'.str_replace('-', '', (string) Str::uuid());
        }
        ksort($body);

        return 'ssp-'.substr(hash('sha256', $operation.'|'.json_encode($body, JSON_UNESCAPED_SLASHES)), 0, 40);
    }

    /** The origin that serves checkout pages and embed.js (the API base URL without /v1). */
    public function origin(): string
    {
        $parts = parse_url((string) ($this->config['base_url'] ?? ''));

        return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? 'solosmartpay.com').(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
