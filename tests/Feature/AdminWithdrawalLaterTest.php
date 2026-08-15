<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Admin\FinanceOverviewService;
use App\Services\Billing\AdminInvoiceLinks;
use App\Services\Wallet\ManualWithdrawalSettlementService;
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
        $this->assertStringContainsString('escapeHtml(label)', $html);
        $this->assertStringContainsString('if (!Object.keys(appliedFilters).length)', $html);
        $this->assertStringContainsString('escapeHtml(adminStatusLabel(w.status))', $html);
        $this->assertStringContainsString("pendingSet.has(n) ? 'pending' : 'processing'", $html);
        $this->assertStringContainsString('appliedFilters.page = currentPage', $html);
        $this->assertStringContainsString('notes: notes', $html);
        $this->assertStringContainsString('hasOwnProperty.call(options, \'notes\')', $html);
        $this->assertStringContainsString('if (!dateString) return', $html);
        $this->assertStringContainsString('encodeURIComponent(w.destination_copy_text || \'\')', $html);
        $this->assertStringContainsString("if (!row || typeof row !== 'object') return '';", $html);
        $this->assertStringContainsString('function detailText', $html);
        $this->assertStringContainsString('function getJson', $html);
        $this->assertStringContainsString('cache: false', $html);
        $this->assertStringContainsString('Array.isArray(response.data) ? response.data : []', $html);
        $this->assertStringContainsString('const SELECTION_LIMIT = 100', $html);
        $this->assertStringContainsString('function addSelectedId', $html);
        $this->assertStringContainsString('function clearStatsDisplay', $html);
        $this->assertStringContainsString('function safeAdminHref', $html);
        $this->assertStringContainsString('function isAdminPath', $html);
        $this->assertStringContainsString('function formatMoney', $html);
        $this->assertStringContainsString('Selection is limited to', $html);
        $this->assertStringContainsString('requestId !== statsRequestId', $html);
        $this->assertStringContainsString('requestId !== withdrawalsRequestId', $html);
        $this->assertStringContainsString('requestId !== detailsRequestId', $html);
        $this->assertStringContainsString('requestId !== matchingRequestId', $html);
        $this->assertStringContainsString('const selectedCount = selectedIds.size', $html);
        $this->assertStringContainsString(json_encode(route('admin.withdrawals.data', [], false)), $html);
        $this->assertStringContainsString('existing.possible_duplicate = dupSet.has(n)', $html);
        $this->assertStringContainsString('Apply to <strong>${ids.length}</strong>', $html);
        $this->assertStringContainsString('Array.isArray(body.duplicate_ids)', $html);
        $this->assertStringContainsString('function uniqueWdRefs', $html);
        $this->assertStringContainsString('function paidMatchIdsFromMap', $html);
        $this->assertStringContainsString('flag.duplicate_match_ids', $html);
        $this->assertStringContainsString('res.duplicate_match_ids', $html);
        $this->assertStringContainsString('href="'.route('admin.finance', [], false).'"', $html);
        $this->assertStringContainsString('Array.isArray(options.ids) ? options.ids', $html);
        $this->assertStringContainsString('ids: ids', $html);
        $this->assertStringContainsString('!Array.isArray(withdrawal.payment_details)', $html);
        $this->assertStringContainsString("failedCount > 0 ? 'warning' : 'success'", $html);
        $this->assertStringContainsString('failed.forEach(function (row)', $html);
        $this->assertStringContainsString('addSelectedId(row && row.id)', $html);
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
            ->assertSee(route('admin.finance.user', $publisher->id, false), false)
            ->getContent();

        $this->assertStringContainsString(
            'href="'.route('admin.finance.user', $publisher->id, false).'"',
            $html
        );

        $htmlShow = $this->actingAs($admin)
            ->get(route('admin.withdrawals.show', $withdrawal->id));
        $this->assertStringContainsString('text/html', (string) $htmlShow->headers->get('content-type'));
        $this->assertStringContainsString('no-store', (string) $htmlShow->headers->get('Cache-Control'));
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

    public function test_html_show_invoice_href_is_admin_path(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $withdrawal = $this->seedWithdrawal($publisher, [
            'status' => 'completed',
            'processed_at' => now(),
        ]);
        $statement = Invoice::create([
            'user_id' => $publisher->id,
            'customer_name' => $publisher->name,
            'customer_email' => $publisher->email,
            'invoice_number' => 'PAY-SHOW-SAFE-1',
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

        $path = parse_url(route('admin.invoices.show', $statement), PHP_URL_PATH);
        $this->assertIsString($path);

        $html = $this->actingAs($admin)
            ->get(route('admin.withdrawals.show', $withdrawal->id))
            ->assertOk()
            ->assertSee('Open invoice', false)
            ->getContent();

        $this->assertStringContainsString('href="'.$path.'"', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function test_html_show_drops_non_admin_invoice_url(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $withdrawal = $this->seedWithdrawal($publisher, [
            'status' => 'completed',
            'processed_at' => now(),
        ]);

        $this->mock(AdminInvoiceLinks::class, function ($mock) use ($withdrawal) {
            $mock->shouldReceive('forWithdrawals')->andReturn(collect([
                (int) $withdrawal->id => [
                    'id' => 1,
                    'invoice_number' => 'PAY-BAD',
                    'type' => Invoice::TYPE_WITHDRAWAL_PAYOUT,
                    'type_label' => 'Payout Statement',
                    'status' => Invoice::STATUS_PAID,
                    'url' => 'javascript:alert(1)',
                ],
            ]));
        });

        $html = $this->actingAs($admin)
            ->get(route('admin.withdrawals.show', $withdrawal->id))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('Open invoice', $html);
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
        $this->assertSame([], $response->json('duplicate_ids'));

        $this->actingAs($admin)
            ->getJson(route('admin.withdrawals.ids', ['queue' => 'history']))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('total', 0)
            ->assertJsonPath('ids', []);
    }

    public function test_matching_ids_include_duplicate_flags(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $paid = $this->seedWithdrawal($publisher, [
            'status' => 'completed',
            'processed_at' => now()->subDay(),
            'net_amount' => 95,
        ]);
        $open = $this->seedWithdrawal($publisher, [
            'status' => 'pending',
            'net_amount' => 95,
        ]);
        $other = $this->seedWithdrawal($publisher, [
            'status' => 'processing',
            'net_amount' => 40,
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.withdrawals.ids'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('duplicate_ids', [$open->id])
            ->assertJsonPath('duplicate_match_ids.'.$open->id, [$paid->id]);

        $ids = $this->actingAs($admin)
            ->getJson(route('admin.withdrawals.ids'))
            ->json('ids');
        $this->assertContains($open->id, $ids);
        $this->assertContains($other->id, $ids);
    }

    public function test_export_over_row_cap_redirects_or_returns_422(): void
    {
        config(['billing.withdrawal_export_max_rows' => 2]);

        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $this->seedWithdrawal($publisher);
        $this->seedWithdrawal($publisher, ['net_amount' => 40]);
        $this->seedWithdrawal($publisher, ['net_amount' => 20]);

        $overCap = $this->actingAs($admin)
            ->get(route('admin.withdrawals.export'))
            ->assertRedirect(route('admin.withdrawals'))
            ->assertSessionHas('error');
        $this->assertStringContainsString(
            route('admin.withdrawals', [], false),
            (string) $overCap->headers->get('Location')
        );

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
            route('admin.withdrawals.show', $withdrawal->id, false),
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

    public function test_same_timestamp_rows_paginate_and_match_in_stable_id_order(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $when = now()->subHours(2);

        $first = $this->seedWithdrawal($publisher, ['net_amount' => 11]);
        $second = $this->seedWithdrawal($publisher, ['net_amount' => 22]);
        $third = $this->seedWithdrawal($publisher, ['net_amount' => 33]);

        foreach ([$first, $second, $third] as $row) {
            $row->forceFill(['created_at' => $when, 'updated_at' => $when])->save();
        }

        $page1 = $this->actingAs($admin)
            ->getJson(route('admin.withdrawals.data', ['per_page' => 2, 'page' => 1]))
            ->assertOk()
            ->json('data');
        $page2 = $this->actingAs($admin)
            ->getJson(route('admin.withdrawals.data', ['per_page' => 2, 'page' => 2]))
            ->assertOk()
            ->json('data');

        $page1Ids = collect($page1)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $page2Ids = collect($page2)->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertSame([$first->id, $second->id], $page1Ids);
        $this->assertSame([$third->id], $page2Ids);

        $this->actingAs($admin)
            ->getJson(route('admin.withdrawals.ids'))
            ->assertOk()
            ->assertJsonPath('ids', [$first->id, $second->id, $third->id]);
    }

    public function test_search_treats_like_wildcards_as_literals(): void
    {
        $admin = $this->makeUser('admin');
        $percent = $this->makeUser('publisher');
        $percent->forceFill(['name' => '100% Club', 'email' => 'user_name@example.com'])->save();
        $other = $this->makeUser('publisher');
        $other->forceFill(['name' => '100X Club', 'email' => 'userXname@example.com'])->save();

        $percentRow = $this->seedWithdrawal($percent);
        $this->seedWithdrawal($other);

        $this->actingAs($admin)
            ->getJson(route('admin.withdrawals.data', ['search' => '100%']))
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('data.0.id', $percentRow->id);

        $this->actingAs($admin)
            ->getJson(route('admin.withdrawals.data', ['search' => 'user_name']))
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('data.0.id', $percentRow->id);
    }

    public function test_finance_hub_open_row_links_to_html_show(): void
    {
        $publisher = $this->makeUser('publisher');
        $withdrawal = $this->seedWithdrawal($publisher);

        $urls = collect(app(FinanceOverviewService::class)->walletLiability()['open_withdrawal_rows'] ?? [])
            ->pluck('url');

        $this->assertTrue($urls->contains(route('admin.withdrawals.show', $withdrawal->id, false)));
    }

    public function test_data_clamps_page_past_the_last_page(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $this->seedWithdrawal($publisher, ['net_amount' => 11]);
        $last = $this->seedWithdrawal($publisher, ['net_amount' => 22]);

        $this->actingAs($admin)
            ->getJson(route('admin.withdrawals.data', [
                'per_page' => 1,
                'page' => 99,
            ]))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('pagination.current_page', 2)
            ->assertJsonPath('pagination.last_page', 2)
            ->assertJsonPath('pagination.total', 2)
            ->assertJsonPath('data.0.id', $last->id);
    }

    public function test_data_ignores_array_page_and_defaults_to_first(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $first = $this->seedWithdrawal($publisher, ['created_at' => now()->subDay()]);
        $this->seedWithdrawal($publisher);

        $this->actingAs($admin)
            ->getJson(route('admin.withdrawals.data', [
                'per_page' => 1,
                'page' => ['99'],
            ]))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('pagination.current_page', 1)
            ->assertJsonPath('data.0.id', $first->id);
    }

    public function test_export_ids_are_capped_to_export_max_rows(): void
    {
        config(['billing.withdrawal_export_max_rows' => 2]);

        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $one = $this->seedWithdrawal($publisher, ['net_amount' => 11]);
        $two = $this->seedWithdrawal($publisher, ['net_amount' => 22]);
        $three = $this->seedWithdrawal($publisher, ['net_amount' => 33]);

        $csv = $this->actingAs($admin)
            ->get(route('admin.withdrawals.export', [
                'ids' => [$one->id, $two->id, $three->id],
            ]))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('WD-'.$one->id, $csv);
        $this->assertStringContainsString('WD-'.$two->id, $csv);
        $this->assertStringNotContainsString('WD-'.$three->id, $csv);
    }

    public function test_mark_paid_type_error_returns_json_500(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $withdrawal = $this->seedWithdrawal($publisher);

        $this->mock(ManualWithdrawalSettlementService::class, function ($mock) {
            $mock->shouldReceive('transition')->andThrow(new \TypeError('simulated type error'));
        });

        $this->actingAs($admin)
            ->postJson(route('admin.withdrawals.paid', $withdrawal->id), ['notes' => 'x'])
            ->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Failed to update status. Please try again.');
    }

    public function test_json_show_returns_json_500_when_enrichment_throws(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $withdrawal = $this->seedWithdrawal($publisher);

        $this->mock(AdminInvoiceLinks::class, function ($mock) {
            $mock->shouldReceive('forWithdrawals')->andThrow(new \TypeError('simulated show error'));
        });

        $this->actingAs($admin)
            ->getJson(route('admin.withdrawals.show', $withdrawal->id))
            ->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Failed to load withdrawal.');
    }

    public function test_export_tolerates_nested_payment_detail_arrays(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $withdrawal = $this->seedWithdrawal($publisher, [
            'payment_method' => 'paypal',
            'payment_details' => ['email' => ['not-a-string']],
        ]);

        $csv = $this->actingAs($admin)
            ->get(route('admin.withdrawals.export'))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('WD-'.$withdrawal->id, $csv);
        $this->assertStringNotContainsString('Array', $csv);
    }

    public function test_html_show_list_and_export_tolerate_scalar_payment_details(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $withdrawal = $this->seedWithdrawal($publisher, [
            'payment_method' => 'paypal',
            'payment_details' => ['email' => 'pub@example.com'],
        ]);
        $withdrawal->forceFill(['payment_details' => 123])->save();

        $this->actingAs($admin)
            ->get(route('admin.withdrawals.show', $withdrawal->id))
            ->assertOk()
            ->assertSee('WD-'.$withdrawal->id, false)
            ->assertDontSee('TypeError', false);

        $this->actingAs($admin)
            ->getJson(route('admin.withdrawals.data'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.id', $withdrawal->id)
            ->assertJsonPath('data.0.destination_snippet', 'PayPal · —');

        $csv = $this->actingAs($admin)
            ->get(route('admin.withdrawals.export'))
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString('WD-'.$withdrawal->id, $csv);
        $this->assertStringNotContainsString('Array', $csv);
    }

    public function test_unchanged_mark_paid_retries_missing_payout_statement(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $withdrawal = $this->seedWithdrawal($publisher, [
            'status' => 'completed',
            'processed_at' => now(),
        ]);

        $this->assertNull(
            Invoice::query()
                ->where('type', Invoice::TYPE_WITHDRAWAL_PAYOUT)
                ->where('reference_code', 'WD-'.$withdrawal->id)
                ->where('status', '!=', Invoice::STATUS_CANCELLED)
                ->first()
        );

        $result = app(ManualWithdrawalSettlementService::class)->markPaid($withdrawal, $admin);
        $this->assertTrue($result['unchanged']);

        $statement = Invoice::query()
            ->where('type', Invoice::TYPE_WITHDRAWAL_PAYOUT)
            ->where('reference_code', 'WD-'.$withdrawal->id)
            ->where('status', '!=', Invoice::STATUS_CANCELLED)
            ->first();
        $this->assertNotNull($statement);

        $again = app(ManualWithdrawalSettlementService::class)->markPaid($withdrawal->fresh(), $admin);
        $this->assertTrue($again['unchanged']);
        $this->assertSame(1, Invoice::query()
            ->where('type', Invoice::TYPE_WITHDRAWAL_PAYOUT)
            ->where('reference_code', 'WD-'.$withdrawal->id)
            ->where('status', '!=', Invoice::STATUS_CANCELLED)
            ->count());
    }

    public function test_masked_payout_destination_ignores_nested_detail_arrays(): void
    {
        $this->assertNull(Invoice::maskedPayoutDestination([
            'account_number' => ['not-a-string'],
        ], 'bank'));
        $this->assertSame('···3000', Invoice::maskedPayoutDestination([
            'account_number' => 'DE89370400440532013000',
        ], 'bank'));

        $this->assertNull(Invoice::maskedPayoutDestination([
            'email' => ['not-a-string'],
        ], 'paypal'));
        $this->assertSame('p***@example.com', Invoice::maskedPayoutDestination([
            'email' => 'pub@example.com',
        ], 'paypal'));

        $this->assertSame('Crypto', Invoice::maskedPayoutDestination([
            'wallet_address' => ['not-a-string'],
            'crypto_type' => ['not-a-string'],
        ], 'crypto'));
        $this->assertSame('TRX · ···wxyz', Invoice::maskedPayoutDestination([
            'crypto_type' => 'TRX',
            'wallet_address' => 'TAbcdefghijklmnopqrstuvwxyz',
        ], 'crypto'));

        $publisher = $this->makeUser('publisher');
        $statement = Invoice::create([
            'user_id' => $publisher->id,
            'customer_name' => $publisher->name,
            'customer_email' => $publisher->email,
            'invoice_number' => 'PAY-NEST-MASK-1',
            'type' => Invoice::TYPE_WITHDRAWAL_PAYOUT,
            'status' => Invoice::STATUS_PAID,
            'payment_method' => 'bank',
            'subtotal' => 95,
            'total_amount' => 95,
            'invoice_date' => now(),
            'line_items' => [['description' => 'Payout', 'line_total' => 95]],
            'pdf_disk' => 'local',
            'reference_code' => 'WD-9001',
            'billing_snapshot' => [
                'payment_details' => [
                    'account_number' => ['not-a-string'],
                ],
            ],
            'meta' => ['withdrawal_id' => 9001],
        ]);

        $html = view('billing.pdf.invoice', [
            'invoice' => $statement,
            'company' => config('billing.company'),
            'colors' => config('billing.colors'),
            'currencySymbol' => '€',
        ])->render();

        $this->assertStringNotContainsString('Array', $html);
        $this->assertStringNotContainsString('···rray', $html);
    }

    public function test_mark_paid_issues_statement_when_payment_details_are_nested(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $publisher->forceFill(['name' => 'Pat Publisher'])->save();
        $withdrawal = $this->seedWithdrawal($publisher, [
            'payment_method' => 'bank',
            'payment_details' => [
                'bank_name' => 'Test Bank',
                'account_holder' => ['not-a-string'],
                'account_number' => 'DE89370400440532013000',
            ],
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.withdrawals.paid', $withdrawal->id), ['notes' => 'Paid'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('completed', $withdrawal->fresh()->status);
        $statement = Invoice::query()
            ->where('type', Invoice::TYPE_WITHDRAWAL_PAYOUT)
            ->where('reference_code', 'WD-'.$withdrawal->id)
            ->where('status', '!=', Invoice::STATUS_CANCELLED)
            ->first();
        $this->assertNotNull($statement);
        $this->assertSame('Pat Publisher', $statement->customer_name);
        $this->assertStringNotContainsString('Array', (string) $statement->customer_name);
    }

    public function test_html_show_and_list_tolerate_nested_payment_details(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $withdrawal = $this->seedWithdrawal($publisher, [
            'payment_method' => 'paypal',
            'payment_details' => ['email' => ['not-a-string']],
        ]);

        $this->actingAs($admin)
            ->get(route('admin.withdrawals.show', $withdrawal->id))
            ->assertOk()
            ->assertSee('WD-'.$withdrawal->id, false)
            ->assertSee('N/A', false)
            ->assertDontSee('Array', false);

        $this->actingAs($admin)
            ->getJson(route('admin.withdrawals.data'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.id', $withdrawal->id)
            ->assertJsonPath('data.0.destination_snippet', 'PayPal · —');
    }

    public function test_later_get_json_is_not_store_cached(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $withdrawal = $this->seedWithdrawal($publisher);

        foreach ([
            route('admin.withdrawals.data'),
            route('admin.withdrawals.statistics'),
            route('admin.withdrawals.ids'),
            route('admin.withdrawals.show', $withdrawal->id),
        ] as $url) {
            $cache = (string) $this->actingAs($admin)
                ->getJson($url)
                ->assertOk()
                ->headers
                ->get('Cache-Control');
            $this->assertStringContainsString('no-store', $cache, $url);
        }
    }

    public function test_statistics_tolerates_blank_payment_method(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $this->seedWithdrawal($publisher, ['payment_method' => '']);

        $this->actingAs($admin)
            ->getJson(route('admin.withdrawals.statistics'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.pending', 1)
            ->assertJsonPath('data.by_method.unknown.count', 1);
    }

    public function test_batch_rejects_more_than_one_hundred_ids(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->postJson(route('admin.withdrawals.batch'), [
                'ids' => range(1, 101),
                'action' => 'processing',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ids');
    }

    public function test_batch_rejects_non_positive_ids(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->postJson(route('admin.withdrawals.batch'), [
                'ids' => [0],
                'action' => 'processing',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ids.0');
    }

    public function test_batch_activity_log_lists_only_succeeded_ids(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $open = $this->seedWithdrawal($publisher);
        $cancelled = $this->seedWithdrawal($publisher, [
            'status' => 'cancelled',
            'net_amount' => 10,
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.withdrawals.batch'), [
                'ids' => [$cancelled->id, $open->id],
                'action' => 'completed',
            ])
            ->assertOk()
            ->assertJsonPath('succeeded', 1)
            ->assertJsonPath('failed.0.id', $cancelled->id);

        $log = ActivityLog::query()->where('action', 'withdrawal.batch_completed')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame([$open->id], $log->properties['ids'] ?? null);
        $this->assertSame(1, $log->properties['succeeded'] ?? null);
        $this->assertSame(1, $log->properties['failed'] ?? null);
    }

    public function test_batch_does_not_count_already_settled_as_updated(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $paid = $this->seedWithdrawal($publisher, [
            'status' => 'completed',
            'processed_at' => now(),
            'net_amount' => 10,
        ]);
        $open = $this->seedWithdrawal($publisher);

        $this->actingAs($admin)
            ->postJson(route('admin.withdrawals.batch'), [
                'ids' => [$paid->id, $open->id],
                'action' => 'completed',
            ])
            ->assertOk()
            ->assertJsonPath('succeeded', 1)
            ->assertJsonPath('unchanged', [$paid->id])
            ->assertJsonPath('failed', []);

        $log = ActivityLog::query()->where('action', 'withdrawal.batch_completed')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame([$open->id], $log->properties['ids'] ?? null);
        $this->assertSame(1, $log->properties['succeeded'] ?? null);
        $this->assertSame(1, $log->properties['unchanged'] ?? null);
        $this->assertSame('completed', $open->fresh()->status);

        $this->actingAs($admin)
            ->postJson(route('admin.withdrawals.batch'), [
                'ids' => [$paid->id],
                'action' => 'completed',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('succeeded', 0)
            ->assertJsonPath('unchanged', [$paid->id]);
    }
}
