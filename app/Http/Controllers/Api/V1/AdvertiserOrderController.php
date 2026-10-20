<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\AdvertiserApiCannotCancelException;
use App\Exceptions\AdvertiserApiInsufficientFundsException;
use App\Http\Controllers\Controller;
use App\Services\Advertiser\AdvertiserApiIdempotency;
use App\Services\Advertiser\AdvertiserApiOrderService;
use App\Services\Advertiser\AdvertiserApiSerializer;
use App\Services\Advertiser\AdvertiserSandbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class AdvertiserOrderController extends Controller
{
    public function __construct(
        private AdvertiserApiOrderService $orders,
        private AdvertiserApiSerializer $serializer,
        private AdvertiserApiIdempotency $idempotency,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $page = $this->orders->paginate(
            $request->user(),
            AdvertiserSandbox::currentIsTest($request),
            (int) $request->input('per_page', 20)
        );

        return response()->json([
            'status' => 'ok',
            'data' => $page->getCollection()->map(
                fn ($order) => $this->serializer->order($request->user(), $order)
            )->values(),
            'meta' => [
                'total' => $page->total(),
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, string $order): JsonResponse
    {
        $row = $this->orders->findOwn(
            $request->user(),
            (int) $order,
            AdvertiserSandbox::currentIsTest($request)
        );
        if (! $row) {
            return $this->notFound();
        }

        return response()->json([
            'status' => 'ok',
            'data' => $this->serializer->order($request->user(), $row),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->idempotency->remember($request, $request->user(), function () use ($request) {
            $data = $request->validate([
                'site_id' => 'required|integer|min:1',
                'anchor' => 'required|string|max:255',
                'target_url' => 'required|url|max:2000',
                'content' => 'nullable|string|max:200000',
                'content_format' => 'nullable|in:html,markdown',
                'writing' => 'nullable|boolean',
            ]);

            try {
                $order = $this->orders->create(
                    $request->user(),
                    $data,
                    AdvertiserSandbox::currentIsTest($request)
                );
            } catch (AdvertiserApiInsufficientFundsException $e) {
                return response()->json([
                    'status' => 'error',
                    'error' => 'insufficient_funds',
                    'message' => $e->getMessage(),
                    'required' => $e->required,
                    'available' => $e->available,
                    'currency' => 'EUR',
                ], 402);
            } catch (RuntimeException $e) {
                return $this->fromRuntime($e);
            }

            return response()->json([
                'status' => 'ok',
                'data' => $this->serializer->order($request->user(), $order),
            ], 201);
        });
    }

    public function cancel(Request $request, string $order): JsonResponse
    {
        return $this->idempotency->remember($request, $request->user(), function () use ($request, $order) {
            $row = $this->orders->findOwn(
                $request->user(),
                (int) $order,
                AdvertiserSandbox::currentIsTest($request)
            );
            if (! $row) {
                return $this->notFound();
            }

            try {
                $fresh = $this->orders->cancel(
                    $request->user(),
                    $row,
                    AdvertiserSandbox::currentIsTest($request)
                );
            } catch (AdvertiserApiCannotCancelException $e) {
                return response()->json([
                    'status' => 'error',
                    'error' => 'cannot_cancel',
                    'message' => $e->getMessage(),
                ], 422);
            } catch (RuntimeException $e) {
                return $this->fromRuntime($e);
            }

            return response()->json([
                'status' => 'ok',
                'data' => $this->serializer->order($request->user(), $fresh),
            ]);
        });
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'error' => 'not_found',
            'message' => 'Order not found.',
        ], 404);
    }

    private function fromRuntime(RuntimeException $e): JsonResponse
    {
        $map = [
            'site_not_found' => [404, 'not_found', 'No live listing matched that site_id.'],
            'writing_not_offered' => [422, 'writing_not_offered', 'This listing does not offer publisher writing.'],
            'content_or_writing_required' => [422, 'validation_error', 'Send content (HTML or markdown) or writing=true when the site offers writing.'],
            'anchor_and_target_required' => [422, 'validation_error', 'anchor and target_url are required.'],
            'target_url_https_required' => [422, 'validation_error', 'target_url must be an https URL.'],
            'wallet_unavailable' => [503, 'wallet_unavailable', 'The advertiser wallet is temporarily unavailable.'],
            'sandbox_unavailable' => [503, 'sandbox_unavailable', 'Sandbox columns are missing. Apply database/sql/add_advertiser_ordering_api.sql.'],
            'order_not_found' => [404, 'not_found', 'Order not found.'],
        ];
        $code = $e->getMessage();
        if (isset($map[$code])) {
            [$status, $error, $message] = $map[$code];

            return response()->json([
                'status' => 'error',
                'error' => $error,
                'message' => $message,
            ], $status);
        }

        return response()->json([
            'status' => 'error',
            'error' => 'failed',
            'message' => $e->getMessage(),
        ], 422);
    }
}
