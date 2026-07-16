<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Models\Site;
use App\Models\SiteRating;
use Illuminate\Http\Request;

class SiteRatingController extends Controller
{
    /**
     * Rate a publisher site after the advertiser has approved/completed the order.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'order_item_id' => 'required|integer|exists:order_items,id',
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:500',
        ]);

        $result = $this->saveRatingForAdvertiser(
            (int) $data['order_item_id'],
            (int) $data['rating'],
            $data['comment'] ?? null
        );

        return response()->json($result['body'], $result['status']);
    }

    /**
     * Batch rate multiple order items (multi-site orders) after approval.
     */
    public function storeBatch(Request $request)
    {
        $data = $request->validate([
            'ratings' => 'required|array|min:1',
            'ratings.*.order_item_id' => 'required|integer|exists:order_items,id',
            'ratings.*.rating' => 'required|integer|min:1|max:5',
            'ratings.*.comment' => 'nullable|string|max:500',
        ]);

        $saved = [];
        foreach ($data['ratings'] as $row) {
            $result = $this->saveRatingForAdvertiser(
                (int) $row['order_item_id'],
                (int) $row['rating'],
                $row['comment'] ?? null
            );
            if (($result['status'] ?? 500) >= 400) {
                return response()->json($result['body'], $result['status']);
            }
            $saved[] = $result['body']['rating'] ?? null;
        }

        return response()->json([
            'success' => true,
            'message' => 'Ratings saved. Thank you!',
            'ratings' => $saved,
        ]);
    }

    /**
     * @return array{status:int, body:array}
     */
    private function saveRatingForAdvertiser(int $orderItemId, int $ratingValue, ?string $comment): array
    {
        $item = OrderItem::with(['order', 'site'])->findOrFail($orderItemId);
        $order = $item->order;

        if (! $order || (int) $order->user_id !== (int) auth()->id()) {
            return [
                'status' => 403,
                'body' => [
                    'success' => false,
                    'message' => 'Unauthorized: this order does not belong to you.',
                ],
            ];
        }

        if ($order->status !== 'completed') {
            return [
                'status' => 422,
                'body' => [
                    'success' => false,
                    'message' => 'You can rate a publisher only after approving the completed order.',
                ],
            ];
        }

        if (! $item->site_id) {
            return [
                'status' => 422,
                'body' => [
                    'success' => false,
                    'message' => 'This order item has no site to rate.',
                ],
            ];
        }

        $rating = SiteRating::updateOrCreate(
            ['order_item_id' => $item->id],
            [
                'site_id' => $item->site_id,
                'user_id' => auth()->id(),
                'order_id' => $order->id,
                'rating' => $ratingValue,
                'comment' => $comment,
                'status' => SiteRating::STATUS_APPROVED,
                'is_admin' => false,
            ]
        );

        SiteRating::refreshSiteAggregate((int) $item->site_id);
        $site = Site::find($item->site_id);

        return [
            'status' => 200,
            'body' => [
                'success' => true,
                'message' => 'Thanks — your rating helps other advertisers.',
                'rating' => $rating,
                'rating_avg' => (float) ($site->rating_avg ?? 0),
                'rating_count' => (int) ($site->rating_count ?? 0),
                'label' => $site?->ratingStarsLabel(),
            ],
        ];
    }
}
