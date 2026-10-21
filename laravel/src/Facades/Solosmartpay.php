<?php

namespace SolosmartDigital\Solosmartpay\Facades;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use SolosmartDigital\Solosmartpay\Data\Invoice;
use SolosmartDigital\Solosmartpay\Data\PaymentMethod;
use SolosmartDigital\Solosmartpay\Solosmartpay as Client;

/**
 * @method static Invoice createQris(int $amount, ?string $reference = null, ?string $redirectUrl = null, ?string $idempotencyKey = null, ?bool $wait = null)
 * @method static Invoice createPayment(int $amount, string $method = 'all', ?string $reference = null, ?string $redirectUrl = null, ?string $idempotencyKey = null, ?bool $wait = null)
 * @method static Invoice findQris(string $id)
 * @method static Invoice findPayment(string $id)
 * @method static Invoice|null find(string $id)
 * @method static Invoice cancelQris(string $id)
 * @method static Invoice cancelPayment(string $id)
 * @method static Invoice refresh(Invoice $invoice)
 * @method static Invoice waitUntilReady(Invoice $invoice, ?int $timeout = null, float $interval = 1.0)
 * @method static Collection<int, PaymentMethod> methods()
 * @method static int ping()
 * @method static bool isSandbox()
 * @method static Invoice simulate(Invoice|string $invoice, string $result)
 * @method static string origin()
 *
 * @see Client
 */
class Solosmartpay extends Facade
{
    /** @var array<string, mixed> read by the stubs at request time, so calling fake() again just updates it */
    private static array $fakeOverrides = [];

    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }

    /**
     * Stub the whole API for your own tests, no network and no real token needed:
     *
     *   Solosmartpay::fake();
     *   $invoice = Solosmartpay::createQris(50000, 'ORDER-1');   // a ready, pending invoice
     *
     * Pass overrides to change the returned invoice, e.g. Solosmartpay::fake(['status' => 'paid']).
     *
     * @param  array<string, mixed>  $invoiceOverrides
     */
    public static function fake(array $invoiceOverrides = []): void
    {
        self::$fakeOverrides = $invoiceOverrides;
        config(['solosmartpay.token' => config('solosmartpay.token') ?: 'ssp_fake_token_for_tests']);
        static::clearResolvedInstance(Client::class);
        app()->forgetInstance(Client::class);

        $invoice = static fn (string $id, string $channel, ?int $amount = null, ?string $reference = null) => array_merge([
            'id' => $id, 'reference' => $reference, 'status' => 'pending', 'channel' => $channel,
            'method' => $channel === 'qris' ? 'qris' : 'all', 'amount' => $amount ?? 10000,
            'unique_code' => $channel === 'qris' ? 123 : null, 'total_amount' => ($amount ?? 10000) + ($channel === 'qris' ? 123 : 0),
            'fee' => 300, 'net_amount' => ($amount ?? 10000) - 300, 'provisioning_status' => 'ready', 'error_code' => null,
            'expires_at' => now()->addMinutes(15)->toIso8601String(), 'paid_at' => null, 'created_at' => now()->toIso8601String(),
            'checkout_url' => "https://pay.test/pay/{$id}", 'basic_url' => "https://pay.test/pay/{$id}",
            'embed_url' => "https://pay.test/pay/{$id}/embed", 'payment_url' => null,
            'qris_string' => $channel === 'qris' ? '000201FAKE' : null, 'qris_image' => null, 'redirect_url' => null,
        ], self::$fakeOverrides);

        Http::fake([
            '*/payment-methods' => Http::response(['data' => [
                ['code' => 'BC', 'name' => 'BCA Virtual Account', 'group' => 'va', 'fee_type' => 'flat', 'fee' => 5500, 'min_amount' => 10000, 'max_amount' => 50000000],
            ]]),
            '*/qris' => fn ($request) => Http::response(['data' => $invoice('fake-qris-id', 'qris', $request['amount'] ?? null, $request['reference'] ?? null), 'status_url' => 'https://pay.test/v1/qris/fake-qris-id'], 202),
            '*/qris/*' => fn () => Http::response(['data' => $invoice('fake-qris-id', 'qris')]),
            '*/payments' => fn ($request) => Http::response(['data' => $invoice('fake-payment-id', 'bank_transfer', $request['amount'] ?? null, $request['reference'] ?? null), 'status_url' => 'https://pay.test/v1/payments/fake-payment-id'], 202),
            '*/payments/*' => fn () => Http::response(['data' => $invoice('fake-payment-id', 'bank_transfer')]),
        ]);
    }
}
