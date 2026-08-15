<?php

namespace App\Services;

use App\Models\AdBanner;
use App\Models\PromotionEvent;
use App\Models\SiteAnnouncement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class PromotionTrackingService
{
    public const EVENT_IMPRESSION = 'impression';

    public const EVENT_CLICK = 'click';

    /**
     * @var list<string>
     */
    private const BOT_MARKERS = [
        'bot', 'crawler', 'spider', 'preview', 'slurp', 'bingpreview',
        'facebookexternalhit', 'twitterbot', 'linkedinbot', 'embedly',
    ];

    public function tablesReady(): bool
    {
        try {
            return Schema::hasTable('promotion_events');
        } catch (\Throwable) {
            return false;
        }
    }

    public function record(Model $subject, string $event, Request $request): bool
    {
        if (! $this->tablesReady()) {
            return false;
        }

        if (! in_array($event, [self::EVENT_IMPRESSION, self::EVENT_CLICK], true)) {
            return false;
        }

        if (! $subject instanceof AdBanner && ! $subject instanceof SiteAnnouncement) {
            return false;
        }

        if (method_exists($subject, 'isCurrentlyLive') && ! $subject->isCurrentlyLive()) {
            return false;
        }

        if ($this->looksLikeBot($request)) {
            return false;
        }

        $hash = $this->visitorHash($request);
        $day = now()->toDateString();

        try {
            PromotionEvent::query()->create([
                'subject_type' => $subject::class,
                'subject_id' => $subject->getKey(),
                'event' => $event,
                'visitor_hash' => $hash,
                'occurred_on' => $day,
                'created_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE') || str_contains($e->getMessage(), 'unique')) {
                return false;
            }
            Log::warning('Promotion event write failed', ['error' => $e->getMessage()]);

            return false;
        } catch (\Throwable $e) {
            Log::warning('Promotion event write failed', ['error' => $e->getMessage()]);

            return false;
        }

        try {
            if ($event === self::EVENT_IMPRESSION && $subject instanceof AdBanner) {
                $subject->recordImpression();
            }
            if ($event === self::EVENT_CLICK && method_exists($subject, 'recordClick')) {
                $subject->recordClick();
            }
        } catch (\Throwable $e) {
            Log::warning('Promotion rollup increment failed', ['error' => $e->getMessage()]);
        }

        return true;
    }

    public function countSince(string $subjectType, string $event, \DateTimeInterface $since): int
    {
        if (! $this->tablesReady()) {
            return 0;
        }

        try {
            return (int) PromotionEvent::query()
                ->where('subject_type', $subjectType)
                ->where('event', $event)
                ->where('occurred_on', '>=', $since->format('Y-m-d'))
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    public function countForSubjectSince(Model $subject, string $event, \DateTimeInterface $since): int
    {
        if (! $this->tablesReady()) {
            return 0;
        }

        try {
            return (int) PromotionEvent::query()
                ->where('subject_type', $subject::class)
                ->where('subject_id', $subject->getKey())
                ->where('event', $event)
                ->where('occurred_on', '>=', $since->format('Y-m-d'))
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    public function visitorHash(Request $request): string
    {
        $seed = scalar_text($request->ip()).'|'.substr((string) $request->userAgent(), 0, 180);

        return hash_hmac('sha256', $seed, (string) config('app.key'));
    }

    public function looksLikeBot(Request $request): bool
    {
        $ua = strtolower((string) $request->userAgent());
        if ($ua === '') {
            return true;
        }

        foreach (self::BOT_MARKERS as $marker) {
            if (str_contains($ua, $marker)) {
                return true;
            }
        }

        return false;
    }
}
