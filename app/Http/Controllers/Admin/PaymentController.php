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
use App\Services\Admin\FinanceOverviewService;
use App\Services\Advertiser\SpendBudgetService;
use App\Services\Billing\AdminInvoiceLinks;
use App\Services\Billing\BillingDocumentService;
use App\Services\CheckoutIntentService;
use App\Services\CheckoutSchemaService;
use App\Services\InAppNotificationService;
use App\Services\OrderPaymentService;
use App\Services\Orders\OrderRefundService;
use App\Support\BillingCustomerMailSuppressor;
use App\Support\OrderLifecycleMailSuppressor;
use App\Support\UserFacingError;
use Carbon\Carbon;
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

    /** @var list<\Closure> */
    private array $deferredPaymentSideEffects = [];

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
            $unpaid = Order::query()->unpaidOps();
            $dateError = null;
            $filtered = $this->paymentsQuery($request, $dateError);
            $totals = $this->paymentFilterTotals($filtered);
            $perPage = (int) $request->input('per_page', 20);
            $perPage = max(1, min(100, $perPage));
            $orders = $this->applyPaymentSort($filtered, $request)->paginate($perPage);
            $this->attachInvoiceDocuments($orders->getCollection());

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
                'summary' => $this->unpaidSummary($unpaid),
                'totals' => $totals,
                'date_error' => $dateError,
                'export_limited' => ($totals['count'] ?? 0) > self::EXPORT_LIMIT,
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
        $dateError = null;
        try {
            $rows = $this->applyPaymentSort($this->paymentsQuery($request, $dateError), $request)
                ->limit(self::EXPORT_LIMIT)
                ->get();
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
                'completed_at',
                'created_at',
                'charge_currency',
                'charge_amount',
                'stripe_session_id',
                'paypal_order_id',
                'paypal_capture_id',
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
                    optional($order->completed_at)->toDateTimeString(),
                    optional($order->created_at)->toDateTimeString(),
                    $this->csvCell($order->charge_currency),
                    $order->charge_amount !== null ? number_format((float) $order->charge_amount, 2, '.', '') : '',
                    $this->csvCell($order->stripe_session_id),
                    $this->csvCell($order->paypal_order_id),
                    $this->csvCell($order->paypal_capture_id),
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
                try {
                    $libraryState = app(OrderPaymentService::class)->libraryContentStateForSettlement($preview);
                } catch (\Throwable $e) {
                    report($e);

                    return response()->json([
                        'success' => false,
                        'message' => 'Could not check the Content Library article for this order.',
                    ], 422);
                }
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

            $effect = function () use ($order, $oldStatus, $newStatus, $sendNotification, $notes, $paymentReference, $refundAmount) {
                $billingSuppressor = app(BillingCustomerMailSuppressor::class);
                if (! $sendNotification) {
                    $billingSuppressor->enable();
                }
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
                } finally {
                    if (! $sendNotification) {
                        $billingSuppressor->disable();
                    }
                }
            };
            if (DB::transactionLevel() > 0) {
                $this->deferredPaymentSideEffects[] = $effect;
            } else {
                $effect();
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
    private function paymentsQuery(Request $request, ?string &$dateError = null): Builder
    {
        $query = Order::query()->with([
            'user:id,name,email',
            'items:id,order_id,site_name',
        ]);

        if ($request->boolean('finance')) {
            $fromRaw = is_string($request->input('date_from')) ? trim($request->input('date_from')) : '';
            $toRaw = is_string($request->input('date_to')) ? trim($request->input('date_to')) : '';
            $fromOk = $fromRaw === '' || $this->isPaymentDay($fromRaw);
            $toOk = $toRaw === '' || $this->isPaymentDay($toRaw);
            if (! $fromOk || ! $toOk) {
                $dateError = 'Enter real dates.';
                $query->whereRaw('0 = 1');

                return $query;
            }
            if ($fromRaw !== '' && $toRaw !== '' && $toRaw < $fromRaw) {
                $dateError = 'The to date must be on or after the from date.';
                $query->whereRaw('0 = 1');

                return $query;
            }
            app(FinanceOverviewService::class)->applyGmvWindow(
                $query,
                $fromRaw !== '' ? $fromRaw : null,
                $toRaw !== '' ? $toRaw : null
            );

            return $query;
        }

        $search = is_string($request->input('search')) ? trim($request->input('search')) : '';
        $needle = str_replace(['\\', '%', '_'], '', $search);
        if ($search !== '' && $needle === '') {
            $query->whereRaw('0 = 1');
        } elseif ($search !== '') {
            $like = like_contains($search);
            $query->where(function ($q) use ($like, $search) {
                $q->whereRaw('order_number LIKE ? ESCAPE ?', [$like, '\\'])
                    ->orWhereRaw('reference_code LIKE ? ESCAPE ?', [$like, '\\']);
                if (ctype_digit($search) && (string) (int) $search === $search) {
                    $q->orWhere('id', (int) $search);
                }
                $q->orWhereHas('user', function ($sub) use ($like) {
                    $sub->whereRaw('name LIKE ? ESCAPE ?', [$like, '\\'])
                        ->orWhereRaw('email LIKE ? ESCAPE ?', [$like, '\\']);
                    if (Schema::hasColumn('users', 'company_name')) {
                        $sub->orWhereRaw('company_name LIKE ? ESCAPE ?', [$like, '\\']);
                    }
                });
                $q->orWhereHas('items', function ($sub) use ($like) {
                    $sub->whereRaw('site_name LIKE ? ESCAPE ?', [$like, '\\']);
                });
                foreach (['payment_reference', 'stripe_session_id', 'paypal_order_id', 'paypal_capture_id'] as $column) {
                    if (Schema::hasColumn('orders', $column)) {
                        $q->orWhereRaw($column.' LIKE ? ESCAPE ?', [$like, '\\']);
                    }
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
        if ($paymentMethod === 'card') {
            $query->whereIn('payment_method', ['card', 'stripe']);
        } elseif ($paymentMethod === 'bank') {
            $query->whereIn('payment_method', ['bank', 'bank_transfer']);
        } elseif ($paymentMethod !== '') {
            $query->where('payment_method', $paymentMethod);
        }

        $orderStatus = is_string($request->input('status')) ? $request->input('status') : '';
        if ($orderStatus === 'scheduled') {
            $query->awaitingScheduledRelease();
        } elseif ($orderStatus !== '') {
            $query->where('status', $orderStatus);
        }

        $fromRaw = is_string($request->input('date_from')) ? trim($request->input('date_from')) : '';
        $toRaw = is_string($request->input('date_to')) ? trim($request->input('date_to')) : '';
        $fromOk = $fromRaw === '' || $this->isPaymentDay($fromRaw);
        $toOk = $toRaw === '' || $this->isPaymentDay($toRaw);
        if (! $fromOk || ! $toOk) {
            $dateError = 'Enter real dates.';
        } elseif ($fromRaw !== '' && $toRaw !== '' && $toRaw < $fromRaw) {
            $dateError = 'The to date must be on or after the from date.';
        } else {
            $dateField = is_string($request->input('date_field')) ? $request->input('date_field') : 'created_at';
            if (! in_array($dateField, ['paid_at', 'completed_at'], true)) {
                $dateField = 'created_at';
            }
            if (in_array($dateField, ['paid_at', 'completed_at'], true) && ! $this->ordersHaveColumn($dateField)) {
                $dateField = 'created_at';
            }
            if ($fromRaw !== '') {
                $query->whereDate($dateField, '>=', $fromRaw);
            }
            if ($toRaw !== '') {
                $query->whereDate($dateField, '<=', $toRaw);
            }
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function applyPaymentSort(Builder $query, Request $request): Builder
    {
        $sort = is_string($request->input('sort')) ? $request->input('sort') : '';

        if ($sort === 'paid' && ! $this->ordersHaveColumn('paid_at')) {
            $sort = '';
        }

        return match ($sort) {
            'oldest' => $query->orderBy('created_at')->orderBy('id'),
            'amount' => $query->orderByDesc('total_amount')->orderByDesc('id'),
            'paid' => $query->orderByDesc('paid_at')->orderByDesc('id'),
            default => $query->orderByDesc('created_at')->orderByDesc('id'),
        };
    }

    /**
     * @param  Builder<Order>  $unpaid
     * @return array<string, mixed>
     */
    private function unpaidSummary(Builder $unpaid): array
    {
        $methods = ['wise' => 0, 'bank' => 0, 'crypto' => 0];
        $amounts = ['wise' => 0.0, 'bank' => 0.0, 'crypto' => 0.0];
        $rows = (clone $unpaid)
            ->setEagerLoads([])
            ->selectRaw('payment_method, COUNT(*) as method_count, SUM(total_amount) as method_total')
            ->groupBy('payment_method')
            ->get();
        foreach ($rows as $row) {
            $method = $this->normalizedPaymentMethod((string) $row->payment_method);
            if (! array_key_exists($method, $methods)) {
                continue;
            }
            $methods[$method] += (int) $row->method_count;
            $amounts[$method] = round($amounts[$method] + (float) $row->method_total, 2);
        }

        return [
            'unpaid_count' => (clone $unpaid)->count(),
            'unpaid_amount' => round((float) (clone $unpaid)->sum('total_amount'), 2),
            'methods' => $methods,
            'method_amounts' => $amounts,
        ];
    }

    /**
     * @param  Builder<Order>  $query
     * @return array{count: int, euros: float, charges: array<string, float>, not_recorded: int}
     */
    private function paymentFilterTotals(Builder $query): array
    {
        $count = (clone $query)->count();
        $euros = round((float) (clone $query)->sum('total_amount'), 2);
        $charges = [];
        $notRecorded = 0;
        if ($this->ordersHaveColumn('charge_currency') && $this->ordersHaveColumn('charge_amount')) {
            $external = (clone $query)->whereIn('payment_method', ['card', 'stripe', 'paypal']);
            $notRecorded = (clone $external)->where(function ($q) {
                $q->whereNull('charge_currency')
                    ->orWhere('charge_currency', '')
                    ->orWhereNull('charge_amount');
            })->count();
            $inner = (clone $external)
                ->setEagerLoads([])
                ->reorder()
                ->whereNotNull('charge_currency')
                ->where('charge_currency', '!=', '')
                ->whereNotNull('charge_amount');
            $inner->getQuery()->columns = null;
            $inner->selectRaw('UPPER(charge_currency) as code, charge_amount');
            $rows = DB::query()
                ->fromSub($inner, 'payment_charge_rows')
                ->selectRaw('code, SUM(charge_amount) as total')
                ->groupBy('code')
                ->get();
            foreach ($rows as $row) {
                $code = strtoupper(trim((string) $row->code));
                if ($code !== '') {
                    $charges[$code] = round((float) $row->total, 2);
                }
            }
            ksort($charges);
        }

        return [
            'count' => $count,
            'euros' => $euros,
            'charges' => $charges,
            'not_recorded' => $notRecorded,
        ];
    }

    private function normalizedPaymentMethod(string $method): string
    {
        $method = strtolower(trim($method));

        return match ($method) {
            'stripe' => 'card',
            'bank_transfer' => 'bank',
            default => $method,
        };
    }

    private function isPaymentDay(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }

        try {
            return Carbon::parse($value)->toDateString() === $value;
        } catch (\Throwable) {
            return false;
        }
    }

    public function batchMarkPaid(Request $request)
    {
        $ids = $request->input('ids');
        if (! is_array($ids) || $ids === []) {
            return response()->json([
                'success' => false,
                'message' => 'Select at least one unpaid Wise, bank, or crypto payment.',
            ], 422);
        }

        $ids = array_values(array_unique(array_filter(array_map(
            fn ($id) => is_numeric($id) ? (int) $id : 0,
            $ids
        ))));
        if ($ids === []) {
            return response()->json([
                'success' => false,
                'message' => 'Select at least one unpaid Wise, bank, or crypto payment.',
            ], 422);
        }
        $orders = Order::query()->whereIn('id', $ids)->get();
        if ($orders->count() !== count($ids)) {
            return response()->json([
                'success' => false,
                'message' => 'Select one payment method at a time. None of these rows were marked paid.',
            ], 422);
        }

        $methods = $orders->map(fn (Order $order) => $this->normalizedPaymentMethod((string) $order->payment_method))->unique()->values();
        $allowed = ['wise', 'bank', 'crypto'];
        $unpaid = $orders->every(fn (Order $order) => $order->isUnpaidOps() && in_array('paid', $this->allowedPaymentStatuses($order), true));
        if ($methods->count() !== 1 || ! in_array($methods->first(), $allowed, true) || ! $unpaid) {
            return response()->json([
                'success' => false,
                'message' => 'Select one payment method at a time — Wise, bank, or crypto, still unpaid. None of these rows were marked paid.',
            ], 422);
        }

        $payments = app(OrderPaymentService::class);
        $blocked = $this->precheckBatchMarkPaid($orders, $payments);
        if ($blocked !== null) {
            return response()->json([
                'success' => false,
                'message' => $blocked,
            ], 422);
        }

        $marked = 0;
        DB::beginTransaction();
        try {
            foreach ($ids as $id) {
                $sub = Request::create('/', 'POST', [
                    'payment_status' => 'paid',
                    'send_notification' => $request->boolean('send_notification', true) ? 1 : 0,
                    'notes' => is_string($request->input('notes')) ? $request->input('notes') : '',
                ]);
                $sub->headers->set('Accept', 'application/json');
                $sub->setUserResolver(fn () => $request->user());
                $response = $this->updatePaymentStatus($sub, $id);
                $payload = $response->getData(true);
                if (! ($payload['success'] ?? false)) {
                    $this->discardDeferredPaymentSideEffects();
                    $this->rollBackOpenTransaction();

                    return response()->json([
                        'success' => false,
                        'message' => $this->batchNoneMarkedMessage($payload['message'] ?? null),
                    ], 422);
                }
                $marked++;
            }
            // Invoice and lifecycle mail are registered as afterCommit hooks.
            // They run inside this outer commit, after each row's own
            // transaction has already released the "don't email" flag.
            $suppressCustomerMail = ! $request->boolean('send_notification', true);
            if ($suppressCustomerMail) {
                app(BillingCustomerMailSuppressor::class)->enable();
            }
            try {
                DB::commit();
            } finally {
                if ($suppressCustomerMail) {
                    app(BillingCustomerMailSuppressor::class)->disable();
                }
            }
        } catch (\Throwable $e) {
            report($e);
            if (DB::transactionLevel() > 0) {
                $this->discardDeferredPaymentSideEffects();
                $this->rollBackOpenTransaction();

                return response()->json([
                    'success' => false,
                    'message' => 'Could not mark a payment paid. None of these rows were marked paid.',
                ], 422);
            }

            $this->flushDeferredPaymentSideEffects();

            return response()->json([
                'success' => false,
                'message' => 'Payments were marked paid, but a follow-up invoice or email step failed.',
            ], 500);
        }

        $this->flushDeferredPaymentSideEffects();

        return response()->json([
            'success' => true,
            'message' => $marked.' payment(s) marked paid.',
        ]);
    }

    /**
     * Catalog and library checks share one transaction that is always rolled back.
     * A lock error must not be reported as a missing article.
     *
     * @param  Collection<int, Order>  $orders
     */
    private function precheckBatchMarkPaid(Collection $orders, OrderPaymentService $payments): ?string
    {
        $blocked = null;
        try {
            DB::beginTransaction();
            foreach ($orders as $order) {
                $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();
                if (! $locked instanceof Order || ! $locked->hasCatalogVisibleFulfillment()) {
                    $blocked = 'This order cannot be marked paid. The listing left the catalog and is no longer fulfillable. None of these rows were marked paid.';
                    break;
                }
                $libraryState = $payments->libraryContentStateForSettlement($locked);
                if ($libraryState !== 'ok') {
                    $blocked = $this->libraryUnreadyForMarkPaidMessage($libraryState).' None of these rows were marked paid.';
                    break;
                }
            }
        } catch (\Throwable $e) {
            report($e);
            $blocked = 'Could not check these payments. None of these rows were marked paid.';
        } finally {
            $this->rollBackOpenTransaction();
        }

        return $blocked;
    }

    private function batchNoneMarkedMessage(?string $message): string
    {
        $message = trim((string) $message);
        if ($message === '') {
            $message = 'Could not mark a payment paid.';
        }
        if (! str_contains($message, 'None of these rows were marked paid.')) {
            $message = rtrim($message, '.').'. None of these rows were marked paid.';
        }

        return $message;
    }

    private function flushDeferredPaymentSideEffects(): void
    {
        $effects = $this->deferredPaymentSideEffects;
        $this->deferredPaymentSideEffects = [];
        foreach ($effects as $effect) {
            $effect();
        }
    }

    private function discardDeferredPaymentSideEffects(): void
    {
        $this->deferredPaymentSideEffects = [];
    }

    private function rollBackOpenTransaction(): void
    {
        try {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function serializePaymentRow(Order $order): array
    {
        $method = $this->normalizedPaymentMethod((string) $order->payment_method);
        $chargeCode = strtoupper(trim((string) ($order->charge_currency ?? '')));

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'reference_code' => $order->reference_code,
            'total_amount' => (float) $order->total_amount,
            'charge_currency' => $chargeCode !== '' ? $chargeCode : null,
            'charge_amount' => $order->charge_amount !== null ? (float) $order->charge_amount : null,
            'payment_method' => $order->payment_method,
            'payment_method_label' => $method,
            'payment_status' => $order->payment_status,
            'status' => $order->isAwaitingScheduledRelease() ? 'scheduled' : $order->status,
            'site_name' => $order->items->pluck('site_name')->filter()->unique()->implode(', ') ?: null,
            'can_batch_pay' => $order->isUnpaidOps() && in_array($method, ['wise', 'bank', 'crypto'], true),
            'paid_at' => $order->paid_at?->toIso8601String(),
            'created_at' => $order->created_at?->toIso8601String(),
            'admin_notes' => $order->admin_notes,
            'payment_reference' => $order->payment_reference,
            'user' => $order->user ? [
                'id' => $order->user->id,
                'name' => $order->user->name,
                'email' => $order->user->email,
                'dossier_url' => route('admin.finance.user', $order->user->id),
            ] : null,
            'allowed_statuses' => $this->allowedPaymentStatuses($order),
            'invoice_url' => $order->invoice_url ?? null,
            'invoice_documents' => $order->invoice_documents ?? [],
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

            // Keep `paid` so staff can save notes / transfer reference
            // without a money move.
            return ['paid', 'failed', 'refunded'];
        }

        // Paid→failed already credits captured methods. Allow Refunded as a
        // bookkeeping correction (no second credit) and Failed for notes.
        if ($current === 'failed' && (string) $order->status === 'cancelled') {
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
                'methods' => ['wise' => 0, 'bank' => 0, 'crypto' => 0],
                'method_amounts' => ['wise' => 0, 'bank' => 0, 'crypto' => 0],
            ],
            'totals' => ['count' => 0, 'euros' => 0, 'charges' => [], 'not_recorded' => 0],
            'date_error' => null,
            'export_limited' => false,
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
