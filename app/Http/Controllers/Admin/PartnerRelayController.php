<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Models\PartnerOrderAttachment;
use App\Models\PartnerOrderCorrection;
use App\Models\PartnerOrderDispute;
use App\Models\PartnerOrderMessage;
use App\Services\Partners\PartnerCorrectionService;
use App\Services\Partners\PartnerThreadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PartnerRelayController extends Controller
{
    public function __construct(
        private PartnerCorrectionService $corrections,
        private PartnerThreadService $thread,
    ) {}

    public function index(): View
    {
        $pending = PartnerOrderCorrection::query()
            ->with(['orderItem.site', 'orderItem.order'])
            ->where('status', PartnerOrderCorrection::PENDING_RELAY)
            ->orderBy('id')
            ->get();
        $held = PartnerOrderMessage::query()
            ->with(['orderItem.site'])
            ->where('relay_status', 'held_for_review')
            ->orderByDesc('id')
            ->limit(50)
            ->get();
        $disputes = PartnerOrderDispute::query()
            ->with(['orderItem.site', 'orderItem.order'])
            ->where('status', PartnerOrderDispute::OPEN)
            ->orderByDesc('id')
            ->get();

        return view('admin.partners.relay', compact('pending', 'held', 'disputes'));
    }

    public function show(int $item): View
    {
        $orderItem = OrderItem::query()->with(['order.user', 'site.publisher'])->findOrFail($item);
        $messages = $this->thread->visibleToAdmin($orderItem);
        $corrections = PartnerOrderCorrection::query()->where('order_item_id', $orderItem->id)->orderBy('id')->get();
        $disputes = PartnerOrderDispute::query()->where('order_item_id', $orderItem->id)->orderByDesc('id')->get();

        return view('admin.partners.relay-show', compact('orderItem', 'messages', 'corrections', 'disputes'));
    }

    public function relayCorrection(Request $request, PartnerOrderCorrection $correction): RedirectResponse
    {
        $this->corrections->relay($correction, $request->user());

        return back()->with('success', 'Correction relayed to the partner.');
    }

    public function releaseMessage(Request $request, PartnerOrderMessage $message): RedirectResponse
    {
        $this->thread->release($message, $request->user());

        return back()->with('success', 'Message released to the other party (masked).');
    }

    public function hideMessage(PartnerOrderMessage $message): RedirectResponse
    {
        $this->thread->hide($message);

        return back()->with('success', 'Message hidden.');
    }

    public function postNote(Request $request, int $item): RedirectResponse
    {
        $data = $request->validate(['body' => 'required|string|max:5000']);
        $orderItem = OrderItem::query()->findOrFail($item);
        $this->thread->post($orderItem, 'admin', $request->user()->id, $data['body'], [
            'visibility' => 'all',
            'item_type' => 'admin_note',
        ]);

        return back()->with('success', 'Note posted.');
    }

    public function resolveDispute(Request $request, PartnerOrderDispute $dispute): RedirectResponse
    {
        $data = $request->validate([
            'outcome' => 'required|in:buyer,partner',
            'note' => 'nullable|string|max:2000',
        ]);
        $this->corrections->resolve($dispute, $data['outcome'], $request->user(), $data['note'] ?? null);

        return back()->with('success', 'Dispute resolved.');
    }

    public function download(int $item, int $attachment): StreamedResponse
    {
        $row = PartnerOrderAttachment::query()
            ->where('id', $attachment)
            ->where('order_item_id', $item)
            ->firstOrFail();
        abort_unless(Storage::disk('local')->exists($row->storage_path), 404);

        return Storage::disk('local')->download($row->storage_path, $row->filename_original);
    }
}
