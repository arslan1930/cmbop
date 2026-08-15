<?php

namespace App\Services;

use App\Models\AdBanner;
use App\Models\PromotionEvent;
use App\Models\SiteAnnouncement;
use App\Services\Wallet\WelcomeBonusService;
use App\Support\PromotionUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
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
            return DB::transaction(function () use ($subject, $event, $hash, $day) {
                PromotionEvent::query()->create([
                    'subject_type' => $subject::class,
                    'subject_id' => $subject->getKey(),
                    'event' => $event,
                    'visitor_hash' => $hash,
                    'occurred_on' => $day,
                    'created_at' => now(),
                ]);

                if ($event === self::EVENT_IMPRESSION && $subject instanceof AdBanner) {
                    $subject->recordImpression();
                }
                if ($event === self::EVENT_CLICK && method_exists($subject, 'recordClick')) {
                    $subject->recordClick();
                }

                return true;
            });
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
    }

    /**
     * Count a click and send the browser to the stored URL only for real
     * navigations. Image/prefetch hits must not inflate CTR or follow away.
     */
    public function followClick(Model $subject, Request $request, ?string $storedUrl): RedirectResponse|Response
    {
        $href = PromotionUrl::href($storedUrl);
        $live = method_exists($subject, 'isCurrentlyLive') && $subject->isCurrentlyLive();

        if (! $live || $href === null) {
            return redirect()->to('/');
        }

        if (! $this->looksLikeUserNavigation($request)) {
            return response()->noContent();
        }

        $this->record($subject, self::EVENT_CLICK, $request);

        return redirect()->away($href);
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
        // Request::ip() follows X-Forwarded-For while proxies are trusted.
        $ip = app(WelcomeBonusService::class)->normalizedIp($request) ?? 'unknown';
        $seed = $ip.'|'.substr((string) $request->userAgent(), 0, 180);

        return hash_hmac('sha256', $seed, (string) config('app.key'));
    }

    public function looksLikeUserNavigation(Request $request): bool
    {
        if ($request->prefetch()) {
            return false;
        }

        $dest = strtolower((string) $request->headers->get('Sec-Fetch-Dest', ''));
        if (in_array($dest, ['image', 'video', 'audio', 'font', 'style', 'script', 'embed', 'object', 'iframe'], true)) {
            return false;
        }

        $purpose = strtolower((string) $request->headers->get('Sec-Purpose', $request->headers->get('Purpose', '')));
        if (str_contains($purpose, 'prefetch')) {
            return false;
        }

        $accept = strtolower((string) $request->headers->get('Accept', ''));
        if ($accept !== '' && str_starts_with($accept, 'image/')) {
            return false;
        }

        return true;
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
