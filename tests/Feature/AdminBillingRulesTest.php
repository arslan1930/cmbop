<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\BillingRuleSetting;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\Billing\BillingRuleService;
use App\Support\ProductionRepair;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminBillingRulesTest extends TestCase
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

    public function test_guest_cannot_update_payout_rules(): void
    {
        $this->post(route('admin.finance.payout-rules.min'), ['min_amount' => 30])
            ->assertRedirect();
        $this->post(route('admin.finance.payout-rules.fee'), ['fee_percent' => 5])
            ->assertRedirect();

        $this->assertSame(20.0, app(BillingRuleService::class)->minWithdrawalAmount());
        $this->assertSame(0.0, app(BillingRuleService::class)->withdrawalFeePercent());
    }

    public function test_non_admin_cannot_update_payout_rules(): void
    {
        $advertiserRole = Role::where('name', 'advertiser')->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiserRole->id,
        ]);
        $user->roles()->attach($advertiserRole->id);

        $this->actingAs($user)
            ->post(route('admin.finance.payout-rules.min'), ['min_amount' => 30])
            ->assertForbidden();
        $this->actingAs($user)
            ->post(route('admin.finance.payout-rules.fee'), ['fee_percent' => 5])
            ->assertForbidden();
    }

    public function test_finance_hub_shows_payout_rules_card(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.finance'))
            ->assertOk()
            ->assertSee('Payout rules', false)
            ->assertSee('id="payoutRuleMinAmount"', false)
            ->assertSee('id="payoutRuleFeePercent"', false)
            ->assertSee(route('admin.finance.payout-rules.min'), false)
            ->assertSee(route('admin.finance.payout-rules.fee'), false)
            ->assertSee('Config fallback', false)
            ->assertSee('Existing requests keep the fee stored', false);
    }

    public function test_payout_queue_links_to_payout_rules(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.withdrawals'))
            ->assertOk()
            ->assertSee(route('admin.finance').'#payout-rules', false)
            ->assertSee('Payout rules', false);
    }

    public function test_admin_can_update_minimum_withdrawal(): void
    {
        $this->actingAs($this->admin)
            ->from(route('admin.finance'))
            ->post(route('admin.finance.payout-rules.min'), ['min_amount' => 35.5])
            ->assertRedirect(route('admin.finance'))
            ->assertSessionHas('success');

        $this->assertSame(35.5, app(BillingRuleService::class)->minWithdrawalAmount());
        $this->assertSame('stored', app(BillingRuleService::class)->snapshot()['min_amount_source']);
        $this->assertSame(0.0, app(BillingRuleService::class)->withdrawalFeePercent());

        $log = ActivityLog::query()->where('action', 'billing.min_withdrawal_changed')->first();
        $this->assertNotNull($log);
        $this->assertSame(35.5, (float) data_get($log->properties, 'min_amount'));
    }

    public function test_admin_can_update_withdrawal_fee(): void
    {
        $this->actingAs($this->admin)
            ->from(route('admin.finance'))
            ->post(route('admin.finance.payout-rules.fee'), ['fee_percent' => 7.5])
            ->assertRedirect(route('admin.finance'))
            ->assertSessionHas('success');

        $this->assertSame(7.5, app(BillingRuleService::class)->withdrawalFeePercent());
        $this->assertSame('stored', app(BillingRuleService::class)->snapshot()['fee_percent_source']);
        $this->assertSame(20.0, app(BillingRuleService::class)->minWithdrawalAmount());

        $log = ActivityLog::query()->where('action', 'billing.withdrawal_fee_changed')->first();
        $this->assertNotNull($log);
        $this->assertSame(7.5, (float) data_get($log->properties, 'fee_percent'));
    }

    public function test_same_value_does_not_write_a_second_audit_row(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.finance.payout-rules.min'), ['min_amount' => 40])
            ->assertSessionHas('success');
        $this->actingAs($this->admin)
            ->from(route('admin.finance'))
            ->post(route('admin.finance.payout-rules.min'), ['min_amount' => 40])
            ->assertRedirect(route('admin.finance'))
            ->assertSessionHas('success');

        $this->assertSame(1, ActivityLog::query()->where('action', 'billing.min_withdrawal_changed')->count());
    }

    public function test_fee_over_cap_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->from(route('admin.finance'))
            ->post(route('admin.finance.payout-rules.fee'), ['fee_percent' => 51])
            ->assertRedirect(route('admin.finance'))
            ->assertSessionHasErrors('fee_percent');

        $this->assertSame(0.0, app(BillingRuleService::class)->withdrawalFeePercent());
    }

    public function test_minimum_below_floor_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->from(route('admin.finance'))
            ->post(route('admin.finance.payout-rules.min'), ['min_amount' => 0])
            ->assertRedirect(route('admin.finance'))
            ->assertSessionHasErrors('min_amount');

        $this->assertSame(20.0, app(BillingRuleService::class)->minWithdrawalAmount());
    }

    public function test_save_creates_settings_table_when_missing(): void
    {
        Schema::dropIfExists('billing_rule_settings');
        $this->assertFalse(Schema::hasTable('billing_rule_settings'));

        $this->actingAs($this->admin)
            ->from(route('admin.finance'))
            ->post(route('admin.finance.payout-rules.fee'), ['fee_percent' => 4])
            ->assertRedirect(route('admin.finance'))
            ->assertSessionHas('success');

        $this->assertTrue(Schema::hasTable('billing_rule_settings'));
        $this->assertSame(4.0, app(BillingRuleService::class)->withdrawalFeePercent());
    }

    public function test_stored_fee_overrides_config_for_new_withdrawals(): void
    {
        config(['billing.withdrawal_fee_percent' => 10]);
        app(BillingRuleService::class)->setFeePercent(5);

        $publisher = $this->publisher(100);

        $this->actingAs($publisher)
            ->postJson(route('publisher.withdraw.request'), [
                'amount' => 50,
                'payment_method' => 'paypal',
                'paypal_email' => 'pay@example.com',
                'paypal_email_confirm' => 'pay@example.com',
                'details_confirmed' => '1',
            ])
            ->assertOk()
            ->assertJsonPath('fee', 2.5)
            ->assertJsonPath('net_amount', 47.5);

        $this->assertDatabaseHas('withdrawals', [
            'user_id' => $publisher->id,
            'amount' => 50,
            'fee' => 2.5,
            'net_amount' => 47.5,
        ]);
    }

    public function test_stored_minimum_overrides_config_for_new_withdrawals(): void
    {
        config(['billing.withdrawal_min_amount' => 20]);
        app(BillingRuleService::class)->setMinAmount(40);

        $publisher = $this->publisher(100);

        $this->actingAs($publisher)
            ->postJson(route('publisher.withdraw.request'), [
                'amount' => 20,
                'payment_method' => 'paypal',
                'paypal_email' => 'pay@example.com',
                'paypal_email_confirm' => 'pay@example.com',
                'details_confirmed' => '1',
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('withdrawals', 0);

        $this->actingAs($publisher)
            ->postJson(route('publisher.withdraw.request'), [
                'amount' => 40,
                'payment_method' => 'paypal',
                'paypal_email' => 'pay@example.com',
                'paypal_email_confirm' => 'pay@example.com',
                'details_confirmed' => '1',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseCount('withdrawals', 1);
    }

    public function test_changing_fee_does_not_rewrite_existing_withdrawal_fee(): void
    {
        $publisher = $this->publisher(100);
        $this->actingAs($publisher)
            ->postJson(route('publisher.withdraw.request'), [
                'amount' => 50,
                'payment_method' => 'paypal',
                'paypal_email' => 'pay@example.com',
                'paypal_email_confirm' => 'pay@example.com',
                'details_confirmed' => '1',
            ])
            ->assertOk();

        $withdrawal = Withdrawal::where('user_id', $publisher->id)->firstOrFail();
        $this->assertSame(0.0, (float) $withdrawal->fee);

        app(BillingRuleService::class)->setFeePercent(10);

        $withdrawal->refresh();
        $this->assertSame(0.0, (float) $withdrawal->fee);
        $this->assertSame(50.0, (float) $withdrawal->net_amount);
    }

    public function test_missing_table_falls_back_to_config(): void
    {
        config(['billing.withdrawal_fee_percent' => 8, 'billing.withdrawal_min_amount' => 25]);
        Schema::dropIfExists('billing_rule_settings');

        $service = app(BillingRuleService::class);
        $this->assertFalse($service->tableReady());
        $this->assertSame(25.0, $service->minWithdrawalAmount());
        $this->assertSame(8.0, $service->withdrawalFeePercent());
        $this->assertSame('config', $service->snapshot()['min_amount_source']);
        $this->assertSame('config', $service->snapshot()['fee_percent_source']);
    }

    public function test_repair_creates_billing_rule_settings_when_migrate_row_exists(): void
    {
        Schema::dropIfExists('billing_rule_settings');
        $this->assertFalse(ProductionRepair::billingRuleStorageReady());

        $recorded = DB::table('migrations')->where(
            'migration',
            '2026_09_14_213000_create_billing_rule_settings_table'
        )->count();
        $this->assertSame(1, $recorded);

        $notes = [];
        app(ProductionRepair::class)->ensureBillingRuleSettings($notes);

        $this->assertTrue(Schema::hasTable('billing_rule_settings'));
        $this->assertTrue(ProductionRepair::billingRuleStorageReady());
        $this->assertTrue(collect($notes)->contains('billing rule settings table ready'));
        $this->assertTrue(BillingRuleSetting::tableReady());
    }

    private function publisher(float $balance = 100): User
    {
        $role = Role::firstOrCreate(['name' => 'publisher']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        Wallet::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'balance' => $balance,
            'bonus_balance' => 0,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);

        return $user->fresh();
    }
}
