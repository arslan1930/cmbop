<?php

namespace App\Http\Controllers;

use App\Models\Blog;
use App\Models\Country;
use App\Models\Order;
use App\Models\Site;
use App\Services\CuratedBlogWriter;
use App\Services\Marketing\CatalogTeaserService;
use App\Services\Marketing\GuestPostPriceIndex;
use App\Support\AustrianMoneyLanders;
use App\Support\CountryLander;
use App\Support\GermanMoneyLanders;
use App\Support\ItalianMoneyLanders;
use App\Support\RomanianMoneyLanders;
use App\Support\SpanishMoneyLanders;
use App\Support\SwissMoneyLanders;
use Throwable;

class MarketingPageController extends Controller
{
    public function about()
    {
        $company = config('billing.company', []);
        $registrationNo = (string) ($company['registration_no'] ?? '16607074');

        return view('pages.about', [
            'company' => $company,
            'companiesHouseUrl' => 'https://find-and-update.company-information.service.gov.uk/company/'.$registrationNo,
            'stats' => $this->aboutMarketplaceStats(),
            'blogLinks' => $this->aboutBlogLinks(public_locale()),
        ]);
    }

    public function faq()
    {
        return view('pages.faq');
    }

    public function pricing()
    {
        return view('pages.pricing');
    }

    public function marketplace()
    {
        return view('pages.marketplace', [
            'countryLanders' => $this->countryLanderSiblings(),
        ]);
    }

    public function countryLander(string $key)
    {
        abort_unless(class_exists(CountryLander::class), 404);
        abort_unless(view()->exists('pages.guest-posts-country'), 404);
        $lander = CountryLander::find($key);
        abort_unless(is_array($lander), 404);

        $codes = array_values(array_filter(array_map(
            static fn ($code) => strtolower(trim((string) $code)),
            $lander['codes'] ?? []
        )));

        $teasers = $this->catalogTeaserService();

        return view('pages.guest-posts-country', [
            'landerKey' => $key,
            'lander' => $lander,
            'teasers' => $teasers?->teasersForCountries($codes, 8) ?? collect(),
            'siteCount' => $teasers?->countForCountries($codes),
            'priceFrom' => $teasers?->priceFromForCountries($codes),
            'blogLinks' => $this->landerBlogLinks($lander['blog_slugs'] ?? []),
            'siblings' => $this->countryLanderSiblings($key),
        ]);
    }

    public function italianMoneyLander(string $slug)
    {
        abort_unless(class_exists(ItalianMoneyLanders::class), 404);
        abort_unless(view()->exists('pages.italian-money-lander'), 404);

        $page = ItalianMoneyLanders::find($slug);
        abort_unless(is_array($page), 404);

        $codes = array_values(array_filter(array_map(
            static fn ($code) => strtolower(trim((string) $code)),
            $page['teaser_countries'] ?? ['it']
        )));
        if ($codes === []) {
            $codes = ['it'];
        }

        $teasers = $this->catalogTeaserService();

        return view('pages.italian-money-lander', [
            'slug' => $slug,
            'page' => $page,
            'teasers' => $teasers?->teasersForCountries($codes, 8) ?? collect(),
            'siteCount' => $teasers?->countForCountries($codes),
            'priceFrom' => $teasers?->priceFromForCountries($codes),
            'cluster' => method_exists(ItalianMoneyLanders::class, 'clusterLinks')
                ? ItalianMoneyLanders::clusterLinks($slug)
                : [],
        ]);
    }

    public function germanMoneyLander(string $slug)
    {
        abort_unless(class_exists(GermanMoneyLanders::class), 404);
        abort_unless(view()->exists('pages.german-money-lander'), 404);

        $page = GermanMoneyLanders::find($slug);
        abort_unless(is_array($page), 404);

        $codes = array_values(array_filter(array_map(
            static fn ($code) => strtolower(trim((string) $code)),
            $page['teaser_countries'] ?? ['de']
        )));
        if ($codes === []) {
            $codes = ['de'];
        }

        $teasers = $this->catalogTeaserService();

        return view('pages.german-money-lander', [
            'slug' => $slug,
            'page' => $page,
            'teasers' => $teasers?->teasersForCountries($codes, 8) ?? collect(),
            'siteCount' => $teasers?->countForCountries($codes),
            'priceFrom' => $teasers?->priceFromForCountries($codes),
            'cluster' => method_exists(GermanMoneyLanders::class, 'clusterLinks')
                ? GermanMoneyLanders::clusterLinks($slug)
                : [],
        ]);
    }

    public function austrianMoneyLander(string $slug)
    {
        abort_unless(class_exists(AustrianMoneyLanders::class), 404);
        abort_unless(view()->exists('pages.austrian-money-lander'), 404);

        $page = AustrianMoneyLanders::find($slug);
        abort_unless(is_array($page), 404);

        $codes = array_values(array_filter(array_map(
            static fn ($code) => strtolower(trim((string) $code)),
            $page['teaser_countries'] ?? ['at']
        )));
        if ($codes === []) {
            $codes = ['at'];
        }

        $teasers = $this->catalogTeaserService();

        return view('pages.austrian-money-lander', [
            'slug' => $slug,
            'page' => $page,
            'teasers' => $teasers?->teasersForCountries($codes, 8) ?? collect(),
            'siteCount' => $teasers?->countForCountries($codes),
            'priceFrom' => $teasers?->priceFromForCountries($codes),
            'cluster' => method_exists(AustrianMoneyLanders::class, 'clusterLinks')
                ? AustrianMoneyLanders::clusterLinks($slug)
                : [],
        ]);
    }

