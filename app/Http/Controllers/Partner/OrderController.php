<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Publisher\OrderController as PublisherOrderController;
use App\Models\ContentSubmission;
use App\Services\Partners\PartnerOrderPresenter;
use App\Services\Partners\PartnerOrderQuery;
use App\Services\Partners\PartnerOrderStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function show(Request $request, int $order, PartnerOrderQuery $query, PartnerOrderPresenter $presenter): View
    {
        $item = $query->findOwnOrFail($request->user(), $order);

        return view('partner.orders.show', [
            'order' => $presenter->present($request->user(), $item),
            'item' => $item,
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

    public function submit(Request $request, int $order, PartnerOrderQuery $query): RedirectResponse
    {
        $item = $query->findOwnOrFail($request->user(), $order);
        $publisher = app(PublisherOrderController::class);
        if ($item->isModificationRequested()) {
            return $this->fromPublisherJson(
                $publisher->resubmitLiveUrl($request, $order),
                'Live URL resubmitted.'
            );
        }

        return $this->fromPublisherJson(
            $publisher->submitLiveUrl($request, $order),
            'Live URL submitted.'
        );
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
