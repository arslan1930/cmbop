<?php

namespace App\Http\Controllers;

use App\Models\Blog;
use App\Models\Country;
use App\Models\Order;
use App\Models\Site;
use App\Services\CuratedBlogWriter;
use App\Services\Marketing\CatalogTeaserService;
use App\Support\CountryLander;
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

    public function marketplace(CatalogTeaserService $teasers)
    {
        return view('pages.marketplace', [
            'teasers' => $teasers->teasers(8),
            'countryLanders' => CountryLander::siblings(),
        ]);
    }

    public function countryLander(string $key, CatalogTeaserService $teasers)
    {
        $lander = CountryLander::find($key);
        abort_unless(is_array($lander), 404);

        $codes = array_values(array_filter(array_map(
            static fn ($code) => strtolower(trim((string) $code)),
            $lander['codes'] ?? []
        )));

        return view('pages.guest-posts-country', [
            'landerKey' => $key,
            'lander' => $lander,
            'teasers' => $teasers->teasersForCountries($codes, 8),
            'siteCount' => $teasers->countForCountries($codes),
            'priceFrom' => $teasers->priceFromForCountries($codes),
            'blogLinks' => $this->landerBlogLinks($lander['blog_slugs'] ?? []),
            'siblings' => CountryLander::siblings($key),
        ]);
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
            $verified = (int) Site::query()->catalogVisible()->count();
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
