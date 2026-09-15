<?php

namespace Tests\Unit;

use App\Models\BillingRuleSetting;
use App\Services\Billing\BillingRuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingRuleServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_match_config_when_nothing_is_stored(): void
    {
        $service = app(BillingRuleService::class);

        $this->assertSame(20.0, $service->minWithdrawalAmount());
        $this->assertSame(0.0, $service->withdrawalFeePercent());
        $this->assertFalse($service->minAmountIsStored());
        $this->assertFalse($service->feePercentIsStored());
    }

    public function test_normalize_clamps_fee_and_min(): void
    {
        $this->assertSame(50.0, BillingRuleSetting::normalizeFeePercent(999));
        $this->assertSame(0.0, BillingRuleSetting::normalizeFeePercent(-3));
        $this->assertSame(0.01, BillingRuleSetting::normalizeMinAmount(0));
        $this->assertSame(10000.0, BillingRuleSetting::normalizeMinAmount(999999));
    }

    public function test_setting_one_field_does_not_freeze_the_other_to_config(): void
    {
        config(['billing.withdrawal_fee_percent' => 3, 'billing.withdrawal_min_amount' => 20]);
        $service = app(BillingRuleService::class);
        $service->setMinAmount(30);

        $this->assertSame(30.0, $service->minWithdrawalAmount());
        $this->assertTrue($service->minAmountIsStored());
        $this->assertSame(3.0, $service->withdrawalFeePercent());
        $this->assertFalse($service->feePercentIsStored());

        config(['billing.withdrawal_fee_percent' => 9]);
        $this->assertSame(9.0, $service->withdrawalFeePercent());
        $this->assertSame(30.0, $service->minWithdrawalAmount());
    }
}
