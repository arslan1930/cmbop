<?php

namespace App\Services\Billing;

use App\Models\BillingRuleSetting;

class BillingRuleService
{
    public function tableReady(): bool
    {
        return BillingRuleSetting::tableReady();
    }

    public function minWithdrawalAmount(): float
    {
        return BillingRuleSetting::minAmount();
    }

    public function withdrawalFeePercent(): float
    {
        return BillingRuleSetting::feePercent();
    }

    public function minAmountIsStored(): bool
    {
        return BillingRuleSetting::hasStoredMinAmount();
    }

    public function feePercentIsStored(): bool
    {
        return BillingRuleSetting::hasStoredFeePercent();
    }

    public function setMinAmount(float $amount, ?int $updatedBy = null): void
    {
        BillingRuleSetting::setMinAmount($amount, $updatedBy);
    }

    public function setFeePercent(float $percent, ?int $updatedBy = null): void
    {
        BillingRuleSetting::setFeePercent($percent, $updatedBy);
    }

    /**
     * @return array{
     *     min_amount: float,
     *     fee_percent: float,
     *     min_amount_max: float,
     *     fee_percent_max: float,
     *     min_amount_source: 'stored'|'config',
     *     fee_percent_source: 'stored'|'config',
     *     table_ready: bool
     * }
     */
    public function snapshot(): array
    {
        return [
            'min_amount' => $this->minWithdrawalAmount(),
            'fee_percent' => $this->withdrawalFeePercent(),
            'min_amount_max' => BillingRuleSetting::minAmountMax(),
            'fee_percent_max' => BillingRuleSetting::feePercentMax(),
            'min_amount_source' => $this->minAmountIsStored() ? 'stored' : 'config',
            'fee_percent_source' => $this->feePercentIsStored() ? 'stored' : 'config',
            'table_ready' => $this->tableReady(),
        ];
    }
}
