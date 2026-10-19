<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\PasswordChangedMail;
use App\Models\User;
use App\Models\UserConsent;
use App\Services\Staff\PublisherOnboardingService;
use App\Support\PartnerDashboard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

class StaffOnboardingPasswordController extends Controller
{
    public function show(Request $request, User $user): View|RedirectResponse
    {
        if (! $this->hashMatches($request, $user)) {
            return $this->invalidInviteRedirect($request);
        }

        $view = $this->onPartnerHost($request)
            ? 'partner.auth.set-password'
            : 'auth.staff-onboard-password';

        return view($view, [
            'user' => $user,
            'formAction' => $request->fullUrl(),
        ]);
    }

    public function store(Request $request, User $user, PublisherOnboardingService $onboarding): RedirectResponse
    {
        if (! $this->hashMatches($request, $user)) {
            return $this->invalidInviteRedirect($request);
        }

        $validator = Validator::make($request->all(), [
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
            'terms' => 'accepted',
        ], [
            'terms.accepted' => $this->copy('register.terms', 'You must agree to the Terms and Services.'),
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        if (method_exists($user, 'isSuspended') && $user->isSuspended()) {
            return $this->invalidInviteRedirect($request)
                ->with('error', $this->copy('login.suspended', 'This account has been suspended. Contact support if you think this is a mistake.'));
        }

        $user->password = (string) $request->input('password');
        $onboarding->activate($user);

        if (Schema::hasTable('user_consents') && ! $user->consent) {
            UserConsent::create([
                'user_id' => $user->id,
                'terms_accepted' => true,
                'marketing_consent' => $request->boolean('marketing'),
                'newsletter_consent' => $request->boolean('newsletter'),
                'consented_at' => now(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        }

        if (class_exists(PasswordChangedMail::class) && method_exists(PasswordChangedMail::class, 'notify')) {
            try {
                PasswordChangedMail::notify($user);
            } catch (\Throwable $e) {
                Log::warning('Staff onboarding password-changed mail failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
        } catch (\Throwable $e) {
            Log::warning('Could not invalidate other sessions after staff onboarding password', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->flash('prompt_payout_setup', true);
        $request->session()->flash(
            'success',
            $this->copy(
                'publisher_onboard.password_ready',
                'Password saved. Review the listings waiting for you, then add a payment method so we can pay you.'
            )
        );

        if (method_exists($user, 'isSupplyPartner') && $user->isSupplyPartner()
            && class_exists(PartnerDashboard::class)) {
            if ($this->onPartnerHost($request)) {
                $request->session()->put('partner_scope_level', 'full');

                return redirect()->to(PartnerDashboard::url('/'));
            }
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->to(PartnerDashboard::url('/login'))
                ->with('success', 'Password saved. Sign in on the partner dashboard.');
        }

        return redirect()->to(route('publisher.websites', ['status' => 'invites'], false));
    }

    private function hashMatches(Request $request, User $user): bool
    {
        $hash = (string) $request->query('hash', '');

        return hash_equals(sha1($user->getEmailForVerification()), $hash);
    }

    private function onPartnerHost(Request $request): bool
    {
        return class_exists(PartnerDashboard::class) && PartnerDashboard::isPartnerHost($request);
    }

    private function invalidInviteRedirect(Request $request): RedirectResponse
    {
        $target = $this->onPartnerHost($request)
            ? PartnerDashboard::url('/login')
            : route('login', absolute: false);

        return redirect()->to($target)
            ->with('error', $this->copy('publisher_onboard.link_invalid', 'This invite link is invalid. Ask your contact to resend it.'));
    }

    private function copy(string $key, string $fallback): string
    {
        return function_exists('user_message')
            ? user_message($key, $fallback)
            : $fallback;
    }
}
