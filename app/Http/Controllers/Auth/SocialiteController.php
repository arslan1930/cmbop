<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Models\UserConsent;
use App\Models\Wallet;
use App\Services\Auth\StaffTwoFactorService;
use App\Services\EmailNotificationService;
use App\Services\Wallet\WalletLedgerService;
use App\Services\Wallet\WelcomeBonusService;
use App\Support\UserMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;

class SocialiteController extends Controller
{
    public function redirectToGoogle(): RedirectResponse
    {
        if (! google_oauth_configured()) {
            Log::warning('Google OAuth redirect blocked: credentials not configured', [
                'callback' => rtrim(request()->getSchemeAndHttpHost(), '/').'/auth/google/callback',
            ]);

            return $this->loginRedirect(UserMessages::get('oauth.unavailable'));
        }

        try {
            return $this->googleDriver()->redirect();
        } catch (\Throwable $e) {
            Log::error('Google OAuth redirect failed: '.$e->getMessage(), [
                'exception' => $e::class,
            ]);

            return $this->loginRedirect(UserMessages::get('oauth.temporary'));
        }
    }

    public function handleGoogleCallback(): RedirectResponse
    {
        if ($error = request('error')) {
            $denied = $error === 'access_denied';
            Log::info('Google OAuth callback returned error', [
                'error' => $error,
                'description' => request('error_description'),
            ]);

            return $this->loginRedirect(
                $denied
                    ? UserMessages::get('oauth.cancelled')
                    : UserMessages::get('oauth.failed')
            );
        }

        if (! google_oauth_configured()) {
            return $this->loginRedirect(UserMessages::get('oauth.unavailable'));
        }

        try {
            $socialUser = $this->resolveGoogleUser();
            $providerId = $socialUser->getId();
            $email = $socialUser->getEmail();
            $name = $socialUser->getName() ?: ($email ? Str::before($email, '@') : 'Google User');

            $existingUser = null;
            if ($email) {
                $existingUser = User::where('email', $email)->first();
            }
            if (! $existingUser && $providerId) {
                $existingUser = User::where('google_id', $providerId)->first();
            }

            if ($existingUser) {
                $existingUser->google_id = $providerId;
                $existingUser->google_token = $socialUser->token ?? null;
                $existingUser->google_refresh_token = $socialUser->refreshToken ?? null;
                if ($socialUser->getAvatar()) {
                    $existingUser->avatar = $this->normalizedAvatarUrl($socialUser->getAvatar());
                }
                if (! $existingUser->email_verified_at) {
                    $existingUser->email_verified_at = now();
                }
                $existingUser->save();

                return $this->loginAndRedirect($existingUser);
            }

            if (! $email) {
                return $this->loginRedirect(UserMessages::get('oauth.no_email'));
            }

            $request = request();
            $bonusService = app(WelcomeBonusService::class);
            $registerKey = $bonusService->registerRateLimitKey($request);
            if (RateLimiter::tooManyAttempts($registerKey, 5)) {
                return $this->loginRedirect(
                    UserMessages::get('register.throttled')
                );
            }
            RateLimiter::hit($registerKey, 600);

            DB::beginTransaction();

            $advertiserRole = Role::where('name', 'advertiser')->first();
            $publisherRole = Role::where('name', 'publisher')->first();

            if (! $advertiserRole || ! $publisherRole) {
                throw new \RuntimeException('Roles not found. Please run database seeders.');
            }

            // Letters + numbers only — symbols in temp passwords get mangled when
            // copied from email clients, so login with the emailed value fails even
            // though Hash::check against the pristine string would pass. The User
            // model hashed cast hashes once on assign; do not Hash::make here.
            $temporaryPassword = Str::password(14, letters: true, numbers: true, symbols: false);

            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => $temporaryPassword,
                'google_id' => $providerId,
                'avatar' => $this->normalizedAvatarUrl($socialUser->getAvatar()),
            ]);

            // Not mass-assignable: tokens, role, and verify must be set explicitly.
            $user->google_token = $socialUser->token ?? null;
            $user->google_refresh_token = $socialUser->refreshToken ?? null;
            $user->active_role_id = $advertiserRole->id;
            $user->email_verified_at = now();
            $user->save();

            $user->roles()->sync([$advertiserRole->id, $publisherRole->id]);

            $welcomeBonus = $bonusService->amountFor($request, 'advertiser');
            if ($welcomeBonus > 0 && ! $bonusService->recordClaim($user, $request, $welcomeBonus, 'socialite')) {
                $welcomeBonus = 0.0;
            }

            $welcomeBonus = Wallet::insertRegistrationPair(
                $user->id,
                $advertiserRole->id,
                $publisherRole->id,
                $welcomeBonus
            );

            if (Schema::hasTable('user_consents')) {
                UserConsent::create([
                    'user_id' => $user->id,
                    'terms_accepted' => true,
                    'marketing_consent' => false,
                    'newsletter_consent' => false,
                    'consented_at' => now(),
                    'ip_address' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                ]);
            }

            DB::commit();

            if ($welcomeBonus > 0) {
                $bonusService->queueClaimCookie();
            }

