@php
    $xml = static function (?string $value): string {
        return htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    };

    $lines = [
        '<?xml version="1.0" encoding="UTF-8"?>',
        '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
    ];

    foreach ($sitemaps as $sitemap) {
        $lines[] = '  <sitemap>';
        $lines[] = '    <loc>'.$xml($sitemap['loc'] ?? '').'</loc>';
        if (! empty($sitemap['lastmod'])) {
            $lines[] = '    <lastmod>'.$xml($sitemap['lastmod']).'</lastmod>';
        }
        $lines[] = '  </sitemap>';
    }

    $lines[] = '</sitemapindex>';

    echo implode("\n", $lines), "\n";
@endphp
