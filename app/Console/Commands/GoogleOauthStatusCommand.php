<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GoogleOauthStatusCommand extends Command
{
    protected $signature = 'google:oauth-status';

    protected $description = 'Diagnose Google OAuth configuration (does not print secrets)';

    public function handle(): int
    {
        $credentials = google_oauth_credentials();
        $id = $credentials['client_id'];
        $secret = $credentials['client_secret'];
        $configured = google_oauth_configured();
        $redirect = rtrim((string) config('services.google.redirect'), '/')
            ?: rtrim((string) config('app.url'), '/').'/auth/google/callback';

        $this->info('Google OAuth status');
        $this->line('  configured: '.($configured ? 'YES' : 'NO'));
        $this->line('  client_id: '.($id === '' ? 'MISSING' : 'set (len '.strlen($id).')'));
        $this->line('  client_secret: '.($secret === '' ? 'MISSING' : 'set (len '.strlen($secret).')'));
        $this->line('  APP_URL: '.config('app.url'));
        $this->line('  configured redirect: '.$redirect);
        $this->line('  runtime callback (example): '.rtrim((string) config('app.url'), '/').'/auth/google/callback');
        $this->newLine();

        if (! $configured) {
            $this->error('Google login will NOT work until real Client ID + Secret are set.');
            $this->line('1. Google Cloud Console → APIs & Services → Credentials → OAuth 2.0 Client ID (Web)');
            $this->line('2. Authorized redirect URIs must include every host you use, e.g.');
            $this->line('   http://127.0.0.1:8000/auth/google/callback');
            $this->line('   https://seolinkbuildings.com/auth/google/callback');
            $this->line('3. Put values in .env:');
            $this->line('   GOOGLE_CLIENT_ID=...');
            $this->line('   GOOGLE_CLIENT_SECRET=...');
            $this->line('4. php artisan config:clear   (or config:cache after setting secrets)');

            return self::FAILURE;
        }

        $this->info('Credentials look present. If login still fails, check Google Console redirect URIs match the browser origin exactly (http vs https, www vs apex, port).');

        return self::SUCCESS;
    }
}
