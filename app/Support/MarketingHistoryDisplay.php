<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\BulkSiteRequest;
use App\Models\Site;
use App\Models\User;

/**
 * Batch lookups and row fields for marketing task history (no per-row exists()).
 */
class MarketingHistoryDisplay
{
    /** @var array<string, string> */
    private const CHANGE_KEY_LABELS = [
        'site_name' => 'Name',
        'site_url' => 'URL',
        'domain' => 'Domain',
        'da' => 'DA',
        'dr' => 'DR',
        'traffic' => 'Traffic',
        'price' => 'Price',
        'language' => 'Language',
        'country' => 'Country',
        'category' => 'Niches',
        'categories' => 'Niches',
        'active' => 'Active',
        'verified' => 'Verified',
        'description' => 'Description',
        'site_image' => 'Image',
        'link_type' => 'Link type',
        'publication_time' => 'Publication',
    ];

    /**
     * @param  iterable<int, ActivityLog>  $logs
     * @return array{
     *     existingSiteIds: array<int, true>,
     *     existingBulkIds: array<int, true>,
     *     sitePublishers: array<int, int>,
     *     bulkPublishers: array<int, int>,
     *     publishers: array<int, array{name: string, email: ?string}>
     * }
     */
    public static function preload(iterable $logs): array
    {
        $siteIds = [];
        $bulkIds = [];
        $publisherIds = [];

        foreach ($logs as $log) {
            $type = (string) $log->subject_type;
            $id = (int) $log->subject_id;
            if ($id > 0) {
                if ($type === Site::class) {
                    $siteIds[$id] = $id;
                }
                if ($type === BulkSiteRequest::class) {
                    $bulkIds[$id] = $id;
                }
            }

            $props = is_array($log->properties) ? $log->properties : [];
            $propSite = (int) data_get($props, 'site_id');
            $propBulk = (int) data_get($props, 'bulk_site_request_id');
            $propPub = (int) data_get($props, 'publisher_id');
            if ($propSite > 0) {
                $siteIds[$propSite] = $propSite;
            }
            if ($propBulk > 0) {
                $bulkIds[$propBulk] = $propBulk;
            }
            if ($propPub > 0) {
                $publisherIds[$propPub] = $propPub;
            }
        }

        $existingSiteIds = [];
        $sitePublishers = [];
        if ($siteIds !== []) {
            foreach (Site::query()->whereIn('id', array_values($siteIds))->get(['id', 'publisher_id']) as $site) {
                $existingSiteIds[(int) $site->id] = true;
                $pid = (int) $site->publisher_id;
                if ($pid > 0) {
                    $sitePublishers[(int) $site->id] = $pid;
                    $publisherIds[$pid] = $pid;
                }
            }
        }

        $existingBulkIds = [];
        $bulkPublishers = [];
        if ($bulkIds !== []) {
            foreach (BulkSiteRequest::query()->whereIn('id', array_values($bulkIds))->get(['id', 'publisher_id']) as $bulk) {
                $existingBulkIds[(int) $bulk->id] = true;
                $pid = (int) $bulk->publisher_id;
                if ($pid > 0) {
                    $bulkPublishers[(int) $bulk->id] = $pid;
                    $publisherIds[$pid] = $pid;
                }
            }
        }

        $publishers = [];
        if ($publisherIds !== []) {
            foreach (User::query()->whereIn('id', array_values($publisherIds))->get(['id', 'name', 'email']) as $user) {
                $publishers[(int) $user->id] = [
                    'name' => (string) $user->name,
                    'email' => $user->email ? (string) $user->email : null,
                ];
            }
        }

        return [
            'existingSiteIds' => $existingSiteIds,
            'existingBulkIds' => $existingBulkIds,
            'sitePublishers' => $sitePublishers,
            'bulkPublishers' => $bulkPublishers,
            'publishers' => $publishers,
        ];
    }

    /**
     * @param  ?array{
     *     existingSiteIds?: array<int, true>,
     *     existingBulkIds?: array<int, true>
     * }  $lookup
     */
    public static function subjectUrl(?ActivityLog $log, ?array $lookup = null): ?string
    {
        if (! $log || $log->action === 'site.deleted_by_marketing') {
            return null;
        }

        $type = (string) $log->subject_type;
        $id = (int) $log->subject_id;

        if ($id > 0) {
            if ($type === Site::class && self::idExists($id, $lookup['existingSiteIds'] ?? null, Site::class)) {
                return route('marketing.sites.edit', $id);
            }

            if ($type === BulkSiteRequest::class && self::idExists($id, $lookup['existingBulkIds'] ?? null, BulkSiteRequest::class)) {
                return route('marketing.bulk-site-requests.show', $id);
            }
        }

        $bulkId = (int) data_get($log->properties, 'bulk_site_request_id');

        return $bulkId > 0 && self::idExists($bulkId, $lookup['existingBulkIds'] ?? null, BulkSiteRequest::class)
            ? route('marketing.bulk-site-requests.show', $bulkId)
            : null;
    }

