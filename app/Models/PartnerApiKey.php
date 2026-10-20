<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerApiKey extends Model
{
    protected $fillable = [
        'partner_id', 'name', 'prefix', 'key_hash', 'scopes', 'mode',
        'last_used_at', 'last_used_ip', 'expires_at', 'revoked_at', 'created_by',
    ];

    protected $hidden = ['key_hash'];

    protected $casts = [
        'scopes' => 'array',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function isActive(): bool
    {
        if ($this->revoked_at) {
            return false;
        }
        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    public function hasScope(string $scope): bool
    {
        $scopes = $this->scopes ?? [];

        return in_array($scope, $scopes, true) || in_array('*', $scopes, true);
    }
}
