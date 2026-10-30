<?php

namespace Tests\Feature;

use App\Models\AnnouncementDismissal;
use App\Models\Role;
use App\Models\SiteAnnouncement;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Database\Seeders\WhatsNewPackDiscountAnnouncementSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsNewBannerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
    }

    private function makeUser(string $roleName): User
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function liveAnnouncement(array $overrides = []): SiteAnnouncement
    {
        return SiteAnnouncement::create(array_merge([
            'title' => 'Whats new: pack discounts',
            'message' => 'Publishers can set 3, 5, and 10-article pack prices on their sites.',
            'type' => 'new_feature',
            'style' => 'success',
            'audience' => 'all',
            'cta_label' => 'Browse the catalog',
            'cta_url' => '/advertiser/catalog',
            'is_active' => true,
            'is_dismissible' => true,
            'priority' => 10,
            'version' => 1,
        ], $overrides));
    }

    public function test_banner_shows_on_advertiser_and_publisher_dashboards(): void
    {
        $announcement = $this->liveAnnouncement();
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');

        $this->actingAs($advertiser)
            ->get(route('advertiser.dashboard'))
            ->assertOk()
            ->assertSee('Whats new: pack discounts', false)
            ->assertSee('data-announcement-id="'.$announcement->id.'"', false);

        $this->actingAs($publisher)
            ->get(route('publisher.dashboard'))
            ->assertOk()
            ->assertSee('Whats new: pack discounts', false);
    }

    public function test_dismiss_hides_banner_for_that_user_only(): void
    {
        $announcement = $this->liveAnnouncement();
        $first = $this->makeUser('advertiser');
        $second = $this->makeUser('advertiser');

        $this->actingAs($first)
            ->postJson(route('announcements.dismiss', $announcement), [
                'version' => 1,
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertTrue(AnnouncementDismissal::isDismissed((int) $first->id, (int) $announcement->id, 1));

        $this->actingAs($first)
            ->get(route('advertiser.dashboard'))
            ->assertOk()
            ->assertDontSee('data-announcement-id="'.$announcement->id.'"', false);

        $this->actingAs($second)
            ->get(route('advertiser.dashboard'))
            ->assertOk()
            ->assertSee('data-announcement-id="'.$announcement->id.'"', false);
    }

    public function test_newer_version_shows_again_after_dismiss(): void
    {
        $announcement = $this->liveAnnouncement(['version' => 1]);
        $user = $this->makeUser('advertiser');

        $this->actingAs($user)
            ->post(route('announcements.dismiss', $announcement), ['version' => 1])
            ->assertRedirect();

        $this->actingAs($user)
            ->get(route('advertiser.dashboard'))
            ->assertDontSee('data-announcement-id="'.$announcement->id.'"', false);

        $announcement->update(['version' => 2]);

        $this->actingAs($user)
            ->get(route('advertiser.dashboard'))
            ->assertOk()
            ->assertSee('data-announcement-id="'.$announcement->id.'"', false)
            ->assertSee('data-announcement-version="2"', false);
    }

    public function test_seeder_creates_an_unpublished_draft(): void
    {
        $this->seed(WhatsNewPackDiscountAnnouncementSeeder::class);

        $row = SiteAnnouncement::query()
            ->where('title', "What's new: 3, 5 and 10-article packs")
            ->first();

        $this->assertNotNull($row);
        $this->assertFalse((bool) $row->is_active);
        $this->assertStringContainsString('3, 5, and 10-article pack', (string) $row->message);
        $this->assertStringNotContainsString('%', (string) $row->message);

        $advertiser = $this->makeUser('advertiser');
        $this->actingAs($advertiser)
            ->get(route('advertiser.dashboard'))
            ->assertOk()
            ->assertDontSee('data-announcement-id="'.$row->id.'"', false);
    }
}
