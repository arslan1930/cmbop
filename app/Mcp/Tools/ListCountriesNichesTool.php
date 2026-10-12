<?php

namespace App\Mcp\Tools;

use App\Services\Mcp\MarketplaceListing;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_countries_niches')]
#[Title('List countries, languages, and niches')]
#[Description('Catalog vocabulary: marketplace country codes, language codes, and niche names to use with search_marketplace.')]
#[IsReadOnly]
class ListCountriesNichesTool extends Tool
{
    public function __construct(private readonly MarketplaceListing $listing) {}

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        return Response::json($this->listing->vocab());
    }
}
