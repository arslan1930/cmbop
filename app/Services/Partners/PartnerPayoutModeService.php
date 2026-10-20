<?php

namespace App\Services\Partners;

use App\Models\Partner;
use App\Models\PartnerPayoutModeAcceptance;
use App\Models\PartnerPayoutModeChange;
use App\Models\User;
use App\Services\ActivityLogger;
use RuntimeException;

class PartnerPayoutModeService
{
    public const KEEP_LIVE_VERSION = 'KLP-1.0';

    public const KEEP_LIVE_TEXT = 'For every order I fulfil through SEOLinkBuildings, I will keep the published article and its link(s) live, unchanged and with the agreed link attributes for at least 12 months from the date I submit the live link. I accept that SLB checks the links regularly. If an article or link is removed, changed or made nofollow during that period and not restored within 48 hours of SLB\'s notice, the amount SLB paid me for that order is owed back to Teqno LTD and may be offset against my future earnings; if it cannot be offset within 30 days, I will repay it on request. I accept that SLB may switch my account to held payouts or suspend it after repeated removals.';

    public function storeKeepLiveAcceptance(Partner $partner, User $user, ?string $ip = null): PartnerPayoutModeAcceptance
    {
        return PartnerPayoutModeAcceptance::query()->create([
            'partner_id' => $partner->id,
            'user_id' => $user->id,
            'terms_type' => PartnerPayoutModeAcceptance::KEEP_LIVE,
            'terms_version' => self::KEEP_LIVE_VERSION,
            'terms_text_sha256' => hash('sha256', self::KEEP_LIVE_TEXT),
            'keep_live_months' => 12,
            'accepted_at' => now(),
            'ip' => $ip,
            'method' => 'click_accept',
        ]);
    }

    public function switchToInstant(Partner $partner, User $admin, string $reason, ?User $second = null): Partner
    {
        $acceptance = PartnerPayoutModeAcceptance::query()
            ->where('partner_id', $partner->id)
            ->where('terms_type', PartnerPayoutModeAcceptance::KEEP_LIVE)
            ->whereNull('revoked_at')
            ->latest('id')
            ->first();
        if (! $acceptance) {
            throw new RuntimeException('A stored keep-live acceptance is required before instant payouts.');
        }
        $from = PartnerChannel::payoutMode($partner);
        $acceptance->approved_by = $admin->id;
        $acceptance->approved_at = now();
        $acceptance->save();
        $partner->payout_mode = PartnerChannel::MODE_INSTANT;
        $partner->payout_mode_effective_from = now();
        $partner->payout_mode_requested = null;
        $partner->save();
        PartnerPayoutModeChange::query()->create([
            'partner_id' => $partner->id,
            'from_mode' => $from,
            'to_mode' => PartnerChannel::MODE_INSTANT,
            'reason' => $reason,
            'approved_by' => $admin->id,
            'second_approver' => $second?->id,
            'effective_from' => $partner->payout_mode_effective_from,
        ]);
        ActivityLogger::tryLog('partner.payout_mode.changed', 'Partner payout mode set to instant', $partner, [
            'from' => $from,
            'to' => PartnerChannel::MODE_INSTANT,
            'reason' => $reason,
        ], null, $admin);
        app(PartnerWebhookService::class)->emit($partner, 'partner.payout_mode_changed', [
            'object' => ['payout_mode' => PartnerChannel::MODE_INSTANT],
        ]);

        return $partner->fresh();
    }

    public function switchToHeld(Partner $partner, ?User $admin, string $reason): Partner
    {
        $from = PartnerChannel::payoutMode($partner);
        $partner->payout_mode = PartnerChannel::MODE_HELD;
        $partner->payout_mode_effective_from = now();
        $partner->save();
        PartnerPayoutModeChange::query()->create([
            'partner_id' => $partner->id,
            'from_mode' => $from,
            'to_mode' => PartnerChannel::MODE_HELD,
            'reason' => $reason,
            'approved_by' => $admin?->id,
            'effective_from' => $partner->payout_mode_effective_from,
        ]);
        ActivityLogger::tryLog('partner.payout_mode.changed', 'Partner payout mode set to held', $partner, [
            'from' => $from,
            'to' => PartnerChannel::MODE_HELD,
            'reason' => $reason,
        ], null, $admin);
        app(PartnerWebhookService::class)->emit($partner, 'partner.payout_mode_changed', [
            'object' => ['payout_mode' => PartnerChannel::MODE_HELD],
        ]);

        return $partner->fresh();
    }

    public function requestInstant(Partner $partner, User $user): void
    {
        $this->storeKeepLiveAcceptance($partner, $user, request()?->ip());
        $partner->payout_mode_requested = PartnerChannel::MODE_INSTANT;
        $partner->save();
    }
}
