<?php

// app/Http/Controllers/Admin/PaymentController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\OrderPaymentConfirmed;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Services\ActivityLogger;
use App\Services\InAppNotificationService;
use App\Services\OrderPaymentService;
use App\Services\Wallet\WalletLedgerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('order_number', 'like', "%{$search}%")
                        ->orWhere('reference_code', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($sub) use ($search) {
                            $sub->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                });
            }

            // Payment status filter
            if ($request->filled('payment_status')) {
                $query->where('payment_status', $request->payment_status);
            }

            // Payment method filter
            if ($request->filled('payment_method')) {
                $query->where('payment_method', $request->payment_method);
            }

            // Order status filter
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            // Date range filter
            if ($request->filled('date_from')) {
                $query->whereDate('created_at', '>=', $request->date_from);
            }
            if ($request->filled('date_to')) {
                $query->whereDate('created_at', '<=', $request->date_to);
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
                'message' => 'Failed to fetch payments: '.$e->getMessage(),
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
                $refundAmount = $this->creditAdvertiserRefund($order);
                if ($order->status !== 'cancelled') {
                    $order->status = 'cancelled';
                }
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
                $this->consumeReservedCheckoutBonus($order);
                $this->sendPaymentConfirmationEmail($order);
            }

            if (in_array($request->payment_status, ['failed', 'refunded'], true) && $oldStatus !== $request->payment_status) {
                $this->refundReservedCheckoutBonus($order);
            }

            DB::commit();

            $fresh = $order->fresh(['items']);
            $notifications = app(InAppNotificationService::class);

            if ($request->payment_status === 'paid' && $oldStatus !== 'paid') {
                app(OrderPaymentService::class)->notifyPublishersOfPaidOrders([$fresh]);
            }

            if ($request->payment_status === 'failed' && $oldStatus !== 'failed') {
                $notifications->notifyPaymentFailed([$fresh], $request->notes);
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
                'message' => 'Failed to update payment status: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Send payment confirmation email to user
     */
    private function sendPaymentConfirmationEmail($order)
    {
        try {
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
        $key = 'checkout_bonus:'.$order->user_id.':'.$order->reference_code;
        $bonus = round((float) Cache::pull($key, 0), 2);
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
        $key = 'checkout_bonus:'.$order->user_id.':'.$order->reference_code;
        $bonus = round((float) Cache::pull($key, 0), 2);
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
     * Credit the advertiser wallet when admin marks a paid order as refunded.
     * Mirrors publisher reject refund behaviour.
     */
    private function creditAdvertiserRefund(Order $order): float
    {
        $amount = round((float) $order->total_amount, 2);
        if ($amount <= 0) {
            return 0.0;
        }

        $advertiserRoleId = Wallet::advertiserRoleId();
        if (! $advertiserRoleId) {
            throw new \RuntimeException('Advertiser role not configured');
        }

        $wallet = Wallet::lockOrCreateForRole($order->user_id, $advertiserRoleId);

        if ($order->payment_method === 'wallet') {
            $bonusReservedBefore = (float) $wallet->bonus_reserved;
            $wallet->refundReserved($amount);
            $bonusRestored = max(0, round($bonusReservedBefore - (float) $wallet->bonus_reserved, 2));
            app(WalletLedgerService::class)->recordRefund(
                $wallet,
                $amount,
                $bonusRestored,
                $order,
                $order->reference_code ?? $order->order_number
            );
        } else {
            $wallet->credit($amount);
            app(WalletLedgerService::class)->recordRefund(
                $wallet,
                $amount,
                0,
                $order,
                $order->reference_code ?? $order->order_number
            );
        }

        return $amount;
    }
}
