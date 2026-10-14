<?php

namespace Tests\Unit;

use App\Support\PostalCode;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PostalCodeTest extends TestCase
{
    #[DataProvider('validCodes')]
    public function test_valid_postal_codes(string $raw, string $normalized): void
    {
        $this->assertTrue(PostalCode::isValid($raw));
        $this->assertSame($normalized, PostalCode::normalize($raw));
    }

    #[DataProvider('invalidCodes')]
    public function test_invalid_postal_codes(string $raw): void
    {
        $this->assertFalse(PostalCode::isValid($raw));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function validCodes(): array
    {
        return [
            'uk_spaced' => ['sw1a 0aa', 'SW1A 0AA'],
            'uk_compact' => ['SW1A0AA', 'SW1A0AA'],
            'us_zip' => ['78701', '78701'],
            'hyphen' => ['K1A-0B1', 'K1A-0B1'],
            'two_chars' => ['A1', 'A1'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidCodes(): array
    {
        return [
            'too_short' => ['A'],
            'too_long' => ['12345678901'],
            'symbols' => ['SW1A!'],
            'blank' => ['   '],
        ];
    }
}
