<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Advertiser\LibraryArticlePlacement;
use App\Support\AdvertiserOrderStatus;
use Tests\TestCase;

class LibraryArticlePlacementTest extends TestCase
{
    public function test_lock_message_names_the_reserved_site(): void
    {
        $service = new LibraryArticlePlacement;

        $this->assertSame(LibraryArticlePlacement::LOCK_MESSAGE, $service->lockMessage());
        $this->assertStringContainsString('Site Alpha', $service->lockMessage('Site Alpha'));
        $this->assertSame(3, $service->staleCartDays());
        $this->assertSame('Still in your cart — finish payment?', $service->staleHint());
    }

    public function test_cart_article_map_and_stale_flag(): void
    {
        config(['content_library.stale_cart_days' => 3]);
        $service = new LibraryArticlePlacement;
        $fresh = now()->subDay()->toIso8601String();
        $stale = now()->subDays(4)->toIso8601String();

        $map = $service->cartArticleMap([
            [
                'id' => 11,
                'name' => 'Fresh Site',
                'quantity' => 1,
                'content_submission_id' => 5,
                'content_assigned_at' => [0 => $fresh],
            ],
            [
                'id' => 12,
                'name' => 'Stale Site',
                'quantity' => 1,
                'content_submission_ids' => [0 => 6],
                'content_assigned_at' => [0 => $stale],
            ],
        ]);

        $this->assertFalse($map[5]['stale']);
        $this->assertTrue($map[6]['stale']);
        $this->assertSame([5, 6], $service->cartArticleIds([
            ['id' => 11, 'content_submission_id' => 5, 'quantity' => 1],
            ['id' => 12, 'content_submission_ids' => [0 => 6], 'quantity' => 1],
        ]));
    }

    public function test_advertiser_stage_maps_known_order_states(): void
    {
        $service = new LibraryArticlePlacement;

        $waiting = new Order(['status' => 'pending', 'payment_status' => 'paid']);
        $this->assertSame('Waiting for publisher', $service->advertiserStage($waiting));

        $review = new Order(['status' => 'review', 'payment_status' => 'paid']);
        $this->assertSame('In review', $service->advertiserStage($review));

        $approval = new Order(['status' => 'review', 'payment_status' => 'paid']);
        $item = new OrderItem(['live_url' => 'https://live.example/post']);
        $meta = AdvertiserOrderStatus::meta($approval, $item, true);
        $this->assertSame('url_delivered', $meta['stage']);
        $this->assertSame('Awaiting your approval', $service->advertiserStage($approval, $item));
    }
}