    public function swissMoneyLander(string $slug)
    {
        abort_unless(class_exists(SwissMoneyLanders::class), 404);
        abort_unless(view()->exists('pages.swiss-money-lander'), 404);

        $page = SwissMoneyLanders::find($slug);
        abort_unless(is_array($page), 404);

        $codes = array_values(array_filter(array_map(
            static fn ($code) => strtolower(trim((string) $code)),
            $page['teaser_countries'] ?? ['ch']
        )));
        if ($codes === []) {
            $codes = ['ch'];
        }

        $teasers = $this->catalogTeaserService();

        return view('pages.swiss-money-lander', [
            'slug' => $slug,
            'page' => $page,
            'teasers' => $teasers?->teasersForCountries($codes, 8) ?? collect(),
            'siteCount' => $teasers?->countForCountries($codes),
            'priceFrom' => $teasers?->priceFromForCountries($codes),
            'cluster' => method_exists(SwissMoneyLanders::class, 'clusterLinks')
                ? SwissMoneyLanders::clusterLinks($slug)
                : [],
        ]);
    }

    public function spanishMoneyLander(string $slug)
    {
        abort_unless(class_exists(SpanishMoneyLanders::class), 404);
        abort_unless(view()->exists('pages.spanish-money-lander'), 404);

        $page = SpanishMoneyLanders::find($slug);
        abort_unless(is_array($page), 404);

        $codes = array_values(array_filter(array_map(
            static fn ($code) => strtolower(trim((string) $code)),
            $page['teaser_countries'] ?? ['es']
        )));
        if ($codes === []) {
            $codes = ['es'];
        }

        $teasers = $this->catalogTeaserService();

        return view('pages.spanish-money-lander', [
            'slug' => $slug,
            'page' => $page,
            'teasers' => $teasers?->teasersForCountries($codes, 8) ?? collect(),
            'siteCount' => $teasers?->countForCountries($codes),
            'priceFrom' => $teasers?->priceFromForCountries($codes),
            'cluster' => method_exists(SpanishMoneyLanders::class, 'clusterLinks')
                ? SpanishMoneyLanders::clusterLinks($slug)
                : [],
        ]);
    }

    public function romanianMoneyLander(string $slug)
    {
        abort_unless(class_exists(RomanianMoneyLanders::class), 404);
        abort_unless(view()->exists('pages.romanian-money-lander'), 404);

        $page = RomanianMoneyLanders::find($slug);
        abort_unless(is_array($page), 404);

        $codes = array_values(array_filter(array_map(
            static fn ($code) => strtolower(trim((string) $code)),
            $page['teaser_countries'] ?? ['ro']
        )));
        if ($codes === []) {
            $codes = ['ro'];
        }

        $teasers = $this->catalogTeaserService();

        return view('pages.romanian-money-lander', [
            'slug' => $slug,
            'page' => $page,
            'teasers' => $teasers?->teasersForCountries($codes, 8) ?? collect(),
            'siteCount' => $teasers?->countForCountries($codes),
            'priceFrom' => $teasers?->priceFromForCountries($codes),
            'cluster' => method_exists(RomanianMoneyLanders::class, 'clusterLinks')
                ? RomanianMoneyLanders::clusterLinks($slug)
                : [],
        ]);
    }

    public function europePriceIndex()
    {
        abort_unless(view()->exists('pages.guest-post-prices-europe'), 404);

        $snapshot = [
            'generated_at' => null,
            'europe' => ['median' => null, 'listings' => 0],
            'countries' => [],
            'has_index' => false,
        ];

        if (class_exists(GuestPostPriceIndex::class)) {
            try {
                $snapshot = app(GuestPostPriceIndex::class)->snapshot();
            } catch (Throwable) {
                // Leftover Hostinger deploys can miss CountryLander.
            }
        }

        return view('pages.guest-post-prices-europe', [
            'index' => $snapshot,
            'countryLanders' => $this->countryLanderSiblings(),
        ]);
    }

