@php
    $xml = static function (?string $value): string {
        return htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    };

    $lines = [
        '<?xml version="1.0" encoding="UTF-8"?>',
        '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">',
    ];

    foreach ($urls as $url) {
        $lines[] = '  <url>';
        $lines[] = '    <loc>'.$xml($url['loc'] ?? '').'</loc>';
        if (! empty($url['lastmod'])) {
            $lines[] = '    <lastmod>'.$xml($url['lastmod']).'</lastmod>';
        }
        $lines[] = '    <changefreq>'.$xml($url['changefreq'] ?? 'monthly').'</changefreq>';
        $lines[] = '    <priority>'.$xml($url['priority'] ?? '0.5').'</priority>';
        foreach ($url['alternates'] ?? [] as $alt) {
            $lines[] = '    <xhtml:link rel="alternate" hreflang="'.$xml($alt['hreflang'] ?? '').'" href="'.$xml($alt['href'] ?? '').'"/>';
        }
        $lines[] = '  </url>';
    }

    $lines[] = '</urlset>';

    echo implode("\n", $lines), "\n";
@endphp
