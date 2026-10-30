<?php

namespace App\Services\Advertiser;

use App\Models\AdvertiserSavedCartItem;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Server-side mirror of the advertiser session cart.
 *
 * The live UX stays session-based. Logged-in advertisers also get a DB copy
 * (site + quantity + last change) so abandoned-cart reminders can run after
 * the session is gone. Timestamps move only when the item set changes, so a
 * routine saveCart of the same sites does not reset the 24h clock.
 */
class AdvertiserCartPersistence
{
    public const TABLE = 'advertiser_saved_cart_items';

    /**
     * @param  array<int, mixed>|null  $cart
     */
    public function persistFromSession(?User $user = null, ?array $cart = null): void
    {
        $user ??= auth()->user();
        if (! $user instanceof User || ! $this->tableReady()) {
            return;
        }

        if ($cart === null) {
            try {
                $cart = session('cart', []);
            } catch (\Throwable) {
                return;
            }
        }

        if (! is_array($cart)) {
            $cart = [];
        }

        $this->replaceItems($user, $this->quantitiesFromCart($cart));
    }

    /**
     * @param  array<int, int>  $quantities  site_id => quantity
     */
    public function replaceItems(User $user, array $quantities): void
    {
        if (! $this->tableReady()) {
            return;
        }

        $normalized = [];
        foreach ($quantities as $siteId => $quantity) {
            $siteId = (int) $siteId;
            $quantity = max(1, min(10, (int) $quantity));
            if ($siteId <= 0) {
                continue;
            }
            $normalized[$siteId] = ($normalized[$siteId] ?? 0) + $quantity;
            $normalized[$siteId] = min(10, $normalized[$siteId]);
        }
        ksort($normalized);

        try {
            $existing = AdvertiserSavedCartItem::query()
                ->where('user_id', $user->id)
                ->get();
        } catch (\Throwable $e) {
            Log::warning('Advertiser cart persist read failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $current = [];
        foreach ($existing as $item) {
            $current[(int) $item->site_id] = max(1, (int) $item->quantity);
        }
        ksort($current);

        if ($current === $normalized) {
            return;
        }

        $keep = array_keys($normalized);

        try {
            if ($keep === []) {
                AdvertiserSavedCartItem::query()->where('user_id', $user->id)->delete();

                return;
            }

            AdvertiserSavedCartItem::query()
                ->where('user_id', $user->id)
                ->whereNotIn('site_id', $keep)
                ->delete();

            foreach ($normalized as $siteId => $quantity) {
                AdvertiserSavedCartItem::query()->updateOrCreate(
                    ['user_id' => $user->id, 'site_id' => $siteId],
                    ['quantity' => $quantity],
                );
            }
        } catch (\Throwable $e) {
            Log::warning('Advertiser cart persist write failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function clear(?User $user = null): void
    {
        $user ??= auth()->user();
        if (! $user instanceof User) {
            return;
        }

        $this->replaceItems($user, []);
    }

    /**
     * @return array<int, int> site_id => quantity
     */
    public function quantitiesForUser(User|int $user): array
    {
        if (! $this->tableReady()) {
            return [];
        }

        $userId = $user instanceof User ? (int) $user->id : (int) $user;

        try {
            $rows = AdvertiserSavedCartItem::query()
                ->where('user_id', $userId)
                ->orderBy('site_id')
                ->get(['site_id', 'quantity']);
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $siteId = (int) $row->site_id;
            if ($siteId <= 0) {
                continue;
            }
            $out[$siteId] = max(1, min(10, (int) $row->quantity));
        }

        return $out;
    }

    /**
     * @param  array<int, int>  $quantities
     */
    public function fingerprint(array $quantities): string
    {
        ksort($quantities);
        $parts = [];
        foreach ($quantities as $siteId => $quantity) {
            $siteId = (int) $siteId;
            $quantity = max(1, min(10, (int) $quantity));
            if ($siteId <= 0) {
                continue;
            }
            $parts[] = $siteId.':'.$quantity;
        }

        return $parts === [] ? '' : sha1(implode(',', $parts));
    }

    public function lastActivityAt(User|int $user): ?Carbon
    {
        if (! $this->tableReady()) {
            return null;
        }

        $userId = $user instanceof User ? (int) $user->id : (int) $user;

        try {
            $latest = AdvertiserSavedCartItem::query()
                ->where('user_id', $userId)
                ->max('updated_at');
        } catch (\Throwable) {
            return null;
        }

        if ($latest === null || $latest === '') {
            return null;
        }

        try {
            return Carbon::parse($latest);
        } catch (\Throwable) {
            return null;
        }
    }

    public function tableReady(): bool
    {
        try {
            return Schema::hasTable(self::TABLE);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  array<int, mixed>  $cart
     * @return array<int, int>
     */
    public function quantitiesFromCart(array $cart): array
    {
        $quantities = [];
        foreach ($cart as $row) {
            if (! is_array($row) || ! isset($row['id'])) {
                continue;
            }
            $siteId = (int) $row['id'];
            if ($siteId <= 0) {
                continue;
            }
            $quantities[$siteId] = ($quantities[$siteId] ?? 0) + max(1, (int) ($row['quantity'] ?? 1));
            $quantities[$siteId] = min(10, $quantities[$siteId]);
        }

        return $quantities;
    }
}
