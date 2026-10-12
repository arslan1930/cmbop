<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\Mcp\MarketplaceListing;
use App\Services\Mcp\SavedCartSync;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;

#[Name('save_to_favorites')]
#[Title('Save to favorites')]
#[Description('Add a live listing to the signed-in advertiser\'s Saved Sites (favorites). Does not replace existing favorites. Use site_id or domain from search_marketplace.')]
class SaveToFavoritesTool extends Tool
{
    public function __construct(
        private readonly MarketplaceListing $listing,
        private readonly SavedCartSync $saved,
    ) {}

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'site_id' => $schema->integer()->description('Listing id from search_marketplace.'),
            'domain' => $schema->string()->description('Hostname if you only have the domain.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return Response::error('Sign in as an advertiser to save favorites.');
        }

        $site = $this->listing->findVisibleSite($request->get('site_id'), $request->get('domain'));
        if ($site === null) {
            return Response::error('No live listing matched that id or domain.');
        }

        if (! $this->saved->addFavorite($user, $site)) {
            return Response::error('That listing cannot be saved (hidden, inactive, or your own site).');
        }

        return Response::json([
            'saved' => true,
            'list' => 'favorites',
            'site' => $this->listing->present($user, $site),
        ]);
    }
}
