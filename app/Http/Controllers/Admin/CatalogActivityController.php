<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CatalogCopyEvent;
use App\Models\DepositRequest;
use App\Models\Order;
use App\Models\Site;
use App\Models\SiteUrlReveal;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Catalog\CatalogCopyStrikeGuard;
use App\Services\Catalog\RevealPaceGuard;
use App\Support\AdminCatalogActivity;
use App\Support\UserFacingError;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * Hide-mode / copy-strike queue.
 *
 * Everyday catalog browsing is not logged. This screen is for accounts that
 * mass-copied domains (warning or hide) and for hide-mode eye/visit/cart
 * unlocks. The judgement column is unlocks in the selected window against
 * paid orders in that same window — plus whether the account has ever paid
 * or completed a deposit.
 */
class CatalogActivityController extends Controller
{
    public const NO_ORDERS_UNLOCK_THRESHOLD = 100;

    public const COPY_ATTENTION_DAYS = AdminCatalogActivity::COPY_ATTENTION_DAYS;

    public function index(Request $request, RevealPaceGuard $pace): View
    {
        $days = AdminCatalogActivity::days($request);
        $copyFilter = AdminCatalogActivity::copyFilter($request);
        $q = AdminCatalogActivity::search($request);
        $focusUserId = AdminCatalogActivity::focusUserId($request);
        AdminCatalogActivity::rememberReturnQuery($request);

        $shared = $this->sharedViewData($days, $copyFilter, $q, $focusUserId);

        try {
            return $this->renderIndex($request, $days, $pace, $shared);
        } catch (\Throwable $e) {
            report($e);
            session()->flash(
                'error',
                UserFacingError::message($e, 'We could not load catalog activity. Please refresh and try again.')
            );

            return view('admin.catalog-activity', array_merge($shared, $this->emptyCatalogActivityPayload()));
        }
    }

