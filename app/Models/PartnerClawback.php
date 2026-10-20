<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerClawback extends Model
{
    public const OPEN = 'open';

    public const OFFSET = 'offset';

    public const REPAYMENT_REQUESTED = 'repayment_requested';

    public const RECOVERED = 'recovered';

    protected $fillable = [
        'order_item_id', 'partner_id', 'amount_minor', 'reason', 'incident_id',
        'dispute_id', 'ledger_transaction_id', 'status', 'opened_at', 'recovered_at',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'recovered_at' => 'datetime',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
}
