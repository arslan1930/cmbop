<?php

namespace App\Services;

use App\Mail\SiteClaimOwnershipTransferred;
use App\Mail\SiteClaimReviewed;
use App\Mail\SiteClaimSubmitted;
use App\Models\BulkSiteRequest;
use App\Models\OrderItem;
use App\Models\OrderItemDispute;
use App\Models\Site;
use App\Models\SiteClaim;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class SiteClaimTransferService
{
    public function __construct(
        private InAppNotificationService $notifications,
    ) {}

    /**
     * Open (in-flight) order items for a site that still belong to the current publisher workflow.
     *
     * Includes NULL publisher_status (legacy rows) — SQL NOT IN drops NULLs.
     */
    public function openOrderItemsCount(Site $site): int
    {
        return OrderItem::query()
            ->where('site_id', $site->id)
            ->where(function ($q) {
                $q->whereNull('publisher_status')
                    ->orWhereNotIn('publisher_status', ['completed', 'rejected']);
            })
            ->whereHas('order', function ($q) {
                $q->whereNotIn('status', ['cancelled', 'completed', 'refunded']);
            })
            ->count();
    }

    /**
     * Open link-removed disputes tied to this site's order items.
     * Transferring ownership while a dispute is open would claw back the new publisher.
     */
    public function openDisputesCount(Site $site): int
    {
        if (! OrderItemDispute::tableAvailable()) {
            return 0;
        }

        return OrderItemDispute::query()
            ->where('status', OrderItemDispute::STATUS_OPEN)
            ->whereHas('orderItem', fn ($q) => $q->where('site_id', $site->id))
            ->count();
    }

    /**
     * @return array{open_orders: int, open_disputes: int, verified: bool, name_matches: bool, claimer_has_publisher_role: bool}
     */
    public function approveContext(SiteClaim $claim): array
    {
        $claim->loadMissing(['site', 'claimer']);
        $site = $claim->site;
        $claimer = $claim->claimer;

        return [
            'open_orders' => $site ? $this->openOrderItemsCount($site) : 0,
            'open_disputes' => $site ? $this->openDisputesCount($site) : 0,
            'verified' => (bool) ($site?->verified),
            'name_matches' => (bool) $claim->name_matches,
            'claimer_has_publisher_role' => (bool) ($claimer?->hasRole('publisher')),
        ];
    }

    /**
     * Approve a pending claim and transfer listing ownership.
     *
     * Policy: block while the site has open order items (Phase 4 — safest).
     *
     * @throws ValidationException
     */
    public function approve(SiteClaim $claim, User $admin, ?string $adminNotes = null): SiteClaim
    {
        $claim->loadMissing(['site', 'claimer']);
        $site = $claim->site;
        $claimer = $claim->claimer;

        if (! $site || ! $claimer) {
            throw ValidationException::withMessages([
                'claim' => 'This claim is missing its site or claimer account.',
            ]);
        }

        $previousPublisher = null;
        $previousPublisherId = null;
        $linkedBulkId = null;
        /** @var list<SiteClaim> $closedSiblings */
        $closedSiblings = [];

        DB::transaction(function () use (
            $claim,
            $site,
            $claimer,
            $admin,
            $adminNotes,
            &$previousPublisher,
            &$previousPublisherId,
            &$linkedBulkId,
            &$closedSiblings
        ) {
            // Lock site + claim so concurrent approves cannot both win.
            $locked = Site::query()->lockForUpdate()->findOrFail($site->id);
            $lockedClaim = SiteClaim::query()->lockForUpdate()->findOrFail($claim->id);

            if ($lockedClaim->status !== 'pending') {
                throw ValidationException::withMessages([
                    'claim' => 'This claim was already reviewed.',
                ]);
            }

            $openOrders = $this->openOrderItemsCount($locked);
            if ($openOrders > 0) {
                throw ValidationException::withMessages([
                    'claim' => "Cannot approve while this site has {$openOrders} open order(s). Finish, cancel, or resolve them first, then try again.",
                ]);
            }

            $openDisputes = $this->openDisputesCount($locked);
            if ($openDisputes > 0) {
                throw ValidationException::withMessages([
                    'claim' => "Cannot approve while this site has {$openDisputes} open dispute(s). Resolve them first, then try again.",
                ]);
            }

            $previousPublisherId = $locked->publisher_id;
            $previousPublisher = $locked->publisher;

            $locked->publisher_id = $claimer->id;
            if (Site::hasSitesColumn('publisher_accepted_at')) {
                $locked->publisher_accepted_at = now();
            }
            if (Site::hasSitesColumn('assigned_by_user_id')) {
                $locked->assigned_by_user_id = null;
            }
            // Leave active/verified as-is; clear unfinished onboarding so My Sites isn't stuck.
            if (Site::hasSitesColumn('onboarding_status')
                && in_array($locked->onboarding_status, [
                    Site::ONBOARDING_AWAITING_DETAILS,
                    Site::ONBOARDING_DETAILS_COMPLETE,
                ], true)) {
                $locked->onboarding_status = null;
            }
            if (Site::hasSitesColumn('bulk_site_request_id') && $locked->bulk_site_request_id) {
                $linkedBulkId = (int) $locked->bulk_site_request_id;
                $locked->bulk_site_request_id = null;
            }
            $locked->save();

            // www/apex twins still pending on the old batch cannot be Done
            // (domain now belongs to the claimer) and would block a new bulk.
            if ($linkedBulkId) {
                BulkSiteRequest::query()->lockForUpdate()->find($linkedBulkId)
                    ?->releaseItemsForTransferredSite($locked);
            }

            if (! $claimer->hasRole('publisher')) {
                $claimer->assignRole('publisher');
            }

            $lockedClaim->forceFill([
                'status' => 'approved',
                'admin_notes' => $adminNotes ?? $lockedClaim->admin_notes,
                'reviewed_at' => now(),
                'reviewed_by' => $admin->id,
            ])->save();

            $siblings = SiteClaim::query()
                ->where('site_id', $locked->id)
                ->where('id', '!=', $lockedClaim->id)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->get();

            foreach ($siblings as $sibling) {
                $sibling->forceFill([
                    'status' => 'rejected',
                    'admin_notes' => 'Closed because another claim was approved.',
                    'reviewed_at' => now(),
                    'reviewed_by' => $admin->id,
                ])->save();
                $closedSiblings[] = $sibling;
            }

            ActivityLogger::log(
                'site.claim_approved',
                $admin->name.' approved site claim #'.$lockedClaim->id.' (publisher '.$previousPublisherId.' → '.$claimer->id.')',
                $locked,
                [
                    'claim_id' => $lockedClaim->id,
                    'previous_publisher_id' => $previousPublisherId,
                    'new_publisher_id' => $claimer->id,
                ],
                $locked->site_name
            );
        });

        if ($linkedBulkId) {
            BulkSiteRequest::query()->find($linkedBulkId)?->refreshProgressStatus();
        }

        $claim->refresh()->load(['site', 'claimer', 'reviewer']);
        $this->notifyApproved($claim, $previousPublisher);

        foreach ($closedSiblings as $sibling) {
            $sibling->loadMissing(['site', 'claimer']);
            $this->notifyRejected($sibling);
        }

        return $claim;
    }

    /**
     * @throws ValidationException
     */
    public function reject(SiteClaim $claim, User $admin, ?string $adminNotes = null): SiteClaim
    {
        DB::transaction(function () use ($claim, $admin, $adminNotes) {
            $lockedClaim = SiteClaim::query()->lockForUpdate()->findOrFail($claim->id);

            if ($lockedClaim->status !== 'pending') {
                throw ValidationException::withMessages([
                    'claim' => 'This claim was already reviewed.',
                ]);
            }

            $lockedClaim->forceFill([
                'status' => 'rejected',
                'admin_notes' => $adminNotes ?? $lockedClaim->admin_notes,
                'reviewed_at' => now(),
                'reviewed_by' => $admin->id,
            ])->save();

            ActivityLogger::log(
                'site.claim_rejected',
                $admin->name.' rejected site claim #'.$lockedClaim->id,
                $lockedClaim->site,
                ['claim_id' => $lockedClaim->id],
                $lockedClaim->website_name
            );
        });

        $claim->refresh()->load(['site', 'claimer', 'reviewer']);
        $this->notifyRejected($claim);

        return $claim;
    }

    public function notifySubmitted(SiteClaim $claim): void
    {
        $claim->loadMissing(['site', 'claimer']);

        try {
            $this->notifications->notifyAdminsSiteClaimSubmitted($claim);
        } catch (\Throwable $e) {
            Log::warning('Failed to bell admins about site claim: '.$e->getMessage(), [
                'claim_id' => $claim->id,
            ]);
        }

        try {
            $admins = User::query()
                ->whereHas('roles', fn ($q) => $q->where('name', 'admin'))
                ->get();
            $recipients = $admins->isNotEmpty()
                ? $admins
                : collect([(object) ['email' => config('mail.admin_email')]]);

            foreach ($recipients as $admin) {
                if (empty($admin->email)) {
                    continue;
                }

                $mailable = new SiteClaimSubmitted($claim);
                if ($admin instanceof User) {
                    $mailable->recipientUser = $admin;
                    $mailable->dedupeKey = 'site-claim-submitted-'.$claim->id.':admin:'.$admin->id;
                } else {
                    $mailable->dedupeKey = 'site-claim-submitted-'.$claim->id.':fallback:'.strtolower((string) $admin->email);
                }

                Mail::to($admin->email)->send($mailable);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to email admins about site claim: '.$e->getMessage(), [
                'claim_id' => $claim->id,
            ]);
        }
    }

    private function notifyApproved(SiteClaim $claim, ?User $previousPublisher): void
    {
        try {
            $this->notifications->notifyClaimerSiteClaimReviewed($claim);
        } catch (\Throwable $e) {
            Log::warning('Failed to bell claimer about approved claim: '.$e->getMessage());
        }

        try {
            if ($claim->claimer?->email) {
                Mail::to($claim->claimer->email)->send(new SiteClaimReviewed($claim));
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to email claimer about approved claim: '.$e->getMessage());
        }

        if ($previousPublisher && (int) $previousPublisher->id !== (int) $claim->claimer_id) {
            try {
                $this->notifications->notifyPreviousPublisherOwnershipTransferred($claim, $previousPublisher);
            } catch (\Throwable $e) {
                Log::warning('Failed to bell previous publisher about claim transfer: '.$e->getMessage());
            }

            try {
                if ($previousPublisher->email) {
                    Mail::to($previousPublisher->email)->send(
                        new SiteClaimOwnershipTransferred($claim, $previousPublisher)
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to email previous publisher about claim transfer: '.$e->getMessage());
            }
        }
    }

    private function notifyRejected(SiteClaim $claim): void
    {
        try {
            $this->notifications->notifyClaimerSiteClaimReviewed($claim);
        } catch (\Throwable $e) {
            Log::warning('Failed to bell claimer about rejected claim: '.$e->getMessage());
        }

        try {
            if ($claim->claimer?->email) {
                Mail::to($claim->claimer->email)->send(new SiteClaimReviewed($claim));
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to email claimer about rejected claim: '.$e->getMessage());
        }
    }
}
