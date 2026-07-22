<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrdersStatsStripTest extends TestCase
{
    use RefreshDatabase;

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    public function test_orders_page_shows_kpi_strip(): void
    {
        $advertiser = $this->advertiser();

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.orders'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Total Deposits', $html);
        $this->assertStringContainsString('Total Spent', $html);
        $this->assertStringContainsString('Total Orders', $html);
        $this->assertStringContainsString('id="ordTotalDeposits"', $html);
        $this->assertStringContainsString('id="ordTotalSpent"', $html);
        $this->assertStringContainsString('id="ordTotalOrders"', $html);
        $this->assertStringContainsString('loadOrdStatistics', $html);
        $this->assertStringContainsString(route('advertiser.reports.statistics'), $html);
    }

    public function test_reports_page_no_longer_shows_kpi_strip(): void
    {
        // ReportsController::index uses MySQL DATE_FORMAT; assert the Blade markup directly.
        $html = file_get_contents(resource_path('views/advertiser/reports.blade.php'));

        $this->assertIsString($html);
        $this->assertStringNotContainsString('id="repTotalDeposits"', $html);
        $this->assertStringNotContainsString('id="repTotalSpent"', $html);
        $this->assertStringNotContainsString('id="repTotalOrders"', $html);
        $this->assertStringNotContainsString('loadRepStatistics', $html);
        $this->assertStringContainsString('Funds Activity', $html);
        $this->assertStringContainsString('id="repFundsTab"', $html);
        $this->assertStringContainsString('id="repOrdersTab"', $html);
    }

    public function test_reports_statistics_endpoint_still_works(): void
    {
        $advertiser = $this->advertiser();

        $this->actingAs($advertiser)
            ->getJson(route('advertiser.reports.statistics'))
            ->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'total_deposits' => 0,
                    'total_spent' => 0,
                    'total_orders' => 0,
                ],
            ]);
    }
}
