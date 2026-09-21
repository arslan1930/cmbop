<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\User;
use App\Models\UserBlacklist;
use App\Models\UserFavorite;
use App\Services\Catalog\SiteUrlVisibility;
use App\Services\PlatformFeeService;
use App\Support\UserFacingError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SavedSitesController extends Controller
{
    /**
     * Dedicated page to manage favorites and blacklisted sites together.
     */
    public function index(Request $request, SiteUrlVisibility $visibility): View
    {
        $user = auth()->user();
        $userId = (int) $user->id;
        $tab = in_array($request->get('tab'), ['favorites', 'blacklist'], true)
            ? $request->get('tab')
            : 'favorites';

        try {
            $favoriteIds = UserFavorite::where('user_id', $userId)->pluck('site_id');
            $blacklistIds = UserBlacklist::where('user_id', $userId)->pluck('site_id');

            $favorites = $this->visibleSavedSites($favoriteIds);
            $blacklist = $this->visibleSavedSites($blacklistIds);
            $visibility->warmFor($user, $favorites->merge($blacklist));

            $favorites->each(fn (Site $site) => $this->applyIdentity($site, $user, $visibility));
            $blacklist->each(fn (Site $site) => $this->applyIdentity($site, $user, $visibility));
        } catch (\Throwable $e) {
            report($e);
            session()->flash(
                'error',
                UserFacingError::message($e, 'Unable to load saved sites. Please refresh and try again.')
            );
            $favorites = collect();
            $blacklist = collect();
        }

        return view('advertiser.saved-sites', [
            'tab' => $tab,
            'favorites' => $favorites,
            'blacklist' => $blacklist,
            'favoritesCount' => $favorites->count(),
            'blacklistCount' => $blacklist->count(),
        ]);
    }

    /**
     * Remove a site from the advertiser's favorites.
     */
    public function removeFavorite(Request $request): JsonResponse
    {
        return $this->jsonSavedSitesAction(function () use ($request) {
            $data = $request->validate([
                'site_id' => 'required|integer|exists:sites,id',
            ]);

            $userId = auth()->id();
            UserFavorite::where('user_id', $userId)
                ->where('site_id', $data['site_id'])
                ->delete();

            return response()->json([
                'success' => true,
                'count' => $this->visibleSavedCount(UserFavorite::class, $userId),
            ]);
        }, 'Could not update your saved sites. Please try again.');
    }

    /**
     * Unblock a site (remove from blacklist) so it returns to the catalog.
     */
    public function removeBlacklist(Request $request): JsonResponse
    {
        return $this->jsonSavedSitesAction(function () use ($request) {
            $data = $request->validate([
                'site_id' => 'required|integer|exists:sites,id',
            ]);

            $userId = auth()->id();
            UserBlacklist::where('user_id', $userId)
                ->where('site_id', $data['site_id'])
                ->delete();

            return response()->json([
                'success' => true,
                'count' => $this->visibleSavedCount(UserBlacklist::class, $userId),
            ]);
        }, 'Could not update your blocked sites. Please try again.');
    }

    /**
     * Move a favorited site onto the blacklist (and off favorites).
     */
    public function moveToBlacklist(Request $request): JsonResponse
    {
        return $this->jsonSavedSitesAction(function () use ($request) {
            $data = $request->validate([
                'site_id' => 'required|integer|exists:sites,id',
            ]);

            $userId = auth()->id();
            $siteId = (int) $data['site_id'];

            UserFavorite::where('user_id', $userId)->where('site_id', $siteId)->delete();

            UserBlacklist::firstOrCreate([
                'user_id' => $userId,
                'site_id' => $siteId,
            ]);

            return response()->json([
                'success' => true,
                'favorites_count' => $this->visibleSavedCount(UserFavorite::class, $userId),
                'blacklist_count' => $this->visibleSavedCount(UserBlacklist::class, $userId),
            ]);
        }, 'Could not update your saved sites. Please try again.');
    }

    /**
     * Move a blacklisted site into favorites (and off the blacklist).
     */
    public function moveToFavorites(Request $request): JsonResponse
    {
        return $this->jsonSavedSitesAction(function () use ($request) {
            $data = $request->validate([
                'site_id' => 'required|integer|exists:sites,id',
            ]);

            $userId = auth()->id();
            $siteId = (int) $data['site_id'];

            UserBlacklist::where('user_id', $userId)->where('site_id', $siteId)->delete();

            UserFavorite::firstOrCreate([
                'user_id' => $userId,
                'site_id' => $siteId,
            ]);

            return response()->json([
                'success' => true,
                'favorites_count' => $this->visibleSavedCount(UserFavorite::class, $userId),
                'blacklist_count' => $this->visibleSavedCount(UserBlacklist::class, $userId),
            ]);
        }, 'Could not update your saved sites. Please try again.');
    }

    /**
     * @param  callable(): JsonResponse  $action
     */
    private function jsonSavedSitesAction(callable $action, string $fallback): JsonResponse
    {
        try {
            return $action();
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            $message = UserFacingError::message($e, $fallback);

            return response()->json([
                'success' => false,
                'error' => $message,
                'message' => $message,
            ], 500);
        }
    }

    private function decorateSite(Site $site): Site
    {
        $owned = $site->isOwnedBy(auth()->user());
        $site->is_owned_by_me = $owned;
        $site->display_price = $owned
            ? $site->publisherBasePrice()
            : app(PlatformFeeService::class)->advertiserBase((float) $site->price);

        return $site;
    }

    /**
     * Hide mode dual-masks name + host here the same way as the catalog.
     * Favoriting a masked row must not unmask it on Saved Sites.
     */
    private function applyIdentity(Site $site, User $user, SiteUrlVisibility $visibility): void
    {
        $site->display_name = $visibility->nameFor($user, $site);
        $site->display_host = $visibility->hostFor($user, $site);
    }

    /**
     * @param  Collection<int, int|string>  $siteIds
     * @return Collection<int, Site>
     */
    private function visibleSavedSites($siteIds)
    {
        return Site::query()
            ->catalogVisible()
            ->whereIn('id', $siteIds)
            ->orderBy('site_name')
            ->get()
            ->map(fn (Site $site) => $this->decorateSite($site));
    }

    /**
     * Count saved rows that still point at active, non-archived catalog sites.
     *
     * @param  class-string<UserFavorite|UserBlacklist>  $modelClass
     */
    private function visibleSavedCount(string $modelClass, int $userId): int
    {
        $siteIds = $modelClass::where('user_id', $userId)->pluck('site_id');

        if ($siteIds->isEmpty()) {
            return 0;
        }

        return Site::query()
            ->catalogVisible()
            ->whereIn('id', $siteIds)
            ->count();
    }
}
