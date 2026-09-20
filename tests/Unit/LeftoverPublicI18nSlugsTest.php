<?php

namespace Tests\Unit;

use App\Support\EnglishOnlyMarketingSlugs;
use App\Support\LeftoverPublicI18nSlugs;
use App\Support\PublicI18n;
use Tests\TestCase;

class LeftoverPublicI18nSlugsTest extends TestCase
{
    public function test_injects_missing_method_into_leftover_class_source(): void
    {
        $src = <<<'PHP'
<?php

namespace App\Support;

class PublicI18n
{
    public static function default(): string
    {
        return 'en';
    }
}

PHP;

        $this->assertFalse(LeftoverPublicI18nSlugs::sourceDefinesMethod($src));

        $patched = LeftoverPublicI18nSlugs::injectMethodSource($src);
        $this->assertNotNull($patched);
        $this->assertTrue(LeftoverPublicI18nSlugs::sourceDefinesMethod((string) $patched));
        $this->assertStringContainsString('function englishOnlyMarketingSlugs(): array', (string) $patched);
        $this->assertStringContainsString("return ['guest-post-prices-europe'];", (string) $patched);
        $this->assertLessThan(
            strpos((string) $patched, 'function default'),
            strpos((string) $patched, 'function englishOnlyMarketingSlugs')
        );
    }

    public function test_does_not_duplicate_method_when_source_already_defines_it(): void
    {
        $src = (string) file_get_contents(base_path('app/Support/PublicI18n.php'));
        $this->assertTrue(LeftoverPublicI18nSlugs::sourceDefinesMethod($src));
        $this->assertSame($src, LeftoverPublicI18nSlugs::injectMethodSource($src));
    }

    public function test_current_public_i18n_keeps_the_method_for_leftover_route_files(): void
    {
        LeftoverPublicI18nSlugs::ensureEnglishOnlyMarketingSlugsMethod();

        $this->assertTrue(method_exists(PublicI18n::class, 'englishOnlyMarketingSlugs'));
        $this->assertContains('guest-post-prices-europe', PublicI18n::englishOnlyMarketingSlugs());
        $this->assertSame(
            EnglishOnlyMarketingSlugs::all(),
            PublicI18n::englishOnlyMarketingSlugs()
        );
        $this->assertFalse(LeftoverPublicI18nSlugs::persistMissingMethod(base_path('app/Support/PublicI18n.php')));
    }

    public function test_persists_missing_method_onto_leftover_disk_file(): void
    {
        $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'slb_leftover_public_i18n_'.uniqid('', true).'.php';
        $src = <<<'PHP'
<?php

namespace App\Support;

class PublicI18n
{
    public static function default(): string
    {
        return 'en';
    }
}

PHP;
        file_put_contents($tmp, $src);

        try {
            $this->assertTrue(LeftoverPublicI18nSlugs::persistMissingMethod($tmp));
            $healed = (string) file_get_contents($tmp);
            $this->assertTrue(LeftoverPublicI18nSlugs::sourceDefinesMethod($healed));
            $this->assertFalse(LeftoverPublicI18nSlugs::persistMissingMethod($tmp));
        } finally {
            @unlink($tmp);
        }
    }
}
