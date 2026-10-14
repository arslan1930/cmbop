<?php

namespace Tests\Unit;

use App\Support\MailAddress;
use Tests\TestCase;

class MailAddressTest extends TestCase
{
    public function test_rejects_malformed_and_hostless_addresses(): void
    {
        $this->assertTrue(MailAddress::isValid('ada@example.com'));
        $this->assertFalse(MailAddress::isValid('not-an-email'));
        $this->assertFalse(MailAddress::isValid('user@localhost'));
        $this->assertFalse(MailAddress::isValid(''));
        $this->assertSame('ada@example.com', MailAddress::normalize('Ada@Example.COM'));
        $this->assertNull(MailAddress::normalize('bad@'));
    }
}
