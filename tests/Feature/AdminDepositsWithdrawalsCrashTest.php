<?php

namespace Tests\Feature;

use App\Models\DepositRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\Wallet\ManualDepositApproveLink;
use App\Services\Wallet\ManualWithdrawalMarkPaidLink;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AdminDepositsWithdrawalsCrashTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        Mail::fake();
        config([
            'app.url' => 'http://127.0.0.1:8000',
            'billing.deposit_approve_link_expire_minutes' => 60 * 24 * 7,
            'billing.withdrawal_mark_paid_link_expire_minutes' => 60 * 24 * 7,
        ]);
        URL::forceRootUrl('http://127.0.0.1:8000');
    }

    private function admin(): User
    {
        return $this->makeUser('admin');
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

    private function advertiserWallet(User $user, float $balance = 0): Wallet
    {
        return Wallet::create([
            'user_id' => $user->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => $balance,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);
    }

    private function publisherWallet(User $user, float $balance = 0): Wallet
    {
        return Wallet::create([
            'user_id' => $user->id,
            'role_id' => Wallet::publisherRoleId(),
            'balance' => $balance,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);
    }

    private function pendingDeposit(User $advertiser, array $overrides = []): DepositRequest
    {
        return DepositRequest::create(array_merge([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-'.substr(uniqid(), -6),
            'amount' => 40,
            'payment_method' => 'bank',
            'status' => 'pending',
        ], $overrides));
    }

    private function pendingWithdrawal(User $publisher, array $overrides = []): Withdrawal
    {
        return Withdrawal::create(array_merge([
            'user_id' => $publisher->id,
            'amount' => 50,
            'fee' => 0,
            'net_amount' => 50,
            'payment_method' => 'wise',
            'payment_details' => ['email' => 'wise@example.com'],
            'status' => 'pending',
        ], $overrides));
    }

    private function relativeSignedUrl(string $absoluteUrl): string
    {
        $parts = parse_url($absoluteUrl);
        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return $path.$query;
    }

    private function dropColumnOrSkip(string $table, string $column): void
    {
        if (! Schema::hasColumn($table, $column)) {
            $this->markTestSkipped($table.'.'.$column.' is already absent');
        }

        try {
            Schema::table($table, function (Blueprint $blueprint) use ($column) {
                $blueprint->dropColumn($column);
            });
        } catch (\Throwable) {
            $this->markTestSkipped('Could not drop '.$table.'.'.$column.' on this driver');
        }

        if (Schema::hasColumn($table, $column)) {
            $this->markTestSkipped($table.'.'.$column.' is still present after drop');
        }
    }

    private function restoreColumn(string $table, string $column, string $type = 'timestamp'): void
    {
        if (Schema::hasColumn($table, $column)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column, $type) {
            if ($type === 'string') {
                $blueprint->string($column, 20)->nullable();

                return;
            }

            $blueprint->timestamp($column)->nullable();
        });
    }

    private function restoreDepositRequestsTable(): void
    {
        $this->artisan('migrate', [
            '--path' => 'database/migrations/2026_04_21_115734_create_deposit_requests_table.php',
            '--force' => true,
        ]);
        $this->artisan('migrate', [
            '--path' => 'database/migrations/2026_04_22_113004_add_stripe_fields_to_deposit_requests_table.php',
            '--force' => true,
        ]);
        $this->artisan('migrate', [
            '--path' => 'database/migrations/2026_07_21_140000_add_user_marked_paid_to_deposit_requests.php',
            '--force' => true,
        ]);
        $this->artisan('migrate', [
            '--path' => 'database/migrations/2026_08_14_160000_unique_deposit_stripe_ids.php',
            '--force' => true,
        ]);
    }

    private function restoreWithdrawalsTable(): void
    {
        $this->artisan('migrate', [
            '--path' => 'database/migrations/2026_04_28_092359_create_withdrawals_table.php',
            '--force' => true,
        ]);
        $this->artisan('migrate', [
            '--path' => 'database/migrations/2026_08_12_110000_add_cancelled_by_to_withdrawals_table.php',
            '--force' => true,
        ]);
        $this->artisan('migrate', [
            '--path' => 'database/migrations/2026_08_14_120000_add_wallet_id_to_withdrawals_table.php',
            '--force' => true,
        ]);
    }

    private function restoreWalletsTable(): void
    {
        $this->artisan('migrate', [
            '--path' => 'database/migrations/2026_03_27_170746_create_wallets_table.php',
            '--force' => true,
        ]);
        $this->artisan('migrate', [
            '--path' => 'database/migrations/2026_07_15_120000_add_bonus_balances_to_wallets_table.php',
            '--force' => true,
        ]);
        $this->artisan('migrate', [
            '--path' => 'database/migrations/2026_07_31_150100_add_debt_balance_to_wallets_table.php',
            '--force' => true,
        ]);
    }

    public function test_deposits_index_survives_missing_table_and_array_filters(): void
    {
        $admin = $this->admin();
        $advertiser = $this->makeUser('advertiser');
        $this->pendingDeposit($advertiser, ['reference_code' => 'DEP-ARRAY']);

        $this->actingAs($admin)
            ->get(route('admin.deposits', [
                'status' => ['pending'],
                'search' => ['injected'],
            ]))
            ->assertOk()
            ->assertDontSee('Something went wrong')
            ->assertSee('DEP-ARRAY');

        Schema::dropIfExists('deposit_requests');
        $this->assertFalse(Schema::hasTable('deposit_requests'));

        try {
            $this->actingAs($admin)
                ->get(route('admin.deposits'))
                ->assertOk()
                ->assertDontSee('Something went wrong');

            $this->actingAs($admin)
                ->getJson(route('admin.deposits.show', 1))
                ->assertOk()
                ->assertJsonPath('success', false);

            $this->actingAs($admin)
                ->postJson(route('admin.deposits.approve', 1))
                ->assertNotFound()
                ->assertJsonPath('success', false);

            $this->actingAs($admin)
                ->postJson(route('admin.deposits.reject', 1))
                ->assertOk()
                ->assertJsonPath('success', false);

            $this->actingAs($admin)
                ->postJson(route('admin.deposits.paypal-refund', 1))
                ->assertOk()
                ->assertJsonPath('success', false);

            $confirm = URL::temporarySignedRoute(
                'admin.deposits.approve-confirm.show',
                now()->addHour(),
                ['deposit' => 1],
                absolute: false
            );
            $this->actingAs($admin)->get($confirm)->assertNotFound();
        } finally {
            $this->restoreDepositRequestsTable();
        }
    }

    public function test_deposits_index_survives_leftover_created_at(): void
    {
        $admin = $this->admin();
        $advertiser = $this->makeUser('advertiser');
        $deposit = $this->pendingDeposit($advertiser, ['reference_code' => 'DEP-LEFTOVER-DATE']);
        DB::table('deposit_requests')->where('id', $deposit->id)->update([
            'created_at' => 'not-a-date',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.deposits'))
            ->assertOk()
            ->assertDontSee('Something went wrong')
            ->assertSee('DEP-LEFTOVER-DATE');

        $this->actingAs($admin)
            ->getJson(route('admin.deposits.show', $deposit->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('deposit.reference_code', 'DEP-LEFTOVER-DATE');
    }

    public function test_deposits_search_finds_numeric_id(): void
    {
        $admin = $this->admin();
        $advertiser = $this->makeUser('advertiser');
        $deposit = $this->pendingDeposit($advertiser, ['reference_code' => 'DEP-SEARCH-ID']);

        $this->actingAs($admin)
            ->get(route('admin.deposits', ['search' => (string) $deposit->id]))
            ->assertOk()
            ->assertSee('DEP-SEARCH-ID')
            ->assertDontSee('Something went wrong');
    }

    public function test_approve_survives_leftover_approved_and_reported_dates(): void
    {
        $admin = $this->admin();
        $advertiser = $this->makeUser('advertiser');
        $wallet = $this->advertiserWallet($advertiser, 0);
        $deposit = $this->pendingDeposit($advertiser, ['amount' => 25, 'reference_code' => 'DEP-LEFTOVER-APPROVE']);
        DB::table('deposit_requests')->where('id', $deposit->id)->update([
            'approved_at' => 'not-a-date',
            'user_marked_paid_at' => 'also-not-a-date',
            'rejected_at' => 'not-a-date',
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.deposits.approve', $deposit->id))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('completed', $deposit->fresh()->status);
        $this->assertEqualsWithDelta(25.0, (float) $wallet->fresh()->balance, 0.01);
    }

    public function test_approve_still_credits_without_approved_at_column(): void
    {
        $admin = $this->admin();
        $advertiser = $this->makeUser('advertiser');
        $wallet = $this->advertiserWallet($advertiser, 0);
        $deposit = $this->pendingDeposit($advertiser, ['amount' => 40]);

        $this->dropColumnOrSkip('deposit_requests', 'approved_at');

        try {
            $this->actingAs($admin)
                ->postJson(route('admin.deposits.approve', $deposit->id))
                ->assertOk()
                ->assertJsonPath('success', true);

            $this->assertSame('completed', $deposit->fresh()->status);
            $this->assertEqualsWithDelta(40.0, (float) $wallet->fresh()->balance, 0.01);
        } finally {
            $this->restoreColumn('deposit_requests', 'approved_at');
        }
    }

    public function test_reject_still_works_without_rejected_at_column(): void
    {
        $admin = $this->admin();
        $advertiser = $this->makeUser('advertiser');
        $deposit = $this->pendingDeposit($advertiser);

        $this->dropColumnOrSkip('deposit_requests', 'rejected_at');

        try {
            $this->actingAs($admin)
                ->postJson(route('admin.deposits.reject', $deposit->id), [
                    'admin_notes' => 'No proof of this transfer.',
                ])
                ->assertOk()
                ->assertJsonPath('success', true);

            $this->assertSame('rejected', $deposit->fresh()->status);
        } finally {
            $this->restoreColumn('deposit_requests', 'rejected_at');
        }
    }

    public function test_deposit_confirm_survives_missing_wallets_and_approved_at(): void
    {
        $admin = $this->admin();
        $advertiser = $this->makeUser('advertiser');
        $this->advertiserWallet($advertiser, 10);
        $deposit = $this->pendingDeposit($advertiser, ['amount' => 25]);
        $url = $this->relativeSignedUrl(ManualDepositApproveLink::url($deposit));

        $this->dropColumnOrSkip('deposit_requests', 'approved_at');

        try {
            Schema::dropIfExists('wallets');
        } catch (\Throwable) {
            $this->markTestSkipped('Could not drop wallets on this driver');
        }
        if (Schema::hasTable('wallets')) {
            $this->markTestSkipped('wallets is still present after drop');
        }

        try {
            $this->actingAs($admin)
                ->get($url)
                ->assertOk()
                ->assertDontSee('Something went wrong')
                ->assertSee('Confirm deposit approval', false);
        } finally {
            $this->restoreColumn('deposit_requests', 'approved_at');
            if (! Schema::hasTable('wallets')) {
                $this->restoreWalletsTable();
            }
        }
    }

    public function test_approve_fails_safely_when_wallets_table_is_gone(): void
    {
        $admin = $this->admin();
        $advertiser = $this->makeUser('advertiser');
        $this->advertiserWallet($advertiser, 0);
        $deposit = $this->pendingDeposit($advertiser, ['amount' => 30]);

        try {
            Schema::dropIfExists('wallets');
        } catch (\Throwable) {
            $this->markTestSkipped('Could not drop wallets on this driver');
        }
        if (Schema::hasTable('wallets')) {
            $this->markTestSkipped('wallets is still present after drop');
        }

        try {
            $this->actingAs($admin)
                ->postJson(route('admin.deposits.approve', $deposit->id))
                ->assertOk()
                ->assertJsonPath('success', false);

            $this->assertSame('pending', $deposit->fresh()->status);
        } finally {
            $this->restoreWalletsTable();
        }
    }

    public function test_withdrawals_list_survives_missing_table_and_array_filters(): void
    {
        $admin = $this->admin();
        $publisher = $this->makeUser('publisher');
        $this->pendingWithdrawal($publisher);

        $this->actingAs($admin)
            ->get(route('admin.withdrawals'))
            ->assertOk()
            ->assertDontSee('Something went wrong');

        $this->actingAs($admin)
            ->getJson(route('admin.withdrawals.data', [
                'status' => ['pending'],
                'queue' => ['open'],
                'search' => ['injected'],
                'payment_method' => ['wise'],
                'date_from' => ['not-a-date'],
                'date_to' => ['also-bad'],
            ]))
            ->assertOk()
            ->assertJsonPath('success', true);

        Schema::dropIfExists('withdrawals');
        $this->assertFalse(Schema::hasTable('withdrawals'));

        try {
            $this->actingAs($admin)
                ->getJson(route('admin.withdrawals.data'))
                ->assertOk()
                ->assertJsonPath('success', true)
                ->assertJsonPath('data', []);

            $this->actingAs($admin)
                ->getJson(route('admin.withdrawals.statistics'))
                ->assertOk()
                ->assertJsonPath('success', true)
                ->assertJsonPath('data.pending', 0);

            $this->actingAs($admin)
                ->get(route('admin.withdrawals.export'))
                ->assertOk();

            $this->actingAs($admin)
                ->getJson(route('admin.withdrawals.show', 1))
                ->assertNotFound();

            $this->actingAs($admin)
                ->postJson(route('admin.withdrawals.paid', 1))
                ->assertNotFound();

            $this->actingAs($admin)
                ->postJson(route('admin.withdrawals.processing', 1))
                ->assertNotFound();

            $this->actingAs($admin)
                ->postJson(route('admin.withdrawals.reject', 1))
                ->assertNotFound();

            $this->actingAs($admin)
                ->postJson(route('admin.withdrawals.batch'), [
                    'ids' => [1],
                    'action' => 'completed',
                ])
                ->assertNotFound();

            $confirm = URL::temporarySignedRoute(
                'admin.withdrawals.mark-paid-confirm.show',
                now()->addHour(),
                ['withdrawal' => 1],
                absolute: false
            );
            $this->actingAs($admin)->get($confirm)->assertNotFound();
        } finally {
            $this->restoreWithdrawalsTable();
        }
    }

    public function test_mark_paid_survives_leftover_processed_at(): void
    {
        $admin = $this->admin();
        $publisher = $this->makeUser('publisher');
        $this->publisherWallet($publisher, 0);
        $withdrawal = $this->pendingWithdrawal($publisher);
        DB::table('withdrawals')->where('id', $withdrawal->id)->update([
            'processed_at' => 'not-a-date',
            'cancelled_at' => 'also-not-a-date',
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.withdrawals.paid', $withdrawal->id))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('completed', $withdrawal->fresh()->status);
    }

    public function test_withdrawals_data_survives_leftover_created_at(): void
    {
        $admin = $this->admin();
        $publisher = $this->makeUser('publisher');
        $withdrawal = $this->pendingWithdrawal($publisher);
        DB::table('withdrawals')->where('id', $withdrawal->id)->update([
            'created_at' => 'not-a-date',
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.withdrawals.data'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.id', $withdrawal->id);

        $this->actingAs($admin)
            ->getJson(route('admin.withdrawals.show', $withdrawal->id))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAs($admin)
            ->get(route('admin.withdrawals.export'))
            ->assertOk();
    }

    public function test_withdrawals_mark_paid_without_processed_at_column(): void
    {
        $admin = $this->admin();
        $publisher = $this->makeUser('publisher');
        $this->publisherWallet($publisher);
        $withdrawal = $this->pendingWithdrawal($publisher, ['amount' => 60, 'net_amount' => 60]);

        $this->dropColumnOrSkip('withdrawals', 'processed_at');

        try {
            $this->actingAs($admin)
                ->getJson(route('admin.withdrawals.data'))
                ->assertOk()
                ->assertJsonPath('success', true);

            $this->actingAs($admin)
                ->getJson(route('admin.withdrawals.statistics'))
                ->assertOk()
                ->assertJsonPath('success', true);

            $this->actingAs($admin)
                ->postJson(route('admin.withdrawals.paid', $withdrawal->id), [
                    'notes' => 'Sent',
                ])
                ->assertOk()
                ->assertJsonPath('success', true);

            $this->assertSame('completed', $withdrawal->fresh()->status);
        } finally {
            $this->restoreColumn('withdrawals', 'processed_at');
        }
    }

    public function test_withdrawals_reject_without_cancelled_columns(): void
    {
        $admin = $this->admin();
        $publisher = $this->makeUser('publisher');
        $wallet = $this->publisherWallet($publisher, 0);
        $withdrawal = $this->pendingWithdrawal($publisher, [
            'amount' => 35,
            'net_amount' => 35,
            ...Withdrawal::walletIdAttributes($wallet),
        ]);

        $this->dropColumnOrSkip('withdrawals', 'cancelled_at');
        if (Schema::hasColumn('withdrawals', 'cancelled_by')) {
            try {
                Schema::table('withdrawals', function (Blueprint $table) {
                    $table->dropColumn('cancelled_by');
                });
            } catch (\Throwable) {
                // Keep going — reject must still work without cancelled_at alone.
            }
        }

        try {
            $this->actingAs($admin)
                ->postJson(route('admin.withdrawals.reject', $withdrawal->id), [
                    'notes' => 'Bad IBAN details',
                ])
                ->assertOk()
                ->assertJsonPath('success', true);

            $this->assertSame('cancelled', $withdrawal->fresh()->status);
            $this->assertEqualsWithDelta(35.0, (float) $wallet->fresh()->balance, 0.01);
        } finally {
            $this->restoreColumn('withdrawals', 'cancelled_at');
            $this->restoreColumn('withdrawals', 'cancelled_by', 'string');
        }
    }

    public function test_withdrawal_confirm_survives_missing_wallets_and_processed_at(): void
    {
        $admin = $this->admin();
        $publisher = $this->makeUser('publisher');
        $this->publisherWallet($publisher, 0);
        $withdrawal = $this->pendingWithdrawal($publisher);
        $url = $this->relativeSignedUrl(ManualWithdrawalMarkPaidLink::url($withdrawal));

        $this->dropColumnOrSkip('withdrawals', 'processed_at');

        try {
            Schema::dropIfExists('wallets');
        } catch (\Throwable) {
            $this->markTestSkipped('Could not drop wallets on this driver');
        }
        if (Schema::hasTable('wallets')) {
            $this->markTestSkipped('wallets is still present after drop');
        }

        try {
            $this->actingAs($admin)
                ->get($url)
                ->assertOk()
                ->assertDontSee('Something went wrong')
                ->assertSee('Confirm marked paid', false);
        } finally {
            $this->restoreColumn('withdrawals', 'processed_at');
            if (! Schema::hasTable('wallets')) {
                $this->restoreWalletsTable();
            }
        }
    }

    public function test_withdrawal_reject_is_422_when_wallets_table_is_gone(): void
    {
        $admin = $this->admin();
        $publisher = $this->makeUser('publisher');
        $wallet = $this->publisherWallet($publisher, 0);
        $withdrawal = $this->pendingWithdrawal($publisher, Withdrawal::walletIdAttributes($wallet));

        try {
            Schema::dropIfExists('wallets');
        } catch (\Throwable) {
            $this->markTestSkipped('Could not drop wallets on this driver');
        }
        if (Schema::hasTable('wallets')) {
            $this->markTestSkipped('wallets is still present after drop');
        }

        try {
            $this->actingAs($admin)
                ->postJson(route('admin.withdrawals.reject', $withdrawal->id), [
                    'notes' => 'Cannot refund',
                ])
                ->assertStatus(422)
                ->assertJsonPath('success', false);

            $this->assertSame('pending', $withdrawal->fresh()->status);
        } finally {
            $this->restoreWalletsTable();
        }
    }

    public function test_withdrawal_processing_and_batch_still_work(): void
    {
        $admin = $this->admin();
        $publisher = $this->makeUser('publisher');
        $this->publisherWallet($publisher);
        $first = $this->pendingWithdrawal($publisher, ['amount' => 20, 'net_amount' => 20]);
        $second = $this->pendingWithdrawal($publisher, ['amount' => 15, 'net_amount' => 15]);

        $this->actingAs($admin)
            ->postJson(route('admin.withdrawals.processing', $first->id))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('processing', $first->fresh()->status);

        $this->actingAs($admin)
            ->postJson(route('admin.withdrawals.batch'), [
                'ids' => [$second->id],
                'action' => 'processing',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('processing', $second->fresh()->status);
    }

    public function test_confirm_posts_work_without_timestamp_columns(): void
    {
        $admin = $this->admin();
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $this->advertiserWallet($advertiser, 0);
        $this->publisherWallet($publisher, 0);
        $deposit = $this->pendingDeposit($advertiser, ['amount' => 22]);
        $withdrawal = $this->pendingWithdrawal($publisher, ['amount' => 18, 'net_amount' => 18]);

        $this->dropColumnOrSkip('deposit_requests', 'approved_at');
        $this->dropColumnOrSkip('withdrawals', 'processed_at');

        try {
            $depositUrl = $this->relativeSignedUrl(ManualDepositApproveLink::url($deposit));
            $this->actingAs($admin)
                ->post($depositUrl)
                ->assertRedirect(route('admin.deposits'));
            $this->assertSame('completed', $deposit->fresh()->status);

            $withdrawalUrl = $this->relativeSignedUrl(ManualWithdrawalMarkPaidLink::url($withdrawal));
            $this->actingAs($admin)
                ->post($withdrawalUrl)
                ->assertRedirect(route('admin.withdrawals'));
            $this->assertSame('completed', $withdrawal->fresh()->status);
        } finally {
            $this->restoreColumn('deposit_requests', 'approved_at');
            $this->restoreColumn('withdrawals', 'processed_at');
        }
    }

    public function test_approve_and_mark_paid_survive_missing_invoices_table(): void
    {
        $admin = $this->admin();
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $wallet = $this->advertiserWallet($advertiser, 0);
        $this->publisherWallet($publisher, 0);
        $deposit = $this->pendingDeposit($advertiser, ['amount' => 28]);
        $withdrawal = $this->pendingWithdrawal($publisher, ['amount' => 16, 'net_amount' => 16]);

        Schema::dropIfExists('billing_events');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('invoice_sequences');
        $this->assertFalse(Schema::hasTable('invoices'));

        try {
            $this->actingAs($admin)
                ->postJson(route('admin.deposits.approve', $deposit->id))
                ->assertOk()
                ->assertJsonPath('success', true);
            $this->assertSame('completed', $deposit->fresh()->status);
            $this->assertEqualsWithDelta(28.0, (float) $wallet->fresh()->balance, 0.01);

            $this->actingAs($admin)
                ->postJson(route('admin.withdrawals.paid', $withdrawal->id))
                ->assertOk()
                ->assertJsonPath('success', true);
            $this->assertSame('completed', $withdrawal->fresh()->status);
        } finally {
            $this->artisan('migrate', [
                '--path' => 'database/migrations/2026_07_17_100000_create_billing_invoices_tables.php',
                '--force' => true,
            ]);
            $this->artisan('migrate', [
                '--path' => 'database/migrations/2026_08_02_110000_add_series_to_invoice_sequences_table.php',
                '--force' => true,
            ]);
        }
    }

    public function test_withdrawals_data_survives_leftover_payment_details(): void
    {
        $admin = $this->admin();
        $publisher = $this->makeUser('publisher');
        $withdrawal = $this->pendingWithdrawal($publisher);
        DB::table('withdrawals')->where('id', $withdrawal->id)->update([
            'payment_details' => 'not-json',
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.withdrawals.data'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.id', $withdrawal->id);

        $this->actingAs($admin)
            ->getJson(route('admin.withdrawals.show', $withdrawal->id))
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_array_withdrawal_notes_do_not_mutate(): void
    {
        $admin = $this->admin();
        $publisher = $this->makeUser('publisher');
        $this->publisherWallet($publisher);
        $withdrawal = $this->pendingWithdrawal($publisher);

        $this->actingAs($admin)
            ->postJson(route('admin.withdrawals.paid', $withdrawal->id), [
                'notes' => ['injected'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('notes');

        $this->assertSame('pending', $withdrawal->fresh()->status);
    }

    public function test_queue_badges_and_finance_survive_missing_money_tables(): void
    {
        $admin = $this->admin();

        Schema::dropIfExists('deposit_requests');
        Schema::dropIfExists('withdrawals');
        $this->assertFalse(Schema::hasTable('deposit_requests'));
        $this->assertFalse(Schema::hasTable('withdrawals'));

        try {
            $this->actingAs($admin)
                ->getJson(route('admin.dashboard.queue-counts'))
                ->assertOk()
                ->assertJsonPath('success', true)
                ->assertJsonPath('pending_deposits', 0)
                ->assertJsonPath('pending_withdrawals', 0);

            $this->actingAs($admin)
                ->getJson(route('admin.dashboard.action-queue'))
                ->assertOk()
                ->assertJsonPath('success', true);

            $this->actingAs($admin)
                ->get(route('admin.finance'))
                ->assertOk()
                ->assertDontSee('Something went wrong');
        } finally {
            $this->restoreDepositRequestsTable();
            $this->restoreWithdrawalsTable();
        }
    }

    public function test_pages_use_named_action_routes(): void
    {
        $admin = $this->admin();

        $deposits = $this->actingAs($admin)
            ->get(route('admin.deposits'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('formatDateTime', $deposits);

        $html = $this->actingAs($admin)
            ->get(route('admin.withdrawals'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('withdrawalsDataUrl', $html);
        $this->assertStringContainsString('withdrawalsStatisticsUrl', $html);
        $this->assertStringContainsString('withdrawalsExportUrl', $html);
        $this->assertStringContainsString('withdrawalsBatchUrl', $html);
        $this->assertStringContainsString('withdrawalActionUrl', $html);
        $this->assertStringNotContainsString("url: '/admin/withdrawals/data'", $html);
        $this->assertStringNotContainsString("$.getJSON('/admin/withdrawals/statistics'", $html);
        $this->assertStringNotContainsString('`/admin/withdrawals/${id}/paid`', $html);
    }
}
