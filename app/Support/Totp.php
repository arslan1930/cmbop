<?php

namespace App\Support;

/**
 * RFC 6238 TOTP (SHA-1, 6 digits, 30s) compatible with Google Authenticator.
 */
class Totp
{
    public const PERIOD = 30;

    public const DIGITS = 6;

    public const WINDOW = 1;

    public static function secret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function otpAuthUrl(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer.':'.$account);

        return 'otpauth://totp/'.$label.'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return array{ok: bool, step: ?int}
     */
    public static function verify(string $secret, string $code, int $window = self::WINDOW, ?int $at = null, ?int $rejectStep = null): array
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (! preg_match('/^\d{'.self::DIGITS.'}$/', $code)) {
            return ['ok' => false, 'step' => null];
        }

        $raw = self::base32Decode($secret);
        if ($raw === '') {
            return ['ok' => false, 'step' => null];
        }

        $at ??= time();
        $step = intdiv($at, self::PERIOD);

        for ($i = -$window; $i <= $window; $i++) {
            $candidate = $step + $i;
            if ($rejectStep !== null && $candidate === $rejectStep) {
                continue;
            }
            $otp = self::hotp($raw, $candidate);
            if (hash_equals($otp, $code)) {
                return ['ok' => true, 'step' => $candidate];
            }
        }

        return ['ok' => false, 'step' => null];
    }

    public static function at(string $secret, int $unixTime): string
    {
        $raw = self::base32Decode($secret);

        return self::hotp($raw, intdiv($unixTime, self::PERIOD));
    }

    /**
     * RFC 6238 SHA-1 sample: ASCII secret "12345678901234567890".
     */
    public static function hotp(string $rawSecret, int $counter): string
    {
        $binCounter = pack('N*', 0, $counter);
        $hash = hash_hmac('sha1', $binCounter, $rawSecret, true);
        $offset = ord($hash[19]) & 0x0F;
        $truncated = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;

        return str_pad((string) ($truncated % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    public static function base32Encode(string $bytes): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $buffer = 0;
        $bits = 0;
        $out = '';
        $len = strlen($bytes);
        for ($i = 0; $i < $len; $i++) {
            $buffer = ($buffer << 8) | ord($bytes[$i]);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $out .= $alphabet[($buffer >> $bits) & 31];
            }
        }
        if ($bits > 0) {
            $out .= $alphabet[($buffer << (5 - $bits)) & 31];
        }

        return $out;
    }

    public static function base32Decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret) ?? '');
        $map = array_flip(str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'));
        $buffer = 0;
        $bits = 0;
        $out = '';
        $len = strlen($secret);
        for ($i = 0; $i < $len; $i++) {
            if (! isset($map[$secret[$i]])) {
                continue;
            }
            $buffer = ($buffer << 5) | $map[$secret[$i]];
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $out .= chr(($buffer >> $bits) & 255);
            }
        }

        return $out;
    }
}
