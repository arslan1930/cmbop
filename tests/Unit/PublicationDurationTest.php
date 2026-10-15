<?php

namespace Tests\Unit;

use App\Support\PublicationDuration;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PublicationDurationTest extends TestCase
{
    public function test_options_include_two_and_three_years(): void
    {
        $options = PublicationDuration::options();

        $this->assertSame([
            '6months' => '6 Months',
            '1year' => '1 Year',
            '2years' => '2 Years',
            '3years' => '3 Years',
            'permanent' => 'Permanent',
        ], $options);
        $this->assertSame('in:6months,1year,2years,3years,permanent', PublicationDuration::rule());
        $this->assertStringContainsString('`2years`', PublicationDuration::csvHint());
        $this->assertStringContainsString('`3years`', PublicationDuration::csvHint());
        $this->assertSame('permanent', PublicationDuration::DEFAULT);
        $this->assertArrayHasKey('', PublicationDuration::filterOptions());
        $this->assertSame('Any', PublicationDuration::filterOptions()['']);
        $this->assertSame('2 Years', PublicationDuration::filterOptions()['2years']);
    }

    #[DataProvider('normalizeProvider')]
    public function test_normalize_accepts_aliases(mixed $raw, ?string $expected): void
    {
        $this->assertSame($expected, PublicationDuration::normalize($raw));
    }

    public static function normalizeProvider(): array
    {
        return [
            ['2years', '2years'],
            ['2 Years', '2years'],
            ['2yrs', '2years'],
            ['24months', '2years'],
            ['3years', '3years'],
            ['3 year', '3years'],
            ['36months', '3years'],
            ['6 months', '6months'],
            ['1yr', '1year'],
            ['forever', 'permanent'],
            ['not-a-term', null],
            [null, null],
            [false, null],
        ];
    }

    public function test_catalog_label_uses_sentence_case(): void
    {
        $this->assertSame('2 years', PublicationDuration::catalogLabel('2years'));
        $this->assertSame('3 years', PublicationDuration::catalogLabel('3 Years'));
        $this->assertSame('2 Years', PublicationDuration::label('2years'));
        $this->assertNull(PublicationDuration::label('unknown'));
        $this->assertSame('kept', PublicationDuration::label('unknown', 'kept'));
    }
}