    /**
     * @param  array<string, mixed>  $shared
     */
    private function renderIndex(Request $request, int $days, RevealPaceGuard $pace, array $shared): View
    {
        $q = (string) ($shared['q'] ?? '');

        if (! Schema::hasTable('site_url_reveals')) {
            [$copyStrikeRows, $copyStrikeCapped] = $this->copyStrikeRows($days, $pace, $shared);

            return view('admin.catalog-activity', array_merge($shared, [
                'rows' => collect(),
                'available' => false,
                'unlockCapped' => false,
                'copyStrikeRows' => $copyStrikeRows,
                'copyStrikesAvailable' => $this->copyStrikeColumnsReady(),
                'copyStrikeCapped' => $copyStrikeCapped,
            ]));
        }

        $matchingIds = $this->matchingUserIds($q);

        $counts = SiteUrlReveal::query()
            ->select('user_id', DB::raw('COUNT(*) as total'), DB::raw('MAX(created_at) as last_at'))
            ->where('created_at', '>=', now()->subDays($days))
            ->where('created_at', '<=', SiteUrlReveal::PLAUSIBLE_SQL_DATETIME_CEIL)
            ->when($matchingIds !== null, fn ($query) => $query->whereIn('user_id', $matchingIds))
            ->groupBy('user_id')
            ->orderByDesc('total')
            ->limit(AdminCatalogActivity::UNLOCK_LIMIT + 1)
            ->get();

        $unlockCapped = $counts->count() > AdminCatalogActivity::UNLOCK_LIMIT;
        $counts = $counts->take(AdminCatalogActivity::UNLOCK_LIMIT)->values();

        $userIds = $counts->pluck('user_id');
        $users = User::whereIn('id', $userIds)->get()->keyBy('id');

        $windowStart = now()->subDays($days);
        $ordersInWindow = $this->paidOrderCounts($userIds, $windowStart);
        $ordersLifetime = $this->paidOrderCounts($userIds, null);
        $establishedIds = $this->establishedUserIds($userIds);

        $lastHour = SiteUrlReveal::query()
            ->select('user_id', DB::raw('COUNT(*) as total'))
            ->whereIn('user_id', $userIds)
            ->where('created_at', '>=', now()->subHour())
            ->where('created_at', '<=', SiteUrlReveal::PLAUSIBLE_SQL_DATETIME_CEIL)
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        $recent = SiteUrlReveal::query()
            ->whereIn('user_id', $userIds)
            ->where('created_at', '>=', now()->subHour())
            ->where('created_at', '<=', SiteUrlReveal::PLAUSIBLE_SQL_DATETIME_CEIL)
            ->orderBy('created_at')
            ->get(['user_id', 'created_at'])
            ->groupBy('user_id');

        $samples = max(5, (int) config('catalog.url_reveal.pace.regularity_samples', 15));
        $stddev = (float) config('catalog.url_reveal.pace.regularity_stddev_seconds', 1.5);

        $rows = $counts->map(function ($row) use ($users, $ordersInWindow, $ordersLifetime, $establishedIds, $pace, $lastHour, $recent, $samples, $stddev) {
            $user = $users->get($row->user_id);

            if (! $user) {
                return null;
            }

            $orders = (int) ($ordersInWindow[$row->user_id] ?? 0);
            $total = (int) $row->total;

            return [
                'user' => $user,
                'total' => $total,
                'last_at' => $this->parseDbTimestamp($row->last_at),
                'orders' => $orders,
                'orders_lifetime' => (int) ($ordersLifetime[$row->user_id] ?? 0),
                'per_order' => $orders > 0 ? round($total / $orders, 1) : null,
                'established' => $establishedIds->has((int) $row->user_id),
                'last_hour' => (int) ($lastHour[$row->user_id] ?? 0),
                'metronomic' => $pace->seriesLooksMetronomic(
                    $recent->get($row->user_id, collect())
                        ->pluck('created_at')
                        ->filter(fn ($at) => $at instanceof \DateTimeInterface)
                        ->values()
                        ->all(),
                    $samples,
                    $stddev
                ),
                'exempt' => $pace->isExempt($user),
                'exempt_until' => $user->catalog_reveal_exempt_until,
                'account_age_days' => (int) ($user->created_at?->diffInDays(now()) ?? 0),
            ];
        })->filter()->values();

        [$copyStrikeRows, $copyStrikeCapped] = $this->copyStrikeRows($days, $pace, $shared);

        return view('admin.catalog-activity', array_merge($shared, [
            'rows' => $rows,
            'available' => true,
            'unlockCapped' => $unlockCapped,
            'enforcing' => (bool) config('catalog.url_reveal.pace.enforce', true),
            'copyStrikeRows' => $copyStrikeRows,
            'copyStrikesAvailable' => $this->copyStrikeColumnsReady(),
            'copyStrikeCapped' => $copyStrikeCapped,
        ]));
    }

    public function show(int $user): View|RedirectResponse
    {
        try {
            $model = User::findOrFail($user);

            $copyEvents = collect();
            if (Schema::hasTable('catalog_copy_events')) {
                $copyEvents = CatalogCopyEvent::query()
                    ->with('site:id,site_name,domain,site_url')
                    ->where('user_id', $model->id)
                    ->orderByDesc('created_at')
                    ->limit(50)
                    ->get();
            }

            $reveals = collect();
            if (Schema::hasTable('site_url_reveals')) {
                $reveals = SiteUrlReveal::query()
                    ->with('site:id,site_name,domain,site_url')
                    ->where('user_id', $model->id)
                    ->orderByDesc('created_at')
                    ->limit(50)
                    ->get();
            }

            return view('admin.catalog-activity-show', [
                'account' => $model,
                'status' => $model->catalogCopyStatus(),
                'copyEvents' => $copyEvents,
                'reveals' => $reveals,
                'existingSiteIds' => $this->existingSiteIds($copyEvents->pluck('site_id')->merge($reveals->pluck('site_id'))),
                'hideHours' => max(1, (int) config('catalog.copy_strikes.hide_hours', 24)),
                'userUrl' => $this->userUrl($model->id),
                'queueUrl' => AdminCatalogActivity::queueUrl(request(), (int) $model->id),
                'historyUrl' => route('admin.activity-logs.index', ['user_id' => $model->id]),
            ]);
        } catch (ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return redirect()
                ->route('admin.catalog-activity')
                ->with('error', UserFacingError::message($e, 'We could not load that catalog activity. Please try again.'));
        }
    }

