<?php

namespace App\Services\Auth;

use App\Models\StaffCapability;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Admin capability overlay. Marketing stays a separate role.
 *
 * No rows for an admin = unrestricted (today's god mode). Any rows = only those
 * capabilities. Table missing = unrestricted so Hostinger leftovers do not lock ops out.
 */
class StaffCapabilityService
{
    /** @var array<int, list<string>|null> */
    private array $cache = [];

    /**
     * @return list<string>
     */
    public function capabilities(User $user): array
    {
        $cached = $this->cached($user);
        if ($cached !== null) {
            return $cached;
        }

        return StaffCapability::ALL;
    }

    public function allows(User $user, string ...$capabilities): bool
    {
        if ($capabilities === []) {
            return false;
        }

        $have = $this->capabilities($user);
        foreach ($capabilities as $capability) {
            if (in_array($capability, $have, true)) {
                return true;
            }
        }

        return false;
    }

    public function isUnrestricted(User $user): bool
    {
        if (! $user->hasRole('admin')) {
            return false;
        }

        return $this->storedCapabilities($user) === [];
    }

    /**
     * @param  list<string>|null  $capabilities  null or [] = full admin
     * @return list<string>
     */
    public function sync(User $actor, User $target, ?array $capabilities): array
    {
        if (! $this->isUnrestricted($actor)) {
            throw new \RuntimeException('Only a full admin can change staff capabilities.');
        }
        if ((int) $actor->id === (int) $target->id) {
            throw new \RuntimeException('You cannot change your own capabilities.');
        }
        if (! $target->hasRole('admin')) {
            throw new \RuntimeException('Capabilities apply to admin accounts only. Marketing stays on the marketing role.');
        }

        StaffCapability::ensureTable();
        if (! StaffCapability::tableReady()) {
            throw new \RuntimeException('Staff capabilities cannot be saved on this database.');
        }

        $normalized = $this->normalize($capabilities);

        DB::transaction(function () use ($target, $normalized) {
            StaffCapability::query()->where('user_id', $target->id)->delete();
            foreach ($normalized as $capability) {
                StaffCapability::query()->create([
                    'user_id' => $target->id,
                    'capability' => $capability,
                ]);
            }
        });

        unset($this->cache[(int) $target->id]);

        ActivityLogger::tryLog(
            'staff_capabilities.updated',
            ($actor->name ?? 'Admin').' set capabilities for user #'.$target->id.': '.$this->label($normalized),
            $target,
            ['user_id' => $target->id, 'capabilities' => $normalized],
            $target->name
        );

        return $normalized;
    }

    /**
     * @return list<string>
     */
    public function storedCapabilities(User $user): array
    {
        $id = (int) $user->id;
        if (array_key_exists($id, $this->cache)) {
            return $this->cache[$id] ?? [];
        }

        if (! $user->hasRole('admin') || ! StaffCapability::tableReady()) {
            $this->cache[$id] = [];

            return [];
        }

        try {
            $rows = StaffCapability::query()
                ->where('user_id', $id)
                ->pluck('capability')
                ->all();
        } catch (\Throwable) {
            $this->cache[$id] = [];

            return [];
        }

        $stored = $this->normalize($rows);
        $this->cache[$id] = $stored;

        return $stored;
    }

    /**
     * @return list<string>|null null means "use the default full set"
     */
    private function cached(User $user): ?array
    {
        if (! $user->hasRole('admin')) {
            return [];
        }

        $stored = $this->storedCapabilities($user);
        if ($stored === []) {
            return null;
        }

        return $stored;
    }

    /**
     * @param  list<string>|null  $capabilities
     * @return list<string>
     */
    public function normalize(?array $capabilities): array
    {
        if ($capabilities === null || $capabilities === []) {
            return [];
        }

        $allowed = [];
        foreach ($capabilities as $capability) {
            if (! is_string($capability)) {
                continue;
            }
            $capability = strtolower(trim($capability));
            if (in_array($capability, StaffCapability::ALL, true)) {
                $allowed[] = $capability;
            }
        }

        return array_values(array_unique($allowed));
    }

    /**
     * @param  list<string>  $capabilities
     */
    public function label(array $capabilities): string
    {
        if ($capabilities === []) {
            return 'full admin';
        }

        $labels = [
            StaffCapability::FINANCE => 'finance',
            StaffCapability::SUPPORT => 'support',
        ];

        return implode(', ', array_map(fn (string $cap) => $labels[$cap] ?? $cap, $capabilities));
    }
}
