<?php

namespace App\Services\Marketplace;

use App\Models\OrderItem;
use App\Models\PublisherReliabilityStat;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class PublisherReliabilityService
{
    public const MIN_SAMPLE_STATS = 5;

    public const MIN_SAMPLE_BADGE = 10;

    public const BADGE_RESPONSE_RATE = 80.0;

    public const BADGE_PUBLICATION_RATE = 75.0;

    public const BADGE_MAX_RESPONSE_HOURS = 72.0;

    /**
     * @return array{publishers: int, updated: int}
     */
    public function refreshAll(): array
    {
        if (! PublisherReliabilityStat::tableAvailable() || ! Schema::hasTable('order_items')) {
            return ['publishers' => 0, 'updated' => 0];
        }

        $publisherIds = Site::query()
            ->whereNotNull('publisher_id')
            ->distinct()
            ->pluck('publisher_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->values();

        $updated = 0;
        foreach ($publisherIds as $publisherId) {
            $this->refreshPublisher($publisherId);
            $updated++;
        }

        return ['publishers' => $publisherIds->count(), 'updated' => $updated];
    }

    /**
     * @return array<string, mixed>
     */
    public function computeForPublisher(int $publisherId): array
    {
        $empty = [
            'sample_size' => 0,
            'completed_orders' => 0,
            'response_rate' => null,
            'avg_response_hours' => null,
            'publication_rate' => null,
            'avg_delivery_hours' => null,
            'badge_eligible' => false,
        ];

        if ($publisherId < 1 || ! Schema::hasTable('order_items')) {
            return $empty;
        }

        $siteIds = Site::query()->where('publisher_id', $publisherId)->pluck('id');
        if ($siteIds->isEmpty()) {
            return $empty;
        }

        $items = OrderItem::query()
            ->whereIn('site_id', $siteIds)
            ->whereHas('order', function ($orders) {
                $orders->where('payment_status', 'paid');
            })
            ->with('order')
            ->get();

        $respondable = 0;
        $responded = 0;
        $responseHours = [];
        $accepted = 0;
        $completed = 0;
        $deliveryHours = [];

        foreach ($items as $item) {
            $order = $item->order;
            if (! $order) {
                continue;
            }
            $paidAt = $order->paid_at ?: $order->created_at;
            if (! $paidAt) {
                continue;
            }

            $respondable++;
            $acceptedAt = $item->accepted_at;
            $rejectedAt = $item->rejected_at;
            if ($acceptedAt || $rejectedAt) {
                $responded++;
                $respondedAt = $acceptedAt ?: $rejectedAt;
                if ($respondedAt instanceof Carbon) {
                    $responseHours[] = max(0, $paidAt->diffInMinutes($respondedAt, false) / 60);
                }
            }

            if ($acceptedAt) {
                $accepted++;
                $doneAt = $item->live_url_submitted_at ?: $item->completed_at;
                if ($item->completed_at || (string) $order->status === 'completed') {
                    $completed++;
                }
                if ($doneAt instanceof Carbon) {
                    $deliveryHours[] = max(0, $acceptedAt->diffInMinutes($doneAt, false) / 60);
                }
            } elseif ($item->completed_at || (string) $order->status === 'completed') {
                $completed++;
            }
        }

        $responseRate = $respondable > 0 ? round(($responded / $respondable) * 100, 2) : null;
        $publicationRate = $accepted > 0 ? round(($completed / max($accepted, $completed)) * 100, 2) : null;
        if ($accepted > 0) {
            $publicationRate = round((min($completed, $accepted) / $accepted) * 100, 2);
        }

        $avgResponse = $responseHours !== [] ? round(array_sum($responseHours) / count($responseHours), 2) : null;
        $avgDelivery = $deliveryHours !== [] ? round(array_sum($deliveryHours) / count($deliveryHours), 2) : null;

        $badge = $completed >= self::MIN_SAMPLE_BADGE
            && $responseRate !== null && $responseRate >= self::BADGE_RESPONSE_RATE
            && $publicationRate !== null && $publicationRate >= self::BADGE_PUBLICATION_RATE
            && $avgResponse !== null && $avgResponse <= self::BADGE_MAX_RESPONSE_HOURS;

        return [
            'sample_size' => $respondable,
            'completed_orders' => $completed,
            'response_rate' => $responseRate,
            'avg_response_hours' => $avgResponse,
            'publication_rate' => $publicationRate,
            'avg_delivery_hours' => $avgDelivery,
            'badge_eligible' => $badge,
        ];
    }

    public function refreshPublisher(int $publisherId): PublisherReliabilityStat
    {
        $stats = $this->computeForPublisher($publisherId);

        return PublisherReliabilityStat::query()->updateOrCreate(
            ['publisher_id' => $publisherId],
            array_merge($stats, ['computed_at' => now()])
        );
    }

    public static function completedBucket(int $count): ?string
    {
        if ($count < self::MIN_SAMPLE_STATS) {
            return null;
        }
        foreach ([100, 50, 25, 10, 5] as $floor) {
            if ($count >= $floor) {
                return $floor.'+';
            }
        }

        return null;
    }
}
