<?php

namespace App\Models\Concerns;

use App\Models\Scopes\OptionalSoftDeletingScope;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;

trait SoftDeletesWhenReady
{
    use SoftDeletes {
        SoftDeletes::bootSoftDeletes as private bootAlwaysSoftDeletes;
        SoftDeletes::performDeleteOnModel as private performSoftDeleteOnModel;
        SoftDeletes::restore as private performRestore;
    }

    public static function bootSoftDeletes(): void
    {
        static::addGlobalScope(new OptionalSoftDeletingScope);
    }

    protected function performDeleteOnModel()
    {
        if (! OptionalSoftDeletingScope::columnReady($this)) {
            $this->forceDeleting = true;
        }

        return $this->performSoftDeleteOnModel();
    }

    public function restore()
    {
        if (! OptionalSoftDeletingScope::columnReady($this)) {
            return false;
        }

        return $this->performRestore();
    }

    /**
     * Implicit route binding must not 500 when Hostinger is still missing the table.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        try {
            return parent::resolveRouteBinding($value, $field);
        } catch (\Throwable) {
            return null;
        }
    }

    public function resolveSoftDeletableRouteBinding($value, $field = null)
    {
        try {
            return parent::resolveSoftDeletableRouteBinding($value, $field);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function deletedAtColumnReady(): bool
    {
        try {
            $table = (new static)->getTable();

            return Schema::hasTable($table) && Schema::hasColumn($table, (new static)->getDeletedAtColumn());
        } catch (\Throwable) {
            return false;
        }
    }
}
