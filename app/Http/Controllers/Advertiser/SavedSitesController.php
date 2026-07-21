<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\UserBlacklist;
use App\Models\UserFavorite;
use App\Services\PlatformFeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SavedSitesController extends Controller
{
    /**
     * Dedicated page to manage favorites and blacklisted sites together.
     */
    public function index(Request $request): View
    {
        $userId = auth()->id();
        $tab = in_array($request->get('tab'), ['favorites', 'blacklist'], true)
            ? $request->get('tab')
            : 'favorites';

        $favoriteIds = UserFavorite::where('user_id', $userId)->pluck('site_id');
        $blacklistIds = UserBlacklist::where('user_id', $userId)->pluck('site_id');

        $favorites = Site::query()
            ->where('active', 1)
            ->whereIn('id', $favoriteIds)
            ->orderBy('site_name')
            ->get()
            ->map(fn (Site $site) => $this->decorateSite($site));

        $blacklist = Site::query()
            ->where('active', 1)
            ->whereIn('id', $blacklistIds)
            ->orderBy('site_name')
            ->get()
            ->map(fn (Site $site) => $this->decorateSite($site));

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
        $data = $request->validate([
            'site_id' => 'required|integer|exists:sites,id',
        ]);

        $userId = auth()->id();
        UserFavorite::where('user_id', $userId)
            ->where('site_id', $data['site_id'])
            ->delete();

        return response()->json([
            'success' => true,
            'count' => UserFavorite::where('user_id', $userId)->count(),
        ]);
    }

    /**
     * Unblock a site (remove from blacklist) so it returns to the catalog.
     */
    public function removeBlacklist(Request $request): JsonResponse
    {
        $data = $request->validate([
            'site_id' => 'required|integer|exists:sites,id',
        ]);

        $userId = auth()->id();
        UserBlacklist::where('user_id', $userId)
            ->where('site_id', $data['site_id'])
            ->delete();

        return response()->json([
            'success' => true,
            'count' => UserBlacklist::where('user_id', $userId)->count(),
        ]);
    }

    /**
     * Move a favorited site onto the blacklist (and off favorites).
     */
    public function moveToBlacklist(Request $request): JsonResponse
    {
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
            'favorites_count' => UserFavorite::where('user_id', $userId)->count(),
            'blacklist_count' => UserBlacklist::where('user_id', $userId)->count(),
        ]);
    }

    /**
     * Move a blacklisted site into favorites (and off the blacklist).
     */
    public function moveToFavorites(Request $request): JsonResponse
    {
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
            'favorites_count' => UserFavorite::where('user_id', $userId)->count(),
            'blacklist_count' => UserBlacklist::where('user_id', $userId)->count(),
        ]);
    }

    private function decorateSite(Site $site): Site
    {
        $site->display_price = app(PlatformFeeService::class)
            ->advertiserBase((float) $site->price);

        return $site;
    }
}
