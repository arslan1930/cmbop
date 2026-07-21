<?php

namespace Tests\Unit;

use App\Services\CheckoutSchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CheckoutSchemaServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_ensure_checkout_tables_is_idempotent(): void
    {
        $svc = app(CheckoutSchemaService::class);
        $svc->ensureCheckoutTables();
        $svc->ensureCheckoutTables();

        $this->assertTrue(Schema::hasColumn('order_items', 'content_submission_id'));
        $this->assertTrue(Schema::hasColumn('order_items', 'publisher_price'));
        $this->assertTrue(Schema::hasColumn('orders', 'publication_mode'));
    }

    public function test_filter_existing_columns_drops_unknown_keys(): void
    {
        $svc = app(CheckoutSchemaService::class);
        $out = $svc->filterExistingColumns('order_items', [
            'order_id' => 1,
            'not_a_real_column_xyz' => 'nope',
            'price' => 10,
        ]);

        $this->assertArrayHasKey('order_id', $out);
        $this->assertArrayHasKey('price', $out);
        $this->assertArrayNotHasKey('not_a_real_column_xyz', $out);
    }
}
