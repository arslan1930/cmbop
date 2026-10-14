<?php

namespace App\Models\Concerns;

use App\Support\CountryCode;

trait NormalizesCountry
{
    public static function bootNormalizesCountry(): void
    {
        static::saving(function ($model) {
            if (! $model->isDirty('country')) {
                return;
            }

            $normalized = CountryCode::normalize($model->country);
            if ($normalized !== null) {
                $model->country = $normalized;
            }
        });
    }
}