    /**
     * Lift hide only — strike ladder and copy events stay.
     */
    public function liftHide(int $user): RedirectResponse
    {
        if (! $this->copyStrikeColumnsReady()) {
            return back()->with('error', 'Copy-strike columns are not available yet — run migrations.');
        }

        $result = $this->mutateLockedUser($user, function (User $model) {
            $wasHidden = $model->inCatalogHideMode();
            $hideUntilWas = $model->catalog_hide_until;
            $strikesWere = (int) ($model->catalog_copy_strike_count ?? 0);

            if (! $wasHidden) {
                $staleUntil = $model->hasRawDateValue('catalog_hide_until');
                $exempt = $this->hasPaceExemption($model);
                if (! $staleUntil && ! $exempt) {
                    return ['noop' => true, 'model' => $model];
                }

                $model->catalog_hide_until = null;
                $this->clearPaceExemption($model);
                CatalogCopyStrikeGuard::watermarkEvents($model);
                $model->save();

                return [
                    'noop' => false,
                    'healed_stale_until' => $staleUntil,
                    'cleared_exempt' => $exempt,
                    'model' => $model,
                    'hide_until_was' => $hideUntilWas,
                    'strikes_were' => $strikesWere,
                ];
            }

            $model->catalog_hide_until = null;
            $this->clearPaceExemption($model);
            CatalogCopyStrikeGuard::watermarkEvents($model);
            $model->save();

            return [
                'noop' => false,
                'model' => $model,
                'hide_until_was' => $hideUntilWas,
                'strikes_were' => $strikesWere,
            ];
        });

        $model = $result['model'];
        if ($result['noop']) {
            return back()->with(
                'success',
                $model->email.' is already out of catalog hide mode.'
            );
        }

        CatalogCopyStrikeGuard::forgetNotices((int) $model->id);
        $this->logActivity(
            'catalog_hide_lifted',
            $model->email.' is out of catalog hide mode. Strike ladder is unchanged.',
            $model,
            [
                'hide_until_was' => $result['hide_until_was']?->toIso8601String(),
                'strikes_were' => $result['strikes_were'],
                'healed_stale_until' => (bool) ($result['healed_stale_until'] ?? false),
            ]
        );

        return back()->with(
            'success',
            $model->email.' is out of catalog hide mode — names and URLs show normally again. Strikes are unchanged.'
        );
    }

    /**
     * Reset the strike ladder. Does not lift an active hide or delete copy events.
     */
    public function resetStrikes(int $user): RedirectResponse
    {
        if (! $this->copyStrikeColumnsReady()) {
            return back()->with('error', 'Copy-strike columns are not available yet — run migrations.');
        }

        $result = $this->mutateLockedUser($user, function (User $model) {
            $strikesWere = (int) ($model->catalog_copy_strike_count ?? 0);
            $warnedAtWas = $model->catalog_copy_warned_at;
            $inHide = $model->inCatalogHideMode();

            if ($strikesWere === 0 && $warnedAtWas === null) {
                return ['noop' => true, 'model' => $model];
            }

            $model->catalog_copy_strike_count = 0;
            $model->catalog_copy_warned_at = null;
            CatalogCopyStrikeGuard::watermarkEvents($model);
            $model->save();

            return [
                'noop' => false,
                'model' => $model,
                'strikes_were' => $strikesWere,
                'warned_at_was' => $warnedAtWas,
                'in_hide' => $inHide,
            ];
        });

        $model = $result['model'];
        if ($result['noop']) {
            return back()->with(
                'success',
                $model->email.' copy strikes are already at zero.'
            );
        }

        CatalogCopyStrikeGuard::forgetNotices((int) $model->id);
        $this->logActivity(
            'catalog_strikes_reset',
            $model->email.' copy-strike ladder was reset.',
            $model,
            [
                'strikes_were' => $result['strikes_were'],
                'warned_at_was' => $result['warned_at_was']?->toIso8601String(),
                'still_in_hide' => $result['in_hide'],
            ]
        );

        $tail = $result['in_hide']
            ? ' Hide mode is still on until you lift it.'
            : '';

        return back()->with(
            'success',
            $model->email.' copy strikes were reset. Copy history is kept.'.$tail
        );
    }

