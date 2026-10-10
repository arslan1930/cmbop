<?php

namespace App\Support;

use App\Models\Site;
use Illuminate\Support\Carbon;

/**
 * Staff can invite a publisher to Accept, or publish now (live, unverified).
 * Single-site assign, CSV bulk create, and publisher-bulk Done share these flags.
 */
final class StaffListingPublishMode
{
    public const INVITE = 'invite';

    public const PUBLISH = 'publish';

    /** Bulk Done “Send for review” — not a staff-assign invite, but the same wait. */
    public const REVIEW = 'review';

    public static function validationRule(): string
    {
        return 'nullable|in:'.self::INVITE.','.self::PUBLISH.','.self::REVIEW;
    }

    /**
     * Missing / invite / review → invite. Unknown values are null (fail validation).
     */
    public static function parse(mixed $value): ?string
    {
        if ($value === null) {
            return self::INVITE;
        }

        if (! is_scalar($value)) {
            return null;
        }

        $raw = strtolower(trim((string) $value));
        if ($raw === '' || $raw === self::INVITE || $raw === self::REVIEW) {
            return self::INVITE;
        }

        if ($raw === self::PUBLISH) {
            return self::PUBLISH;
        }

        return null;
    }

    public static function isPublish(?string $mode): bool
    {
        return $mode === self::PUBLISH;
    }

    /**
     * Flags for Add site / CSV staff batch (assigned_by stays on the site).
     *
     * @return array{active: bool, verified: bool, publisher_accepted_at: Carbon|null, onboarding_status: string|null}
     */
    public static function staffAssignAttributes(string $mode): array
    {
        $publish = self::isPublish($mode);

        return [
            'active' => $publish,
            'verified' => false,
            'publisher_accepted_at' => $publish ? now() : null,
            'onboarding_status' => null,
        ];
    }

    /**
     * Flags for publisher bulk request Done (no assigned_by).
     *
     * @return array{active: bool, verified: bool, publisher_accepted_at: Carbon|null, assigned_by_user_id: null, onboarding_status: string|null}
     */
    public static function bulkDoneAttributes(string $doneMode): array
    {
        $publish = $doneMode !== self::REVIEW;

        return [
            'active' => $publish,
            'verified' => false,
            'publisher_accepted_at' => $publish ? now() : null,
            'assigned_by_user_id' => null,
            'onboarding_status' => $publish ? null : Site::ONBOARDING_DETAILS_COMPLETE,
        ];
    }

    public static function applyStaffAssign(Site $site, string $mode): void
    {
        $site->forceFill(self::staffAssignAttributes($mode));
    }
}
