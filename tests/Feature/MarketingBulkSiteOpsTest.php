<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\BulkSiteRequest;
use App\Models\BulkSiteRequestItem;
use App\Models\Country;
use App\Models\InAppNotification;
use App\Models\Language;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CategoriesTableSeeder;
use Database\Seeders\CountriesTableSeeder;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class MarketingBulkSiteOpsTest extends TestCase
{
    use RefreshDatabase;

    private User $publisher;

    private User $marketer;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesTableSeeder::class);
        $this->seed(CountriesTableSeeder::class);
        $this->seed(LanguagesTableSeeder::class);
        $this->seed(CategoriesTableSeeder::class);

        $publisherRole = Role::where('name', 'publisher')->firstOrFail();
        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $this->publisher->roles()->attach($publisherRole->id);

        $marketingRole = Role::where('name', 'marketing')->firstOrFail();
        $this->marketer = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $marketingRole->id,
            'name' => 'Marketer Casey',
        ]);
        $this->marketer->roles()->attach($marketingRole->id);

        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $this->admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
        ]);
        $this->admin->roles()->attach($adminRole->id);
    }

    private function marketplaceCodes(): array
    {
        $country = Country::marketplace()->where('code', 'de')->first()
            ?? Country::marketplace()->firstOrFail();
        $language = Language::marketplace()->where('code', 'de')->first()
            ?? Language::marketplace()->firstOrFail();

        return [strtolower($country->code), strtolower($language->code)];
    }

    private function makeBulkRequest(): BulkSiteRequest
    {
        return BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_SHEET_SENT,
            'estimated_count' => 10,
            'sheet_sent_at' => now(),
        ]);
    }

    private function seedDraft(BulkSiteRequest $bulk, string $domain = 'wrong-seed.example'): Site
    {
        [$country, $language] = $this->marketplaceCodes();

        $site = new Site;
        $site->applyMarketplaceListing([
            'publisher_id' => $this->publisher->id,
            'bulk_site_request_id' => $bulk->id,
            'site_name' => 'Wrong Seed',
            'site_url' => 'https://'.$domain,
            'domain' => $domain,
            'example_url' => 'https://'.$domain,
            'da' => 10,
            'dr' => 12,
            'traffic' => 1000,
            'metrics_manual' => true,
            'country' => $country,
            'countries' => [$country],
            'language' => $language,
            'languages' => [$language],
            'category' => 'Pending',
            'price' => 50,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Placeholder description text. ', 3),
            'verified' => false,
            'active' => false,
            'as_you_prefer' => true,
            'onboarding_status' => Site::ONBOARDING_AWAITING_DETAILS,
        ]);
        $site->save();

        return $site->fresh();
    }

    public function test_marketer_can_seed_and_history_is_logged(): void
    {
        Mail::fake();
        [$country, $language] = $this->marketplaceCodes();
        $bulk = $this->makeBulkRequest();

        $rows = "https://seed-mkt.example,99,40,45,12000,{$language},{$country},Seed Mkt";

        $this->actingAs($this->marketer)
            ->post(route('admin.bulk-site-requests.seed', $bulk), ['rows' => $rows])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('sites', [
            'domain' => 'seed-mkt.example',
            'bulk_site_request_id' => $bulk->id,
            'onboarding_status' => Site::ONBOARDING_AWAITING_DETAILS,
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'bulk_request.seeded',
            'user_id' => $this->marketer->id,
        ]);

        $html = $this->actingAs($this->marketer)
            ->get(route('admin.bulk-site-requests.show', $bulk))
            ->assertOk()
            ->assertSee('History')
            ->assertSee('Cannot be deleted')
            ->assertSee('bulk_request.seeded')
            ->getContent();

        $this->assertStringContainsString('Append-only', $html);
        $this->assertStringContainsString('Done — add sites', $html);
    }

    public function test_marketer_done_from_items_creates_drafts_and_notifies(): void
    {
        Mail::fake();
        [$country, $language] = $this->marketplaceCodes();

        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 2,
        ]);
        BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://mkt-done-a.example',
            'domain' => 'mkt-done-a.example',
            'price' => 55,
        ]);
        BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://mkt-done-b.example',
            'domain' => 'mkt-done-b.example',
            'price' => 66,
        ]);

        $this->actingAs($this->marketer)
            ->post(route('admin.bulk-site-requests.done', $bulk), [
                'language' => $language,
                'country' => $country,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('sites', [
            'domain' => 'mkt-done-a.example',
            'publisher_id' => $this->publisher->id,
            'onboarding_status' => Site::ONBOARDING_AWAITING_DETAILS,
            'active' => 0,
        ]);

        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $this->publisher->id,
        ]);
        $this->assertTrue(
            InAppNotification::query()
                ->where('user_id', $this->publisher->id)
                ->where('title', 'like', '%Pending sites%')
                ->exists()
        );
    }

    public function test_marketer_can_delete_awaiting_details_draft_and_history_remains(): void
    {
        $bulk = $this->makeBulkRequest();
        $site = $this->seedDraft($bulk, 'oops-wrong.example');

        $this->actingAs($this->marketer)
            ->deleteJson(route('admin.sites.destroy', $site->id))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('sites', ['id' => $site->id]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'site.deleted_by_marketing',
            'user_id' => $this->marketer->id,
        ]);

        $log = ActivityLog::where('action', 'site.deleted_by_marketing')->first();
        $this->assertNotNull($log);
        $this->assertSame($bulk->id, (int) ($log->properties['bulk_site_request_id'] ?? 0));
        $this->assertSame('oops-wrong.example', $log->properties['domain'] ?? null);

        $this->actingAs($this->marketer)
            ->get(route('admin.bulk-site-requests.show', $bulk))
            ->assertOk()
            ->assertSee('site.deleted_by_marketing')
            ->assertSee('oops-wrong.example');
    }

    public function test_marketer_cannot_delete_verified_or_ready_site(): void
    {
        $bulk = $this->makeBulkRequest();
        $site = $this->seedDraft($bulk, 'locked-site.example');
        $site->update([
            'onboarding_status' => Site::ONBOARDING_READY_FOR_REVIEW,
            'verified' => false,
            'active' => false,
        ]);

        $this->actingAs($this->marketer)
            ->deleteJson(route('admin.sites.destroy', $site->id))
            ->assertStatus(403);

        $this->assertDatabaseHas('sites', ['id' => $site->id]);
    }

    public function test_activity_history_has_no_delete_route(): void
    {
        $hasDelete = collect(Route::getRoutes())->contains(function ($route) {
            $uri = $route->uri();
            $methods = $route->methods();

            return str_contains($uri, 'activity-log')
                && (in_array('DELETE', $methods, true) || in_array('delete', $methods, true));
        });

        $this->assertFalse($hasDelete, 'Activity history must remain immutable (no DELETE route)');
    }

    public function test_sheet_sent_and_cancel_are_logged(): void
    {
        $bulk = $this->makeBulkRequest();
        $bulk->update(['status' => BulkSiteRequest::STATUS_REQUESTED, 'sheet_sent_at' => null]);

        $this->actingAs($this->marketer)
            ->post(route('admin.bulk-site-requests.sheet-sent', $bulk))
            ->assertRedirect();

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'bulk_request.sheet_sent',
            'user_id' => $this->marketer->id,
        ]);

        $this->actingAs($this->marketer)
            ->post(route('admin.bulk-site-requests.cancel', $bulk))
            ->assertRedirect(route('admin.bulk-site-requests.index'));

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'bulk_request.cancelled',
            'user_id' => $this->marketer->id,
        ]);
        $this->assertSame(BulkSiteRequest::STATUS_CANCELLED, $bulk->fresh()->status);
    }
}
