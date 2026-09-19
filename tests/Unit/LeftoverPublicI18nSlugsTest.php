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

        $src = (string) file_get_contents(base_path('app/Support/PublicI18n.php'));
        $classPos = strpos($src, 'class PublicI18n');
        $methodPos = strpos($src, 'function englishOnlyMarketingSlugs');
        $this->assertNotFalse($classPos);
        $this->assertNotFalse($methodPos);
        $this->assertLessThan(800, $methodPos - $classPos);
    }

    public function test_leftover_web_php_guard_does_not_fatal_when_method_is_missing(): void
    {
        $script = <<<'PHP'
<?php
namespace App\Support {
    class PublicI18n
    {
        public static function supported(): array
        {
            return ['en'];
        }
    }
}

namespace {
    $englishOnlyMarketingSlugs = ['guest-post-prices-europe'];
    try {
        if (class_exists(\App\Support\PublicI18n::class)
            && method_exists(\App\Support\PublicI18n::class, 'englishOnlyMarketingSlugs')) {
            $englishOnlyMarketingSlugs = \App\Support\PublicI18n::englishOnlyMarketingSlugs();
        }
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage());
        exit(1);
    }
    echo json_encode($englishOnlyMarketingSlugs);
}
PHP;

        $tmp = tempnam(sys_get_temp_dir(), 'slb_i18n_guard_');
        file_put_contents($tmp, $script);
        $output = [];
        $exit = 0;
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($tmp).' 2>&1', $output, $exit);
        @unlink($tmp);

        $this->assertSame(0, $exit, implode("\n", $output));
        $this->assertSame(['guest-post-prices-europe'], json_decode(implode('', $output), true));
    }

    public function test_injector_lets_leftover_web_php_call_english_only_marketing_slugs(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'slb_i18n_'.bin2hex(random_bytes(4));
        mkdir($dir);
        $leftover = $dir.DIRECTORY_SEPARATOR.'PublicI18n.php';
        file_put_contents($leftover, <<<'PHP'
<?php

namespace App\Support;

class PublicI18n
{
    public static function supported(): array
    {
        return ['en'];
    }
}
PHP);

        $injector = base_path('app/Support/LeftoverPublicI18nSlugs.php');
        $helper = base_path('app/Support/EnglishOnlyMarketingSlugs.php');
        $script = $dir.DIRECTORY_SEPARATOR.'boot.php';
        file_put_contents($script, <<<PHP
<?php
require_once {$this->phpString($helper)};
require_once {$this->phpString($injector)};
\\App\\Support\\LeftoverPublicI18nSlugs::ensureEnglishOnlyMarketingSlugsMethod({$this->phpString($leftover)});
\$englishOnlyMarketingSlugs = class_exists(\\App\\Support\\PublicI18n::class)
    ? \\App\\Support\\PublicI18n::englishOnlyMarketingSlugs()
    : ['guest-post-prices-europe'];
echo json_encode(\$englishOnlyMarketingSlugs);
PHP);

        $output = [];
        $exit = 0;
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' 2>&1', $output, $exit);
        @unlink($script);
        @unlink($leftover);
        @rmdir($dir);

        $this->assertSame(0, $exit, implode("\n", $output));
        $decoded = json_decode(implode('', $output), true);
        $this->assertIsArray($decoded);
        $this->assertContains('guest-post-prices-europe', $decoded);
    }

    private function phpString(string $value): string
    {
        return var_export($value, true);
    }
}
