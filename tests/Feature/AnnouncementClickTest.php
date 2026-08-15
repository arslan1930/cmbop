<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SiteAnnouncement;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnnouncementClickTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_cta_click_increments_and_redirects(): void
    {
        $announcement = SiteAnnouncement::create([
            'title' => 'Offer',
            'message' => 'Save',
            'type' => 'limited_offer',
            'style' => 'promo',
            'audience' => 'all',
            'cta_label' => 'Shop',
            'cta_url' => '/advertiser/catalog',
            'is_active' => true,
        ]);

        $this->get(route('announcements.click', $announcement))
            ->assertRedirect();

        $this->assertSame(1, (int) $announcement->fresh()->clicks);
        $this->assertStringContainsString(
            '/advertiser/catalog',
            (string) $this->get(route('announcements.click', $announcement))->headers->get('Location')
        );
    }

    public function test_expired_click_goes_home(): void
    {
        $announcement = SiteAnnouncement::create([
            'title' => 'Old',
            'message' => 'Gone',
            'type' => 'limited_offer',
            'style' => 'promo',
            'audience' => 'all',
            'cta_label' => 'Shop',
            'cta_url' => 'https://example.com/sale',
            'is_active' => true,
            'ends_at' => now()->subDay(),
        ]);

        $this->get(route('announcements.click', $announcement))
            ->assertRedirect('/');
        $this->assertSame(0, (int) $announcement->fresh()->clicks);
    }

    public function test_guest_cannot_follow_advertiser_only_cta(): void
    {
        $announcement = SiteAnnouncement::create([
            'title' => 'Advertisers only',
            'message' => 'Private',
            'type' => 'limited_offer',
            'style' => 'promo',
            'audience' => 'advertiser',
            'cta_label' => 'Shop',
            'cta_url' => '/advertiser/catalog',
            'is_active' => true,
        ]);

        $this->get(route('announcements.click', $announcement))
            ->assertRedirect('/');
        $this->assertSame(0, (int) $announcement->fresh()->clicks);
    }

    public function test_advertiser_can_follow_advertiser_only_cta(): void
    {
        $this->seed(RolesTableSeeder::class);
        $role = Role::where('name', 'advertiser')->firstOrFail();
        $advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $advertiser->roles()->attach($role->id);

        $announcement = SiteAnnouncement::create([
            'title' => 'Advertisers only',
            'message' => 'Private',
            'type' => 'limited_offer',
            'style' => 'promo',
            'audience' => 'advertiser',
            'cta_label' => 'Shop',
            'cta_url' => '/advertiser/catalog',
            'is_active' => true,
        ]);

        $this->actingAs($advertiser)
            ->get(route('announcements.click', $announcement))
            ->assertRedirect();
        $this->assertStringContainsString(
            '/advertiser/catalog',
            (string) $this->actingAs($advertiser)->get(route('announcements.click', $announcement))->headers->get('Location')
        );
    }

    public function test_userinfo_cta_goes_home(): void
    {
        $announcement = SiteAnnouncement::create([
            'title' => 'Phish',
            'message' => 'Click',
            'type' => 'limited_offer',
            'style' => 'promo',
            'audience' => 'all',
            'cta_label' => 'Shop',
            'cta_url' => 'https://google.com@evil.example/sale',
            'is_active' => true,
        ]);

        $this->get(route('announcements.click', $announcement))
            ->assertRedirect('/');
        $this->assertSame(0, (int) $announcement->fresh()->clicks);
    }
}
