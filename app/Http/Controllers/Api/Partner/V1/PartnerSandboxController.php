<?php

namespace App\Http\Controllers\Api\Partner\V1;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\PartnerApiKey;
use App\Services\Partners\PartnerApiSerializer;
use App\Services\Partners\PartnerSandboxOrderService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PartnerSandboxController extends Controller
{
    public function __construct(
        private PartnerSandboxOrderService $sandbox,
        private PartnerApiSerializer $serializer,
    ) {}

    public function createOrder(Request $request): JsonResponse
    {
        $key = $request->attributes->get('partner_api_key');
        if (! $key instanceof PartnerApiKey || $key->mode !== 'test') {
            return response()->json([
                'error' => 'test_mode_required',
                'message' => 'POST /sandbox/orders is only available with slb_test_ keys.',
            ], 403);
        }
        $data = $request->validate([
            'site_id' => 'required|string',
            'anchor_text' => 'nullable|string|max:255',
            'target_url' => 'nullable|url|max:2000',
        ]);
        try {
            $item = $this->sandbox->create($this->partner($request), (string) $data['site_id'], $data);
        } catch (QueryException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            $code = $e->getMessage() === 'site_not_found' ? 422 : 400;

            return response()->json(['error' => $e->getMessage()], $code);
        }

        return response()->json([
            'data' => $this->serializer->order($request->user(), $item),
            'livemode' => false,
        ], 201);
    }

    private function partner(Request $request): Partner
    {
        $partner = $request->attributes->get('partner');
        if (! $partner instanceof Partner) {
            abort(401);
        }

        return $partner;
    }
}
