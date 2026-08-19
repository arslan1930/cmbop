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
        if ($snap['secret_looks_like_webhook']) {
            $this->warn('  PAYPAL_SECRET starts with WH- — that is the webhook ID, not the REST Secret.');
        }
        if ($snap['credentials_look_truncated']) {
            $this->warn('  Client ID/Secret is shorter than a typical PayPal REST key (~80 chars). Wrap values in single quotes in .env.');
        }
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
            if ($e->getMessage() === UserMessages::get('payment.paypal_auth_live_keys')) {
                $this->line('  Set PAYPAL_MODE=live in .env (Live tab keys on developer.paypal.com).');
            } elseif ($e->getMessage() === UserMessages::get('payment.paypal_auth_sandbox_keys')) {
                $this->line('  Set PAYPAL_MODE=sandbox in .env (Sandbox tab keys on developer.paypal.com).');
            } elseif ($e->getMessage() === UserMessages::get('payment.paypal_auth_webhook_secret')) {
                $this->line('  Developer Dashboard → App → Sandbox → Secret (Show), not Webhooks → ID.');
            } elseif ($e->getMessage() === UserMessages::get('payment.paypal_auth')) {
                $this->line('  Developer Dashboard → Apps, Sandbox toggle ON, open the app, Show Secret.');
                $this->line('  In .env wrap the secret in single quotes: PAYPAL_SECRET=\'...\'');
                $this->line('  Then: php artisan config:clear');
            }

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('OAuth: ok');

        return self::SUCCESS;
    }
}
