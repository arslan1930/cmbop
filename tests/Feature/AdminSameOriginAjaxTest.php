<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSameOriginAjaxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
    }

    private function userWithRole(string $roleName): User
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function assertJsonScriptUrlIsRelative(string $html, string $assignment, string $relativePath): void
    {
        $encoded = str_replace('/', '\/', $relativePath);
        $this->assertStringContainsString($assignment.' = "'.$encoded.'"', $html);
        $this->assertStringNotContainsString(
            $assignment.' = "'.str_replace('/', '\/', rtrim((string) config('app.url'), '/').$relativePath).'"',
            $html
        );
    }

    public function test_dashboard_and_sidebar_fetch_on_the_same_origin(): void
    {
        $html = $this->actingAs($this->userWithRole('admin'))
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('dashboardFetch(`/admin/dashboard/statistics`)', $html);
        $this->assertStringContainsString("fetch('/admin/dashboard/queue-counts'", $html);
        $this->assertStringContainsString('REMIND_PUBLISHER_URL = "\/admin\/orders\/items\/__ID__\/remind-publisher"', $html);

        $origin = rtrim((string) config('app.url'), '/');
        $this->assertStringNotContainsString($origin.'/admin/dashboard/statistics', $html);
        $this->assertStringNotContainsString($origin.'/admin/dashboard/queue-counts', $html);
    }

    public function test_withdrawals_deposits_and_records_fetch_on_the_same_origin(): void
    {
        $admin = $this->userWithRole('admin');

        $withdrawals = $this->actingAs($admin)->get(route('admin.withdrawals'))->assertOk()->getContent();
        $this->assertJsonScriptUrlIsRelative($withdrawals, 'withdrawalsDataUrl', '/admin/withdrawals/data');
        $this->assertJsonScriptUrlIsRelative($withdrawals, 'withdrawalsStatisticsUrl', '/admin/withdrawals/statistics');

        $deposits = $this->actingAs($admin)->get(route('admin.deposits'))->assertOk()->getContent();
        $this->assertStringContainsString('approveUrlTemplate = "\/admin\/deposits\/__ID__\/approve"', $deposits);
        $this->assertStringNotContainsString(
            'approveUrlTemplate = "'.str_replace('/', '\/', rtrim((string) config('app.url'), '/').'/admin/deposits/__ID__/approve').'"',
            $deposits
        );

        $records = $this->actingAs($admin)->get(route('admin.sites.records'))->assertOk()->getContent();
        $this->assertJsonScriptUrlIsRelative($records, 'RECORDS_URL', '/admin/sites/records');
        $this->assertJsonScriptUrlIsRelative($records, 'BULK_VERIFY_URL', '/admin/sites/records/bulk-verify');
    }

    public function test_campaigns_and_ratings_fetch_on_the_same_origin(): void
    {
        $admin = $this->userWithRole('admin');

        $campaigns = $this->actingAs($admin)->get(route('admin.campaigns.index'))->assertOk()->getContent();
        $this->assertStringContainsString('countUrl = "\/admin\/campaigns\/recipient-count"', $campaigns);
        $this->assertStringContainsString('fetch("\/admin\/campaigns\/preview"', $campaigns);

        $ratings = $this->actingAs($admin)->get(route('admin.site-ratings.index'))->assertOk()->getContent();
        $this->assertJsonScriptUrlIsRelative($ratings, 'RATING_STORE', '/admin/site-ratings');
    }

    public function test_marketer_sidebar_badges_fetch_on_the_same_origin(): void
    {
        $html = $this->actingAs($this->userWithRole('marketing'))
            ->get(route('marketing.dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('fetch("\/marketing\/dashboard\/queue-counts"', $html);
        $this->assertStringNotContainsString(
            'fetch("'.str_replace('/', '\/', rtrim((string) config('app.url'), '/').'/marketing/dashboard/queue-counts').'"',
            $html
        );
    }
}
