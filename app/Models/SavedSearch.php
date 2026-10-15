<?php

namespace App\Models;

use App\Services\Catalog\CatalogUrlQuery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

class SavedSearch extends Model
{
    public const MAX_PER_USER = 25;

    protected $fillable = [
        'user_id',
        'name',
        'query_json',
        'notify',
        'last_alerted_at',
        'last_seen_max_id',
    ];

    protected $casts = [
        'query_json' => 'array',
        'notify' => 'boolean',
        'last_alerted_at' => 'datetime',
        'last_seen_max_id' => 'integer',
    ];

    public static function tableAvailable(): bool
    {
        try {
            return Schema::hasTable((new static)->getTable());
        } catch (\Throwable) {
            return false;
        }
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    public function catalogQuery(): array
    {
        $raw = is_array($this->query_json) ? $this->query_json : [];
        unset($raw['page']);

        return CatalogUrlQuery::canonicalize($raw);
    }

    public function catalogUrl(): string
    {
        return route('advertiser.catalog', $this->catalogQuery());
    }
}
