<?php

namespace Tests\Feature;

use App\Models\AdvertiserApiToken;
use App\Models\AdvertiserSavedCartItem;
use App\Models\Category;
use App\Models\Country;
use App\Models\Language;
use App\Models\McpOAuthAccessToken;
use App\Models\McpOAuthClient;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\UserFavorite;
use App\Models\Wallet;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class McpCustomerServerTest extends TestCase
{
    use RefreshDatabase;

    private User $advertiser;

    private User $publisher;

    private Site $site;

    private string $token;

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

        Country::query()->firstOrCreate(
            ['code' => 'no'],
            ['name' => 'Norway', 'region' => 'Europe']
        );
        Language::query()->firstOrCreate(
            ['code' => 'no'],
            ['name' => 'Norwegian', 'native_name' => 'Norsk']
        );
        Category::query()->firstOrCreate(
            ['name' => 'Marketing, PR & Advertising'],
            ['group' => 'Business']
        );

        $this->site = Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Oslo Marketing',
            'site_url' => 'https://oslo-marketing.no',
            'domain' => 'oslo-marketing.no',
            'example_url' => 'https://oslo-marketing.no/sample',
            'da' => 35,
            'dr' => 48,
            'traffic' => 12000,
            'country' => 'no',
            'language' => 'no',
            'price' => 90,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'sponsored' => true,
            'category' => 'Marketing, PR & Advertising',
            'categories' => ['Marketing, PR & Advertising'],
            'description' => 'Marketing fixture.',
            'verified' => true,
            'active' => 1,
        ]);

        $issued = AdvertiserApiToken::issue($this->advertiser, 'Claude');
        $this->token = $issued['plain'];

        Wallet::create([
            'user_id' => $this->advertiser->id,
            'role_id' => $advertiserRole->id,
            'balance' => 125.50,
            'reserved_balance' => 10,
            'bonus_balance' => 20,
            'currency' => 'EUR',
        ]);
    }

    public function test_initialize_requires_a_token(): void
    {
        $response = $this->rpc(1, 'initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'phpunit', 'version' => '1'],
        ], token: null);

        $response->assertUnauthorized()->assertHeader('WWW-Authenticate');
        $this->assertStringContainsString(
            'oauth-protected-resource',
            (string) $response->headers->get('WWW-Authenticate')
        );
    }

    public function test_initialize_and_initialized_notification(): void
    {
        $this->rpc(1, 'initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'phpunit', 'version' => '1'],
        ])
            ->assertOk()
            ->assertHeader('Mcp-Session-Id')
            ->assertJsonPath('jsonrpc', '2.0')
            ->assertJsonPath('result.protocolVersion', '2025-06-18')
            ->assertJsonPath('result.serverInfo.name', 'SEOLinkBuildings Marketplace');

        $this->postJson('/api/mcp/customer', [
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
        ], $this->headers($this->token))
            ->assertStatus(202);
    }

    public function test_ping_and_tools_list(): void
    {
        $this->rpc(2, 'ping')->assertOk()->assertJsonPath('jsonrpc', '2.0');

        $names = collect($this->rpc(3, 'tools/list')->assertOk()->json('result.tools'))
            ->pluck('name')
            ->all();

        $this->assertEqualsCanonicalizing([
            'search_marketplace',
            'get_site_details',
            'list_countries_niches',
            'save_to_favorites',
            'save_to_cart',
            'list_my_orders',
            'get_wallet_balance',
        ], $names);
    }

    public function test_search_marketplace_and_save_tools(): void
    {
        $search = $this->rpc(10, 'tools/call', [
            'name' => 'search_marketplace',
            'arguments' => [
                'country' => 'no',
                'dr_min' => 40,
                'price_max' => 750,
                'traffic_min' => 1000,
                'topic' => 'Marketing, PR & Advertising',
                'dofollow' => true,
                'limit' => 10,
            ],
        ])->assertOk();

        $text = (string) data_get($search->json(), 'result.content.0.text');
        $payload = json_decode($text, true);
        $this->assertIsArray($payload);
        $this->assertSame('oslo-marketing.no', $payload['sites'][0]['host'] ?? null);
        $this->assertSame('EUR', $payload['sites'][0]['price']['currency'] ?? null);
        $this->assertArrayNotHasKey('publisher_id', $payload['sites'][0]);
        $this->assertArrayNotHasKey('email', $payload['sites'][0]);

        $this->rpc(11, 'tools/call', [
            'name' => 'save_to_favorites',
            'arguments' => ['site_id' => $this->site->id],
        ])->assertOk();

        $this->assertDatabaseHas('user_favorites', [
            'user_id' => $this->advertiser->id,
            'site_id' => $this->site->id,
        ]);

        $this->rpc(12, 'tools/call', [
            'name' => 'save_to_cart',
            'arguments' => ['domain' => 'oslo-marketing.no'],
        ])->assertOk();

        $this->assertDatabaseHas('advertiser_saved_cart_items', [
            'user_id' => $this->advertiser->id,
            'site_id' => $this->site->id,
        ]);
        $this->assertInstanceOf(AdvertiserSavedCartItem::class, AdvertiserSavedCartItem::query()->first());
        $this->assertInstanceOf(UserFavorite::class, UserFavorite::query()->first());
    }

    public function test_wallet_and_orders_are_the_advertisers_own(): void
    {
        $order = Order::create([
            'user_id' => $this->advertiser->id,
            'order_number' => '100001',
            'reference_code' => 'REF-MCP-1',
            'subtotal' => 90,
            'tax' => 0,
            'total_amount' => 90,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'completed',
            'paid_at' => now(),
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $this->site->id,
            'site_name' => 'Oslo Marketing',
            'site_url' => 'https://oslo-marketing.no',
            'content_link' => 'https://example.com/article.docx',
            'price' => 90,
            'publisher_status' => 'completed',
        ]);

        $wallet = $this->rpc(20, 'tools/call', [
            'name' => 'get_wallet_balance',
            'arguments' => (object) [],
        ])->assertOk();
        $walletPayload = json_decode((string) data_get($wallet->json(), 'result.content.0.text'), true);
        $this->assertSame('EUR', $walletPayload['currency'] ?? null);
        $this->assertEquals(125.5, $walletPayload['spendable'] ?? null);

        $orders = $this->rpc(21, 'tools/call', [
            'name' => 'list_my_orders',
            'arguments' => ['limit' => 5],
        ])->assertOk();
        $orderPayload = json_decode((string) data_get($orders->json(), 'result.content.0.text'), true);
        $this->assertSame('100001', $orderPayload['orders'][0]['order_number'] ?? null);
        $this->assertSame('EUR', $orderPayload['orders'][0]['currency'] ?? null);
        $this->assertArrayNotHasKey('publisher_email', $orderPayload['orders'][0]);
    }

    public function test_publisher_token_is_rejected(): void
    {
        $issued = AdvertiserApiToken::issue($this->publisher, 'Wrong role');

        $this->postJson('/api/mcp/customer', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => (object) [],
                'clientInfo' => ['name' => 'phpunit', 'version' => '1'],
            ],
        ], [
            'Authorization' => 'Bearer '.$issued['plain'],
            'Accept' => 'application/json, text/event-stream',
        ])->assertUnauthorized();
    }

    public function test_oauth_discovery_registration_and_pkce_connect(): void
    {
        $this->getJson('/.well-known/oauth-protected-resource')
            ->assertOk()
            ->assertJsonPath('scopes_supported.0', 'mcp:use')
            ->assertJsonFragment(['resource' => url('/api/mcp/customer')]);

        $this->getJson('/.well-known/oauth-authorization-server')
            ->assertOk()
            ->assertJsonPath('code_challenge_methods_supported.0', 'S256')
            ->assertJsonPath('registration_endpoint', url('/oauth/register'));

        $register = $this->postJson('/oauth/register', [
            'client_name' => 'Claude',
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        ])->assertCreated();

        $clientId = $register->json('client_id');
        $this->assertNotEmpty($clientId);

        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $this->actingAs($this->advertiser)
            ->get('/oauth/authorize?'.http_build_query([
                'response_type' => 'code',
                'client_id' => $clientId,
                'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
                'code_challenge' => $challenge,
                'code_challenge_method' => 'S256',
                'state' => 'abc',
                'scope' => 'mcp:use',
            ]))
            ->assertOk()
            ->assertSee('Connect Claude');

        $approve = $this->actingAs($this->advertiser)
            ->post('/oauth/authorize', [
                'client_id' => $clientId,
                'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
                'state' => 'abc',
                'code_challenge' => $challenge,
                'code_challenge_method' => 'S256',
                'scope' => 'mcp:use',
                'decision' => 'approve',
            ]);

        $approve->assertRedirect();
        $location = $approve->headers->get('Location');
        $this->assertStringContainsString('https://claude.ai/api/mcp/auth_callback', (string) $location);
        parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);
        $this->assertArrayHasKey('code', $query);

        $token = $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
            'code' => $query['code'],
            'code_verifier' => $verifier,
        ])->assertOk();

        $access = $token->json('access_token');
        $this->assertStringStartsWith('slb_oa_', (string) $access);
        $this->assertNotEmpty($token->json('refresh_token'));

        $this->postJson('/api/mcp/customer', [
            'jsonrpc' => '2.0',
            'id' => 99,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => (object) [],
                'clientInfo' => ['name' => 'claude', 'version' => '1'],
            ],
        ], [
            'Authorization' => 'Bearer '.$access,
            'Accept' => 'application/json, text/event-stream',
        ])
            ->assertOk()
            ->assertJsonPath('result.serverInfo.name', 'SEOLinkBuildings Marketplace');

        $this->assertDatabaseHas('mcp_oauth_access_tokens', [
            'user_id' => $this->advertiser->id,
            'client_id' => $clientId,
        ]);
        $this->assertInstanceOf(McpOAuthClient::class, McpOAuthClient::query()->find($clientId));
        $this->assertInstanceOf(McpOAuthAccessToken::class, McpOAuthAccessToken::query()->first());
    }

    public function test_api_settings_page_shows_mcp_url(): void
    {
        $this->actingAs($this->advertiser)
            ->get(route('advertiser.api.index'))
            ->assertOk()
            ->assertSee('API & MCP')
            ->assertSee('/api/mcp/customer')
            ->assertSee('claude mcp add')
            ->assertSee('.cursor/mcp.json', false);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function rpc(int|string $id, string $method, array $params = [], ?string $token = 'default'): TestResponse
    {
        return $this->postJson('/api/mcp/customer', [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => $params,
        ], $this->headers($token === 'default' ? $this->token : $token));
    }

    /**
     * @return array<string, string>
     */
    private function headers(?string $token = null): array
    {
        $headers = [
            'Accept' => 'application/json, text/event-stream',
        ];
        if ($token) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        return $headers;
    }
}
