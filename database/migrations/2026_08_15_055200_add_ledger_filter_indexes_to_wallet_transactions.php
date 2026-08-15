<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin ledger filters and the default newest-first sort scan these columns.
 * user_id + created_at and reference already exist on the create migration.
 */
return new class extends Migration
{
    /**
     * @var array<string, string[]>
     */
    private array $indexes = [
        'wallet_transactions_created_at_index' => ['created_at'],
        'wallet_transactions_type_created_at_index' => ['type', 'created_at'],
        'wallet_transactions_status_created_at_index' => ['status', 'created_at'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('wallet_transactions')) {
            return;
        }

        $existing = $this->existingIndexNames();

        foreach ($this->indexes as $name => $columns) {
            if (in_array($name, $existing, true)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn('wallet_transactions', $column)) {
                    continue 2;
                }
            }

            Schema::table('wallet_transactions', function (Blueprint $table) use ($columns, $name) {
                $table->index($columns, $name);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('wallet_transactions')) {
            return;
        }

        $existing = $this->existingIndexNames();

        foreach (array_keys($this->indexes) as $name) {
            if (! in_array($name, $existing, true)) {
                continue;
            }

            Schema::table('wallet_transactions', function (Blueprint $table) use ($name) {
                $table->dropIndex($name);
            });
        }
    }

    /**
     * @return string[]
     */
    private function existingIndexNames(): array
    {
        try {
            return array_map(
                fn (array $index) => (string) $index['name'],
                Schema::getIndexes('wallet_transactions')
            );
        } catch (Throwable $e) {
            return [];
        }
    }
};
