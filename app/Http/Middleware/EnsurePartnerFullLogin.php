<?php

namespace App\Http\Middleware;

use App\Support\PartnerDashboard;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePartnerFullLogin
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->session()->get('partner_scope_level') === 'order') {
            return redirect()->to(PartnerDashboard::route('login'))
                ->with('error', 'Sign in to open money and settings.');
        }

        return $next($request);
    }
}
