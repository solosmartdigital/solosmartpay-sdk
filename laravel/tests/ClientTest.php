<?php

namespace SolosmartDigital\Solosmartpay\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use SolosmartDigital\Solosmartpay\Data\Invoice;
use SolosmartDigital\Solosmartpay\Exceptions\SolosmartpayException;
use SolosmartDigital\Solosmartpay\Facades\Solosmartpay;

class ClientTest extends TestCase
{
    public function test_create_qris_sends_the_right_request_and_returns_an_invoice(): void
    {
        Http::fake(['api.test/v1/qris' => Http::response(['data' => $this->invoiceData(), 'status_url' => 'https://api.test/v1/qris/3f2b'], 202)]);

        $invoice = Solosmartpay::createQris(50000, 'ORDER-123', 'https://shop.test/thanks');

        $this->assertInstanceOf(Invoice::class, $invoice);
        $this->assertSame(50127, $invoice->totalAmount);
        $this->assertSame(127, $invoice->uniqueCode);
        $this->assertSame('https://api.test/pay/3f2b', $invoice->checkoutUrl);
        $this->assertSame('https://api.test/v1/qris/3f2b', $invoice->statusUrl);
        $this->assertTrue($invoice->isQris() && $invoice->isReady() && $invoice->isPending() && ! $invoice->isPaid());
        $this->assertSame('2026-10-08', $invoice->expiresAt->format('Y-m-d'));

        Http::assertSent(function (Request $r) {
            return $r->method() === 'POST' && $r->url() === 'https://api.test/v1/qris'
                && $r->hasHeader('X-API-Token', 'ssp_test_token')
                && str_starts_with($r->header('Idempotency-Key')[0], 'ssp-')
                && $r->data() === ['amount' => 50000, 'reference' => 'ORDER-123', 'redirect_url' => 'https://shop.test/thanks'];
        });
    }

