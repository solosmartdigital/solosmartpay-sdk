<?php

namespace SolosmartDigital\Solosmartpay\Console;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'solosmartpay:install {--force : Overwrite the published config}';

    protected $description = 'Publish the Solosmartpay config and show the remaining setup steps';

    public function handle(): int
    {
        $this->call('vendor:publish', ['--tag' => 'solosmartpay-config', '--force' => (bool) $this->option('force')]);

        $url = rtrim((string) config('app.url'), '/').'/'.ltrim((string) config('solosmartpay.webhook.path'), '/');

        $this->newLine();
        $this->info('Tinggal dua langkah lagi:');
        $this->line('  1. Tambahkan ke .env (token dari dashboard: Developer > API Tokens):');
        $this->line('       SOLOSMARTPAY_TOKEN=ssp_...');
        $this->line('       SOLOSMARTPAY_WEBHOOK_SECRET=...');
        $this->line('  2. Di dashboard (Developer > API & Webhook) isi URL webhook:');
        $this->line("       {$url}");
        $this->newLine();
        $this->line('Cek koneksi: <comment>php artisan solosmartpay:check</comment>');

        return self::SUCCESS;
    }
}
