<?php

namespace Tests\Unit;

use App\Support\CountryCode;
use Tests\TestCase;

class CountryCodeTest extends TestCase
{
    public function test_normalizes_names_and_dirty_codes_to_iso2(): void
    {
        $this->assertSame('es', CountryCode::normalize('Spain'));
        $this->assertSame('es', CountryCode::normalize('España'));
        $this->assertSame('es', CountryCode::normalize('ES'));
        $this->assertSame('it', CountryCode::normalize('Italy'));
        $this->assertSame('it', CountryCode::normalize('Italia'));
        $this->assertSame('uk', CountryCode::normalize('GB'));
        $this->assertSame('uk', CountryCode::normalize('United Kingdom'));
        $this->assertSame('us', CountryCode::normalize('USA'));
        $this->assertNull(CountryCode::normalize('not-a-country'));
        $this->assertNull(CountryCode::normalize(''));
    }

    public function test_display_name_prefers_a_readable_label(): void
    {
        $this->assertNotSame('', CountryCode::displayName('ES'));
        $this->assertTrue(mb_strlen(CountryCode::displayName('ES')) >= 2);
        $this->assertSame('—', CountryCode::displayName(null));
        $this->assertSame('mystery-land', CountryCode::displayName('mystery-land'));
    }
}
