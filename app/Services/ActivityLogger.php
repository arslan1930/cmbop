<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\BulkSiteRequest;
use App\Models\Site;
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
        $properties = self::withSubjectContext($subject, $properties);

        return ActivityLog::create([
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'user_email' => $user?->email,
            'role' => $user?->activeRole(),
            'action' => $action,
            'subject_type' => $subject ? get_class($subject) : null,
            'subject_id' => $subject?->getKey(),
            'subject_label' => $subjectLabel
                ?? ($subject?->site_name ?? $subject?->name ?? $subject?->email ?? null),
            'description' => $description,
            'properties' => $properties ?: null,
            'ip_address' => Request::ip(),
            'user_agent' => substr((string) Request::userAgent(), 0, 512),
        ]);
    }

    /**
     * Attach publisher / bulk ids from the subject when the caller omitted them.
     *
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private static function withSubjectContext(?Model $subject, array $properties): array
    {
        if ($subject instanceof Site) {
            if (! array_key_exists('publisher_id', $properties) && $subject->publisher_id) {
                $properties['publisher_id'] = (int) $subject->publisher_id;
            }
            if (! array_key_exists('bulk_site_request_id', $properties) && $subject->bulk_site_request_id) {
                $properties['bulk_site_request_id'] = (int) $subject->bulk_site_request_id;
            }
        }

        if ($subject instanceof BulkSiteRequest && ! array_key_exists('publisher_id', $properties) && $subject->publisher_id) {
            $properties['publisher_id'] = (int) $subject->publisher_id;
        }

        return $properties;
    }
}
