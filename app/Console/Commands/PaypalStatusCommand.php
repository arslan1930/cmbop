<?php

namespace App\Console\Commands;

use App\Services\PaypalCheckoutService;
use App\Support\UserMessages;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Probe PayPal OAuth without printing secrets. 401 is almost always
 * sandbox keys against live (or the reverse).
 */
class PaypalStatusCommand extends Command
{
    protected $signature = 'paypal:status';

    protected $description = 'Show PayPal mode/host and whether Client ID + Secret authenticate';

    public function handle(PaypalCheckoutService $paypal): int
    {
        $snap = $paypal->connectionSnapshot();

        $this->newLine();
        $this->line(sprintf('  Mode        %s', $snap['mode']));
        $this->line(sprintf('  Host        %s', $snap['host']));
        $this->line(sprintf(
            '  Client ID   %s',
            $snap['client_id_set'] ? $snap['client_id_hint'] : 'missing'
        ));
        $this->line(sprintf(
            '  Secret      %s',
            $snap['secret_set'] ? 'set ('.$snap['secret_length'].' chars)' : 'missing'
        ));
        $this->line(sprintf(
            '  Webhook ID  %s',
            $snap['webhook_id_set'] ? 'set' : 'missing (webhooks will 503)'
        ));
        $this->line(sprintf(
            '  Config cache %s',
            file_exists($this->laravel->getCachedConfigPath())
                ? 'PRESENT — .env edits do nothing until php artisan config:clear'
                : 'none'
        ));

        if (! $snap['configured']) {
            $this->newLine();
            $this->error('PayPal is not configured. Set PAYPAL_CLIENT_ID and PAYPAL_SECRET, then config:clear.');

            return self::FAILURE;
        }

        try {
            $paypal->accessToken();
        } catch (RuntimeException $e) {
            $this->newLine();
            $this->error($e->getMessage());
            if ($e->getMessage() === UserMessages::get('payment.paypal_auth')) {
                $this->line('  Sandbox keys only work with PAYPAL_MODE=sandbox (developer.paypal.com Sandbox tab).');
                $this->line('  Live keys only work with PAYPAL_MODE=live (Live tab).');
                $this->line('  Copy Client ID + Secret from the same app; do not paste the webhook ID as the secret.');
            }

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('OAuth: ok');

        return self::SUCCESS;
    }
}
