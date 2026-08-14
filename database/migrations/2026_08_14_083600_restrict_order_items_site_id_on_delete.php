<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Keep order history when a site row is removed.
     * Application code also blocks delete when order items exist.
     */
    public function up(): void
    {
        $this->replaceSiteIdForeign('restrict');
    }

    public function down(): void
    {
        $this->replaceSiteIdForeign('cascade');
    }

    private function replaceSiteIdForeign(string $onDelete): void
    {
        if (! Schema::hasTable('order_items') || ! Schema::hasColumn('order_items', 'site_id')) {
            return;
        }

        if (! Schema::hasTable('sites')) {
            return;
        }

        $dropped = false;
        foreach (Schema::getForeignKeys('order_items') as $key) {
            $columns = $key['columns'] ?? [];
            if (! in_array('site_id', $columns, true)) {
                continue;
            }

            $name = $key['name'] ?? null;
            Schema::table('order_items', function (Blueprint $table) use ($name) {
                if (is_string($name) && $name !== '') {
                    $table->dropForeign($name);
                } else {
                    $table->dropForeign(['site_id']);
                }
            });
            $dropped = true;
        }

        if (! $dropped) {
            try {
                Schema::table('order_items', function (Blueprint $table) {
                    $table->dropForeign(['site_id']);
                });
            } catch (Throwable $e) {
                // No existing FK to drop (sqlite / partial schema).
            }
        }

        Schema::table('order_items', function (Blueprint $table) use ($onDelete) {
            $foreign = $table->foreign('site_id')->references('id')->on('sites');
            if ($onDelete === 'restrict') {
                $foreign->restrictOnDelete();
            } else {
                $foreign->cascadeOnDelete();
            }
        });
    }
};
