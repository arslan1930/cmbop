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
