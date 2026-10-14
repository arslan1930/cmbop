<?php

namespace App\Services\Wallet;

use App\Models\User;
use App\Support\WithdrawalPayoutConfig;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PayoutProfileService
{
    /**
     * @return array<string, mixed>
     */
    public function paymentDetailsFromProfile(User $user, string $method): array
    {
        $profile = $user->payoutProfile();

        return match ($method) {
            'bank' => [
                'bank_name' => $profile['bank_name'],
                'account_holder' => $profile['bank_holder_name'],
                'account_number' => $profile['bank_account'],
                'swift_code' => $profile['bank_swift'],
            ],
            'paypal' => [
                'email' => $profile['paypal_email'],
            ],
            'wise' => [
                'email' => $profile['wise_email'],
            ],
            'crypto' => [
                'crypto_type' => $profile['crypto_type'] ?: 'USDT',
                'wallet_address' => $profile['crypto_wallet'],
            ],
            default => [],
        };
    }

    public function profileHasMethod(User $user, string $method): bool
    {
        $details = $this->paymentDetailsFromProfile($user, $method);

        return match ($method) {
            'bank' => filled($details['bank_name'] ?? null)
                && filled($details['account_holder'] ?? null)
                && filled($details['account_number'] ?? null),
            'paypal', 'wise' => filled($details['email'] ?? null),
            'crypto' => filled($details['wallet_address'] ?? null),
            default => false,
        };
    }

    /**
     * Payout methods that already have saved destination details.
     *
     * @return list<string>
     */
    public function availableMethods(User $user, ?string $currency = null): array
    {
        $allowed = WithdrawalPayoutConfig::allowedMethods($currency);
        $methods = [];
        foreach ($allowed as $method) {
            if ($this->profileHasMethod($user, $method)) {
                $methods[] = $method;
            }
        }

        return $methods;
    }

    /**
     * Remember the method chosen at withdraw checkout (details unchanged).
     */
    public function setPreferredMethod(User $user, string $method): void
    {
        if (! in_array($method, ['bank', 'paypal', 'wise', 'crypto'], true)) {
            return;
        }

        if (! $this->profileHasMethod($user, $method)) {
            return;
        }

        if ($user->payout_preferred_method === $method) {
            return;
        }

        $user->forceFill(['payout_preferred_method' => $method])->save();
    }

    /**
     * Validate request details. When locked, values must match the saved profile.
     * When unlocked, confirmation fields are required.
     *
     * Locked profiles may switch among methods that already have saved details.
     *
     * @return array<string, mixed>
     */
    public function validatedPaymentDetails(Request $request, User $user, bool $requireConfirm = true): array
    {
        $request->validate([
            'payment_method' => 'required|in:bank,paypal,wise,crypto',
        ]);

        $method = (string) $request->payment_method;
        $locked = $user->payoutProfileLocked();

        if ($locked && $this->profileHasMethod($user, $method)) {
            // Locked destinations always come from the saved profile (user cannot edit).
            return $this->paymentDetailsFromProfile($user, $method);
        }

        if ($locked) {
            throw ValidationException::withMessages([
                'payment_method' => 'Your payout details are locked. Contact support to add or change a payment method.',
            ]);
        }

        return match ($method) {
            'bank' => $this->validateBank($request, $requireConfirm),
            'paypal' => $this->validatePaypal($request, $requireConfirm),
            'wise' => $this->validateWise($request, $requireConfirm),
            'crypto' => $this->validateCrypto($request, $requireConfirm),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public function persistAndLock(User $user, string $method, array $details): void
    {
        if ($user->payoutProfileLocked()) {
            return;
        }

        $updates = [
            'payout_preferred_method' => $method,
            'payout_profile_locked_at' => now(),
        ];

        if ($method === 'paypal' && ! empty($details['email'])) {
            $updates['payout_paypal_email'] = $details['email'];
        }

        if ($method === 'wise' && ! empty($details['email'])) {
            $updates['payout_wise_email'] = $details['email'];
        }

        if ($method === 'bank') {
            $updates['payout_bank_holder_name'] = $details['account_holder'] ?? null;
            $updates['payout_bank_name'] = $details['bank_name'] ?? null;
            $updates['payout_bank_account'] = $details['account_number'] ?? null;
            $updates['payout_bank_swift'] = $details['swift_code'] ?? null;
        }

        if ($method === 'crypto' && ! empty($details['wallet_address'])) {
            $updates['payout_crypto_trx_wallet'] = $details['wallet_address'];
            $updates['payout_crypto_type'] = $details['crypto_type'] ?? null;
            $updates['payout_crypto_trx_verified_at'] = now();
        }

        $user->forceFill($updates)->save();
    }

    /**
     * Admin/support override — replaces locked payout details and keeps the profile locked.
     *
     * @param  array<string, mixed>  $input
     * @return array{before: array<string, mixed>, after: array<string, mixed>}
     */
    public function adminUpdateProfile(User $user, string $method, array $input): array
    {
        $before = $user->payoutProfile();

        // Leftover lock stamps cast to null; write a real timestamp so later
        // reads match payoutProfileLocked() and Eloquent dirty-diff can save.
        $updates = [
            'payout_preferred_method' => $method,
            'payout_profile_locked_at' => $user->payout_profile_locked_at ?? now(),
        ];

        if ($method === 'paypal') {
            $updates['payout_paypal_email'] = $input['paypal_email'] ?? $input['email'] ?? null;
        } elseif ($method === 'wise') {
            $updates['payout_wise_email'] = $input['wise_email'] ?? $input['email'] ?? null;
        } elseif ($method === 'bank') {
            $updates['payout_bank_name'] = $input['bank_name'] ?? null;
            $updates['payout_bank_holder_name'] = $input['account_holder'] ?? $input['bank_holder_name'] ?? null;
            $updates['payout_bank_account'] = $input['account_number'] ?? $input['bank_account'] ?? null;
            $updates['payout_bank_swift'] = $input['swift_code'] ?? $input['bank_swift'] ?? null;
        } elseif ($method === 'crypto') {
            $updates['payout_crypto_trx_wallet'] = $input['wallet_address'] ?? $input['crypto_wallet'] ?? null;
            $updates['payout_crypto_type'] = $input['crypto_type'] ?? null;
            $updates['payout_crypto_trx_verified_at'] = now();
        }

        $updates = User::existingAttributes($updates);
        if ($updates === []) {
            throw ValidationException::withMessages([
                'payment_method' => 'Payout details cannot be saved on this database.',
            ]);
        }

        $user->forceFill($updates)->save();

        return [
            'before' => $before,
            'after' => $user->fresh()->payoutProfile(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validateBank(Request $request, bool $requireConfirm): array
    {
        $rules = [
            'bank_name' => 'required|string|max:255',
            'account_holder' => 'required|string|max:255',
            'account_number' => 'required|string|max:255',
            'swift_code' => 'nullable|string|max:50',
            'details_confirmed' => 'accepted',
        ];
        if ($requireConfirm) {
            $rules['account_number_confirm'] = 'required|string|max:255|same:account_number';
        }

        $request->validate($rules, [
            'account_number_confirm.same' => 'IBAN / account numbers must match exactly (enter twice to verify).',
            'details_confirmed.accepted' => 'Please confirm you have double-checked your payout details.',
        ]);

        $account = strtoupper(preg_replace('/\s+/', '', (string) $request->account_number) ?: '');
        if (! $this->looksLikeValidIbanOrAccount($account)) {
            throw ValidationException::withMessages([
                'account_number' => 'Enter a valid IBAN (e.g. DE89…) or account number (15–34 letters/digits).',
            ]);
        }

        return [
            'bank_name' => $request->bank_name,
            'account_holder' => $request->account_holder,
            'account_number' => $account,
            'swift_code' => $request->swift_code,
        ];
    }

    /**
     * Accept IBAN (with MOD-97) or a conservative alphanumeric account number.
     */
    private function looksLikeValidIbanOrAccount(string $account): bool
    {
        if ($account === '' || strlen($account) < 8 || strlen($account) > 34) {
            return false;
        }

        if (! preg_match('/^[A-Z0-9]+$/', $account)) {
            return false;
        }

        // IBAN shape (country + check digits): always validate as IBAN.
        if (preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]+$/', $account)) {
            return strlen($account) >= 15 && strlen($account) <= 34 && $this->ibanMod97Valid($account);
        }

        // Non-IBAN domestic account: digits (optional letters), length 8–34
        return (bool) preg_match('/^[0-9A-Z]{8,34}$/', $account);
    }

    private function ibanMod97Valid(string $iban): bool
    {
        $rearranged = substr($iban, 4).substr($iban, 0, 4);
        $numeric = '';
        foreach (str_split($rearranged) as $ch) {
            $numeric .= ctype_alpha($ch) ? (string) (ord($ch) - 55) : $ch;
        }

        // Chunked mod 97 for large numbers without BCMath dependency.
        $checksum = 0;
        foreach (str_split($numeric, 7) as $chunk) {
            $checksum = (int) (($checksum.$chunk) % 97);
        }

        return $checksum === 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePaypal(Request $request, bool $requireConfirm): array
    {
        $rules = [
            'paypal_email' => 'required|email|max:255',
            'details_confirmed' => 'accepted',
        ];
        if ($requireConfirm) {
            $rules['paypal_email_confirm'] = 'required|email|max:255|same:paypal_email';
        }

        $request->validate($rules, [
            'paypal_email_confirm.same' => 'PayPal emails must match exactly (enter twice to verify).',
            'details_confirmed.accepted' => 'Please confirm you have double-checked your payout details.',
        ]);

        return ['email' => $request->paypal_email];
    }

    /**
     * @return array<string, mixed>
     */
    private function validateWise(Request $request, bool $requireConfirm): array
    {
        $rules = [
            'wise_email' => 'required|email|max:255',
            'details_confirmed' => 'accepted',
        ];
        if ($requireConfirm) {
            $rules['wise_email_confirm'] = 'required|email|max:255|same:wise_email';
        }

        $request->validate($rules, [
            'wise_email_confirm.same' => 'Wise emails must match exactly (enter twice to verify).',
            'details_confirmed.accepted' => 'Please confirm you have double-checked your payout details.',
        ]);

        return ['email' => $request->wise_email];
    }

    /**
     * @return array<string, mixed>
     */
    private function validateCrypto(Request $request, bool $requireConfirm): array
    {
        $rules = [
            'crypto_type' => 'required|string|in:BTC,ETH,USDT,BNB',
            'wallet_address' => 'required|string|max:255',
            'details_confirmed' => 'accepted',
        ];
        if ($requireConfirm) {
            $rules['wallet_address_confirm'] = 'required|string|max:255|same:wallet_address';
        }

        $request->validate($rules, [
            'wallet_address_confirm.same' => 'Wallet addresses must match exactly (enter twice to verify).',
            'details_confirmed.accepted' => 'Please confirm you have double-checked your payout details.',
        ]);

        $type = strtoupper((string) $request->crypto_type);
        $address = trim((string) $request->wallet_address);
        if (! $this->looksLikeValidCryptoAddress($type, $address)) {
            throw ValidationException::withMessages([
                'wallet_address' => 'Enter a valid '.$type.' wallet address for the selected coin.',
            ]);
        }

        return [
            'crypto_type' => $type,
            'wallet_address' => $address,
        ];
    }

    private function looksLikeValidCryptoAddress(string $type, string $address): bool
    {
        if ($address === '' || preg_match('/\s/', $address)) {
            return false;
        }

        return match ($type) {
            'BTC' => (bool) preg_match('/^(bc1|[13])[a-zA-HJ-NP-Z0-9]{25,62}$/', $address),
            'ETH', 'BNB' => (bool) preg_match('/^0x[a-fA-F0-9]{40}$/', $address),
            // USDT commonly TRC20 (T…) or ERC20 (0x…)
            'USDT' => (bool) (
                preg_match('/^0x[a-fA-F0-9]{40}$/', $address)
                || preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $address)
            ),
            default => false,
        };
    }
}
