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
use App\Support\UserFacingError;
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
        try {
            $languages = Language::marketplace()->orderBy('name')->get(['code', 'name']);
            $countries = Country::marketplace()->orderBy('name')->get(['code', 'name']);
            $languageCountryMap = $this->languageCountryMap->map();
            $countryLanguageMap = $this->countryLanguagePairs->mapWithNames();
            $categories = $this->nicheCategories();
        } catch (\Throwable $e) {
            report($e);
            session()->flash(
                'error',
                UserFacingError::message($e, 'Unable to load marketplace filters. Please refresh and try again.')
            );
            $languages = collect();
            $countries = collect();
            $languageCountryMap = [];
            $countryLanguageMap = [];
            $categories = [];
        }

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

        try {
            $cart = $this->syncVisibleCart();
        } catch (\Throwable $e) {
            report($e);

            return redirect()
                ->route('advertiser.wizard.publishers')
                ->with('error', UserFacingError::message($e, 'Unable to load your cart. Please try again.'));
        }
        if ($cart === []) {
            return redirect()
                ->route('advertiser.wizard.publishers')
                ->with('error', 'Add at least one publisher before assigning content.');
        }

        $mustIncludeIds = [];
        foreach ($cart as $line) {
            $slotIds = is_array($line['content_submission_ids'] ?? null) ? $line['content_submission_ids'] : [];
            if (! empty($line['content_submission_id'])) {
                $slotIds[] = $line['content_submission_id'];
            }
            foreach ($slotIds as $id) {
                if ((int) $id > 0) {
                    $mustIncludeIds[] = (int) $id;
                }
            }
        }

        try {
            $approvedArticles = ContentSubmission::pickerArticlesForUser((int) auth()->id(), $mustIncludeIds);
            $marketplaceCountries = Country::marketplace()->orderBy('name')->get(['code', 'name']);
            $marketplaceLanguages = Language::marketplace()->orderBy('name')->get(['code', 'name']);
            $languageCountryMap = $this->languageCountryMap->map();
        } catch (\Throwable $e) {
            report($e);

            return redirect()
                ->route('advertiser.wizard.publishers')
                ->with('error', UserFacingError::message($e, 'Unable to load articles for this step.'));
        }

        return view('advertiser.wizard.content', [
            'step' => 3,
            'state' => $state,
            'cart' => $cart,
            'approvedArticles' => $approvedArticles,
            'marketplaceCountries' => $marketplaceCountries,
            'marketplaceLanguages' => $marketplaceLanguages,
            'languageCountryMap' => $languageCountryMap,
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

        try {
            $cart = $this->syncVisibleCart();
        } catch (\Throwable $e) {
            report($e);

            return redirect()
                ->route('advertiser.wizard.publishers')
                ->with('error', UserFacingError::message($e, 'Unable to load your cart. Please try again.'));
        }
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
        try {
            $raw = session(self::SESSION_KEY, []);
            if (! is_array($raw)) {
                return [];
            }

            $state = [];
            foreach (['language', 'country'] as $key) {
                $value = $raw[$key] ?? null;
                if (! is_scalar($value)) {
                    continue;
                }
                $value = strtolower(trim((string) $value));
                if ($value === '' || strlen($value) > 16) {
                    continue;
                }
                $state[$key] = $value;
            }

            $cats = $raw['categories'] ?? null;
            if (is_array($cats)) {
                $state['categories'] = array_values(array_filter(
                    array_map(
                        static fn ($c) => is_scalar($c) ? trim((string) $c) : '',
                        $cats
                    ),
                    static fn ($c) => $c !== '' && strlen($c) <= 120
                ));
            }

            if (isset($raw['started_at']) && is_scalar($raw['started_at'])) {
                $state['started_at'] = (string) $raw['started_at'];
            }

            return $state;
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
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
        $rawCart = session('cart', []);
        $cart = is_array($rawCart) ? array_values(array_filter($rawCart, 'is_array')) : [];
        $pruned = $this->cartPricing->pruneUnavailableCartItems($cart);
        $cart = array_values($pruned['cart']);
        $this->enrichCartSites($cart);
        $cart = $this->dropUnreadyCartArticles($cart);
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
            if (! is_array($line)) {
                continue;
            }
            $site = $sites->get((int) ($line['id'] ?? 0));
            if (! $site || ! $site->isCatalogVisible()) {
                continue;
            }
            $line['name'] = $line['name'] ?? $site->site_name;
            $line['url'] = $line['url'] ?? $site->site_url;
            $line['language'] = $site->language;
            $line['languages'] = $site->languageCodes();
            $line['country'] = $site->country;
            $line['countries'] = $site->countryCodes();
            $line['link_type'] = $line['link_type'] ?? $site->link_type;
            if (! isset($line['price'])) {
                $line['price'] = $site->price;
            }
            $kept[] = $line;
        }
        $cart = $kept;
    }

    /**
     * @param  list<array<string, mixed>>  $cart
     * @return list<array<string, mixed>>
     */
    private function dropUnreadyCartArticles(array $cart): array
    {
        $ids = [];
        foreach ($cart as $line) {
            $lineIds = is_array($line['content_submission_ids'] ?? null) ? $line['content_submission_ids'] : [];
            foreach ($lineIds as $id) {
                $id = (int) $id;
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
            $scalar = (int) ($line['content_submission_id'] ?? 0);
            if ($scalar > 0) {
                $ids[$scalar] = $scalar;
            }
        }
        if ($ids === []) {
            return $cart;
        }

        $ready = ContentSubmission::query()
            ->whereIn('id', array_values($ids))
            ->where('user_id', auth()->id())
            ->availableForPicker()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $readySet = array_fill_keys($ready, true);

        foreach ($cart as $i => $line) {
            $qty = max(1, (int) ($line['quantity'] ?? 1));
            $lineIds = is_array($line['content_submission_ids'] ?? null) ? $line['content_submission_ids'] : [];
            $cleaned = [];
            for ($copy = 0; $copy < $qty; $copy++) {
                $id = (int) ($lineIds[$copy] ?? 0);
                if ($id <= 0 && $copy === 0) {
                    $id = (int) ($line['content_submission_id'] ?? 0);
                }
                $cleaned[$copy] = ($id > 0 && isset($readySet[$id])) ? $id : 0;
            }
            $cart[$i]['content_submission_id'] = $cleaned[0] ?? 0;
            $cart[$i]['content_submission_ids'] = $cleaned;
        }

        return $cart;
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
