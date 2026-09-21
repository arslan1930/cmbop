<?php

namespace App\Support;

/**
 * Public + portal visitor chat (not advertiser↔publisher order chat).
 *
 * Leftover Hostinger layouts often omit @include('partials.tawk'), so
 * EnsureVisitorChat appends this markup before </body> when it is missing.
 */
class VisitorChatEmbed
{
    public static function firstPartyOn(): bool
    {
        return class_exists(VisitorSupportChat::class)
            && VisitorSupportChat::enabled()
            && view()->exists('partials.visitor-support-chat');
    }

    public static function tawkOn(): bool
    {
        return class_exists(TawkChat::class) && TawkChat::enabled();
    }

    public static function anyOn(): bool
    {
        return self::firstPartyOn() || self::tawkOn();
    }

    public static function markupAlreadyPresent(string $html): bool
    {
        return str_contains($html, 'id="slbLiveChat"')
            || str_contains($html, 'embed.tawk.to')
            || str_contains($html, 'id="slb-visitor-chat-overflow"');
    }

    public static function snippet(): string
    {
        if (! view()->exists('partials.tawk')) {
            return '';
        }

        try {
            $html = (string) view('partials.tawk')->render();
        } catch (\Throwable) {
            return '';
        }

        return self::markupAlreadyPresent($html) ? $html : '';
    }

    public static function inject(string $html): string
    {
        if ($html === '' || self::markupAlreadyPresent($html) || ! str_contains($html, '</body>')) {
            return $html;
        }

        $snippet = self::snippet();
        if ($snippet === '') {
            return $html;
        }

        $pos = strripos($html, '</body>');
        if ($pos === false) {
            return $html;
        }

        return substr($html, 0, $pos).$snippet."\n".substr($html, $pos);
    }
}
