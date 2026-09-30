<?php

namespace Tests\Feature;

use App\Mail\AudienceCampaignMail;
use App\Models\DepositRequest;
use App\Models\EmailCampaign;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Services\AudienceInventoryService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminAudienceNeverDepositedTest extends TestCase
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

    private function giveWelcomeBonus(User $advertiser): void
    {
        $advRoleId = Role::where('name', 'advertiser')->value('id');
        Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => $advRoleId,
            'balance' => 20,
            'bonus_balance' => 20,
            'reserved_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);
    }

    private function deposit(User $user, string $status): DepositRequest
    {
        return DepositRequest::create([
            'user_id' => $user->id,
            'reference_code' => (string) random_int(100000, 999999),
            'amount' => 50,
            'payment_method' => 'wise',
            'status' => $status,
        ]);
    }

    public function test_segment_includes_advertisers_without_credited_deposits(): void
    {
        $noDeposit = $this->makeUser('advertiser');
        $this->giveWelcomeBonus($noDeposit);

        $pendingOnly = $this->makeUser('advertiser');
        $this->deposit($pendingOnly, 'pending');

        $rejectedOnly = $this->makeUser('advertiser');
        $this->deposit($rejectedOnly, 'rejected');

        $completed = $this->makeUser('advertiser');
        $this->deposit($completed, 'completed');

        $approved = $this->makeUser('advertiser');
        $this->deposit($approved, 'approved');

        $publisher = $this->makeUser('publisher');

        $ids = app(AudienceInventoryService::class)
            ->collect('advertisers_never_deposited')
            ->pluck('id')
            ->all();

        $this->assertContains($noDeposit->id, $ids);
        $this->assertContains($pendingOnly->id, $ids);
        $this->assertContains($rejectedOnly->id, $ids);
        $this->assertNotContains($completed->id, $ids);
        $this->assertNotContains($approved->id, $ids);
        $this->assertNotContains($publisher->id, $ids);

        $stats = app(AudienceInventoryService::class)->stats();
        $this->assertSame(3, $stats['advertisers_never_deposited']);
    }

    public function test_welcome_bonus_alone_does_not_exclude_advertiser(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $this->giveWelcomeBonus($advertiser);

        $this->assertTrue(
            app(AudienceInventoryService::class)
                ->queryAdvertisersNeverDeposited()
                ->where('users.id', $advertiser->id)
                ->exists()
        );
    }

    public function test_audience_inventory_never_deposited_tab_and_export(): void
    {
        $admin = $this->makeUser('admin');
        $target = $this->makeUser('advertiser');
        $funded = $this->makeUser('advertiser');
        $this->deposit($funded, 'completed');

        $this->actingAs($admin)
            ->get(route('admin.audiences.index', ['tab' => 'never_deposited']))
            ->assertOk()
            ->assertSee('Never deposited', false)
            ->assertSee($target->email, false)
            ->assertDontSee($funded->email, false)
            ->assertSee(route('admin.campaigns.index', ['audience' => 'advertisers_never_deposited'], false), false);

        $csv = $this->actingAs($admin)
            ->get(route('admin.audiences.export', ['audience' => 'never_deposited']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString($target->email, $csv);
        $this->assertStringNotContainsString($funded->email, $csv);
        $this->assertStringContainsString('advertisers_never_deposited', $csv);
    }

    public function test_campaigns_page_lists_never_deposited_audience(): void
    {
        $admin = $this->makeUser('admin');
        $this->makeUser('advertiser');

        $this->actingAs($admin)
            ->get(route('admin.campaigns.index', ['audience' => 'advertisers_never_deposited']))
            ->assertOk()
            ->assertSee('Advertisers: never deposited', false)
            ->assertSee('value="advertisers_never_deposited"', false);
    }

    public function test_campaign_send_accepts_never_deposited_audience(): void
    {
        Mail::fake();

        $admin = $this->makeUser('admin');
        $target = $this->makeUser('advertiser');
        $funded = $this->makeUser('advertiser');
        $this->deposit($funded, 'completed');

        $this->actingAs($admin)
            ->post(route('admin.campaigns.send'), [
                'name' => 'Deposit nudge',
                'subject' => 'Add funds to place your first guest post',
                'body_html' => '<p>Top up your EUR wallet to checkout.</p>',
                'audience' => 'advertisers_never_deposited',
                'cta_label' => 'Add funds',
                'cta_url' => url('/advertiser/add-funds'),
                'respect_preferences' => false,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $campaign = EmailCampaign::query()->latest('id')->first();
        $this->assertNotNull($campaign);
        $this->assertSame('advertisers_never_deposited', $campaign->audience);
        $this->assertSame('Advertisers (never deposited)', $campaign->audienceLabel());
        $this->assertSame(1, $campaign->recipients_count);
        $this->assertSame(1, $campaign->sent_count);

        Mail::assertQueued(AudienceCampaignMail::class, function (AudienceCampaignMail $mail) use ($target) {
            return $mail->hasTo($target->email);
        });
        Mail::assertNotQueued(AudienceCampaignMail::class, function (AudienceCampaignMail $mail) use ($funded) {
            return $mail->hasTo($funded->email);
        });
    }
}
