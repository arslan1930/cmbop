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
use App\Services\CheckoutIntentService;
use App\Services\CheckoutSchemaService;
use App\Services\InAppNotificationService;
use App\Services\OrderPaymentService;
use App\Services\Orders\AdminPaymentStatusPolicy;
use App\Services\Orders\OrderRefundService;
use App\Support\BillingCustomerMailSuppressor;
use App\Support\OrderLifecycleMailSuppressor;
use App\Support\UserFacingError;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
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

    public function __construct(private AdminPaymentStatusPolicy $paymentStatuses) {}

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
            if (! Schema::hasTable('orders')) {
                return $this->emptyPaymentsPayload($request);
            }
        } catch (\Throwable) {
            return $this->emptyPaymentsPayload($request);
        }

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

        } catch (\Throwable $e) {
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
        try {
            $rows = $this->paymentsQuery($request)->limit(self::EXPORT_LIMIT)->get();
        } catch (\Throwable $e) {
            Log::warning('Admin payments export query failed', [
                'error' => $e->getMessage(),
            ]);
            $rows = collect();
        }
        $filename = 'order-payments-'.now()->format('Y-m-d-His').'.csv';

        ActivityLogger::tryLog(
            'payment.exported',
            ($request->user()?->name ?? 'Admin').' exported order payments ('.$rows->count().' row(s)).',
            null,
            [
                'search' => is_string($request->input('search')) ? trim($request->input('search')) : '',
                'payment_status' => is_string($request->input('payment_status')) ? $request->input('payment_status') : '',
                'payment_method' => is_string($request->input('payment_method')) ? $request->input('payment_method') : '',
                'status' => is_string($request->input('status')) ? $request->input('status') : '',
                'date_from' => is_string($request->input('date_from')) ? $request->input('date_from') : null,
                'date_to' => is_string($request->input('date_to')) ? $request->input('date_to') : null,
                'date_field' => is_string($request->input('date_field')) ? $request->input('date_field') : 'created_at',
                'rows_exported' => $rows->count(),
                'truncated' => $rows->count() >= self::EXPORT_LIMIT,
            ]
        );

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
                    $this->csvCell($order->order_number),
                    $this->csvCell($order->reference_code),
                    $this->csvCell($order->user?->name),
                    $this->csvCell($order->user?->email),
                    number_format((float) $order->total_amount, 2, '.', ''),
                    $this->csvCell($order->payment_method),
                    $this->csvCell($order->payment_status),
                    $this->csvCell($order->status),
                    $this->csvCell($order->payment_reference),
                    $this->csvCell($order->admin_notes),
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
            $order = Order::with(['user'])->findOrFail($id);
            $this->attachInvoiceDocuments(collect([$order]));

            return response()->json([
                'success' => true,
                'data' => $this->serializePaymentRow($order),
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Payment not found',
            ], 404);
        } catch (\Throwable $e) {
            Log::warning('Admin payment show failed', [
                'payment_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to load payment. Please try again.'),
            ], 500);
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

        $requestedStatus = (string) $request->payment_status;
        if (in_array($requestedStatus, ['refunded', 'failed'], true)) {
            $paypalPreview = Order::query()->find($id);
            if ($paypalPreview instanceof Order
                && $paypalPreview->payment_status === 'paid'
                && $paypalPreview->payment_method === 'paypal'
                && ! in_array((string) $paypalPreview->status, ['cancelled', 'completed'], true)
            ) {
                try {
                    $paypalAmount = app(OrderRefundService::class)
                        ->resolveOrderCancelRefundAmount($paypalPreview);
                    app(OrderRefundService::class)
                        ->refundPaypalCaptureIfPossible($paypalPreview, $paypalAmount);
                } catch (\Throwable $e) {
                    Log::error('Admin PayPal refund API failed', [
                        'order_id' => $id,
                        'error' => $e->getMessage(),
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => UserFacingError::message(
                            $e,
                            'PayPal refund failed. The wallet was not credited.'
                        ),
                    ], 422);
                }
            }
        }
        if ($requestedStatus === 'paid') {
            $preview = Order::query()->with('user')->find($id);
            if ($preview instanceof Order
                && $preview->payment_status !== 'paid'
                && $preview->payment_status !== 'refunded'
                && ! in_array((string) $preview->status, ['cancelled', 'completed'], true)
            ) {
                // Scan before the payment TX. abortPaymentUpdate() rolls back, so a
                // reject written inside that TX would leave the library row approved.
                $libraryState = app(OrderPaymentService::class)->libraryContentStateForSettlement($preview);
                if ($libraryState !== 'ok') {
                    return response()->json([
                        'success' => false,
                        'message' => $this->libraryUnreadyForMarkPaidMessage($libraryState),
                    ], 422);
                }
            }
        }

        try {
            if (! $sendNotification) {
                app(OrderLifecycleMailSuppressor::class)->suppress((int) $id, ['advertiser']);
                $billingSuppressor->enable();
            }

            DB::beginTransaction();

            $order = Order::with('user')->where('id', $id)->lockForUpdate()->firstOrFail();

            $oldStatus = $order->payment_status;
            $newStatus = (string) $request->payment_status;

            if (! in_array($newStatus, $this->paymentStatuses->allowedStatuses($order), true)) {
                return $this->abortPaymentUpdate(
                    (int) $id,
                    $sendNotification,
                    $this->paymentStatuses->disallowedMessage($order, $newStatus)
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
                if (! $order->hasCatalogVisibleFulfillment()) {
                    return $this->abortPaymentUpdate(
                        (int) $id,
                        $sendNotification,
                        'This order cannot be marked paid. The listing left the catalog and is no longer fulfillable.'
                    );
                }

                $payments = app(OrderPaymentService::class);
                // Late card capture credits the wallet and leaves the leftover
                // failed when promo cannot be re-reserved. Mark-paid would
                // hand them the placement on top of that credit.
                if ($order->payment_method === 'card') {
                    $alreadyCredited = $payments->unfulfilledCardCreditAmount(
                        (string) ($order->reference_code ?? '')
                    );
                    if ($alreadyCredited > 0.009) {
                        return $this->abortPaymentUpdate(
                            (int) $id,
                            $sendNotification,
                            sprintf(
                                'This leftover already credited €%s to the advertiser wallet after the card charge could not settle. Marking it paid would give them the placement on top of that credit. Ask them to finish leftover checkout (wallet can use the credit), or adjust the wallet first.',
                                number_format($alreadyCredited, 2, '.', '')
                            )
                        );
                    }
                }
                $libraryState = $payments->libraryContentStateForSettlement($order);
                if ($libraryState !== 'ok') {
                    return $this->abortPaymentUpdate(
                        (int) $id,
                        $sendNotification,
                        $this->libraryUnreadyForMarkPaidMessage($libraryState)
                    );
                }
                $payments->refreshOrderItemLibraryFields($order);
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

            if ($request->payment_status === 'paid'
                && ! $order->paid_at
                && $this->ordersHaveColumn('paid_at')) {
                $order->paid_at = now();
            }

            $refundAmount = 0.0;
            // Failed-from-paid already credited captured methods. A later
            // refunded label must not pay the advertiser a second time.
            if ($newStatus === 'refunded' && $oldStatus === 'paid') {
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
                } else {
                    // Collected card / bank / Wise: credit the advertiser wallet
                    // the same way Refunded does. Failed used to skip already-
                    // cancelled paid rows and leave captured money uncredited.
                    $refundAmount = $this->creditAdvertiserRefund($order);
                    if ($order->status !== 'cancelled') {
                        $order->status = 'cancelled';
                    }
                }

                if ($order->status === 'cancelled') {
                    ContentSubmission::releaseAllForOrder((int) $order->id);
                }

                if ($this->ordersHaveColumn('paid_at')) {
                    $order->paid_at = null;
                }
            }

            // Unpaid failure: release leftover checkout bonus and cancel
            // non-card methods in this same save so lifecycle mail sees both
            // payment_status + status with the same suppressor snapshot.
            if ($newStatus === 'failed' && $oldStatus !== 'failed' && $oldStatus !== 'paid') {
                $this->refundReservedCheckoutBonus($order);
                if ($order->payment_method !== 'card') {
                    if ($order->status !== 'cancelled') {
                        $order->status = 'cancelled';
                    }
                    ContentSubmission::releaseAllForOrder((int) $order->id);
                }
            }

            $order->save();

            if ($newStatus === 'paid' && $oldStatus !== 'paid') {
                $payments = app(OrderPaymentService::class);
                $reference = (string) ($order->reference_code ?? '');
                $bonusApplied = $payments->leftoverBonusToRereserve($order);
                // Fail/cancel already returned this leftover's promo to
                // bonus_balance. Mark-paid without re-reserving made reject
                // credit that slice as withdrawable cash. The purchase-ledger
                // helper is 0 while another checkout is open — still use this
                // leftover's snapshot so we re-reserve OUR promo, not skip it.
                $payments->rereserveReleasedCheckoutBonus(
                    (int) $order->user_id,
                    $reference,
                    $bonusApplied
                );
                $bonusApplied = $payments->leftoverBonusForPurchaseLedger($order);
                $payments->recordAdvertiserPurchaseForPaidCheckout(
                    $reference,
                    collect([$order->fresh(['items']) ?: $order]),
                    $bonusApplied,
                    (float) $order->total_amount
                );
            }

            Log::info('Payment status updated by admin', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'old_status' => $oldStatus,
                'new_status' => $request->payment_status,
                'admin_id' => auth()->id(),
                'notes' => $notes !== '' ? $notes : null,
                'payment_reference' => $paymentReference !== '' ? $paymentReference : null,
            ]);

            DB::commit();

            try {
                $this->runPostCommitPaymentSideEffects(
                    $order,
                    (string) $oldStatus,
                    $newStatus,
                    $sendNotification,
                    $notes,
                    $paymentReference,
                    $refundAmount
                );
            } catch (\Throwable $e) {
                Log::error('Post-commit payment side effects failed: '.$e->getMessage(), [
                    'order_id' => $order->id,
                    'from' => $oldStatus,
                    'to' => $newStatus,
                ]);
            }

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

        } catch (ModelNotFoundException $e) {
            DB::rollBack();
            if (! $sendNotification) {
                app(OrderLifecycleMailSuppressor::class)->forget((int) $id);
            }

            return response()->json([
                'success' => false,
                'message' => 'Payment not found',
            ], 404);
        } catch (\RuntimeException $e) {
            DB::rollBack();
            if (! $sendNotification) {
                app(OrderLifecycleMailSuppressor::class)->forget((int) $id);
            }
            Log::warning('Payment status update blocked on leftover schema: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Could not update payment status on this database.'),
            ], 422);
        } catch (\Throwable $e) {
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

            $invoice = Invoice::tableAvailable()
                ? Invoice::query()
                    ->where('order_id', $order->id)
                    ->where('type', Invoice::TYPE_TAX_INVOICE)
                    ->where('status', '!=', Invoice::STATUS_CANCELLED)
                    ->latest('id')
                    ->first()
                : null;

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
        } catch (\Throwable $e) {
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
        try {
            return $this->releaseWalletHoldOnAdminFailedInner($order);
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (QueryException $e) {
            throw new \RuntimeException('Cannot credit the advertiser wallet on this database.', 0, $e);
        }
    }

    private function releaseWalletHoldOnAdminFailedInner(Order $order): float
    {
        if ((string) $order->status === 'completed') {
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
            'Admin marked payment failed',
            $this->bonusShareCapForRefund($order)
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
        try {
            return $this->creditAdvertiserRefundInner($order);
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (QueryException $e) {
            throw new \RuntimeException('Cannot credit the advertiser wallet on this database.', 0, $e);
        }
    }

    private function creditAdvertiserRefundInner(Order $order): float
    {
        $order->loadMissing('items');
        $amount = app(OrderRefundService::class)->resolveOrderCancelRefundAmount($order);
        if ($amount <= 0) {
            return 0.0;
        }

        $reservedBefore = 0.0;
        $wallet = null;
        if ($order->payment_method === 'wallet') {
            $advertiserRoleId = Wallet::advertiserRoleId();
            if ($advertiserRoleId) {
                $wallet = Wallet::lockOrCreateForRole($order->user_id, $advertiserRoleId);
                $reservedBefore = round((float) $wallet->reserved_balance, 2);
            }
        }

        app(OrderRefundService::class)->refundToAdvertiser(
            $order,
            $amount,
            'Admin refund',
            $this->bonusShareCapForRefund($order)
        );

        if ($order->payment_method === 'wallet') {
            $wallet?->refresh();

            return max(0, round($reservedBefore - (float) ($wallet?->reserved_balance ?? 0), 2));
        }

        return $amount;
    }

    /**
     * Wallet holds already contain promo — a missing intent must not cap the
     * share at 0 (that restores the hold as cash and then burns bonus_reserved).
     * Card leftover still passes peek including 0 so we cannot steal another
     * in-flight checkout's reserved bonus.
     */
    private function bonusShareCapForRefund(Order $order): ?float
    {
        $held = app(CheckoutIntentService::class)->heldBonus(
            (int) $order->user_id,
            (string) $order->reference_code
        );

        if ($order->payment_method === 'wallet') {
            return $held > 0 ? $held : null;
        }

        return app(OrderRefundService::class)->cardLeftoverBonusCap(
            (int) $order->user_id,
            (string) $order->reference_code
        );
    }

    /**
     * Notifications / invoices / audit must not 500 after money already moved.
     */
    private function runPostCommitPaymentSideEffects(
        Order $order,
        string $oldStatus,
        string $newStatus,
        bool $sendNotification,
        string $notes,
        string $paymentReference,
        float $refundAmount
    ): void {
        // Keep leftover checkout bonus reserved until approve/reject,
        // matching Stripe finalize. Consuming here minted promo as cash
        // if the publisher later rejected the placement.
        if ($newStatus === 'paid' && $oldStatus !== 'paid' && $sendNotification) {
            $this->sendPaymentConfirmationEmail($order);
        }

        $fresh = $order->fresh(['items']) ?: $order;
        $notifications = app(InAppNotificationService::class);

        if ($newStatus === 'paid' && $oldStatus !== 'paid') {
            app(OrderPaymentService::class)->notifyPublishersOfPaidOrders([$fresh]);
            if ($fresh->user) {
                try {
                    app(SpendBudgetService::class)->evaluate($fresh->user);
                } catch (\Throwable $e) {
                    Log::warning('Spend budget evaluate after admin mark-paid failed: '.$e->getMessage());
                }
            }
        }

        if ($sendNotification && $newStatus === 'failed' && $oldStatus !== 'failed') {
            if ($refundAmount > 0) {
                $notifications->notifyRefundCredited(
                    $fresh,
                    $refundAmount,
                    $notes !== '' ? $notes : 'Admin marked payment failed'
                );
            } else {
                $notifications->notifyPaymentFailed([$fresh], $notes !== '' ? $notes : null);
            }
        }

        if ($sendNotification && $newStatus === 'refunded' && $oldStatus !== 'refunded' && $refundAmount > 0) {
            $notifications->notifyRefundCredited(
                $fresh,
                $refundAmount,
                $notes !== '' ? $notes : 'Admin refund'
            );
        }

        // Notes / reference saves keep payment_status the same on purpose.
        // Do not write payment.status_updated for those — it looks like a money move.
        if ($oldStatus === $newStatus) {
            return;
        }

        ActivityLogger::tryLog(
            'payment.status_updated',
            (auth()->user()?->name ?? 'Admin').' set payment for order '.$order->order_number.' to '.$newStatus,
            $order,
            [
                'from' => $oldStatus,
                'to' => $newStatus,
                'notes' => $notes !== '' ? $notes : null,
                'payment_reference' => $paymentReference !== '' ? $paymentReference : null,
                'refund_amount' => $refundAmount > 0 ? $refundAmount : null,
            ],
            $order->order_number
        );
    }

    /**
     * @param  Collection<int, Order>  $orders
     */
    private function attachInvoiceDocuments(Collection $orders): void
    {
        $links = app(AdminInvoiceLinks::class);
        $byOrder = $links->forOrders($orders);

        foreach ($orders as $order) {
            $documents = $byOrder->get((int) $order->id, []);
            $order->setAttribute('invoice_documents', $documents);
            $primary = $links->primary($documents);
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
        if ($orderStatus === 'scheduled') {
            // Live scheduled rows keep status=pending and store the slot on
            // publication_mode — the same trap the orders console already fixed.
            $query->awaitingScheduledRelease();
        } elseif ($orderStatus !== '') {
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
        if ($dateField === 'paid_at' && ! $this->ordersHaveColumn('paid_at')) {
            $dateField = 'created_at';
        }
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
            'allowed_statuses' => $this->paymentStatuses->allowedStatuses($order),
            'invoice_url' => $order->invoice_url ?? null,
            'invoice_documents' => $order->invoice_documents ?? [],
        ];
    }

    private function emptyPaymentsPayload(Request $request)
    {
        $perPage = max(1, min(100, (int) $request->input('per_page', 20)));

        return response()->json([
            'success' => true,
            'data' => [],
            'pagination' => [
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => $perPage,
                'total' => 0,
                'from' => null,
                'to' => null,
            ],
            'summary' => [
                'unpaid_count' => 0,
                'unpaid_amount' => 0.0,
            ],
        ]);
    }

    /**
     * Neutralize spreadsheet formula injection in admin CSV exports.
     */
    private function csvCell(mixed $value): string
    {
        $text = (string) ($value ?? '');
        if ($text !== '' && preg_match('/^[=+\-@\t\r]/', $text)) {
            return "'".$text;
        }

        return $text;
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

    private function ordersHaveColumn(string $column): bool
    {
        try {
            return Schema::hasColumn('orders', $column);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  'missing'|'unready'|'taken'  $state
     */
    private function libraryUnreadyForMarkPaidMessage(string $state): string
    {
        return match ($state) {
            'taken' => 'This order cannot be marked paid. The Content Library article is already used on another order.',
            'missing' => 'This order cannot be marked paid. The Content Library article is missing.',
            default => 'This order cannot be marked paid. The Content Library article is no longer ready for checkout.',
        };
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
