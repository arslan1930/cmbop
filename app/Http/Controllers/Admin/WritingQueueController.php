<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FeatureOfferSetting;
use App\Models\OrderItem;
use App\Services\Marketplace\ArticleWritingService;
use App\Services\Marketplace\CheckoutAddOnService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WritingQueueController extends Controller
{
    public function __construct(
        private ArticleWritingService $writing,
        private CheckoutAddOnService $addons,
    ) {}

    public function index(Request $request): View
    {
        $status = trim((string) $request->input('status', ''));
        $items = $this->writing->queueQuery($status !== '' ? $status : null)
            ->paginate(30)
            ->withQueryString();

        return view('admin.writing-queue.index', [
            'items' => $items,
            'status' => $status,
            'writingTurnaroundText' => $this->addons->writingTurnaroundText(),
        ]);
    }

    public function updateTurnaround(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'turnaround_text' => 'required|string|max:80',
        ]);
        FeatureOfferSetting::saveWritingTurnaroundText($data['turnaround_text']);

        return back()->with('success', 'Advertiser writing turnaround text saved.');
    }

    public function show(OrderItem $orderItem): View
    {
        $orderItem->load(['order.user', 'site', 'assignee']);

        return view('admin.writing-queue.show', [
            'item' => $orderItem,
        ]);
    }

    public function assign(Request $request, OrderItem $orderItem): RedirectResponse
    {
        $this->writing->assign($orderItem, $request->user());

        return back()->with('success', 'Writing task assigned to you.');
    }

    public function upload(Request $request, OrderItem $orderItem): RedirectResponse
    {
        $data = $request->validate([
            'article' => 'required|file|max:10240',
            'notes' => 'nullable|string|max:2000',
        ]);
        if (! empty($data['notes']) && $orderItem->isFillable('writing_brief_notes')) {
            $orderItem->forceFill(['writing_brief_notes' => $data['notes']])->save();
        }
        $stored = $this->writing->storeUploadedArticle($data['article'], $orderItem);
        $this->writing->submitArticle($orderItem, $stored, $request->user());

        return redirect()->route('admin.writing-queue.index')->with('success', 'Article submitted for advertiser approval.');
    }

    public function download(OrderItem $orderItem)
    {
        return $this->writing->writingDownloadResponse($orderItem);
    }
}
