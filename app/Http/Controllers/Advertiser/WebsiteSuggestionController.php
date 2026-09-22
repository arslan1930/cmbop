<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\WebsiteSuggestion;
use App\Services\ActivityLogger;
use App\Services\CommunityInboxNotifier;
use App\Support\CommunityInbox;
use App\Support\UserFacingError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class WebsiteSuggestionController extends Controller
{
    public function store(Request $request)
    {
        try {
            return $this->storeSuggestion($request);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Website suggestion failed: '.$e->getMessage());

            $message = UserFacingError::message($e, 'Could not send that website suggestion. Please try again.');

            return response()->json([
                'success' => false,
                'error' => $message,
                'message' => $message,
            ], 500);
        }
    }

    public function check(Request $request)
    {
        try {
            $verdict = $this->verdictForUrl((string) $request->query('url', ''));

            return response()->json([
                'success' => true,
                'state' => $verdict['state'],
                'message' => $verdict['message'],
                'domain' => $verdict['domain'],
                'href' => $verdict['href'],
            ]);
        } catch (\Throwable $e) {
            Log::error('Website suggestion check failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'state' => 'error',
                'message' => UserFacingError::message($e, 'Could not check that website. Please try again.'),
            ], 500);
        }
    }

    private function storeSuggestion(Request $request)
    {
        $data = $request->validate([
            'website_name' => 'nullable|string|max:190',
            'website_url' => 'required|string|max:255',
            'country' => 'nullable|string|max:8',
            'language' => 'nullable|string|max:8',
            'notes' => 'nullable|string|max:2000',
            'search_query' => 'nullable|string|max:190',
        ]);

        $verdict = $this->verdictForUrl($data['website_url']);
        if ($verdict['state'] !== 'available') {
            return response()->json([
                'success' => false,
                'state' => $verdict['state'],
                'message' => $verdict['message'],
            ], 422);
        }

        $url = $verdict['url'];
        $domain = $verdict['domain'];
        $websiteName = trim((string) ($data['website_name'] ?? ''));
        if ($websiteName === '') {
            $websiteName = $domain;
        }

        $suggestion = WebsiteSuggestion::create([
            'user_id' => auth()->id(),
            'website_name' => $websiteName,
            'website_url' => $url,
            'domain' => $domain,
            'country' => ($data['country'] ?? '') !== '' ? $data['country'] : null,
            'language' => ($data['language'] ?? '') !== '' ? $data['language'] : null,
            'notes' => $data['notes'] ?? null,
            'search_query' => $data['search_query'] ?? null,
            'status' => 'pending',
        ]);

        try {
            ActivityLogger::log(
                'website.suggested',
                (auth()->user()?->name ?? 'Advertiser').' suggested website '.$suggestion->website_name,
                $suggestion,
                ['domain' => $domain],
                $suggestion->website_name
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to log website suggestion: '.$e->getMessage(), [
                'suggestion_id' => $suggestion->id,
            ]);
        }

        try {
            app(CommunityInboxNotifier::class)->notifyAdminsNewWebsite($suggestion);
        } catch (\Throwable $e) {
            Log::warning('Failed to notify admins about website suggestion: '.$e->getMessage());
        }

        $recent = WebsiteSuggestion::query()
            ->where('user_id', auth()->id())
            ->latest('id')
            ->limit(5)
            ->get(['website_name', 'domain', 'status'])
            ->map(fn (WebsiteSuggestion $row) => [
                'name' => $row->website_name,
                'domain' => $row->domain,
                'status' => $row->status,
            ])
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'status' => 'pending',
            'message' => 'Thanks! We’ll review “'.$suggestion->website_name.'” and email you when it’s accepted or declined.',
            'recent' => $recent,
        ]);
    }

    /**
     * @return array{state: string, message: string, domain: ?string, href: ?string, url: ?string}
     */
    private function verdictForUrl(string $raw): array
    {
        $url = CommunityInbox::safeHttpUrl($raw);
        $domain = $url ? $this->extractDomain($url) : null;
        if (! $url || ! $domain) {
            return [
                'state' => 'invalid',
                'message' => 'Enter a full website URL, like https://example.com.',
                'domain' => null,
                'href' => null,
                'url' => null,
            ];
        }

        $existing = Site::findOccupyingDomain($domain);
        if ($existing) {
            if ($existing->isCatalogVisible()) {
                return [
                    'state' => 'listed',
                    'message' => 'That website is already listed in our catalog. Try searching for “'.$domain.'”.',
                    'domain' => $domain,
                    'href' => route('advertiser.catalog', ['search' => $domain]),
                    'url' => $url,
                ];
            }

            return [
                'state' => 'unavailable',
                'message' => 'We already have this website on file. It is not currently available in the catalog.',
                'domain' => $domain,
                'href' => null,
                'url' => $url,
            ];
        }

        $recentDuplicate = WebsiteSuggestion::query()
            ->where('domain', $domain)
            ->where('status', 'pending')
            ->where('created_at', '>=', now()->subDays(30))
            ->exists();

        if ($recentDuplicate) {
            return [
                'state' => 'pending',
                'message' => 'We already have a pending suggestion for this website. Thank you!',
                'domain' => $domain,
                'href' => null,
                'url' => $url,
            ];
        }

        return [
            'state' => 'available',
            'message' => 'This site is not in the catalog yet. You can suggest it.',
            'domain' => $domain,
            'href' => null,
            'url' => $url,
        ];
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
