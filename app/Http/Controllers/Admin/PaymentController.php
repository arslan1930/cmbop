<?php

// app/Http/Controllers/Admin/PaymentController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\OrderPaymentConfirmed;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Services\ActivityLogger;
use App\Services\Advertiser\SpendBudgetService;
use App\Services\Billing\BillingDocumentService;
use App\Services\CheckoutIntentService;
use App\Services\InAppNotificationService;
use App\Services\OrderPaymentService;
use App\Services\Orders\OrderRefundService;
use App\Services\Wallet\WalletLedgerService;
use App\Support\UserFacingError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PaymentController extends Controller
{
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
            $query = Order::with('user')->orderBy('created_at', 'desc');

            // Search filter
            if ($request->filled('search')) {
                $search = trim(scalar_text($request->search));
                $query->where(function ($q) use ($search) {
                    $q->where('order_number', 'like', "%{$search}%")
                        ->orWhere('reference_code', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($sub) use ($search) {
                            $sub->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                });
            }

            // Payment status filter. "unpaid" is the ops queue, not an enum value.
            $paymentStatus = scalar_text($request->input('payment_status'));
            if ($paymentStatus === 'unpaid') {
                $query->unpaidOps();
            } elseif ($paymentStatus !== '') {
                $query->where('payment_status', $paymentStatus);
            }

            // Payment method filter
            $paymentMethod = scalar_text($request->input('payment_method'));
            if ($paymentMethod !== '') {
                $query->where('payment_method', $paymentMethod);
            }

            // Order status filter
            $status = scalar_text($request->input('status'));
            if ($status !== '') {
                $query->where('status', $status);
            }

            // Date range filter
            $dateFrom = scalar_text($request->input('date_from'));
            if ($dateFrom !== '') {
                $query->whereDate('created_at', '>=', $dateFrom);
            }
            $dateTo = scalar_text($request->input('date_to'));
            if ($dateTo !== '') {
                $query->whereDate('created_at', '<=', $dateTo);
            }

            $perPage = $request->get('per_page', 20);
            $orders = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $orders->items(),
                'pagination' => [
                    'current_page' => $orders->currentPage(),
                    'last_page' => $orders->lastPage(),
                    'per_page' => $orders->perPage(),
                    'total' => $orders->total(),
                    'from' => $orders->firstItem(),
                    'to' => $orders->lastItem(),
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
     * Show single payment details
     */
    public function show($id)
    {
        try {
            $order = Order::with(['user', 'items.site'])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $order,
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
        try {
            $request->validate([
                'payment_status' => 'required|in:pending,paid,failed,refunded',
                'notes' => 'nullable|string',
            ]);

            DB::beginTransaction();

            $order = Order::with('user')->where('id', $id)->lockForUpdate()->firstOrFail();

            $oldStatus = $order->payment_status;
            $order->payment_status = $request->payment_status;

            if ($request->payment_status === 'paid' && ! $order->paid_at) {
                $order->paid_at = now();
            }

            $refundAmount = 0.0;
            if ($request->payment_status === 'refunded' && $oldStatus === 'paid') {
                if ($order->status === 'completed') {
                    DB::rollBack();

                    return response()->json([
                        'success' => false,
                        'message' => 'Completed orders cannot be refunded here. Use a dispute clawback so the publisher payout is reversed first.',
                    ], 422);
                }

                $refundAmount = $this->creditAdvertiserRefund($order);
                if ($order->status !== 'cancelled') {
                    $order->status = 'cancelled';
                }
            }

            if ($request->payment_status === 'failed' && $oldStatus === 'paid' && $order->payment_method === 'wallet') {
                $refundAmount = $this->releaseWalletHoldOnAdminFailed($order);
            }

            $order->save();

            Log::info('Payment status updated by admin', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'old_status' => $oldStatus,
                'new_status' => $request->payment_status,
                'admin_id' => auth()->id(),
            ]);

            // Send email notification to user when payment is marked as paid
            if ($request->payment_status === 'paid' && $oldStatus !== 'paid') {
                $this->sendPaymentConfirmationEmail($order);
            }

            // Unpaid failure: release leftover checkout bonus. Paid refunds go
            // through creditAdvertiserRefund so promo is not minted as cash.
            if ($request->payment_status === 'failed' && $oldStatus !== 'failed') {
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

            if ($request->payment_status === 'failed' && $oldStatus !== 'failed') {
                $notifications->notifyPaymentFailed([$fresh], $request->notes);
                if ($refundAmount > 0) {
                    $notifications->notifyRefundCredited(
                        $fresh,
                        $refundAmount,
                        $request->notes ?: 'Admin marked payment failed'
                    );
                }
            }

            if ($request->payment_status === 'refunded' && $oldStatus !== 'refunded' && $refundAmount > 0) {
                $notifications->notifyRefundCredited(
                    $fresh,
                    $refundAmount,
                    $request->notes ?: 'Admin refund'
                );
            }

            ActivityLogger::log(
                'payment.status_updated',
                auth()->user()->name.' set payment for order '.$order->order_number.' to '.$request->payment_status,
                $order,
                ['from' => $oldStatus, 'to' => $request->payment_status],
                $order->order_number
            );

            return response()->json([
                'success' => true,
                'message' => 'Payment status updated successfully',
                'data' => [
                    'payment_status' => $order->payment_status,
                    'paid_at' => $order->paid_at,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating payment status: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to update payment status. Please try again.'),
            ], 500);
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

    private function consumeReservedCheckoutBonus(Order $order): void
    {
        $bonus = app(CheckoutIntentService::class)->takeBonus((int) $order->user_id, (string) $order->reference_code);
        if ($bonus <= 0) {
            return;
        }

        $roleId = Wallet::advertiserRoleId();
        if (! $roleId) {
            return;
        }

        $wallet = Wallet::where('user_id', $order->user_id)->where('role_id', $roleId)->lockForUpdate()->first();
        if ($wallet && (float) $wallet->bonus_reserved > 0) {
            $wallet->consumeReserved(min($bonus, (float) $wallet->bonus_reserved));
        }
    }

    private function refundReservedCheckoutBonus(Order $order): void
    {
        $bonus = app(CheckoutIntentService::class)->takeBonus((int) $order->user_id, (string) $order->reference_code);
        if ($bonus <= 0) {
            return;
        }

        $roleId = Wallet::advertiserRoleId();
        if (! $roleId) {
            return;
        }

        $wallet = Wallet::where('user_id', $order->user_id)->where('role_id', $roleId)->lockForUpdate()->first();
        if ($wallet && (float) $wallet->bonus_reserved > 0) {
            $wallet->refundReserved(min($bonus, (float) $wallet->bonus_reserved));
        }
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

        $bonusReservedBefore = (float) $wallet->bonus_reserved;
        $wallet->refundReserved($amount);
        $refunded = max(0, round($reservedBefore - (float) $wallet->reserved_balance, 2));
        $bonusRestored = max(0, round($bonusReservedBefore - (float) $wallet->bonus_reserved, 2));

        if ($refunded > 0) {
            app(WalletLedgerService::class)->recordRefund(
                $wallet,
                $refunded,
                $bonusRestored,
                $order,
                $order->reference_code ?? $order->order_number
            );
        }

        if ($order->status !== 'cancelled') {
            $order->status = 'cancelled';
        }

        return $refunded;
    }

    /**
     * Credit the advertiser wallet when admin marks a paid order as refunded.
     * Mirrors publisher reject refund behaviour.
     */
    private function creditAdvertiserRefund(Order $order): float
    {
        $order->loadMissing('items');
        $amount = app(OrderRefundService::class)
            ->resolveLineRefundAmount(
                $order,
                (float) ($order->items->sum('price') ?: $order->total_amount)
            );
        if ($amount <= 0) {
            return 0.0;
        }

        app(OrderRefundService::class)->refundToAdvertiser($order, $amount, 'Admin refund');

        return $amount;
    }
}
