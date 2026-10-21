<?php

namespace SolosmartDigital\Solosmartpay\Tests;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;
use SolosmartDigital\Solosmartpay\Facades\Solosmartpay;
use SolosmartDigital\Solosmartpay\SolosmartpayServiceProvider;

abstract class TestCase extends Orchestra
{
    protected const SECRET = 'whsec_test_secret';

    protected function getPackageProviders($app): array
    {
        return [SolosmartpayServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['Solosmartpay' => Solosmartpay::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('solosmartpay.token', 'ssp_test_token');
        $app['config']->set('solosmartpay.base_url', 'https://api.test/v1');
        $app['config']->set('solosmartpay.webhook.secret', self::SECRET);
        $app['config']->set('solosmartpay.wait_until_ready', false);
        $app['config']->set('solosmartpay.retry_delay_ms', 1);
        $app['config']->set('cache.default', 'array');
    }

    /** Re-resolve the client after changing config in a test. */
    protected function rebuildClient(): void
    {
        Facade::clearResolvedInstances();
        $this->app->forgetInstance(\SolosmartDigital\Solosmartpay\Solosmartpay::class);
    }

    /** Http::fake() keeps the first matching stub, so start from a clean factory when a test fakes twice. */
    protected function freshHttp(): void
    {
        Http::swap(new Factory);
    }

    /** @return array<string, mixed> */
    protected function invoiceData(array $overrides = []): array
    {
        return array_merge([
            'id' => '3f2b8c1e-aaaa-bbbb-cccc-1234567890ab', 'reference' => 'ORDER-123', 'status' => 'pending', 'channel' => 'qris',
            'method' => 'qris', 'amount' => 50000, 'unique_code' => 127, 'total_amount' => 50127, 'fee' => 300, 'net_amount' => 49827,
            'provisioning_status' => 'ready', 'error_code' => null, 'expires_at' => '2026-10-08T12:15:00+07:00', 'paid_at' => null,
            'created_at' => '2026-10-08T12:00:00+07:00', 'checkout_url' => 'https://api.test/pay/3f2b', 'basic_url' => 'https://api.test/pay/3f2b?sig=x',
            'embed_url' => 'https://api.test/pay/3f2b/embed', 'payment_url' => null, 'qris_string' => '000201...', 'qris_image' => 'data:image/png;base64,AAA',
            'redirect_url' => null,
        ], $overrides);
    }
}
