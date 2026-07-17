<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Role;
use App\Models\UserConsent;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class SocialiteController extends Controller
{
    public function redirectToGoogle()
    {
        return Socialite::driver('google')->redirect();
    }

    public function handleGoogleCallback()
    {
        try {
            $socialUser = Socialite::driver('google')->user();
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

                Auth::login($existingUser);

                $existingUser->load('activeRoleRelation', 'roles');

                return redirect()->intended($existingUser->getDashboardRoute());
            }

            if (! $email) {
                return redirect()->route('login')
                    ->with('error', 'Google did not share an email address. Please use another sign-in method.');
            }

            DB::beginTransaction();

            $advertiserRole = Role::where('name', 'advertiser')->first();
            $publisherRole = Role::where('name', 'publisher')->first();

            if (! $advertiserRole || ! $publisherRole) {
                throw new \Exception('Roles not found. Please run database seeders.');
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

            $advertiserWallet = \App\Models\Wallet::where('user_id', $user->id)
                ->where('role_id', $advertiserRole->id)
                ->first();
            if ($advertiserWallet && $welcomeBonus > 0) {
                app(\App\Services\Wallet\WalletLedgerService::class)->recordBonusCredit(
                    $advertiserWallet,
                    (float) $welcomeBonus,
                    'Welcome promotional bonus',
                    ['source' => 'socialite']
                );
            }

            UserConsent::create([
                'user_id' => $user->id,
                'terms_accepted' => true,
                'marketing_consent' => false,
                'newsletter_consent' => false,
                'consented_at' => now(),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);

            DB::commit();

            Auth::login($user);

            $user->load('activeRoleRelation', 'roles');

            return redirect()->intended($user->getDashboardRoute());
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Google authentication failed: '.$e->getMessage());

            return redirect()->route('login')
                ->with('error', 'Google authentication failed. Please try again.');
        }
    }
}
