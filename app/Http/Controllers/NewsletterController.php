<?php

namespace App\Http\Controllers;

use App\Models\NewsletterSubscriber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class NewsletterController extends Controller
{
    public function subscribe(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|max:255',
            'newsletter_opt_in' => 'accepted',
            'int_com_lang' => 'nullable|string|max:10',
        ], [
            'newsletter_opt_in.accepted' => __('messages.newsletter_consent_required'),
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        try {
            $email = strtolower(trim($request->email));
            $locale = $request->input('int_com_lang', app()->getLocale() ?: 'en');

            $subscriber = NewsletterSubscriber::firstOrNew(['email' => $email]);
            $wasExisting = $subscriber->exists;

            $subscriber->fill([
                'locale' => $locale,
                'ip_address' => $request->ip(),
                'consented_at' => now(),
            ]);
            $subscriber->save();

            return response()->json([
                'success' => true,
                'message' => $wasExisting
                    ? __('messages.newsletter_already_subscribed')
                    : __('messages.newsletter_success_message'),
            ]);
        } catch (\Exception $e) {
            Log::error('Newsletter subscribe failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => __('messages.newsletter_error_message'),
            ], 500);
        }
    }
}
