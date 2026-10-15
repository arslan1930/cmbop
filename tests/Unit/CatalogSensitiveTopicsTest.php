<?php

namespace Tests\Unit;

use App\Support\CatalogSensitiveTopics;
use Tests\TestCase;

class CatalogSensitiveTopicsTest extends TestCase
{
    public function test_filter_types_exclude_casino(): void
    {
        $this->assertSame(['crypto', 'trading', 'cbd', 'forex'], CatalogSensitiveTopics::FILTER_TYPES);
        foreach (CatalogSensitiveTopics::JSON_PATHS as $path) {
            $this->assertStringNotContainsString('casino', strtolower($path));
        }
        $this->assertNull(CatalogSensitiveTopics::normalizeType('casino'));
        $this->assertSame('crypto', CatalogSensitiveTopics::normalizeType('Crypto'));
    }

    public function test_chip_ignores_casino_and_summarizes_niches(): void
    {
        $this->assertNull(CatalogSensitiveTopics::chip(['casino' => 80]));

        $one = CatalogSensitiveTopics::chip(['crypto' => 25, 'casino' => 90]);
        $this->assertNotNull($one);
        $this->assertSame(['crypto'], $one['types']);
        $this->assertStringContainsString('Crypto', $one['label']);
        $this->assertStringNotContainsString('Casino', $one['label']);

        $many = CatalogSensitiveTopics::chip([
            'crypto' => 20,
            'trading' => 15,
            'cbd' => 30,
            'forex' => 40,
        ]);
        $this->assertNotNull($many);
        $this->assertStringContainsString('Sensitive topics from', $many['label']);
        $this->assertSame(15.0, $many['min']);
    }
}
