<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Models\OrderPack;
use App\Services\Billing\BillingDocumentService;
use App\Services\Billing\InvoicePdfGenerator;
use App\Services\Orders\OrderPackService;
use App\Support\UserFacingError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class OrderPackController extends Controller
{
    public function __construct(private readonly OrderPackService $packs) {}

    public function show(Request $request, OrderPack $pack): JsonResponse
    {
        if ((int) $pack->user_id !== (int) $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'Pack not found.'], 404);
        }

        $this->packs->refreshStatus($pack);
        $pack->load(['site', 'orders.items']);

        $orders = $pack->orders->map(function ($order) {
            return [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'payment_status' => $order->payment_status,
                'total_amount' => (float) $order->total_amount,
                'site_name' => $order->items->first()?->site_name,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'pack' => [
                'id' => $pack->id,
                'label' => $pack->label(),
                'pack_size' => (int) $pack->pack_size,
                'status' => $pack->status,
                'status_label' => $pack->statusLabel(),
                'progress_label' => $pack->progressLabel(),
                'published_count' => (int) $pack->published_count,
                'completed_count' => (int) $pack->completed_count,
                'refunded_count' => (int) $pack->refunded_count,
                'site_name' => $pack->site?->site_name,
                'reference_code' => $pack->reference_code,
                'total_amount' => (float) $pack->total_amount,
                'receipt_url' => route('advertiser.packs.receipt', $pack),
                'can_cancel_rest' => $this->packs->cancellableRest($pack)->isNotEmpty(),
                'orders' => $orders,
            ],
        ]);
    }

    public function cancelRest(Request $request, OrderPack $pack): JsonResponse
    {
        if ((int) $pack->user_id !== (int) $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'Pack not found.'], 404);
        }

        try {
            $result = $this->packs->cancelRest(
                $pack,
                $request->user(),
                'Advertiser cancelled the remaining articles in this pack.'
            );

            return response()->json([
                'success' => true,
                'message' => $result['cancelled'] > 0
                    ? 'Cancelled '.$result['cancelled'].' remaining article(s) in the pack.'
                    : 'No remaining articles could be cancelled.',
                'result' => $result,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Could not cancel the rest of this pack.'),
            ], 500);
        }
    }

    public function receipt(
        Request $request,
        OrderPack $pack,
        InvoicePdfGenerator $pdfs,
        BillingDocumentService $billing
    ): StreamedResponse|JsonResponse|\Illuminate\Http\Response {
        if ((int) $pack->user_id !== (int) $request->user()->id) {
            abort(404);
        }

        try {
            $invoice = $this->packs->ensurePackReceipt($pack);
            if (! $invoice) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pack receipt is not available yet.',
                ], 404);
            }

            $pdfs->ensureCustomerPdf($invoice);
            $invoice->refresh();
            $billing->recordDownload($invoice);

            return $pdfs->download($invoice);
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Could not download the pack receipt.'),
            ], 500);
        }
    }
}
