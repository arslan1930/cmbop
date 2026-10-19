<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Services\Partners\PartnerEmailActionService;
use App\Services\Partners\PartnerEmailChangeService;
use App\Support\PartnerDashboard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EmailActionController extends Controller
{
    public function open(Request $request, string $token, PartnerEmailActionService $actions): RedirectResponse
    {
        $row = $actions->consume($token, $request->ip(), (string) $request->userAgent());
        if (! $row) {
            return redirect()->to(PartnerDashboard::route('login'))
                ->with('error', 'This link is invalid, has expired, or was already used. Sign in to open the order.');
        }
        $user = $row->user;
        if (! $user || ! PartnerDashboard::userMayAccess($user)) {
            return redirect()->to(PartnerDashboard::route('login'))
                ->with('error', PartnerDashboard::denialMessage($user));
        }

        Auth::login($user);
        $request->session()->regenerate();
        $idle = max(5, (int) config('partners.dashboard_session_idle_minutes', 30));
        $max = max(10, (int) config('partners.dashboard_session_max_minutes', 120));
        $request->session()->put('partner_scope_level', 'order');
        $request->session()->put('partner_scope_item_id', (int) $row->order_item_id);
        $request->session()->put('partner_scope_actions', $row->actions ?? ['view']);
        $request->session()->put('partner_scope_idle_at', now()->addMinutes($idle)->timestamp);
        $request->session()->put('partner_scope_max_at', now()->addMinutes($max)->timestamp);

        return redirect()->to(PartnerDashboard::route('orders.show', ['order' => (int) $row->order_item_id]));
    }

    public function confirmEmail(string $token, PartnerEmailChangeService $changes): RedirectResponse
    {
        $user = $changes->confirm($token);
        if (! $user) {
            return redirect()->to(PartnerDashboard::route('login'))
                ->with('error', 'This confirmation link is invalid or has expired.');
        }

        return redirect()->to(PartnerDashboard::route('login'))
            ->with('success', 'Email updated. Sign in with your new address.');
    }

    public function cancelEmail(string $token, PartnerEmailChangeService $changes): RedirectResponse
    {
        $user = $changes->cancel($token);
        if (! $user) {
            return redirect()->to(PartnerDashboard::route('login'))
                ->with('error', 'This cancellation link is invalid or has expired.');
        }
        Auth::logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect()->to(PartnerDashboard::route('login'))
            ->with('error', 'The email change was cancelled and this account is locked for a security review. Email partners@seolinkbuildings.com.');
    }
}
