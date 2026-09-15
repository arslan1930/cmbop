<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemDispute;
use App\Models\StaffCapability;
use App\Models\User;
use App\Services\Orders\OrderClawbackService;
use App\Support\UserFacingError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OrderDisputeController extends Controller
{
    public function __construct(
        private OrderClawbackService $clawbacks,
    ) {}

    public function open(Request $request, $orderId)
    {
        if ($denied = $this->denyUnlessCapability($request, StaffCapability::SUPPORT)) {
            return $denied;
        }
        $data = $request->validate([
            'reason' => 'required|string|min:10|max:1000',
            'order_item_id' => 'nullable|integer',
        ]);

        $order = Order::with('items')->find($orderId);
        if (! $order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found.',
            ], 404);
        }

        $requestedItemId = isset($data['order_item_id']) ? (int) $data['order_item_id'] : null;
        if ($requestedItemId) {
            $item = $order->items->firstWhere('id', $requestedItemId);
        } else {
            $candidates = $order->items->filter(
                fn ($line) => $line instanceof OrderItem && $this->clawbacks->canOpenDispute($order, $line, asAdmin: true)
            );
            $item = $candidates->count() === 1
                ? $candidates->first()
                : ($order->items->count() === 1 ? $order->items->first() : null);
        }

        if (! $item instanceof OrderItem) {
            return response()->json([
                'success' => false,
                'message' => $order->items->count() > 1
                    ? 'Please choose which placement to dispute.'
                    : 'Order item not found.',
            ], $order->items->count() > 1 ? 422 : 404);
        }

        try {
            $dispute = $this->clawbacks->openDispute($item, $request->user(), $data['reason'], asAdmin: true);

            return response()->json([
                'success' => true,
                'message' => 'Dispute opened.',
                'dispute' => $this->disputePayload($dispute),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first() ?? 'Unable to open dispute.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Unable to open dispute.'),
            ], 500);
        }
    }

    public function uphold(Request $request, $disputeId)
    {
        if ($denied = $this->denyUnlessCapability($request, StaffCapability::FINANCE)) {
            return $denied;
        }
        $data = $request->validate([
            'admin_notes' => 'required|string|min:10|max:1000',
        ]);

        if ($unavailable = $this->disputesUnavailableResponse()) {
            return $unavailable;
        }

        $dispute = OrderItemDispute::find($disputeId);
        if (! $dispute) {
            return response()->json([
                'success' => false,
                'message' => 'Dispute not found.',
            ], 404);
        }

        try {
            $dispute = $this->clawbacks->uphold($dispute, $request->user(), $data['admin_notes']);

            return response()->json([
                'success' => true,
                'message' => 'Dispute upheld. Publisher clawback and advertiser refund applied.',
                'dispute' => $this->disputePayload($dispute),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first() ?? 'Unable to uphold dispute.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Unable to uphold dispute.'),
            ], 500);
        }
    }

    public function dismiss(Request $request, $disputeId)
    {
        if ($denied = $this->denyUnlessCapability($request, StaffCapability::SUPPORT)) {
            return $denied;
        }
        $data = $request->validate([
            'admin_notes' => 'required|string|min:10|max:1000',
        ]);

        if ($unavailable = $this->disputesUnavailableResponse()) {
            return $unavailable;
        }

        $dispute = OrderItemDispute::find($disputeId);
        if (! $dispute) {
            return response()->json([
                'success' => false,
                'message' => 'Dispute not found.',
            ], 404);
        }

        try {
            $dispute = $this->clawbacks->dismiss($dispute, $request->user(), $data['admin_notes']);

            return response()->json([
                'success' => true,
                'message' => 'Dispute dismissed.',
                'dispute' => $this->disputePayload($dispute),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first() ?? 'Unable to dismiss dispute.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Unable to dismiss dispute.'),
            ], 500);
        }
    }

    private function denyUnlessCapability(Request $request, string $capability): ?JsonResponse
    {
        $actor = $request->user();
        if ($actor instanceof User && $actor->staffCan($capability)) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => 'This area is limited to a different admin capability.',
        ], 403);
    }

    private function disputesUnavailableResponse(): ?JsonResponse
    {
        OrderItemDispute::ensureTable();
        if (OrderItemDispute::tableAvailable()) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => 'Disputes are unavailable until the database migration has been run.',
        ], 503);
    }

    private function disputePayload(OrderItemDispute $dispute): array
    {
        return [
            'id' => $dispute->id,
            'status' => $dispute->status,
            'reason' => $dispute->reason,
            'admin_notes' => $dispute->admin_notes,
            'publisher_debited' => $dispute->publisher_debited,
            'advertiser_credited' => $dispute->advertiser_credited,
            'debt_created' => $dispute->debt_created,
            'resolved_at' => optional($dispute->resolved_at)?->toIso8601String(),
        ];
    }
}
