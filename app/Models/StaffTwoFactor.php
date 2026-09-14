<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class StaffTwoFactor extends Model
{
    protected $table = 'staff_two_factor';

    protected $fillable = [
        'user_id',
        'secret',
        'recovery_codes',
        'confirmed_at',
        'last_totp_step',
    ];

    protected $hidden = [
        'secret',
        'recovery_codes',
    ];

    protected $casts = [
        'secret' => 'encrypted',
        'recovery_codes' => 'encrypted:array',
        'confirmed_at' => 'datetime',
        'last_totp_step' => 'integer',
    ];

    public static function ensureTable(): void
    {
        try {
            if (Schema::hasTable((new static)->getTable())) {
                return;
            }

            Schema::create((new static)->getTable(), function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->unique();
                $table->text('secret')->nullable();
                $table->text('recovery_codes')->nullable();
                $table->timestamp('confirmed_at')->nullable();
                $table->unsignedBigInteger('last_totp_step')->nullable();
                $table->timestamps();
            });
        } catch (\Throwable $e) {
            Log::warning('Could not create staff_two_factor at runtime', [
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

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null && is_string($this->secret) && $this->secret !== '';
    }
}
