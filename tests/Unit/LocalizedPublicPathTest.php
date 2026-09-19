<?php

namespace Tests\Unit;

use App\Support\LocalizedPublicPath;
use App\Support\PublicI18n;
use Tests\TestCase;

class LocalizedPublicPathTest extends TestCase
{
    public function test_german_and_french_page_slugs_are_translated(): void
    {
        $this->assertSame('ueber-uns', LocalizedPublicPath::for('about', 'de'));
        $this->assertSame('marktplatz', LocalizedPublicPath::for('marketplace', 'de'));
        $this->assertSame('a-propos', LocalizedPublicPath::for('about', 'fr'));
        $this->assertSame('comment-ca-marche', LocalizedPublicPath::for('how-it-works', 'fr'));
        $this->assertSame('blog', LocalizedPublicPath::for('blog', 'de'));
        $this->assertSame('about', LocalizedPublicPath::for('about', 'en'));
        $this->assertSame('about', LocalizedPublicPath::for('about', 'us'));
        $this->assertSame('ueber-uns', LocalizedPublicPath::for('about', 'at'));
        $this->assertSame('marktplatz', LocalizedPublicPath::for('marketplace', 'ch'));
        $this->assertSame('despre-noi', LocalizedPublicPath::for('about', 'ro'));
        $this->assertSame('agora', LocalizedPublicPath::for('marketplace', 'gr'));
        $this->assertSame('o-nas', LocalizedPublicPath::for('about', 'pl'));
        $this->assertSame('rynek', LocalizedPublicPath::for('marketplace', 'pl'));
    }

    public function test_canonicalize_round_trips_localized_segments(): void
    {
        $this->assertSame('about', LocalizedPublicPath::toEnglish('ueber-uns'));
        $this->assertSame('about', LocalizedPublicPath::canonicalize('ueber-uns'));
        $this->assertSame('blog/deutscher-titel', LocalizedPublicPath::canonicalize('blog/deutscher-titel'));
        $this->assertSame('marketplace', LocalizedPublicPath::toEnglish('marktplatz'));
        $this->assertSame('a-propos', LocalizedPublicPath::localize('ueber-uns', 'fr'));
        $this->assertSame('ueber-uns', LocalizedPublicPath::localize('about', 'de'));
    }

    public function test_public_path_includes_locale_prefix(): void
    {
        $this->assertSame('/about', LocalizedPublicPath::publicPath('about', 'en'));
        $this->assertSame('/de/ueber-uns', LocalizedPublicPath::publicPath('about', 'de'));
        $this->assertSame('/us/about', LocalizedPublicPath::publicPath('about', 'us'));
        $this->assertSame('/de/blog', LocalizedPublicPath::publicPath('blog', 'de'));
    }

    public function test_url_for_locale_uses_translated_page_paths(): void
    {
        $this->assertSame(url('/de/ueber-uns'), PublicI18n::urlForLocale('about', 'de'));
        $this->assertSame(url('/de/marktplatz'), PublicI18n::urlForLocale('marketplace', 'de'));
        $this->assertSame(url('/fr/a-propos'), PublicI18n::urlForLocale('ueber-uns', 'fr'));
        $this->assertSame(url('/about'), PublicI18n::urlForLocale('about', 'en'));
    }
}
