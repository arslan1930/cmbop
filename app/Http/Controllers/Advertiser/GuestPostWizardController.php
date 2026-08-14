<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Models\ContentSubmission;
use App\Models\Country;
use App\Models\Language;
use App\Models\Site;
use App\Services\CartPricingService;
use App\Services\Marketplace\CountryLanguagePairs;
use App\Services\Marketplace\LanguageCountryMap;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GuestPostWizardController extends Controller
{
    public const SESSION_KEY = 'guest_post_wizard';

    public function __construct(
        private LanguageCountryMap $languageCountryMap,
        private CountryLanguagePairs $countryLanguagePairs,
        private CartPricingService $cartPricing,
    ) {}

    /**
     * Entry: start or resume wizard.
     * Never force content/pay just because the cart already has sites —
     * advertisers can keep browsing publishers and finish payment from the cart anytime.
     */
    public function start(Request $request)
    {
        $state = $this->state();
        if (! empty($state['language']) && ! empty($state['country'])) {
            return redirect()->route('advertiser.wizard.publishers');
        }

        return redirect()->route('advertiser.wizard.market');
    }

    public function market(): View
    {
        $state = $this->state();
        $languages = Language::marketplace()->orderBy('name')->get(['code', 'name']);
        $countries = Country::marketplace()->orderBy('name')->get(['code', 'name']);
        $languageCountryMap = $this->languageCountryMap->map();
        $countryLanguageMap = $this->countryLanguagePairs->mapWithNames();
        $categories = $this->nicheCategories();

        return view('advertiser.wizard.market', [
            'step' => 1,
            'state' => $state,
            'languages' => $languages,
            'countries' => $countries,
            'languageCountryMap' => $languageCountryMap,
            'countryLanguageMap' => $countryLanguageMap,
            'categories' => $categories,
        ]);
    }

    public function saveMarket(Request $request)
    {
        $data = $request->validate([
            'country' => ['required', 'string', 'max:16'],
            'language' => ['required', 'string', 'max:16'],
            'categories' => ['nullable', 'array'],
            'categories.*' => ['string', 'max:120'],
        ]);

        $language = strtolower(trim($data['language']));
        $country = strtolower(trim($data['country']));

        if (! $this->countryLanguagePairs->isAllowedPair($country, $language)) {
            return back()
                ->withInput()
                ->withErrors(['language' => 'That language is not allowed for the selected country.']);
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

        $cart = $this->syncVisibleCart();
        if ($cart === []) {
            return redirect()
                ->route('advertiser.wizard.publishers')
                ->with('error', 'Add at least one publisher before assigning content.');
        }

        $approvedArticles = ContentSubmission::query()
            ->where('user_id', auth()->id())
            ->orderable()
            ->latest('id')
            ->limit(100)
            ->get();

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
            'cartReady' => $this->cartHasReadyLine($cart),
            'cartFullyAssigned' => $this->cartFullyAssigned($cart),
        ]);
    }

    public function pay()
    {
        $state = $this->requireMarket();
        if ($state instanceof RedirectResponse) {
            return $state;
        }

        $cart = $this->syncVisibleCart();
        if ($cart === []) {
            return redirect()
                ->route('advertiser.wizard.publishers')
                ->with('error', 'Your cart is empty. Choose publishers first.');
        }

        // Same as Catalog checkout: pay ready lines only; unassigned stay in cart.
        if (! $this->cartHasReadyLine($cart)) {
            return redirect()
                ->route('advertiser.wizard.content')
                ->with('error', 'Assign an approved article to at least one website before paying.');
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
     * True when every placement/slot has an article (used for copy only).
     *
     * @param  array<int, array<string, mixed>>  $cart
     */
    private function cartFullyAssigned(array $cart): bool
    {
        if ($cart === []) {
            return false;
        }

        foreach ($cart as $line) {
            if (! $this->lineFullyAssigned($line)) {
                return false;
            }
        }

        return true;
    }

    /**
     * At least one line is fully assigned — checkout can charge those and defer the rest.
     *
     * @param  array<int, array<string, mixed>>  $cart
     */
    private function cartHasReadyLine(array $cart): bool
    {
        foreach ($cart as $line) {
            if ($this->lineFullyAssigned($line)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function lineFullyAssigned(array $line): bool
    {
        $qty = max(1, (int) ($line['quantity'] ?? 1));
        $ids = is_array($line['content_submission_ids'] ?? null) ? $line['content_submission_ids'] : [];
        for ($i = 0; $i < $qty; $i++) {
            $id = (int) ($ids[$i] ?? 0);
            if ($id <= 0 && $i === 0) {
                $id = (int) ($line['content_submission_id'] ?? 0);
            }
            if ($id <= 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Drop hidden/missing listings and refresh market fields from live catalog rows.
     *
     * @return list<array<string, mixed>>
     */
    private function syncVisibleCart(): array
    {
        $cart = array_values(session('cart', []));
        $pruned = $this->cartPricing->pruneUnavailableCartItems($cart);
        $cart = array_values($pruned['cart']);
        $this->enrichCartSites($cart);
        session()->put('cart', $cart);

        return $cart;
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

        $sites = Site::query()->catalogVisible()->whereIn('id', $siteIds)->get()->keyBy('id');
        $kept = [];
        foreach ($cart as $line) {
            $site = $sites->get((int) ($line['id'] ?? 0));
            if (! $site || ! $site->isCatalogVisible()) {
                continue;
            }
            $line['name'] = $line['name'] ?? $site->site_name;
            $line['url'] = $line['url'] ?? $site->site_url;
            $line['language'] = $line['language'] ?? $site->language;
            $line['country'] = $line['country'] ?? $site->country;
            $line['link_type'] = $line['link_type'] ?? $site->link_type;
            if (! isset($line['price'])) {
                $line['price'] = $site->price;
            }
            $kept[] = $line;
        }
        $cart = $kept;
    }

    /**
     * @return list<string>
     */
    private function nicheCategories(): array
    {
        $fromDb = Site::query()
            ->catalogVisible()
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
