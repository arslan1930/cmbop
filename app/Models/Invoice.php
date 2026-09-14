<?php

namespace App\Models;

use App\Models\Concerns\ToleratesUnparseableDates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class Invoice extends Model
{
    use ToleratesUnparseableDates;

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

    public static function tableAvailable(): bool
    {
        try {
            $table = (new static)->getTable();
            if (! Schema::hasTable($table)) {
                return false;
            }
            DB::table($table)->limit(1)->exists();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Implicit /invoices/{invoice} must 404, not 500, when the table is gone.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        if (! static::tableAvailable()) {
            return null;
        }

        try {
            return parent::resolveRouteBinding($value, $field);
        } catch (\Throwable) {
            return null;
        }
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function statusBadgeClass(): string
    {
        return match ($this->displayPaymentStatus()) {
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
     * Deposit/payout JSON show endpoints are not used — ops land on the list.
     */
    public function relatedAdminUrl(): ?string
    {
        if ($this->order_id) {
            return route('admin.orders.show', $this->order_id);
        }

        if ($this->isDepositReceipt()) {
            if (filled($this->reference_code)) {
                return route('admin.deposits', ['search' => $this->reference_code]);
            }

            if ($this->depositRequestId()) {
                return route('admin.deposits', ['search' => (string) $this->depositRequestId()]);
            }
        }

        if ($this->isWithdrawalPayout() && $this->withdrawalId()) {
            return route('admin.withdrawals', [
                'search' => (string) $this->withdrawalId(),
                'queue' => 'history',
            ]);
        }

        return null;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /**
     * Terminal document states that win over a leftover payment_status snapshot.
     *
     * @return list<string>
     */
    public static function closedStatuses(): array
    {
        return [
            self::STATUS_REFUNDED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
        ];
    }

    /**
     * Document status wins over a frozen payment_status snapshot.
     * A refunded invoice still stored as payment_status=paid is leftover.
     * A deposit receipt whose wallet top-up was clawed back is leftover
     * even if markRefunded() never flipped the RCT- row. A tax/payment
     * receipt whose order is refunded or failed is leftover the same way.
     */
    public function displayPaymentStatus(): string
    {
        if (in_array($this->status, self::closedStatuses(), true)) {
            return $this->status;
        }

        if ($this->linkedDepositIsRefunded()) {
            return self::STATUS_REFUNDED;
        }

        $orderPayment = $this->linkedOrderClosedPaymentStatus();
        if ($orderPayment !== null) {
            return $orderPayment;
        }

        $payment = trim((string) $this->payment_status);

        return $payment !== '' ? $payment : (string) $this->status;
    }

    public function linkedDepositRequest(): ?DepositRequest
    {
        if (! $this->isDepositReceipt()) {
            return null;
        }

        if ($this->relationLoaded('linkedDepositRequestCache')) {
            return $this->getRelation('linkedDepositRequestCache');
        }

        $deposit = null;
        try {
            $id = $this->depositRequestId();
            if ($id) {
                $deposit = DepositRequest::query()->find($id);
            }
            if (! $deposit && filled($this->reference_code)) {
                $query = DepositRequest::query()->where('reference_code', $this->reference_code);
                if ($this->user_id) {
                    $query->where('user_id', $this->user_id);
                }
                $deposit = $query->first();
            }
        } catch (\Throwable) {
            $deposit = null;
        }

        $this->setRelation('linkedDepositRequestCache', $deposit);

        return $deposit;
    }

    public function linkedDepositIsRefunded(): bool
    {
        return $this->linkedDepositRequest()?->status === 'refunded';
    }

    /**
     * Tax/payment receipts that stayed Paid after the order was refunded
     * or failed (handlePaymentRefunded threw, or a leftover pre-fix row).
     */
    public function linkedOrderClosedPaymentStatus(): ?string
    {
        if (! $this->order_id) {
            return null;
        }

        $order = $this->relatedOrderForDisplay();
        $payment = trim((string) ($order?->payment_status ?? ''));

        return in_array($payment, [self::STATUS_REFUNDED, self::STATUS_FAILED], true)
            ? $payment
            : null;
    }

    protected function relatedOrderForDisplay(): ?Order
    {
        if ($this->relationLoaded('order')) {
            return $this->getRelation('order');
        }

        $order = null;
        try {
            $order = $this->order()->first();
        } catch (\Throwable) {
            $order = null;
        }

        $this->setRelation('order', $order);

        return $order;
    }

    public function isClosedDocument(): bool
    {
        return in_array($this->displayPaymentStatus(), self::closedStatuses(), true);
    }

    /**
     * Stored PDFs are generated once. After a leftover refund/fail the file
     * can still say Paid until we regenerate on view/download.
     */
    public function storedPdfMayBeStale(): bool
    {
        return in_array($this->displayPaymentStatus(), self::closedStatuses(), true);
    }

    /**
     * Filter by what the advertiser/admin badge shows, not the leftover
     * status column. Paid + payment_status=refunded is Refunded, not Paid.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWhereDisplayStatus($query, string $status)
    {
        $closed = self::closedStatuses();

        return $query->where(function ($inner) use ($status, $closed) {
            $inner->where(function ($display) use ($status, $closed) {
                $display->where(function ($closedMatch) use ($status, $closed) {
                    $closedMatch->whereIn('status', $closed)
                        ->where('status', $status);
                })->orWhere(function ($paymentMatch) use ($status, $closed) {
                    $paymentMatch->whereNotIn('status', $closed)
                        ->where('payment_status', $status);
                })->orWhere(function ($statusOnly) use ($status, $closed) {
                    $statusOnly->whereNotIn('status', $closed)
                        ->where(function ($emptyPayment) {
                            $emptyPayment->whereNull('payment_status')
                                ->orWhere('payment_status', '');
                        })
                        ->where('status', $status);
                });
            });

            if (self::depositRequestsQueryable()) {
                if ($status === self::STATUS_REFUNDED) {
                    $inner->orWhere(function ($depositRefunded) {
                        $depositRefunded->where('type', self::TYPE_DEPOSIT_RECEIPT)
                            ->whereNotIn('status', self::closedStatuses())
                            ->whereIn('reference_code', self::refundedDepositReferenceCodes());
                    });
                } elseif (! in_array($status, $closed, true)) {
                    $inner->where(function ($keep) {
                        $keep->where('type', '!=', self::TYPE_DEPOSIT_RECEIPT)
                            ->orWhereNull('reference_code')
                            ->orWhereNotIn('reference_code', self::refundedDepositReferenceCodes());
                    });
                }
            }

            if (self::ordersQueryable()) {
                if ($status === self::STATUS_REFUNDED) {
                    $inner->orWhere(function ($orderRefunded) {
                        $orderRefunded->whereNotNull('order_id')
                            ->whereNotIn('status', self::closedStatuses())
                            ->whereIn('order_id', self::orderIdsWithPaymentStatus(self::STATUS_REFUNDED));
                    });
                } elseif ($status === self::STATUS_FAILED) {
                    $inner->orWhere(function ($orderFailed) {
                        $orderFailed->whereNotNull('order_id')
                            ->whereNotIn('status', self::closedStatuses())
                            ->whereIn('order_id', self::orderIdsWithPaymentStatus(self::STATUS_FAILED));
                    });
                } elseif (! in_array($status, $closed, true)) {
                    $inner->where(function ($keep) {
                        $keep->whereNull('order_id')
                            ->orWhereNotIn('order_id', self::orderIdsWithClosedPayment());
                    });
                }
            }
        });
    }

    protected static function depositRequestsQueryable(): bool
    {
        try {
            return Schema::hasTable((new DepositRequest)->getTable());
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return Builder<DepositRequest>
     */
    protected static function refundedDepositReferenceCodes()
    {
        return DepositRequest::query()
            ->select('reference_code')
            ->where('status', 'refunded')
            ->whereNotNull('reference_code');
    }

    protected static function ordersQueryable(): bool
    {
        try {
            return Schema::hasTable((new Order)->getTable());
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return Builder<Order>
     */
    protected static function orderIdsWithPaymentStatus(string $paymentStatus)
    {
        return Order::query()
            ->select('id')
            ->where('payment_status', $paymentStatus);
    }

    /**
     * @return Builder<Order>
     */
    protected static function orderIdsWithClosedPayment()
    {
        return Order::query()
            ->select('id')
            ->whereIn('payment_status', [self::STATUS_REFUNDED, self::STATUS_FAILED]);
    }

    /**
     * Refund receipts and failure reports may be resent. Paid confirmations
     * (tax invoice / payment receipt / deposit receipt) must not go out again
     * after the money was refunded or the charge failed.
     */
    public function canResendCustomerEmail(): bool
    {
        if ($this->isCancelled()) {
            return false;
        }

        if (! $this->isClosedDocument()) {
            return true;
        }

        return ! in_array($this->type, [
            self::TYPE_TAX_INVOICE,
            self::TYPE_PAYMENT_RECEIPT,
            self::TYPE_DEPOSIT_RECEIPT,
        ], true);
    }

    public function advertiserOrderUrl(): ?string
    {
        if (! $this->order_id) {
            return null;
        }

        return route('advertiser.orders', [
            'focus' => 'order',
            'order' => $this->order_id,
        ]);
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
        if (! $this->hasPdf()) {
            return false;
        }

        try {
            return Storage::disk($this->pdfStorageDisk())->exists((string) $this->pdf_path);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Disk used to read a stored PDF. Blank leftover pdf_disk must not 500
     * Storage::disk('') on view/download.
     */
    public function pdfStorageDisk(): string
    {
        $disk = is_string($this->pdf_disk) && $this->pdf_disk !== '' ? $this->pdf_disk : 'local';

        try {
            Storage::disk($disk);

            return $disk;
        } catch (\Throwable) {
            return 'local';
        }
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

    /**
     * Payout statements for the publisher wallet. Dual-role advertiser
     * cash-outs are excluded. Publisher-only leftover withdrawals with a
     * null wallet_id stay visible.
     *
     * @return Builder<static>
     */
    public static function queryPayoutsForPublisherUser(User $user): Builder
    {
        $query = static::query()
            ->where('user_id', $user->id)
            ->where('type', self::TYPE_WITHDRAWAL_PAYOUT)
            ->where('status', '!=', self::STATUS_CANCELLED);

        $ids = static::publisherPayoutWithdrawalIds($user);
        if ($ids === []) {
            return $query->whereRaw('0 = 1');
        }

        $refs = array_map(static fn (int $id): string => 'WD-'.$id, $ids);

        return $query->where(function (Builder $inner) use ($refs) {
            $inner->whereIn('reference_code', $refs)
                ->orWhereIn('transaction_id', $refs);
        });
    }

    public function isPublisherPayoutFor(User $user): bool
    {
        if ((int) $this->user_id !== (int) $user->id) {
            return false;
        }

        return static::queryPayoutsForPublisherUser($user)
            ->whereKey($this->id)
            ->exists();
    }

    /**
     * @return list<int>
     */
    public static function publisherPayoutWithdrawalIds(User $user): array
    {
        $query = Withdrawal::query()->where('user_id', $user->id);

        if (Withdrawal::hasTableColumn('wallet_id')) {
            $wallet = Wallet::forPublisher((int) $user->id);
            if (! $wallet) {
                return [];
            }

            $query->where(function (Builder $inner) use ($user, $wallet) {
                $inner->where('wallet_id', $wallet->id);
                if (! $user->hasRole('advertiser')) {
                    $inner->orWhereNull('wallet_id');
                }
            });
        }

        return $query->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public static function paymentMethodLabel(?string $method): string
    {
        $key = strtolower(trim((string) $method));

        return match ($key) {
            'bank', 'bank_transfer' => 'Bank Transfer',
            'paypal' => 'PayPal',
            'wise' => 'Wise',
            'crypto' => 'Cryptocurrency',
            'card' => 'Card',
            'wallet', 'wallet_balance' => 'Wallet',
            '' => '—',
            default => ucfirst(str_replace('_', ' ', $key)),
        };
    }

    public static function maskedPayoutDestination(mixed $details, ?string $method): ?string
    {
        if (! is_array($details) || $details === []) {
            return null;
        }

        $key = strtolower(trim((string) $method));

        if (in_array($key, ['paypal', 'wise'], true)) {
            $email = (string) ($details['email'] ?? $details['paypal_email'] ?? $details['wise_email'] ?? '');
            if ($email === '' || ! str_contains($email, '@')) {
                return null;
            }
            $at = strpos($email, '@');

            return substr($email, 0, 1).'***'.substr($email, $at);
        }

        if ($key === 'bank' || $key === 'bank_transfer') {
            $account = preg_replace('/\s+/', '', (string) ($details['account_number'] ?? $details['iban'] ?? $details['bank_account'] ?? ''));
            if ($account === '') {
                return null;
            }

            return '···'.substr($account, -4);
        }

        if ($key === 'crypto') {
            $wallet = (string) ($details['wallet_address'] ?? $details['crypto_wallet'] ?? '');
            $coin = (string) ($details['crypto_type'] ?? 'Crypto');
            if ($wallet === '') {
                return $coin !== '' ? $coin : null;
            }

            return $coin.' · ···'.substr($wallet, -4);
        }

        return null;
    }
}
