<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteClaim;
use App\Services\ActivityLogger;
use App\Services\Catalog\SiteUrlVisibility;
use App\Services\SiteClaimTransferService;
use App\Support\NormalizesHttpUrls;
use App\Support\UserFacingError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SiteClaimController extends Controller
{
    use NormalizesHttpUrls;

    public function __construct(private SiteClaimTransferService $transfers) {}

    /**
     * The claimer's own ownership claims (any authenticated user).
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user();
            $claims = SiteClaim::query()
                ->with(['site:id,site_name,domain,site_url,publisher_id', 'reviewer:id,name'])
                ->where('claimer_id', $user->id)
                ->latest('id')
                ->paginate(20)
                ->withQueryString();

            SiteClaim::applyCatalogIdentity($claims->getCollection(), $user);
        } catch (\Throwable $e) {
            report($e);
            $message = UserFacingError::message($e, 'We could not load your ownership claims. Please try again.');

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $message,
                ], 500);
            }

            $fallback = $request->routeIs('advertiser.site-claims')
                ? 'advertiser.dashboard'
                : 'publisher.websites';

            return redirect()
                ->route($fallback)
                ->with('error', $message);
        }

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'claims' => $claims->getCollection()->map(fn (SiteClaim $c) => [
                    'id' => $c->id,
                    'site_name' => $c->display_name,
                    'domain' => $c->display_host,
                    'name_matches' => (bool) $c->name_matches,
                    'status' => $c->status,
                    'admin_notes' => $c->admin_notes,
                    'reviewed_at' => optional($c->reviewed_at)?->toIso8601String(),
                    'created_at' => optional($c->created_at)?->toIso8601String(),
                ])->all(),
            ]);
        }

        $view = $request->routeIs('advertiser.site-claims')
            ? 'advertiser.site-claims'
            : 'publisher.site-claims';

        return view($view, compact('claims'));
    }

    public function store(Request $request)
    {
        $request->merge([
            'website_url' => $this->normalizeHttpUrl($request->input('website_url', '')),
        ]);

        $data = $request->validate([
            'site_id' => 'nullable|integer|exists:sites,id',
            'website_url' => 'required_without:site_id|nullable|url|max:255',
            'website_name' => 'nullable|string|max:190',
            'proof_message' => 'required|string|min:20|max:3000',
            'contact_email' => 'nullable|email|max:190',
        ]);

        $site = null;
        if (! empty($data['site_id'])) {
            $site = Site::query()->catalogVisible()->find($data['site_id']);
        } else {
            $domain = $this->extractDomain((string) ($data['website_url'] ?? ''));
            if (! $domain) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please enter a valid website URL.',
                ], 422);
            }
            $site = Site::findOccupyingDomain($domain);
        }

        if (! $site) {
            return response()->json([
                'success' => false,
                'message' => 'We could not find that website in our catalog.',
            ], 422);
        }

        if ((int) $site->publisher_id === (int) auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'You already own this listing.',
            ], 422);
        }

        $claimer = auth()->user();
        $visibility = app(SiteUrlVisibility::class);
        $canSeeListing = $claimer && $visibility->canSee($claimer, $site);

        // Hide mode must not fill name/URL from the listing. Claiming by
        // site_id and then opening My Claims used to print the real domain.
        if (! $canSeeListing && empty($data['website_url'])) {
            return response()->json([
                'success' => false,
                'message' => 'Please enter the website URL. We cannot fill it in from the catalog while names are hidden.',
            ], 422);
        }

        $websiteUrl = $data['website_url'] ?? null;
        if (! $websiteUrl && $canSeeListing) {
            $websiteUrl = $site->site_url ?: ($site->domain ? 'https://'.$site->domain : null);
        }

        $websiteName = trim((string) ($data['website_name'] ?? ''));
        if ($websiteName === '' && $canSeeListing) {
            $websiteName = (string) $site->site_name;
        }
        if ($websiteName === '') {
            $websiteName = 'Ownership claim';
        }

        if (! is_string($websiteUrl) || $websiteUrl === '') {
            return response()->json([
                'success' => false,
                'message' => 'Please enter a valid website URL.',
            ], 422);
        }

        $domain = $site->domain ?: $this->extractDomain((string) $websiteUrl);
        $nameMatches = $this->namesMatch($websiteName, (string) $site->site_name);

        try {
            $claim = DB::transaction(function () use ($site, $data, $websiteUrl, $websiteName, $domain, $nameMatches) {
                // Serialize pending-claim creation per site so double-submit cannot
                // create two pending rows for the same claimer.
                Site::query()->whereKey($site->id)->lockForUpdate()->firstOrFail();

                $pending = SiteClaim::query()
                    ->where('site_id', $site->id)
                    ->where('claimer_id', auth()->id())
                    ->where('status', 'pending')
                    ->exists();

                if ($pending) {
                    throw ValidationException::withMessages([
                        'claim' => 'You already have a pending claim for this website. We’ll email you after review.',
                    ]);
                }

                return SiteClaim::create([
                    'site_id' => $site->id,
                    'claimer_id' => auth()->id(),
                    'website_name' => $websiteName,
                    'website_url' => $websiteUrl,
                    'domain' => $domain,
                    'name_matches' => $nameMatches,
                    'proof_message' => $data['proof_message'],
                    'contact_email' => $data['contact_email'] ?? auth()->user()->email,
                    'status' => 'pending',
                ]);
            });
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->validator->errors()->first() ?: 'Claim could not be submitted.',
            ], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'We could not submit that claim. Please try again.'),
            ], 500);
        }

        try {
            ActivityLogger::log(
                'site.claim_submitted',
                auth()->user()->name.' claimed ownership of '.$site->site_name,
                $site,
                [
                    'claim_id' => $claim->id,
                    'name_matches' => $nameMatches,
                    'provided_name' => $websiteName,
                    'via' => ! empty($data['site_id']) ? 'catalog' : 'url',
                ],
                $site->site_name
            );
        } catch (\Throwable $e) {
            report($e);
        }

        $this->transfers->notifySubmitted($claim);

        return response()->json([
            'success' => true,
            'message' => $nameMatches
                ? 'Claim submitted. The website name matches our listing — our team will verify ownership and get back to you.'
                : 'Claim submitted. The website name you entered does not exactly match our listing, so we will verify carefully before transferring ownership.',
            'name_matches' => $nameMatches,
        ]);
    }

    /**
     * Pre-submit check: is this URL already a listed site?
     */
    public function lookup(Request $request)
    {
        $url = $this->normalizeHttpUrl($request->query('website_url', $request->input('website_url', '')));
        $domain = $this->extractDomain((string) $url);
        if (! $domain) {
            return response()->json([
                'success' => false,
                'listed' => false,
                'message' => 'Enter a full website URL, like https://example.com.',
            ], 422);
        }

        $site = Site::findOccupyingDomain($domain);
        if (! $site) {
            return response()->json([
                'success' => true,
                'listed' => false,
                'message' => 'We could not find that website in our catalog. Claims only work for sites already listed by another publisher.',
            ]);
        }

        if ((int) $site->publisher_id === (int) auth()->id()) {
            return response()->json([
                'success' => true,
                'listed' => true,
                'owned' => true,
                'message' => 'You already own this listing.',
            ]);
        }

        return response()->json([
            'success' => true,
            'listed' => true,
            'owned' => false,
            'message' => 'This URL matches a listed site. Submit proof of at least 20 characters — review usually takes 1–2 business days.',
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

    private function namesMatch(string $provided, string $listed): bool
    {
        $normalize = static function (string $value): string {
            $value = mb_strtolower(trim($value));
            $value = preg_replace('/\s+/', ' ', $value) ?? $value;

            return $value;
        };

        return $normalize($provided) === $normalize($listed);
    }
}
