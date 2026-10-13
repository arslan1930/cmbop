<?php

namespace App\Http\Controllers;

use App\Support\DisplayCurrencyPreference;
use Illuminate\Http\Request;

class CurrencySwitchController extends Controller
{
    public function store(Request $request, DisplayCurrencyPreference $preference)
    {
        $code = $preference->remember((string) $request->input('currency', ''), $request);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'currency' => $code,
            ]);
        }

        return redirect()->back();
    }
}
