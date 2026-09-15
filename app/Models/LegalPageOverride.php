<?php

namespace App\Models;

use App\Support\PublicI18n;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class LegalPageOverride extends Model
{
    public const SLUG_PRIVACY = 'privacy-policy';

    public const SLUG_TERMS = 'terms-of-services';

    public const SLUG_COOKIE = 'cookie-policy';

    public const SLUG_REFUND = 'refund-policy';

    /**
     * @var array<string, array{label: string, view: string, hero: string, meta_title: string, meta_description: string}>
     */
    public const PAGES = [
        self::SLUG_PRIVACY => [
            'label' => 'Privacy policy',
            'view' => 'pages.privacy-policy',
            'hero' => 'privacy_hero_title',
            'meta_title' => 'meta_privacy_title',
            'meta_description' => 'meta_privacy_description',
        ],
        self::SLUG_TERMS => [
            'label' => 'Terms of service',
            'view' => 'pages.terms-of-services',
            'hero' => 'terms_hero_title',
            'meta_title' => 'meta_terms_title',
            'meta_description' => 'meta_terms_description',
        ],
        self::SLUG_COOKIE => [
            'label' => 'Cookie policy',
            'view' => 'pages.cookie-policy',
            'hero' => 'cookie_title',
            'meta_title' => 'meta_cookie_title',
            'meta_description' => 'meta_cookie_description',
        ],
        self::SLUG_REFUND => [
            'label' => 'Refund policy',
            'view' => 'pages.refund-policy',
            'hero' => 'refund_title',
            'meta_title' => 'meta_refund_title',
            'meta_description' => 'meta_refund_description',
        ],
    ];

    protected static ?bool $tableAvailable = null;

    protected $fillable = [
        'slug',
        'locale',
        'title',
        'body_html',
        'published_at',
        'updated_by',
    ];

    protected $casts = [
        'published_at' => 'datetime',
    ];

    public static function slugs(): array
    {
        return array_keys(self::PAGES);
    }

    public static function isKnownSlug(string $slug): bool
    {
        return isset(self::PAGES[$slug]);
    }

    public static function tableAvailable(): bool
    {
        if (static::$tableAvailable !== null) {
            return static::$tableAvailable;
        }

        try {
            return static::$tableAvailable = Schema::hasTable((new static)->getTable());
        } catch (\Throwable) {
            return static::$tableAvailable = false;
        }
    }

    /** @internal */
    public static function forgetTableAvailabilityCache(): void
    {
        static::$tableAvailable = null;
    }

    public static function ensureTable(): void
    {
        try {
            if (self::tableAvailable()) {
                return;
            }

            Schema::create((new static)->getTable(), function (Blueprint $table) {
                $table->id();
                $table->string('slug', 64);
                $table->string('locale', 8);
                $table->string('title')->nullable();
                $table->mediumText('body_html');
                $table->timestamp('published_at')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
                $table->unique(['slug', 'locale']);
            });

            static::forgetTableAvailabilityCache();
            Log::warning('legal_page_overrides table was missing — created at runtime');
        } catch (\Throwable $e) {
            Log::error('Could not create legal_page_overrides at runtime', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function publishedFor(string $slug, string $locale): ?self
    {
        if (! self::isKnownSlug($slug) || ! self::tableAvailable()) {
            return null;
        }

        try {
            return static::query()
                ->where('slug', $slug)
                ->where('locale', $locale)
                ->whereNotNull('published_at')
                ->first();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<string>
     */
    public static function locales(): array
    {
        if (class_exists(PublicI18n::class)) {
            return PublicI18n::supported();
        }

        return ['en'];
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
