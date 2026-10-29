<?php

namespace App\Http\Controllers;

use App\Models\EmailNotificationPreference;
use App\Models\InAppNotificationPreference;
use Illuminate\Http\Request;

class NotificationPreferenceController extends Controller
{
    public function edit()
    {
        $preferences = EmailNotificationPreference::forUser(auth()->user());
        $inAppPreferences = InAppNotificationPreference::forUser(auth()->user());
        $inAppPrefsAvailable = InAppNotificationPreference::tableAvailable();

        return view('profile.notification-preferences', compact(
            'preferences',
            'inAppPreferences',
            'inAppPrefsAvailable'
        ));
    }

    public function update(Request $request)
    {
        $user = $request->user();
        $keys = array_keys(config('email_notifications.preference_keys', []));
        $input = $request->input('preferences', []);

        foreach ($keys as $key) {
            $meta = config("email_notifications.preference_keys.{$key}", []);
            if (! empty($meta['locked'])) {
                EmailNotificationPreference::updateOrCreate(
                    ['user_id' => $user->id, 'preference_key' => $key],
                    ['enabled' => true]
                );

                continue;
            }

            EmailNotificationPreference::updateOrCreate(
                ['user_id' => $user->id, 'preference_key' => $key],
                ['enabled' => ! empty($input[$key])]
            );
        }

        // In-app category toggles — no-op when the table has not been created yet.
        if (InAppNotificationPreference::tableAvailable()) {
            $inAppInput = $request->input('in_app', []);
            foreach (InAppNotificationPreference::CATEGORIES as $category) {
                InAppNotificationPreference::updateOrCreate(
                    ['user_id' => $user->id, 'category' => $category],
                    ['enabled' => ! empty($inAppInput[$category])]
                );
            }
        }

        return back()->with('success', 'Notification preferences saved.');
    }
}
