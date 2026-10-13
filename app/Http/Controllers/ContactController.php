<?php

namespace App\Http\Controllers;

use App\Mail\ContactFormMail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class ContactController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        if (trim((string) $request->input('website', '')) !== '') {
            return back()->with('success', __('messages.contact_form_thanks'));
        }

        $key = 'contact-form:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()
                ->withInput()
                ->with('error', __('messages.contact_form_throttled'));
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
        ]);

        RateLimiter::hit($key, 3600);

        $to = (string) config('company.support_email', 'support@seolinkbuildings.com');

        try {
            Mail::to($to)->send(new ContactFormMail(
                name: $data['name'],
                email: $data['email'],
                body: $data['message'],
            ));
        } catch (Throwable $e) {
            report($e);

            return back()
                ->withInput()
                ->with('error', __('messages.contact_form_error'));
        }

        return back()->with('success', __('messages.contact_form_thanks'));
    }
}
