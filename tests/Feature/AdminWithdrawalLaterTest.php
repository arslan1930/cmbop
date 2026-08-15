<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Role;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
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
        $this->assertStringContainsString('function reloadFilteredView', $html);
        $this->assertStringContainsString('function snapshotFilters', $html);
        $this->assertStringContainsString('function viewFilterParams', $html);
        $this->assertStringContainsString('selectedIds.clear();', $html);
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

        $this->assertStringContainsString('text/html', (string) $this->actingAs($admin)
            ->get(route('admin.withdrawals.show', $withdrawal->id))
            ->headers->get('content-type'));
        $this->assertStringNotContainsString(route('admin.withdrawals.paid', $withdrawal->id), $html);
        $this->assertStringNotContainsString(route('admin.withdrawals.reject', $withdrawal->id), $html);
        $this->assertStringNotContainsString('Yes, mark paid', $html);

        $this->actingAs($admin)
            ->getJson(route('admin.withdrawals.show', $withdrawal->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $withdrawal->id)
            ->assertJsonPath('data.user.email', 'pat-show@example.com');

        $this->actingAs($admin)
            ->get(route('admin.withdrawals.show', $withdrawal->id), [
                'X-Requested-With' => 'XMLHttpRequest',
                'Accept' => 'text/html,application/xhtml+xml',
            ])
            ->assertOk()
            ->assertSee('Back to payout queue', false)
            ->assertDontSee('"success":true', false);
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

        $this->actingAs($admin)
            ->getJson(route('admin.withdrawals.statistics', [
                'scope' => 'view',
                'status' => 'completed',
            ]))
            ->assertOk()
            ->assertJsonPath('data.scope', 'view')
            ->assertJsonPath('data.pending', 0)
            ->assertJsonPath('data.processing', 0)
            ->assertJsonPath('data.total_to_pay', 0);
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

        $this->actingAs($admin)
            ->getJson(route('admin.withdrawals.ids', ['queue' => 'history']))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('total', 0)
            ->assertJsonPath('ids', []);
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

        $this->seedWithdrawal($publisher, [
            'status' => 'completed',
            'processed_at' => now(),
            'net_amount' => 11,
        ]);
        $this->seedWithdrawal($publisher, [
            'status' => 'completed',
            'processed_at' => now(),
            'net_amount' => 12,
        ]);
        $this->seedWithdrawal($publisher, [
            'status' => 'cancelled',
            'net_amount' => 13,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.withdrawals.export', ['queue' => 'history']))
            ->assertRedirect(route('admin.withdrawals', ['queue' => 'history']))
            ->assertSessionHas('error');

        $this->actingAs($admin)
            ->get(route('admin.withdrawals.export'), [
                'X-Requested-With' => 'XMLHttpRequest',
                'Accept' => 'text/html,application/xhtml+xml',
            ])
            ->assertRedirect(route('admin.withdrawals'))
            ->assertSessionHas('error');
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

        $statement->order_id = 999;

        $this->assertSame(
            route('admin.withdrawals.show', $withdrawal->id),
            $statement->relatedAdminUrl()
        );
    }

    public function test_ids_route_is_not_shadowed_by_show(): void
    {
        $matched = app('router')->getRoutes()->match(
            Request::create('/admin/withdrawals/ids', 'GET')
        );
        $this->assertSame('admin.withdrawals.ids', $matched->getName());
    }

    public function test_later_endpoints_ignore_array_filters_and_invalid_per_page(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $open = $this->seedWithdrawal($publisher);
        $this->seedWithdrawal($publisher, [
            'status' => 'completed',
            'processed_at' => now(),
            'net_amount' => 10,
        ]);

        $junk = [
            'search' => ['injected'],
            'queue' => ['history'],
            'status' => ['completed'],
            'payment_method' => ['bank'],
            'date_from' => ['not-a-date'],
            'date_to' => ['2026-01-01'],
            'scope' => ['view'],
            'ids' => ['x'],
            'per_page' => ['20'],
        ];

        $this->actingAs($admin)
            ->getJson(route('admin.withdrawals.data', $junk))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.id', $open->id);

        $this->actingAs($admin)
            ->getJson(route('admin.withdrawals.statistics', $junk))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.scope', 'all')
            ->assertJsonPath('data.pending', 1);

        $this->actingAs($admin)
            ->getJson(route('admin.withdrawals.ids', $junk))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('ids', [$open->id]);

        $csv = $this->actingAs($admin)
            ->get(route('admin.withdrawals.export', $junk))
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString('WD-'.$open->id, $csv);
    }

    public function test_guest_and_advertiser_cannot_open_show_or_matching_ids(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $withdrawal = $this->seedWithdrawal($publisher);

        $this->get(route('admin.withdrawals.show', $withdrawal->id))
            ->assertRedirect();
        $this->getJson(route('admin.withdrawals.ids'))
            ->assertUnauthorized();

        $this->actingAs($advertiser)
            ->get(route('admin.withdrawals.show', $withdrawal->id))
            ->assertForbidden();
        $this->actingAs($advertiser)
            ->getJson(route('admin.withdrawals.ids'))
            ->assertForbidden();
    }
}
