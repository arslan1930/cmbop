<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Optional default catalog filters. Off until staff clean live inventory
 * (set CATALOG_DEFAULT_VERIFIED / CATALOG_DEFAULT_QUALITY in .env).
 * An explicit 0 in the query string always wins over the config default.
 */
class CatalogBrowseDefaults
{
    public static function flagOn(Request $request, string $key, string $configKey): bool
    {
        if ($request->exists($key)) {
            $raw = $request->input($key);

            return $raw === 1 || $raw === '1' || $raw === true;
        }

        return (bool) config($configKey, false);
    }

    public static function verifiedOn(Request $request): bool
    {
        return self::flagOn($request, 'verified', 'catalog.default_verified');
    }

    public static function qualityOn(Request $request): bool
    {
        return self::flagOn($request, 'quality', 'catalog.default_quality');
    }

    /**
     * @return array<string, bool>
     */
    public static function configMap(): array
    {
        return [
            'verified' => (bool) config('catalog.default_verified', false),
            'quality' => (bool) config('catalog.default_quality', false),
        ];
    }
}
