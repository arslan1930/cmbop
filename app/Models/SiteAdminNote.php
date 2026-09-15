<?php

namespace App\Models;

use App\Models\Concerns\ToleratesUnparseableDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class SiteAdminNote extends Model
{
    use ToleratesUnparseableDates;

    public $timestamps = false;

    protected static ?bool $tableAvailable = null;

    protected $fillable = [
        'site_id',
        'admin_id',
        'body',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public static function tableAvailable(): bool
    {
        if (static::$tableAvailable !== null) {
            return static::$tableAvailable;
        }

        try {
            return static::$tableAvailable = Schema::hasTable((new static)->getTable());
        } catch (\Throwable) {
            return static::$tableAvailable = false;
        }
    }

    /** @internal */
    public static function forgetTableAvailabilityCache(): void
    {
        static::$tableAvailable = null;
    }

    public static function ensureTable(): void
    {
        try {
            if (self::tableAvailable()) {
                return;
            }

            Schema::create((new static)->getTable(), function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('site_id')->index();
                $table->unsignedBigInteger('admin_id')->nullable()->index();
                $table->text('body');
                $table->timestamp('created_at')->useCurrent();
            });

            static::forgetTableAvailabilityCache();
            Log::warning('site_admin_notes table was missing — created at runtime');
        } catch (\Throwable $e) {
            Log::error('Could not create site_admin_notes at runtime', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
