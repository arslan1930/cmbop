<?php

namespace App\Support;

use Illuminate\Http\Request;

class HttpCron
{
    public static function authorize(Request $request): void
    {
        $secret = (string) config('app.cron_secret', '');
        if (strlen($secret) < 32) {
            abort(404, UserMessages::get('cron.disabled'));
        }

        $provided = trim((string) $request->header('X-Cron-Key', ''));
        if ($provided === '' || ! hash_equals($secret, $provided)) {
            abort(403, UserMessages::get('cron.forbidden'));
        }
    }
}