    private function catalogTeaserService(): ?CatalogTeaserService
    {
        if (! class_exists(CatalogTeaserService::class)) {
            return null;
        }

        try {
            return app(CatalogTeaserService::class);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return list<array{key: string, slug: string, market: string, kicker: string, url: string}>
     */
    private function countryLanderSiblings(?string $exceptKey = null): array
    {
        if (! class_exists(CountryLander::class)) {
            return [];
        }

        return CountryLander::siblings($exceptKey);
    }

    public function howItWorks()
    {
        return view('pages.how-it-works');
    }

    public function becomePublisher()
    {
        return view('pages.become-a-publisher');
    }

    public function whyChooseUs()
    {
        return view('pages.why-choose-us');
    }

    public function cookiePolicy()
    {
        return view('pages.cookie-policy');
    }

    public function refundPolicy()
    {
        return view('pages.refund-policy');
    }

    /**
     * Live marketplace proof points. Never invent numbers — omit a metric when
     * the query fails or the count is zero.
     *
     * @return array{
     *     sites: ?int,
     *     countries: ?int,
     *     completed_orders: ?int,
     *     verified_sites: ?int,
     *     rated_sites: ?int
     * }
     */
    private function aboutMarketplaceStats(): array
    {
        $stats = [
            'sites' => null,
            'countries' => null,
            'completed_orders' => null,
            'verified_sites' => null,
            'rated_sites' => null,
        ];

        try {
            $sites = (int) Site::query()->catalogVisible()->count();
            if ($sites > 0) {
                $stats['sites'] = $sites;
            }
        } catch (Throwable) {
            // Keep the About page up even if the DB is mid-migrate.
        }

        try {
            $countries = (int) Country::query()->marketplace()->count();
            if ($countries > 0) {
                $stats['countries'] = $countries;
            }
        } catch (Throwable) {
            //
        }

        try {
            $completed = (int) Order::query()->where('status', 'completed')->count();
            if ($completed > 0) {
                $stats['completed_orders'] = $completed;
            }
        } catch (Throwable) {
            //
        }

        try {
            $verified = (int) Site::query()->catalogVisible()->verified()->count();
            if ($verified > 0) {
                $stats['verified_sites'] = $verified;
            }
        } catch (Throwable) {
            //
        }

        try {
            if (Site::hasSitesColumn('rating_count')) {
                $rated = (int) Site::query()
                    ->catalogVisible()
                    ->where('rating_count', '>=', 1)
                    ->count();
                if ($rated > 0) {
                    $stats['rated_sites'] = $rated;
                }
            }
        } catch (Throwable) {
            //
        }

        return $stats;
    }

    /**
     * Locale-aware pillar posts. Only include published rows so dead blog
     * links never ship from this page.
     *
     * @return list<array{title: string, url: string}>
     */
    private function aboutBlogLinks(string $locale): array
    {
        $slugsByLocale = [
            'en' => [
                'buy-guest-posts-in-europe-how-to-choose-publisher-sites',
                'dofollow-nofollow-and-anchor-text-for-marketplace-links',
                'what-to-check-after-the-live-link-indexation-attributes-rankings',
            ],
            'de' => [
                'gastbeitraege-kaufen-europa-publisher-sites-richtig-waehlen',
                'dofollow-nofollow-ankertexte-marketplace-links',
                'gastbeitraege-kaufen-auf-seolinkbuildings-advertiser-leitfaden',
            ],
            'fr' => [
                'acheter-des-guest-posts-sur-seolinkbuildings-guide-annonceur',
                'choisir-un-editeur-dr-da-trafic-et-pertinence',
                'what-to-check-after-the-live-link-indexation-attributes-rankings',
            ],
            'nl' => [
                'gastposts-kopen-op-seolinkbuildings-adverteerdersgids',
                'uitgevers-kiezen-dr-da-verkeer-en-niche',
                'what-to-check-after-the-live-link-indexation-attributes-rankings',
            ],
            'it' => [
                'cose-un-guest-post',
                'come-fare-link-building',
                'come-ottenere-backlink',
            ],
        ];

        $slugs = $slugsByLocale[$locale] ?? $slugsByLocale['en'];
        $links = [];

        foreach ($slugs as $slug) {
            try {
                $match = CuratedBlogWriter::findExisting($slug);
                $blog = $match
                    ? Blog::published()
                        ->withPublishedLocale($locale)
                        ->where('id', $match->id)
                        ->first()
                    : null;

                if (! $blog) {
                    continue;
                }

                $translation = $blog->translationFor($locale, null);
                if (! $translation) {
                    continue;
                }

                $links[] = [
                    'title' => (string) $translation->title,
                    'url' => localized_url('blog/'.$translation->slug),
                ];
            } catch (Throwable) {
                continue;
            }
        }

        return $links;
    }

    /**
     * Published English translations only — landers are English URLs.
     *
     * @param  list<string>  $slugs
     * @return list<array{title: string, url: string}>
     */
    private function landerBlogLinks(array $slugs): array
    {
        $links = [];

        foreach ($slugs as $slug) {
            $slug = trim((string) $slug);
            if ($slug === '') {
                continue;
            }

            try {
                $match = CuratedBlogWriter::findExisting($slug);
                $blog = $match
                    ? Blog::published()
                        ->withPublishedLocale('en')
                        ->where('id', $match->id)
                        ->first()
                    : Blog::published()
                        ->withPublishedLocale('en')
                        ->where('slug', $slug)
                        ->first();

                if (! $blog) {
                    continue;
                }

                $translation = $blog->translationFor('en', null);
                if (! $translation) {
                    continue;
                }

                $links[] = [
                    'title' => (string) $translation->title,
                    'url' => url('/blog/'.$translation->slug),
                ];
            } catch (Throwable) {
                continue;
            }
        }

        return $links;
    }
}
