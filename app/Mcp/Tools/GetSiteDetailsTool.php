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

#[Name('get_site_details')]
#[Title('Get site details')]
#[Description('Buyer-safe details for one live listing. Look up by site id or domain. Prices are EUR. Never returns publisher contact or payout data.')]
#[IsReadOnly]
class GetSiteDetailsTool extends Tool
{
    public function __construct(private readonly MarketplaceListing $listing) {}

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'site_id' => $schema->integer()->description('Listing id from search_marketplace.'),
            'domain' => $schema->string()->description('Hostname or URL when you do not have the id.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return Response::error('Sign in as an advertiser to load listing details.');
        }

        $row = $this->listing->details(
            $user,
            $request->get('site_id'),
            $request->get('domain'),
        );

        if ($row === null) {
            return Response::error('No live listing matched that id or domain.');
        }

        return Response::json($row);
    }
}
