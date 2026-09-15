<?php

namespace Tests\Unit;

use App\Support\Totp;
use Tests\TestCase;

class TotpTest extends TestCase
{
    public function test_rfc6238_sha1_samples(): void
    {
        $secret = '12345678901234567890';

        $this->assertSame('287082', Totp::hotp($secret, intdiv(59, 30)));
        $this->assertSame('081804', Totp::hotp($secret, intdiv(1111111109, 30)));
        $this->assertSame('050471', Totp::hotp($secret, intdiv(1111111111, 30)));
    }

    public function test_generated_secret_round_trips(): void
    {
        $secret = Totp::secret();
        $now = 1_700_000_000;
        $code = Totp::at($secret, $now);

        $this->assertTrue(Totp::verify($secret, $code, 1, $now)['ok']);
        $this->assertFalse(Totp::verify($secret, '000000', 1, $now)['ok']);
        $this->assertSame(
            intdiv($now, Totp::PERIOD),
            Totp::verify($secret, $code, 1, $now)['step']
        );
    }

    public function test_replay_step_is_rejected(): void
    {
        $secret = Totp::secret();
        $now = 1_700_000_030;
        $code = Totp::at($secret, $now);
        $step = intdiv($now, Totp::PERIOD);

        $this->assertFalse(Totp::verify($secret, $code, 1, $now, $step)['ok']);
        $this->assertTrue(Totp::verify($secret, $code, 1, $now, $step - 1)['ok']);
    }
}
