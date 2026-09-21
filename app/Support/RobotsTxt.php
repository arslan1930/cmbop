<?php

namespace App\Support;

class RobotsTxt
{
    /** @return list<string> */
    public static function agents(): array
    {
        return [
            '*',
            // Search engines
            'Googlebot',
            'bingbot',
            'Slurp', // Yahoo
            'DuckDuckBot',
            // AI / answer engines
            'GPTBot',
            'ChatGPT-User',
            'OAI-SearchBot',
            'Google-Extended',
            'ClaudeBot',
            'Anthropic-AI',
            'PerplexityBot',
            'Bytespider',
            'CCBot',
            'Applebot-Extended',
            'meta-externalagent',
            // Social / previews (LinkedIn, Meta, etc.)
            'LinkedInBot',
            'facebookexternalhit',
            'FacebookBot',
        ];
    }

    /** @return list<string> */
    public static function allows(): array
    {
        $allows = [
            '/',
            '/marketplace',
            '/blog',
            '/become-a-publisher',
            '/pricing',
            '/how-it-works',
            '/guest-post-prices-europe',
        ];

        if (class_exists(CountryLander::class)) {
            foreach (CountryLander::slugs() as $slug) {
                $allows[] = '/'.$slug;
            }
        }

        return array_values(array_unique($allows));
    }

    /** @return list<string> */
    public static function disallows(): array
    {
        return [
            '/admin/',
            '/marketing/',
            '/advertiser/',
            '/publisher/',
            '/profile',
            '/chat/',
            '/notifications',
            '/login',
            '/register',
            '/forgot-password',
            '/reset-password',
            '/email/',
            '/auth/',
            '/cron/',
        ];
    }

    public static function render(?string $baseUrl = null): string
    {
        $base = rtrim($baseUrl ?: app_public_url(), '/');
        $blocks = [];

        foreach (self::agents() as $agent) {
            $block = "User-agent: {$agent}\n";
            foreach (self::allows() as $path) {
                $block .= "Allow: {$path}\n";
            }
            foreach (self::disallows() as $path) {
                $block .= "Disallow: {$path}\n";
            }
            $blocks[] = rtrim($block);
        }

        return implode("\n\n", $blocks)
            ."\n\n"
            ."Sitemap: {$base}/sitemap.xml\n"
            ."# AI / LLM product digest for assistants and answer engines\n"
            ."# {$base}/llms.txt\n";
    }
}
