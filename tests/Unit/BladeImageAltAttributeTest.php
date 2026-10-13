<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BladeImageAltAttributeTest extends TestCase
{
    #[Test]
    public function test_blade_images_outside_email_templates_have_an_alt_attribute(): void
    {
        $offenders = [];

        foreach ($this->bladeFiles() as $path) {
            $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
            $text = $this->stripComments((string) file_get_contents($path));

            foreach ($this->imgTags($text) as $tag) {
                if (! preg_match('/\balt\s*=/i', $tag)) {
                    $offenders[] = $relative.': '.preg_replace('/\s+/', ' ', trim($tag));
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Blade <img> tags must include an alt attribute (use alt=\"\" when decorative):\n".implode("\n", $offenders)
        );
    }

    #[Test]
    public function test_metric_source_logos_have_non_empty_alt_in_blade_and_js(): void
    {
        $expected = [
            'assets/img/traffic.svg' => 'Organic traffic',
            'assets/img/ahref.jpeg' => 'Ahrefs DR',
            'assets/img/moz_da.png' => 'Moz DA',
        ];

        $partial = (string) file_get_contents(resource_path('views/advertiser/partials/metric-source.blade.php'));
        foreach ($expected as $file => $alt) {
            $this->assertStringContainsString($file, $partial);
            $this->assertStringContainsString("'alt' => '".$alt."'", $partial, $file.' must map to alt="'.$alt.'"');
        }
        $this->assertStringContainsString('alt="{{ $source[\'alt\'] }}"', $partial);
        $this->assertStringNotContainsString('alt=""', $this->stripComments($partial));

        $offenders = [];
        foreach (array_merge($this->bladeFiles(), $this->jsFiles()) as $path) {
            $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
            $text = $this->stripComments((string) file_get_contents($path));

            foreach ($this->imgTags($text) as $tag) {
                if (! $this->isMetricSourceLogoTag($tag, array_keys($expected))) {
                    continue;
                }
                if (! preg_match('/\balt\s*=\s*(["\'])((?:\\\\.|(?!\1).)*)\1/s', $tag, $altMatch)) {
                    $offenders[] = $relative.': metric logo <img> is missing alt';

                    continue;
                }
                $value = trim(stripslashes($altMatch[2]));
                if ($value === '') {
                    $offenders[] = $relative.': metric logo <img> has empty alt';
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Metric-source logos must have a non-empty descriptive alt:\n".implode("\n", $offenders)
        );
    }

    /**
     * @return list<string>
     */
    private function bladeFiles(): array
    {
        $root = resource_path('views');
        $paths = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $relative = str_replace($root.DIRECTORY_SEPARATOR, '', $file->getPathname());
            $normalized = str_replace('\\', '/', $relative);
            if (str_starts_with($normalized, 'emails/') || str_contains($normalized, 'vendor/mail')) {
                continue;
            }

            $paths[] = $file->getPathname();
        }

        sort($paths);

        return $paths;
    }

    /**
     * @return list<string>
     */
    private function jsFiles(): array
    {
        $roots = [
            public_path('assets/js'),
            public_path('js'),
            resource_path('js'),
        ];
        $paths = [];

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($iterator as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.js')) {
                    $paths[] = $file->getPathname();
                }
            }
        }

        sort($paths);

        return $paths;
    }

    /**
     * @param  list<string>  $files
     */
    private function isMetricSourceLogoTag(string $tag, array $files): bool
    {
        foreach ($files as $file) {
            if (str_contains($tag, $file)) {
                return true;
            }
        }

        if (preg_match('/(?:semrush|majestic)[^"\']*\.(?:svg|png|jpe?g|webp)/i', $tag) === 1) {
            return true;
        }

        // Shared partial: src is asset($source['file']), alt comes from the map.
        return str_contains($tag, '$source[\'file\']')
            || str_contains($tag, '$source["file"]')
            || str_contains($tag, 'asset($source[\'file\'])');
    }

    private function stripComments(string $text): string
    {
        $text = preg_replace('/{{--.*?--}}/s', '', $text) ?? $text;
        $text = preg_replace('/<!--.*?-->/s', '', $text) ?? $text;
        $text = preg_replace('/^\s*\/\/.*$/m', '', $text) ?? $text;
        $text = preg_replace('/\/\*.*?\*\//s', '', $text) ?? $text;

        return $text;
    }

    /**
     * @return list<string>
     */
    private function imgTags(string $text): array
    {
        $tags = [];
        $length = strlen($text);
        $offset = 0;

        while ($offset < $length) {
            $start = stripos($text, '<img', $offset);
            if ($start === false) {
                break;
            }

            $after = $start + 4;
            if ($after < $length && ! preg_match('/[\s\/]/', $text[$after]) && $text[$after] !== '>') {
                $offset = $after;

                continue;
            }

            $quote = null;
            $blade = false;
            $j = $after;
            while ($j < $length) {
                if ($blade) {
                    if (substr($text, $j, 2) === '}}') {
                        $blade = false;
                        $j += 2;

                        continue;
                    }
                    $j++;

                    continue;
                }
                if ($quote !== null) {
                    if ($text[$j] === $quote) {
                        $quote = null;
                    }
                    $j++;

                    continue;
                }
                if (substr($text, $j, 2) === '{{') {
                    $blade = true;
                    $j += 2;

                    continue;
                }
                if ($text[$j] === '"' || $text[$j] === "'") {
                    $quote = $text[$j];
                    $j++;

                    continue;
                }
                if ($text[$j] === '>') {
                    $tags[] = substr($text, $start, $j - $start + 1);
                    $offset = $j + 1;

                    continue 2;
                }
                $j++;
            }

            break;
        }

        return $tags;
    }
}
