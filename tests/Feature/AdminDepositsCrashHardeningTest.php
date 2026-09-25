<?php

namespace Tests\Feature;

use App\Models\DepositRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Services\InAppNotificationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminDepositsCrashHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function makeUser(string $roleName): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function depositFor(User $advertiser, array $overrides = []): DepositRequest
    {
        return DepositRequest::create(array_merge([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-'.uniqid(),
            'amount' => 40,
            'payment_method' => 'bank',
            'status' => 'pending',
        ], $overrides));
    }

    public function test_index_view_survives_a_missing_user(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $deposit = $this->depositFor($advertiser);
        $deposit->setRelation('user', null);

        $this->actingAs($admin)->withViewErrors([]);

        $html = view('admin.deposits', [
            'deposits' => new LengthAwarePaginator(collect([$deposit]), 1, 20),
            'stats' => [
                'pending' => 1,
                'user_reported_paid' => 0,
                'approved' => 0,
                'completed' => 0,
                'rejected' => 0,
                'total_amount' => 0,
            ],
            'invoiceLinks' => collect(),
        ])->render();

        $this->assertStringContainsString('Unknown', $html);
        $this->assertStringContainsString($deposit->reference_code, $html);
        $this->assertStringContainsString('deposit.user || {}', $html);
        $this->assertStringContainsString('admin-deposits-filters', $html);
        $this->assertStringContainsString('admin-deposits-filters__actions', $html);
    }

    public function test_index_view_survives_a_null_created_at(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $deposit = $this->depositFor($advertiser);
        $deposit->created_at = null;

        $this->actingAs($admin)->withViewErrors([]);

        $html = view('admin.deposits', [
            'deposits' => new LengthAwarePaginator(collect([$deposit]), 1, 20),
            'stats' => [
                'pending' => 1,
                'user_reported_paid' => 0,
                'approved' => 0,
                'completed' => 0,
                'rejected' => 0,
                'total_amount' => 0,
            ],
            'invoiceLinks' => collect(),
        ])->render();

        $this->assertStringContainsString($deposit->reference_code, $html);
        $this->assertStringContainsString('—', $html);
    }

    public function test_deposits_filter_css_aligns_actions_with_inputs(): void
    {
        $css = file_get_contents(public_path('assets/css/admin-components.css'));
        $this->assertStringContainsString('.admin-deposits-filters .slb-search-status:empty', $css);
        $this->assertStringContainsString('.admin-deposits-filters__actions .btn', $css);

        $live = file_get_contents(public_path('assets/css/slb-live-search.css'));
        $this->assertStringContainsString('.slb-search-status:empty', $live);
    }

    public function test_array_status_filter_does_not_500(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $this->depositFor($advertiser, ['reference_code' => 'DEP-ARRAY-STATUS']);

        $this->actingAs($admin)
            ->get(route('admin.deposits', ['status' => ['pending', 'approved']]))
            ->assertOk()
            ->assertSee('DEP-ARRAY-STATUS');
    }

    public function test_array_admin_notes_do_not_approve_or_reject(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $approve = $this->depositFor($advertiser, ['reference_code' => 'DEP-NOTE-A']);
        $reject = $this->depositFor($advertiser, ['reference_code' => 'DEP-NOTE-R']);

        $this->actingAs($admin)
            ->postJson(route('admin.deposits.approve', $approve->id), [
                'admin_notes' => ['injected'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('admin_notes');

        $this->actingAs($admin)
            ->postJson(route('admin.deposits.reject', $reject->id), [
                'admin_notes' => ['injected'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('admin_notes');

        $this->assertSame('pending', $approve->fresh()->status);
        $this->assertSame('pending', $reject->fresh()->status);
    }

    public function test_oversized_admin_notes_are_rejected(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $deposit = $this->depositFor($advertiser);

        $this->actingAs($admin)
            ->postJson(route('admin.deposits.reject', $deposit->id), [
                'admin_notes' => str_repeat('x', 1001),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('admin_notes');

        $this->assertSame('pending', $deposit->fresh()->status);
    }

    public function test_index_uses_named_deposit_action_routes(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $deposit = $this->depositFor($advertiser);

        $html = $this->actingAs($admin)
            ->get(route('admin.deposits'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(route('admin.deposits.show', $deposit->id), $html);
        $this->assertStringContainsString('data-show-url', $html);
        $this->assertStringContainsString('readJsonResponse', $html);
        $this->assertStringContainsString('paypalRefundUrlTemplate', $html);
        $this->assertStringContainsString('/paypal-refund', $html);
        $this->assertStringNotContainsString("fetch('/admin/deposits/'", $html);
        $this->assertStringNotContainsString('fetch(`/admin/deposits/${id}/approve`', $html);
        $this->assertStringNotContainsString('fetch(`/admin/deposits/${id}/reject`', $html);
        $this->assertStringNotContainsString('fetch(`/admin/deposits/${id}/paypal-refund`', $html);
    }

    public function test_approve_still_credits_when_activity_log_table_is_gone(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $deposit = $this->depositFor($advertiser, ['amount' => 40]);
        Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 0,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        Schema::dropIfExists('activity_logs');

        $this->actingAs($admin)
            ->postJson(route('admin.deposits.approve', $deposit->id))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('completed', $deposit->fresh()->status);
        $this->assertEqualsWithDelta(40.0, (float) Wallet::query()
            ->where('user_id', $advertiser->id)
            ->value('balance'), 0.01);
    }

    public function test_reject_still_succeeds_when_notification_throws(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $deposit = $this->depositFor($advertiser);

        $this->mock(InAppNotificationService::class, function ($mock) {
            $mock->shouldReceive('notifyDepositRejected')->andThrow(new \RuntimeException('bell down'));
        });

        $this->actingAs($admin)
            ->postJson(route('admin.deposits.reject', $deposit->id), [
                'admin_notes' => 'Notification failed but the rejection stands.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('rejected', $deposit->fresh()->status);
    }

    public function test_index_survives_missing_user_marked_paid_at_column(): void
    {
        if (! Schema::hasColumn('deposit_requests', 'user_marked_paid_at')) {
            $this->markTestSkipped('deposit_requests.user_marked_paid_at is already absent');
        }

        try {
            Schema::table('deposit_requests', function (Blueprint $table) {
                $table->dropColumn('user_marked_paid_at');
            });
        } catch (\Throwable) {
            $this->markTestSkipped('Could not drop user_marked_paid_at on this driver');
        }

        if (Schema::hasColumn('deposit_requests', 'user_marked_paid_at')) {
            $this->markTestSkipped('user_marked_paid_at is still present after drop');
        }

        try {
            $admin = $this->makeUser('admin');
            $advertiser = $this->makeUser('advertiser');
            $this->depositFor($advertiser);

            $this->actingAs($admin)
                ->get(route('admin.deposits'))
                ->assertOk()
                ->assertDontSee('Something went wrong');
        } finally {
            if (! Schema::hasColumn('deposit_requests', 'user_marked_paid_at')) {
                Schema::table('deposit_requests', function (Blueprint $table) {
                    $table->timestamp('user_marked_paid_at')->nullable();
                });
            }
        }
    }
}
