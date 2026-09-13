<?php

namespace App\Models;

use App\Support\AdvertiserOrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
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
}
