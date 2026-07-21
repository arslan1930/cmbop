<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Models\ContentSubmission;
use App\Models\Country;
use App\Models\Language;
use App\Models\Site;
use App\Services\Marketplace\LanguageCountryMap;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GuestPostWizardController extends Controller
{
    public const SESSION_KEY = 'guest_post_wizard';

    public function __construct(
        private LanguageCountryMap $languageCountryMap
    ) {}

    /**
     * Entry: start or resume wizard.
     * Never force content/pay just because the cart already has sites —
     * advertisers can keep browsing publishers and finish payment from the cart anytime.
     */
    public function start(Request $request)
    {
        $state = $this->state();
        if (! empty($state['language'])) {
            return redirect()->route('advertiser.wizard.publishers');
        }

        return redirect()->route('advertiser.wizard.market');
    }

    public function market(): View
    {
        $state = $this->state();
        $languages = Language::marketplace()->orderBy('name')->get(['code', 'name']);
        $languageCountryMap = $this->languageCountryMap->map();
        $categories = $this->nicheCategories();

        return view('advertiser.wizard.market', [
            'step' => 1,
            'state' => $state,
            'languages' => $languages,
            'languageCountryMap' => $languageCountryMap,
            'categories' => $categories,
        ]);
    }

    public function saveMarket(Request $request)
    {
        $data = $request->validate([
            'language' => ['required', 'string', 'max:16'],
            'categories' => ['nullable', 'array'],
            'categories.*' => ['string', 'max:120'],
            'country' => ['nullable', 'string', 'max:16'],
        ]);

        $language = strtolower(trim($data['language']));
        $country = isset($data['country']) && $data['country'] !== ''
            ? strtolower(trim($data['country']))
            : null;

        if ($country && ! $this->languageCountryMap->languageAcceptsCountry($language, $country)) {
            return back()
                ->withInput()
                ->withErrors(['country' => 'That country is not available for the selected language.']);
        }

        $categories = collect($data['categories'] ?? [])
            ->map(fn ($c) => trim((string) $c))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $this->putState([
            'language' => $language,
            'categories' => $categories,
            'country' => $country,
            'started_at' => now()->toIso8601String(),
        ]);

        return redirect()->route('advertiser.wizard.publishers');
    }

    /**
     * Step 2 — hand off to catalog with market filters + wizard chrome.
     */
    public function publishers()
    {
        $state = $this->requireMarket();
        if ($state instanceof RedirectResponse) {
            return $state;
        }

        $params = [
            'wizard' => 1,
            'language' => $state['language'],
            'filters_open' => 1,
            'sort' => 'dr_desc',
        ];

        if (! empty($state['categories'])) {
            $params['category'] = implode(',', $state['categories']);
        }
        if (! empty($state['country'])) {
            $params['country'] = $state['country'];
        }

        return redirect()->route('advertiser.catalog', $params);
    }

    public function content(): View|RedirectResponse
    {
        $state = $this->requireMarket();
        if ($state instanceof RedirectResponse) {
            return $state;
        }

        $cart = array_values(session('cart', []));
        if ($cart === []) {
            return redirect()
                ->route('advertiser.wizard.publishers')
                ->with('error', 'Add at least one publisher before assigning content.');
        }

        $this->enrichCartSites($cart);
        session()->put('cart', $cart);

        $approvedArticles = ContentSubmission::query()
            ->where('user_id', auth()->id())
            ->whereNull('order_id')
            ->whereNull('archived_at')
            ->where('moderation_status', ContentSubmission::STATUS_APPROVED)
            ->latest('id')
            ->limit(100)
            ->get()
            ->filter(fn (ContentSubmission $s) => $s->canBeOrdered())
            ->values();

        $marketplaceCountries = Country::marketplace()->orderBy('name')->get(['code', 'name']);
        $marketplaceLanguages = Language::marketplace()->orderBy('name')->get(['code', 'name']);

        return view('advertiser.wizard.content', [
            'step' => 3,
            'state' => $state,
            'cart' => $cart,
            'approvedArticles' => $approvedArticles,
            'marketplaceCountries' => $marketplaceCountries,
            'marketplaceLanguages' => $marketplaceLanguages,
            'languageCountryMap' => $this->languageCountryMap->map(),
            'cartReady' => $this->cartFullyAssigned($cart),
        ]);
    }

    public function pay()
    {
        $state = $this->requireMarket();
        if ($state instanceof RedirectResponse) {
            return $state;
        }

        $cart = session('cart', []);
        if ($cart === []) {
            return redirect()
                ->route('advertiser.wizard.publishers')
                ->with('error', 'Your cart is empty. Choose publishers first.');
        }

        if (! $this->cartFullyAssigned($cart)) {
            return redirect()
                ->route('advertiser.wizard.content')
                ->with('error', 'Assign an approved article to each website before paying.');
        }

        return redirect()->route('advertiser.checkout', ['wizard' => 1]);
    }

    public function exit()
    {
        session()->forget(self::SESSION_KEY);

        return redirect()
            ->route('advertiser.dashboard')
            ->with('success', 'Guided flow closed. You can browse Catalog or Content Library anytime.');
    }

    /**
     * @return array<string, mixed>
     */
    public static function stateFromSession(): array
    {
        $raw = session(self::SESSION_KEY, []);

        return is_array($raw) ? $raw : [];
    }

    public static function clear(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    /**
     * @return array<string, mixed>
     */
    private function state(): array
    {
        return self::stateFromSession();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function putState(array $data): void
    {
        session()->put(self::SESSION_KEY, array_merge($this->state(), $data));
    }

    /**
     * @return array<string, mixed>|RedirectResponse
     */
    private function requireMarket()
    {
        $state = $this->state();
        if (empty($state['language'])) {
            return redirect()
                ->route('advertiser.wizard.market')
                ->with('error', 'Choose your market first.');
        }

        return $state;
    }

    /**
     * @param  array<int, array<string, mixed>>  $cart
     */
    private function cartFullyAssigned(array $cart): bool
    {
        if ($cart === []) {
            return false;
        }

        foreach ($cart as $line) {
            if ((int) ($line['content_submission_id'] ?? 0) <= 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, array<string, mixed>>  $cart
     */
    private function enrichCartSites(array &$cart): void
    {
        $siteIds = collect($cart)->pluck('id')->filter()->unique()->values();
        if ($siteIds->isEmpty()) {
            return;
        }

        $sites = Site::query()->whereIn('id', $siteIds)->get()->keyBy('id');
        foreach ($cart as $i => $line) {
            $site = $sites->get((int) ($line['id'] ?? 0));
            if (! $site) {
                continue;
            }
            $cart[$i]['name'] = $line['name'] ?? $site->site_name;
            $cart[$i]['url'] = $line['url'] ?? $site->site_url;
            $cart[$i]['language'] = $line['language'] ?? $site->language;
            $cart[$i]['country'] = $line['country'] ?? $site->country;
            $cart[$i]['link_type'] = $line['link_type'] ?? $site->link_type;
            if (! isset($cart[$i]['price']) && isset($line['price'])) {
                $cart[$i]['price'] = $line['price'];
            }
        }
    }

    /**
     * @return list<string>
     */
    private function nicheCategories(): array
    {
        $fromDb = Site::query()
            ->where('active', 1)
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->pluck('category')
            ->flatMap(function ($raw) {
                return preg_split('/\s*,\s*/', (string) $raw) ?: [];
            })
            ->map(fn ($c) => trim((string) $c))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($fromDb !== []) {
            return $fromDb;
        }

        return [
            'Marketing, PR & Advertising',
            'Technology & Gadgets',
            'Business & Finance',
            'E-commerce & Retail',
            'Health & Wellness',
            'Travel & Hospitality',
            'Lifestyle',
            'News & Media',
        ];
    }
}
