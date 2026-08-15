<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\WebsiteSuggestion;
use App\Services\ActivityLogger;
use App\Services\CommunityInboxNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebsiteSuggestionController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'website_name' => 'required|string|max:190',
            'website_url' => 'required|url|max:255',
            'country' => 'nullable|string|max:8',
            'language' => 'nullable|string|max:8',
            'notes' => 'nullable|string|max:2000',
            'search_query' => 'nullable|string|max:190',
        ]);

        $domain = $this->extractDomain($data['website_url']);
        if (! $domain) {
            return response()->json([
                'success' => false,
                'message' => 'Please enter a valid website URL.',
            ], 422);
        }

        $existing = Site::findOccupyingDomain($domain);
        if ($existing) {
            $message = $existing->isCatalogVisible()
                ? 'That website is already listed in our catalog. Try searching for “'.$domain.'”.'
                : 'We already have this website on file. It is not currently available in the catalog.';

            return response()->json([
                'success' => false,
                'message' => $message,
            ], 422);
        }

        $recentDuplicate = WebsiteSuggestion::query()
            ->where('domain', $domain)
            ->where('status', 'pending')
            ->where('created_at', '>=', now()->subDays(30))
            ->exists();

        if ($recentDuplicate) {
            return response()->json([
                'success' => false,
                'message' => 'We already have a pending suggestion for this website. Thank you!',
            ], 422);
        }

        $suggestion = WebsiteSuggestion::create([
            'user_id' => auth()->id(),
            'website_name' => $data['website_name'],
            'website_url' => $data['website_url'],
            'domain' => $domain,
            'country' => $data['country'] ?? null,
            'language' => $data['language'] ?? null,
            'notes' => $data['notes'] ?? null,
            'search_query' => $data['search_query'] ?? null,
            'status' => 'pending',
        ]);

        ActivityLogger::log(
            'website.suggested',
            auth()->user()->name.' suggested website '.$suggestion->website_name,
            $suggestion,
            ['domain' => $domain],
            $suggestion->website_name
        );

        try {
            app(CommunityInboxNotifier::class)->notifyAdminsNewWebsite($suggestion);
        } catch (\Throwable $e) {
            Log::warning('Failed to notify admins about website suggestion: '.$e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Thanks! We’ll review “'.$suggestion->website_name.'” and try to include it if it fits our marketplace.',
        ]);
    }

    private function extractDomain(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! $host) {
            return null;
        }

        $normalized = Site::normalizeMarketplaceDomain($host);

        return $normalized !== '' ? $normalized : null;
    }
}