    public function test_the_idempotency_key_is_stable_for_the_same_order_and_valid_for_the_api(): void
    {
        Http::fake(['*' => Http::response(['data' => $this->invoiceData()], 202)]);

        Solosmartpay::createQris(50000, 'ORDER-123');
        Solosmartpay::createQris(50000, 'ORDER-123');
        Solosmartpay::createQris(60000, 'ORDER-123');
        Solosmartpay::createQris(50000);
        Solosmartpay::createQris(50000);

        $keys = collect(Http::recorded())->map(fn ($pair) => $pair[0]->header('Idempotency-Key')[0])->all();
        $this->assertSame($keys[0], $keys[1]);
        $this->assertNotSame($keys[0], $keys[2]);
        $this->assertNotSame($keys[3], $keys[4], 'no reference: nothing stable, each call is its own invoice');
        foreach ($keys as $key) {
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9._:-]{8,100}$/', $key);
        }
    }

    public function test_a_custom_idempotency_key_wins(): void
    {
        Http::fake(['*' => Http::response(['data' => $this->invoiceData()], 202)]);

        Solosmartpay::createQris(50000, 'ORDER-1', idempotencyKey: 'my-own-key-0001');

        Http::assertSent(fn (Request $r) => $r->header('Idempotency-Key')[0] === 'my-own-key-0001');
    }

    public function test_create_payment_defaults_to_all_methods(): void
    {
        Http::fake(['*' => Http::response(['data' => $this->invoiceData(['channel' => 'bank_transfer', 'method' => 'all', 'unique_code' => null]), 'status_url' => 'x'], 202)]);

        $invoice = Solosmartpay::createPayment(150000, reference: 'ORDER-124');

        $this->assertFalse($invoice->isQris());
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.test/v1/payments' && $r['method'] === 'all' && $r['amount'] === 150000);
    }

    public function test_find_refresh_and_cancel_use_the_right_endpoints(): void
    {
        Http::fake([
            'api.test/v1/qris/abc' => fn (Request $r) => Http::response(['data' => $this->invoiceData(['status' => $r->method() === 'DELETE' ? 'cancelled' : 'paid'])]),
            'api.test/v1/payments/xyz' => Http::response(['data' => $this->invoiceData(['channel' => 'bank_transfer'])]),
        ]);

        $this->assertTrue(Solosmartpay::findQris('abc')->isPaid());
        $this->assertTrue(Solosmartpay::cancelQris('abc')->isCancelled());
        $this->assertSame('bank_transfer', Solosmartpay::findPayment('xyz')->channel);

        $qris = Invoice::fromArray($this->invoiceData(['id' => 'abc']));
        $this->assertTrue(Solosmartpay::refresh($qris)->isPaid());
    }

    public function test_find_without_knowing_the_channel_falls_back_and_returns_null_when_missing(): void
    {
        Http::fake([
            'api.test/v1/qris/pay-1' => Http::response(['message' => 'Not found'], 404),
            'api.test/v1/payments/pay-1' => Http::response(['data' => $this->invoiceData(['id' => 'pay-1', 'channel' => 'bank_transfer'])]),
            'api.test/v1/qris/none' => Http::response([], 404),
            'api.test/v1/payments/none' => Http::response([], 404),
        ]);

        $this->assertSame('pay-1', Solosmartpay::find('pay-1')->id);
        $this->assertNull(Solosmartpay::find('none'));
    }

    public function test_methods_returns_typed_collection(): void
    {
        Http::fake(['*' => Http::response(['data' => [
            ['code' => 'BC', 'name' => 'BCA Virtual Account', 'group' => 'va', 'fee_type' => 'flat', 'fee' => 5500, 'min_amount' => 10000, 'max_amount' => 50000000],
            ['code' => 'OV', 'name' => 'OVO', 'group' => 'ewallet', 'fee_type' => 'percent', 'fee' => 3.5, 'min_amount' => null, 'max_amount' => null],
        ]])]);

        $methods = Solosmartpay::methods();

        $this->assertCount(2, $methods);
        $this->assertSame('BCA Virtual Account', $methods[0]->name);
        $this->assertSame(5500, $methods[0]->feeFor(100000));
        $this->assertSame(3500, $methods[1]->feeFor(100000));
        $this->assertSame(4, $methods[1]->feeFor(100));
    }

    public function test_api_errors_become_exceptions_with_status_and_validation_errors(): void
    {
        Http::fake(['*' => Http::response(['message' => 'The amount field is required.', 'errors' => ['amount' => ['The amount field is required.']]], 422)]);

        try {
            Solosmartpay::createQris(0);
            $this->fail('expected an exception');
        } catch (SolosmartpayException $e) {
            $this->assertSame(422, $e->status);
            $this->assertTrue($e->isValidation());
            $this->assertSame(['amount' => ['The amount field is required.']], $e->errors);
        }

        $this->freshHttp();
        Http::fake(['*' => Http::response(['message' => 'Referensi sudah dipakai untuk tagihan lain.'], 409)]);
        try {
            Solosmartpay::createQris(1000, 'X');
            $this->fail('expected an exception');
        } catch (SolosmartpayException $e) {
            $this->assertTrue($e->isConflict());
        }
    }

    public function test_client_errors_are_not_retried_but_server_errors_are(): void
    {
        Http::fake(['*' => Http::response(['message' => 'bad'], 422)]);
        try { Solosmartpay::createQris(1000, 'A'); } catch (SolosmartpayException) {}
        Http::assertSentCount(1);

        config(['solosmartpay.retries' => 2]);
        $this->rebuildClient();
        $this->freshHttp();
        Http::fake(['*' => Http::sequence()->push(['message' => 'boom'], 503)->push(['message' => 'boom'], 503)->push(['data' => $this->invoiceData()], 202)]);

        $invoice = Solosmartpay::createQris(1000, 'B');

        $this->assertSame('ORDER-123', $invoice->reference);
        Http::assertSentCount(3);
    }

    public function test_wait_until_ready_polls_until_provisioning_is_done(): void
    {
        Http::fake([
            'api.test/v1/qris' => Http::response(['data' => $this->invoiceData(['provisioning_status' => 'queued', 'checkout_url' => null, 'embed_url' => null]), 'status_url' => 'x'], 202),
            'api.test/v1/qris/*' => Http::sequence()
                ->push(['data' => $this->invoiceData(['provisioning_status' => 'processing'])])
                ->push(['data' => $this->invoiceData(['provisioning_status' => 'ready'])]),
        ]);

        $invoice = Solosmartpay::createQris(50000, 'ORDER-123', wait: false);
        $this->assertTrue($invoice->isProvisioning());

        $ready = Solosmartpay::waitUntilReady($invoice, timeout: 5, interval: 0.01);

        $this->assertTrue($ready->isReady());
        $this->assertNotNull($ready->checkoutUrl);
    }

    public function test_wait_until_ready_gives_up_at_the_timeout_and_returns_the_latest_state(): void
    {
        Http::fake(['*' => Http::response(['data' => $this->invoiceData(['provisioning_status' => 'queued'])], 200)]);

        $invoice = Solosmartpay::waitUntilReady(Invoice::fromArray($this->invoiceData(['provisioning_status' => 'queued'])), timeout: 0, interval: 0.01);

        $this->assertTrue($invoice->isProvisioning());
    }

    public function test_config_can_make_creation_wait_for_readiness(): void
    {
        config(['solosmartpay.wait_until_ready' => true]);
        $this->rebuildClient();
        Http::fake([
            'api.test/v1/qris' => Http::response(['data' => $this->invoiceData(['provisioning_status' => 'queued'])], 202),
            'api.test/v1/qris/*' => Http::response(['data' => $this->invoiceData(['provisioning_status' => 'ready'])]),
        ]);

        $this->assertTrue(Solosmartpay::createQris(50000, 'ORDER-123')->isReady());
    }

    public function test_missing_or_malformed_token_gives_a_helpful_error_without_calling_the_api(): void
    {
        Http::fake();

        config(['solosmartpay.token' => null]);
        $this->rebuildClient();
        try { Solosmartpay::methods(); $this->fail(); } catch (SolosmartpayException $e) { $this->assertStringContainsString('SOLOSMARTPAY_TOKEN', $e->getMessage()); }

        config(['solosmartpay.token' => 'not-a-token']);
        $this->rebuildClient();
        try { Solosmartpay::methods(); $this->fail(); } catch (SolosmartpayException $e) { $this->assertStringContainsString('ssp_', $e->getMessage()); }

        Http::assertNothingSent();
    }

    public function test_fake_stubs_the_api_for_the_users_own_tests(): void
    {
        config(['solosmartpay.token' => null]);
        Solosmartpay::fake();

        $qris = Solosmartpay::createQris(50000, 'ORDER-9');
        $payment = Solosmartpay::createPayment(150000, 'BC', 'ORDER-10');

        $this->assertTrue($qris->isReady() && $qris->isQris());
        $this->assertSame(50123, $qris->totalAmount);
        $this->assertSame('ORDER-9', $qris->reference);
        $this->assertFalse($payment->isQris());
        $this->assertSame('BCA Virtual Account', Solosmartpay::methods()->first()->name);

        Solosmartpay::fake(['status' => 'paid']);
        $this->assertTrue(Solosmartpay::findQris('whatever')->isPaid());
    }

    public function test_sandbox_tokens_are_detected_and_can_simulate(): void
    {
        config(['solosmartpay.token' => 'ssp_test_abc']);
        $this->rebuildClient();
        Http::fake([
            'api.test/v1/qris' => Http::response(['data' => $this->invoiceData(['sandbox' => true, 'va_number' => '880812345678901'])], 202),
            'api.test/v1/sandbox/payments/*/simulate' => Http::response(['data' => $this->invoiceData(['sandbox' => true, 'status' => 'paid'])]),
        ]);

        $this->assertTrue(Solosmartpay::isSandbox());
        $invoice = Solosmartpay::createQris(50000, 'ORDER-9');
        $this->assertTrue($invoice->isSandbox());
        $this->assertSame('880812345678901', $invoice->vaNumber);

        $paid = Solosmartpay::simulate($invoice, 'paid');
        $this->assertTrue($paid->isPaid() && $paid->isSandbox());
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sandbox/payments/'.$invoice->id.'/simulate') && $r->data() === ['result' => 'paid']);
    }

    public function test_live_tokens_cannot_simulate_and_results_are_checked(): void
    {
        config(['solosmartpay.token' => 'ssp_live_style_token']);
        $this->rebuildClient();
        Http::fake();

        $this->assertFalse(Solosmartpay::isSandbox());
        $this->expectException(SolosmartpayException::class);
        try {
            Solosmartpay::simulate('abc', 'paid');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_simulate_rejects_unknown_results(): void
    {
        config(['solosmartpay.token' => 'ssp_test_abc']);
        $this->rebuildClient();

        $this->expectException(\InvalidArgumentException::class);
        Solosmartpay::simulate('abc', 'maybe');
    }
}
