<?php

namespace Tests\Feature;

use App\Models\AdvertiserApiToken;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdvertiserCatalogMcpTest extends TestCase
{
    use RefreshDatabase;

    private User $advertiser;

    private User $publisher;

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is required for this suite.');
        }

        parent::setUp();
        $this->seed(RolesTableSeeder::class);

        $advertiserRole = Role::where('name', 'advertiser')->firstOrFail();
        $publisherRole = Role::where('name', 'publisher')->firstOrFail();

        $this->advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiserRole->id,
        ]);
        $this->advertiser->roles()->attach($advertiserRole->id);

        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $this->publisher->roles()->attach($publisherRole->id);

        Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Oslo Rentals',
            'site_url' => 'https://oslo-rentals.no',
            'domain' => 'oslo-rentals.no',
            'example_url' => 'https://oslo-rentals.no/sample',
            'da' => 35,
            'dr' => 40,
            'traffic' => 12000,
            'country' => 'no',
            'language' => 'no',
            'price' => 90,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'MCP listing fixture.',
            'verified' => true,
            'active' => 1,
        ]);
    }

    public function test_missing_token_is_rejected(): void
    {
        $this->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ])->assertUnauthorized();
    }

    public function test_tools_list_is_search_only(): void
    {
        $issued = AdvertiserApiToken::issue($this->advertiser, 'MCP');

        $this->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ], [
            'Authorization' => 'Bearer '.$issued['plain'],
        ])
            ->assertOk()
            ->assertJsonPath('result.tools.0.name', 'catalog_search')
            ->assertJsonCount(1, 'result.tools');
    }

    public function test_catalog_search_returns_slim_rows(): void
    {
        $issued = AdvertiserApiToken::issue($this->advertiser, 'MCP');

        $response = $this->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => [
                'name' => 'catalog_search',
                'arguments' => ['country' => 'no', 'page' => 1, 'per_page' => 10],
            ],
        ], [
            'Authorization' => 'Bearer '.$issued['plain'],
        ])->assertOk();

        $text = $response->json('result.content.0.text');
        $payload = json_decode((string) $text, true);

        $this->assertSame('ok', $payload['status'] ?? null);
        $this->assertSame('oslo-rentals.no', $payload['sites'][0]['host'] ?? null);
        $this->assertArrayNotHasKey('id', $payload['sites'][0] ?? []);
        $this->assertArrayHasKey('page', $payload['meta'] ?? []);
        $this->assertSame(1, $payload['meta']['page'] ?? null);
    }

    public function test_place_order_tool_is_rejected(): void
    {
        $issued = AdvertiserApiToken::issue($this->advertiser, 'MCP');

        $this->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => ['name' => 'place_order', 'arguments' => []],
        ], [
            'Authorization' => 'Bearer '.$issued['plain'],
        ])
            ->assertOk()
            ->assertJsonPath('error.code', -32602);
    }

    public function test_publisher_token_is_forbidden(): void
    {
        $issued = AdvertiserApiToken::issue($this->publisher, 'MCP');

        $this->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ], [
            'Authorization' => 'Bearer '.$issued['plain'],
        ])->assertForbidden();
    }
}
