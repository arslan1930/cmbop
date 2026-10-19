<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Publisher\OrderController as PublisherOrderController;
use App\Models\ContentSubmission;
use App\Models\PartnerOrderAttachment;
use App\Models\PartnerOrderCorrection;
use App\Services\Partners\PartnerCompleteService;
use App\Services\Partners\PartnerCorrectionService;
use App\Services\Partners\PartnerInvalidTransitionException;
use App\Services\Partners\PartnerLinkCheckFailedException;
use App\Services\Partners\PartnerOrderPresenter;
use App\Services\Partners\PartnerOrderQuery;
use App\Services\Partners\PartnerOrderStatus;
use App\Services\Partners\PartnerThreadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderController extends Controller
{
    public function index(Request $request, PartnerOrderQuery $query, PartnerOrderPresenter $presenter): View
    {
        $user = $request->user();
        $page = $query->paginate($user, $request);

        return view('partner.orders.index', [
            'rows' => $presenter->presentMany($user, $page->items()),
            'paginator' => $page,
            'counts' => $query->counts($user),
            'labels' => PartnerOrderStatus::labels(),
            'filters' => [
                'status' => (string) $request->query('status', ''),
                'q' => (string) $request->query('q', ''),
                'from' => (string) $request->query('from', ''),
                'to' => (string) $request->query('to', ''),
            ],
        ]);
    }

    public function show(Request $request, int $order, PartnerOrderQuery $query, PartnerOrderPresenter $presenter, PartnerThreadService $thread): View
    {
        $item = $query->findOwnOrFail($request->user(), $order);
        $openCorrection = PartnerOrderCorrection::query()
            ->where('order_item_id', $item->id)
            ->where('status', PartnerOrderCorrection::OPEN)
            ->latest('id')
            ->first();

        return view('partner.orders.show', [
            'order' => $presenter->present($request->user(), $item),
            'item' => $item,
            'messages' => $thread->partnerPayload($item),
            'openCorrection' => $openCorrection,
        ]);
    }

    public function accept(Request $request, int $order, PartnerOrderQuery $query): RedirectResponse
    {
        $query->findOwnOrFail($request->user(), $order);

        return $this->fromPublisherJson(
            app(PublisherOrderController::class)->acceptOrder($request, $order),
            'Order accepted. Submit the live URL when the article is published.'
        );
    }

    public function reject(Request $request, int $order, PartnerOrderQuery $query): RedirectResponse
    {
        $query->findOwnOrFail($request->user(), $order);

        return $this->fromPublisherJson(
            app(PublisherOrderController::class)->rejectOrder($request, $order),
            'Order rejected.'
        );
    }

    public function submit(Request $request, int $order, PartnerOrderQuery $query, PartnerCompleteService $complete): RedirectResponse
    {
        $request->validate(['live_url' => 'required|url']);
        $item = $query->findOwnOrFail($request->user(), $order);
        try {
            $complete->complete($item, (string) $request->input('live_url'));
        } catch (PartnerInvalidTransitionException) {
            return back()->with('error', 'This order cannot be submitted in its current status.')->withInput();
        } catch (PartnerLinkCheckFailedException $e) {
            $detail = is_array($e->check) ? (string) ($e->check['message'] ?? '') : '';

            return back()->with('error', trim('Link check failed. '.$detail))->withInput();
        }

        return back()->with('success', 'Live URL submitted.');
    }

    public function reply(Request $request, int $order, PartnerOrderQuery $query, PartnerThreadService $thread): RedirectResponse
    {
        $data = $request->validate([
            'body' => 'required|string|max:5000',
            'attachment' => 'nullable|file|max:5120',
        ]);
        $item = $query->findOwnOrFail($request->user(), $order);
        $attachmentIds = [];
        if ($request->hasFile('attachment')) {
            $attachmentIds[] = $thread->storeAttachment($item, $request->file('attachment'), $request->user())->id;
        }
        $thread->post($item, 'partner', $request->user()->id, $data['body'], [
            'attachment_ids' => $attachmentIds,
        ]);

        return back()->with('success', 'Message sent. Contact details are removed automatically.');
    }

    public function decline(Request $request, int $order, int $correction, PartnerOrderQuery $query, PartnerCorrectionService $corrections): RedirectResponse
    {
        $data = $request->validate(['reason' => 'required|string|min:10|max:2000']);
        $item = $query->findOwnOrFail($request->user(), $order);
        $row = PartnerOrderCorrection::query()
            ->where('id', $correction)
            ->where('order_item_id', $item->id)
            ->where('status', PartnerOrderCorrection::OPEN)
            ->firstOrFail();
        $corrections->decline($item, $row, $data['reason']);

        return back()->with('success', 'Correction declined. SLB will review the dispute.');
    }

    public function download(Request $request, int $order, PartnerOrderQuery $query): StreamedResponse|RedirectResponse
    {
        $item = $query->findOwnOrFail($request->user(), $order);
        $submissionId = (int) ($item->content_submission_id ?? 0);
        if ($submissionId <= 0) {
            abort(404);
        }

        return app(PublisherOrderController::class)->downloadContent(
            ContentSubmission::query()->findOrFail($submissionId)
        );
    }

    public function attachment(Request $request, int $order, int $attachment, PartnerOrderQuery $query): StreamedResponse
    {
        $item = $query->findOwnOrFail($request->user(), $order);
        $row = PartnerOrderAttachment::query()
            ->where('id', $attachment)
            ->where('order_item_id', $item->id)
            ->firstOrFail();
        abort_unless(Storage::disk('local')->exists($row->storage_path), 404);

        return Storage::disk('local')->download($row->storage_path, $row->filename_masked);
    }

    public function markFixed(Request $request, int $order, PartnerOrderQuery $query): RedirectResponse
    {
        $query->findOwnOrFail($request->user(), $order);

        return $this->fromPublisherJson(
            app(PublisherOrderController::class)->markRevisionFixed($request, $order),
            'Marked as fixed.'
        );
    }

    private function fromPublisherJson(Response $response, string $fallbackSuccess): RedirectResponse
    {
        $payload = [];
        if (method_exists($response, 'getData')) {
            $payload = (array) $response->getData(true);
        }
        $ok = (bool) ($payload['success'] ?? ($response->getStatusCode() < 400));
        $message = (string) ($payload['message'] ?? ($ok ? $fallbackSuccess : 'That action could not be completed.'));
        if (isset($payload['live_url_check']['message']) && is_string($payload['live_url_check']['message'])) {
            $message .= ' '.$payload['live_url_check']['message'];
        }
        if ($ok) {
            return back()->with('success', $message);
        }

        return back()->with('error', $message)->withInput();
    }
}
