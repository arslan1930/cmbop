<?php

namespace App\Services\Mcp;

use App\Models\AdvertiserSavedCartItem;
use App\Models\Site;
use App\Models\User;
use App\Models\UserFavorite;
use Illuminate\Support\Facades\Schema;

class SavedCartSync
{
    public function addFavorite(User $user, Site $site): bool
    {
        if ($site->isOwnedBy($user) || ! $site->isCatalogVisible()) {
            return false;
        }

        UserFavorite::query()->firstOrCreate([
            'user_id' => $user->id,
            'site_id' => $site->id,
        ]);

        return true;
    }

    public function addCartItem(User $user, Site $site, int $quantity = 1): bool
    {
        if ($site->isOwnedBy($user) || ! $site->isCatalogVisible()) {
            return false;
        }

        $quantity = max(1, min(10, $quantity));

        AdvertiserSavedCartItem::query()->updateOrCreate(
            ['user_id' => $user->id, 'site_id' => $site->id],
            ['quantity' => $quantity],
        );

        $this->mergeIntoSession($user);

        return true;
    }

    public function mergeIntoSession(?User $user = null): void
    {
        $user ??= auth()->user();
        if (! $user instanceof User || ! Schema::hasTable('advertiser_saved_cart_items')) {
            return;
        }

        try {
            $session = session();
        } catch (\Throwable) {
            return;
        }

        $cart = $session->get('cart', []);
        if (! is_array($cart)) {
            $cart = [];
        }

        $existing = [];
        foreach ($cart as $row) {
            if (is_array($row) && isset($row['id'])) {
                $existing[(int) $row['id']] = true;
            }
        }

        $saved = AdvertiserSavedCartItem::query()
            ->where('user_id', $user->id)
            ->get();

        $changed = false;
        foreach ($saved as $item) {
            $siteId = (int) $item->site_id;
            if ($siteId <= 0 || isset($existing[$siteId])) {
                continue;
            }

            $cart[] = [
                'id' => $siteId,
                'quantity' => max(1, (int) $item->quantity),
                'source' => 'mcp',
            ];
            $existing[$siteId] = true;
            $changed = true;
        }

        if ($changed) {
            $session->put('cart', array_values($cart));
        }
    }
}
