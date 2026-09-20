<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Models\UserConsent;
use App\Models\Wallet;
use App\Services\Wallet\WalletLedgerService;
use App\Services\Wallet\WelcomeBonusService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class RegisterController extends Controller
{
    /**
     * Show the registration form
     */
    public function show(WelcomeBonusService $welcomeBonus)
    {
        $roles = ['advertiser' => 'Advertiser', 'publisher' => 'Publisher'];
        $welcomeBonusEnabled = false;
        $welcomeBonusAmount = 0.0;
        try {
            $welcomeBonusEnabled = $welcomeBonus->canGrant();
            $welcomeBonusAmount = $welcomeBonus->amount();
        } catch (\Throwable) {
            $welcomeBonusEnabled = false;
            $welcomeBonusAmount = 0.0;
        }

        return view('auth.register', compact('roles', 'welcomeBonusEnabled', 'welcomeBonusAmount'));
    }

    /**
     * Handle registration (AJAX)
     */
    public function register(Request $request)
    {
        $key = 'register:'.$request->ip();
        if (class_exists(WelcomeBonusService::class)) {
            try {
                $key = app(WelcomeBonusService::class)->registerRateLimitKey($request);
            } catch (\Throwable) {
                $key = 'register:'.$request->ip();
            }
        }
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json([
                'status' => 'error',
                'message' => $this->copy('register.throttled', 'Too many registration attempts. Please try again later.'),
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
                'message' => $this->copy('register.validation', 'Please fix the highlighted fields and try again.'),
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
                'message' => $this->copy('register.unavailable', 'Registration is temporarily unavailable. Please contact support.'),
            ], 500);
        }

        $bonusService = class_exists(WelcomeBonusService::class) ? app(WelcomeBonusService::class) : null;
        $welcomeBonus = 0.0;
        $user = null;

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

            $welcomeBonus = 0.0;
            if ($bonusService) {
                $welcomeBonus = $bonusService->amountFor($request, (string) $request->role);
                if ($welcomeBonus > 0 && ! $bonusService->recordClaim($user, $request, $welcomeBonus, 'registration')) {
                    $welcomeBonus = 0.0;
                }
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
                    'terms_accepted' => $request->boolean('terms'),
                    'marketing_consent' => $request->boolean('marketing'),
                    'newsletter_consent' => $request->boolean('newsletter'),
                    'consented_at' => now(),
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Registration failed', [
                'error' => $e->getMessage(),
                'email' => $request->email,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => $this->copy('register.failed', 'Something went wrong. Please try again.'),
            ], 500);
        }

        // Side-effects after commit — never roll back a created account
        if ($welcomeBonus > 0) {
            if ($bonusService && method_exists($bonusService, 'queueClaimCookie')) {
                $bonusService->queueClaimCookie();
            }
            try {
                $advertiserWallet = Wallet::where('user_id', $user->id)
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

        $verificationSent = false;
        try {
            // Framework listener SendEmailVerificationNotification → User::sendEmailVerificationNotification()
            event(new Registered($user));
            $verificationSent = true;
        } catch (\Throwable $e) {
            Log::error('Registered/verification email failed after signup', [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $e->getMessage(),
            ]);

            // Direct retry if the event path failed
            try {
                $user->sendEmailVerificationNotification();
                $verificationSent = true;
            } catch (\Throwable $retry) {
                Log::error('Direct verification email retry also failed', [
                    'user_id' => $user->id,
                    'error' => $retry->getMessage(),
                ]);
            }
        }

        $message = $verificationSent
            ? 'Registration successful! A verification email has been sent. Please verify your email to login.'
            : 'Registration successful! We could not send the verification email automatically — please use “Resend verification” on the login page.';

        session()->flash('message', $message);

        return response()->json([
            'status' => 'success',
            'message' => $message,
            'verification_sent' => $verificationSent,
            'redirect' => route('login', absolute: false),
        ]);
    }

    private function copy(string $key, string $fallback): string
    {
        return function_exists('user_message')
            ? user_message($key, $fallback)
            : $fallback;
    }
}
