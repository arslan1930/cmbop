<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Role;
use App\Models\Wallet;
use App\Models\UserConsent;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\RateLimiter;
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
        // Rate limiting: max 5 attempts per 10 minutes per IP
        $key = 'register:' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Too many registration attempts. Please try again later.'
            ]);
        }
        RateLimiter::hit($key, 600); // 600 seconds = 10 minutes

        // Validation
        $validator = Validator::make($request->all(), [
            'name'                  => 'required|string|max:255',
            'email'                 => 'required|email|max:255|unique:users',
            'password'              => ['required', 'confirmed', Password::defaults()],
            'role'                  => 'required|in:advertiser,publisher',
            'terms'                 => 'accepted',
            'g-recaptcha-response'  => 'required',
        ], [
            'terms.accepted' => 'You must agree to the Terms and Services.',
            'g-recaptcha-response.required' => 'Please verify that you are not a robot.'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'validation',
                'errors' => $validator->errors()
            ]);
        }

        DB::beginTransaction();

        try {
            // Create the user
            $user = User::create([
                'name'     => $request->name,
                'email'    => $request->email,
                'password' => $request->password, // hashed in User model
            ]);

            // Fetch both roles
            $advertiserRole = Role::where('name', 'advertiser')->first();
            $publisherRole  = Role::where('name', 'publisher')->first();

            // Attach both roles to the user
            $user->roles()->sync([$advertiserRole->id, $publisherRole->id]);

            // Set active role based on selected role
            $activeRole = $request->role === 'advertiser' ? $advertiserRole : $publisherRole;
            $user->active_role_id = $activeRole->id;
            $user->save();

            // Create wallets for both roles
            $wallets = [
                [
                    'user_id'          => $user->id,
                    'role_id'          => $advertiserRole->id,
                    'balance'          => $request->role === 'advertiser' ? 20.00 : 0.00,
                    'reserved_balance' => 0.00,
                    'currency'         => 'EUR',
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ],
                [
                    'user_id'          => $user->id,
                    'role_id'          => $publisherRole->id,
                    'balance'          => $request->role === 'publisher' ? 20.00 : 0.00,
                    'reserved_balance' => 0.00,
                    'currency'         => 'EUR',
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]
            ];

            DB::table('wallets')->insert($wallets);

            // Save user consents
            UserConsent::create([
                'user_id'            => $user->id,
                'terms_accepted'     => $request->has('terms'),
                'marketing_consent'  => $request->has('marketing'),
                'newsletter_consent' => $request->has('newsletter'),
                'consented_at'       => now(),
                'ip_address'         => $request->ip(),
                'user_agent'         => $request->userAgent(),
            ]);

            // Trigger Laravel's registered event (email verification)
            event(new Registered($user));

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Registration successful! A verification email has been sent. Please verify your email to login.'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => 'Something went wrong. Please try again.',
                'error' => $e->getMessage()
            ]);
        }
    }
}