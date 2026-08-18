<?php

namespace Tests\Unit;

use App\Models\Site;
use App\Support\CatalogPlaceholderListing;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CatalogPlaceholderListingTest extends TestCase
{
    #[DataProvider('descriptionProvider')]
    public function test_description_looks_placeholder(string $description, bool $expected): void
    {
        $this->assertSame($expected, CatalogPlaceholderListing::descriptionLooksPlaceholder($description));
    }

    public static function descriptionProvider(): array
    {
        return [
            'lorem' => ['Lorem Ipsum is simply dummy text for testing purposes.', true],
            'onboarding' => ['Please replace this placeholder with a real site description before review.', true],
            'real' => ['Guest posts on a German finance magazine for founders.', false],
            'empty' => ['', false],
        ];
    }

    #[DataProvider('hostProvider')]
    public function test_host_looks_placeholder(string $host, bool $expected): void
    {
        $this->assertSame($expected, CatalogPlaceholderListing::hostLooksPlaceholder($host));
    }

    public static function hostProvider(): array
    {
        return [
            'demo86' => ['https://demo86.com/example', true],
            'example.com' => ['https://www.example.com/x', true],
            'fixture tld' => ['https://expand-correct.example/sample', false],
            'real' => ['https://publisher-magazine.de/guest', false],
        ];
    }

    public function test_matches_site_on_lorem_or_demo_host(): void
    {
        $lorem = new Site([
            'description' => 'Lorem Ipsum dummy',
            'site_url' => 'https://real-publisher.de',
            'domain' => 'real-publisher.de',
            'example_url' => 'https://real-publisher.de/sample',
        ]);
        $this->assertTrue(CatalogPlaceholderListing::matches($lorem));

        $demo = new Site([
            'description' => 'A real brief about auto repair shops.',
            'site_url' => 'https://demo86.com',
            'domain' => 'demo86.com',
            'example_url' => 'https://demo86.com/example',
        ]);
        $this->assertTrue(CatalogPlaceholderListing::matches($demo));

        $live = new Site([
            'description' => 'A real brief about auto repair shops.',
            'site_url' => 'https://real-publisher.de',
            'domain' => 'real-publisher.de',
            'example_url' => 'https://real-publisher.de/sample',
        ]);
        $this->assertFalse(CatalogPlaceholderListing::matches($live));
    }
}
