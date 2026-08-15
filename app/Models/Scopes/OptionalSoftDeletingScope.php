<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Schema;

/**
 * Soft-delete scope that no-ops when deleted_at has not been migrated yet.
 * Hostinger heals schema after the first response, so the first page view
 * and public click routes must not 500 on the new column.
 */
class OptionalSoftDeletingScope extends SoftDeletingScope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (! self::columnReady($model)) {
            return;
        }

        parent::apply($builder, $model);
    }

    public static function columnReady(Model $model): bool
    {
        try {
            $table = $model->getTable();
            $column = method_exists($model, 'getDeletedAtColumn')
                ? $model->getDeletedAtColumn()
                : 'deleted_at';

            return Schema::hasTable($table) && Schema::hasColumn($table, $column);
        } catch (\Throwable) {
            return false;
        }
    }

    public function extend(Builder $builder): void
    {
        parent::extend($builder);

        $builder->onDelete(function (Builder $builder) {
            if (! self::columnReady($builder->getModel())) {
                return $builder->toBase()->delete();
            }

            $column = $this->getDeletedAtColumn($builder);

            return $builder->update([
                $column => $builder->getModel()->freshTimestampString(),
            ]);
        });
    }

    protected function addOnlyTrashed(Builder $builder)
    {
        $builder->macro('onlyTrashed', function (Builder $builder) {
            $model = $builder->getModel();
            $builder->withoutGlobalScope($this);

            if (! self::columnReady($model)) {
                return $builder->whereRaw('0 = 1');
            }

            return $builder->whereNotNull($model->getQualifiedDeletedAtColumn());
        });
    }

    protected function addWithoutTrashed(Builder $builder)
    {
        $builder->macro('withoutTrashed', function (Builder $builder) {
            $model = $builder->getModel();
            $builder->withoutGlobalScope($this);

            if (! self::columnReady($model)) {
                return $builder;
            }

            return $builder->whereNull($model->getQualifiedDeletedAtColumn());
        });
    }
}
