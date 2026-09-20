<?php

namespace Tests\Feature;

use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoLeftoverClassHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_boot_and_save_paths_guard_missing_seo_stack_classes(): void
    {
        $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));
        $this->assertStringContainsString('CanonicalHost.php', $bootstrap);
        $this->assertStringContainsString('TrustedProxies.php', $bootstrap);
        $this->assertStringContainsString('SetLocale.php', $bootstrap);
        $this->assertStringContainsString('SecurityHeaders.php', $bootstrap);
        $this->assertStringContainsString('$loadAppClass', $bootstrap);
        $this->assertStringContainsString('class_exists(ContentUploadService::class)', $bootstrap);
        $this->assertStringContainsString('prependToGroup(\'web\', CanonicalHost::class)', $bootstrap);
        $this->assertStringContainsString('LeftoverPublicI18nSlugs.php', $bootstrap);
        $this->assertStringContainsString('ensureEnglishOnlyMarketingSlugsMethod', $bootstrap);

        $web = (string) file_get_contents(base_path('routes/web.php'));
        $this->assertStringContainsString('class_exists(CountryLander::class)', $web);
        $this->assertStringContainsString('class_exists(CatalogTeaserService::class)', $web);
        $this->assertStringContainsString('class_exists(LocalizedPublicPath::class)', $web);
        $this->assertStringContainsString('class_exists(PublicI18n::class)', $web);
        $this->assertStringContainsString("method_exists(PublicI18n::class, 'englishOnlyMarketingSlugs')", $web);
        $this->assertStringContainsString("method_exists(PublicI18n::class, 'prefixed')", $web);
        $this->assertStringContainsString("method_exists(PublicI18n::class, 'supported')", $web);
        $this->assertStringContainsString("method_exists(PublicI18n::class, 'catalogTeaserCountries')", $web);
        $this->assertStringContainsString('PublicI18n::englishOnlyMarketingSlugs()', $web);
        $this->assertStringContainsString('class_exists(EnglishOnlyMarketingSlugs::class)', $web);
        $this->assertStringContainsString('} catch (Throwable)', $web);
        $this->assertStringContainsString('class_exists(RobotsTxt::class)', $web);
        $this->assertStringContainsString("method_exists(RobotsTxt::class, 'render')", $web);

        $composer = (string) file_get_contents(base_path('composer.json'));
        $this->assertStringContainsString('leftover_public_i18n_boot.php', $composer);
        $this->assertStringContainsString('leftover_public_i18n_slugs_boot.php', $composer);
        $this->assertFileExists(base_path('app/Support/leftover_public_i18n_boot.php'));
        $this->assertFileExists(base_path('app/Support/leftover_public_i18n_slugs_boot.php'));

        $artisan = (string) file_get_contents(base_path('artisan'));
        $index = (string) file_get_contents(base_path('public/index.php'));
        $helper = (string) file_get_contents(base_path('app/Helpers/LanguageHelper.php'));
        $this->assertStringContainsString('leftover_public_i18n_boot.php', $artisan);
        $this->assertStringContainsString('ensureEnglishOnlyMarketingSlugsMethod', $artisan);
        $this->assertStringContainsString('leftover_public_i18n_boot.php', $index);
        $this->assertStringContainsString('ensureEnglishOnlyMarketingSlugsMethod', $index);
        $this->assertStringContainsString('ensureEnglishOnlyMarketingSlugsMethod', $helper);

        $controller = (string) file_get_contents(base_path('app/Http/Controllers/MarketingPageController.php'));
        $this->assertStringContainsString('class_exists(CountryLander::class)', $controller);
        $this->assertStringContainsString('class_exists(GuestPostPriceIndex::class)', $controller);
        $this->assertStringContainsString('class_exists(CatalogTeaserService::class)', $controller);
        $this->assertStringContainsString('catalogTeaserService()', $controller);
        $this->assertStringContainsString("view()->exists('pages.guest-posts-country')", $controller);
        $this->assertStringContainsString("view()->exists('pages.guest-post-prices-europe')", $controller);
        $this->assertStringNotContainsString('$teasers->teasersForCountries', $controller);

        $sitemap = (string) file_get_contents(base_path('app/Http/Controllers/SitemapController.php'));
        $this->assertStringContainsString('class_exists(CountryLander::class)', $sitemap);
        $this->assertStringContainsString('class_exists(GuestPostPriceIndex::class)', $sitemap);
        $this->assertStringContainsString('class_exists(PublicI18n::class)', $sitemap);
        $this->assertStringContainsString("method_exists(PublicI18n::class, 'supported')", $sitemap);
        $this->assertStringContainsString("method_exists(PublicI18n::class, 'urlForLocale')", $sitemap);
        $this->assertStringContainsString('class_exists(ThinBlogRedirects::class)', $sitemap);
        $this->assertStringContainsString('method_exists(Blog::class, \'scopeWithoutLegacyRedirects\')', $sitemap);

        $site = (string) file_get_contents(base_path('app/Models/Site.php'));
        $this->assertStringContainsString('class_exists(GuestPostPriceIndex::class)', $site);
        $this->assertStringContainsString('class_exists(CatalogCountryInventory::class)', $site);

        $staffSites = (string) file_get_contents(base_path('app/Http/Controllers/Admin/SiteController.php'));
        $this->assertStringContainsString('class_exists(SiteTag::class)', $staffSites);
        $this->assertStringContainsString('persistStaffSiteImagePath($site, $imagePath)', $staffSites);

        $promoIndex = (string) file_get_contents(base_path('resources/views/admin/promotions/announcements/index.blade.php'));
        $promoForm = (string) file_get_contents(base_path('resources/views/admin/promotions/announcements/form.blade.php'));
        $this->assertStringContainsString('class_exists(\\App\\Support\\PromotionCampaignHandoff::class)', $promoIndex);
        $this->assertStringContainsString('class_exists(\\App\\Support\\PromotionCampaignHandoff::class)', $promoForm);

        $i18n = (string) file_get_contents(base_path('app/Support/PublicI18n.php'));
        $this->assertStringContainsString('class_exists(LocalizedPublicPath::class)', $i18n);
        $this->assertStringContainsString("method_exists(self::class, 'englishOnlyMarketingSlugs')", $i18n);
        $this->assertStringContainsString("method_exists(self::class, 'isEnglishOnlyMarketingPath')", $i18n);

        $about = (string) file_get_contents(base_path('resources/views/pages/about.blade.php'));
        $prices = (string) file_get_contents(base_path('resources/views/pages/guest-post-prices-europe.blade.php'));
        $layout = (string) file_get_contents(base_path('resources/views/layouts/app.blade.php'));
        $helper = (string) file_get_contents(base_path('app/Helpers/LanguageHelper.php'));
        $this->assertStringContainsString("method_exists(\\App\\Support\\BrandOrganization::class, 'schema')", $about);
        $this->assertStringContainsString("method_exists(\\App\\Support\\BrandOrganization::class, 'schema')", $prices);
        $this->assertStringContainsString("view()->exists('components.language-suggestion')", $layout);
        $this->assertStringContainsString('class_exists(\\App\\Support\\BrandOrganization::class)', $layout);
        $this->assertStringContainsString('class_exists(\\App\\Support\\MarketingCssBundle::class)', $layout);
        $this->assertStringContainsString("method_exists(\\App\\Support\\BrandOrganization::class, 'pageGraphJson')", $layout);
        $this->assertStringContainsString("method_exists(\\App\\Support\\MarketingCssBundle::class, 'urlIfReady')", $layout);
        $this->assertStringContainsString("method_exists(\\App\\Support\\PublicI18n::class, 'robotsContent')", $layout);
        $this->assertStringContainsString('urlIfReady', $layout);
        $this->assertStringContainsString('pageGraphJson', $layout);
        $this->assertStringContainsString('jsonLd', (string) file_get_contents(base_path('app/Support/BrandOrganization.php')));
        $this->assertStringContainsString('assets/css/type-system.css', $layout);
        $this->assertStringContainsString('assets/css/hover-system.css', $layout);
        $this->assertStringContainsString('assets/css/slb-icons.css', $layout);
        $this->assertStringContainsString('class_exists(\\App\\Support\\PublicI18n::class)', $layout);
        $this->assertStringContainsString('JSON_HEX_TAG', (string) file_get_contents(resource_path('views/home.blade.php')));
        $this->assertStringContainsString('JSON_HEX_TAG', (string) file_get_contents(resource_path('views/pages/about.blade.php')));
        $this->assertStringContainsString('JSON_HEX_TAG', (string) file_get_contents(resource_path('views/components/breadcrumbs.blade.php')));
        $this->assertStringContainsString('class_exists(PublicI18n::class)', $helper);
        $this->assertStringContainsString("method_exists(PublicI18n::class, 'supported')", $helper);
        $this->assertStringContainsString("method_exists(PublicI18n::class, 'switchUrl')", $helper);
        $this->assertStringContainsString("method_exists(PublicI18n::class, 'urlForLocale')", $helper);
        $this->assertStringContainsString("method_exists(PublicI18n::class, 'shouldShowLanguageSwitcher')", $helper);
        $this->assertStringContainsString("method_exists(WelcomeBonusCopy::class, 'message')", $helper);

        $navbar = (string) file_get_contents(base_path('resources/views/components/navbar.blade.php'));
        $suggestion = (string) file_get_contents(base_path('resources/views/components/language-suggestion.blade.php'));
        $this->assertStringContainsString("method_exists(PublicI18n::class, 'rememberedPublicLocale')", $navbar);
        $this->assertStringContainsString("method_exists(\\App\\Support\\PublicI18n::class, 'htmlLang')", $navbar);
        $this->assertStringContainsString("method_exists(PublicI18n::class, 'preferredFromBrowser')", $suggestion);
        $this->assertStringContainsString("method_exists(\\App\\Support\\PublicI18n::class, 'htmlLang')", $suggestion);
        $this->assertStringContainsString("method_exists(\\App\\Support\\PublicI18n::class, 'htmlLang')", (string) file_get_contents(resource_path('views/home.blade.php')));

        $marketplace = (string) file_get_contents(base_path('resources/views/pages/marketplace.blade.php'));
        $lander = (string) file_get_contents(base_path('resources/views/pages/guest-posts-country.blade.php'));
        $this->assertStringContainsString("view()->exists('advertiser.partials.metric-source')", (string) file_get_contents(base_path('resources/views/components/hero-catalog-preview.blade.php')));
        $this->assertStringContainsString("view()->exists('components.hero-catalog-preview')", (string) file_get_contents(base_path('resources/views/components/hero.blade.php')));
        $this->assertStringContainsString("view()->exists('components.hero')", (string) file_get_contents(base_path('resources/views/home.blade.php')));
        $this->assertStringContainsString('slb-hero-catalog-clone', (string) file_get_contents(base_path('resources/views/components/hero.blade.php')));
        $this->assertStringContainsString("@include('components.hero')", (string) file_get_contents(base_path('resources/views/home.blade.php')));
        $this->assertStringNotContainsString('dashboard.png', (string) file_get_contents(base_path('resources/views/components/hero.blade.php')));
        $this->assertStringContainsString("view()->exists('components.country-lander-nav')", $marketplace);
        $this->assertStringContainsString("view()->exists('components.country-lander-nav')", $lander);
        $this->assertStringContainsString("view()->exists('components.country-lander-nav')", $prices);

        $sync = (string) file_get_contents(base_path('app/Services/CuratedBlogSync.php'));
        $this->assertStringContainsString('class_exists(BlogTranslationSlug::class)', $sync);
        $this->assertStringContainsString('class_exists(PublicI18n::class)', $sync);

        $setLocale = (string) file_get_contents(base_path('app/Http/Middleware/SetLocale.php'));
        $this->assertStringContainsString('class_exists(PublicI18n::class)', $setLocale);
        $this->assertStringContainsString("method_exists(PublicI18n::class, 'isEnglishOnlyPath')", $setLocale);

        $blogModel = (string) file_get_contents(base_path('app/Models/Blog.php'));
        $catalog = (string) file_get_contents(base_path('app/Support/CuratedBlogCatalog.php'));
        $writer = (string) file_get_contents(base_path('app/Services/CuratedBlogWriter.php'));
        $validates = (string) file_get_contents(base_path('app/Http/Requests/Admin/Concerns/ValidatesBlogPost.php'));
        $this->assertStringContainsString('class_exists(PublicI18n::class)', $blogModel);
        $this->assertStringContainsString("method_exists(PublicI18n::class, 'isSupported')", $blogModel);
        $this->assertStringContainsString("method_exists(PublicI18n::class, 'urlForLocale')", $blogModel);
        $this->assertStringContainsString('class_exists(ThinBlogRedirects::class)', $blogModel);
        $this->assertStringContainsString('class_exists(PublicI18n::class)', $catalog);
        $this->assertStringContainsString("method_exists(PublicI18n::class, 'prefixed')", $catalog);
        $this->assertStringContainsString('class_exists(PublicI18n::class)', $writer);
        $this->assertStringContainsString("method_exists(PublicI18n::class, 'isSupported')", $writer);
        $this->assertStringContainsString('class_exists(PublicI18n::class)', $validates);
        $this->assertStringContainsString("method_exists(PublicI18n::class, 'supported')", $validates);

        $localizedPath = (string) file_get_contents(base_path('app/Support/LocalizedPublicPath.php'));
        $this->assertStringContainsString("method_exists(PublicI18n::class, 'isSupported')", $localizedPath);
        $this->assertStringContainsString("method_exists(PublicI18n::class, 'isPrefixed')", $localizedPath);
    }

    public function test_public_money_pages_and_admin_login_stay_up(): void
    {
        foreach (['/', '/about', '/marketplace', '/guest-posts-germany', '/guest-post-prices-europe', '/how-it-works', '/refund-policy', '/login'] as $path) {
            $this->get($path)
                ->assertOk()
                ->assertDontSee('SQLSTATE')
                ->assertDontSee('App\\Models');
        }
        $this->get('/sitemap-en.xml')->assertOk();
        $this->get('/robots.txt')->assertOk();
        $this->get('/de/guest-posts-germany')->assertRedirect('/guest-posts-germany');
        $this->get('/de/guest-post-prices-europe')->assertRedirect('/guest-post-prices-europe');

        $this->assertNull(Site::forgetMarketingCaches());
    }
}
