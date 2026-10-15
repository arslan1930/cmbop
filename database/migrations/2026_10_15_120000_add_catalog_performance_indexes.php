<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MySQL-only generated columns + catalog filter/sort indexes.
 * SQLite PHPUnit skips this. Hostinger can also run
 * database/sql/add_catalog_performance_indexes.sql in phpMyAdmin.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        if (! Schema::hasTable('sites')) {
            return;
        }

        $this->addGeneratedColumn('country_norm', 'country');
        $this->addGeneratedColumn('language_norm', 'language');

        $this->addIndex('sites', 'sites_country_norm_index', 'country_norm');
        $this->addIndex('sites', 'sites_language_norm_index', 'language_norm');
        $this->addIndex('sites', 'sites_active_da_index', 'active, da, id');
        $this->addIndex('sites', 'sites_active_traffic_index', 'active, traffic, id');
        $this->addIndex('sites', 'sites_active_price_index', 'active, price, id');
        $this->addIndex('sites', 'sites_active_created_at_index', 'active, created_at, id');
        $this->addIndex('sites', 'sites_featured_until_index', 'featured_until');
        $this->addIndex('sites', 'sites_country_index', 'country');
        $this->addIndex('sites', 'sites_language_index', 'language');
        $this->addIndex('sites', 'sites_category_index', 'category');
        $this->addIndex('sites', 'sites_archived_at_index', 'archived_at');
        $this->addIndex('sites', 'sites_bulk_site_request_id_index', 'bulk_site_request_id');

        if (Schema::hasTable('bulk_site_requests')) {
            $this->addIndex('bulk_site_requests', 'bulk_site_requests_status_index', 'status');
        }

        if (Schema::hasTable('order_items')) {
            $this->addIndex('order_items', 'order_items_site_id_completed_at_index', 'site_id, completed_at');
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        foreach ([
            'sites' => [
                'sites_country_norm_index',
                'sites_language_norm_index',
                'sites_active_da_index',
                'sites_active_traffic_index',
                'sites_active_price_index',
                'sites_active_created_at_index',
                'sites_featured_until_index',
                'sites_country_index',
                'sites_language_index',
                'sites_category_index',
                'sites_archived_at_index',
                'sites_bulk_site_request_id_index',
            ],
            'bulk_site_requests' => [
                'bulk_site_requests_status_index',
            ],
            'order_items' => [
                'order_items_site_id_completed_at_index',
            ],
        ] as $table => $names) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($names as $name) {
                try {
                    DB::statement("DROP INDEX `{$name}` ON `{$table}`");
                } catch (Throwable) {
                }
            }
        }

        foreach (['country_norm', 'language_norm'] as $column) {
            if (Schema::hasColumn('sites', $column)) {
                try {
                    DB::statement("ALTER TABLE `sites` DROP COLUMN `{$column}`");
                } catch (Throwable) {
                }
            }
        }
    }

    private function addGeneratedColumn(string $column, string $source): void
    {
        if (Schema::hasColumn('sites', $column) || ! Schema::hasColumn('sites', $source)) {
            return;
        }

        try {
            DB::statement(
                "ALTER TABLE `sites` ADD COLUMN `{$column}` VARCHAR(16) GENERATED ALWAYS AS (LOWER(TRIM(`{$source}`))) STORED"
            );
        } catch (Throwable) {
        }
    }

    private function addIndex(string $table, string $name, string $columns): void
    {
        try {
            $existing = collect(Schema::getIndexes($table))->pluck('name')->all();
            if (in_array($name, $existing, true)) {
                return;
            }
        } catch (Throwable) {
            return;
        }

        $needed = array_map('trim', explode(',', $columns));
        foreach ($needed as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return;
            }
        }

        try {
            DB::statement("CREATE INDEX `{$name}` ON `{$table}` ({$columns})");
        } catch (Throwable) {
        }
    }
};
