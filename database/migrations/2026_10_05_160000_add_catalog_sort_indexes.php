<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Default catalog sort is active + dr. last_completed_at uses MAX(completed_at).
 */
return new class extends Migration
{
    /**
     * @var array<string, array<string, string[]>>
     */
    private array $indexes = [
        'sites' => [
            'sites_active_dr_index' => ['active', 'dr'],
        ],
        'order_items' => [
            'order_items_site_id_completed_at_index' => ['site_id', 'completed_at'],
        ],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $definitions) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $existing = $this->existingIndexNames($table);

            foreach ($definitions as $name => $columns) {
                if (in_array($name, $existing, true)) {
                    continue;
                }

                if (! $this->hasAllColumns($table, $columns)) {
                    continue;
                }

                Schema::table($table, function (Blueprint $blueprint) use ($columns, $name) {
                    $blueprint->index($columns, $name);
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $table => $definitions) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $existing = $this->existingIndexNames($table);

            foreach (array_keys($definitions) as $name) {
                if (! in_array($name, $existing, true)) {
                    continue;
                }

                try {
                    Schema::table($table, function (Blueprint $blueprint) use ($name) {
                        $blueprint->dropIndex($name);
                    });
                } catch (Throwable $e) {
                    continue;
                }
            }
        }
    }

    /**
     * @return string[]
     */
    private function existingIndexNames(string $table): array
    {
        try {
            return array_map(
                fn (array $index) => (string) $index['name'],
                Schema::getIndexes($table)
            );
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * @param  string[]  $columns
     */
    private function hasAllColumns(string $table, array $columns): bool
    {
        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }
};
