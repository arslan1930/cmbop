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

    public const TYPE_DEPOSIT_RECEIPT = 'deposit_receipt';

    public const TYPE_WITHDRAWAL_PAYOUT = 'withdrawal_payout';

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

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            self::STATUS_PAID => 'success',
            self::STATUS_FAILED => 'danger',
            self::STATUS_PENDING => 'warning',
            self::STATUS_REFUNDED => 'info',
            self::STATUS_CANCELLED => 'secondary',
            default => 'primary',
        };
    }

    public function referenceLabel(): string
    {
        if (filled($this->order_number)) {
            return '#'.$this->order_number;
        }

        if (filled($this->reference_code)) {
            return (string) $this->reference_code;
        }

        return '—';
    }

    public function depositRequestId(): ?int
    {
        $id = (int) data_get($this->meta, 'deposit_request_id');

        return $id > 0 ? $id : null;
    }

    public function withdrawalId(): ?int
    {
        $id = (int) data_get($this->meta, 'withdrawal_id');
        if ($id > 0) {
            return $id;
        }

        if (preg_match('/^WD-(\d+)$/', (string) $this->reference_code, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * HTML admin page for the related order, deposit, or withdrawal.
     * Payout statements open the shareable withdrawal page (browser GET).
     * Root-relative so APP_URL host mismatch does not drop the admin session.
     */
    public function relatedAdminUrl(): ?string
    {
        if ($this->isWithdrawalPayout() && $this->withdrawalId()) {
            return route('admin.withdrawals.show', $this->withdrawalId(), false);
        }

        if ($this->isDepositReceipt()) {
            if (filled($this->reference_code)) {
                return route('admin.deposits', ['search' => $this->reference_code], false);
            }

            if ($this->depositRequestId()) {
                return route('admin.deposits.show', $this->depositRequestId(), false);
            }
        }

        if ($this->order_id) {
            return route('admin.orders.show', $this->order_id, false);
        }

        return null;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isTaxInvoice(): bool
    {
        return $this->type === self::TYPE_TAX_INVOICE;
    }

    public function isDepositReceipt(): bool
    {
        return $this->type === self::TYPE_DEPOSIT_RECEIPT;
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
            self::TYPE_DEPOSIT_RECEIPT => 'Deposit Receipt',
            self::TYPE_WITHDRAWAL_PAYOUT => 'Payout Statement',
            default => ucfirst(str_replace('_', ' ', (string) $this->type)),
        };
    }

    public function isWithdrawalPayout(): bool
    {
        return $this->type === self::TYPE_WITHDRAWAL_PAYOUT;
    }

    public static function paymentMethodLabel(?string $method): string
    {
        $key = strtolower(trim((string) $method));

        return match ($key) {
            'bank', 'bank_transfer' => 'Bank Transfer',
            'paypal' => 'PayPal',
            'wise' => 'Wise',
            'crypto' => 'Cryptocurrency',
            '' => '—',
            default => ucfirst(str_replace('_', ' ', $key)),
        };
    }

    public static function maskedPayoutDestination(mixed $details, ?string $method): ?string
    {
        $details = Withdrawal::detailsArray($details);
        if ($details === []) {
            return null;
        }

        $key = strtolower(trim((string) $method));

        if (in_array($key, ['paypal', 'wise'], true)) {
            $email = Withdrawal::firstDetailText($details, 'email', 'paypal_email', 'wise_email');
            if ($email === '' || ! str_contains($email, '@')) {
                return null;
            }
            $at = strpos($email, '@');

            return substr($email, 0, 1).'***'.substr($email, $at);
        }

        if ($key === 'bank' || $key === 'bank_transfer') {
            $account = preg_replace('/\s+/', '', Withdrawal::firstDetailText($details, 'account_number', 'iban', 'bank_account')) ?? '';
            if ($account === '') {
                return null;
            }

            return '···'.substr($account, -4);
        }

        if ($key === 'crypto') {
            $wallet = Withdrawal::firstDetailText($details, 'wallet_address', 'crypto_wallet');
            $coin = Withdrawal::firstDetailText($details, 'crypto_type') ?: 'Crypto';
            if ($wallet === '') {
                return $coin !== '' ? $coin : null;
            }

            return $coin.' · ···'.substr($wallet, -4);
        }

        return null;
    }
}
