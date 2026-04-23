<?php


namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StripeWebhookLog extends Model
{
    protected $table = 'stripe_webhook_logs';
    
    protected $fillable = [
        'event_id', 'event_type', 'payload', 'processed'
    ];
    
    protected $casts = [
        'payload' => 'array',
        'processed' => 'boolean'
    ];
}