<?php

namespace App\Services\Marketplace;

use App\Models\Site;
use App\Support\ListingMoney;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class WritingFeeService
{
    public const SOURCE_BUYER = 'buyer';

    public const SOURCE_PUBLISHER = 'publisher';

    public const DEFAULT_EN_EUR = 25.0;

    public const DEFAULT_OTHER_EUR = 35.0;

    public function defaultEurosForLanguage(?string $language): float
    {
        $code = strtolower(trim((string) $language));

        return $code === 'en' ? self::DEFAULT_EN_EUR : self::DEFAULT_OTHER_EUR;
    }

    public function defaultEurosForSite(Site $site): float
    {
        $codes = method_exists($site, 'languageCodes') ? $site->languageCodes() : [];
        $primary = strtolower(trim((string) ($site->language ?? ($codes[0] ?? ''))));

        return $this->defaultEurosForLanguage($primary);
    }

    public function writingOffered(Site $site): bool
    {
        if (! Schema::hasColumn('sites', 'writing_enabled')) {
            return true;
        }

        if ($site->getAttribute('writing_enabled') === null) {
            return true;
        }

        return (bool) $site->writing_enabled;
    }

    /**
     * Publisher listing-currency writing fee. Null stored amount uses the language default.
     */
    public function resolvedListingWritingFee(Site $site): float
    {
        $listing = $site->getAttribute('listing_writing_fee');
        if ($listing !== null && $listing !== '' && is_numeric($listing)) {
            return round((float) $listing, 2);
        }

        $euros = $site->getAttribute('writing_fee');
        if ($euros !== null && $euros !== '' && is_numeric($euros)) {
            return round((float) $euros, 2);
        }

        $defaultEuros = $this->defaultEurosForSite($site);
        $currency = strtoupper((string) ($site->listingCurrency() ?? 'EUR'));
        if ($currency === 'EUR') {
            return $defaultEuros;
        }

        $money = app(ListingMoney::class);
        $rate = $money->ratePerEur($currency);
        if (! $rate || $rate <= 0) {
            return $defaultEuros;
        }

        return round($defaultEuros * $rate, 2);
    }

    /**
     * Advertiser-facing EUR writing fee (FX buffer when listing currency is not EUR).
     */
    public function resolvedAdvertiserWritingFee(Site $site, ?string $buyerCurrency = null): float
    {
        if (! $this->writingOffered($site)) {
            return 0.0;
        }

        $listing = app(ListingMoney::class);
        $listingCurrency = $site->listingCurrency();
        $buyerCurrency = $listing->normalizeWallet($buyerCurrency ?? $listing->buyerCurrency());
        $withBuffer = $listing->appliesBuffer($listingCurrency, $buyerCurrency);

        return round($listing->toEuros($this->resolvedListingWritingFee($site), $listingCurrency, $withBuffer), 2);
    }

    /**
     * Persist writing fields from a publisher form request.
     *
     * @return array{writing_enabled: bool, writing_fee: ?float, listing_writing_fee: ?float}
     */
    public function persistenceFromRequest(Request $request, Site $site, ?string $country = null): array
    {
        $enabled = ! $request->boolean('writing_disabled');
        $raw = $request->input('writing_fee');
        $listing = app(ListingMoney::class);
        $currency = $site->listingCurrency();
        if (method_exists($site, 'listingCurrency') && $request->filled('price_input_currency')) {
            $currency = strtoupper((string) $request->input('price_input_currency'));
        }

        $listingFee = null;
        $euroFee = null;
        if ($enabled && $raw !== null && $raw !== '') {
            $listingFee = round((float) $raw, 2);
            $euroFee = $listing->toEuros($listingFee, $currency, false);
        }

        return [
            'writing_enabled' => $enabled,
            'writing_fee' => $euroFee,
            'listing_writing_fee' => $listingFee,
        ];
    }

    /**
     * Persist writing fields from a bulk CSV row.
     *
     * @param  array<string, mixed>  $row
     * @return array{writing_enabled: bool, writing_fee: ?float, listing_writing_fee: ?float}
     */
    public function persistenceFromImportRow(array $row, string $language, string $currency = 'EUR'): array
    {
        $enabledRaw = strtolower(trim((string) ($row['writing_enabled'] ?? '1')));
        $enabled = ! in_array($enabledRaw, ['0', 'false', 'no', 'off', 'n'], true);
        $raw = $row['writing_fee'] ?? '';
        $listing = app(ListingMoney::class);

        $listingFee = null;
        $euroFee = null;
        if ($enabled && $raw !== null && $raw !== '') {
            $listingFee = round((float) $raw, 2);
            $euroFee = $listing->toEuros($listingFee, $currency, false);
        }

        return [
            'writing_enabled' => $enabled,
            'writing_fee' => $euroFee,
            'listing_writing_fee' => $listingFee,
        ];
    }
}
