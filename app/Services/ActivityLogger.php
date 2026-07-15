<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class ActivityLogger
{
    /**
     * Record a dashboard activity with the actor's registered name.
     */
    public static function log(
        string $action,
        string $description,
        ?Model $subject = null,
        array $properties = [],
        ?string $subjectLabel = null
    ): ActivityLog {
        $user = Auth::user();

        return ActivityLog::create([
            'user_id'       => $user?->id,
            'user_name'     => $user?->name,
            'user_email'    => $user?->email,
            'role'          => $user?->activeRole(),
            'action'        => $action,
            'subject_type'  => $subject ? get_class($subject) : null,
            'subject_id'    => $subject?->getKey(),
            'subject_label' => $subjectLabel
                ?? ($subject?->site_name ?? $subject?->name ?? $subject?->email ?? null),
            'description'   => $description,
            'properties'    => $properties ?: null,
            'ip_address'    => Request::ip(),
            'user_agent'    => substr((string) Request::userAgent(), 0, 512),
        ]);
    }
}
