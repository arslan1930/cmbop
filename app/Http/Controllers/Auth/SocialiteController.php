<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Role;
use App\Models\Wallet;
use App\Models\UserConsent;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
            $googleUser = Socialite::driver('google')->user();

            // Check if user already exists by email
            $existingUser = User::where('email', $googleUser->getEmail())->first();

            if ($existingUser) {
                // Update existing user with Google data
                $existingUser->google_id = $googleUser->getId();
                $existingUser->google_token = $googleUser->token;
                $existingUser->google_refresh_token = $googleUser->refreshToken ?? null;
                $existingUser->avatar = $googleUser->getAvatar();
                $existingUser->email_verified_at = now();
                $existingUser->save();
                
                Auth::login($existingUser);
                
                $existingUser->load('activeRoleRelation', 'roles');
                $redirect = $existingUser->getDashboardRoute();
                
                return redirect()->intended($redirect);
            }

            // Create new user from Google data
            DB::beginTransaction();

            // Fetch roles
            $advertiserRole = Role::where('name', 'advertiser')->first();
            $publisherRole = Role::where('name', 'publisher')->first();

            if (!$advertiserRole || !$publisherRole) {
                throw new \Exception('Roles not found. Please run database seeders.');
            }

            // Create the user
            $user = User::create([
                'name' => $googleUser->getName(),
                'email' => $googleUser->getEmail(),
                'password' => bcrypt(Str::random(24)),
                'email_verified_at' => now(),
                'google_id' => $googleUser->getId(),
                'google_token' => $googleUser->token,
                'google_refresh_token' => $googleUser->refreshToken ?? null,
                'avatar' => $googleUser->getAvatar(),
                'active_role_id' => $advertiserRole->id,
            ]);

            // Attach both roles to the user
            $user->roles()->sync([$advertiserRole->id, $publisherRole->id]);

            // Create wallets for both roles (€20 spend-only welcome credit on advertiser wallet)
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
                ]
            ];

            DB::table('wallets')->insert($wallets);

            // Save user consents
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
            $redirect = $user->getDashboardRoute();

            return redirect()->intended($redirect);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            \Log::error('Google authentication failed: ' . $e->getMessage());
            
            return redirect()->route('login')
                ->with('error', 'Google authentication failed. Please try again.');
        }
    }
}