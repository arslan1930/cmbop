<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HotTableIndexesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<int, string[]>
     */
    private function indexedColumnSets(string $table): array
    {
        return array_map(
            fn (array $index) => array_map('strtolower', $index['columns']),
            Schema::getIndexes($table)
        );
    }

    private function assertIndexed(string $table, array $columns): void
    {
        $wanted = array_map('strtolower', $columns);
        $sets = $this->indexedColumnSets($table);

        // A composite index also serves queries filtering on its leading columns.
        $covered = collect($sets)->contains(
            fn (array $set) => array_slice($set, 0, count($wanted)) === $wanted
        );

        $this->assertTrue(
            $covered,
            sprintf(
                'Expected %s to be indexed on (%s). Found: %s',
                $table,
                implode(', ', $wanted),
                json_encode($sets)
            )
        );
    }

    public function test_orders_hot_filter_columns_are_indexed(): void
    {
        $this->assertIndexed('orders', ['status']);
        $this->assertIndexed('orders', ['payment_status']);
        $this->assertIndexed('orders', ['user_id', 'status']);
        $this->assertIndexed('orders', ['created_at']);
    }

    public function test_sites_catalog_filter_columns_are_indexed(): void
    {
        $this->assertIndexed('sites', ['country']);
        $this->assertIndexed('sites', ['language']);
        $this->assertIndexed('sites', ['price']);
        $this->assertIndexed('sites', ['active', 'verified']);
        $this->assertIndexed('sites', ['publisher_id', 'active']);
    }

    public function test_deposit_queue_columns_are_indexed(): void
    {
        $this->assertIndexed('deposit_requests', ['status']);
        $this->assertIndexed('deposit_requests', ['user_id', 'status']);
    }

    public function test_ledger_filter_columns_are_indexed(): void
    {
        $this->assertIndexed('wallet_transactions', ['created_at']);
        $this->assertIndexed('wallet_transactions', ['type', 'created_at']);
        $this->assertIndexed('wallet_transactions', ['status', 'created_at']);
        $this->assertIndexed('wallet_transactions', ['user_id', 'created_at']);
        $this->assertIndexed('wallet_transactions', ['reference']);
    }
}
