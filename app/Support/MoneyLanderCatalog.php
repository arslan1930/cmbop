<?php

namespace App\Support;

/**
 * Registry of money-lander classes (existing locales + Nordic/CEE).
 */
class MoneyLanderCatalog
{
    /**
     * Locale → class, in hreflang / x-default priority order.
     *
     * @return array<string, class-string>
     */
    public static function classes(): array
    {
        $map = [
            'it' => ItalianMoneyLanders::class,
            'de' => GermanMoneyLanders::class,
            'at' => AustrianMoneyLanders::class,
            'ch' => SwissMoneyLanders::class,
            'es' => SpanishMoneyLanders::class,
            'pt' => PortugueseMoneyLanders::class,
            'ro' => RomanianMoneyLanders::class,
            'dk' => DanishMoneyLanders::class,
            'se' => SwedishMoneyLanders::class,
            'no' => NorwegianMoneyLanders::class,
            'bg' => BulgarianMoneyLanders::class,
            'ee' => EstonianMoneyLanders::class,
            'hu' => HungarianMoneyLanders::class,
            'pl' => PolishMoneyLanders::class,
            'be' => BelgianMoneyLanders::class,
            'nl' => DutchMoneyLanders::class,
            'fr' => FrenchMoneyLanders::class,
        ];

        $out = [];
        foreach ($map as $locale => $class) {
            if (class_exists($class) && method_exists($class, 'isSlug')) {
                $out[$locale] = $class;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public static function nordicCeeLocales(): array
    {
        return ['dk', 'se', 'no', 'bg', 'hu', 'ee', 'pl'];
    }

    /**
     * Locales whose marketplace / pricing / home chrome uses MoneyLanderCatalog::chrome().
     *
     * @return list<string>
     */
    public static function chromeLocales(): array
    {
        return array_values(array_filter(
            array_merge(self::nordicCeeLocales(), ['be']),
            static fn (string $locale) => self::classFor($locale) !== null
        ));
    }

    /**
     * @return class-string|null
     */
    public static function classFor(string $locale): ?string
    {
        $locale = strtolower(trim($locale));
        $classes = self::classes();

        return $classes[$locale] ?? null;
    }

    public static function localeOwnsSegment(string $locale, string $segment): bool
    {
        $class = self::classFor($locale);
        if ($class === null) {
            return false;
        }

        if (method_exists($class, 'capturesLocaleCopy') && $class::capturesLocaleCopy($locale, $segment)) {
            return true;
        }

        return method_exists($class, 'isPublicSegment') && $class::isPublicSegment($segment);
    }

    public static function anotherOwnsSlug(string $locale, string $slug): bool
    {
        foreach (self::classes() as $otherLocale => $class) {
            if ($otherLocale === $locale) {
                continue;
            }
            if (method_exists($class, 'isSlug') && $class::isSlug($slug)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cluster / marketplace / pricing chrome for Nordic+CEE locales.
     *
     * @return array<string, mixed>|null
     */
    public static function chrome(string $locale): ?array
    {
        $locale = strtolower(trim($locale));
        if (! in_array($locale, self::chromeLocales(), true)) {
            return null;
        }

        $class = self::classFor($locale);
        if ($class === null || ! method_exists($class, 'clusterLinks')) {
            return null;
        }

        $ui = method_exists($class, 'ui') ? $class::ui() : [];
        $marketSlug = method_exists($class, 'marketingUrl')
            ? trim((string) parse_url($class::marketingUrl('marketplace'), PHP_URL_PATH), '/')
            : $locale.'/marketplace';
        $marketKey = str_contains($marketSlug, '/') ? (explode('/', $marketSlug)[1] ?? 'marketplace') : 'marketplace';
        $priceSlug = method_exists($class, 'marketingUrl')
            ? trim((string) parse_url($class::marketingUrl('pricing'), PHP_URL_PATH), '/')
            : $locale.'/pricing';
        $priceKey = str_contains($priceSlug, '/') ? (explode('/', $priceSlug)[1] ?? 'pricing') : 'pricing';

        return [
            'locale' => $locale,
            'class' => $class,
            'ui' => $ui,
            'cluster_title' => $ui['cluster_title'] ?? '',
            'home_links' => $class::clusterLinks('home'),
            'marketplace_links' => $class::clusterLinks($marketKey),
            'pricing_links' => $class::clusterLinks($priceKey),
            'footer_links' => method_exists($class, 'footerLinks')
                ? $class::footerLinks()
                : array_values(array_filter(
                    $class::clusterLinks('home'),
                    static fn (array $item) => ! in_array($item['slug'], ['home', $marketKey, $priceKey], true)
                )),
            'marketplace' => method_exists($class, 'marketplaceCopy') ? $class::marketplaceCopy() : null,
            'pricing' => method_exists($class, 'pricingCopy') ? $class::pricingCopy() : null,
        ];
    }
}
