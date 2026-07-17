<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Invoice extends Model
{
    public const TYPE_TAX_INVOICE = 'tax_invoice';
    public const TYPE_PAYMENT_RECEIPT = 'payment_receipt';
    public const TYPE_PAYMENT_FAILURE = 'payment_failure';
    public const TYPE_REFUND_RECEIPT = 'refund_receipt';

    public const STATUS_ISSUED = 'issued';
    public const STATUS_PAID = 'paid';
    public const STATUS_PENDING = 'pending';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'invoice_number',
        'type',
        'status',
        'user_id',
        'order_id',
        'reference_code',
        'order_number',
        'currency',
        'subtotal',
        'tax_amount',
        'discount_amount',
        'total_amount',
        'tax_rate',
        'tax_label',
        'coupon_code',
        'payment_method',
        'payment_status',
        'transaction_id',
        'invoice_date',
        'due_date',
        'paid_at',
        'customer_name',
        'customer_email',
        'billing_snapshot',
        'line_items',
        'pdf_disk',
        'pdf_path',
        'emailed_at',
        'email_count',
        'download_count',
        'parent_invoice_id',
        'cancelled_by',
        'cancelled_at',
        'cancel_reason',
        'notes',
        'meta',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'tax_rate' => 'decimal:4',
        'invoice_date' => 'datetime',
        'due_date' => 'datetime',
        'paid_at' => 'datetime',
        'emailed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'billing_snapshot' => 'array',
        'line_items' => 'array',
        'meta' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function parentInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_invoice_id');
    }

    public function childInvoices(): HasMany
    {
        return $this->hasMany(self::class, 'parent_invoice_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(BillingEvent::class);
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isTaxInvoice(): bool
    {
        return $this->type === self::TYPE_TAX_INVOICE;
    }

    public function hasPdf(): bool
    {
        return filled($this->pdf_path);
    }

    public function pdfExists(): bool
    {
        return $this->hasPdf()
            && Storage::disk($this->pdf_disk ?: 'local')->exists($this->pdf_path);
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_TAX_INVOICE => 'Invoice',
            self::TYPE_PAYMENT_RECEIPT => 'Payment Receipt',
            self::TYPE_PAYMENT_FAILURE => 'Payment Failure',
            self::TYPE_REFUND_RECEIPT => 'Refund Receipt',
            default => ucfirst(str_replace('_', ' ', (string) $this->type)),
        };
    }
}
