<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\GetSiteDetailsTool;
use App\Mcp\Tools\GetWalletBalanceTool;
use App\Mcp\Tools\ListCountriesNichesTool;
use App\Mcp\Tools\ListMyOrdersTool;
use App\Mcp\Tools\SaveToCartTool;
use App\Mcp\Tools\SaveToFavoritesTool;
use App\Mcp\Tools\SearchMarketplaceTool;
use Laravel\Mcp\Enums\ProtocolVersion;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;

#[Name('SEOLinkBuildings Marketplace')]
#[Version('1.0.0')]
#[Instructions(<<<'MARKDOWN'
SEOLinkBuildings is a Europe-focused guest-post / backlink marketplace. Prices are EUR.

Use search_marketplace to find live publisher listings (country, language, niche, DR, DA, Ahrefs traffic, max price, dofollow, sponsored label).
Use list_countries_niches when the buyer is unsure of filter codes.
Use get_site_details for one listing, then save_to_favorites or save_to_cart.
list_my_orders and get_wallet_balance are the signed-in advertiser's own data only.

Never invent domains. Return a compact table of host, DR, traffic, price_eur, and why it fits.
Do not place orders — checkout stays on the website.
MARKDOWN)]
class CustomerMarketplaceServer extends Server
{
    /**
     * @var array<int, string>
     */
    protected array $supportedProtocolVersion = [
        ProtocolVersion::V2025_11_25->value,
        ProtocolVersion::V2025_06_18->value,
        ProtocolVersion::V2025_03_26->value,
        ProtocolVersion::V2024_11_05->value,
        ProtocolVersion::V2026_07_28->value,
    ];

    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        SearchMarketplaceTool::class,
        GetSiteDetailsTool::class,
        ListCountriesNichesTool::class,
        SaveToFavoritesTool::class,
        SaveToCartTool::class,
        ListMyOrdersTool::class,
        GetWalletBalanceTool::class,
    ];
}
