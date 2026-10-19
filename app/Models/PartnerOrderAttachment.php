<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerOrderAttachment extends Model
{
    protected $fillable = [
        'message_id', 'order_item_id', 'uploaded_by', 'filename_original',
        'filename_masked', 'mime', 'size', 'storage_path', 'scan_status',
    ];

    protected $hidden = ['filename_original', 'storage_path'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(PartnerOrderMessage::class, 'message_id');
    }
}
