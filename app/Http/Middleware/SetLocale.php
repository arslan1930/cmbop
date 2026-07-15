<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Session;

class SetLocale
{
    public function handle(Request $request, Closure $next)
    {
        $availableLocales = ['de', 'fr', 'nl'];
        
        // Get the first segment of the URL
        $locale = $request->segment(1);
        
        // Check if the first segment is a valid locale
        if (in_array($locale, $availableLocales)) {
            // Set locale to the detected language
            App::setLocale($locale);
            Session::put('locale', $locale);
        } else {
            // Default to English
            App::setLocale('en');
            Session::put('locale', 'en');
        }
        
        return $next($request);
    }
}