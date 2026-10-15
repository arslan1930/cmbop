<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Models\SavedSearch;
use App\Services\Catalog\CatalogUrlQuery;
use App\Support\UserFacingError;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SavedSearchController extends Controller
{
    public function index(): View
    {
        $searches = SavedSearch::tableAvailable()
            ? SavedSearch::query()
                ->where('user_id', auth()->id())
                ->orderByDesc('id')
                ->get()
            : collect();

        return view('advertiser.saved-searches', [
            'searches' => $searches,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (! SavedSearch::tableAvailable()) {
            return back()->with('error', 'Saved searches are not available yet.');
        }

        $data = $request->validate([
            'name' => 'required|string|min:2|max:120',
            'notify' => 'sometimes|boolean',
        ]);

        $userId = (int) auth()->id();
        $existing = SavedSearch::query()->where('user_id', $userId)->count();
        if ($existing >= SavedSearch::MAX_PER_USER) {
            return back()->with('error', 'You can keep up to '.SavedSearch::MAX_PER_USER.' saved searches.');
        }

        $query = CatalogUrlQuery::canonicalize($request->except(['_token', 'name', 'notify']));
        unset($query['page']);

        try {
            SavedSearch::query()->create([
                'user_id' => $userId,
                'name' => trim($data['name']),
                'query_json' => $query,
                'notify' => $request->boolean('notify'),
                'last_seen_max_id' => 0,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', UserFacingError::message($e, 'Could not save this search.'));
        }

        return redirect()
            ->route('advertiser.saved-searches')
            ->with('success', 'Search saved.');
    }

    public function destroy(SavedSearch $savedSearch): RedirectResponse
    {
        if ((int) $savedSearch->user_id !== (int) auth()->id()) {
            abort(403);
        }

        $savedSearch->delete();

        return redirect()
            ->route('advertiser.saved-searches')
            ->with('success', 'Saved search deleted.');
    }
}
