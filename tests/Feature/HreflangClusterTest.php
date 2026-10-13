<?php

namespace Tests\Feature;

use App\Support\CountryLander;
use App\Support\LocaleIndexability;
use App\Support\LocalizedPublicPath;
use App\Support\PublicI18n;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HreflangClusterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<string>  $locales
     * @return list<string>
     */
    private function clusterKeys(array $locales): array
    {
        $keys = array_map(
            static fn (string $locale) => PublicI18n::hreflang($locale),
            $locales
        );
        $keys[] = 'x-default';

        return $keys;
    }

    /**
     * @return array<string, string>
     */
    private function hreflangCluster(string $html): array
    {
        preg_match_all(
            '/<link[^>]+rel="alternate"[^>]+hreflang="([^"]+)"[^>]+href="([^"]+)"[^>]*>/i',
            $html,
            $matches,
            PREG_SET_ORDER
        );

        if ($matches === []) {
            preg_match_all(
                '/<link[^>]+rel="alternate"[^>]+href="([^"]+)"[^>]+hreflang="([^"]+)"[^>]*>/i',
                $html,
                $matches,
                PREG_SET_ORDER
            );
            $cluster = [];
            foreach ($matches as $match) {
                $cluster[$match[2]] = $match[1];
            }

            return $cluster;
        }

        $cluster = [];
        foreach ($matches as $match) {
            $cluster[$match[1]] = $match[2];
        }

        return $cluster;
    }

    /**
     * @return array<string, string>
     */
    private function expectedHomeCluster(): array
    {
        return $this->expectedPageCluster('');
    }

    /**
     * @return array<string, string>
     */
    private function expectedPageCluster(string $englishPath): array
    {
        $cluster = [];
        foreach (LocaleIndexability::indexableLocales($englishPath) as $locale) {
            $cluster[PublicI18n::hreflang($locale)] = url(LocalizedPublicPath::publicPath($englishPath, $locale));
        }
        $cluster['x-default'] = url(LocalizedPublicPath::publicPath($englishPath, PublicI18n::default()));

        return $cluster;
    }

    public function test_locale_homes_share_one_reciprocal_cluster_with_uk_x_default(): void
    {
        $expected = $this->expectedHomeCluster();
        $homeLocales = LocaleIndexability::indexableLocales('');
        $this->assertSame($this->clusterKeys($homeLocales), array_keys($expected));
        $this->assertSame(url('/'), $expected['en-GB']);
        $this->assertArrayNotHasKey('en-US', $expected);
        $this->assertSame(url('/at'), $expected['de-AT']);
        $this->assertSame(url('/pl'), $expected['pl-PL']);
        $this->assertSame(url('/'), $expected['x-default']);

        foreach ($homeLocales as $locale) {
            $path = LocalizedPublicPath::publicPath('', $locale);
            $html = $this->get($path)->assertOk()->getContent();
            $cluster = $this->hreflangCluster($html);

            $this->assertSame($expected, $cluster, $path);
            $this->assertArrayNotHasKey('en', $cluster, $path.' must not emit generic hreflang=en');
            $this->assertArrayNotHasKey('en-US', $cluster, $path);
        }

        $us = $this->hreflangCluster($this->get('/us')->assertOk()->getContent());
        $this->assertSame([], $us);
    }

    public function test_marketplace_and_about_clusters_use_localized_slugs(): void
    {
        foreach (['marketplace', 'about', 'contact'] as $englishPath) {
            $expected = $this->expectedPageCluster($englishPath);
            $locales = LocaleIndexability::indexableLocales($englishPath);
            $this->assertSame($this->clusterKeys($locales), array_keys($expected));
            $this->assertSame(url('/'.$englishPath), $expected['x-default']);
            $this->assertArrayNotHasKey('en-US', $expected);

            foreach ($locales as $locale) {
                $path = LocalizedPublicPath::publicPath($englishPath, $locale);
                $html = $this->get($path)->assertOk()->getContent();
                $this->assertSame($expected, $this->hreflangCluster($html), $path);
            }

            $usPath = LocalizedPublicPath::publicPath($englishPath, 'us');
            $this->assertSame([], $this->hreflangCluster($this->get($usPath)->assertOk()->getContent()), $usPath);
        }
    }

    public function test_english_only_assets_do_not_advertise_thin_locale_copies(): void
    {
        $paths = array_merge(
            ['/guest-post-prices-europe', '/is-seolinkbuildings-safe'],
            array_map(static fn (string $slug) => '/'.$slug, CountryLander::slugs()),
        );

        foreach ($paths as $path) {
            $html = $this->get($path)->assertOk()->getContent();
            $cluster = $this->hreflangCluster($html);
            $canonicalEnglish = url($path);

            $this->assertSame(
                [
                    'en-GB' => $canonicalEnglish,
                    'x-default' => $canonicalEnglish,
                ],
                $cluster,
                $path
            );
            $this->assertArrayNotHasKey('de', $cluster, $path);
            $this->assertArrayNotHasKey('fr', $cluster, $path);
            $this->assertArrayNotHasKey('en-US', $cluster, $path);
            $this->assertDoesNotMatchRegularExpression('/<a[^>]+hreflang=/i', $html, $path);
        }
    }

    public function test_auth_pages_use_english_only_self_hreflang(): void
    {
        foreach (['/login', '/register'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();
            $this->assertSame([
                'en-GB' => url($path),
                'x-default' => url($path),
            ], $this->hreflangCluster($html), $path);
        }
    }

    public function test_sitemaps_repeat_the_home_and_marketplace_clusters(): void
    {
        $home = $this->expectedHomeCluster();
        $market = $this->expectedPageCluster('marketplace');

        foreach (['en', 'de'] as $locale) {
            $xml = $this->get('/sitemap-'.$locale.'.xml')->assertOk()->getContent();
            $tag = PublicI18n::hreflang($locale);
            $this->assertSitemapUrlHasCluster($xml, $home[$tag], $home, $locale.' home');
        }

        $usXml = $this->get('/sitemap-us.xml')->assertOk()->getContent();
        $this->assertStringNotContainsString('<loc>'.url('/us').'</loc>', $usXml);
        $this->assertStringNotContainsString('<loc>'.url('/us/marketplace').'</loc>', $usXml);

        foreach (['en', 'de'] as $locale) {
            $xml = $this->get('/sitemap-'.$locale.'.xml')->assertOk()->getContent();
            $tag = PublicI18n::hreflang($locale);
            $this->assertSitemapUrlHasCluster($xml, $market[$tag], $market, $locale.' marketplace');
        }
    }

    /**
     * @param  array<string, string>  $expected
     */
    private function assertSitemapUrlHasCluster(string $xml, string $loc, array $expected, string $label): void
    {
        $this->assertMatchesRegularExpression(
            '#<loc>'.preg_quote($loc, '#').'</loc>.*?</url>#s',
            $xml,
            $label.' loc missing'
        );
        preg_match_all('#<url>(.*?)</url>#s', $xml, $blocks);
        $found = null;
        foreach ($blocks[1] as $block) {
            if (str_contains($block, '<loc>'.$loc.'</loc>')) {
                $found = $block;
                break;
            }
        }
        $this->assertNotNull($found, $label.' url block');

        $cluster = [];
        preg_match_all(
            '/hreflang="([^"]+)"[^>]*href="([^"]+)"/',
            (string) $found,
            $matches,
            PREG_SET_ORDER
        );
        foreach ($matches as $match) {
            $cluster[$match[1]] = $match[2];
        }

        $this->assertSame($expected, $cluster, $label);
    }
}
