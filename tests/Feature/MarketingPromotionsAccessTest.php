<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\Wallet\WelcomeBonusService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketingPromotionsAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $marketer;

    private User $advertiser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);

        $marketing = Role::where('name', 'marketing')->firstOrFail();
        $this->marketer = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $marketing->id,
        ]);
        $this->marketer->roles()->attach($marketing->id);

        $advertiser = Role::where('name', 'advertiser')->firstOrFail();
        $this->advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiser->id,
        ]);
        $this->advertiser->roles()->attach($advertiser->id);
    }

    public function test_marketer_can_open_promotions_and_create_a_notice(): void
    {
        $this->actingAs($this->marketer)
            ->get(route('marketing.promotions.index'))
            ->assertOk()
            ->assertSee('Promotions Center', false)
            ->assertDontSee('welcome credit', false);

        $this->actingAs($this->marketer)
            ->post(route('marketing.promotions.announcements.store'), [
                'title' => 'Mkt notice',
                'message' => 'From marketing',
                'type' => 'general',
                'style' => 'info',
                'audience' => 'all',
                'is_active' => 1,
            ])
            ->assertRedirect(route('marketing.promotions.announcements.index'));
    }

    public function test_marketer_cannot_toggle_welcome_bonus(): void
    {
        $this->actingAs($this->marketer)
            ->post(route('admin.promotions.welcome-bonus.toggle'), ['enabled' => 0])
            ->assertRedirect(route('marketing.dashboard'));

        $this->assertTrue(app(WelcomeBonusService::class)->isEnabled());
    }

    public function test_marketer_cannot_change_welcome_bonus_amount(): void
    {
        $this->actingAs($this->marketer)
            ->post(route('admin.promotions.welcome-bonus.amount'), ['amount' => 100])
            ->assertRedirect(route('marketing.dashboard'));

        $this->assertSame(20.0, app(WelcomeBonusService::class)->amount());
    }

    public function test_advertiser_cannot_open_promotions(): void
    {
        $this->actingAs($this->advertiser)
            ->get(route('admin.promotions.index'))
            ->assertForbidden();
    }
}
