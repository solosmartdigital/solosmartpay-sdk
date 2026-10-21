<?php

namespace SolosmartDigital\Solosmartpay\Console;

use Illuminate\Console\Command;
use SolosmartDigital\Solosmartpay\Exceptions\SolosmartpayException;
use SolosmartDigital\Solosmartpay\Solosmartpay;

class CheckCommand extends Command
{
    protected $signature = 'solosmartpay:check';

    protected $description = 'Verify the token, the API connection and the webhook configuration';

    public function handle(Solosmartpay $ssp): int
    {
        $ok = true;
        $this->line('API: '.config('solosmartpay.base_url'));
        $this->line('Mode: '.($ssp->isSandbox() ? 'SANDBOX (uji coba, tanpa uang sungguhan)' : 'Live'));

        try {
            $count = $ssp->ping();
            $this->components->info("Token valid. {$count} metode bank/e-wallet aktif untuk toko Anda.");
        } catch (SolosmartpayException $e) {
            $ok = false;
            $this->components->error($e->isUnauthorized() ? 'Token ditolak (401/403). Periksa SOLOSMARTPAY_TOKEN dan scope-nya.' : $e->getMessage());
        }

        if (config('solosmartpay.webhook.enabled')) {
            if (blank(config('solosmartpay.webhook.secret'))) {
                $ok = false;
                $this->components->warn('SOLOSMARTPAY_WEBHOOK_SECRET kosong: webhook akan ditolak sampai diisi.');
            } else {
                $this->components->info('Webhook secret terisi. URL: '.rtrim((string) config('app.url'), '/').'/'.ltrim((string) config('solosmartpay.webhook.path'), '/'));
            }
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