    /**
     * Lift hide and reset strikes. Copy events are kept for forensics.
     *
     * @deprecated Use liftHide / resetStrikes. Kept so older bookmarks still work.
     */
    public function clearCopyHide(int $user): RedirectResponse
    {
        if (! $this->copyStrikeColumnsReady()) {
            return back()->with('error', 'Copy-strike columns are not available yet — run migrations.');
        }

        $result = $this->mutateLockedUser($user, function (User $model) {
            $wasHidden = $model->inCatalogHideMode();
            $hideUntilWas = $model->catalog_hide_until;
            $strikesWere = (int) ($model->catalog_copy_strike_count ?? 0);
            $warnedAtWas = $model->catalog_copy_warned_at;

            if (! $wasHidden && $strikesWere === 0 && $warnedAtWas === null) {
                if (! $model->hasRawDateValue('catalog_hide_until') && ! $this->hasPaceExemption($model)) {
                    return ['noop' => true, 'model' => $model];
                }

                $model->catalog_hide_until = null;
                $this->clearPaceExemption($model);
                CatalogCopyStrikeGuard::watermarkEvents($model);
                $model->save();

                return [
                    'noop' => false,
                    'healed_stale_until' => true,
                    'model' => $model,
                    'hide_until_was' => $hideUntilWas,
                    'strikes_were' => $strikesWere,
                ];
            }

            $model->catalog_hide_until = null;
            $model->catalog_copy_strike_count = 0;
            $model->catalog_copy_warned_at = null;
            $this->clearPaceExemption($model);
            CatalogCopyStrikeGuard::watermarkEvents($model);
            $model->save();

            return [
                'noop' => false,
                'model' => $model,
                'hide_until_was' => $hideUntilWas,
                'strikes_were' => $strikesWere,
            ];
        });

        $model = $result['model'];
        if ($result['noop']) {
            return back()->with(
                'success',
                $model->email.' is already out of catalog hide mode, with no strikes to reset. Copy history is kept.'
            );
        }

        CatalogCopyStrikeGuard::forgetNotices((int) $model->id);
        $this->logActivity(
            'catalog_activity.copy_hide_cleared',
            $model->email.' hide mode lifted and strikes reset. Copy history is kept.',
            $model,
            [
                'hide_until_was' => $result['hide_until_was']?->toIso8601String(),
                'strikes_were' => $result['strikes_were'] ?? 0,
                'healed_stale_until' => (bool) ($result['healed_stale_until'] ?? false),
            ]
        );

        return back()->with(
            'success',
            $model->email.' is out of catalog hide mode — strikes reset, names and URLs show normally again. Copy history is kept.'
        );
    }

