<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderActivity extends Model
{
    protected $fillable = [
        'order_id',
        'actor_id',
        'actor_name',
        'actor_role',
        'event',
        'title',
        'description',
        'icon',
        'badge_color',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'event' => $this->event,
            'title' => $this->title,
            'description' => $this->description,
            'icon' => $this->icon,
            'badge_color' => $this->badge_color,
            'actor_name' => $this->actor_name,
            'actor_role' => $this->actor_role,
            'meta' => $this->meta ?? [],
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'relative_time' => optional($this->created_at)?->diffForHumans(),
            'exact_time' => optional($this->created_at)?->format('M j, Y g:i A'),
        ];
    }
}
