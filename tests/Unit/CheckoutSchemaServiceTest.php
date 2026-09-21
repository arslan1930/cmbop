<?php

namespace Tests\Unit;

use App\Models\Site;
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
        $this->assertTrue(Schema::hasColumn('orders', 'admin_notes'));
        $this->assertTrue(Schema::hasColumn('orders', 'payment_reference'));
        $this->assertTrue(Schema::hasColumn('orders', 'paypal_order_id'));
        $this->assertTrue(Schema::hasColumn('orders', 'paypal_capture_id'));
        $this->assertTrue(Schema::hasColumn('orders', 'paypal_refund_id'));
        $this->assertTrue(Schema::hasColumn('orders', 'paypal_response'));
        $this->assertTrue(Schema::hasColumn('orders', 'project_id'));
        $this->assertTrue(Schema::hasTable('paypal_webhook_logs'));
        $this->assertTrue(Schema::hasColumn('sites', 'homepage_placement_prices'));
        $this->assertTrue(Schema::hasColumn('sites', 'social_promotion'));
        $this->assertTrue(Schema::hasColumn('order_items', 'homepage_days'));
        $this->assertTrue(Schema::hasColumn('order_items', 'social_channels'));
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

    public function test_ensure_repairs_homepage_placement_prices_so_where_not_null_count_works(): void
    {
        if (Schema::hasColumn('sites', 'homepage_placement_prices')) {
            Schema::table('sites', function ($table) {
                $table->dropColumn('homepage_placement_prices');
            });
        }

        $this->assertFalse(Schema::hasColumn('sites', 'homepage_placement_prices'));

        app(CheckoutSchemaService::class)->ensureCheckoutTables();

        $this->assertTrue(Schema::hasColumn('sites', 'homepage_placement_prices'));
        $this->assertSame(0, Site::countWithHomepagePlacement());
    }

    public function test_homepage_placement_count_is_zero_when_column_is_missing(): void
    {
        if (Schema::hasColumn('sites', 'homepage_placement_prices')) {
            Schema::table('sites', function ($table) {
                $table->dropColumn('homepage_placement_prices');
            });
        }

        $this->assertFalse(Schema::hasColumn('sites', 'homepage_placement_prices'));
        $this->assertSame(0, Site::countWithHomepagePlacement());
    }
}
