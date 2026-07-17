<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Role;
use App\Models\UserConsent;
use App\Services\Wallet\WalletLedgerService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Log;
use Illuminate\Auth\Events\Registered;

class RegisterController extends Controller
{
    /**
     * Show the registration form
     */
    public function show()
    {
        $roles = ['advertiser' => 'Advertiser', 'publisher' => 'Publisher'];

        return view('auth.register', compact('roles'));
    }

    /**
     * Handle registration (AJAX)
     */
    public function register(Request $request)
    {
        $key = 'register:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Too many registration attempts. Please try again later.',
            ], 429);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users',
            'password' => ['required', 'confirmed', Password::defaults()],
            'role' => 'required|in:advertiser,publisher',
            'terms' => 'accepted',
        ], [
            'terms.accepted' => 'You must agree to the Terms and Services.',
        ]);

        if ($validator->fails()) {
            // Do not burn rate-limit budget on validation mistakes
            return response()->json([
                'status' => 'validation',
                'message' => 'Please fix the highlighted fields and try again.',
                'errors' => $validator->errors(),
            ], 422);
        }

        RateLimiter::hit($key, 600);

        $advertiserRole = Role::where('name', 'advertiser')->first();
        $publisherRole = Role::where('name', 'publisher')->first();

        if (! $advertiserRole || ! $publisherRole) {
            Log::error('Registration failed: advertiser/publisher roles missing');

            return response()->json([
                'status' => 'error',
                'message' => 'Registration is temporarily unavailable. Please contact support.',
            ], 500);
        }

        DB::beginTransaction();

        try {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => $request->password,
            ]);

            $user->roles()->sync([$advertiserRole->id, $publisherRole->id]);

            $activeRole = $request->role === 'advertiser' ? $advertiserRole : $publisherRole;
            $user->active_role_id = $activeRole->id;
            $user->save();

            $welcomeBonus = ($request->role === 'advertiser') ? 20.00 : 0.00;

            $wallets = [
                [
                    'user_id' => $user->id,
                    'role_id' => $advertiserRole->id,
                    'balance' => $welcomeBonus,
                    'reserved_balance' => 0.00,
                    'bonus_balance' => $welcomeBonus,
                    'bonus_reserved' => 0.00,
                    'currency' => 'EUR',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'user_id' => $user->id,
                    'role_id' => $publisherRole->id,
                    'balance' => 0.00,
                    'reserved_balance' => 0.00,
                    'bonus_balance' => 0.00,
                    'bonus_reserved' => 0.00,
                    'currency' => 'EUR',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ];

            DB::table('wallets')->insert($wallets);

            if ($welcomeBonus > 0) {
                try {
                    $advertiserWallet = \App\Models\Wallet::where('user_id', $user->id)
                        ->where('role_id', $advertiserRole->id)
                        ->first();
                    if ($advertiserWallet) {
                        app(WalletLedgerService::class)->recordBonusCredit(
                            $advertiserWallet,
                            (float) $welcomeBonus,
                            'Welcome promotional bonus',
                            ['source' => 'registration']
                        );
                    }
                } catch (\Throwable $e) {
                    Log::warning('Welcome bonus ledger write failed during registration', [
                        'user_id' => $user->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            UserConsent::create([
                'user_id' => $user->id,
                'terms_accepted' => $request->boolean('terms'),
                'marketing_consent' => $request->boolean('marketing'),
                'newsletter_consent' => $request->boolean('newsletter'),
                'consented_at' => now(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            event(new Registered($user));

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Registration successful! A verification email has been sent. Please verify your email to login.',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Registration failed', ['error' => $e->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => 'Something went wrong. Please try again.',
            ], 500);
        }
    }
}
