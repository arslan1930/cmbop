<?php

// app/Models/Order.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Order extends Model
{
    protected $fillable = [
        'user_id',
        'order_number',
        'reference_code',
        'checkout_line_key',
        'stripe_session_id',
        'stripe_payment_intent_id',
        'stripe_response',
        'paid_at',
        'completed_at',
        'subtotal',
        'tax',
        'total_amount',
        'payment_method',
        'payment_status',
        'payment_reference',
        'admin_notes',
        'status',
        'publication_mode',
        'scheduled_publish_at',
        'schedule_timezone',
        'schedule_released_at',
        'schedule_reminder_sent_at',
        'sensitive_type',
        'additional_price',
    ];

    protected $hidden = [
        'stripe_session_id',
        'stripe_payment_intent_id',
        'stripe_response',
    ];

    protected $casts = [
        'stripe_response' => 'array',
        'paid_at' => 'datetime',
        'completed_at' => 'datetime',
        'scheduled_publish_at' => 'datetime',
        'schedule_released_at' => 'datetime',
        'schedule_reminder_sent_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'additional_price' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'tax' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    public function isScheduled(): bool
    {
        return $this->status === 'scheduled' || $this->publication_mode === 'scheduled';
    }

    /**
     * True when this order has any scheduled-publish data, including after release.
     * Timezone alone does not count — checkout stamps UTC on immediate orders too.
     */
    public function hasPublicationSchedule(): bool
    {
        return $this->isScheduled()
            || $this->scheduled_publish_at !== null
            || $this->schedule_released_at !== null
            || $this->schedule_reminder_sent_at !== null;
    }

    /**
     * Still waiting on the scheduled slot (list chip / status filter).
     * Checkout keeps status=pending and stores the slot on publication_mode.
     * Processing/review means the publisher already has it.
     */
    public function isAwaitingScheduledRelease(): bool
    {
        if ($this->schedule_released_at !== null) {
            return false;
        }

        if (in_array($this->status, ['cancelled', 'completed', 'processing', 'review'], true)) {
            return false;
        }

        return $this->isScheduled();
    }

    public function scopeAwaitingScheduledRelease($query)
    {
        return $query
            ->whereNull('schedule_released_at')
            ->whereNotIn('status', ['cancelled', 'completed', 'processing', 'review'])
            ->where(function ($q) {
                $q->where('status', 'scheduled')
                    ->orWhere('publication_mode', 'scheduled');
            });
    }

    public function scopeNotAwaitingScheduledRelease($query)
    {
        return $query->where(function ($q) {
            $q->whereNotNull('schedule_released_at')
                ->orWhereIn('status', ['cancelled', 'completed', 'processing', 'review'])
                ->orWhere(function ($inner) {
                    $inner->where(function ($status) {
                        $status->whereNull('status')->orWhere('status', '!=', 'scheduled');
                    })->where(function ($mode) {
                        $mode->whereNull('publication_mode')
                            ->orWhere('publication_mode', '!=', 'scheduled');
                    });
                });
        });
    }

    /**
     * Advertiser timezone for the scheduled slot. Invalid values fall back to UTC.
     */
    public function scheduleTimezoneOrUtc(): string
    {
        $tz = filled($this->schedule_timezone) ? (string) $this->schedule_timezone : 'UTC';

        try {
            new \DateTimeZone($tz);
        } catch (\Throwable) {
            return 'UTC';
        }

        return $tz;
    }

    /**
     * Scheduled publish instant in the advertiser timezone (UTC if the TZ is missing/invalid).
     */
    public function scheduledPublishAtInScheduleTimezone(): ?Carbon
    {
        if (! $this->scheduled_publish_at) {
            return null;
        }

        return $this->scheduled_publish_at->copy()->timezone($this->scheduleTimezoneOrUtc());
    }

    /**
     * Ops unpaid queue: not paid/refunded, and still an open order.
     */
    public function scopeUnpaidOps($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('payment_status')
                ->orWhereNotIn('payment_status', ['paid', 'refunded']);
        })->whereIn('status', ['pending', 'processing', 'review']);
    }

    /**
     * Same definition as scopeUnpaidOps(), for a loaded row.
     */
    public function isUnpaidOps(): bool
    {
        $payment = $this->payment_status;

        return ($payment === null || ! in_array($payment, ['paid', 'refunded'], true))
            && in_array($this->status, ['pending', 'processing', 'review'], true);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * True when every linked listing is still buyable. Lines without a
     * site_id (legacy guest-post) are ignored.
     */
    public function hasCatalogVisibleFulfillment(): bool
    {
        $this->loadMissing('items.site');

        foreach ($this->items as $item) {
            $siteId = (int) ($item->site_id ?? 0);
            if ($siteId <= 0) {
                continue;
            }

            $site = $item->site;
            if (! $site || ! $site->isCatalogVisible()) {
                return false;
            }
        }

        return true;
    }

    public function disputes()
    {
        return $this->hasMany(OrderItemDispute::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Get all chat messages for this order
     */
    public function chatMessages()
    {
        return $this->hasMany(OrderChatMessage::class)->orderBy('created_at', 'asc');
    }

    /**
     * Get unread chat messages for this order
     */
    public function unreadChatMessages($userId, $userType)
    {
        return $this->chatMessages()
            ->where('is_read', false)
            ->notBlocked()
            ->where('user_id', '!=', $userId)
            ->when($userType === 'advertiser', function ($q) {
                $q->where('sender_type', 'publisher');
            })
            ->when($userType === 'publisher', function ($q) {
                $q->where('sender_type', 'advertiser');
            });
    }

    /**
     * Get the latest chat message
     */
    public function getLatestChatMessageAttribute()
    {
        return $this->chatMessages()->latest()->first();
    }

    /**
     * Get unread count for this order
     */
    public function getUnreadChatCountAttribute()
    {
        $user = auth()->user();
        if (! $user) {
            return 0;
        }

        $isAdvertiser = $this->user_id === $user->id;
        $userType = $isAdvertiser ? 'advertiser' : 'publisher';

        return $this->unreadChatMessages($user->id, $userType)->count();
    }

    // Helper method to get base price
    public function getBasePriceAttribute()
    {
        return $this->subtotal - $this->additional_price;
    }

    // Helper method to check if order has sensitive pricing
    public function hasSensitivePricing()
    {
        return ! is_null($this->sensitive_type) && $this->additional_price > 0;
    }

    /**
     * 6-digit order number that is not already stored.
     */
    public static function nextOrderNumber(): string
    {
        for ($attempt = 0; $attempt < 12; $attempt++) {
            $candidate = str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);
            if (! static::query()->where('order_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        return substr(preg_replace('/\D/', '', uniqid('', true)) ?: (string) random_int(100000, 999999), -8);
    }
}
