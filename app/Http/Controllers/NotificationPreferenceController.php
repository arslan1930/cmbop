<?php

namespace App\Http\Controllers;

use App\Models\EmailNotificationPreference;
use Illuminate\Http\Request;

class NotificationPreferenceController extends Controller
{
    public function edit()
    {
        $preferences = EmailNotificationPreference::forUser(auth()->user());

        return view('profile.notification-preferences', compact('preferences'));
    }

    public function update(Request $request)
    {
        $user = $request->user();
        $keys = array_keys(config('email_notifications.preference_keys', []));
        $input = $request->input('preferences', []);

        foreach ($keys as $key) {
            $meta = config("email_notifications.preference_keys.{$key}", []);
            if (!empty($meta['locked'])) {
                EmailNotificationPreference::updateOrCreate(
                    ['user_id' => $user->id, 'preference_key' => $key],
                    ['enabled' => true]
                );
                continue;
            }

            EmailNotificationPreference::updateOrCreate(
                ['user_id' => $user->id, 'preference_key' => $key],
                ['enabled' => !empty($input[$key])]
            );
        }

        return back()->with('success', 'Email notification preferences saved.');
    }
}
