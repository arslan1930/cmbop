<?php

namespace App\Models;

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

    /**
     * Guest Posting badge buckets shown on the advertiser projects page.
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
    ];

    /**
     * @var array<string, string>
     */
    public const STAGE_HINTS = [
        'not_started' => 'Paid, scheduled, or waiting for the publisher to start',
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
        $candidate = $owned;
        $n = 2;

        while (self::query()
            ->where('slug', $candidate)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists()) {
            $candidate = $owned.'-'.$n;
            $n++;
        }

        return $candidate;
    }

    public static function hostTakenByUser(int $userId, string $url, ?int $ignoreId = null): bool
    {
        $host = self::hostFromUrl($url);
        if ($host === '') {
            return false;
        }

        return self::query()
            ->where('user_id', $userId)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->get(['id', 'project_url'])
            ->contains(fn (self $project) => self::hostFromUrl($project->project_url) === $host);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
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
     * Live-URL review + open revisions — same attention set as My Orders.
     *
     * @param  array<string, int>  $counts
     */
    public static function needsYouCountFrom(array $counts): int
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
     * Count the advertiser's placements per project host and Guest Posting stage.
     *
     * A line matches a project when its brief target URL host equals the
     * project's project_url host (www. stripped). Lines without a destination
     * URL on the item or the linked article are skipped.
     *
     * @param  Collection<int, Order>  $orders  Orders with items (and contentSubmission) eager-loaded.
     * @return array<string, array{not_started: int, in_progress: int, in_review: int, waiting_approval: int, needs_improvements: int, completed: int, rejected: int}>
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

                $bucket = self::stageBucket((string) (AdvertiserOrderStatus::meta($order, $item)['stage'] ?? ''));
                if ($bucket === null) {
                    continue;
                }

                if (! isset($byHost[$host])) {
                    $byHost[$host] = self::emptyStageCounts();
                }

                $byHost[$host][$bucket]++;
            }
        }

        return $byHost;
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

        $withoutOpenContentRevision = function (Builder $q): void {
            if (Schema::hasColumn('order_items', 'content_revision_requested')) {
                $q->whereDoesntHave('items', function ($items) {
                    $items->where('content_revision_requested', 'yes');
                });
            }
        };

        return match ($stage) {
            'not_started' => $query
                ->where('status', 'pending')
                ->whereHas('items', $matchingItems),
            'in_progress' => tap($query
                ->where('status', 'processing')
                ->whereHas('items', function ($items) use ($host) {
                    if ($host !== '') {
                        self::constrainItemsByHost($items, $host);
                    }
                    if (Schema::hasColumn('order_items', 'modification_requested')) {
                        $items->where(function ($q) {
                            $q->whereNull('modification_requested')
                                ->orWhere('modification_requested', '!=', 'yes');
                        });
                    }
                }), $withoutOpenContentRevision),
            'in_review' => tap($query
                ->where('status', 'review')
                ->whereHas('items', function ($items) use ($host) {
                    if ($host !== '') {
                        self::constrainItemsByHost($items, $host);
                    }
                    $items->where(function ($q) {
                        $q->whereNull('live_url')->orWhere('live_url', '');
                    });
                }), $withoutOpenContentRevision),
            'waiting_approval' => tap(
                AdvertiserOrderStatus::constrainReviewReady($query)
                    ->whereHas('items', function ($items) use ($host) {
                        if ($host !== '') {
                            self::constrainItemsByHost($items, $host);
                        }
                        $items->whereNotNull('live_url')->where('live_url', '!=', '');
                    }),
                $withoutOpenContentRevision
            ),
            'needs_improvements' => $query->where(function ($q) use ($host) {
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
                        ->whereHas('items', function ($items) {
                            $items->where('content_revision_requested', 'yes');
                        });
                    if ($host !== '') {
                        $revision->whereHas('items', function ($items) use ($host) {
                            self::constrainItemsByHost($items, $host);
                        });
                    }
                });
            }),
            'completed' => $query
                ->where('status', 'completed')
                ->whereHas('items', $matchingItems),
            'rejected' => $query
                ->where(function ($q) {
                    $q->where('status', 'cancelled')
                        ->orWhere('payment_status', 'failed');
                })
                ->whereHas('items', $matchingItems),
            'needs_you' => $query->where(function ($q) use ($host) {
                $q->where(function ($ready) use ($host) {
                    tap(
                        AdvertiserOrderStatus::constrainReviewReady($ready)
                            ->whereHas('items', function ($items) use ($host) {
                                if ($host !== '') {
                                    self::constrainItemsByHost($items, $host);
                                }
                                $items->whereNotNull('live_url')->where('live_url', '!=', '');
                            }),
                        function (Builder $inner): void {
                            if (Schema::hasColumn('order_items', 'content_revision_requested')) {
                                $inner->whereDoesntHave('items', function ($items) {
                                    $items->where('content_revision_requested', 'yes');
                                });
                            }
                        }
                    );
                })->orWhere(function ($improvements) use ($host) {
                    self::constrainOrdersByStage($improvements, 'needs_improvements', $host !== '' ? $host : null);
                });
            }),
            default => $query,
        };
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
    }

    /**
     * @return list<string>
     */
    public static function hostLikePatterns(string $host): array
    {
        return [
            '%://'.$host.'/%',
            '%://'.$host,
            '%://'.$host.'?%',
            '%://'.$host.'#%',
            $host.'/%',
        ];
    }
}
