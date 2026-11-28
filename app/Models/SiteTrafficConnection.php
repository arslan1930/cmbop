<?php

namespace App\Models;

use App\Support\VerifiedTraffic;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class SiteTrafficConnection extends Model
{
    protected $fillable = [
        'site_id',
        'user_id',
        'google_account_email',
        'google_account_id',
        'access_token_encrypted',
        'refresh_token_encrypted',
        'token_expires_at',
        'scopes',
        'ga4_property_id',
        'ga4_property_name',
        'gsc_site_url',
        'status',
        'last_error',
        'last_synced_at',
        'last_notified_at',
    ];

    protected $hidden = [
        'access_token_encrypted',
        'refresh_token_encrypted',
    ];

    protected $casts = [
        'scopes' => 'array',
        'token_expires_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'last_notified_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function accessToken(): ?string
    {
        return $this->decryptToken((string) ($this->access_token_encrypted ?? ''));
    }

    public function refreshToken(): ?string
    {
        return $this->decryptToken((string) ($this->refresh_token_encrypted ?? ''));
    }

    public function setAccessToken(?string $token): void
    {
        $this->access_token_encrypted = $token ? Crypt::encryptString($token) : '';
    }

    public function setRefreshToken(?string $token): void
    {
        $this->refresh_token_encrypted = $token ? Crypt::encryptString($token) : null;
    }

    public function isConnected(): bool
    {
        return $this->status === VerifiedTraffic::STATUS_CONNECTED;
    }

    public function hasProperty(): bool
    {
        return filled($this->ga4_property_id) || filled($this->gsc_site_url);
    }

    public function canNotifyFailure(): bool
    {
        if (! $this->last_notified_at) {
            return true;
        }

        return $this->last_notified_at->lte(now()->subHours(VerifiedTraffic::NOTIFY_THROTTLE_HOURS));
    }

    private function decryptToken(string $ciphertext): ?string
    {
        if ($ciphertext === '') {
            return null;
        }

        try {
            $plain = Crypt::decryptString($ciphertext);

            return $plain !== '' ? $plain : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