    /**
     * Grant a pace exemption, or end one early.
     *
     * Only meaningful while hide mode is on (pace is not consulted otherwise).
     */
    public function toggleExempt(int $user): RedirectResponse
    {
        if (! $this->revealExemptColumnsReady()) {
            return back()->with('error', 'Catalog pace exemption columns are not available yet — run migrations.');
        }

        $minutes = max(1, (int) config('catalog.url_reveal.pace.exemption_minutes', 60));
        $result = $this->mutateLockedUser($user, function (User $model) use ($minutes) {
            if ($model->catalog_reveal_exempt_until && $model->catalog_reveal_exempt_until->isFuture()) {
                $model->catalog_reveal_exempt = false;
                $model->catalog_reveal_exempt_until = null;
                $model->save();

                return ['grant' => false, 'model' => $model];
            }

            $until = now()->addMinutes($minutes);
            $model->catalog_reveal_exempt = true;
            $model->catalog_reveal_exempt_until = $until;
            $model->save();

            return [
                'grant' => true,
                'model' => $model,
                'until' => $until,
                'minutes' => $minutes,
            ];
        });

        $model = $result['model'];
        if (! $result['grant']) {
            $this->logActivity(
                'catalog_activity.exempt_toggled',
                $model->email.' is back under the usual pace checks.',
                $model,
                ['exempt' => false]
            );

            return back()->with(
                'success',
                $model->email.' is back under the usual pace checks.'
            );
        }

        $this->logActivity(
            'catalog_activity.exempt_toggled',
            $model->email.' is trusted for '.$result['minutes'].' minutes.',
            $model,
            [
                'exempt' => true,
                'exempt_until' => $result['until']->toIso8601String(),
                'minutes' => $result['minutes'],
            ]
        );

        return back()->with(
            'success',
            $model->email.' is trusted until '.$result['until']->timezone(config('app.timezone'))->format('H:i').' ('.$result['minutes'].' minutes).'
        );
    }

