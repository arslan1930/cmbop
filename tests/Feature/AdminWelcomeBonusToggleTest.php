<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use App\Services\PromotionService;
use App\Services\Wallet\WelcomeBonusService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminWelcomeBonusToggleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);

        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $this->admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
        ]);
        $this->admin->roles()->attach($adminRole->id);
    }

    public function test_guest_cannot_toggle_welcome_bonus(): void
    {
        $this->post(route('admin.promotions.welcome-bonus.toggle'))
            ->assertRedirect();

        $this->assertTrue(app(WelcomeBonusService::class)->isEnabled());
    }

    public function test_non_admin_cannot_toggle_welcome_bonus(): void
    {
        $advertiserRole = Role::where('name', 'advertiser')->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiserRole->id,
        ]);
        $user->roles()->attach($advertiserRole->id);

        $this->actingAs($user)
            ->post(route('admin.promotions.welcome-bonus.toggle'))
            ->assertForbidden();

        $this->assertTrue(app(WelcomeBonusService::class)->isEnabled());
    }

    public function test_admin_can_disable_and_enable_welcome_bonus(): void
    {
        $service = app(WelcomeBonusService::class);
        $this->assertTrue($service->isEnabled());

        $this->actingAs($this->admin)
            ->from(route('admin.promotions.index'))
            ->post(route('admin.promotions.welcome-bonus.toggle'), ['enabled' => 0])
            ->assertRedirect(route('admin.promotions.index'))
            ->assertSessionHas('success');

        $this->assertFalse($service->isEnabled());

        $this->actingAs($this->admin)
            ->from(route('admin.promotions.index'))
            ->post(route('admin.promotions.welcome-bonus.toggle'), ['enabled' => 1])
            ->assertRedirect(route('admin.promotions.index'))
            ->assertSessionHas('success');

        $this->assertTrue($service->isEnabled());
    }

    public function test_disable_when_already_disabled_does_not_reenable(): void
    {
        $service = app(WelcomeBonusService::class);
        $service->setEnabled(false);

        $this->actingAs($this->admin)
            ->from(route('admin.promotions.index'))
            ->post(route('admin.promotions.welcome-bonus.toggle'), ['enabled' => 0])
            ->assertRedirect(route('admin.promotions.index'))
            ->assertSessionHas('success');

        $this->assertFalse($service->isEnabled());
        $this->assertSame(0, ActivityLog::query()->where('action', 'welcome_bonus.toggled')->count());
    }

    public function test_toggle_requires_an_intended_enabled_state(): void
    {
        $this->actingAs($this->admin)
            ->from(route('admin.promotions.index'))
            ->post(route('admin.promotions.welcome-bonus.toggle'))
            ->assertRedirect(route('admin.promotions.index'))
            ->assertSessionHasErrors('enabled');

        $this->assertTrue(app(WelcomeBonusService::class)->isEnabled());
    }

    public function test_promotions_hub_shows_welcome_bonus_card(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.promotions.index'))
            ->assertOk()
            ->assertSee('€20 welcome credit', false)
            ->assertSee('Enabled', false)
            ->assertDontSee('>Unknown<', false)
            ->assertSee('Disable', false)
            ->assertSee(route('admin.promotions.welcome-bonus.toggle'), false)
            ->assertSee('name="enabled"', false)
            ->assertSee('value="0"', false);
    }

    public function test_promotions_hub_shows_enable_when_bonus_is_disabled(): void
    {
        app(WelcomeBonusService::class)->setEnabled(false);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.promotions.index'))
            ->assertOk()
            ->assertSee('€20 welcome credit', false)
            ->assertSee('Disabled', false)
            ->getContent();

        $this->assertMatchesRegularExpression('/btn-primary[^>]*>\s*Enable\s*</', $html);
        $this->assertDoesNotMatchRegularExpression('/btn-outline-danger[^>]*>\s*Disable\s*</', $html);
        $this->assertStringContainsString('name="enabled"', $html);
        $this->assertStringContainsString('value="1"', $html);
    }

    public function test_promotions_hub_does_not_fake_enabled_when_status_throws(): void
    {
        $this->mock(WelcomeBonusService::class, function ($mock) {
            $mock->shouldReceive('isEnabled')
                ->once()
                ->andThrow(new \RuntimeException('SQLSTATE[HY000]: leftover'));
        });

        $html = $this->actingAs($this->admin)
            ->get(route('admin.promotions.index'))
            ->assertOk()
            ->assertSee('Unknown', false)
            ->assertDontSee('SQLSTATE', false)
            ->getContent();

        $this->assertStringNotContainsString('>Enabled</span>', $html);
        $this->assertStringNotContainsString('€20 welcome credit', $html);
    }

    public function test_toggle_creates_settings_table_when_missing_then_disables(): void
    {
        Schema::dropIfExists('welcome_bonus_settings');
        $this->assertFalse(Schema::hasTable('welcome_bonus_settings'));

        $this->actingAs($this->admin)
            ->from(route('admin.promotions.index'))
            ->post(route('admin.promotions.welcome-bonus.toggle'), ['enabled' => 0])
            ->assertRedirect(route('admin.promotions.index'))
            ->assertSessionHas('success');

        $this->assertTrue(Schema::hasTable('welcome_bonus_settings'));
        $this->assertFalse(app(WelcomeBonusService::class)->isEnabled());
    }

    public function test_set_amount_creates_settings_table_when_missing(): void
    {
        Schema::dropIfExists('welcome_bonus_settings');
        $this->assertFalse(Schema::hasTable('welcome_bonus_settings'));

        $this->actingAs($this->admin)
            ->from(route('admin.promotions.index'))
            ->post(route('admin.promotions.welcome-bonus.amount'), ['amount' => 25])
            ->assertRedirect(route('admin.promotions.index'))
            ->assertSessionHas('success');

        $this->assertTrue(Schema::hasTable('welcome_bonus_settings'));
        $this->assertSame(25.0, app(WelcomeBonusService::class)->amount());
    }

    public function test_admin_can_update_welcome_bonus_amount(): void
    {
        $this->actingAs($this->admin)
            ->from(route('admin.promotions.index'))
            ->post(route('admin.promotions.welcome-bonus.amount'), ['amount' => 35.5])
            ->assertRedirect(route('admin.promotions.index'))
            ->assertSessionHas('success');

        $this->assertSame(35.5, app(WelcomeBonusService::class)->amount());
        $this->assertTrue(app(WelcomeBonusService::class)->isEnabled());
    }

    public function test_set_amount_does_not_reenable_a_disabled_bonus(): void
    {
        $service = app(WelcomeBonusService::class);
        $service->setEnabled(false);

        $this->actingAs($this->admin)
            ->from(route('admin.promotions.index'))
            ->post(route('admin.promotions.welcome-bonus.amount'), ['amount' => 40])
            ->assertRedirect(route('admin.promotions.index'))
            ->assertSessionHas('success');

        $this->assertSame(40.0, $service->amount());
        $this->assertFalse($service->isEnabled());
    }

    public function test_amount_above_the_hard_max_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->from(route('admin.promotions.index'))
            ->post(route('admin.promotions.welcome-bonus.amount'), ['amount' => 501])
            ->assertRedirect(route('admin.promotions.index'))
            ->assertSessionHasErrors('amount');

        $this->assertSame(20.0, app(WelcomeBonusService::class)->amount());
    }

    public function test_disable_and_enable_keep_a_zero_amount(): void
    {
        $service = app(WelcomeBonusService::class);
        $service->setAmount(0);

        $this->actingAs($this->admin)
            ->from(route('admin.promotions.index'))
            ->post(route('admin.promotions.welcome-bonus.toggle'), ['enabled' => 0])
            ->assertRedirect(route('admin.promotions.index'))
            ->assertSessionHas('success');

        $this->actingAs($this->admin)
            ->from(route('admin.promotions.index'))
            ->post(route('admin.promotions.welcome-bonus.toggle'), ['enabled' => 1])
            ->assertRedirect(route('admin.promotions.index'))
            ->assertSessionHas('success');

        $this->assertTrue($service->isEnabled());
        $this->assertSame(0.0, $service->amount());
    }

    public function test_promotions_hub_does_not_promise_grants_when_amount_is_zero(): void
    {
        app(WelcomeBonusService::class)->setAmount(0);

        $this->actingAs($this->admin)
            ->get(route('admin.promotions.index'))
            ->assertOk()
            ->assertSee('On — not granting', false)
            ->assertSee('amount is €0 so new advertisers will not receive credit', false)
            ->assertDontSee('New advertisers receive this spend-only credit', false)
            ->assertDontSee('>Enabled</span>', false);
    }

    public function test_enable_flash_does_not_promise_a_zero_grant(): void
    {
        $service = app(WelcomeBonusService::class);
        $service->setEnabled(false);
        $service->setAmount(0);

        $this->actingAs($this->admin)
            ->from(route('admin.promotions.index'))
            ->post(route('admin.promotions.welcome-bonus.toggle'), ['enabled' => 1])
            ->assertRedirect(route('admin.promotions.index'))
            ->assertSessionHas('success', 'Welcome bonus enabled. Amount is €0 — new advertisers will not receive credit.');

        $this->assertTrue($service->isEnabled());
        $this->assertFalse($service->canGrant());
    }

    public function test_set_amount_flash_says_disabled_bonus_does_not_grant(): void
    {
        app(WelcomeBonusService::class)->setEnabled(false);

        $this->actingAs($this->admin)
            ->from(route('admin.promotions.index'))
            ->post(route('admin.promotions.welcome-bonus.amount'), ['amount' => 25])
            ->assertRedirect(route('admin.promotions.index'))
            ->assertSessionHas(
                'success',
                'Welcome bonus amount set to €25.00. Bonus is still disabled — new advertisers will not receive it. Existing bonuses stay.'
            );
    }

    public function test_welcome_bonus_claim_stats_are_unavailable_when_table_missing(): void
    {
        Schema::dropIfExists('welcome_bonus_claims');

        $stats = app(PromotionService::class)->welcomeBonusClaimStats();

        $this->assertFalse($stats['available']);
        $this->assertSame(0, $stats['week']);
        $this->assertSame(0, $stats['total']);
        $this->assertNull($stats['last']);
    }

    public function test_promotions_hub_does_not_fake_zero_claims_when_stats_leftover(): void
    {
        $this->partialMock(PromotionService::class, function ($mock) {
            $mock->shouldReceive('welcomeBonusClaimStats')->andReturn([
                'week' => 0,
                'total' => 0,
                'last' => null,
                'available' => false,
            ]);
        });

        $this->actingAs($this->admin)
            ->get(route('admin.promotions.index'))
            ->assertOk()
            ->assertSee('Claims unavailable', false)
            ->assertDontSee('0 claims this week', false)
            ->assertDontSee('Something went wrong');
    }

    public function test_pricing_hides_bonus_note_when_bonus_cannot_grant(): void
    {
        app(WelcomeBonusService::class)->setEnabled(false);

        $this->get('/pricing')
            ->assertOk()
            ->assertDontSee('New advertisers get €20 free credit', false)
            ->assertDontSee('free credit for first orders', false);

        $this->get('/')
            ->assertOk()
            ->assertDontSee('New advertisers get €20 free credit', false);
    }

    public function test_pricing_shows_live_grant_amount(): void
    {
        app(WelcomeBonusService::class)->setAmount(35);

        $this->get('/pricing')
            ->assertOk()
            ->assertSee('New advertisers get €35 free credit', false)
            ->assertDontSee('New advertisers get €20 free credit', false);
    }

    public function test_marketing_pages_hide_new_grant_copy_when_bonus_cannot_grant(): void
    {
        app(WelcomeBonusService::class)->setEnabled(false);

        $this->get('/')
            ->assertOk()
            ->assertDontSee('€20 welcome credit', false);

        $this->get('/about')
            ->assertOk()
            ->assertDontSee('€20 welcome credit for new advertisers', false)
            ->assertDontSee('New advertisers receive €20 promotional credit', false)
            ->assertSee('wallet checkout.', false);

        $this->get('/how-it-works')
            ->assertOk()
            ->assertDontSee('New advertisers get €20 welcome credit', false)
            ->assertDontSee('What is the €20 welcome credit?', false)
            ->assertSee('Site prices are clear before checkout.', false);

        $this->get('/refund-policy')
            ->assertOk()
            ->assertDontSee('New advertisers receive €20 promotional welcome credit', false)
            ->assertDontSee('What happens to the €20 welcome bonus if an order is refunded?', false)
            ->assertSee('Promotional welcome credit is spend-only', false)
            ->assertSee('What happens to welcome credit if an order is refunded?', false);

        $this->get('/faq')
            ->assertOk()
            ->assertDontSee('Do new advertisers get bonus credit?', false)
            ->assertDontSee('New advertisers receive promotional bonus credit for first orders', false);
    }

    public function test_marketing_pages_use_live_grant_amount(): void
    {
        app(WelcomeBonusService::class)->setAmount(35);

        $this->get('/about')
            ->assertOk()
            ->assertSee('€35 welcome credit for new advertisers', false)
            ->assertDontSee('€20 welcome credit for new advertisers', false);

        $this->get('/how-it-works')
            ->assertOk()
            ->assertSee('New advertisers get €35 welcome credit', false)
            ->assertSee('What is the €35 welcome credit?', false);
    }

    public function test_register_meta_uses_live_grant_or_off_copy(): void
    {
        $this->get(route('register'))
            ->assertOk()
            ->assertSee('€20 Welcome Credit', false)
            ->assertSee('New advertisers get €20 welcome credit', false);

        app(WelcomeBonusService::class)->setAmount(35);
        $this->get(route('register'))
            ->assertOk()
            ->assertSee('€35 Welcome Credit', false)
            ->assertSee('New advertisers get €35 welcome credit', false)
            ->assertDontSee('€20 Welcome Credit', false);

        app(WelcomeBonusService::class)->setEnabled(false);
        $this->get(route('register'))
            ->assertOk()
            ->assertSee('Create Account | SEOLinkBuildings', false)
            ->assertSee('Free to start — no card required', false)
            ->assertDontSee('€20 Welcome Credit', false)
            ->assertDontSee('€35 Welcome Credit', false);
    }

    public function test_llms_txt_matches_live_grant(): void
    {
        $this->get('/llms.txt')
            ->assertOk()
            ->assertSee('€20 welcome credit for first orders', false);

        app(WelcomeBonusService::class)->setAmount(35);
        $this->get('/llms.txt')
            ->assertOk()
            ->assertSee('€35 welcome credit for first orders', false)
            ->assertDontSee('€20 welcome credit for first orders', false);

        app(WelcomeBonusService::class)->setEnabled(false);
        $this->get('/llms.txt')
            ->assertOk()
            ->assertDontSee('€20 welcome credit', false)
            ->assertDontSee('€35 welcome credit', false)
            ->assertSee('not always offered', false);
    }
}
