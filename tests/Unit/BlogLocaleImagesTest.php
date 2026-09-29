<?php

namespace Tests\Unit;

use App\Support\BlogLocaleImages;
use App\Support\DofollowNofollowAnchorsEnBlogPost;
use App\Support\DofollowNofollowAnchorsI18n;
use App\Support\DofollowNofollowAnkertexteBlogPost;
use App\Support\GuestPostingGuideBlogPost;
use App\Support\GuestPostingGuideI18n;
use App\Support\HowToGetBacklinksBlogPost;
use App\Support\HowToGetBacklinksI18n;
use App\Support\LinkBuildingGuideBlogPost;
use App\Support\LinkBuildingGuideI18n;
use App\Support\SponsoredPostGuideBlogPost;
use App\Support\SponsoredPostGuideI18n;
use Tests\TestCase;

class BlogLocaleImagesTest extends TestCase
{
    public function test_locale_svg_replaces_english_diagram_when_the_file_exists(): void
    {
        $this->assertSame(
            'guest-posting-guide-workflow-de.svg',
            BlogLocaleImages::inlineFilename(GuestPostingGuideBlogPost::IMAGE_WORKFLOW, 'de')
        );
        $this->assertSame(
            GuestPostingGuideBlogPost::IMAGE_WORKFLOW,
            BlogLocaleImages::inlineFilename(GuestPostingGuideBlogPost::IMAGE_WORKFLOW, 'en')
        );
        $this->assertSame(
            DofollowNofollowAnchorsEnBlogPost::IMAGE_TYPES,
            BlogLocaleImages::inlineFilename(DofollowNofollowAnchorsEnBlogPost::IMAGE_TYPES, 'de')
        );
    }

    public function test_translated_pillars_embed_diagrams_in_that_language(): void
    {
        $cases = [
            [GuestPostingGuideI18n::all(), GuestPostingGuideBlogPost::IMAGE_WORKFLOW],
            [HowToGetBacklinksI18n::all(), HowToGetBacklinksBlogPost::IMAGE_METHODS],
            [LinkBuildingGuideI18n::all(), LinkBuildingGuideBlogPost::IMAGE_ROADMAP],
            [SponsoredPostGuideI18n::all(), SponsoredPostGuideBlogPost::IMAGE_COMPARE],
        ];

        foreach ($cases as [$posts, $english]) {
            $base = preg_replace('/\.(jpe?g|png|webp)$/i', '', $english);
            foreach (['de', 'fr', 'nl', 'it'] as $locale) {
                $html = $posts[$locale]['content'];
                $this->assertStringContainsString($base.'-'.$locale.'.svg', $html);
                $this->assertStringNotContainsString($english, $html);
            }
        }

        foreach (['de', 'it'] as $locale) {
            $html = DofollowNofollowAnchorsI18n::all()[$locale]['content'];
            $this->assertStringContainsString('market-dofollow-anchors-en-mix-'.$locale.'.svg', $html);
            $this->assertStringNotContainsString(DofollowNofollowAnchorsEnBlogPost::IMAGE_MIX, $html);
            $this->assertStringContainsString(DofollowNofollowAnchorsEnBlogPost::IMAGE_TYPES, $html);
        }

        $germanOnly = DofollowNofollowAnkertexteBlogPost::contentHtml();
        $this->assertStringContainsString('dofollow-nofollow-ankertexte-mix-de.svg', $germanOnly);
        $this->assertStringNotContainsString(DofollowNofollowAnkertexteBlogPost::IMAGE_ANCHOR_MIX, $germanOnly);
        $this->assertSame(
            'dofollow-nofollow-ankertexte-featured-de.svg',
            BlogLocaleImages::inlineFilename(basename(DofollowNofollowAnkertexteBlogPost::FEATURED_ASSET), 'de')
        );
    }

    public function test_locale_svgs_are_well_formed(): void
    {
        $files = array_values(array_filter(
            glob(public_path('assets/img/blog/*.svg')) ?: [],
            static fn (string $file): bool => preg_match('/-(de|fr|nl|it)\.svg$/', $file) === 1
        ));
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $dom = new \DOMDocument;
            $this->assertTrue($dom->load($file), basename($file).' is not valid XML');
            $this->assertSame(0, substr_count(strtolower((string) file_get_contents($file)), '<script'));
        }
    }
}
