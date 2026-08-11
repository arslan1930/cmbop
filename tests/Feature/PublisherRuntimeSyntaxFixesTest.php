<?php

namespace Tests\Feature;

use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublisherRuntimeSyntaxFixesTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_item_exposes_auto_approve_hours_helpers(): void
    {
        $this->assertGreaterThanOrEqual(1, OrderItem::autoApproveHours());
        $this->assertGreaterThanOrEqual(0, OrderItem::autoApproveReminderHoursBefore());
        $this->assertIsBool(OrderItem::autoApproveRequiresLiveUrlOk());
    }

    public function test_site_completed_orders_label_does_not_syntax_error(): void
    {
        $site = new Site(['completed_orders_count' => 0]);
        $this->assertSame('No completed orders yet', $site->completedOrdersLabel());

        $site->completed_orders_count = 2;
        $this->assertSame('2 completed orders', $site->completedOrdersLabel());
    }

    public function test_publisher_core_pages_render_without_fatal_errors(): void
    {
        $publisherRole = Role::firstOrCreate(['name' => 'publisher']);
        $publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $publisher->roles()->attach($publisherRole->id);

        $this->actingAs($publisher)
            ->get(route('publisher.dashboard'))
            ->assertOk();

        $this->actingAs($publisher)
            ->get(route('publisher.websites'))
            ->assertOk();

        $this->actingAs($publisher)
            ->get(route('publisher.tasks'))
            ->assertOk();

        $this->actingAs($publisher)
            ->get(route('publisher.withdraw'))
            ->assertOk();
    }

    public function test_advertiser_dashboard_renders_without_site_syntax_error(): void
    {
        $advertiserRole = Role::firstOrCreate(['name' => 'advertiser']);
        $advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiserRole->id,
        ]);
        $advertiser->roles()->attach($advertiserRole->id);

        // Recommended sites path loads Site models — regression for completedOrdersLabel brace break.
        Site::create([
            'publisher_id' => User::factory()->create(['email_verified_at' => now()])->id,
            'site_name' => 'Dash Reco Site',
            'site_url' => 'https://dash-reco.example',
            'domain' => 'dash-reco.example',
            'da' => 40,
            'dr' => 40,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 80,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Recommended site for advertiser dashboard regression.',
            'verified' => true,
            'active' => true,
            'completed_orders_count' => 2,
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.dashboard'))
            ->assertOk();
    }

    public function test_advertiser_content_library_renders_without_count_scope_fatal(): void
    {
        $advertiserRole = Role::firstOrCreate(['name' => 'advertiser']);
        $advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiserRole->id,
        ]);
        $advertiser->roles()->attach($advertiserRole->id);

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library'))
            ->assertOk();
    }

    public function test_site_safe_description_html_is_available(): void
    {
        $site = new Site(['description' => '<p onclick="x">Hello <strong>world</strong></p>']);
        $html = $site->safeDescriptionHtml();

        $this->assertStringContainsString('Hello', $html);
        $this->assertStringContainsString('<strong>world</strong>', $html);
        $this->assertStringNotContainsString('onclick', $html);
    }
}
