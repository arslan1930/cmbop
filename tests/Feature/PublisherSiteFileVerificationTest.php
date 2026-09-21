<?php

namespace Tests\Feature;

use App\Jobs\EnrichSiteJob;
use App\Mail\SiteStatusNotification;
use App\Models\InAppNotification;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\SiteFileVerificationService;
use Database\Seeders\CategoriesTableSeeder;
use Database\Seeders\CountriesTableSeeder;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class PublisherSiteFileVerificationTest extends TestCase
{
    use RefreshDatabase;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        $this->seed(CountriesTableSeeder::class);
        $this->seed(LanguagesTableSeeder::class);
        $this->seed(CategoriesTableSeeder::class);

        $role = Role::where('name', 'publisher')->firstOrFail();
        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $this->publisher->roles()->attach($role->id);
    }

    private function makeSite(array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Verify Demo',
            'site_url' => 'https://verify-demo.example',
            'domain' => 'verify-demo.example',
            'da' => 30,
            'dr' => 40,
            'traffic' => 5000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'News',
            'price' => 50,
            'publication_time' => 'permanent',
            'description' => 'A site awaiting file verification.',
            'link_type' => 'dofollow',
            'verified' => false,
            'active' => false,
        ], $overrides));
    }

    public function test_start_generates_token_and_instructions(): void
    {
        $site = $this->makeSite();

        $res = $this->actingAs($this->publisher)
            ->postJson(route('publisher.sites.verification.start', $site->id));

        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('verified', false)
            ->assertJsonPath('file_name', 'seolinkbuildings-verify.txt')
            ->assertJsonPath('file_url', 'https://verify-demo.example/seolinkbuildings-verify.txt');

        $this->assertNotEmpty($res->json('token'));
        $this->assertStringStartsWith('slb-verify-', $res->json('token'));

        $site->refresh();
        $this->assertSame($res->json('token'), $site->verify_token);
        $this->assertNotNull($site->verify_token_created_at);
    }

    public function test_check_verifies_when_file_matches_without_activating(): void
    {
        Mail::fake();
        Queue::fake();

        $site = $this->makeSite([
            'verify_token' => 'slb-verify-abcdefghijklmnopqrstuvwx',
            'verify_token_created_at' => now(),
            'active' => false,
        ]);

        Http::fake([
            'https://verify-demo.example/seolinkbuildings-verify.txt' => Http::response('slb-verify-abcdefghijklmnopqrstuvwx', 200, [
                'Content-Type' => 'text/plain',
            ]),
        ]);

        $res = $this->actingAs($this->publisher)
            ->postJson(route('publisher.sites.verification.check', $site->id));

        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('verified', true)
            ->assertJsonPath('error_code', null);

        $site->refresh();
        $this->assertTrue((bool) $site->verified);
        $this->assertFalse((bool) $site->active);
        $this->assertSame('file', $site->verify_method);
        $this->assertNotNull($site->verified_at);
        $this->assertNull($site->verify_token);

        Mail::assertQueued(SiteStatusNotification::class);
        Queue::assertPushed(EnrichSiteJob::class);
        $this->assertTrue(
            InAppNotification::query()
                ->where('user_id', $this->publisher->id)
                ->where('related_type', Site::class)
                ->where('related_id', $site->id)
                ->exists()
        );
    }

    public function test_check_trims_whitespace_and_bom_before_matching(): void
    {
        $site = $this->makeSite([
            'verify_token' => 'slb-verify-abcdefghijklmnopqrstuvwx',
            'verify_token_created_at' => now(),
        ]);

        Http::fake([
            '*' => Http::response("\xEF\xBB\xBF  slb-verify-abcdefghijklmnopqrstuvwx\n", 200, [
                'Content-Type' => 'text/plain',
            ]),
        ]);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.sites.verification.check', $site->id))
            ->assertOk()
            ->assertJsonPath('verified', true);

        $this->assertTrue((bool) $site->fresh()->verified);
    }

    public function test_check_fails_when_file_missing(): void
    {
        $site = $this->makeSite([
            'verify_token' => 'slb-verify-abcdefghijklmnopqrstuvwx',
            'verify_token_created_at' => now(),
        ]);

        Http::fake([
            '*' => Http::response('Not Found', 404),
        ]);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.sites.verification.check', $site->id))
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('verified', false)
            ->assertJsonPath('error_code', SiteFileVerificationService::ERROR_NOT_FOUND);

        $this->assertFalse((bool) $site->fresh()->verified);
    }

    public function test_check_fails_when_token_mismatches(): void
    {
        $site = $this->makeSite([
            'verify_token' => 'slb-verify-abcdefghijklmnopqrstuvwx',
            'verify_token_created_at' => now(),
        ]);

        Http::fake([
            '*' => Http::response('slb-verify-wrong-token-value-here', 200, [
                'Content-Type' => 'text/plain',
            ]),
        ]);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.sites.verification.check', $site->id))
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', SiteFileVerificationService::ERROR_MISMATCH);

        $this->assertFalse((bool) $site->fresh()->verified);
    }

    public function test_check_reports_unreachable_when_all_requests_fail(): void
    {
        $site = $this->makeSite([
            'verify_token' => 'slb-verify-abcdefghijklmnopqrstuvwx',
            'verify_token_created_at' => now(),
        ]);

        Http::fake(function () {
            throw new ConnectionException('Connection refused');
        });

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.sites.verification.check', $site->id))
            ->assertStatus(422)
            ->assertJsonPath('error_code', SiteFileVerificationService::ERROR_UNREACHABLE);
    }

    public function test_check_reports_not_public_when_all_candidates_blocked(): void
    {
        $site = $this->makeSite([
            'verify_token' => 'slb-verify-abcdefghijklmnopqrstuvwx',
            'verify_token_created_at' => now(),
        ]);

        Http::fake([
            '*' => Http::response('Forbidden', 403),
        ]);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.sites.verification.check', $site->id))
            ->assertStatus(422)
            ->assertJsonPath('error_code', SiteFileVerificationService::ERROR_NOT_PUBLIC);
    }

    public function test_check_falls_back_to_www_https_candidate(): void
    {
        $site = $this->makeSite([
            'verify_token' => 'slb-verify-abcdefghijklmnopqrstuvwx',
            'verify_token_created_at' => now(),
        ]);

        Http::fake([
            'https://verify-demo.example/seolinkbuildings-verify.txt' => Http::response('Not Found', 404),
            'https://www.verify-demo.example/seolinkbuildings-verify.txt' => Http::response('slb-verify-abcdefghijklmnopqrstuvwx', 200),
        ]);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.sites.verification.check', $site->id))
            ->assertOk()
            ->assertJsonPath('verified', true);
    }

    public function test_check_prefers_https_when_site_url_is_http(): void
    {
        $site = $this->makeSite([
            'site_url' => 'http://verify-demo.example',
            'verify_token' => 'slb-verify-abcdefghijklmnopqrstuvwx',
            'verify_token_created_at' => now(),
        ]);

        Http::fake([
            'https://verify-demo.example/seolinkbuildings-verify.txt' => Http::response('slb-verify-abcdefghijklmnopqrstuvwx', 200),
            'http://verify-demo.example/seolinkbuildings-verify.txt' => Http::response('wrong', 200),
        ]);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.sites.verification.check', $site->id))
            ->assertOk()
            ->assertJsonPath('verified', true)
            ->assertJsonPath('file_url', 'https://verify-demo.example/seolinkbuildings-verify.txt');
    }

    public function test_check_uses_domain_fallback_when_site_url_has_no_host(): void
    {
        $site = $this->makeSite([
            'site_url' => 'https://',
            'domain' => 'verify-demo.example/blog',
            'verify_token' => 'slb-verify-abcdefghijklmnopqrstuvwx',
            'verify_token_created_at' => now(),
        ]);

        Http::fake([
            'https://verify-demo.example/seolinkbuildings-verify.txt' => Http::response('slb-verify-abcdefghijklmnopqrstuvwx', 200),
        ]);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.sites.verification.check', $site->id))
            ->assertOk()
            ->assertJsonPath('verified', true)
            ->assertJsonPath('file_url', 'https://verify-demo.example/seolinkbuildings-verify.txt');
    }

    public function test_check_continues_after_wrong_apex_body_to_www_match(): void
    {
        $site = $this->makeSite([
            'verify_token' => 'slb-verify-abcdefghijklmnopqrstuvwx',
            'verify_token_created_at' => now(),
        ]);

        Http::fake([
            'https://verify-demo.example/seolinkbuildings-verify.txt' => Http::response('stale-wrong-token', 200),
            'https://www.verify-demo.example/seolinkbuildings-verify.txt' => Http::response('slb-verify-abcdefghijklmnopqrstuvwx', 200),
        ]);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.sites.verification.check', $site->id))
            ->assertOk()
            ->assertJsonPath('verified', true);
    }

    public function test_check_continues_after_403_to_next_candidate(): void
    {
        $site = $this->makeSite([
            'verify_token' => 'slb-verify-abcdefghijklmnopqrstuvwx',
            'verify_token_created_at' => now(),
        ]);

        Http::fake([
            'https://verify-demo.example/seolinkbuildings-verify.txt' => Http::response('Forbidden', 403),
            'https://www.verify-demo.example/seolinkbuildings-verify.txt' => Http::response('slb-verify-abcdefghijklmnopqrstuvwx', 200),
        ]);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.sites.verification.check', $site->id))
            ->assertOk()
            ->assertJsonPath('verified', true);
    }

    public function test_regenerate_invalidates_old_file_content(): void
    {
        $site = $this->makeSite([
            'verify_token' => 'slb-verify-old-token-aaaaaaaaaaaa',
            'verify_token_created_at' => now()->subHour(),
        ]);

        $start = $this->actingAs($this->publisher)
            ->postJson(route('publisher.sites.verification.start', $site->id), [
                'regenerate' => true,
            ]);

        $start->assertOk();
        $newToken = $start->json('token');
        $this->assertNotSame('slb-verify-old-token-aaaaaaaaaaaa', $newToken);
        $this->assertSame($newToken, $site->fresh()->verify_token);

        Http::fake([
            '*' => Http::response('slb-verify-old-token-aaaaaaaaaaaa', 200),
        ]);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.sites.verification.check', $site->id))
            ->assertStatus(422)
            ->assertJsonPath('error_code', SiteFileVerificationService::ERROR_MISMATCH);
    }

    public function test_already_verified_site_returns_success_without_fetch(): void
    {
        $site = $this->makeSite([
            'verified' => true,
            'verified_at' => now(),
            'verify_method' => 'manual',
        ]);

        Http::fake();

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.sites.verification.check', $site->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('verified', true);

        Http::assertNothingSent();
    }

    public function test_incomplete_bulk_draft_cannot_start_verification(): void
    {
        $site = $this->makeSite([
            'onboarding_status' => Site::ONBOARDING_AWAITING_DETAILS,
        ]);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.sites.verification.start', $site->id))
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', SiteFileVerificationService::ERROR_INCOMPLETE);

        $this->assertNull($site->fresh()->verify_token);
    }

    public function test_check_is_throttled_to_fifteen_attempts_per_ten_minutes_per_site(): void
    {
        $site = $this->makeSite([
            'verify_token' => 'slb-verify-abcdefghijklmnopqrstuvwx',
            'verify_token_created_at' => now(),
        ]);

        Http::fake([
            '*' => Http::response('Not Found', 404),
        ]);

        RateLimiter::clear('site-verify-check:'.$this->publisher->id.':'.$site->id);

        for ($i = 0; $i < 15; $i++) {
            $this->actingAs($this->publisher)
                ->postJson(route('publisher.sites.verification.check', $site->id))
                ->assertStatus(422)
                ->assertJsonPath('error_code', SiteFileVerificationService::ERROR_NOT_FOUND);
        }

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.sites.verification.check', $site->id))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'rate_limited');
    }

    public function test_successful_check_clears_rate_limit_budget(): void
    {
        Mail::fake();
        Queue::fake();

        $site = $this->makeSite([
            'verify_token' => 'slb-verify-abcdefghijklmnopqrstuvwx',
            'verify_token_created_at' => now(),
        ]);

        $key = 'site-verify-check:'.$this->publisher->id.':'.$site->id;
        RateLimiter::clear($key);
        for ($i = 0; $i < 5; $i++) {
            RateLimiter::hit($key, 600);
        }
        $this->assertSame(5, RateLimiter::attempts($key));

        Http::fake([
            '*' => Http::response('slb-verify-abcdefghijklmnopqrstuvwx', 200),
        ]);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.sites.verification.check', $site->id))
            ->assertOk()
            ->assertJsonPath('verified', true);

        $this->assertSame(0, RateLimiter::attempts($key));
    }

    public function test_background_recheck_verifies_pending_site_when_file_matches(): void
    {
        Mail::fake();
        Queue::fake();

        $site = $this->makeSite([
            'verify_token' => 'slb-verify-abcdefghijklmnopqrstuvwx',
            'verify_token_created_at' => now(),
        ]);

        Http::fake([
            '*' => Http::response('slb-verify-abcdefghijklmnopqrstuvwx', 200),
        ]);

        Artisan::call('sites:recheck-file-verification', ['--limit' => 50]);

        $site->refresh();
        $this->assertTrue((bool) $site->verified);
        $this->assertFalse((bool) $site->active);
        $this->assertSame('file', $site->verify_method);
        Mail::assertQueued(SiteStatusNotification::class);
    }

    public function test_background_recheck_skips_incomplete_bulk_drafts(): void
    {
        $site = $this->makeSite([
            'verify_token' => 'slb-verify-abcdefghijklmnopqrstuvwx',
            'verify_token_created_at' => now(),
            'onboarding_status' => Site::ONBOARDING_AWAITING_DETAILS,
        ]);

        Http::fake([
            '*' => Http::response('slb-verify-abcdefghijklmnopqrstuvwx', 200),
        ]);

        Artisan::call('sites:recheck-file-verification');

        $this->assertFalse((bool) $site->fresh()->verified);
        Http::assertNothingSent();
    }

    public function test_background_recheck_skips_active_catalog_listings(): void
    {
        Mail::fake();
        $site = $this->makeSite([
            'active' => true,
            'verify_token' => 'slb-verify-abcdefghijklmnopqrstuvwx',
            'verify_token_created_at' => now(),
        ]);

        Http::fake([
            '*' => Http::response('slb-verify-abcdefghijklmnopqrstuvwx', 200),
        ]);

        Artisan::call('sites:recheck-file-verification', ['--limit' => 50]);

        $this->assertFalse((bool) $site->fresh()->verified);
        Http::assertNothingSent();
    }

    public function test_my_sites_page_includes_verification_dialog_hooks(): void
    {
        $page = $this->actingAs($this->publisher)->get(route('publisher.websites'));
        $page->assertOk();
        $html = $page->getContent();
        $js = file_get_contents(public_path('assets/js/publisher-websites.js'));
        $this->assertStringContainsString('publisher-websites.js', $html);
        $this->assertStringContainsString('verificationCsrfToken', $js);
        $this->assertStringContainsString('credentials: \'same-origin\'', $js);
        $this->assertStringContainsString('replace(/&/g, \'&amp;\')', $js);
        $this->assertStringContainsString('openSiteVerificationDialog', $js);
        $this->assertStringContainsString('Verify this website', $js);
        $this->assertStringContainsString('.btn-verify-site', $js);
        $this->assertStringContainsString('verificationErrorTitle', $js);
    }

    public function test_my_sites_table_shows_get_verified_for_unverified_sites(): void
    {
        $this->makeSite(['verified' => false, 'active' => false]);

        $ajax = $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'pending']));

        $ajax->assertOk();
        $this->assertStringContainsString('btn-verify-site', $ajax->getContent());
        $this->assertStringContainsString('Get Verified', $ajax->getContent());
    }

    public function test_other_publishers_cannot_verify_foreign_sites(): void
    {
        $site = $this->makeSite();
        $role = Role::where('name', 'publisher')->firstOrFail();
        $other = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $other->roles()->attach($role->id);

        $this->actingAs($other)
            ->postJson(route('publisher.sites.verification.start', $site->id))
            ->assertNotFound();
    }
}
