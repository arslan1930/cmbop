<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Advertiser\AdvertiserApiSerializer;
use App\Services\Mcp\MarketplaceListing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdvertiserSiteController extends Controller
{
    public function __construct(
        private MarketplaceListing $listing,
        private AdvertiserApiSerializer $serializer,
    ) {}

    public function show(Request $request, string $id): JsonResponse
    {
        $site = ctype_digit($id)
            ? $this->listing->findVisibleSite((int) $id)
            : $this->listing->findVisibleSite(null, $id);

        $user = $request->user();
        if (! $site || ! $user || $site->isOwnedBy($user)) {
            return response()->json([
                'status' => 'error',
                'error' => 'not_found',
                'message' => 'No live listing matched that id or host.',
            ], 404);
        }

        return response()->json([
            'status' => 'ok',
            'data' => $this->serializer->site($user, $site),
        ]);
    }
}