    /**
     * @param  ?array{
     *     existingSiteIds?: array<int, true>,
     *     existingBulkIds?: array<int, true>
     * }  $lookup
     */
    public static function bulkUrl(?ActivityLog $log, ?array $lookup = null): ?string
    {
        if (! $log) {
            return null;
        }

        $bulkId = (int) data_get($log->properties, 'bulk_site_request_id');
        if ($bulkId <= 0 || ! self::idExists($bulkId, $lookup['existingBulkIds'] ?? null, BulkSiteRequest::class)) {
            return null;
        }

        $primary = self::subjectUrl($log, $lookup);
        $bulk = route('marketing.bulk-site-requests.show', $bulkId);

        return $primary !== $bulk ? $bulk : null;
    }

    /**
     * @param  array{
     *     existingSiteIds?: array<int, true>,
     *     existingBulkIds?: array<int, true>,
     *     sitePublishers?: array<int, int>,
     *     bulkPublishers?: array<int, int>,
     *     publishers?: array<int, array{name: string, email: ?string}>
     * }  $lookup
     */
    public static function publisherLabel(?ActivityLog $log, array $lookup): ?string
    {
        if (! $log) {
            return null;
        }

        $pid = (int) data_get($log->properties, 'publisher_id');
        if ($pid <= 0) {
            $type = (string) $log->subject_type;
            $id = (int) $log->subject_id;
            if ($type === Site::class) {
                $pid = (int) ($lookup['sitePublishers'][$id] ?? 0);
            } elseif ($type === BulkSiteRequest::class) {
                $pid = (int) ($lookup['bulkPublishers'][$id] ?? 0);
            }
        }
        if ($pid <= 0) {
            $bulkId = (int) data_get($log->properties, 'bulk_site_request_id');
            $pid = (int) ($lookup['bulkPublishers'][$bulkId] ?? 0);
        }
        if ($pid <= 0) {
            $siteId = (int) data_get($log->properties, 'site_id');
            $pid = (int) ($lookup['sitePublishers'][$siteId] ?? 0);
        }
        if ($pid <= 0) {
            return null;
        }

        $pub = $lookup['publishers'][$pid] ?? null;
        if (! is_array($pub)) {
            return null;
        }

        $name = trim((string) ($pub['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        $email = trim((string) ($pub['email'] ?? ''));

        return $email !== '' ? $email : null;
    }

    public static function reason(?ActivityLog $log): ?string
    {
        $reason = trim((string) data_get($log?->properties, 'reason'));

        return $reason !== '' ? $reason : null;
    }

    /**
     * @return list<string>
     */
    public static function changeKeys(?ActivityLog $log): array
    {
        $changes = data_get($log?->properties, 'changes');
        if (! is_array($changes) || $changes === []) {
            return [];
        }

        $labels = [];
        foreach (array_keys($changes) as $key) {
            $key = (string) $key;
            $labels[] = self::CHANGE_KEY_LABELS[$key] ?? ucfirst(str_replace('_', ' ', $key));
        }

        return array_values(array_unique($labels));
    }

    /**
     * @param  array{existingSiteIds?: array<int, true>, existingBulkIds?: array<int, true>}  $lookup
     */
    public static function isRemoved(?ActivityLog $log, array $lookup): bool
    {
        if (! $log) {
            return false;
        }

        if ($log->action === 'site.deleted_by_marketing') {
            return true;
        }

        $type = (string) $log->subject_type;
        $id = (int) $log->subject_id;
        if ($id <= 0) {
            return false;
        }

        if ($type === Site::class) {
            return ! isset($lookup['existingSiteIds'][$id]);
        }

        if ($type === BulkSiteRequest::class) {
            return ! isset($lookup['existingBulkIds'][$id]);
        }

        return false;
    }

    /**
     * @param  ?array<int, true>  $existingIds
     * @param  class-string<Site|BulkSiteRequest>  $model
     */
    private static function idExists(int $id, ?array $existingIds, string $model): bool
    {
        if ($existingIds !== null) {
            return isset($existingIds[$id]);
        }

        return $model::query()->whereKey($id)->exists();
    }
}
