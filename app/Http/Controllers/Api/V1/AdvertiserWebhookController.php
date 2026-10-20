<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AdvertiserWebhookEndpoint;
use App\Services\Advertiser\AdvertiserWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdvertiserWebhookController extends Controller
{
    public function __construct(private AdvertiserWebhookService $webhooks) {}

    public function index(Request $request): JsonResponse
    {
        $rows = AdvertiserWebhookEndpoint::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (AdvertiserWebhookEndpoint $row) => $this->webhooks->serializeEndpoint($row));

        return response()->json(['status' => 'ok', 'data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'url' => 'required|url|max:2000',
            'events' => 'nullable|array',
            'events.*' => 'string|max:80',
            'description' => 'nullable|string|max:255',
        ]);
        try {
            $created = $this->webhooks->createEndpoint(
                $request->user(),
                (string) $data['url'],
                $data['events'] ?? ['*'],
                $data['description'] ?? null
            );
        } catch (\RuntimeException $e) {
            return response()->json([
                'status' => 'error',
                'error' => 'validation_error',
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'status' => 'ok',
            'data' => $this->webhooks->serializeEndpoint($created['endpoint'], $created['secret']),
        ], 201);
    }

    public function show(Request $request, string $webhook): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'data' => $this->webhooks->serializeEndpoint($this->own($request, $webhook)),
        ]);
    }

    public function update(Request $request, string $webhook): JsonResponse
    {
        $data = $request->validate([
            'url' => 'nullable|url|max:2000',
            'events' => 'nullable|array',
            'events.*' => 'string|max:80',
            'status' => 'nullable|in:enabled,disabled',
            'description' => 'nullable|string|max:255',
        ]);
        $endpoint = $this->webhooks->updateEndpoint($this->own($request, $webhook), $data);

        return response()->json([
            'status' => 'ok',
            'data' => $this->webhooks->serializeEndpoint($endpoint),
        ]);
    }

    public function destroy(Request $request, string $webhook): JsonResponse
    {
        $this->webhooks->deleteEndpoint($this->own($request, $webhook));

        return response()->json(['status' => 'ok', 'data' => ['deleted' => true]]);
    }

    public function test(Request $request, string $webhook): JsonResponse
    {
        $endpoint = $this->own($request, $webhook);
        if (! $endpoint->isEnabled()) {
            return response()->json([
                'status' => 'error',
                'error' => 'webhook_disabled',
                'message' => 'Enable the webhook before sending a test event.',
            ], 422);
        }
        $event = $this->webhooks->sendTest($endpoint);

        return response()->json([
            'status' => 'ok',
            'data' => [
                'id' => $event?->public_id,
                'type' => 'webhook.test',
                'livemode' => false,
            ],
        ]);
    }

    private function own(Request $request, string $webhook): AdvertiserWebhookEndpoint
    {
        $id = (int) preg_replace('/^wh_/', '', $webhook);
        $row = AdvertiserWebhookEndpoint::query()
            ->where('user_id', $request->user()->id)
            ->where('id', $id)
            ->first();
        if (! $row) {
            abort(404);
        }

        return $row;
    }
}
