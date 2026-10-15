<?php

namespace App\Services\Marketplace;

use App\Http\Controllers\Advertiser\CatalogListingController;
use App\Mail\SavedSearchAlertMail;
use App\Models\SavedSearch;
use App\Models\Site;
use App\Models\User;
use App\Services\EmailNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class SavedSearchAlertService
{
    public const MAX_LISTED = 12;

    /**
     * @return array{searches: int, mailed: int}
     */
    public function sendDueAlerts(int $limit = 200): array
    {
        if (! SavedSearch::tableAvailable()) {
            return ['searches' => 0, 'mailed' => 0];
        }

        $searches = SavedSearch::query()
            ->where('notify', true)
            ->orderBy('id')
            ->limit(max(1, min(500, $limit)))
            ->get();

        $mailed = 0;
        foreach ($searches as $search) {
            if ($this->sendForSearch($search)) {
                $mailed++;
            }
        }

        return ['searches' => $searches->count(), 'mailed' => $mailed];
    }

    public function sendForSearch(SavedSearch $search): bool
    {
        $user = User::query()->find($search->user_id);
        if (! $user || ! $user->email) {
            return false;
        }

        $matches = $this->newMatches($search);
        if ($matches->isEmpty()) {
            return false;
        }

        try {
            app(EmailNotificationService::class)->sendReminder(
                $user,
                new SavedSearchAlertMail($user, $search, $matches)
            );
        } catch (\Throwable $e) {
            Log::warning('Saved search alert failed', [
                'saved_search_id' => $search->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $search->update([
            'last_alerted_at' => now(),
            'last_seen_max_id' => max(
                (int) $search->last_seen_max_id,
                (int) $matches->max('id')
            ),
        ]);

        return true;
    }

    /**
     * @return Collection<int, Site>
     */
    public function newMatches(SavedSearch $search): Collection
    {
        $query = $search->catalogQuery();
        unset($query['page']);
        $query['per_page'] = 50;
        $query['sort'] = $query['sort'] ?? 'newest';

        $floorId = (int) ($search->last_seen_max_id ?? 0);
        $since = $search->last_alerted_at ?: $search->created_at;
        $user = $search->relationLoaded('user') ? $search->user : User::query()->find($search->user_id);
        if (! $user) {
            return collect();
        }

        $collected = collect();
        for ($page = 1; $page <= 4 && $collected->count() < self::MAX_LISTED; $page++) {
            $query['page'] = (string) $page;
            $request = Request::create('/advertiser/catalog', 'GET', $query);
            $request->setUserResolver(fn () => $user);

            $previous = Auth::user();
            try {
                Auth::onceUsingId((int) $user->id);
                $listing = app(CatalogListingController::class)->listingForExternalRequest($request);
            } catch (\Throwable $e) {
                Log::warning('Saved search listing failed', [
                    'saved_search_id' => $search->id,
                    'error' => $e->getMessage(),
                ]);
                break;
            } finally {
                if ($previous) {
                    Auth::login($previous);
                } else {
                    Auth::logout();
                }
            }

            $sites = $listing['sites'] ?? collect();
            $pageItems = collect($sites instanceof Collection ? $sites->all() : $sites->items());
            if ($pageItems->isEmpty()) {
                break;
            }

            foreach ($pageItems as $site) {
                if (! $site instanceof Site) {
                    continue;
                }
                $isNew = $site->id > $floorId
                    || ($since && $site->created_at && $site->created_at->greaterThan($since));
                if ($isNew) {
                    $collected->push($site);
                }
                if ($collected->count() >= self::MAX_LISTED) {
                    break 2;
                }
            }

            if ($pageItems->count() < 50) {
                break;
            }
        }

        return $collected->unique('id')->values();
    }
}
