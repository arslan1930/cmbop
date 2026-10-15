<?php

namespace Tests\Unit;

use App\Services\Catalog\CatalogUrlQuery;
use Illuminate\Http\Request;
use Tests\TestCase;

class CatalogUrlQueryTest extends TestCase
{
    public function test_from_array_keeps_allowlisted_non_empty_values_only(): void
    {
        $params = CatalogUrlQuery::fromArray([
            'search' => '  alpha  ',
            'da_min' => '40',
            'sponsored' => '',
            'evil' => 'drop-me',
            'category' => ['ignored-array'],
            'sort' => 'price_asc',
            'page' => '2',
        ]);

        $this->assertSame([
            'search' => 'alpha',
            'da_min' => '40',
            'sort' => 'price_asc',
            'page' => '2',
        ], $params);
    }

    public function test_canonicalize_drops_default_sort_and_page_one(): void
    {
        $params = CatalogUrlQuery::canonicalize([
            'search' => 'beta',
            'sort' => CatalogUrlQuery::DEFAULT_SORT,
            'page' => '1',
            'dr_min' => '50',
        ]);

        $this->assertSame([
            'search' => 'beta',
            'dr_min' => '50',
        ], $params);
    }

    public function test_per_page_is_allowlisted_clamped_and_default_omitted(): void
    {
        $this->assertContains('per_page', CatalogUrlQuery::KEYS);
        $this->assertContains('link_type', CatalogUrlQuery::KEYS);
        $this->assertContains('turnaround_max', CatalogUrlQuery::KEYS);
        $this->assertContains('sensitive_topics', CatalogUrlQuery::KEYS);
        $this->assertContains('google_news', CatalogUrlQuery::KEYS);
        $this->assertSame(20, CatalogUrlQuery::perPage(['per_page' => '20']));
        $this->assertSame(10, CatalogUrlQuery::perPage(['per_page' => '10']));
        $this->assertSame(50, CatalogUrlQuery::perPage(['per_page' => '50']));
        $this->assertSame(20, CatalogUrlQuery::perPage(['per_page' => '99']));
        $this->assertSame(20, CatalogUrlQuery::perPage([]));

        $this->assertSame(
            ['per_page' => '50'],
            CatalogUrlQuery::canonicalize(['per_page' => '50', 'page' => '1'])
        );
        $this->assertSame(
            [],
            CatalogUrlQuery::canonicalize(['per_page' => '20'])
        );
        $this->assertSame(
            [],
            CatalogUrlQuery::canonicalize(['per_page' => '99'])
        );
    }

    public function test_except_removes_chip_keys_and_page(): void
    {
        $params = CatalogUrlQuery::except([
            'search' => 'gamma',
            'da_min' => '30',
            'da_max' => '60',
            'country' => 'de',
            'page' => '3',
            'sort' => 'dr_desc',
        ], ['da_min', 'da_max']);

        $this->assertSame([
            'search' => 'gamma',
            'country' => 'de',
        ], $params);
    }

    public function test_canonicalize_rewrites_sponsored_alias_to_tag(): void
    {
        $this->assertContains('tag', CatalogUrlQuery::KEYS);
        $this->assertSame(
            ['tag' => 'sponsored'],
            CatalogUrlQuery::canonicalize(['sponsored' => '1'])
        );
        $this->assertSame(
            ['tag' => 'partner_material'],
            CatalogUrlQuery::canonicalize([
                'tag' => 'partner_material',
                'sponsored' => '1',
            ])
        );
        $this->assertSame(
            ['tag' => 'none'],
            CatalogUrlQuery::canonicalize(['tag' => 'none'])
        );
        $this->assertSame(
            [],
            CatalogUrlQuery::canonicalize(['tag' => 'guest', 'sponsored' => ''])
        );
    }

    public function test_from_request_matches_canonicalize(): void
    {
        $request = Request::create('/advertiser/catalog', 'GET', [
            'search' => 'delta',
            'sort' => 'dr_desc',
            'noise' => 'nope',
        ]);

        $this->assertSame(
            ['search' => 'delta'],
            CatalogUrlQuery::fromRequest($request)
        );
    }

    public function test_from_request_uses_merged_input_not_query_only(): void
    {
        $request = Request::create('/advertiser/catalog', 'GET', [
            'search' => 'da>=50',
        ]);
        $originalQuery = $request->query();
        $request->merge(['search' => '', 'da_min' => 50]);

        $this->assertSame(
            ['da_min' => '50'],
            CatalogUrlQuery::fromRequest($request)
        );
        $this->assertSame(
            ['da_min' => '50'],
            CatalogUrlQuery::canonicalRedirectParams($request, $originalQuery)
        );
    }
}
