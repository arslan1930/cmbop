<?php

namespace App\Mcp\Tools;

use App\Models\User;
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

#[Name('search_marketplace')]
#[Title('Search marketplace')]
#[Description('Search live SEOLinkBuildings publisher listings with filters (country, language, niche/topic, DR, DA, traffic, max EUR price, dofollow, sponsored label). Returns buyer-safe rows in EUR.')]
#[IsReadOnly]
class SearchMarketplaceTool extends Tool
{
    public function __construct(private readonly MarketplaceListing $listing) {}

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'country' => $schema->string()->description('ISO country code(s), comma-separated (e.g. de,nl,no).'),
            'language' => $schema->string()->description('Language code(s), comma-separated (e.g. en,de).'),
            'topic' => $schema->string()->description('Niche / topic name as used in the catalog (e.g. Marketing, PR & Advertising).'),
            'niche' => $schema->string()->description('Alias of topic.'),
            'dr_min' => $schema->integer()->description('Minimum Ahrefs Domain Rating.'),
            'dr_max' => $schema->integer()->description('Maximum Ahrefs Domain Rating.'),
            'da_min' => $schema->integer()->description('Minimum Moz Domain Authority.'),
            'da_max' => $schema->integer()->description('Maximum Moz Domain Authority.'),
            'traffic_min' => $schema->integer()->description('Minimum Ahrefs organic traffic.'),
            'traffic_max' => $schema->integer()->description('Maximum Ahrefs organic traffic.'),
            'price_max' => $schema->number()->description('Maximum advertiser price in EUR.'),
            'max_price' => $schema->number()->description('Alias of price_max (EUR).'),
            'price_min' => $schema->number()->description('Minimum advertiser price in EUR.'),
            'dofollow' => $schema->boolean()->description('When true, only dofollow listings.'),
            'sponsored' => $schema->boolean()->description('When true, only listings tagged Sponsored.'),
            'sort' => $schema->string()->description('dr_desc (default), dr_asc, da_desc, traffic_desc, price_asc, price_desc, newest.'),
            'limit' => $schema->integer()->description('How many rows to return (1–50, default 10).'),
            'page' => $schema->integer()->description('Page number (default 1).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return Response::error('Sign in as an advertiser to search the marketplace.');
        }

        $result = $this->listing->search($user, $request->all());

        return Response::json($result);
    }
}
