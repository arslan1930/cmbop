<?php

namespace App\Services\Advertiser;

use App\Models\AdvertiserIdempotencyKey;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class AdvertiserApiIdempotency
{
    /**
     * @param  callable(): JsonResponse  $callback
     */
    public function remember(Request $request, User $user, callable $callback, bool $required = true): JsonResponse
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));
        if ($key === '') {
            if ($required) {
                return response()->json([
                    'status' => 'error',
                    'error' => 'idempotency_key_required',
                    'message' => 'Send an Idempotency-Key header on this POST.',
                ], 422);
            }

            return $callback();
        }
        if (! Schema::hasTable('advertiser_idempotency_keys')) {
            return $callback();
        }

        $hash = hash('sha256', $request->method().'|'.$request->path().'|'.$request->getContent());
        $existing = AdvertiserIdempotencyKey::query()
            ->where('user_id', $user->id)
            ->where('key', $key)
            ->first();
        if ($existing) {
            if ($existing->request_hash !== $hash) {
                return response()->json([
                    'status' => 'error',
                    'error' => 'idempotency_conflict',
                    'message' => 'This Idempotency-Key was already used with a different body.',
                ], 409);
            }

            return response()->json($existing->response_body, (int) $existing->response_code);
        }

        $response = $callback();
        AdvertiserIdempotencyKey::query()->create([
            'user_id' => $user->id,
            'key' => $key,
            'request_hash' => $hash,
            'response_code' => $response->getStatusCode(),
            'response_body' => $response->getData(true),
        ]);

        return $response;
    }
}
