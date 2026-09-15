<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class StaffCapability extends Model
{
    public const FINANCE = 'finance';

    public const SUPPORT = 'support';

    /** @var list<string> */
    public const ALL = [self::FINANCE, self::SUPPORT];

    protected $table = 'staff_capabilities';

    protected $fillable = [
        'user_id',
        'capability',
    ];

    public static function ensureTable(): void
    {
        try {
            if (Schema::hasTable((new static)->getTable())) {
                return;
            }

            Schema::create((new static)->getTable(), function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('capability', 32);
                $table->timestamps();
                $table->unique(['user_id', 'capability']);
            });
        } catch (\Throwable $e) {
            Log::warning('Could not create staff_capabilities at runtime', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function tableReady(): bool
    {
        try {
            return Schema::hasTable((new static)->getTable());
        } catch (\Throwable) {
            return false;
        }
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
