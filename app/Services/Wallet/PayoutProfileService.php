<?php

namespace App\Services\Wallet;

use App\Models\User;
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
     * Validate request details. When locked, values must match the saved profile.
     * When unlocked, confirmation fields are required.
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
        $profile = $user->payoutProfile();

        if ($locked && $this->profileHasMethod($user, $method)) {
            // Locked destinations always come from the saved profile (publisher cannot edit).
            return $this->paymentDetailsFromProfile($user, $method);
        }

        if ($locked) {
            $preferred = (string) ($profile['preferred_method'] ?? '');
            if ($preferred !== '' && $preferred !== $method) {
                throw ValidationException::withMessages([
                    'payment_method' => 'Your payout method is locked to '.strtoupper($preferred).'. Contact support to change it.',
                ]);
            }

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

        return [
            'bank_name' => $request->bank_name,
            'account_holder' => $request->account_holder,
            'account_number' => $request->account_number,
            'swift_code' => $request->swift_code,
        ];
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

        return [
            'crypto_type' => $request->crypto_type,
            'wallet_address' => $request->wallet_address,
        ];
    }
}
