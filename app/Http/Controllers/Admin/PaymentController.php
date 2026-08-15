<?php

// app/Http/Controllers/Admin/PaymentController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\OrderPaymentConfirmed;
use App\Models\ContentSubmission;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Wallet;
use App\Services\ActivityLogger;
use App\Services\Advertiser\SpendBudgetService;
use App\Services\Billing\AdminInvoiceLinks;
use App\Services\Billing\BillingDocumentService;
use App\Services\CheckoutSchemaService;
use App\Services\InAppNotificationService;
use App\Services\OrderPaymentService;
use App\Services\Orders\OrderRefundService;
use App\Support\BillingCustomerMailSuppressor;
use App\Support\OrderLifecycleMailSuppressor;
use App\Support\UserFacingError;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentController extends Controller
{
    public const EXPORT_LIMIT = 5000;

    /**
     * Display payments list page
     */
    public function index()
    {
        return view('admin.payments');
    }

    /**
     * Get payments data for DataTable (AJAX)
     */
    public function getPaymentsData(Request $request)
    {
        try {
            $this->ensurePaymentColumns();
            $query = $this->paymentsQuery($request);

            $perPage = (int) $request->input('per_page', 20);
            $perPage = max(1, min(100, $perPage));
            $orders = $query->paginate($perPage);
            $this->attachInvoiceDocuments($orders->getCollection());

            $unpaid = Order::query()->unpaidOps();

            return response()->json([
                'success' => true,
                'data' => collect($orders->items())->map(fn (Order $order) => $this->serializePaymentRow($order))->values(),
                'pagination' => [
                    'current_page' => $orders->currentPage(),
                    'last_page' => $orders->lastPage(),
                    'per_page' => $orders->perPage(),
                    'total' => $orders->total(),
                    'from' => $orders->firstItem(),
                    'to' => $orders->lastItem(),
                ],
                'summary' => [
                    'unpaid_count' => (clone $unpaid)->count(),
                    'unpaid_amount' => round((float) (clone $unpaid)->sum('total_amount'), 2),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching payments: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to fetch payments. Please try again.'),
            ], 500);
        }
    }

    /**
     * CSV of the current filter (capped) for finance reconciliation.
     */
    public function export(Request $request): StreamedResponse
    {
        $this->ensurePaymentColumns();
        $rows = $this->paymentsQuery($request)->limit(self::EXPORT_LIMIT)->get();
        $filename = 'order-payments-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'order_number',
                'reference_code',
                'user_name',
                'user_email',
                'amount',
                'payment_method',
                'payment_status',
                'order_status',
                'payment_reference',
                'admin_notes',
                'paid_at',
                'created_at',
            ]);

            foreach ($rows as $order) {
                fputcsv($out, [
                    $order->order_number,
                    $order->reference_code,
                    $order->user?->name,
                    $order->user?->email,
                    number_format((float) $order->total_amount, 2, '.', ''),
                    $order->payment_method,
                    $order->payment_status,
                    $order->status,
                    $order->payment_reference,
                    $order->admin_notes,
                    optional($order->paid_at)->toDateTimeString(),
                    optional($order->created_at)->toDateTimeString(),
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Show single payment details (slim payload — no Stripe dump / payout fields).
     */
    public function show($id)
    {
        try {
            $order = Order::with(['user', 'items.site'])->findOrFail($id);
            $this->attachInvoiceDocuments(collect([$order]));

            return response()->json([
                'success' => true,
                'data' => $this->serializePaymentRow($order),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Payment not found',
            ], 404);
        }
    }

    /**
     * Update payment status
     */
    public function updatePaymentStatus(Request $request, $id)
    {
        // jQuery form posts send_notification as "true"/"false". Laravel's
        // boolean rule only allows true/false/0/1/"0"/"1", so normalize first.
        $this->mergeJqueryBoolean($request, 'send_notification');

        // Outside the try: the catch-all would turn a ValidationException into a 500.
        $request->validate([
            'payment_status' => 'required|in:pending,paid,failed,refunded',
            'notes' => 'nullable|string|max:2000',
            'payment_reference' => 'nullable|string|max:120',
            'send_notification' => 'sometimes|boolean',
        ]);

        // Omitted (API / existing clients) still notifies. The Order Payments
        // checkbox posts true/false and must be honoured.
        $sendNotification = $request->has('send_notification')
            ? $request->boolean('send_notification')
            : true;

        $billingSuppressor = app(BillingCustomerMailSuppressor::class);
        $notes = is_string($request->input('notes')) ? trim((string) $request->input('notes')) : '';
        $paymentReference = is_string($request->input('payment_reference'))
            ? trim((string) $request->input('payment_reference'))
            : '';

        $this->ensurePaymentColumns();

        try {
            if (! $sendNotification) {
                app(OrderLifecycleMailSuppressor::class)->suppress((int) $id, ['advertiser']);
                $billingSuppressor->enable();
            }

            DB::beginTransaction();

            $order = Order::with('user')->where('id', $id)->lockForUpdate()->firstOrFail();

            $oldStatus = $order->payment_status;
            $newStatus = (string) $request->payment_status;

            if (! in_array($newStatus, $this->allowedPaymentStatuses($order), true)) {
                return $this->abortPaymentUpdate(
                    (int) $id,
                    $sendNotification,
                    $this->disallowedStatusMessage($order, $newStatus)
                );
            }

            if ($newStatus === 'paid' && $oldStatus !== 'paid') {
                if (in_array((string) $order->status, ['cancelled', 'completed'], true)
                    || $oldStatus === 'refunded') {
                    return $this->abortPaymentUpdate(
                        (int) $id,
                        $sendNotification,
                        'This order cannot be marked paid. Cancelled, completed, or refunded payments have to stay settled.'
                    );
                }
            }

            if ($oldStatus === 'paid' && $newStatus === 'pending') {
                return $this->abortPaymentUpdate(
                    (int) $id,
                    $sendNotification,
                    'A paid payment cannot be moved back to pending. Mark it failed or refunded instead.'
                );
            }

            $order->payment_status = $newStatus;

            if ($notes !== '' && Schema::hasColumn('orders', 'admin_notes')) {
                $order->admin_notes = $notes;
            }
            if ($paymentReference !== '' && Schema::hasColumn('orders', 'payment_reference')) {
                $order->payment_reference = $paymentReference;
            }

            if ($request->payment_status === 'paid' && ! $order->paid_at) {
                $order->paid_at = now();
            }

            $refundAmount = 0.0;
            if ($request->payment_status === 'refunded' && $oldStatus === 'paid') {
                if ($order->status === 'completed') {
                    return $this->abortPaymentUpdate(
                        (int) $id,
                        $sendNotification,
                        'Completed orders cannot be refunded here. Use a dispute clawback so the publisher payout is reversed first.'
                    );
                }

                $refundAmount = $this->creditAdvertiserRefund($order);
                if ($order->status !== 'cancelled') {
                    $order->status = 'cancelled';
                }
                ContentSubmission::releaseAllForOrder((int) $order->id);
            }

            if ($request->payment_status === 'failed' && $oldStatus === 'paid') {
                if ($order->status === 'completed') {
                    return $this->abortPaymentUpdate(
                        (int) $id,
                        $sendNotification,
                        'Completed orders cannot be marked failed here. Use a dispute clawback so the publisher payout is reversed first.'
                    );
                }

                if ($order->payment_method === 'wallet') {
                    $refundAmount = $this->releaseWalletHoldOnAdminFailed($order);
                } elseif (! in_array((string) $order->status, ['cancelled', 'completed'], true)) {
                    // Collected card / bank / Wise: credit the advertiser wallet
                    // the same way Refunded does. Failed used to cancel the
                    // placement and release the article with €0 in-app credit.
                    $refundAmount = $this->creditAdvertiserRefund($order);
                    $order->status = 'cancelled';
                }

                if ($order->status === 'cancelled') {
                    ContentSubmission::releaseAllForOrder((int) $order->id);
                }

                $order->paid_at = null;
            }

            $order->save();

            Log::info('Payment status updated by admin', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'old_status' => $oldStatus,
                'new_status' => $request->payment_status,
                'admin_id' => auth()->id(),
                'notes' => $notes !== '' ? $notes : null,
                'payment_reference' => $paymentReference !== '' ? $paymentReference : null,
            ]);

            // Send email notification to user when payment is marked as paid.
            // Do not consume checkout bonus here — Stripe mark-paid also leaves
            // bonus_reserved until approve/reject so a later refund cannot mint
            // promo as withdrawable cash.
            if ($request->payment_status === 'paid' && $oldStatus !== 'paid') {
                // Keep leftover checkout bonus reserved until approve/reject,
                // matching Stripe finalize. Consuming here minted promo as cash
                // if the publisher later rejected the placement.
                if ($sendNotification) {
                    $this->sendPaymentConfirmationEmail($order);
                }
            }

            // Unpaid failure: release this line's leftover checkout bonus.
            // Paid failures already restored promo via creditAdvertiserRefund /
            // releaseWalletHoldOnAdminFailed — do not dump the sibling share.
            if ($request->payment_status === 'failed' && $oldStatus !== 'failed' && $oldStatus !== 'paid') {
                $this->refundReservedCheckoutBonus($order);
            }

            DB::commit();

            $fresh = $order->fresh(['items']);
            $notifications = app(InAppNotificationService::class);

            if ($request->payment_status === 'paid' && $oldStatus !== 'paid') {
                app(OrderPaymentService::class)->notifyPublishersOfPaidOrders([$fresh]);
                if ($fresh->user) {
                    try {
                        app(SpendBudgetService::class)->evaluate($fresh->user);
                    } catch (\Throwable $e) {
                        Log::warning('Spend budget evaluate after admin mark-paid failed: '.$e->getMessage());
                    }
                }
            }

            if ($sendNotification && $request->payment_status === 'failed' && $oldStatus !== 'failed') {
                $notifications->notifyPaymentFailed([$fresh], $notes !== '' ? $notes : $request->notes);
                if ($refundAmount > 0) {
                    $notifications->notifyRefundCredited(
                        $fresh,
                        $refundAmount,
                        $notes !== '' ? $notes : 'Admin marked payment failed'
                    );
                }
            }

            if ($sendNotification && $request->payment_status === 'refunded' && $oldStatus !== 'refunded' && $refundAmount > 0) {
                $notifications->notifyRefundCredited(
                    $fresh,
                    $refundAmount,
                    $notes !== '' ? $notes : 'Admin refund'
                );
            }

            ActivityLogger::log(
                'payment.status_updated',
                auth()->user()->name.' set payment for order '.$order->order_number.' to '.$request->payment_status,
                $order,
                [
                    'from' => $oldStatus,
                    'to' => $request->payment_status,
                    'notes' => $notes !== '' ? $notes : null,
                    'payment_reference' => $paymentReference !== '' ? $paymentReference : null,
                    'refund_amount' => $refundAmount > 0 ? $refundAmount : null,
                ],
                $order->order_number
            );

            return response()->json([
                'success' => true,
                'message' => 'Payment status updated successfully',
                'data' => [
                    'payment_status' => $order->payment_status,
                    'paid_at' => $order->paid_at,
                    'admin_notes' => $order->admin_notes,
                    'payment_reference' => $order->payment_reference,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            if (! $sendNotification) {
                app(OrderLifecycleMailSuppressor::class)->forget((int) $id);
            }
            Log::error('Error updating payment status: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to update payment status. Please try again.'),
            ], 500);
        } finally {
            if (! $sendNotification) {
                $billingSuppressor->disable();
            }
        }
    }

    /**
     * Send payment confirmation email to user.
     * Prefer the PDF tax-invoice mail (BillingDocumentService). Skip the legacy
     * OrderPaymentConfirmed when that invoice mail already went out — otherwise
     * admins marking paid trigger a double email.
     */
    private function sendPaymentConfirmationEmail($order)
    {
        try {
            $order = $order->fresh(['user', 'items']) ?: $order;

            $invoice = Invoice::query()
                ->where('order_id', $order->id)
                ->where('type', Invoice::TYPE_TAX_INVOICE)
                ->where('status', '!=', Invoice::STATUS_CANCELLED)
                ->latest('id')
                ->first();

            if (! $invoice) {
                $invoice = app(BillingDocumentService::class)->handlePaymentPaid($order);
            }

            if ($invoice && $invoice->emailed_at) {
                Log::info('Skipping legacy payment confirmation — PDF invoice already emailed', [
                    'order_id' => $order->id,
                    'invoice_id' => $invoice->id,
                ]);

                return;
            }

            if ($invoice && ! $invoice->emailed_at) {
                app(BillingDocumentService::class)->resendInvoiceEmail($invoice);

                return;
            }

            $user = $order->user;

            if ($user && $user->email) {
                Mail::to($user->email)->send(new OrderPaymentConfirmed($order));
                Log::info('Payment confirmation email sent to user', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'user_email' => $user->email,
                ]);
            } else {
                Log::warning('Cannot send payment confirmation - no user email', [
                    'order_id' => $order->id,
                    'user_id' => $order->user_id,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to send payment confirmation email: '.$e->getMessage());
        }
    }

    private function refundReservedCheckoutBonus(Order $order): void
    {
        app(OrderRefundService::class)->releaseReservedCheckoutBonus($order);
    }

    /**
     * Paid wallet orders keep cash/bonus in reserved_balance until approve/reject.
     * Admin "failed" used to flip payment_status only, leaving the hold locked
     * and Approve still able to pay the publisher from that reserved bucket.
     */
    private function releaseWalletHoldOnAdminFailed(Order $order): float
    {
        if (in_array((string) $order->status, ['completed', 'cancelled'], true)) {
            return 0.0;
        }

        $amount = round((float) $order->total_amount, 2);
        if ($amount <= 0) {
            if ($order->status !== 'cancelled') {
                $order->status = 'cancelled';
            }

            return 0.0;
        }

        $advertiserRoleId = Wallet::advertiserRoleId();
        if (! $advertiserRoleId) {
            throw new \RuntimeException('Advertiser role not configured');
        }

        $wallet = Wallet::lockOrCreateForRole($order->user_id, $advertiserRoleId);
        $reservedBefore = round((float) $wallet->reserved_balance, 2);
        if ($reservedBefore <= 0) {
            if ($order->status !== 'cancelled') {
                $order->status = 'cancelled';
            }

            return 0.0;
        }

        app(OrderRefundService::class)->refundToAdvertiser(
            $order,
            $amount,
            'Admin marked payment failed'
        );
        $wallet->refresh();
        $refunded = max(0, round($reservedBefore - (float) $wallet->reserved_balance, 2));

        if ($order->status !== 'cancelled') {
            $order->status = 'cancelled';
        }

        return $refunded;
    }

    /**
     * Credit the advertiser wallet when admin marks a paid order as refunded.
     * Uses the full order total (tax / surcharges included), not a line helper.
     */
    private function creditAdvertiserRefund(Order $order): float
    {
        $order->loadMissing('items');
        $amount = app(OrderRefundService::class)->resolveOrderCancelRefundAmount($order);
        if ($amount <= 0) {
            return 0.0;
        }

        app(OrderRefundService::class)->refundToAdvertiser($order, $amount, 'Admin refund');

        return $amount;
    }

    /**
     * @param  Collection<int, Order>  $orders
     */
    private function attachInvoiceDocuments($orders): void
    {
        $links = app(AdminInvoiceLinks::class);
        $byOrder = $links->forOrders($orders);

        foreach ($orders as $order) {
            $documents = $byOrder->get((int) $order->id, []);
            $primary = $links->primary($documents);
            $order->setAttribute('invoice_documents', $documents);
            $order->setAttribute('invoice', $primary);
            $order->setAttribute('invoice_url', data_get($primary, 'url'));
        }
    }

    /**
     * Map jQuery/form truthy strings onto real booleans before the boolean rule.
     */
    private function mergeJqueryBoolean(Request $request, string $key): void
    {
        if (! $request->exists($key) || ! is_string($request->input($key))) {
            return;
        }

        $raw = strtolower(trim((string) $request->input($key)));
        if (! in_array($raw, ['true', 'false', 'on', 'off', 'yes', 'no'], true)) {
            return;
        }

        $request->merge([
            $key => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
        ]);
    }

    /**
     * @return Builder<Order>
     */
    private function paymentsQuery(Request $request): Builder
    {
        $query = Order::query()->with('user:id,name,email')->orderBy('created_at', 'desc');

        $search = is_string($request->input('search')) ? trim($request->input('search')) : '';
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                    ->orWhere('reference_code', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($sub) use ($search) {
                        $sub->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
                if (Schema::hasColumn('orders', 'payment_reference')) {
                    $q->orWhere('payment_reference', 'like', "%{$search}%");
                }
            });
        }

        $paymentStatus = is_string($request->input('payment_status')) ? $request->input('payment_status') : '';
        if ($paymentStatus === 'unpaid') {
            $query->unpaidOps();
        } elseif ($paymentStatus !== '') {
            $query->where('payment_status', $paymentStatus);
        }

        $paymentMethod = is_string($request->input('payment_method')) ? $request->input('payment_method') : '';
        if ($paymentMethod !== '') {
            $query->where('payment_method', $paymentMethod);
        }

        $orderStatus = is_string($request->input('status')) ? $request->input('status') : '';
        if ($orderStatus !== '') {
            $query->where('status', $orderStatus);
        }

        $dates = validator(
            [
                'date_from' => is_string($request->input('date_from')) ? $request->input('date_from') : null,
                'date_to' => is_string($request->input('date_to')) ? $request->input('date_to') : null,
                'date_field' => is_string($request->input('date_field')) ? $request->input('date_field') : 'created_at',
            ],
            [
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date|after_or_equal:date_from',
                'date_field' => 'nullable|in:created_at,paid_at',
            ]
        )->valid();

        $dateField = ($dates['date_field'] ?? 'created_at') === 'paid_at' ? 'paid_at' : 'created_at';
        if (! empty($dates['date_from'])) {
            $query->whereDate($dateField, '>=', $dates['date_from']);
        }
        if (! empty($dates['date_to'])) {
            $query->whereDate($dateField, '<=', $dates['date_to']);
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePaymentRow(Order $order): array
    {
        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'reference_code' => $order->reference_code,
            'total_amount' => (float) $order->total_amount,
            'payment_method' => $order->payment_method,
            'payment_status' => $order->payment_status,
            'status' => $order->status,
            'paid_at' => $order->paid_at?->toIso8601String(),
            'created_at' => $order->created_at?->toIso8601String(),
            'admin_notes' => $order->admin_notes,
            'payment_reference' => $order->payment_reference,
            'user' => $order->user ? [
                'id' => $order->user->id,
                'name' => $order->user->name,
                'email' => $order->user->email,
            ] : null,
            'allowed_statuses' => $this->allowedPaymentStatuses($order),
            'invoices' => $order->getAttribute('invoice_documents') ?? [],
            'invoice' => $order->getAttribute('invoice'),
            'invoice_url' => $order->getAttribute('invoice_url'),
        ];
    }

    /**
     * @return list<string>
     */
    private function allowedPaymentStatuses(Order $order): array
    {
        $current = (string) $order->payment_status;

        if ($current === 'refunded') {
            return [];
        }

        if ($current === 'paid') {
            if (in_array((string) $order->status, ['completed'], true)) {
                return [];
            }

            return ['failed', 'refunded'];
        }

        $allowed = ['pending', 'paid', 'failed'];
        if (in_array((string) $order->status, ['cancelled', 'completed'], true)) {
            $allowed = array_values(array_diff($allowed, ['paid']));
        }

        return $allowed;
    }

    private function disallowedStatusMessage(Order $order, string $newStatus): string
    {
        if ($order->payment_status === 'paid' && $order->status === 'completed') {
            if ($newStatus === 'refunded') {
                return 'Completed orders cannot be refunded here. Use a dispute clawback so the publisher payout is reversed first.';
            }
            if ($newStatus === 'failed') {
                return 'Completed orders cannot be marked failed here. Use a dispute clawback so the publisher payout is reversed first.';
            }

            return 'Completed orders cannot be changed here. Use a dispute clawback so the publisher payout is reversed first.';
        }

        if ($order->payment_status === 'paid' && $newStatus === 'pending') {
            return 'A paid payment cannot be moved back to pending. Mark it failed or refunded instead.';
        }

        if ($newStatus === 'paid') {
            return 'This order cannot be marked paid. Cancelled, completed, or refunded payments have to stay settled.';
        }

        return 'That payment status change is not allowed for this order.';
    }

    /**
     * Hostinger deploys often skip migrate. Search/update must not 500
     * when admin_notes / payment_reference are still missing.
     */
    private function ensurePaymentColumns(): void
    {
        if (Schema::hasColumn('orders', 'admin_notes')
            && Schema::hasColumn('orders', 'payment_reference')) {
            return;
        }

        app(CheckoutSchemaService::class)->ensureCheckoutTables();
    }

    private function abortPaymentUpdate(int $orderId, bool $sendNotification, string $message)
    {
        DB::rollBack();
        if (! $sendNotification) {
            app(OrderLifecycleMailSuppressor::class)->forget($orderId);
        }

        return response()->json([
            'success' => false,
            'message' => $message,
        ], 422);
    }
}
