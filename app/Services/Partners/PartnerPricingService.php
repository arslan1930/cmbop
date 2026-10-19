<?php

namespace App\Services\Partners;

use App\Models\PartnerCatalogItem;
use App\Models\PartnerPricingRule;

class PartnerPricingService
{
    public function marginFloor(): float
    {
        return max(0.0, min(0.95, (float) config('partners.margin_floor', 0.35)));
    }

    public function hardFloor(): float
    {
        return max(0.0, min(0.95, (float) config('partners.hard_floor', 0.15)));
    }

    public function minCommission(): float
    {
        return max(0.0, (float) config('partners.min_commission_eur', 25));
    }

    public function defaultRule(): ?PartnerPricingRule
    {
        return PartnerPricingRule::defaultRule();
    }

    /**
     * @return array{amount: float, jitter: float, source: string}
     */
    public function suggestPublish(PartnerCatalogItem $item, ?PartnerPricingRule $rule = null): array
    {
        $rule ??= $item->pricingRule ?: $this->defaultRule();
        $partnerEur = (float) $item->partner_rate_publish_eur;
        $jitter = $item->slb_jitter !== null ? (float) $item->slb_jitter : $this->rollJitter($rule);

        return $this->quote($partnerEur, $rule, $jitter);
    }

    /**
     * @return array{amount: float, jitter: float, source: string}
     */
    public function quote(float $partnerEur, ?PartnerPricingRule $rule, ?float $jitter = null): array
    {
        $rule ??= $this->defaultRule();
        $jitter ??= $this->rollJitter($rule);
        $multiplier = $this->multiplier($partnerEur, $rule);
        $raw = $partnerEur * $multiplier * (1 + $jitter);
        $minCommission = $rule?->min_commission_eur !== null
            ? (float) $rule->min_commission_eur
            : $this->minCommission();
        $floor = $this->marginFloor();
        $minByCommission = $partnerEur + $minCommission;
        $minByFloor = $floor > 0 && $floor < 1 ? $partnerEur / (1 - $floor) : $partnerEur;
        $raw = max($raw, $minByCommission, $minByFloor);
        $rounding = $rule?->rounding ?? 'end_9';
        $amount = $this->roundAmount($raw, $rounding);
        if ($amount < $minByCommission || ($floor > 0 && $amount > 0 && (($amount - $partnerEur) / $amount) < $floor)) {
            $amount = $this->roundAmount(max($minByCommission, $minByFloor, $amount + 1), $rounding);
            while ($amount > 0 && (($amount - $partnerEur) / $amount) < $floor) {
                $amount = $this->roundAmount($amount + 10, $rounding);
                if ($amount > $partnerEur * 10 + 1000) {
                    break;
                }
            }
        }

        return [
            'amount' => round($amount, 2),
            'jitter' => $jitter,
            'source' => 'rule',
        ];
    }

    public function applySuggested(PartnerCatalogItem $item, ?PartnerPricingRule $rule = null, bool $overwriteManual = false): PartnerCatalogItem
    {
        if ($item->slb_price_overridden && ! $overwriteManual) {
            $this->refreshFloorFlag($item);

            return $item;
        }

        $rule ??= $this->defaultRule();
        $publish = $this->suggestPublish($item, $rule);
        $item->slb_jitter = $publish['jitter'];
        $item->slb_price_publish_eur = $publish['amount'];
        $item->slb_price_source = 'rule';
        $item->pricing_rule_id = $rule?->id;

        if ($item->partner_rate_writing_eur !== null) {
            $writing = $this->quote((float) $item->partner_rate_writing_eur, $rule, $publish['jitter']);
            $item->slb_price_writing_eur = $writing['amount'];
        } else {
            $item->slb_price_writing_eur = null;
        }

        if ($item->partner_rate_sensitive_extra_eur !== null) {
            $extra = $this->quote((float) $item->partner_rate_sensitive_extra_eur, $rule, $publish['jitter']);
            $item->slb_price_sensitive_extra_eur = $extra['amount'];
        } else {
            $item->slb_price_sensitive_extra_eur = null;
        }

        $this->refreshFloorFlag($item);

        return $item;
    }

    public function refreshFloorFlag(PartnerCatalogItem $item): void
    {
        $slb = (float) ($item->slb_price_publish_eur ?? 0);
        $partner = (float) $item->partner_rate_publish_eur;
        if ($slb <= 0) {
            $item->below_margin_floor = true;

            return;
        }
        $margin = ($slb - $partner) / $slb;
        $item->below_margin_floor = $margin + 0.00001 < $this->marginFloor() && ! $item->margin_override;
    }

    public function holdsFloor(float $slb, float $partnerEur, bool $allowOverride = false): bool
    {
        if ($allowOverride) {
            return true;
        }
        if ($slb <= 0) {
            return false;
        }
        $commission = $slb - $partnerEur;
        if ($commission + 0.00001 < $this->minCommission()) {
            return false;
        }

        return (($commission / $slb) + 0.00001) >= $this->marginFloor();
    }

    public function belowHardFloor(float $slb, float $partnerEur): bool
    {
        if ($slb <= 0) {
            return true;
        }

        return (($slb - $partnerEur) / $slb) < $this->hardFloor();
    }

    public function multiplier(float $partnerEur, ?PartnerPricingRule $rule = null): float
    {
        $rule ??= $this->defaultRule();
        $tiers = $rule?->normalizedTiers() ?? [];
        foreach ($tiers as $tier) {
            $max = $tier['max_eur'];
            if ($max === null || $partnerEur <= $max + 0.00001) {
                return (float) $tier['multiplier'];
            }
        }

        return 2.0;
    }

    private function rollJitter(?PartnerPricingRule $rule): float
    {
        $max = $rule?->jitter_percent !== null ? (float) $rule->jitter_percent : 5.0;
        $max = max(0.0, min(5.0, $max));
        if ($max <= 0) {
            return 0.0;
        }

        return round((mt_rand(0, (int) round($max * 100)) / 10000), 6);
    }

    private function roundAmount(float $amount, string $rounding): float
    {
        return match ($rounding) {
            'end_0' => (float) (round($amount / 10) * 10),
            'none' => round($amount, 2),
            default => $this->roundEnd9($amount),
        };
    }

    private function roundEnd9(float $amount): float
    {
        $n = (int) max(9, (int) round($amount));
        $tens = intdiv($n, 10) * 10;
        $down = $tens - 1;
        if ($down < 9) {
            $down = 9;
        }
        $up = $tens + 9;
        if ($up < 9) {
            $up = 9;
        }

        return (float) (abs($n - $down) <= abs($up - $n) ? $down : $up);
    }
}
