<?php

namespace SolosmartDigital\Solosmartpay;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use SolosmartDigital\Solosmartpay\Console\CheckCommand;
use SolosmartDigital\Solosmartpay\Console\InstallCommand;

class SolosmartpayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/solosmartpay.php', 'solosmartpay');

        // Resolved lazily from config on every first use, so config changes at runtime (and tests) are honoured.
        $this->app->singleton(Solosmartpay::class, fn ($app) => new Solosmartpay($app['config']->get('solosmartpay', [])));
        $this->app->alias(Solosmartpay::class, 'solosmartpay');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/solosmartpay.php' => config_path('solosmartpay.php')], 'solosmartpay-config');
            $this->publishes([__DIR__.'/../resources/views/components' => resource_path('views/vendor/solosmartpay/components')], 'solosmartpay-views');
            $this->commands([InstallCommand::class, CheckCommand::class]);
        }

        Blade::anonymousComponentPath(__DIR__.'/../resources/views/components', 'solosmartpay');

        if (config('solosmartpay.webhook.enabled', true) && ! $this->app->routesAreCached()) {
            Route::group([], __DIR__.'/../routes/webhook.php');
        }
    }
}
