<?php

namespace App\Models;

use App\Models\Concerns\ToleratesMissingSchema;
use App\Models\Concerns\ToleratesUnparseableDates;
use App\Support\AdvertiserOrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class Project extends Model
{
    use HasFactory;
    use ToleratesMissingSchema;
    use ToleratesUnparseableDates;

    /**
     * Placement-stage buckets shown on the advertiser projects page.
     *
     * `waiting_approval` is live-URL review only (same as My Orders
     * “Needs review”). `in_review` is review without a live URL.
     *
     * @var list<string>
     */
    public const STAGE_KEYS = [
        'not_started',
        'in_progress',
        'in_review',
        'waiting_approval',
        'needs_improvements',
        'completed',
        'rejected',
    ];

    /**
     * Visible badge labels (not tooltip-only).
     *
     * @var array<string, string>
     */
    public const STAGE_LABELS = [
        'not_started' => 'Not started',
        'in_progress' => 'In progress',
        'in_review' => 'In review',
        'waiting_approval' => 'Needs review',
        'needs_improvements' => 'Needs you',
        'completed' => 'Completed',
        'rejected' => 'Rejected',
        'needs_you' => 'Needs attention',
    ];

    /**
     * @var array<string, string>
     */
    public const STAGE_HINTS = [
        'not_started' => 'Awaiting payment, paid and waiting, or scheduled',
        'in_progress' => 'Publisher is preparing the placement',
        'in_review' => 'In review — waiting for a live URL',
        'waiting_approval' => 'Live URL ready for your review',
        'needs_improvements' => 'Revision or a new article needed',
        'completed' => 'Marked complete',
        'rejected' => 'Cancelled, refunded, or payment failed',
    ];

    protected $fillable = [
        'user_id',
        'project_name',
        'project_url',
        'slug', // ✅ REQUIRED
    ];

    /**
     * Auto-generate slug on create/update
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($project) {
            $project->slug = self::uniqueSlug($project->project_name, $project->user_id);
        });

        static::updating(function ($project) {
            if ($project->isDirty('project_name') || $project->isDirty('user_id')) {
                $project->slug = self::uniqueSlug($project->project_name, $project->user_id, $project->id);
            }
        });
    }

    public static function generateSlug($name, $userId = null): string
    {
        $base = Str::slug((string) $name);
        $suffix = $userId !== null && $userId !== '' ? '-'.$userId : '';

        return $base.$suffix;
    }

    /**
     * Globally unique slug. Same-user names that collapse to one slug
     * ("Acme Client" vs "Acme-Client") get a numeric suffix instead of 500ing.
     */
    public static function uniqueSlug(string $name, $userId = null, ?int $ignoreId = null): string
    {
        $owned = self::generateSlug($name, $userId);
        if (! static::tableAvailable()) {
            return $owned;
        }

        $candidate = $owned;
        $n = 2;

        try {
            while (self::query()
                ->where('slug', $candidate)
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists()) {
                $candidate = $owned.'-'.$n;
                $n++;
            }
        } catch (\Throwable) {
            return $owned;
        }

        return $candidate;
    }

    public static function hostTakenByUser(int $userId, string $url, ?int $ignoreId = null): bool
    {
        $host = self::hostFromUrl($url);
        if ($host === '' || ! static::tableAvailable()) {
            return false;
        }

        try {
            return self::query()
                ->where('user_id', $userId)
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->get(['id', 'project_url'])
                ->contains(fn (self $project) => self::hostFromUrl($project->project_url) === $host);
        } catch (\Throwable) {
            return false;
        }
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Registrable host used to match a project URL to placement target URLs.
     */
    public static function hostFromUrl(?string $url): string
    {
        $raw = trim((string) $url);
        if ($raw === '') {
            return '';
        }

        $candidate = preg_match('#^https?://#i', $raw) === 1 ? $raw : 'https://'.$raw;
        $host = strtolower((string) parse_url($candidate, PHP_URL_HOST));
        $host = rtrim($host, '.');

        if ($host === '') {
            return '';
        }

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /**
     * @return array{not_started: int, in_progress: int, in_review: int, waiting_approval: int, needs_improvements: int, completed: int, rejected: int}
     */
    public static function emptyStageCounts(): array
    {
        return [
            'not_started' => 0,
            'in_progress' => 0,
            'in_review' => 0,
            'waiting_approval' => 0,
            'needs_improvements' => 0,
            'completed' => 0,
            'rejected' => 0,
        ];
    }

    /**
     * Live-URL review + advertiser content revisions — same set as
     * My Orders `needs_action`. Publisher-wait `modification_requested`
     * stays on the Needs you chip, not in this KPI.
     *
     * @param  array<string, int>  $counts
     */
    public static function needsYouCountFrom(array $counts): int
    {
        return (int) ($counts['waiting_approval'] ?? 0)
            + (int) ($counts['content_revision'] ?? 0);
    }

    /**
     * Cards with a Needs review or Needs you chip stay above quiet rows.
     * Includes publisher-wait revisions that are not in the attention banner.
     *
     * @param  array<string, int>  $counts
     */
    public static function cardPriorityFrom(array $counts): int
    {
        return (int) ($counts['waiting_approval'] ?? 0)
            + (int) ($counts['needs_improvements'] ?? 0);
    }

    public static function stageLabel(string $key): string
    {
        return self::STAGE_LABELS[$key] ?? $key;
    }

    public static function stageHint(string $key): string
    {
        return self::STAGE_HINTS[$key] ?? '';
    }

    public static function stageBucket(string $stage): ?string
    {
        return match ($stage) {
            'awaiting_payment', 'scheduled', 'paid' => 'not_started',
            'processing' => 'in_progress',
            'review' => 'in_review',
            'url_delivered' => 'waiting_approval',
            'revision', 'content_revision' => 'needs_improvements',
            'completed' => 'completed',
            'cancelled', 'refunded', 'payment_failed' => 'rejected',
            default => null,
        };
    }

    /**
     * Destination host for a placement: item target URL, then the article brief.
     */
    public static function placementHost(OrderItem $item): string
    {
        return self::hostFromUrl($item->briefTargetUrl());
    }

    /**
     * Count the advertiser's placements per project host and stage.
     *
     * A line matches a project when its brief target URL host equals the
     * project's project_url host (www. stripped). Lines without a destination
     * URL on the item or the linked article are skipped.
     *
     * @param  Collection<int, Order>  $orders  Orders with items (and contentSubmission) eager-loaded.
     * @return array<string, array{not_started: int, in_progress: int, in_review: int, waiting_approval: int, needs_improvements: int, completed: int, rejected: int, content_revision?: int}>
     */
    public static function stageCountsByHost(Collection $orders): array
    {
        $byHost = [];

        foreach ($orders as $order) {
            foreach ($order->items as $item) {
                $host = self::placementHost($item);
                if ($host === '') {
                    continue;
                }

                $rawStage = (string) (AdvertiserOrderStatus::meta($order, $item, true)['stage'] ?? '');
                $bucket = self::stageBucket($rawStage);
                if ($bucket === null) {
                    continue;
                }

                if (! isset($byHost[$host])) {
                    $byHost[$host] = self::emptyStageCounts();
                }

                $byHost[$host][$bucket]++;
                if ($rawStage === 'content_revision') {
                    $byHost[$host]['content_revision'] = (int) ($byHost[$host]['content_revision'] ?? 0) + 1;
                }
            }
        }

        return $byHost;
    }

    /**
     * Count placements that are assigned to a project via orders.project_id.
     *
     * @param  Collection<int, Order>  $orders
     * @return array<int, array{not_started: int, in_progress: int, in_review: int, waiting_approval: int, needs_improvements: int, completed: int, rejected: int, content_revision?: int}>
     */
    public static function stageCountsByAssignedProject(Collection $orders): array
    {
        $byId = [];

        foreach ($orders as $order) {
            $projectId = (int) ($order->project_id ?? 0);
            if ($projectId <= 0) {
                continue;
            }

            foreach ($order->items as $item) {
                $rawStage = (string) (AdvertiserOrderStatus::meta($order, $item, true)['stage'] ?? '');
                $bucket = self::stageBucket($rawStage);
                if ($bucket === null) {
                    continue;
                }

                if (! isset($byId[$projectId])) {
                    $byId[$projectId] = self::emptyStageCounts();
                }

                $byId[$projectId][$bucket]++;
                if ($rawStage === 'content_revision') {
                    $byId[$projectId]['content_revision'] = (int) ($byId[$projectId]['content_revision'] ?? 0) + 1;
                }
            }
        }

        return $byId;
    }

    /**
     * @param  array<string, int>  $left
     * @param  array<string, int>  $right
     * @return array<string, int>
     */
    public static function mergeStageCounts(array $left, array $right): array
    {
        $merged = self::emptyStageCounts();
        foreach (array_keys($merged) as $key) {
            $merged[$key] = (int) ($left[$key] ?? 0) + (int) ($right[$key] ?? 0);
        }
        $revision = (int) ($left['content_revision'] ?? 0) + (int) ($right['content_revision'] ?? 0);
        if ($revision > 0) {
            $merged['content_revision'] = $revision;
        }

        return $merged;
    }

    /**
     * Stage keys accepted on My Orders (`project_stage`), including the
     * attention-banner composite `needs_you`.
     *
     * @return list<string>
     */
    public static function stageFilterKeys(): array
    {
        return array_merge(self::STAGE_KEYS, ['needs_you']);
    }

    public static function isKnownStageFilter(string $stage): bool
    {
        return in_array($stage, self::stageFilterKeys(), true);
    }

    /**
     * Limit orders to placements whose brief destination host matches.
     *
     * Matches item `target_url`, then the linked article brief when the
     * item URL is empty. Does not use the publisher `site_url`.
     *
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    public static function constrainOrdersByHost(Builder $query, string $urlOrHost): Builder
    {
        $host = self::sanitizeHostForLike($urlOrHost);
        if ($host === '') {
            return $query->whereRaw('0 = 1');
        }

        return $query->whereHas('items', function ($items) use ($host) {
            self::constrainItemsByHost($items, $host);
        });
    }

    /**
     * Limit orders to a Projects stage. When `$urlOrHost` is set, the stage
     * must hold on a line that also matches that destination host.
     *
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    public static function constrainOrdersByStage(Builder $query, string $stage, ?string $urlOrHost = null): Builder
    {
        $stage = strtolower(trim($stage));
        if (! self::isKnownStageFilter($stage)) {
            return $query;
        }

        $host = $urlOrHost !== null && $urlOrHost !== ''
            ? self::sanitizeHostForLike($urlOrHost)
            : '';
        if ($urlOrHost !== null && $urlOrHost !== '' && $host === '') {
            return $query->whereRaw('0 = 1');
        }

        $matchingItems = function ($items) use ($host) {
            if ($host !== '') {
                self::constrainItemsByHost($items, $host);
            }
        };

        $constrainItemWithoutContentRevision = function ($items): void {
            if (Schema::hasColumn('order_items', 'content_revision_requested')) {
                $items->where(function ($q) {
                    $q->whereNull('content_revision_requested')
                        ->orWhere('content_revision_requested', '!=', 'yes');
                });
            }
        };

        $withoutOpenContentRevision = function (Builder $q) use ($host): void {
            if ($host !== '' || ! Schema::hasColumn('order_items', 'content_revision_requested')) {
                return;
            }
            $q->whereDoesntHave('items', function ($items) {
                $items->where('content_revision_requested', 'yes');
            });
        };

        $alignWithMeta = function (Builder $q) use ($withoutOpenContentRevision): void {
            self::constrainWithoutFailedPayment($q);
            $withoutOpenContentRevision($q);
        };

        return match ($stage) {
            'not_started' => tap($query
                ->where('status', 'pending')
                ->whereHas('items', $matchingItems), [self::class, 'constrainWithoutFailedPayment']),
            'in_progress' => tap($query
                ->where('status', 'processing')
                ->whereHas('items', function ($items) use ($host, $constrainItemWithoutContentRevision) {
                    if ($host !== '') {
                        self::constrainItemsByHost($items, $host);
                    }
                    $constrainItemWithoutContentRevision($items);
                    if (Schema::hasColumn('order_items', 'modification_requested')) {
                        $items->where(function ($q) {
                            $q->whereNull('modification_requested')
                                ->orWhere('modification_requested', '!=', 'yes');
                        });
                    }
                }), $alignWithMeta),
            'in_review' => tap($query
                ->where('status', 'review')
                ->whereHas('items', function ($items) use ($host, $constrainItemWithoutContentRevision) {
                    if ($host !== '') {
                        self::constrainItemsByHost($items, $host);
                    }
                    $constrainItemWithoutContentRevision($items);
                    $items->where(function ($q) {
                        $q->whereNull('live_url')->orWhere('live_url', '');
                    });
                }), $alignWithMeta),
            'waiting_approval' => tap(
                AdvertiserOrderStatus::constrainReviewReady($query)
                    ->whereHas('items', function ($items) use ($host, $constrainItemWithoutContentRevision) {
                        if ($host !== '') {
                            self::constrainItemsByHost($items, $host);
                        }
                        $constrainItemWithoutContentRevision($items);
                        $items->whereNotNull('live_url')->where('live_url', '!=', '');
                    }),
                $alignWithMeta
            ),
            'needs_improvements' => tap($query->where(function ($q) use ($host) {
                $q->where(function ($mod) use ($host) {
                    $mod->where('status', 'processing')
                        ->whereHas('items', function ($items) use ($host) {
                            if ($host !== '') {
                                self::constrainItemsByHost($items, $host);
                            }
                            if (Schema::hasColumn('order_items', 'modification_requested')) {
                                $items->where('modification_requested', 'yes');
                            } else {
                                $items->whereRaw('0 = 1');
                            }
                        });
                })->orWhere(function ($revision) use ($host) {
                    if (! Schema::hasColumn('order_items', 'content_revision_requested')) {
                        $revision->whereRaw('0 = 1');

                        return;
                    }
                    $revision->whereIn('status', ['processing', 'review'])
                        ->whereHas('items', function ($items) use ($host) {
                            $items->where('content_revision_requested', 'yes');
                            if ($host !== '') {
                                self::constrainItemsByHost($items, $host);
                            }
                        });
                });
            }), [self::class, 'constrainWithoutFailedPayment']),
            'completed' => tap($query
                ->where('status', 'completed')
                ->whereHas('items', $matchingItems), function (Builder $q) {
                    AdvertiserOrderStatus::constrainWithoutFailedPayment($q, false);
                }),
            'rejected' => $query
                ->where(function ($q) {
                    $q->where('status', 'cancelled')
                        ->orWhere('payment_status', 'failed')
                        ->orWhere(function ($refunded) {
                            $refunded->where('payment_status', 'refunded')
                                ->where('status', '!=', 'completed');
                        });
                })
                ->whereHas('items', $matchingItems),
            'needs_you' => tap($query->where(function ($q) use ($host, $constrainItemWithoutContentRevision) {
                $q->where(function ($ready) use ($host, $constrainItemWithoutContentRevision) {
                    tap(
                        AdvertiserOrderStatus::constrainReviewReady($ready)
                            ->whereHas('items', function ($items) use ($host, $constrainItemWithoutContentRevision) {
                                if ($host !== '') {
                                    self::constrainItemsByHost($items, $host);
                                }
                                $constrainItemWithoutContentRevision($items);
                                $items->whereNotNull('live_url')->where('live_url', '!=', '');
                            }),
                        function (Builder $inner) use ($host): void {
                            if ($host !== '' || ! Schema::hasColumn('order_items', 'content_revision_requested')) {
                                return;
                            }
                            $inner->whereDoesntHave('items', function ($items) {
                                $items->where('content_revision_requested', 'yes');
                            });
                        }
                    );
                })->orWhere(function ($revision) use ($host) {
                    if (! Schema::hasColumn('order_items', 'content_revision_requested')) {
                        $revision->whereRaw('0 = 1');

                        return;
                    }
                    $revision->whereIn('status', ['processing', 'review'])
                        ->whereHas('items', function ($items) use ($host) {
                            $items->where('content_revision_requested', 'yes');
                            if ($host !== '') {
                                self::constrainItemsByHost($items, $host);
                            }
                        });
                })->orWhere(function ($linkDown) use ($host) {
                    if (! Schema::hasColumn('order_items', 'live_url_check_ok')) {
                        $linkDown->whereRaw('0 = 1');

                        return;
                    }
                    $windowDays = max(1, (int) config('orders.live_url_down_window_days', 90));
                    $linkDown->where('status', 'completed')
                        ->where('payment_status', 'paid')
                        ->whereHas('items', function ($items) use ($host, $windowDays) {
                            if ($host !== '') {
                                self::constrainItemsByHost($items, $host);
                            }
                            $items->whereNotNull('live_url')
                                ->where('live_url', '!=', '')
                                ->where('live_url_check_ok', false)
                                ->where(function ($recent) use ($windowDays) {
                                    $recent->where('live_url_checked_at', '>=', now()->subDays($windowDays));
                                    if (Schema::hasColumn('order_items', 'completed_at')) {
                                        $recent->orWhere('completed_at', '>=', now()->subDays($windowDays));
                                    }
                                });
                        });
                });
            }), [self::class, 'constrainWithoutFailedPayment']),
            default => $query,
        };
    }

    /**
     * Failed and refunded charges are not live work (same as meta()).
     *
     * @param  Builder<Order>  $query
     */
    public static function constrainWithoutFailedPayment(Builder $query, bool $alsoRefunded = true): void
    {
        AdvertiserOrderStatus::constrainWithoutFailedPayment($query, $alsoRefunded);
    }

    /**
     * @param  Builder<OrderItem>  $query
     */
    public static function constrainItemsByHost(Builder $query, string $urlOrHost): void
    {
        $host = self::sanitizeHostForLike($urlOrHost);
        if ($host === '') {
            $query->whereRaw('0 = 1');

            return;
        }

        $applyToColumn = function (Builder $q, string $column) use ($host): void {
            self::constrainColumnByHost($q, $column, $host);
        };

        $query->where(function ($q) use ($applyToColumn) {
            $applyToColumn($q, 'target_url');

            if (Schema::hasColumn('order_items', 'content_submission_id')) {
                $q->orWhere(function ($fallback) use ($applyToColumn) {
                    $fallback->where(function ($empty) {
                        $empty->whereNull('target_url')->orWhere('target_url', '');
                    })->whereHas('contentSubmission', function ($submission) use ($applyToColumn) {
                        $applyToColumn($submission, 'target_url');
                    });
                });
            }
        });
    }

    /**
     * Registrable host with LIKE wildcards removed so a crafted project URL
     * cannot widen the destination match.
     */
    public static function sanitizeHostForLike(string $urlOrHost): string
    {
        $host = self::hostFromUrl($urlOrHost);
        if ($host === '') {
            $host = strtolower(trim($urlOrHost));
            $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
        }

        return str_replace(['%', '_', '\\'], '', $host);
    }

    /**
     * @param  Builder<*>  $query
     */
    private static function constrainColumnByHost(Builder $query, string $column, string $host): void
    {
        $hosts = array_values(array_unique(array_filter([$host, $host !== '' ? 'www.'.$host : ''])));
        $query->where(function ($inner) use ($column, $hosts) {
            $first = true;
            foreach ($hosts as $candidate) {
                foreach (self::hostLikePatterns($candidate) as $pattern) {
                    if ($first) {
                        $inner->where($column, 'like', $pattern);
                        $first = false;
                    } else {
                        $inner->orWhere($column, 'like', $pattern);
                    }
                }
                $inner->orWhere($column, '=', $candidate);
            }
        });

        foreach ($hosts as $candidate) {
            foreach (self::hostLikeFalseUserinfoPatterns($candidate) as $pattern) {
                $query->where($column, 'not like', $pattern);
            }
        }
    }

    /**
     * Authority-only LIKE shapes. Leading `%://` would also hit an
     * embedded URL in a query (`?redirect=https://host/…`); the string
     * must start with http(s) plus this host (or scheme-less host/).
     *
     * @return list<string>
     */
    public static function hostLikePatterns(string $host): array
    {
        $patterns = [$host.'/%'];

        foreach (['http://', 'https://'] as $scheme) {
            $patterns[] = $scheme.$host.'/%';
            $patterns[] = $scheme.$host;
            $patterns[] = $scheme.$host.'?%';
            $patterns[] = $scheme.$host.'#%';
            $patterns[] = $scheme.$host.':%';
            $patterns[] = $scheme.'%:%@'.$host.'/%';
            $patterns[] = $scheme.'%:%@'.$host;
            $patterns[] = $scheme.'%:%@'.$host.'?%';
            $patterns[] = $scheme.'%:%@'.$host.'#%';
            $patterns[] = $scheme.'%:%@'.$host.':%';
        }

        return $patterns;
    }

    /**
     * Userinfo LIKE (`%://%:%@host`) also hits a path/query/hash that
     * happens to contain `:…@host`. parse_url would not treat that as
     * this project's authority (https://evil.com/foo:bar@acme.example).
     *
     * @return list<string>
     */
    public static function hostLikeFalseUserinfoPatterns(string $host): array
    {
        return [
            '%://%/%@'.$host.'%',
            '%://%?%@'.$host.'%',
            '%://%#%@'.$host.'%',
        ];
    }
}
