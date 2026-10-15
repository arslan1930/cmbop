<?php

namespace App\Models;

use App\Services\Marketplace\PublisherReliabilityService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

class PublisherReliabilityStat extends Model
{
    /** @var array<int, array<string, mixed>|null> */
    private static array $requestCache = [];

    protected $fillable = [
        'publisher_id',
        'sample_size',
        'completed_orders',
        'response_rate',
        'avg_response_hours',
        'publication_rate',
        'avg_delivery_hours',
        'badge_eligible',
        'computed_at',
    ];

    protected $casts = [
        'sample_size' => 'integer',
        'completed_orders' => 'integer',
        'response_rate' => 'float',
        'avg_response_hours' => 'float',
        'publication_rate' => 'float',
        'avg_delivery_hours' => 'float',
        'badge_eligible' => 'boolean',
        'computed_at' => 'datetime',
    ];

    public static function tableAvailable(): bool
    {
        try {
            return Schema::hasTable((new static)->getTable());
        } catch (\Throwable) {
            return false;
        }
    }

    public static function forgetRequestCache(): void
    {
        self::$requestCache = [];
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'publisher_id');
    }

    /**
     * Buyer-facing row. Hidden below the minimum sample. Completed count is a bucket only.
     *
     * @return array{
     *     visible: bool,
     *     badge: bool,
     *     response_rate: ?int,
     *     avg_response_label: ?string,
     *     publication_rate: ?int,
     *     avg_delivery_label: ?string,
     *     completed_bucket: ?string
     * }|null
     */
    public static function presentationFor(int $publisherId): ?array
    {
        if ($publisherId < 1 || ! static::tableAvailable()) {
            return null;
        }

        if (array_key_exists($publisherId, self::$requestCache)) {
            return self::$requestCache[$publisherId];
        }

        $row = static::query()->where('publisher_id', $publisherId)->first();
        if (! $row) {
            return self::$requestCache[$publisherId] = null;
        }

        $min = PublisherReliabilityService::MIN_SAMPLE_STATS;
        if ((int) $row->completed_orders < $min || (int) $row->sample_size < $min) {
            return self::$requestCache[$publisherId] = [
                'visible' => false,
                'badge' => false,
                'response_rate' => null,
                'avg_response_label' => null,
                'publication_rate' => null,
                'avg_delivery_label' => null,
                'completed_bucket' => null,
            ];
        }

        return self::$requestCache[$publisherId] = [
            'visible' => true,
            'badge' => (bool) $row->badge_eligible,
            'response_rate' => $row->response_rate !== null ? (int) round((float) $row->response_rate) : null,
            'avg_response_label' => self::hoursLabel($row->avg_response_hours),
            'publication_rate' => $row->publication_rate !== null ? (int) round((float) $row->publication_rate) : null,
            'avg_delivery_label' => self::hoursLabel($row->avg_delivery_hours),
            'completed_bucket' => PublisherReliabilityService::completedBucket((int) $row->completed_orders),
        ];
    }

    private static function hoursLabel(mixed $hours): ?string
    {
        if ($hours === null) {
            return null;
        }

        $hours = (float) $hours;
        if ($hours < 1) {
            return '< 1 hour';
        }
        if ($hours < 48) {
            return round($hours).' hours';
        }

        return round($hours / 24).' days';
    }
}
