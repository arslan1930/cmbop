<?php

namespace App\Services\Partners;

use App\Models\PartnerEmailActionToken;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PartnerEmailActionService
{
    /** @param  list<string>  $actions */
    public function issue(User $user, int $orderItemId, array $actions = ['view']): string
    {
        $plain = Str::random(64);
        PartnerEmailActionToken::query()->create([
            'user_id' => $user->id,
            'order_item_id' => $orderItemId,
            'token_hash' => hash('sha256', $plain),
            'actions' => array_values($actions),
            'expires_at' => now()->addHours(max(1, (int) config('partners.dashboard_action_link_hours', 72))),
            'bound_email' => $user->email,
            'bound_password_hash' => (string) $user->password,
            'created_ip' => request()?->ip(),
        ]);

        return $plain;
    }

    public function consume(string $plain, ?string $ip = null, ?string $userAgent = null): ?PartnerEmailActionToken
    {
        if ($plain === '' || ! Schema::hasTable('partner_email_action_tokens')) {
            return null;
        }
        $hash = hash('sha256', $plain);

        return DB::transaction(function () use ($hash, $ip, $userAgent) {
            $token = PartnerEmailActionToken::query()
                ->where('token_hash', $hash)
                ->lockForUpdate()
                ->first();
            if (! $token || ! $token->isOpen()) {
                return null;
            }
            $user = $token->user;
            if (! $user) {
                return null;
            }
            if (! hash_equals((string) $token->bound_email, (string) $user->email)
                || ! hash_equals((string) $token->bound_password_hash, (string) $user->password)) {
                $token->forceFill(['revoked_at' => now()])->save();

                return null;
            }
            $token->forceFill([
                'used_at' => now(),
                'used_ip' => $ip,
                'used_user_agent' => $userAgent,
            ])->save();

            ActivityLogger::tryLog(
                'partner.email_action.used',
                'Partner one-click order link used',
                $token,
                ['order_item_id' => $token->order_item_id, 'ip' => $ip],
                null,
                $user
            );

            return $token;
        });
    }

    public function revokeAllForUser(User $user): void
    {
        if (! Schema::hasTable('partner_email_action_tokens')) {
            return;
        }
        PartnerEmailActionToken::query()
            ->where('user_id', $user->id)
            ->whereNull('used_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }
}
