<?php

namespace App\Services\Partners;

final class PartnerCasino
{
    /** @var list<string> */
    private const KEYWORDS = [
        'casino',
        'casinos',
        'gambling',
        'sportsbook',
        'roulette',
        'slot machine',
        'online slots',
        'poker room',
        'betting site',
        'bookmaker',
    ];

    /**
     * @param  list<string>  $niches
     * @param  list<string>  $rawNiches
     */
    public static function detect(string $siteName, string $description, string $exampleUrl, array $niches, array $rawNiches): bool
    {
        $haystack = strtolower(implode(' ', array_filter([
            $siteName,
            $description,
            $exampleUrl,
            implode(' ', $niches),
            implode(' ', $rawNiches),
        ])));
        $haystack = preg_replace('/[^a-z0-9]+/i', ' ', $haystack) ?? $haystack;
        $haystack = ' '.$haystack.' ';

        foreach (self::KEYWORDS as $keyword) {
            if (str_contains($haystack, ' '.$keyword.' ')) {
                return true;
            }
        }

        foreach (array_merge($niches, $rawNiches) as $niche) {
            $n = strtolower(trim((string) $niche));
            if ($n === 'casino' || str_contains($n, 'casino')) {
                return true;
            }
        }

        return false;
    }
}
