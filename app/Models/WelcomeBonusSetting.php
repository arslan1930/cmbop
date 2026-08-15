<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WelcomeBonusSetting extends Model
{
    protected $fillable = ['key', 'value'];

    protected $casts = [
        'value' => 'array',
    ];

    public static function getValue(string $key, mixed $default = null): mixed
    {
        if (! Schema::hasTable((new static)->getTable())) {
            return $default;
        }

        try {
            $row = static::query()->where('key', $key)->orderBy('id')->first();

            return $row?->value ?? $default;
        } catch (\Throwable) {
            return $default;
        }
    }

    public static function setValue(string $key, mixed $value): void
    {
        if (! Schema::hasTable((new static)->getTable())) {
            return;
        }

        $row = static::query()->where('key', $key)->orderBy('id')->first();
        if ($row === null) {
            static::query()->create(['key' => $key, 'value' => $value]);
        } else {
            $row->value = $value;
            $row->save();
            static::query()->where('key', $key)->where('id', '!=', $row->id)->delete();
        }
        Cache::forget('welcome_bonus_setting:'.$key);
    }

    public static function config(): array
    {
        $defaultOn = static::parseEnabledFlag(config('welcome_bonus.enabled_default', true), true);
        $read = static::readConfig(false);

        if ($read['state'] === 'missing') {
            return ['enabled' => $defaultOn];
        }

        if ($read['state'] === 'unreadable' || ! is_array($read['value'])) {
            return ['enabled' => false];
        }

        $stored = $read['value'];
        if (! array_key_exists('enabled', $stored)) {
            $stored['enabled'] = false;
        }

        return $stored;
    }

    public static function isEnabled(): bool
    {
        return static::readEnabled(false);
    }

    /**
     * Kill-switch read for the signup transaction. Locks the settings row so
     * an admin Disable cannot commit underneath recordClaim().
     */
    public static function isEnabledForGrant(): bool
    {
        return static::readEnabled(true);
    }

    /**
     * Accept bools and common string/int flags. Non-scalars must not throw —
     * filter_var() TypeErrors would roll back every signup.
     */
    public static function parseEnabledFlag(mixed $raw, bool $default = false): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }

        if (is_int($raw) || is_float($raw) || is_string($raw)) {
            return filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
        }

        return $default;
    }

    public static function setEnabled(bool $enabled, ?int $updatedBy = null): void
    {
        if (! Schema::hasTable((new static)->getTable())) {
            return;
        }

        $write = function () use ($enabled, $updatedBy): void {
            DB::transaction(function () use ($enabled, $updatedBy) {
                $rows = static::configRows(true);
                $keep = $rows->first();
                $current = static::payloadForWrite($rows, $keep);

                $current['enabled'] = $enabled;
                $current['updated_at'] = now()->toIso8601String();
                if ($updatedBy !== null) {
                    $current['updated_by'] = $updatedBy;
                }

                if ($keep === null) {
                    static::query()->create(['key' => 'config', 'value' => $current]);
                } else {
                    $keep->value = $current;
                    $keep->save();
                    static::query()->where('key', 'config')->where('id', '!=', $keep->id)->delete();
                }
                Cache::forget('welcome_bonus_setting:config');
            });
        };

        try {
            $write();
        } catch (UniqueConstraintViolationException) {
            // First grant may insert the settings row in the same window.
            $write();
        }
    }

    public static function maxAmount(): float
    {
        return round(max(0, (float) config('welcome_bonus.max_amount', 500)), 2);
    }

    public static function normalizeAmount(mixed $amount): float
    {
        if (! is_numeric($amount)) {
            return 0.0;
        }

        return round(max(0, min((float) $amount, static::maxAmount())), 2);
    }

    /**
     * Live grant ceiling: stored admin amount when present, else config default.
     * Always clamped to max_amount so a corrupt settings row cannot mint more.
     */
    public static function configuredAmount(): float
    {
        $stored = static::config();
        if (is_array($stored) && array_key_exists('amount', $stored)) {
            // A present but unreadable amount must not fall back to €20.
            return is_numeric($stored['amount'])
                ? static::normalizeAmount($stored['amount'])
                : 0.0;
        }

        return static::normalizeAmount(config('welcome_bonus.amount', 20));
    }

    public static function setAmount(float $amount, ?int $updatedBy = null): void
    {
        if (! Schema::hasTable((new static)->getTable())) {
            return;
        }

        $amount = static::normalizeAmount($amount);

        $write = function () use ($amount, $updatedBy): void {
            DB::transaction(function () use ($amount, $updatedBy) {
                $rows = static::configRows(true);
                $keep = $rows->first();
                $current = static::payloadForWrite($rows, $keep);

                // Do not default a missing enabled flag to on — that would
                // undo Disable (or fail-closed) when collapsing duplicates.
                $current['enabled'] = static::enabledAfterAmountWrite($rows, $keep);
                $current['amount'] = $amount;
                $current['updated_at'] = now()->toIso8601String();
                if ($updatedBy !== null) {
                    $current['updated_by'] = $updatedBy;
                }

                if ($keep === null) {
                    static::query()->create(['key' => 'config', 'value' => $current]);
                } else {
                    $keep->value = $current;
                    $keep->save();
                    static::query()->where('key', 'config')->where('id', '!=', $keep->id)->delete();
                }
                Cache::forget('welcome_bonus_setting:config');
            });
        };

        try {
            $write();
        } catch (UniqueConstraintViolationException) {
            $write();
        }
    }

    public static function clearCache(): void
    {
        Cache::forget('welcome_bonus_setting:config');
    }

    private static function readEnabled(bool $lock): bool
    {
        $defaultOn = static::parseEnabledFlag(config('welcome_bonus.enabled_default', true), true);
        $read = static::readConfig($lock);

        // Missing table / never configured: fail-open so Hostinger drift
        // cannot block the €20 grant. A present row that cannot be trusted
        // fails closed so Disable cannot be undone by corrupt JSON.
        if ($read['state'] === 'missing') {
            return $defaultOn;
        }

        if ($read['state'] === 'unreadable') {
            return false;
        }

        $stored = $read['value'];
        if (! is_array($stored) || ! array_key_exists('enabled', $stored)) {
            return false;
        }

        return static::parseEnabledFlag($stored['enabled'], false);
    }

    /**
     * @return array{state: 'missing'|'unreadable'|'present', value: mixed}
     */
    private static function readConfig(bool $lock): array
    {
        try {
            if (! Schema::hasTable((new static)->getTable())) {
                return ['state' => 'missing', 'value' => null];
            }

            $rows = static::configRows($lock);
            if ($rows->isEmpty() && $lock) {
                $defaultOn = static::parseEnabledFlag(config('welcome_bonus.enabled_default', true), true);
                try {
                    static::query()->create([
                        'key' => 'config',
                        'value' => ['enabled' => $defaultOn],
                    ]);
                } catch (UniqueConstraintViolationException) {
                    // Another grant or Disable created the row first.
                }
                $rows = static::configRows(true);
            }
            if ($rows->isEmpty()) {
                // Table exists but we could not read or create a config row
                // while holding the grant lock. Fail-closed so we do not
                // grant without the settings-row mutex. Unlocked reads
                // (hub / amountFor) still treat "never configured" as on.
                return ['state' => $lock ? 'unreadable' : 'missing', 'value' => null];
            }

            return ['state' => 'present', 'value' => static::authoritativeConfigValue($rows)];
        } catch (\Throwable) {
            return ['state' => 'unreadable', 'value' => null];
        }
    }

    /**
     * @return Collection<int, static>
     */
    private static function configRows(bool $lock)
    {
        $query = static::query()->where('key', 'config')->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    /**
     * Duplicate config rows (no unique on key) can leave Disable on one row
     * and enabled=true on another. Any explicit off wins so Disable sticks.
     *
     * A later ON row with no amount (grant-created leftover) must not wipe an
     * earlier explicit amount — that falls back to €20 and undoes Set amount 0.
     *
     * @param  Collection<int, static>  $rows
     */
    private static function authoritativeConfigValue($rows): mixed
    {
        $latestOn = null;
        $carriedAmount = null;
        foreach ($rows as $row) {
            try {
                $value = $row->value;
            } catch (\Throwable) {
                return null;
            }

            if (! is_array($value) || ! array_key_exists('enabled', $value)) {
                return is_array($value) ? $value : null;
            }

            if (! static::parseEnabledFlag($value['enabled'], false)) {
                return $value;
            }

            if (array_key_exists('amount', $value)) {
                $carriedAmount = $value['amount'];
            } elseif ($carriedAmount !== null) {
                $value['amount'] = $carriedAmount;
            }

            $latestOn = $value;
        }

        return $latestOn;
    }

    /**
     * Collapse onto the oldest row using the same payload reads trust, so
     * Enable/Disable cannot resurrect a stale higher amount (or wipe €0).
     *
     * @param  Collection<int, static>  $rows
     */
    private static function payloadForWrite($rows, ?self $keep): array
    {
        try {
            $current = is_array($keep?->value) ? $keep->value : [];
        } catch (\Throwable) {
            $current = [];
        }

        if ($keep === null || $rows->isEmpty()) {
            return $current;
        }

        $authoritative = static::authoritativeConfigValue($rows);
        if (is_array($authoritative)) {
            $current = array_merge($current, $authoritative);
        }

        if (is_array($authoritative) && array_key_exists('amount', $authoritative)) {
            $current['amount'] = is_numeric($authoritative['amount'])
                ? static::normalizeAmount($authoritative['amount'])
                : 0.0;
        } else {
            unset($current['amount']);
        }

        return $current;
    }

    /**
     * Set amount must not turn the bonus back on. Duplicate rows: any
     * explicit off or unreadable enabled flag wins. Never configured: default on.
     *
     * @param  Collection<int, static>  $rows
     */
    private static function enabledAfterAmountWrite($rows, ?self $keep): bool
    {
        if ($keep === null || $rows->isEmpty()) {
            return static::parseEnabledFlag(config('welcome_bonus.enabled_default', true), true);
        }

        $authoritative = static::authoritativeConfigValue($rows);
        if (! is_array($authoritative) || ! array_key_exists('enabled', $authoritative)) {
            return false;
        }

        return static::parseEnabledFlag($authoritative['enabled'], false);
    }
}
