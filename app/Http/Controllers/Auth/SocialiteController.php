<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Models\UserConsent;
use App\Models\Wallet;
use App\Services\Wallet\WalletLedgerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        google_oauth_credentials();

        if (! google_oauth_configured()) {
            Log::warning('Google OAuth redirect blocked: credentials not configured', [
                'callback' => $this->googleRedirectUri(),
            ]);

            return $this->loginRedirect(
                'Google sign-in is not configured. Set real GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET in .env, add this exact redirect URI in Google Cloud Console: '
                .$this->googleRedirectUri()
                .', then run php artisan config:clear.'
            );
        }

        try {
            $callback = $this->googleRedirectUri();
            Log::info('Google OAuth redirect starting', [
                'redirect_uri' => $callback,
                'host' => request()->getHost(),
                'scheme' => request()->getScheme(),
                'secure' => request()->isSecure(),
            ]);

            return $this->googleDriver()->redirect();
        } catch (\Throwable $e) {
            Log::error('Google OAuth redirect failed: '.$e->getMessage(), [
                'exception' => $e::class,
                'redirect_uri' => $this->googleRedirectUri(),
            ]);

            return $this->loginRedirect(
                'Google sign-in is temporarily unavailable. Please try again or use email and password.'
            );
        }
    }

    public function handleGoogleCallback(): RedirectResponse
    {
        google_oauth_credentials();

        if ($error = request('error')) {
            $denied = $error === 'access_denied';
            Log::info('Google OAuth callback returned error', [
                'error' => $error,
                'description' => request('error_description'),
            ]);

            return $this->loginRedirect(
                $denied
                    ? 'Google sign-in was cancelled. You can try again or use email and password.'
                    : 'Google sign-in failed. Please try again or use email and password.'
            );
        }

        if (! google_oauth_configured()) {
            return $this->loginRedirect(
                'Google sign-in is not configured. Set real GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET in .env, then run php artisan config:clear.'
            );
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
                    $existingUser->avatar = $socialUser->getAvatar();
                }
                if (! $existingUser->email_verified_at) {
                    $existingUser->email_verified_at = now();
                }
                $existingUser->save();

                return $this->loginAndRedirect($existingUser);
            }

            if (! $email) {
                return $this->loginRedirect(
                    'Google did not share an email address. Please use another sign-in method.'
                );
            }

            DB::beginTransaction();

            $advertiserRole = Role::where('name', 'advertiser')->first();
            $publisherRole = Role::where('name', 'publisher')->first();

            if (! $advertiserRole || ! $publisherRole) {
                throw new \RuntimeException('Roles not found. Please run database seeders.');
            }

            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => bcrypt(Str::random(24)),
                'email_verified_at' => now(),
                'google_id' => $providerId,
                'google_token' => $socialUser->token ?? null,
                'google_refresh_token' => $socialUser->refreshToken ?? null,
                'avatar' => $socialUser->getAvatar(),
                'active_role_id' => $advertiserRole->id,
            ]);

            $user->roles()->sync([$advertiserRole->id, $publisherRole->id]);

            $welcomeBonus = 20.00;
            Wallet::insertRegistrationPair(
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

            return $this->loginAndRedirect($user);
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            Log::error('Google authentication failed: '.($e->getMessage() !== '' ? $e->getMessage() : $e::class), [
                'exception' => $e::class,
            ]);

            return redirect()->to(route('login', absolute: false))
                ->with('error', 'Google authentication failed. Please try again or use email and password.');
        }
    }

    /**
     * Resolve the Google user, falling back to stateless when the OAuth
     * session "state" cookie/session was lost (common on localhost / SameSite).
     */
    private function resolveGoogleUser(): SocialiteUser
    {
        try {
            return $this->googleDriver()->user();
        } catch (InvalidStateException $e) {
            Log::warning('Google OAuth state mismatch; retrying stateless user resolve', [
                'exception' => $e::class,
            ]);

            return $this->googleDriver()->stateless()->user();
        }
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
     * Build the Google OAuth redirect_uri for this request.
     *
     * Rules (Google requires an exact string match in Cloud Console):
     * 1) If GOOGLE_REDIRECT_URI host matches the browser host and is https
     *    (or local), use that exact URI.
     * 2) Otherwise build from the request; force https on non-local hosts
     *    (fixes http detection behind Cloudflare/nginx → redirect_uri_mismatch).
     */
    private function googleRedirectUri(): string
    {
        $configured = rtrim((string) config('services.google.redirect'), '/');
        $requestHost = strtolower((string) request()->getHost());
        $configuredHost = strtolower((string) (parse_url($configured, PHP_URL_HOST) ?: ''));
        $isLocal = $this->isLoopbackHost($requestHost);

        if (
            $configured !== ''
            && $configuredHost !== ''
            && $requestHost !== ''
            && $configuredHost === $requestHost
        ) {
            $configuredScheme = strtolower((string) (parse_url($configured, PHP_URL_SCHEME) ?: ''));
            if ($configuredScheme === 'https' || $isLocal) {
                return $configured;
            }
        }

        return $this->requestCallbackUri();
    }

    /**
     * Callback URI derived from the live browser origin.
     */
    private function requestCallbackUri(): string
    {
        $host = strtolower((string) request()->getHost());
        $isLocal = in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || str_ends_with($host, '.localhost');

        $scheme = request()->getScheme();
        if (request()->isSecure()) {
            $scheme = 'https';
        } elseif (! $isLocal) {
            // Production/staging almost always register https:// with Google.
            // Behind TLS-terminating proxies the app may still see http.
            $scheme = 'https';
        }

        $port = (int) request()->getPort();
        $defaultPort = $scheme === 'https' ? 443 : 80;
        $authority = $host;
        if ($isLocal && $port > 0 && $port !== $defaultPort) {
            $authority .= ':'.$port;
        }

        return $scheme.'://'.$authority.'/auth/google/callback';
    }

    private function alignRootUrlWithRequestHost(): void
    {
        $requestHost = strtolower((string) request()->getHost());
        if ($requestHost === '') {
            return;
        }

        $root = $this->requestCallbackUri();
        // Strip path — forceRootUrl wants origin only.
        $origin = preg_replace('#/auth/google/callback$#', '', $root) ?: request()->getSchemeAndHttpHost();

        URL::forceRootUrl($origin);
        if (str_starts_with($origin, 'https://')) {
            URL::forceScheme('https');
        }
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
        Auth::login($user, true);
        request()->session()->regenerate();

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
