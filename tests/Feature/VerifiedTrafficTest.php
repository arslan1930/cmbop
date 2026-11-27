<?php

namespace Tests\Feature;

use App\Jobs\SyncSiteVerifiedTraffic;
use App\Mail\VerifiedTrafficSyncFailed;
use App\Models\InAppNotification;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteTrafficConnection;
use App\Models\User;
use App\Services\VerifiedTraffic\GoogleTrafficClient;
use App\Services\VerifiedTraffic\VerifiedTrafficService;
use App\Support\EmailCatalog;
use App\Support\VerifiedTraffic;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

class VerifiedTrafficTest extends TestCase
{
    use RefreshDatabase;

    private User $publisher;

    private User $advertiser;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        $this->withoutMiddleware(ThrottleRequests::class);

        $publisherRole = Role::where('name', 'publisher')->firstOrFail();
        $advertiserRole = Role::where('name', 'advertiser')->firstOrFail();
        $adminRole = Role::where('name', 'admin')->firstOrFail();

        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $this->publisher->roles()->attach($publisherRole->id);

        $this->advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiserRole->id,
        ]);
        $this->advertiser->roles()->attach($advertiserRole->id);

        $this->admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
        ]);
        $this->admin->roles()->attach($adminRole->id);
    }

    private function enableOauth(): void
    {
        config([
            'services.google_traffic.client_id' => '1234567890.apps.googleusercontent.com',
            'services.google_traffic.client_secret' => 'traffic-client-secret',
        ]);
    }

    private function makeSite(array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Verified Demo',
            'site_url' => 'https://verified-demo.example',
            'domain' => 'verified-demo.example',
            'example_url' => 'https://verified-demo.example/sample',
            'da' => 40,
            'dr' => 45,
            'traffic' => 8000,
            'traffic_manual' => 8000,
            'traffic_source' => VerifiedTraffic::SOURCE_MANUAL,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 120,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'A listing used for verified traffic tests.',
            'verified' => true,
            'active' => 1,
        ], $overrides));
    }

    private function googleUser(): SocialiteUser
    {
        $user = new SocialiteUser;
        $user->id = 'google-user-1';
        $user->email = 'publisher-ga@example.com';
        $user->token = 'ya29-access';
        $user->refreshToken = '1//refresh-token';
        $user->expiresIn = 3600;

        return $user;
    }

    private function fakeGoogleHttp(array $overrides = []): void
    {
        Http::fake(function ($request) use ($overrides) {
            $url = $request->url();
            foreach ($overrides as $pattern => $response) {
                if (Str::is($pattern, $url) || str_contains($url, (string) $pattern)) {
                    return $response instanceof \Closure ? $response($request) : $response;
                }
            }
            if (str_contains($url, 'analyticsadmin.googleapis.com') && str_contains($url, 'accountSummaries')) {
                return Http::response([
                    'accountSummaries' => [[
                        'propertySummaries' => [[
                            'property' => 'properties/111',
                            'displayName' => 'Verified Demo GA4',
                        ]],
                    ]],
                ], 200);
            }
            if (str_contains($url, 'analyticsadmin.googleapis.com') && str_contains($url, 'dataStreams')) {
                return Http::response([
                    'dataStreams' => [[
                        'webStreamData' => ['defaultUri' => 'https://www.verified-demo.example'],
                    ]],
                ], 200);
            }
            if (str_contains($url, 'analyticsdata.googleapis.com')) {
                $organic = str_contains($request->body(), 'Organic Search');

                return Http::response([
                    'rows' => [[
                        'metricValues' => [['value' => $organic ? '0' : '15400']],
                    ]],
                ], 200);
            }
            if (str_contains($url, 'webmasters/v3/sites') && str_contains($url, 'searchAnalytics')) {
                return Http::response(['rows' => [['clicks' => 3210]]], 200);
            }
            if (str_contains($url, 'webmasters/v3/sites')) {
                return Http::response([
                    'siteEntry' => [
                        ['siteUrl' => 'sc-domain:verified-demo.example'],
                        ['siteUrl' => 'https://other.example/'],
                    ],
                ], 200);
            }
            if (str_contains($url, 'oauth2.googleapis.com/token')) {
                return Http::response([
                    'access_token' => 'ya29-refreshed',
                    'expires_in' => 3600,
                ], 200);
            }
            if (str_contains($url, 'oauth2.googleapis.com/revoke')) {
                return Http::response('', 200);
            }

            return Http::response(['error' => 'unfaked '.$url], 500);
        });
    }

    public function test_connect_is_hidden_and_redirect_404s_when_oauth_is_not_configured(): void
    {
        config(['services.google_traffic.client_id' => '', 'services.google_traffic.client_secret' => '']);
        $site = $this->makeSite();

        $this->actingAs($this->publisher)
            ->get(route('publisher.websites'))
            ->assertOk()
            ->assertDontSee('Connect Google', false);

        $this->actingAs($this->publisher)
            ->get(route('publisher.sites.google.redirect', $site))
            ->assertNotFound();
    }

    public function test_redirect_sends_publishers_to_a_separate_google_client(): void
    {
        $this->enableOauth();
        $site = $this->makeSite();

        $response = $this->actingAs($this->publisher)
            ->get(route('publisher.sites.google.redirect', $site));

        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('accounts.google.com', $location);
        $this->assertStringContainsString('1234567890.apps.googleusercontent.com', $location);
        $this->assertStringContainsString(urlencode('https://www.googleapis.com/auth/analytics.readonly'), $location);
        $this->assertStringNotContainsString(urlencode('/auth/google/callback'), $location);
        $this->assertStringContainsString(urlencode('/publisher/sites/google/callback'), $location);
    }

    public function test_callback_encrypts_tokens_and_opens_the_picker(): void
    {
        $this->enableOauth();
        $site = $this->makeSite();
        $this->actingAs($this->publisher);
        $state = app(VerifiedTrafficService::class)->encodeOauthState($site->id, $this->publisher->id);

        $this->mock(GoogleTrafficClient::class, function ($mock) {
            $mock->shouldReceive('userFromCallback')->once()->andReturn($this->googleUser());
        });

        $this->get(route('publisher.sites.google.callback', ['state' => $state, 'code' => 'fake-code']))
            ->assertRedirect(route('publisher.sites.google.properties', $site));

        $connection = SiteTrafficConnection::query()->where('site_id', $site->id)->first();
        $this->assertNotNull($connection);
        $this->assertSame('publisher-ga@example.com', $connection->google_account_email);
        $this->assertSame(VerifiedTraffic::STATUS_PENDING, $connection->status);
        $this->assertNotSame('ya29-access', $connection->access_token_encrypted);
        $this->assertSame('ya29-access', Crypt::decryptString($connection->access_token_encrypted));
        $this->assertSame('1//refresh-token', $connection->refreshToken());
        $this->assertSame(8000, $site->fresh()->traffic_manual);
    }

    public function test_property_picker_requires_hostname_match_and_queues_first_sync(): void
    {
        $this->enableOauth();
        $this->fakeGoogleHttp();
        Queue::fake();
        $site = $this->makeSite();
        app(VerifiedTrafficService::class)->startConnection($site, $this->publisher, $this->googleUser());

        $this->actingAs($this->publisher)
            ->get(route('publisher.sites.google.properties', $site))
            ->assertOk()
            ->assertSee('Match this site to a Google property', false)
            ->assertSee('Verified Demo GA4', false)
            ->assertSee('Domain matches', false)
            ->assertSee('Different domain', false);

        $this->actingAs($this->publisher)
            ->from(route('publisher.sites.google.properties', $site))
            ->post(route('publisher.sites.google.properties.save', $site), [
                'ga4_property_id' => 'properties/999',
            ])
            ->assertRedirect(route('publisher.sites.google.properties', $site))
            ->assertSessionHas('error');

        $this->actingAs($this->publisher)
            ->post(route('publisher.sites.google.properties.save', $site), [
                'ga4_property_id' => 'properties/111',
                'gsc_site_url' => 'sc-domain:verified-demo.example',
            ])
            ->assertRedirect(route('publisher.sites.google.properties', $site))
            ->assertSessionHas('success');

        Queue::assertPushed(SyncSiteVerifiedTraffic::class, fn ($job) => $job->siteId === $site->id);
        $connection = SiteTrafficConnection::query()->where('site_id', $site->id)->first();
        $this->assertSame('properties/111', $connection->ga4_property_id);
        $this->assertSame('sc-domain:verified-demo.example', $connection->gsc_site_url);
    }

    public function test_ga4_organic_zero_falls_back_to_total_sessions_and_stays_verified(): void
    {
        $this->enableOauth();
        $this->fakeGoogleHttp();
        $site = $this->makeSite();
        $connection = app(VerifiedTrafficService::class)->startConnection($site, $this->publisher, $this->googleUser());
        $connection->ga4_property_id = 'properties/111';
        $connection->status = VerifiedTraffic::STATUS_CONNECTED;
        $connection->save();

        app(VerifiedTrafficService::class)->sync($site->fresh());

        $site->refresh();
        $this->assertSame(15400, (int) $site->traffic);
        $this->assertSame(15400, (int) $site->traffic_ga4);
        $this->assertSame(VerifiedTraffic::SOURCE_GA4, $site->traffic_source);
        $this->assertTrue(VerifiedTraffic::showsBadge($site));
        $this->assertSame(8000, (int) $site->traffic_manual);
    }

    public function test_gsc_is_used_when_ga4_is_not_connected(): void
    {
        $this->enableOauth();
        $this->fakeGoogleHttp();
        $site = $this->makeSite();
        $connection = app(VerifiedTrafficService::class)->startConnection($site, $this->publisher, $this->googleUser());
        $connection->gsc_site_url = 'sc-domain:verified-demo.example';
        $connection->status = VerifiedTraffic::STATUS_CONNECTED;
        $connection->save();

        app(VerifiedTrafficService::class)->sync($site->fresh());

        $site->refresh();
        $this->assertSame(3210, (int) $site->traffic);
        $this->assertSame(VerifiedTraffic::SOURCE_GSC, $site->traffic_source);
        $this->assertTrue(VerifiedTraffic::showsBadge($site));
    }

    public function test_token_failure_keeps_the_number_clears_the_badge_and_throttles_notify(): void
    {
        $this->enableOauth();
        Mail::fake();
        $this->fakeGoogleHttp([
            'oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 401),
            'analyticsdata.googleapis.com/*' => Http::response(['error' => 'unauthorized'], 401),
        ]);

        $site = $this->makeSite([
            'traffic' => 15400,
            'traffic_ga4' => 15400,
            'traffic_source' => VerifiedTraffic::SOURCE_GA4,
            'traffic_verified_at' => now(),
        ]);
        $connection = app(VerifiedTrafficService::class)->startConnection($site, $this->publisher, $this->googleUser());
        $connection->ga4_property_id = 'properties/111';
        $connection->token_expires_at = now()->subMinute();
        $connection->status = VerifiedTraffic::STATUS_CONNECTED;
        $connection->save();

        app(VerifiedTrafficService::class)->sync($site->fresh());
        app(VerifiedTrafficService::class)->sync($site->fresh());

        $site->refresh();
        $this->assertSame(15400, (int) $site->traffic);
        $this->assertNull($site->traffic_verified_at);
        $this->assertFalse(VerifiedTraffic::showsBadge($site));
        Mail::assertQueued(VerifiedTrafficSyncFailed::class, 1);
        $this->assertSame(1, InAppNotification::query()->where('user_id', $this->publisher->id)->count());
    }

    public function test_disconnect_revokes_and_restores_manual_traffic(): void
    {
        $this->enableOauth();
        $this->fakeGoogleHttp();
        $site = $this->makeSite([
            'traffic' => 15400,
            'traffic_ga4' => 15400,
            'traffic_source' => VerifiedTraffic::SOURCE_GA4,
            'traffic_verified_at' => now(),
            'traffic_manual' => 8000,
        ]);
        app(VerifiedTrafficService::class)->startConnection($site, $this->publisher, $this->googleUser());
        $site->trafficConnection->forceFill([
            'ga4_property_id' => 'properties/111',
            'status' => VerifiedTraffic::STATUS_CONNECTED,
        ])->save();

        $this->actingAs($this->publisher)
            ->post(route('publisher.sites.google.disconnect', $site))
            ->assertRedirect(route('publisher.websites'));

        $this->assertNull(SiteTrafficConnection::query()->where('site_id', $site->id)->first());
        $site->refresh();
        $this->assertSame(8000, (int) $site->traffic);
        $this->assertSame(VerifiedTraffic::SOURCE_MANUAL, $site->traffic_source);
        $this->assertNull($site->traffic_verified_at);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'oauth2.googleapis.com/revoke'));
    }

    public function test_catalog_shows_verified_badge_without_source_subtitle_or_manual_chip(): void
    {
        $verified = $this->makeSite([
            'site_name' => 'Verified Row',
            'domain' => 'verified-row.example',
            'site_url' => 'https://verified-row.example',
            'traffic' => 15400,
            'traffic_source' => VerifiedTraffic::SOURCE_GA4,
            'traffic_verified_at' => now(),
        ]);
        $manual = $this->makeSite([
            'site_name' => 'Manual Row',
            'domain' => 'manual-row.example',
            'site_url' => 'https://manual-row.example',
            'traffic' => 9000,
            'traffic_source' => VerifiedTraffic::SOURCE_MANUAL,
        ]);

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Verified traffic', $html);
        $this->assertStringNotContainsString('via GA4', $html);
        $this->assertStringNotContainsString('via GSC', $html);
        $this->assertStringNotContainsString('vt-badge--ghost', $html);
        $this->assertStringNotContainsString('publisher price', strtolower($html));
        $this->assertGreaterThanOrEqual(1, substr_count($html, 'vt-badge'));
        $this->assertStringContainsString((string) $verified->id, $html);
        $this->assertStringContainsString((string) $manual->id, $html);
    }

    public function test_admin_override_replaces_the_number_and_drops_the_badge(): void
    {
        $this->enableOauth();
        $site = $this->makeSite([
            'traffic' => 15400,
            'traffic_ga4' => 15400,
            'traffic_source' => VerifiedTraffic::SOURCE_GA4,
            'traffic_verified_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->post(staff_route('sites.traffic-override', $site->id), [
                'traffic_admin_override' => 5000,
            ])
            ->assertRedirect();

        $site->refresh();
        $this->assertSame(5000, (int) $site->traffic);
        $this->assertSame(5000, (int) $site->traffic_admin_override);
        $this->assertSame(VerifiedTraffic::SOURCE_ADMIN, $site->traffic_source);
        $this->assertFalse(VerifiedTraffic::showsBadge($site));

        $html = $this->actingAs($this->admin)
            ->get(staff_route('sites.edit', $site->id))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('ADMIN', $html);
        $this->assertStringContainsString('Admin override', $html);
    }

    public function test_command_is_scheduled_weekly_and_is_a_noop_without_schema(): void
    {
        $scheduled = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'sites:sync-verified-traffic'));
        $this->assertNotNull($scheduled);
        $this->assertTrue($scheduled->withoutOverlapping);
        $this->assertTrue($scheduled->onOneServer);

        Schema::dropIfExists('site_traffic_connections');
        Site::forgetColumnCache();
        $this->artisan('sites:sync-verified-traffic')->assertSuccessful();
    }

    public function test_pages_do_not_500_when_verified_traffic_tables_are_missing(): void
    {
        Schema::dropIfExists('site_traffic_connections');
        Schema::table('sites', function ($table) {
            foreach (['traffic_manual', 'traffic_ga4', 'traffic_gsc_clicks', 'traffic_admin_override', 'traffic_source', 'traffic_verified_at'] as $column) {
                if (Schema::hasColumn('sites', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
        Site::forgetColumnCache();

        $site = Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Pre-migration Site',
            'site_url' => 'https://pre-migration.example',
            'domain' => 'pre-migration.example',
            'da' => 30,
            'dr' => 30,
            'traffic' => 4000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'news',
            'price' => 50,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Pre-migration listing.',
            'verified' => true,
            'active' => 1,
        ]);

        $this->actingAs($this->advertiser)->get(route('advertiser.catalog'))->assertOk();
        $this->actingAs($this->publisher)->get(route('publisher.websites'))->assertOk();
        $this->actingAs($this->admin)->get(staff_route('sites.edit', $site->id))->assertOk();
        $this->assertFalse(VerifiedTraffic::schemaReady());
        $this->assertFalse(VerifiedTraffic::showsBadge($site->fresh()));
    }

    public function test_email_catalog_includes_verified_traffic_failure(): void
    {
        $this->assertArrayHasKey('verified_traffic_sync_failed', EmailCatalog::all());
        $this->assertNotNull(EmailCatalog::makeMailable('verified_traffic_sync_failed'));
        $this->assertSame(
            'verified_traffic_sync_failed',
            EmailCatalog::keyFromSubject('Verified traffic needs attention — Sample Site')
        );
    }

    public function test_login_google_client_is_untouched(): void
    {
        $source = file_get_contents(config_path('services.php'));
        $this->assertStringContainsString("'google' =>", $source);
        $this->assertStringContainsString("'google_traffic' =>", $source);
        $this->assertSame(trim((string) env('GOOGLE_CLIENT_ID', '')), config('services.google.client_id'));
    }
}
