<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Role;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminWithdrawalLaterTest extends TestCase
{
    use RefreshDatabase;

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

    private function seedWithdrawal(User $publisher, array $overrides = []): Withdrawal
    {
        return Withdrawal::create(array_merge([
            'user_id' => $publisher->id,
            'amount' => 100,
            'fee' => 5,
            'net_amount' => 95,
            'payment_method' => 'paypal',
            'payment_details' => ['email' => 'pub@example.com'],
            'status' => 'pending',
        ], $overrides));
    }

    public function test_queue_page_includes_later_ops_hooks(): void
    {
        $admin = $this->makeUser('admin');

        $html = $this->actingAs($admin)
            ->get(route('admin.withdrawals'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('function confirmPendingPayIfNeeded', $html);
        $this->assertStringContainsString('You have not marked this processing. Pay anyway?', $html);
        $this->assertStringContainsString('function isHistoryExport', $html);
        $this->assertStringContainsString('Export history CSV', $html);
        $this->assertStringContainsString('Select all matching', $html);
        $this->assertStringContainsString('const WD_IDS', $html);
        $this->assertStringContainsString('statsScope', $html);
        $this->assertStringContainsString('This view', $html);
        $this->assertStringContainsString('Open page', $html);
        $this->assertStringContainsString('data-status=', $html);
    }

    public function test_browser_show_is_html_and_json_accept_stays_json(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $publisher->forceFill(['name' => 'Pat Publisher', 'email' => 'pat-show@example.com'])->save();
        $withdrawal = $this->seedWithdrawal($publisher, [
            'admin_notes' => 'Wire ref 44',
            'payment_method' => 'wise',
            'payment_details' => ['email' => 'pay@example.com'],
        ]);

        $html = $this->actingAs($admin)
            ->get(route('admin.withdrawals.show', $withdrawal->id))
            ->assertOk()
            ->assertSee('WD-'.$withdrawal->id, false)
            ->assertSee('Pat Publisher', false)
            ->assertSee('pat-show@example.com', false)
            ->assertSee('Wire ref 44', false)
            ->assertSee('Back to payout queue', false)
            ->assertSee(route('admin.finance.user', $publisher->id), false)
            ->getContent();

        $this->assertStringNotContainsString('application/json', (string) $this->actingAs($admin)
            ->get(route('admin.withdrawals.show', $withdrawal->id))
            ->headers->get('content-type'));
        $this->assertStringNotContainsString('admin.withdrawals.paid', $html);
        $this->assertStringNotContainsString('name="_token"', $html);
        $this->assertStringNotContainsString('Mark paid', $html);

        $this->actingAs($admin)
            ->getJson(route('admin.withdrawals.show', $withdrawal->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $withdrawal->id)
            ->assertJsonPath('data.user.email', 'pat-show@example.com');
    }

    public function test_html_show_404_and_json_show_404(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->get(route('admin.withdrawals.show', 999999))
            ->assertNotFound();

        $this->actingAs($admin)
            ->getJson(route('admin.withdrawals.show', 999999))
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_statistics_scope_view_follows_filters(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');

        $this->seedWithdrawal($publisher, ['payment_method' => 'paypal', 'net_amount' => 40]);
        $this->seedWithdrawal($publisher, [
            'payment_method' => 'bank',
            'payment_details' => [
                'bank_name' => 'Test Bank',
                'account_holder' => 'Pat',
                'account_number' => 'DE89370400440532013000',
            ],
            'net_amount' => 80,
        ]);
        $this->seedWithdrawal($publisher, [
            'status' => 'completed',
            'processed_at' => now(),
            'payment_method' => 'paypal',
            'net_amount' => 10,
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.withdrawals.statistics'))
            ->assertOk()
            ->assertJsonPath('data.scope', 'all')
            ->assertJsonPath('data.pending', 2)
            ->assertJsonPath('data.total_to_pay', 120);

        $this->actingAs($admin)
            ->getJson(route('admin.withdrawals.statistics', [
                'scope' => 'view',
                'payment_method' => 'paypal',
            ]))
            ->assertOk()
            ->assertJsonPath('data.scope', 'view')
            ->assertJsonPath('data.pending', 1)
            ->assertJsonPath('data.total_to_pay', 40);
    }

    public function test_matching_ids_are_actionable_capped_and_ignore_ids_param(): void
    {
        config(['billing.withdrawal_select_matching_limit' => 2]);

        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');

        $first = $this->seedWithdrawal($publisher, ['created_at' => now()->subDays(3)]);
        $second = $this->seedWithdrawal($publisher, [
            'status' => 'processing',
            'created_at' => now()->subDays(2),
        ]);
        $third = $this->seedWithdrawal($publisher, ['created_at' => now()->subDay()]);
        $paid = $this->seedWithdrawal($publisher, [
            'status' => 'completed',
            'processed_at' => now(),
            'net_amount' => 10,
        ]);

        $response = $this->actingAs($admin)
            ->getJson(route('admin.withdrawals.ids', [
                'ids' => [$paid->id],
            ]))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('capped', true)
            ->assertJsonPath('limit', 2)
            ->assertJsonPath('total', 3);

        $ids = $response->json('ids');
        $this->assertSame([$first->id, $third->id], $ids);
        $this->assertNotContains($paid->id, $ids);
        $this->assertNotContains($second->id, $ids);
        $this->assertSame([$first->id, $third->id], $response->json('pending_ids'));
    }

    public function test_export_over_row_cap_redirects_or_returns_422(): void
    {
        config(['billing.withdrawal_export_max_rows' => 2]);

        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $this->seedWithdrawal($publisher);
        $this->seedWithdrawal($publisher, ['net_amount' => 40]);
        $this->seedWithdrawal($publisher, ['net_amount' => 20]);

        $this->actingAs($admin)
            ->get(route('admin.withdrawals.export'))
            ->assertRedirect(route('admin.withdrawals'))
            ->assertSessionHas('error');

        $this->actingAs($admin)
            ->getJson(route('admin.withdrawals.export'))
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('limit', 2)
            ->assertJsonPath('total', 3);
    }

    public function test_export_route_is_rate_limited(): void
    {
        $route = Route::getRoutes()->getByName('admin.withdrawals.export');
        $this->assertNotNull($route);
        $this->assertTrue(collect($route->gatherMiddleware())->contains(
            fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'throttle:20,1')
        ));
    }

    public function test_payout_invoice_related_url_is_html_show(): void
    {
        $publisher = $this->makeUser('publisher');
        $withdrawal = $this->seedWithdrawal($publisher, [
            'status' => 'completed',
            'processed_at' => now(),
        ]);
        $statement = Invoice::create([
            'user_id' => $publisher->id,
            'customer_name' => $publisher->name,
            'customer_email' => $publisher->email,
            'invoice_number' => 'PAY-LATER-1',
            'type' => Invoice::TYPE_WITHDRAWAL_PAYOUT,
            'status' => Invoice::STATUS_PAID,
            'subtotal' => 95,
            'total_amount' => 95,
            'invoice_date' => now(),
            'line_items' => [['description' => 'Payout', 'line_total' => 95]],
            'pdf_disk' => 'local',
            'reference_code' => 'WD-'.$withdrawal->id,
            'meta' => ['withdrawal_id' => $withdrawal->id],
        ]);

        $this->assertSame(
            route('admin.withdrawals.show', $withdrawal->id),
            $statement->relatedAdminUrl()
        );
    }
}