    /**
     * @param  array<string, mixed>  $shared
     * @return array{0: Collection<int, array<string, mixed>>, 1: bool}
     */
    private function copyStrikeRows(int $days, RevealPaceGuard $pace, array $shared): array
    {
        if (! $this->copyStrikeColumnsReady()) {
            return [collect(), false];
        }

        $copyFilter = (string) ($shared['copyFilter'] ?? 'attention');
        $q = (string) ($shared['q'] ?? '');
        $focusUserId = (int) ($shared['focusUserId'] ?? 0);
        $matchingIds = $this->matchingUserIds($q);
        $attentionSince = now()->subDays(self::COPY_ATTENTION_DAYS);
        $floor = User::PLAUSIBLE_SQL_DATETIME_FLOOR;
        $ceil = User::PLAUSIBLE_SQL_DATETIME_CEIL;

        $query = User::query()
            ->where(function ($outer) use ($copyFilter, $attentionSince, $floor, $ceil) {
                $outer->where(function ($q) use ($floor, $ceil) {
                    $q->whereNotNull('catalog_hide_until')
                        ->where('catalog_hide_until', '>', now())
                        ->where('catalog_hide_until', '>=', $floor)
                        ->where('catalog_hide_until', '<=', $ceil);
                });

                if ($copyFilter === 'all') {
                    $outer->orWhere('catalog_copy_strike_count', '>', 0);

                    return;
                }

                $outer->orWhere(function ($q) use ($attentionSince, $floor, $ceil) {
                    $q->where('catalog_copy_strike_count', '>', 0)
                        ->where(function ($recent) use ($attentionSince, $floor, $ceil) {
                            $recent->where(function ($warned) use ($attentionSince, $floor, $ceil) {
                                $warned->where('catalog_copy_warned_at', '>=', $attentionSince)
                                    ->where('catalog_copy_warned_at', '>=', $floor)
                                    ->where('catalog_copy_warned_at', '<=', $ceil);
                            })->orWhere(function ($hidden) use ($attentionSince, $floor, $ceil) {
                                $hidden->where('catalog_hide_until', '>=', $attentionSince)
                                    ->where('catalog_hide_until', '>=', $floor)
                                    ->where('catalog_hide_until', '<=', $ceil);
                            });
                        });
                });
            })
            ->when($matchingIds !== null, fn ($q2) => $q2->whereIn('id', $matchingIds));

        $users = $query
            ->orderByRaw(
                'CASE WHEN catalog_hide_until IS NOT NULL AND catalog_hide_until > ? AND catalog_hide_until >= ? AND catalog_hide_until <= ? THEN 0 ELSE 1 END',
                [now(), $floor, $ceil]
            )
            ->orderByDesc('catalog_hide_until')
            ->orderByDesc('catalog_copy_strike_count')
            ->orderByDesc('catalog_copy_warned_at')
            ->limit(AdminCatalogActivity::COPY_LIMIT + 1)
            ->get();

        $capped = $users->count() > AdminCatalogActivity::COPY_LIMIT;
        $users = $users->take(AdminCatalogActivity::COPY_LIMIT)->values();

        // Notification deep-links use ?user= with no search. A later search
        // must not keep pinning that account via a leftover query param.
        if ($q === '' && $focusUserId > 0 && ! $users->contains(fn (User $u) => (int) $u->id === $focusUserId)) {
            $focus = User::find($focusUserId);
            if ($focus) {
                $users->push($focus);
            }
        }

        if ($users->isEmpty()) {
            return [collect(), $capped];
        }

        $userIds = $users->pluck('id');
        $windowStart = now()->subDays($days);
        $unlocks = collect();
        $lastHour = collect();

        if (Schema::hasTable('site_url_reveals')) {
            $unlocks = SiteUrlReveal::query()
                ->select('user_id', DB::raw('COUNT(*) as total'))
                ->whereIn('user_id', $userIds)
                ->where('created_at', '>=', $windowStart)
                ->where('created_at', '<=', SiteUrlReveal::PLAUSIBLE_SQL_DATETIME_CEIL)
                ->groupBy('user_id')
                ->pluck('total', 'user_id');

            $lastHour = SiteUrlReveal::query()
                ->select('user_id', DB::raw('COUNT(*) as total'))
                ->whereIn('user_id', $userIds)
                ->where('created_at', '>=', now()->subHour())
                ->where('created_at', '<=', SiteUrlReveal::PLAUSIBLE_SQL_DATETIME_CEIL)
                ->groupBy('user_id')
                ->pluck('total', 'user_id');
        }

        $ordersInWindow = $this->paidOrderCounts($userIds, $windowStart);
        $establishedIds = $this->establishedUserIds($userIds);
        $copies24h = $this->distinctCopiesSince($userIds, now()->subDay());

        $rows = $users->map(function (User $user) use ($unlocks, $lastHour, $ordersInWindow, $establishedIds, $copies24h, $pace) {
            $hideUntil = $user->catalog_hide_until;
            $status = $user->catalogCopyStatus();
            $orders = (int) ($ordersInWindow[$user->id] ?? 0);
            $total = (int) ($unlocks[$user->id] ?? 0);

            return [
                'user' => $user,
                'status' => $status,
                'strike_count' => (int) ($user->catalog_copy_strike_count ?? 0),
                'warned_at' => $user->catalog_copy_warned_at,
                'hide_until' => $hideUntil,
                'in_hide_mode' => $status === User::CATALOG_COPY_HIDDEN,
                'hide_remaining' => $this->hideRemainingLabel($hideUntil),
                'copies_24h' => (int) ($copies24h[$user->id] ?? 0),
                'total' => $total,
                'last_hour' => (int) ($lastHour[$user->id] ?? 0),
                'orders' => $orders,
                'per_order' => $orders > 0 ? round($total / $orders, 1) : null,
                'established' => $establishedIds->has((int) $user->id),
                'exempt' => $pace->isExempt($user),
                'exempt_until' => $user->catalog_reveal_exempt_until,
                'account_age_days' => (int) ($user->created_at?->diffInDays(now()) ?? 0),
                'user_url' => $this->userUrl($user->id),
            ];
        })->values();

        return [$rows, $capped];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyCatalogActivityPayload(): array
    {
        $revealsReady = false;
        try {
            $revealsReady = Schema::hasTable('site_url_reveals');
        } catch (\Throwable) {
            $revealsReady = false;
        }

        return [
            'rows' => collect(),
            'available' => $revealsReady,
            'unlockCapped' => false,
            'copyStrikeRows' => collect(),
            'copyStrikesAvailable' => $this->copyStrikeColumnsReady(),
            'copyStrikeCapped' => false,
            'enforcing' => (bool) config('catalog.url_reveal.pace.enforce', true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sharedViewData(int $days, string $copyFilter, string $q, int $focusUserId): array
    {
        $hideHours = max(1, (int) config('catalog.copy_strikes.hide_hours', 24));
        $exemptionMinutes = max(1, (int) config('catalog.url_reveal.pace.exemption_minutes', 60));

        return [
            'days' => $days,
            'copyFilter' => $copyFilter,
            'q' => $q,
            'focusUserId' => $focusUserId,
            'hideHours' => $hideHours,
            'exemptionMinutes' => $exemptionMinutes,
            'noOrdersThreshold' => self::NO_ORDERS_UNLOCK_THRESHOLD,
            'copyAttentionDays' => self::COPY_ATTENTION_DAYS,
            'unlockLimit' => AdminCatalogActivity::UNLOCK_LIMIT,
            'copyLimit' => AdminCatalogActivity::COPY_LIMIT,
            'dayChips' => AdminCatalogActivity::DAY_CHIPS,
        ];
    }

    /**
     * @return Collection<int, int>|null
     */
    private function matchingUserIds(string $q): ?Collection
    {
        if ($q === '') {
            return null;
        }

        $like = like_contains($q);

        return User::query()
            ->where(function ($query) use ($like) {
                $query->whereRaw('email LIKE ? ESCAPE ?', [$like, '\\'])
                    ->orWhereRaw('name LIKE ? ESCAPE ?', [$like, '\\']);
            })
            ->pluck('id');
    }

    /**
     * @param  Collection<int, mixed>  $userIds
     * @return Collection<int, int>
     */
    private function paidOrderCounts(Collection $userIds, ?Carbon $since): Collection
    {
        if ($userIds->isEmpty() || ! Schema::hasTable('orders')) {
            return collect();
        }

        return Order::query()
            ->select('user_id', DB::raw('COUNT(*) as total'))
            ->whereIn('user_id', $userIds)
            ->where('payment_status', 'paid')
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->groupBy('user_id')
            ->pluck('total', 'user_id');
    }

    /**
     * @param  Collection<int, mixed>  $userIds
     * @return Collection<int, int>
     */
    private function establishedUserIds(Collection $userIds): Collection
    {
        if ($userIds->isEmpty()) {
            return collect();
        }

        $paid = collect();
        if (Schema::hasTable('orders')) {
            $paid = Order::query()
                ->whereIn('user_id', $userIds)
                ->where('payment_status', 'paid')
                ->distinct()
                ->pluck('user_id');
        }

        $deposited = collect();
        if (Schema::hasTable('deposit_requests')) {
            $deposited = DepositRequest::query()
                ->whereIn('user_id', $userIds)
                ->whereIn('status', ['approved', 'completed'])
                ->distinct()
                ->pluck('user_id');
        }

        return $paid->merge($deposited)->map(fn ($id) => (int) $id)->unique()->flip();
    }

    /**
     * Distinct copied sites + host-only rows in the window (same rule as the guard).
     *
     * @param  Collection<int, mixed>  $userIds
     * @return Collection<int, int>
     */
    private function distinctCopiesSince(Collection $userIds, Carbon $since): Collection
    {
        if ($userIds->isEmpty() || ! Schema::hasTable('catalog_copy_events')) {
            return collect();
        }

        $withSite = CatalogCopyEvent::query()
            ->select('user_id', DB::raw('COUNT(DISTINCT site_id) as total'))
            ->whereIn('user_id', $userIds)
            ->where('created_at', '>=', $since)
            ->where('created_at', '<=', CatalogCopyEvent::PLAUSIBLE_SQL_DATETIME_CEIL)
            ->whereNotNull('site_id')
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        $hostOnly = CatalogCopyEvent::query()
            ->select('user_id', DB::raw('COUNT(DISTINCT normalized_host) as total'))
            ->whereIn('user_id', $userIds)
            ->where('created_at', '>=', $since)
            ->where('created_at', '<=', CatalogCopyEvent::PLAUSIBLE_SQL_DATETIME_CEIL)
            ->whereNull('site_id')
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        $totals = collect();
        foreach ($userIds as $id) {
            $totals[(int) $id] = (int) ($withSite[$id] ?? 0) + (int) ($hostOnly[$id] ?? 0);
        }

        return $totals;
    }

    /**
     * @template T
     *
     * @param  callable(User): T  $callback
     * @return T
     */
    private function mutateLockedUser(int $userId, callable $callback): mixed
    {
        return DB::transaction(function () use ($userId, $callback) {
            $model = User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();

            return $callback($model);
        });
    }

    private function parseDbTimestamp(mixed $value): ?Carbon
    {
        try {
            if ($value instanceof Carbon) {
                return $value;
            }

            if ($value instanceof \DateTimeInterface) {
                return Carbon::parse($value->format('Y-m-d H:i:s'), 'UTC');
            }

            $raw = trim((string) $value);
            if ($raw === '') {
                return null;
            }

            return Carbon::parse($raw, 'UTC');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function logActivity(string $action, string $description, User $subject, array $properties = []): void
    {
        try {
            ActivityLogger::log($action, $description, $subject, $properties);
        } catch (\Throwable $e) {
            Log::warning('Catalog activity log failed', [
                'action' => $action,
                'user_id' => $subject->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function hideRemainingLabel(?Carbon $until): ?string
    {
        if (! $until || ! $until->isFuture()) {
            return null;
        }

        $minutes = max(1, (int) ceil(now()->diffInMinutes($until, false)));

        if ($minutes >= 120) {
            return (int) round($minutes / 60).'h left';
        }

        return $minutes.'m left';
    }

    private function userUrl(int $userId): string
    {
        return route('admin.users.show', $userId);
    }

    /**
     * @param  Collection<int, mixed>  $siteIds
     * @return array<int, true>
     */
    private function existingSiteIds(Collection $siteIds): array
    {
        $ids = $siteIds
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();
        if ($ids->isEmpty() || ! Schema::hasTable('sites')) {
            return [];
        }

        try {
            $existing = [];
            foreach (Site::query()->whereIn('id', $ids->all())->pluck('id') as $id) {
                $existing[(int) $id] = true;
            }

            return $existing;
        } catch (\Throwable) {
            return [];
        }
    }

    private function copyStrikeColumnsReady(): bool
    {
        try {
            return Schema::hasColumn('users', 'catalog_copy_strike_count')
                && Schema::hasColumn('users', 'catalog_hide_until');
        } catch (\Throwable) {
            return false;
        }
    }

    private function revealExemptColumnsReady(): bool
    {
        try {
            return Schema::hasColumn('users', 'catalog_reveal_exempt')
                && Schema::hasColumn('users', 'catalog_reveal_exempt_until');
        } catch (\Throwable) {
            return false;
        }
    }

    private function clearPaceExemption(User $model): void
    {
        if (! $this->revealExemptColumnsReady()) {
            return;
        }

        $model->catalog_reveal_exempt = false;
        $model->catalog_reveal_exempt_until = null;
    }

    private function hasPaceExemption(User $model): bool
    {
        if (! $this->revealExemptColumnsReady()) {
            return false;
        }

        if ($model->catalog_reveal_exempt || $model->catalog_reveal_exempt_until) {
            return true;
        }

        return $model->hasRawDateValue('catalog_reveal_exempt_until');
    }
}
