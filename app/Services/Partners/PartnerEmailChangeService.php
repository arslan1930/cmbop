<?php

namespace App\Services\Partners;

use App\Mail\PartnerEmailChangeCancelMail;
use App\Mail\PartnerEmailChangeConfirmMail;
use App\Models\Partner;
use App\Models\PartnerEmailChangeRequest;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\PartnerDashboard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PartnerEmailChangeService
{
    public function request(User $user, string $newEmail): PartnerEmailChangeRequest
    {
        $newEmail = strtolower(trim($newEmail));
        PartnerEmailChangeRequest::query()
            ->where('user_id', $user->id)
            ->whereNull('confirmed_at')
            ->whereNull('cancelled_at')
            ->update(['cancelled_at' => now()]);

        $confirm = Str::random(64);
        $cancel = Str::random(64);
        $row = PartnerEmailChangeRequest::query()->create([
            'user_id' => $user->id,
            'old_email' => $user->email,
            'new_email' => $newEmail,
            'confirm_hash' => hash('sha256', $confirm),
            'cancel_hash' => hash('sha256', $cancel),
            'expires_at' => now()->addHours(max(1, (int) config('partners.dashboard_email_change_hours', 24))),
        ]);

        Mail::to($newEmail)->send(new PartnerEmailChangeConfirmMail($user, $row, $confirm));
        Mail::to($user->email)->send(new PartnerEmailChangeCancelMail($user, $row, $cancel));

        ActivityLogger::tryLog('partner.email_change.requested', 'Partner requested an email change', $row, [
            'old_email' => $user->email,
        ], null, $user);

        return $row;
    }

    public function confirm(string $plain): ?User
    {
        $row = $this->findByHash('confirm_hash', $plain);
        if (! $row || ! $row->isPending()) {
            return null;
        }
        $user = $row->user;
        if (! $user) {
            return null;
        }

        $user->email = $row->new_email;
        if (method_exists($user, 'hasVerifiedEmail')) {
            $user->email_verified_at = now();
        }
        $user->save();
        $row->forceFill(['confirmed_at' => now()])->save();
        app(PartnerEmailActionService::class)->revokeAllForUser($user);
        $this->invalidateSessions($user);
        ActivityLogger::tryLog('partner.email_change.confirmed', 'Partner email change confirmed', $row, [], null, $user);

        return $user->fresh();
    }

    public function cancel(string $plain): ?User
    {
        $row = $this->findByHash('cancel_hash', $plain);
        if (! $row || ! $row->isPending()) {
            return null;
        }
        $user = $row->user;
        if (! $user) {
            return null;
        }
        $row->forceFill(['cancelled_at' => now()])->save();
        app(PartnerEmailActionService::class)->revokeAllForUser($user);
        $this->invalidateSessions($user);
        $partner = Partner::forUser($user);
        if ($partner && Schema::hasColumn('partners', 'security_review_at')) {
            $partner->forceFill(['security_review_at' => now()])->save();
        }
        ActivityLogger::tryLog('partner.email_change.cancelled', 'Partner email change cancelled from old address', $row, [], null, $user);

        return $user;
    }

    private function findByHash(string $column, string $plain): ?PartnerEmailChangeRequest
    {
        if ($plain === '' || ! Schema::hasTable('partner_email_change_requests')) {
            return null;
        }

        return PartnerEmailChangeRequest::query()
            ->where($column, hash('sha256', $plain))
            ->first();
    }

    private function invalidateSessions(User $user): void
    {
        try {
            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
        } catch (\Throwable) {
        }
    }

    public function confirmUrl(string $plain): string
    {
        return PartnerDashboard::origin().'/email-change/confirm/'.$plain;
    }

    public function cancelUrl(string $plain): string
    {
        return PartnerDashboard::origin().'/email-change/cancel/'.$plain;
    }
}
