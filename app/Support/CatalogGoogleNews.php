<?php

namespace App\Support;

use App\Models\Site;

/**
 * Google News catalog filter — only when a boolean column exists on sites.
 */
class CatalogGoogleNews
{
    /** @var list<string> */
    public const COLUMN_CANDIDATES = [
        'google_news',
        'in_google_news',
        'indexed_google_news',
        'google_news_indexed',
    ];

    public static function column(): ?string
    {
        foreach (self::COLUMN_CANDIDATES as $column) {
            if (Site::hasSitesColumn($column)) {
                return $column;
            }
        }

        return null;
    }

    public static function isAvailable(): bool
    {
        return self::column() !== null;
    }
}