            try {
                $advertiserWallet = Wallet::where('user_id', $user->id)
                    ->where('role_id', $advertiserRole->id)
                    ->first();
                if ($advertiserWallet && $welcomeBonus > 0) {
                    app(WalletLedgerService::class)->recordBonusCredit(
                        $advertiserWallet,
                        (float) $welcomeBonus,
                        'Welcome promotional bonus',
                        ['source' => 'socialite']
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('Welcome bonus ledger write failed during Google signup', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }

            try {
                app(EmailNotificationService::class)->sendGoogleTempPassword($user, $temporaryPassword);
            } catch (\Throwable $e) {
                Log::warning('Google temporary password email failed during signup', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return $this->loginAndRedirect($user);
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            Log::error('Google authentication failed: '.($e->getMessage() !== '' ? $e->getMessage() : $e::class), [
                'exception' => $e::class,
            ]);

            return redirect()->to(route('login', absolute: false))
                ->with('error', UserMessages::get('oauth.failed'));
        }
    }

    /**
     * Resolve the Google user. Local/testing may retry without the OAuth
     * "state" cookie (lost on localhost / SameSite). Production does not —
     * that fallback lets a stolen callback URL finish login as anyone.
     */
    private function resolveGoogleUser(): SocialiteUser
    {
        try {
            return $this->googleDriver()->user();
        } catch (InvalidStateException $e) {
            if (! $this->allowStatelessGoogle()) {
                Log::warning('Google OAuth state mismatch; refusing stateless fallback', [
                    'exception' => $e::class,
                ]);

                throw $e;
            }

            Log::warning('Google OAuth state mismatch; retrying stateless user resolve', [
                'exception' => $e::class,
            ]);

            return $this->googleDriver()->stateless()->user();
        }
    }

    private function allowStatelessGoogle(): bool
    {
        if (filter_var(config('services.google.oauth_allow_stateless', false), FILTER_VALIDATE_BOOL)) {
            return true;
        }

        return ! app()->environment('production');
    }

    /**
     * Socialite driver bound to the browser's current host so Google returns
     * users here — not to a misconfigured APP_URL / localhost callback.
     */
    private function googleDriver(): Provider
    {
        $this->alignRootUrlWithRequestHost();

        return Socialite::driver('google')
            ->scopes(['openid', 'profile', 'email'])
            ->redirectUrl($this->googleRedirectUri());
    }

    /**
     * Always use the browser origin for redirect_uri.
     * Comparing only the host (old behavior) kept http:// callbacks when the
     * user was on https:// — Google then returns redirect_uri_mismatch.
     */
    private function googleRedirectUri(): string
    {
        return rtrim(request()->getSchemeAndHttpHost(), '/').'/auth/google/callback';
    }

    private function alignRootUrlWithRequestHost(): void
    {
        $requestHost = strtolower((string) request()->getHost());
        if ($requestHost === '') {
            return;
        }

        $appHost = strtolower((string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: ''));
        if ($appHost !== '' && $appHost === $requestHost) {
            // Still force scheme when APP_URL is http but the browser is https.
            if (request()->isSecure() && ! str_starts_with((string) config('app.url'), 'https://')) {
                URL::forceRootUrl(request()->getSchemeAndHttpHost());
                URL::forceScheme('https');
            }

            return;
        }

        // Whenever the browser host differs from APP_URL (localhost misconfig,
        // wrong domain, etc.), bind generated OAuth URLs to the request host.
        URL::forceRootUrl(request()->getSchemeAndHttpHost());
        if (request()->isSecure()) {
            URL::forceScheme('https');
        }
    }

    /**
     * Persist only a safe Google avatar URL. Never fail login on oversized/invalid URLs.
     */
    private function normalizedAvatarUrl(mixed $avatar): ?string
    {
        $url = is_string($avatar) ? trim($avatar) : '';
        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        // Soft cap for older VARCHAR(255) deploys before migration runs.
        if (strlen($url) > 2000) {
            return null;
        }

        return $url;
    }

    private function loginRedirect(string $message): RedirectResponse
    {
        return redirect()->to(route('login', absolute: false))->with('error', $message);
    }

    private function isLoopbackHost(string $host): bool
    {
        $host = strtolower($host);

        return in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || str_ends_with($host, '.localhost');
    }

    private function loginAndRedirect(User $user): RedirectResponse
    {
        if ($user->isSuspended()) {
            return $this->loginRedirect(UserMessages::get('login.suspended'));
        }

        $twoFactor = app(StaffTwoFactorService::class);
        if ($twoFactor->mustChallenge($user)) {
            $twoFactor->beginPending(request(), $user, true);

            return redirect()->route('login.two-factor');
        }

        Auth::login($user, true);
        request()->session()->regenerate();
        $twoFactor->markSessionPassed(request(), $user);

        $user->load('activeRoleRelation', 'roles');

        $destination = $this->postLoginDestination($user);

        // Keep Location host-relative when possible so a bad APP_URL cannot
        // send the browser to http://127.0.0.1 after a successful Google login.
        if (str_starts_with($destination, '/')) {
            return new RedirectResponse($destination);
        }

        return redirect()->to($destination);
    }

    /**
     * Prefer a safe intended URL; never bounce back to login/register/OAuth
     * or to a loopback host when the user is browsing elsewhere.
     */
    private function postLoginDestination(User $user): string
    {
        $dashboard = $user->getDashboardRoute();
        $intended = session()->pull('url.intended');

        if (! is_string($intended) || $intended === '') {
            return $dashboard;
        }

        $path = parse_url($intended, PHP_URL_PATH) ?: $intended;
        $blocked = ['/login', '/register', '/auth/google', '/forgot-password', '/reset-password', '/email/verify'];

        foreach ($blocked as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return $dashboard;
            }
        }

        $intendedHost = strtolower((string) (parse_url($intended, PHP_URL_HOST) ?: ''));
        if ($intendedHost !== '' && $this->isLoopbackHost($intendedHost) && ! $this->isLoopbackHost((string) request()->getHost())) {
            return $path !== '' ? $path : $dashboard;
        }

        // Prefer path-only so redirects stay on the current host.
        if (str_starts_with((string) $path, '/')) {
            return $path;
        }

        return $intended;
    }
}
