<?php

namespace App\Support;

use App\Models\DepositRequest;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

/**
 * Viewer-selected display currency (EUR / GBP / USD). Overrides geo.
 * Session + cookie for guests; users.display_currency when signed in.
 * Saved user preference always wins over IP. Account country, EUR ledger
 * history, then geo fill a null preference and we persist that first choice.
 */
class DisplayCurrencyPreference
{
    public const SESSION_KEY = 'display_currency';

    public const COOKIE_KEY = 'slb_display_currency';

    public const PAY_FROM_KEY = 'deposit_pay_from';

    /**
     * @return 'EUR'|'GBP'|'USD'|null
     */
    public function preferred(?Request $request = null): ?string
    {
        $request ??= request();
        $user = Auth::user();

        if ($user instanceof User && $this->usersHaveDisplayCurrencyColumn()) {
            $fromUser = $this->normalize((string) ($user->display_currency ?? ''));
            if ($fromUser !== null) {
                return $fromUser;
            }
        }

        $explicit = $this->explicitGuestChoice($request);
        if ($user instanceof User && $this->usersHaveDisplayCurrencyColumn()) {
            if ($explicit !== null) {
                $this->persistResolved($user, $explicit, $request);

                return $explicit;
            }
            $fromAccount = $this->accountCountryCurrency($user);
            if ($fromAccount !== null) {
                $this->persistResolved($user, $fromAccount, $request);

                return $fromAccount;
            }
            if ($this->hasEuroLedgerHistory($user)) {
                $this->persistResolved($user, 'EUR', $request);

                return 'EUR';
            }

            return null;
        }

        return $explicit;
    }

    /**
     * Persist a first-choice default (header switch, inferred history, country, or geo).
     */
    public function persistResolved(User $user, string $currency, ?Request $request = null): string
    {
        $code = $this->normalize($currency) ?? 'EUR';
        if (! $this->usersHaveDisplayCurrencyColumn()) {
            return $code;
        }
        $existing = $this->normalize((string) ($user->display_currency ?? ''));
        if ($existing !== null) {
            return $existing;
        }

        $user->display_currency = $code;
        $user->saveQuietly();

        $request ??= request();
        if ($request instanceof Request && $request->hasSession()) {
            $request->session()->put(self::SESSION_KEY, $code);
        }

        return $code;
    }

    public function remember(string $currency, ?Request $request = null): string
    {
        $code = $this->normalize($currency) ?? 'EUR';
        $request ??= request();

        if ($request instanceof Request) {
            if ($request->hasSession()) {
                $request->session()->put(self::SESSION_KEY, $code);
            }
            cookie()->queue(cookie(self::COOKIE_KEY, $code, 60 * 24 * 365));
        }

        $user = Auth::user();
        if ($user instanceof User && $this->usersHaveDisplayCurrencyColumn()) {
            $user->display_currency = $code;
            $user->save();
        }

        return $code;
    }

    /**
     * @return 'gbp'|'eur'
     */
    public function payFrom(?Request $request = null): string
    {
        $request ??= request();
        if ($request instanceof Request) {
            $fromRequest = $this->normalizePayFrom((string) $request->input('pay_from', ''));
            if ($fromRequest !== null) {
                return $fromRequest;
            }
            if ($request->hasSession()) {
                $fromSession = $this->normalizePayFrom((string) $request->session()->get(self::PAY_FROM_KEY, ''));
                if ($fromSession !== null) {
                    return $fromSession;
                }
            }
        }

        return app(ViewerCountry::class)->displayCurrency($request) === 'GBP' ? 'gbp' : 'eur';
    }

    public function rememberPayFrom(string $payFrom, ?Request $request = null): string
    {
        $value = $this->normalizePayFrom($payFrom) ?? 'eur';
        $request ??= request();
        if ($request instanceof Request && $request->hasSession()) {
            $request->session()->put(self::PAY_FROM_KEY, $value);
        }

        return $value;
    }

    /**
     * @return 'EUR'|'GBP'|'USD'|null
     */
    public function normalize(string $raw): ?string
    {
        return match (strtoupper(trim($raw))) {
            'EUR', 'EURO', 'EUROS' => 'EUR',
            'GBP', 'POUND', 'POUNDS' => 'GBP',
            'USD', 'DOLLAR', 'DOLLARS' => 'USD',
            default => null,
        };
    }

    /**
     * @return 'gbp'|'eur'|null
     */
    public function normalizePayFrom(string $raw): ?string
    {
        return match (strtolower(trim($raw))) {
            'gbp', 'uk', 'uk_bank', 'pounds' => 'gbp',
            'eur', 'other', 'sepa', 'iban' => 'eur',
            default => null,
        };
    }

    /**
     * @return 'EUR'|'GBP'|'USD'|null
     */
    public function accountCountryCurrency(?User $user): ?string
    {
        if (! $user) {
            return null;
        }
        $country = strtoupper(trim((string) ($user->country ?? '')));
        if ($country === 'UK' || $country === 'UNITED KINGDOM') {
            $country = 'GB';
        }
        if (in_array($country, ['USA', 'UNITED STATES'], true)) {
            $country = 'US';
        }
        $map = config('fx.country_currency', []);
        if ($country === '') {
            return null;
        }
        $mapped = is_array($map) ? strtoupper((string) ($map[$country] ?? '')) : '';
        if (in_array($mapped, ['EUR', 'GBP', 'USD'], true)) {
            return $mapped;
        }

        return 'EUR';
    }

    public function hasEuroLedgerHistory(?User $user): bool
    {
        if (! $user) {
            return false;
        }
        try {
            if (DepositRequest::query()->where('user_id', $user->id)->exists()) {
                return true;
            }
        } catch (\Throwable) {
        }
        try {
            if (WalletTransaction::query()->where('user_id', $user->id)->exists()) {
                return true;
            }
        } catch (\Throwable) {
        }

        return false;
    }

    /**
     * @return 'EUR'|'GBP'|'USD'|null
     */
    private function explicitGuestChoice(?Request $request): ?string
    {
        if (! $request instanceof Request) {
            return null;
        }
        if ($request->hasSession()) {
            $fromSession = $this->normalize((string) $request->session()->get(self::SESSION_KEY, ''));
            if ($fromSession !== null) {
                return $fromSession;
            }
        }
        $fromCookie = $this->normalize((string) $request->cookie(self::COOKIE_KEY, ''));
        if ($fromCookie !== null) {
            return $fromCookie;
        }

        return null;
    }

    private function usersHaveDisplayCurrencyColumn(): bool
    {
        try {
            return Schema::hasColumn('users', 'display_currency');
        } catch (\Throwable) {
            return false;
        }
    }
}
