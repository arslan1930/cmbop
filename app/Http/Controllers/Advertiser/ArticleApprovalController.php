<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Services\Marketplace\ArticleWritingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ArticleApprovalController extends Controller
{
    public function __construct(private ArticleWritingService $writing) {}

    public function approve(Request $request, OrderItem $orderItem): RedirectResponse
    {
        $this->writing->approveArticle($orderItem, $request->user());

        return back()->with('success', 'Article approved. The publisher can now publish it.');
    }

    public function revise(Request $request, OrderItem $orderItem): RedirectResponse
    {
        $data = $request->validate([
            'reason' => 'required|string|min:8|max:2000',
        ]);
        $this->writing->requestRevision($orderItem, $request->user(), $data['reason']);

        return back()->with('success', 'Revision requested. You have used your one free revision.');
    }

    public function download(Request $request, OrderItem $orderItem)
    {
        $order = $orderItem->relationLoaded('order') ? $orderItem->order : $orderItem->order()->first();
        if (! $order || (int) $order->user_id !== (int) $request->user()->id) {
            abort(403);
        }

        return $this->writing->writingDownloadResponse($orderItem);
    }
}
