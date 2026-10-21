<?php

namespace SolosmartDigital\Solosmartpay\Tests;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use SolosmartDigital\Solosmartpay\Data\Invoice;

class ComponentAndCommandTest extends TestCase
{
    public function test_the_pay_button_renders_the_embed_wiring_and_loads_embed_js_once(): void
    {
        $invoice = Invoice::fromArray($this->invoiceData());

        $html = Blade::render('<x-solosmartpay::pay-button :invoice="$invoice" class="btn" lang="id" on-success="done">Bayar</x-solosmartpay::pay-button><x-solosmartpay::pay-button :invoice="$invoice" />', ['invoice' => $invoice]);

        $this->assertSame(1, substr_count($html, '<script src="https://api.test/embed.js"'));
        $this->assertSame(2, substr_count($html, '<button'));
        $this->assertStringContainsString('data-solosmartpay-embed="https://api.test/pay/3f2b/embed"', $html);
        $this->assertStringContainsString('data-solosmartpay-checkout="https://api.test/pay/3f2b"', $html);
        $this->assertStringContainsString('data-solosmartpay-lang="id"', $html);
        $this->assertStringContainsString('data-solosmartpay-on-success="done"', $html);
        $this->assertStringContainsString('class="btn"', $html);
        $this->assertStringContainsString('>Bayar</button>', $html);
        $this->assertStringContainsString('>Bayar sekarang</button>', $html);
    }

    public function test_the_pay_button_is_disabled_when_the_invoice_has_no_embed_url_yet(): void
    {
        $invoice = Invoice::fromArray($this->invoiceData(['embed_url' => null, 'checkout_url' => null, 'provisioning_status' => 'queued']));

        $html = Blade::render('<x-solosmartpay::pay-button :invoice="$invoice" />', ['invoice' => $invoice]);

        $this->assertStringContainsString('disabled', $html);
    }

    public function test_the_check_command_reports_a_valid_token(): void
    {
        Http::fake(['*' => Http::response(['data' => [['code' => 'BC', 'name' => 'BCA']]])]);

        $this->artisan('solosmartpay:check')->expectsOutputToContain('Token valid')->assertSuccessful();
    }

    public function test_the_check_command_fails_clearly_on_a_rejected_token(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

        $this->artisan('solosmartpay:check')->expectsOutputToContain('Token ditolak')->assertFailed();
    }

    public function test_the_install_command_prints_the_webhook_url(): void
    {
        config(['app.url' => 'https://shop.test']);

        $this->artisan('solosmartpay:install')->expectsOutputToContain('https://shop.test/solosmartpay/webhook')->assertSuccessful();
    }
}
