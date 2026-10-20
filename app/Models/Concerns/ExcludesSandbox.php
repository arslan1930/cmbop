<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

trait ExcludesSandbox
{
    protected static ?bool $hasSandboxColumn = null;

    public static function hasSandboxColumn(): bool
    {
        if (static::$hasSandboxColumn === true) {
            return true;
        }
        try {
            $exists = Schema::hasColumn((new static)->getTable(), 'is_sandbox');
        } catch (\Throwable) {
            return false;
        }
        if ($exists) {
            static::$hasSandboxColumn = true;
        }

        return $exists;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeNotSandbox($query)
    {
        if (! static::hasSandboxColumn()) {
            return $query;
        }
        $table = $query->getModel()->getTable();

        return $query->where(function ($inner) use ($table) {
            $inner->where($table.'.is_sandbox', 0)->orWhereNull($table.'.is_sandbox');
        });
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSandboxOnly($query)
    {
        if (! static::hasSandboxColumn()) {
            return $query->whereRaw('0 = 1');
        }

        return $query->where($query->getModel()->getTable().'.is_sandbox', 1);
    }

    public function isSandbox(): bool
    {
        if (! static::hasSandboxColumn()) {
            return false;
        }

        return (bool) ($this->is_sandbox ?? false);
    }
}
