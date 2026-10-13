<?php

namespace Tests\Feature;

use App\Support\AustrianMoneyLanders;
use App\Support\LocaleIndexability;
use App\Support\LocalizedPublicPath;
use App\Support\PublicI18n;
use App\Support\RobotsTxt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GscThinLocaleDuplicatesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{canonical: ?string, robots: ?string}
     */
    private function headTags(string $html): array
    {
        $pick = function (string $pattern) use ($html): ?string {
            return preg_match($pattern, $html, $m) === 1 ? $m[1] : null;
        };

        return [
            'canonical' => $pick('/rel="canonical" href="([^"]+)"/'),
            'robots' => $pick('/name="robots" content="([^"]+)"/'),
        ];
    }

    public function test_us_about_canonicals_to_uk_and_is_noindex(): void
    {
        $html = $this->get('/us/about')->assertOk()->getContent();
        $tags = $this->headTags($html);

        $this->assertSame(url('/about'), $tags['canonical']);
        $this->assertSame('noindex, follow', $tags['robots']);
        $this->assertStringNotContainsString('rel="alternate" hreflang=', $html);
        $this->assertSame('noindex, follow', $this->get('/us/about')->headers->get('X-Robots-Tag'));
    }

    public function test_at_and_ch_about_canonical_to_german_original(): void
    {
        $de = url('/de/ueber-uns');

        foreach (['/at/ueber-uns', '/ch/ueber-uns'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();
            $tags = $this->headTags($html);
            $this->assertSame($de, $tags['canonical'], $path);
            $this->assertSame('noindex, follow', $tags['robots'], $path);
            $this->assertStringNotContainsString('rel="alternate" hreflang=', $html, $path);
        }

        $german = $this->headTags($this->get('/de/ueber-uns')->assertOk()->getContent());
        $this->assertSame($de, $german['canonical']);
        $this->assertStringStartsWith('index, follow', (string) $german['robots']);
    }

    public function test_portuguese_and_greek_english_bodies_canonical_to_uk(): void
    {
        $html = $this->get('/pt/sobre-nos')->assertOk()->getContent();
        $this->assertSame(url('/about'), $this->headTags($html)['canonical']);
        $this->assertSame('noindex, follow', $this->headTags($html)['robots']);

        $gr = $this->get(LocalizedPublicPath::publicPath('about', 'gr'))->assertOk()->getContent();
        $this->assertSame(url('/about'), $this->headTags($gr)['canonical']);
    }

    public function test_unique_locale_homes_stay_self_canonical_without_us(): void
    {
        foreach (['/at', '/ch', '/pt', '/bg', '/de'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();
            $tags = $this->headTags($html);
            $this->assertSame(url($path), $tags['canonical'], $path);
            $this->assertStringStartsWith('index, follow', (string) $tags['robots'], $path);
            $this->assertStringContainsString('hreflang="de-AT"', $html, $path);
            $this->assertStringNotContainsString('hreflang="en-US"', $html, $path);
        }

        $us = $this->headTags($this->get('/us')->assertOk()->getContent());
        $this->assertSame(url('/'), $us['canonical']);
        $this->assertSame('noindex, follow', $us['robots']);
    }

    public function test_sitemaps_drop_thin_copies_and_keep_unique_pages(): void
    {
        $us = $this->get('/sitemap-us.xml')->assertOk()->getContent();
        $this->assertStringNotContainsString('/us</loc>', $us);
        $this->assertStringNotContainsString('/us/about', $us);
        $this->assertStringNotContainsString('/us/marketplace', $us);
        $this->assertStringNotContainsString('/us/faq', $us);

        $at = $this->get('/sitemap-at.xml')->assertOk()->getContent();
        $this->assertStringContainsString('/at</loc>', $at);
        $this->assertStringContainsString('/at/marktplatz', $at);
        $this->assertStringContainsString('/at/preise', $at);
        $this->assertStringNotContainsString('/at/ueber-uns', $at);
        $this->assertStringNotContainsString('/at/kontakt', $at);
        $this->assertStringNotContainsString('hreflang="en-US"', $at);
        if (class_exists(AustrianMoneyLanders::class)) {
            foreach (AustrianMoneyLanders::slugs() as $slug) {
                $this->assertStringContainsString('/at/'.$slug, $at, $slug);
            }
        }

        $pt = $this->get('/sitemap-pt.xml')->assertOk()->getContent();
        $this->assertStringContainsString('/pt/marketplace', $pt);
        $this->assertStringNotContainsString('/pt/sobre-nos', $pt);

        $de = $this->get('/sitemap-de.xml')->assertOk()->getContent();
        $this->assertStringContainsString('/de/ueber-uns', $de);
        $this->assertStringContainsString('hreflang="de-AT"', $de);
        $this->assertStringNotContainsString('hreflang="en-US"', $de);
        $this->assertStringContainsString('hreflang="de-AT"', $de);
    }

    public function test_login_stays_crawlable_and_noindex(): void
    {
        $txt = $this->get('/robots.txt')->assertOk()->getContent();
        $this->assertStringNotContainsString("Disallow: /login\n", $txt);
        $this->assertStringNotContainsString("Disallow: /register\n", $txt);
        $this->assertSame(
            RobotsTxt::render('https://seolinkbuildings.com'),
            (string) file_get_contents(public_path('robots.txt'))
        );

        $this->get('/login')
            ->assertOk()
            ->assertSee('name="robots" content="noindex, nofollow', false)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('rel="canonical" href="'.url('/login').'"', false);
    }

    public function test_sitemap_locs_are_live_self_canonical_and_indexable(): void
    {
        foreach (['en', 'de', 'us', 'at', 'pt', 'gr', 'no'] as $locale) {
            $xml = $this->get('/sitemap-'.$locale.'.xml')->assertOk()->getContent();
            preg_match_all('#<loc>([^<]+)</loc>#', $xml, $matches);
            $locs = array_values(array_unique($matches[1] ?? []));
            if ($locale === 'us') {
                $this->assertSame([], $locs, 'US sitemap stays empty until copy is unique');

                continue;
            }
            $this->assertNotEmpty($locs, $locale.' sitemap should keep at least the unique URLs');

            foreach ($locs as $loc) {
                $path = parse_url($loc, PHP_URL_PATH) ?: '/';
                $response = $this->get($path);
                $this->assertSame(200, $response->status(), $path);
                $html = $response->getContent();
                $this->assertStringContainsString('rel="canonical" href="'.$loc.'"', $html, $path);
                $this->assertDoesNotMatchRegularExpression(
                    '/name="robots" content="noindex/i',
                    $html,
                    $path
                );
            }
        }
    }

    public function test_about_hreflang_cluster_is_only_localized_languages(): void
    {
        $expected = [];
        foreach (LocaleIndexability::indexableLocales('about') as $locale) {
            $expected[PublicI18n::hreflang($locale)] = url(LocalizedPublicPath::publicPath('about', $locale));
        }
        $expected['x-default'] = url('/about');

        $html = $this->get('/about')->assertOk()->getContent();
        preg_match_all(
            '/<link[^>]+rel="alternate"[^>]+hreflang="([^"]+)"[^>]+href="([^"]+)"/i',
            $html,
            $matches,
            PREG_SET_ORDER
        );
        $cluster = [];
        foreach ($matches as $match) {
            $cluster[$match[1]] = $match[2];
        }

        $this->assertSame($expected, $cluster);
        $this->assertArrayNotHasKey('en-US', $cluster);
        $this->assertArrayNotHasKey('de-AT', $cluster);
        $this->assertArrayNotHasKey('pt-PT', $cluster);
    }
}
