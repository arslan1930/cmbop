<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Schema;

class Partner extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = [
        'user_id',
        'status',
        'trusted',
        'agreement_signed',
        'agreement_signed_at',
        'legal_name',
        'notes_internal',
    ];

    protected $casts = [
        'trusted' => 'boolean',
        'agreement_signed' => 'boolean',
        'agreement_signed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function connections(): HasMany
    {
        return $this->hasMany(PartnerConnection::class);
    }

    public function feedConnection(): HasOne
    {
        return $this->hasOne(PartnerConnection::class)->where('type', PartnerConnection::TYPE_FEED_URL);
    }

    public function catalogItems(): HasMany
    {
        return $this->hasMany(PartnerCatalogItem::class);
    }

    public function importJobs(): HasMany
    {
        return $this->hasMany(PartnerImportJob::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function displayName(): string
    {
        $legal = trim((string) $this->legal_name);
        if ($legal !== '') {
            return $legal;
        }

        return trim((string) ($this->user?->name ?: $this->user?->email ?: 'Partner #'.$this->id));
    }

    public static function forUser(User $user): ?self
    {
        if (! Schema::hasTable('partners')) {
            return null;
        }

        return static::query()->where('user_id', $user->id)->first();
    }
}
