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

#[Name('save_to_cart')]
#[Title('Save to cart')]
#[Description('Add a live listing to the signed-in advertiser\'s cart. Checkout still happens on the SEOLinkBuildings website. Use site_id or domain from search_marketplace.')]
class SaveToCartTool extends Tool
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
            'quantity' => $schema->integer()->description('Placements to add (1–10, default 1).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return Response::error('Sign in as an advertiser to save to the cart.');
        }

        $site = $this->listing->findVisibleSite($request->get('site_id'), $request->get('domain'));
        if ($site === null) {
            return Response::error('No live listing matched that id or domain.');
        }

        $quantity = (int) ($request->get('quantity') ?? 1);
        if (! $this->saved->addCartItem($user, $site, $quantity)) {
            return Response::error('That listing cannot be added to the cart (hidden, inactive, or your own site).');
        }

        return Response::json([
            'saved' => true,
            'list' => 'cart',
            'quantity' => max(1, min(10, $quantity)),
            'site' => $this->listing->present($user, $site),
            'next_step' => 'Open Advertiser → Catalog on SEOLinkBuildings to attach an article and check out.',
        ]);
    }
}
