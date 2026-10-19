<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartnerPricingRule extends Model
{
    protected $fillable = [
        'name',
        'tiers',
        'min_commission_eur',
        'rounding',
        'jitter_percent',
        'is_default',
        'active',
        'created_by',
    ];

    protected $casts = [
        'tiers' => 'array',
        'min_commission_eur' => 'decimal:2',
        'jitter_percent' => 'decimal:2',
        'is_default' => 'boolean',
        'active' => 'boolean',
    ];

    public static function defaultRule(): ?self
    {
        return static::query()->where('is_default', true)->where('active', true)->first()
            ?? static::query()->where('active', true)->orderBy('id')->first();
    }

    /**
     * @return list<array{max_eur: ?float, multiplier: float}>
     */
    public function normalizedTiers(): array
    {
        $tiers = is_array($this->tiers) ? $this->tiers : [];
        $out = [];
        foreach ($tiers as $tier) {
            if (! is_array($tier)) {
                continue;
            }
            $out[] = [
                'max_eur' => array_key_exists('max_eur', $tier) && $tier['max_eur'] !== null
                    ? (float) $tier['max_eur']
                    : null,
                'multiplier' => (float) ($tier['multiplier'] ?? 2.0),
            ];
        }

        return $out !== [] ? $out : [
            ['max_eur' => 50.0, 'multiplier' => 2.0],
            ['max_eur' => 150.0, 'multiplier' => 1.8],
            ['max_eur' => 400.0, 'multiplier' => 1.6],
            ['max_eur' => null, 'multiplier' => 1.45],
        ];
    }
}
