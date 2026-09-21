<?php

namespace Tests\Unit;

use App\Models\Site;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SiteCatalogLabelHelpersTest extends TestCase
{
    #[DataProvider('publicationDurationProvider')]
    public function test_publication_duration_label(string $raw, string $expected): void
    {
        $site = new Site(['publication_time' => $raw]);

        $this->assertSame($expected, $site->publicationDurationLabel());
    }

    public static function publicationDurationProvider(): array
    {
        return [
            ['1year', '1 year'],
            ['6months', '6 months'],
            ['permanent', 'Permanent'],
            ['7 days', '7 days'],
        ];
    }

    #[DataProvider('turnaroundProvider')]
    public function test_turnaround_label(string $raw, string $expected): void
    {
        $site = new Site(['turnaround_time' => $raw]);

        $this->assertSame($expected, $site->turnaroundLabel());
    }

    public static function turnaroundProvider(): array
    {
        return [
            ['24h', '24 hours'],
            ['3days', '3 days'],
            ['7days', '7 days'],
        ];
    }

    public function test_link_type_label(): void
    {
        $this->assertSame('DoFollow', (new Site(['link_type' => 'dofollow']))->linkTypeLabel());
        $this->assertSame('NoFollow', (new Site(['link_type' => 'nofollow']))->linkTypeLabel());
        $this->assertNull((new Site(['link_type' => null]))->linkTypeLabel());
        $this->assertSame('Not specified', (new Site(['link_type' => '']))->linkTypeLabel('Not specified'));
        $this->assertNull((new Site(['link_type' => '???']))->linkTypeLabel());
        $this->assertNull((new Site(['link_type' => 'guest']))->linkTypeLabel());
    }

    public function test_language_codes_survive_leftover_json_junk(): void
    {
        $site = new Site([
            'language' => 'de',
            'languages' => 'not-json',
        ]);

        $this->assertSame(['de'], $site->languageCodes());
    }
}
