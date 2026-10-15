<?php

namespace Tests\Unit;

use App\Models\Country;
use App\Models\Language;
use App\Support\MarketplacePicker;
use Database\Seeders\CountriesTableSeeder;
use Database\Seeders\LanguagesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class MarketplacePickerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CountriesTableSeeder::class);
        $this->seed(LanguagesTableSeeder::class);
    }

    public function test_normalize_accepts_models_arrays_strings_and_maps(): void
    {
        $model = Country::marketplace()->where('code', 'de')->firstOrFail();

        $fromModels = MarketplacePicker::normalize(collect([$model]), 'country');
        $this->assertSame('de', $fromModels->first()->code);
        $this->assertNotSame('', $fromModels->first()->name);

        $fromArrays = MarketplacePicker::normalize([
            ['code' => 'ES', 'name' => 'Spain'],
            ['code' => 'fr'],
        ], 'country');
        $this->assertSame(['es', 'fr'], $fromArrays->pluck('code')->all());
        $this->assertSame('Spain', $fromArrays[0]->name);
        $this->assertNotSame('', $fromArrays[1]->name);

        $fromCodes = MarketplacePicker::normalize(['de', 'United Kingdom', 'US'], 'country');
        $this->assertSame(['de', 'uk', 'us'], $fromCodes->pluck('code')->all());
        $this->assertSame('Germany', $fromCodes[0]->name);

        $fromMap = MarketplacePicker::normalize(marketplace_countries(), 'country');
        $this->assertTrue($fromMap->contains(fn ($row) => $row->code === 'de' && $row->name === 'Germany'));
        $this->assertTrue($fromMap->contains(fn ($row) => $row->code === 'uk'));

        $fromSingle = MarketplacePicker::normalize(['code' => 'it', 'name' => 'Italy'], 'country');
        $this->assertCount(1, $fromSingle);
        $this->assertSame('it', $fromSingle->first()->code);

        $fromJson = MarketplacePicker::normalize('["nl","be"]', 'country');
        $this->assertSame(['nl', 'be'], $fromJson->pluck('code')->all());
    }

    public function test_normalize_languages_from_codes_and_models(): void
    {
        $model = Language::marketplace()->where('code', 'en')->firstOrFail();
        $fromModel = MarketplacePicker::normalize(collect([$model]), 'language');
        $this->assertSame('en', $fromModel->first()->code);
        $this->assertSame('English', $fromModel->first()->name);

        $fromCodes = MarketplacePicker::normalize(['de', 'en'], 'language');
        $this->assertSame(['de', 'en'], $fromCodes->pluck('code')->all());
        $this->assertSame('German', $fromCodes[0]->name);
        $this->assertSame('English', $fromCodes[1]->name);
    }

    public function test_option_helpers_tolerate_strings_and_arrays(): void
    {
        $this->assertSame('de', marketplace_option_code('de'));
        $this->assertSame('de', marketplace_option_code('Germany'));
        $this->assertSame('es', marketplace_option_code(['code' => 'ES', 'name' => 'Spain']));
        $this->assertSame('Germany', marketplace_option_name('de'));
        $this->assertSame('German', marketplace_option_name('de', 'language'));
        $this->assertSame('Spain', marketplace_option_name((object) ['code' => 'es', 'name' => 'Spain']));
    }

    public function test_cached_countries_rewrites_legacy_string_payload_to_v2_arrays(): void
    {
        Cache::flush();
        Cache::put(MarketplacePicker::LEGACY_COUNTRIES_CACHE_KEY, ['de', 'fr', 'uk'], 300);

        $rows = MarketplacePicker::cachedCountries();
        $this->assertSame(['de', 'fr', 'uk'], $rows->pluck('code')->all());
        $this->assertSame('Germany', $rows->firstWhere('code', 'de')->name);

        $payload = Cache::get(MarketplacePicker::COUNTRIES_CACHE_KEY);
        $this->assertIsArray($payload);
        $this->assertSame('de', $payload[0]['code'] ?? null);
        $this->assertArrayHasKey('name', $payload[0]);
    }

    public function test_cached_path_accepts_serialized_eloquent_collection(): void
    {
        $models = Country::marketplace()->orderBy('name')->get(['code', 'name']);
        $this->assertNotEmpty($models);

        Cache::flush();
        Cache::put(MarketplacePicker::LEGACY_COUNTRIES_CACHE_KEY, unserialize(serialize($models)), 300);

        $rows = MarketplacePicker::cachedCountries();
        $this->assertGreaterThan(3, $rows->count());
        $this->assertTrue($rows->every(fn ($row) => is_object($row) && $row->code !== '' && $row->name !== ''));
        $this->assertTrue($rows->contains(fn ($row) => $row->code === 'de'));
    }

    public function test_cached_path_accepts_json_decoded_model_arrays(): void
    {
        $arrays = Country::marketplace()
            ->orderBy('name')
            ->get(['code', 'name'])
            ->map(fn (Country $country) => $country->toArray())
            ->all();

        Cache::flush();
        Cache::put(MarketplacePicker::COUNTRIES_CACHE_KEY, $arrays, 300);

        $rows = MarketplacePicker::cachedCountries();
        $this->assertSame(count($arrays), $rows->count());
        $this->assertTrue($rows->contains(fn ($row) => $row->code === 'de' && $row->name !== ''));
    }
}
