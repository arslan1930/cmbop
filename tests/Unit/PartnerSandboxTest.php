<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Partners\PartnerSandbox;
use App\Services\Partners\PartnerSandboxUnavailableException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PartnerSandboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_columns_ready_is_true_when_schema_has_is_sandbox(): void
    {
        $this->assertTrue(Order::hasSandboxColumn());
        $this->assertTrue(OrderItem::hasSandboxColumn());
        $this->assertTrue(PartnerSandbox::columnsReady());
        PartnerSandbox::assertAvailable();
    }

    public function test_columns_ready_true_is_cached_for_the_process(): void
    {
        $this->assertTrue(PartnerSandbox::columnsReady());
        Order::forceSandboxColumnCache(false);
        OrderItem::forceSandboxColumnCache(false);
        $this->assertTrue(PartnerSandbox::columnsReady());
    }

    public function test_missing_columns_fail_closed_and_do_not_cache_false(): void
    {
        Order::forceSandboxColumnCache(false);
        $this->assertFalse(PartnerSandbox::columnsReady());
        Order::forgetSandboxColumnCache();
        OrderItem::forgetSandboxColumnCache();
        $this->assertTrue(PartnerSandbox::columnsReady());

        PartnerSandbox::forceColumnsReady(false);
        $this->assertFalse(PartnerSandbox::columnsReady());
        $this->expectException(PartnerSandboxUnavailableException::class);
        PartnerSandbox::assertAvailable();
    }

    public function test_unavailable_exception_renders_503_sandbox_unavailable_json(): void
    {
        $response = (new PartnerSandboxUnavailableException)->render();
        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('sandbox_unavailable', $response->getData(true)['error']);
    }

    public function test_missing_schema_columns_make_sandbox_unavailable(): void
    {
        $this->assertTrue(PartnerSandbox::columnsReady());
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['is_sandbox']);
            $table->dropColumn('is_sandbox');
        });
        PartnerSandbox::forgetColumnCache();
        $this->assertFalse(Order::hasSandboxColumn());
        $this->assertFalse(PartnerSandbox::columnsReady());
        $this->expectException(PartnerSandboxUnavailableException::class);
        PartnerSandbox::assertAvailable();
    }
}
