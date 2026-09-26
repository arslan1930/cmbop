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
            'fr' => FrenchMoneyLanders::class,
            'nl' => DutchMoneyLanders::class,
            'dk' => DanishMoneyLanders::class,
            'se' => SwedishMoneyLanders::class,
            'no' => NorwegianMoneyLanders::class,
            'bg' => BulgarianMoneyLanders::class,
            'ee' => EstonianMoneyLanders::class,
            'hu' => HungarianMoneyLanders::class,
            'pl' => PolishMoneyLanders::class,
        ];

        $out = [];
        foreach ($map as $locale => $class) {
            if (class_exists($class) && method_exists($class, 'isSlug')) {
                $out[$locale] = $class;
            }
        }

        // A new App\Support\*MoneyLanders class with a LOCALE constant joins
        // the registry without another edit to this list.
        foreach (self::discoveredClasses() as $locale => $class) {
            if (! isset($out[$locale])) {
                $out[$locale] = $class;
            }
        }

        return $out;
    }

    /**
     * @return array<string, class-string>
     */
    private static function discoveredClasses(): array
    {
        $found = [];
        $files = glob(app_path('Support/*MoneyLanders.php')) ?: [];
        sort($files);

        foreach ($files as $file) {
            $base = basename((string) $file, '.php');
            if ($base === '' || preg_match('/^[A-Za-z0-9_]+$/', $base) !== 1) {
                continue;
            }

            $class = 'App\\Support\\'.$base;
            if (! class_exists($class) || ! method_exists($class, 'isSlug') || ! method_exists($class, 'slugs')) {
                continue;
            }
            if (! defined($class.'::LOCALE')) {
                continue;
            }

            $locale = strtolower(trim((string) constant($class.'::LOCALE')));
            if ($locale === '' || isset($found[$locale])) {
                continue;
            }

            $found[$locale] = $class;
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    public static function nordicCeeLocales(): array
    {
        return ['dk', 'se', 'no', 'bg', 'hu', 'ee', 'pl'];
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
        if (! in_array($locale, self::nordicCeeLocales(), true)) {
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
