<?php

namespace App\Http\Controllers;

use App\Support\CartDisplayFx;
use App\Support\DisplayCurrencyPreference;
use Illuminate\Http\Request;

class CurrencySwitchController extends Controller
{
    public function store(Request $request, DisplayCurrencyPreference $preference)
    {
        // Logged-in accounts are locked — remember() ignores the posted code.
        $code = $preference->remember((string) $request->input('currency', ''), $request);
        app(CartDisplayFx::class)->forget();

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'currency' => $code,
            ]);
        }

        return redirect()->back();
    }
}
