<?php

namespace SolosmartDigital\Solosmartpay\Tests;

use Illuminate\Support\Facades\Event;
use SolosmartDigital\Solosmartpay\Data\Invoice;
use SolosmartDigital\Solosmartpay\Events\InvoiceCancelled;
use SolosmartDigital\Solosmartpay\Events\InvoiceExpired;
use SolosmartDigital\Solosmartpay\Events\InvoiceFailed;
use SolosmartDigital\Solosmartpay\Events\PaymentPaid;
use SolosmartDigital\Solosmartpay\Events\WebhookReceived;
use SolosmartDigital\Solosmartpay\Exceptions\InvalidWebhookException;
use SolosmartDigital\Solosmartpay\Webhook;

class WebhookTest extends TestCase
{
    private function body(string $type = 'payment.paid', string $id = 'evt_1', array $data = []): string
    {
        return json_encode(['id' => $id, 'type' => $type, 'created_at' => now()->toIso8601String(), 'data' => $this->invoiceData($data + ['status' => 'paid'])]);
    }

    /** @return array<string, string> */
    private function headers(string $body, ?int $timestamp = null, string $secret = self::SECRET): array
    {
        $timestamp ??= time();

        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SOLOSMARTPAY_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_SOLOSMARTPAY_SIGNATURE' => Webhook::sign($body, $secret, $timestamp),
        ];
    }

    private function deliver(string $body, ?array $headers = null)
    {
        return $this->call('POST', '/solosmartpay/webhook', [], [], [], $headers ?? $this->headers($body), $body);
    }

    public function test_a_valid_paid_webhook_fires_the_paid_event_with_a_typed_invoice(): void
    {
        Event::fake([PaymentPaid::class, WebhookReceived::class]);

        $this->deliver($this->body())->assertOk()->assertJson(['message' => 'ok']);

        Event::assertDispatched(PaymentPaid::class, function (PaymentPaid $e) {
            return $e->invoice instanceof Invoice && $e->invoice->isPaid() && $e->invoice->reference === 'ORDER-123'
                && $e->event['id'] === 'evt_1';
        });
        Event::assertDispatched(WebhookReceived::class, fn (WebhookReceived $e) => $e->type() === 'payment.paid');
    }

    public function test_each_event_type_maps_to_its_class(): void
    {
        Event::fake([InvoiceExpired::class, InvoiceFailed::class, InvoiceCancelled::class]);

        foreach (['invoice.expired', 'invoice.failed', 'invoice.cancelled'] as $i => $type) {
            $this->deliver($this->body($type, 'evt_'.$i))->assertOk();
        }

        Event::assertDispatched(InvoiceExpired::class);
        Event::assertDispatched(InvoiceFailed::class);
        Event::assertDispatched(InvoiceCancelled::class);
    }

    public function test_an_unknown_event_type_still_reaches_the_generic_event_and_is_acknowledged(): void
    {
        Event::fake([WebhookReceived::class, PaymentPaid::class]);

        $this->deliver($this->body('something.new'))->assertOk();

        Event::assertDispatched(WebhookReceived::class);
        Event::assertNotDispatched(PaymentPaid::class);
    }

    public function test_a_redelivered_event_does_not_fire_listeners_twice(): void
    {
        $count = 0;
        Event::listen(PaymentPaid::class, function () use (&$count) { $count++; });
        $body = $this->body();

        $this->deliver($body)->assertOk()->assertJson(['message' => 'ok']);
        $this->deliver($body)->assertOk()->assertJson(['message' => 'duplicate']);

        $this->assertSame(1, $count);
    }

    public function test_a_failing_listener_returns_an_error_and_the_retry_is_processed(): void
    {
        $attempts = 0;
        Event::listen(PaymentPaid::class, function () use (&$attempts) {
            if (++$attempts === 1) {
                throw new \RuntimeException('database down');
            }
        });
        $body = $this->body();

        $this->withoutExceptionHandling([]);
        $this->withExceptionHandling();
        $this->deliver($body)->assertStatus(500);
        $this->deliver($body)->assertOk()->assertJson(['message' => 'ok']);

        $this->assertSame(2, $attempts);
    }

    public function test_an_event_whose_processing_never_finished_is_not_lost_as_a_duplicate(): void
    {
        $body = $this->body();

        // Simulates a process killed mid-listener: only the short claim exists, so after it expires the retry runs.
        \Illuminate\Support\Facades\Cache::put('solosmartpay:webhook:evt_1', 1, 120);
        $this->deliver($body)->assertJson(['message' => 'duplicate']);

        \Illuminate\Support\Facades\Cache::forget('solosmartpay:webhook:evt_1');
        $this->deliver($body)->assertJson(['message' => 'ok']);
        $this->assertTrue(\Illuminate\Support\Facades\Cache::has('solosmartpay:webhook:evt_1'));
    }

    public function test_a_bad_signature_is_rejected_and_nothing_fires(): void
    {
        Event::fake([PaymentPaid::class, WebhookReceived::class]);
        $body = $this->body();

        $this->deliver($body, $this->headers($body, secret: 'someone-elses-secret'))->assertStatus(400);
        $this->deliver($body.' ', $this->headers($body))->assertStatus(400); // body tampered after signing
        $this->deliver($body, ['CONTENT_TYPE' => 'application/json'])->assertStatus(400); // no headers at all

        Event::assertNotDispatched(PaymentPaid::class);
        Event::assertNotDispatched(WebhookReceived::class);
    }

    public function test_an_old_timestamp_is_rejected_as_a_replay(): void
    {
        $body = $this->body();

        $this->deliver($body, $this->headers($body, time() - 3600))->assertStatus(400);
    }

    public function test_it_refuses_everything_when_no_secret_is_configured(): void
    {
        config(['solosmartpay.webhook.secret' => '']);
        $body = $this->body();

        $this->deliver($body, $this->headers($body, secret: ''))->assertStatus(400);
    }

    public function test_verify_helper_is_usable_standalone(): void
    {
        $body = $this->body();
        $ts = (string) time();

        $event = Webhook::verify($body, Webhook::sign($body, self::SECRET, $ts), $ts, self::SECRET);
        $this->assertSame('payment.paid', $event['type']);

        $this->expectException(InvalidWebhookException::class);
        Webhook::verify($body, 'sha256=nope', $ts, self::SECRET);
    }

    public function test_the_route_path_and_switch_are_configurable_by_the_app(): void
    {
        $this->assertSame(url('/solosmartpay/webhook'), route('solosmartpay.webhook'));
    }
}
