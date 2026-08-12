<?php

namespace App\Services;

use App\Jobs\EnrichSiteJob;
use App\Mail\SiteStatusNotification;
use App\Models\AgencySiteImport;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class AgencySiteImportReviewService
{
    public function __construct(
        private InAppNotificationService $notifications,
    ) {}

    /**
     * @param  list<int>  $siteIds
     * @return array{updated: int, skipped: int}
     */
    public function bulkAction(
        AgencySiteImport $import,
        User $admin,
        string $action,
        array $siteIds,
        ?string $reason = null
    ): array {
        if (! $admin->isAdmin()) {
            throw ValidationException::withMessages([
                'action' => 'Only admins can bulk-review agency CSV imports.',
            ]);
        }

        $action = strtolower(trim($action));
        if (! in_array($action, ['verify', 'activate', 'reject'], true)) {
            throw ValidationException::withMessages([
                'action' => 'Unknown bulk action.',
            ]);
        }

        if ($action === 'reject') {
            $reason = trim((string) $reason);
            if (strlen($reason) < 5) {
                throw ValidationException::withMessages([
                    'reason' => 'Please provide a rejection reason (at least 5 characters).',
                ]);
            }
        }

        $siteIds = array_values(array_unique(array_map('intval', $siteIds)));
        if ($siteIds === []) {
            throw ValidationException::withMessages([
                'site_ids' => 'Select at least one site.',
            ]);
        }

        $sites = Site::query()
            ->where('agency_site_import_id', $import->id)
            ->whereIn('id', $siteIds)
            ->get();

        $updated = 0;
        $skipped = count($siteIds) - $sites->count();

        foreach ($sites as $site) {
            match ($action) {
                'verify' => $this->verifySite($site, $admin),
                'activate' => $this->activateSite($site, $admin),
                'reject' => $this->rejectSite($site, $admin, $reason),
            };
            $updated++;
        }

        $import->refreshReviewStatus();

        ActivityLogger::log(
            'agency_import.bulk_'.$action,
            $admin->name.' bulk-'.$action.' on '.$updated.' site(s) in agency import #'.$import->id,
            $import,
            [
                'import_id' => $import->id,
                'action' => $action,
                'updated' => $updated,
                'skipped' => $skipped,
                'site_ids' => $sites->pluck('id')->all(),
            ],
            'Agency import #'.$import->id
        );

        return ['updated' => $updated, 'skipped' => $skipped];
    }

    private function verifySite(Site $site, User $admin): void
    {
        $site->promoteFromAwaitingDetailsIfComplete();
        $site->refresh();
        if ($site->awaitsPublisherDetails()) {
            $site->clearAwaitingDetailsForAdmin();
            $site->refresh();
        }

        $old = (int) $site->verified;
        $site->verified = 1;
        $site->verified_at = now();
        $site->verify_method = 'manual';
        $site->verify_token = null;
        $site->verify_token_created_at = null;
        $site->onboarding_status = null;
        $site->save();

        ActivityLogger::log(
            'site.approved',
            $admin->name.' approved site "'.$site->site_name.'" (agency CSV bulk)',
            $site,
            [
                'from' => $old,
                'to' => 1,
                'agency_site_import_id' => $site->agency_site_import_id,
                'via' => 'agency_import_bulk',
            ],
            $site->site_name
        );

        if (config('site_enrichment.enabled', true)) {
            $runMetrics = ! (bool) $site->metrics_manual;
            EnrichSiteJob::dispatch($site->id, 'verify', $runMetrics, true);
        }

        $this->safeNotifyStatus($site, 'verified');
    }

    private function activateSite(Site $site, User $admin): void
    {
        $site->promoteFromAwaitingDetailsIfComplete();
        $site->refresh();
        if ($site->awaitsPublisherDetails()) {
            $site->clearAwaitingDetailsForAdmin();
            $site->refresh();
        }

        $old = (int) $site->active;
        $site->active = 1;
        $site->onboarding_status = null;
        $site->save();

        ActivityLogger::log(
            'site.activated',
            $admin->name.' activated site "'.$site->site_name.'" (agency CSV bulk)',
            $site,
            [
                'from' => $old,
                'to' => 1,
                'agency_site_import_id' => $site->agency_site_import_id,
                'via' => 'agency_import_bulk',
            ],
            $site->site_name
        );

        $this->safeNotifyStatus($site, 'activated');
    }

    private function rejectSite(Site $site, User $admin, string $reason): void
    {
        Site::ensureStatusReasonColumns();

        $oldVerified = (int) $site->verified;
        $oldActive = (int) $site->active;
        $site->verified = 0;
        $site->verified_at = null;
        $site->verify_method = null;
        $site->active = 0;
        $site->status_reason = $reason;
        $site->status_reason_at = now();
        $site->status_reason_by = $admin->id;
        $site->save();

        ActivityLogger::log(
            'site.rejected',
            $admin->name.' rejected site "'.$site->site_name.'" (agency CSV bulk)',
            $site,
            [
                'from_verified' => $oldVerified,
                'from_active' => $oldActive,
                'agency_site_import_id' => $site->agency_site_import_id,
                'reason' => $reason,
                'via' => 'agency_import_bulk',
            ],
            $site->site_name
        );

        $this->safeNotifyStatus($site, 'unverified', $reason);
    }

    private function safeNotifyStatus(Site $site, string $status, ?string $reason = null): void
    {
        try {
            $this->notifications->completeAdminSiteReviewNotifications($site);
        } catch (\Throwable $e) {
            Log::warning('Could not complete site review notifications: '.$e->getMessage());
        }

        try {
            $publisher = $site->publisher;
            if ($publisher?->email) {
                Mail::to($publisher->email)->send(new SiteStatusNotification($site, $status, null, $reason));
            }
            if ($publisher) {
                $this->notifications->notifySiteStatusChanged($site->fresh(), $status, $reason);
            }
        } catch (\Throwable $e) {
            Log::warning('Agency import bulk status notify failed: '.$e->getMessage());
        }
    }
}
