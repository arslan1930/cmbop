<?php

namespace App\Http\Controllers\Api\Partner\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Publisher\OrderController as PublisherOrderController;
use App\Models\Category;
use App\Models\Country;
use App\Models\Language;
use App\Models\Partner;
use App\Models\PartnerCatalogItem;
use App\Models\PartnerIdempotencyKey;
use App\Models\PartnerLinkIncident;
use App\Models\PartnerOrderCorrection;
use App\Models\PartnerStatement;
use App\Services\LiveUrlHealthChecker;
use App\Services\Partners\PartnerApiSerializer;
use App\Services\Partners\PartnerChannel;
use App\Services\Partners\PartnerCompleteService;
use App\Services\Partners\PartnerConfidentiality;
use App\Services\Partners\PartnerCorrectionService;
use App\Services\Partners\PartnerFx;
use App\Services\Partners\PartnerImportPipeline;
use App\Services\Partners\PartnerInvalidTransitionException;
use App\Services\Partners\PartnerLedgerService;
use App\Services\Partners\PartnerLinkCheckFailedException;
use App\Services\Partners\PartnerLinkMonitorService;
use App\Services\Partners\PartnerOrderQuery;
use App\Services\Partners\PartnerOrderStatus;
use App\Services\Partners\PartnerRateChangeService;
use App\Services\Partners\PartnerThreadService;
use App\Services\Partners\PartnerWithdrawalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class PartnerApiController extends Controller
{
    public function __construct(
        private PartnerApiSerializer $serializer,
        private PartnerOrderQuery $orders,
        private PartnerLedgerService $ledger,
        private PartnerCompleteService $complete,
        private PartnerThreadService $thread,
        private PartnerCorrectionService $corrections,
    ) {}

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->serializer->me($this->partner($request))]);
    }

    public function meUpdate(Request $request): JsonResponse
    {
        if ($request->exists('payout_mode') || $request->exists('payout_policy')) {
            return response()->json([
                'error' => [
                    'type' => 'permission_error',
                    'code' => 'role_forbidden',
                    'message' => 'Payout mode can only be changed by SLB.',
                ],
            ], 403);
        }

        return $this->me($request);
    }

    public function ordersIndex(Request $request): JsonResponse
    {
        $page = $this->orders->paginate($request->user(), $request);
        $rows = [];
        foreach ($page->items() as $item) {
            $rows[] = $this->serializer->order($request->user(), $item);
        }

        return response()->json([
            'data' => $rows,
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function ordersSummary(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->orders->counts($request->user())]);
    }

    public function orderShow(Request $request, string $order): JsonResponse
    {
        $item = $this->orders->findOwnOrFail($request->user(), $this->numericId($order));

        return response()->json(['data' => $this->serializer->order($request->user(), $item)]);
    }

    public function accept(Request $request, string $order): JsonResponse
    {
        return $this->remember($request, function () use ($request, $order) {
            $item = $this->orders->findOwnOrFail($request->user(), $this->numericId($order));
            $response = app(PublisherOrderController::class)->acceptOrder($request, $item->id);

            return $this->fromPublisher($request, $response, $item->id, 'accepted');
        });
    }

    public function reject(Request $request, string $order): JsonResponse
    {
        return $this->remember($request, function () use ($request, $order) {
            $item = $this->orders->findOwnOrFail($request->user(), $this->numericId($order));
            $response = app(PublisherOrderController::class)->rejectOrder($request, $item->id);

            return $this->fromPublisher($request, $response, $item->id, 'rejected');
        });
    }

    public function complete(Request $request, string $order): JsonResponse
    {
        $request->validate(['live_url' => 'required|url']);

        return $this->remember($request, function () use ($request, $order) {
            $item = $this->orders->findOwnOrFail($request->user(), $this->numericId($order));
            try {
                $result = $this->complete->complete($item, (string) $request->input('live_url'), $this->partner($request));
            } catch (PartnerInvalidTransitionException $e) {
                return response()->json(['error' => 'invalid_state_transition', 'message' => $e->getMessage()], 409);
            } catch (PartnerLinkCheckFailedException $e) {
                return response()->json([
                    'error' => 'link_check_failed',
                    'message' => $e->getMessage(),
                    'link_check' => $e->check,
                ], 422);
            }

            return response()->json([
                'data' => $this->serializer->order($request->user(), $result['item']),
                'link_check' => $result['link_check'],
                'earnings_status' => $result['earnings_status'],
            ]);
        });
    }

    public function declineCorrection(Request $request, string $order, int $correction): JsonResponse
    {
        $request->validate(['reason' => 'required|string|min:10|max:2000']);
        $item = $this->orders->findOwnOrFail($request->user(), $this->numericId($order));
        $row = PartnerOrderCorrection::query()
            ->where('id', $correction)
            ->where('order_item_id', $item->id)
            ->where('status', PartnerOrderCorrection::OPEN)
            ->firstOrFail();
        $dispute = $this->corrections->decline($item, $row, (string) $request->input('reason'));

        return response()->json([
            'data' => $this->serializer->order($request->user(), $item->fresh()),
            'dispute_id' => $dispute->id,
        ]);
    }

    public function messages(Request $request, string $order): JsonResponse
    {
        $item = $this->orders->findOwnOrFail($request->user(), $this->numericId($order));

        return response()->json(['data' => $this->thread->partnerPayload($item)]);
    }

    public function postMessage(Request $request, string $order): JsonResponse
    {
        $request->validate(['body' => 'required|string|max:5000']);
        $item = $this->orders->findOwnOrFail($request->user(), $this->numericId($order));
        $message = $this->thread->post($item, 'partner', $request->user()->id, (string) $request->input('body'));

        return response()->json([
            'data' => [
                'id' => $message->id,
                'body' => $message->body_masked,
                'masked' => (bool) $message->masked,
                'relay_status' => $message->relay_status,
            ],
        ], 201);
    }

    public function timeline(Request $request, string $order): JsonResponse
    {
        $item = $this->orders->findOwnOrFail($request->user(), $this->numericId($order));
        $payload = $this->serializer->order($request->user(), $item);
        $payload['messages'] = $this->thread->partnerPayload($item);

        return response()->json(['data' => $payload]);
    }

    public function sitesIndex(Request $request): JsonResponse
    {
        $partner = $this->partner($request);
        $q = PartnerCatalogItem::query()->where('partner_id', $partner->id)->orderByDesc('id');
        if ($request->filled('listing_status')) {
            $q->where('listing_status', $request->input('listing_status'));
        }
        $page = $q->paginate(min(100, max(1, (int) $request->input('limit', 50))));
        $rows = [];
        foreach ($page as $item) {
            $rows[] = $this->serializer->site($item);
        }

        return response()->json(['data' => $rows, 'meta' => ['total' => $page->total()]]);
    }

    public function siteShow(Request $request, string $site): JsonResponse
    {
        $item = $this->ownSite($request, $site);

        return response()->json(['data' => $this->serializer->site($item)]);
    }

    public function siteUpdate(Request $request, string $site): JsonResponse
    {
        $item = $this->ownSite($request, $site);
        $data = $request->validate([
            'your_price.normal.amount' => 'nullable|integer|min:0',
            'price' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|size:3',
        ]);
        $currency = strtoupper((string) ($data['currency'] ?? $item->partner_rate_currency ?: 'EUR'));
        $euros = $item->partner_rate_publish_eur;
        if (isset($data['your_price']['normal']['amount'])) {
            $euros = round(((int) $data['your_price']['normal']['amount']) / 100, 2);
        } elseif (isset($data['price'])) {
            $euros = app(PartnerFx::class)->toEuros((float) $data['price'], $currency)['amount'];
        }
        $new = [
            'publish' => (float) ($data['price'] ?? $euros),
            'writing' => $item->partner_rate_writing,
            'sensitive_extra' => $item->partner_rate_sensitive_extra,
            'currency' => $currency,
            'publish_eur' => (float) $euros,
            'writing_eur' => $item->partner_rate_writing_eur,
            'sensitive_extra_eur' => $item->partner_rate_sensitive_extra_eur,
            'fx_rate' => null,
            'fx_rate_date' => null,
        ];
        app(PartnerRateChangeService::class)->handle($item, $new, 'partner_api');

        return response()->json(['data' => $this->serializer->site($item->fresh())]);
    }

    public function sitesBulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sites' => 'required|array|min:1|max:500',
            'sites.*.site_url' => 'required|string',
            'sites.*.price' => 'required',
        ]);
        $rows = [];
        foreach ($data['sites'] as $i => $site) {
            $row = [];
            foreach ($site as $k => $v) {
                $row[(string) $k] = is_array($v) ? implode(';', $v) : (string) $v;
            }
            $row['_line'] = (string) ($i + 1);
            $rows[] = $row;
        }
        $job = app(PartnerImportPipeline::class)->importMappedFieldRows(
            $this->partner($request),
            'api_push',
            $rows,
            ['skip_reachability' => true, 'actor' => $request->user()]
        );

        return response()->json([
            'data' => [
                'job_id' => $job->id,
                'status' => $job->status,
                'counts' => $job->counts,
            ],
        ], 202);
    }

    public function balance(Request $request): JsonResponse
    {
        $partner = $this->partner($request);
        $available = $this->ledger->availableMinor($partner);

        return response()->json([
            'data' => [
                'currency' => 'EUR',
                'pending' => $this->ledger->pendingMinor($partner),
                'available' => $available,
                'negative_carry' => min(0, $available),
                'payout_minimum' => 2000,
                'payout_mode' => PartnerChannel::payoutMode($partner),
            ],
        ]);
    }

    public function ratesIndex(Request $request): JsonResponse
    {
        $partner = $this->partner($request);
        $page = PartnerCatalogItem::query()
            ->where('partner_id', $partner->id)
            ->orderByDesc('id')
            ->paginate(min(100, max(1, (int) $request->input('limit', 50))));
        $rows = [];
        foreach ($page as $item) {
            $rows[] = [
                'id' => 'site_'.$item->id,
                'domain' => $item->domain,
                'listing_status' => $item->listing_status,
                'your_price' => [
                    'normal' => [
                        'amount' => PartnerChannel::eurosToMinor((float) $item->partner_rate_publish_eur),
                        'currency' => 'EUR',
                    ],
                ],
            ];
        }

        return response()->json(['data' => $rows, 'meta' => ['total' => $page->total()]]);
    }

    public function rateUpdate(Request $request, string $site): JsonResponse
    {
        return $this->siteUpdate($request, $site);
    }

    public function earnings(Request $request): JsonResponse
    {
        $items = $this->orders->scopedItems($request->user())
            ->whereNotNull('partner_earnings_status')
            ->orderByDesc('order_items.id')
            ->limit(100)
            ->get();
        $rows = [];
        foreach ($items as $item) {
            $rows[] = [
                'order_id' => 'ord_'.$item->id,
                'domain' => $item->site?->domain,
                'amount' => PartnerChannel::eurosToMinor((float) ($item->partner_payout_eur ?? $item->publisherPayoutAmount())),
                'currency' => 'EUR',
                'status' => $item->partner_earnings_status,
                'hold_until' => optional($item->hold_until)?->toIso8601String(),
                'keep_live_until' => optional($item->keep_live_until)?->toIso8601String(),
            ];
        }

        return response()->json(['data' => $rows]);
    }

    public function ledger(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->ledger->partnerLines($this->partner($request), 100)]);
    }

    public function statements(Request $request): JsonResponse
    {
        $rows = PartnerStatement::query()
            ->where('partner_id', $this->partner($request)->id)
            ->orderByDesc('period')
            ->limit(24)
            ->get()
            ->map(fn (PartnerStatement $row) => [
                'period' => $row->period,
                'status' => $row->status,
                'totals' => $row->totals,
            ]);

        return response()->json(['data' => $rows]);
    }

    public function statementShow(Request $request, string $period): JsonResponse
    {
        $row = PartnerStatement::query()
            ->where('partner_id', $this->partner($request)->id)
            ->where('period', $period)
            ->firstOrFail();
        $scan = app(PartnerConfidentiality::class)->scan((array) $row->totals);
        abort_unless($scan === [], 500);

        return response()->json([
            'data' => [
                'period' => $row->period,
                'issuer' => ['name' => 'Teqno LTD', 'company_number' => '17392540'],
                'currency' => 'EUR',
                'totals' => $row->totals,
                'lines' => $row->lines,
            ],
        ]);
    }

    public function payouts(Request $request): JsonResponse
    {
        $request->merge([
            'amount' => $request->input('amount')
                ? round(((int) $request->input('amount')) / 100, 2)
                : round($this->ledger->availableMinor($this->partner($request)) / 100, 2),
            'payment_method' => $request->input('payment_method', $request->input('method', 'bank')),
        ]);

        return $this->remember($request, function () use ($request) {
            try {
                $withdrawal = app(PartnerWithdrawalService::class)
                    ->request($request->user(), $this->partner($request), $request);
            } catch (\RuntimeException $e) {
                $code = $e->getMessage() === 'insufficient_available' ? 402 : 400;

                return response()->json([
                    'error' => [
                        'type' => $code === 402 ? 'payment_error' : 'invalid_request',
                        'code' => $e->getMessage(),
                        'message' => $e->getMessage() === 'insufficient_available'
                            ? 'Not enough available balance.'
                            : $e->getMessage(),
                    ],
                ], $code);
            }

            return response()->json([
                'data' => [
                    'id' => 'po_'.$withdrawal->id,
                    'status' => $withdrawal->status,
                    'amount' => PartnerChannel::eurosToMinor((float) $withdrawal->amount),
                    'currency' => 'EUR',
                    'fee' => 0,
                    'requested_at' => optional($withdrawal->created_at)?->toIso8601String(),
                ],
            ], 201);
        });
    }

    public function taxonomy(): JsonResponse
    {
        $categories = [];
        if (class_exists(Category::class)) {
            foreach (Category::query()->orderBy('name')->get(['id', 'name']) as $row) {
                $name = (string) $row->name;
                $categories[] = [
                    'id' => $row->id,
                    'name' => $name,
                    'marketplace_allowed' => ! str_contains(strtolower($name), 'casino'),
                ];
            }
        }
        $hasCasino = collect($categories)->contains(fn ($row) => str_contains(strtolower((string) $row['name']), 'casino'));
        if (! $hasCasino) {
            $categories[] = ['id' => null, 'name' => 'casino', 'marketplace_allowed' => false];
        }

        return response()->json([
            'data' => [
                'categories' => $categories,
                'countries' => class_exists(Country::class)
                    ? Country::query()->orderBy('name')->get(['code', 'name'])
                    : [],
                'languages' => class_exists(Language::class)
                    ? Language::query()->orderBy('name')->get(['code', 'name'])
                    : [],
                'rejection_reasons' => [
                    'casino_not_allowed', 'pbn_suspected', 'low_quality', 'duplicate_unverified', 'not_reachable', 'other',
                ],
            ],
        ]);
    }

    public function restore(Request $request, string $order): JsonResponse
    {
        $item = $this->orders->findOwnOrFail($request->user(), $this->numericId($order));
        $url = (string) ($item->live_url ?: $request->input('live_url', ''));
        if ($url === '') {
            return response()->json(['error' => 'live_url_required'], 422);
        }
        $check = app(LiveUrlHealthChecker::class)->check($url);
        if (! ($check['ok'] ?? false)) {
            return response()->json(['error' => 'link_check_failed', 'link_check' => $check], 422);
        }
        app(PartnerLinkMonitorService::class)->checkItem($item->fresh());

        return response()->json(['data' => $this->serializer->order($request->user(), $item->fresh())]);
    }

    public function monitoring(Request $request, string $order): JsonResponse
    {
        $item = $this->orders->findOwnOrFail($request->user(), $this->numericId($order));
        $incidents = PartnerLinkIncident::query()
            ->where('order_item_id', $item->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (PartnerLinkIncident $row) => [
                'checked_at' => optional($row->created_at)?->toIso8601String(),
                'result' => $row->resolved ?: 'open',
                'failure_code' => $row->failure_code,
            ]);

        return response()->json([
            'data' => [
                'order_id' => 'ord_'.$item->id,
                'hold_until' => optional($item->hold_until)?->toIso8601String(),
                'keep_live_until' => optional($item->keep_live_until)?->toIso8601String(),
                'state' => $item->link_alert ? 'alert' : 'ok',
                'checks' => $incidents,
            ],
        ]);
    }

    private function partner(Request $request): Partner
    {
        $partner = $request->attributes->get('partner');
        if (! $partner instanceof Partner) {
            abort(401);
        }

        return $partner;
    }

    private function numericId(string $id): int
    {
        return (int) preg_replace('/^(ord_|site_)/', '', $id);
    }

    private function ownSite(Request $request, string $site): PartnerCatalogItem
    {
        $item = PartnerCatalogItem::query()
            ->where('partner_id', $this->partner($request)->id)
            ->where('id', $this->numericId($site))
            ->first();
        if (! $item) {
            abort(404);
        }

        return $item;
    }

    private function fromPublisher(Request $request, Response $response, int $itemId, string $fallback): JsonResponse
    {
        $payload = method_exists($response, 'getData') ? (array) $response->getData(true) : [];
        $ok = (bool) ($payload['success'] ?? ($response->getStatusCode() < 400));
        if (! $ok) {
            return response()->json([
                'error' => 'failed',
                'message' => (string) ($payload['message'] ?? 'Request failed'),
            ], $response->getStatusCode() >= 400 ? $response->getStatusCode() : 422);
        }
        $item = $this->orders->findOwnOrFail($request->user(), $itemId);

        return response()->json([
            'data' => $this->serializer->order($request->user(), $item),
            'status' => PartnerOrderStatus::fromItem($item) ?: $fallback,
        ]);
    }

    /**
     * @param  callable(): JsonResponse  $callback
     */
    private function remember(Request $request, callable $callback): JsonResponse
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));
        if ($key === '' || ! Schema::hasTable('partner_idempotency_keys')) {
            return $callback();
        }
        $partner = $this->partner($request);
        $hash = hash('sha256', $request->method().'|'.$request->path().'|'.$request->getContent());
        $existing = PartnerIdempotencyKey::query()
            ->where('partner_id', $partner->id)
            ->where('key', $key)
            ->first();
        if ($existing) {
            if ($existing->request_hash !== $hash) {
                return response()->json(['error' => 'idempotency_conflict'], 409);
            }

            return response()->json($existing->response_body, (int) $existing->response_code);
        }
        $response = $callback();
        PartnerIdempotencyKey::query()->create([
            'partner_id' => $partner->id,
            'key' => $key,
            'request_hash' => $hash,
            'response_code' => $response->getStatusCode(),
            'response_body' => $response->getData(true),
        ]);

        return $response;
    }
}
