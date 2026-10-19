<?php

namespace App\Services\Partners;

use App\Models\Partner;
use App\Models\PartnerConnection;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\Schema;

class PartnerAccountService
{
    public function mark(User $publisher, User $actor, array $attrs = []): Partner
    {
        $partner = Partner::query()->firstOrNew(['user_id' => $publisher->id]);
        $created = ! $partner->exists;
        $partner->status = $attrs['status'] ?? Partner::STATUS_ACTIVE;
        if (array_key_exists('trusted', $attrs)) {
            $partner->trusted = (bool) $attrs['trusted'];
        }
        if (array_key_exists('agreement_signed', $attrs)) {
            $partner->agreement_signed = (bool) $attrs['agreement_signed'];
            $partner->agreement_signed_at = $partner->agreement_signed ? ($partner->agreement_signed_at ?? now()) : null;
        }
        if (array_key_exists('legal_name', $attrs)) {
            $partner->legal_name = $attrs['legal_name'];
        }
        $partner->save();

        ActivityLogger::tryLog(
            $created ? 'partner.created' : 'partner.updated',
            ($created ? 'Marked' : 'Updated').' publisher as Teqno LTD catalog partner',
            $partner,
            ['user_id' => $publisher->id],
            null,
            $actor
        );

        return $partner;
    }

    public function saveFeedUrl(Partner $partner, ?string $url, User $actor): PartnerConnection
    {
        $connection = $partner->feedConnection()->first();
        if (! $connection) {
            $connection = new PartnerConnection([
                'partner_id' => $partner->id,
                'type' => PartnerConnection::TYPE_FEED_URL,
            ]);
        }
        $url = trim((string) $url);
        $connection->feed_url = $url !== '' ? $url : null;
        $connection->status = $url !== '' ? PartnerConnection::STATUS_ACTIVE : PartnerConnection::STATUS_PAUSED;
        $connection->schedule = 'daily';
        $connection->save();
        ActivityLogger::tryLog('partner.feed.updated', 'Updated partner feed URL', $partner, [], null, $actor);

        return $connection;
    }

    public static function tablesReady(): bool
    {
        try {
            return Schema::hasTable('partners') && Schema::hasTable('partner_catalog_items');
        } catch (\Throwable) {
            return false;
        }
    }
}
