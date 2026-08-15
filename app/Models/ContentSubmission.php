<?php

namespace App\Models;

use App\Services\ContentUpload\ArticleDetectedLinks;
use App\Services\ContentUpload\ArticleHtmlSanitizer;
use App\Services\ContentUpload\ArticlePreviewHtml;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ContentSubmission extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /**
     * Legacy soft-fail status. The evaluator no longer emits this
     * (policy fails → rejected / error; uniqueness & quality are advisory).
     * Kept so old rows still appear under “Needs corrections”.
     */
    public const STATUS_NEEDS_IMPROVEMENT = 'needs_improvement';

    public const STATUS_ERROR = 'error';

    public const MODE_IMMEDIATE = 'immediate';

    public const MODE_SCHEDULED = 'scheduled';

    /** The article carries no images at all. */
    public const IMAGE_RIGHTS_NONE = 'none';

    public const UNAVAILABLE_MESSAGE = 'Content Library article is no longer available';

    public const CHECKOUT_LINK_MESSAGE = 'Add anchor text and a valid HTTPS target URL, or clear both link fields.';

    public const ACTIVE_ORDER_CLAIM_MESSAGE = 'This article is still attached to an unpaid or failed order. Pay again on that order, or start a new checkout to replace it.';

    /** Leftover / in-flight placements that still own the article. */
    public const ACTIVE_ORDER_CLAIM_PAYMENT_STATUSES = ['paid', 'pending', 'failed'];

    /** The advertiser owns or created every image. */
    public const IMAGE_RIGHTS_OWN = 'own';

    /** Images are licensed or sourced elsewhere; a source/credit is required. */
    public const IMAGE_RIGHTS_LICENSED = 'licensed';

    protected $fillable = [
        'user_id',
        'site_id',
        'copy_index',
        'cart_key',
        'original_filename',
        'title',
        'country',
        'language',
        'disk',
        'path',
        'mime',
        'extension',
        'size_bytes',
        'extracted_text',
        'preview_html',
        'word_count',
        'uniqueness_score',
        'quality_score',
        'evaluation_status',
        'evaluation_report',
        'evaluated_at',
        'approval_notified_at',
        'moderation_status',
        'moderation_log_id',
        'scan_token',
        'anchor_text',
        'target_url',
        'feature_image_url',
        'image_rights',
        'image_rights_source',
        'image_rights_declared_at',
        'publication_mode',
        'scheduled_publish_at',
        'timezone',
        'wizard_step',
        'draft_payload',
        'order_id',
        'order_item_id',
        'expires_at',
        'archived_at',
    ];

    protected $casts = [
        'copy_index' => 'integer',
        'size_bytes' => 'integer',
        'word_count' => 'integer',
        'uniqueness_score' => 'integer',
        'quality_score' => 'integer',
        'wizard_step' => 'integer',
        'draft_payload' => 'array',
        'evaluation_report' => 'array',
        'scheduled_publish_at' => 'datetime',
        'image_rights_declared_at' => 'datetime',
        'evaluated_at' => 'datetime',
        'approval_notified_at' => 'datetime',
        'expires_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function moderationLog(): BelongsTo
    {
        return $this->belongsTo(ContentModerationLog::class, 'moderation_log_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** Orderable approved articles stay marked “Just approved” for this many days. */
    public const JUST_APPROVED_DAYS = 7;

    public function isApproved(): bool
    {
        return $this->moderation_status === self::STATUS_APPROVED;
    }

    /**
     * True when we approved this article recently and it is still waiting to be ordered.
     */
    public function isJustApproved(): bool
    {
        if (! $this->isReadyForCheckout() || $this->evaluated_at === null) {
            return false;
        }

        $cutoff = now()->copy()->subDays(self::JUST_APPROVED_DAYS)->startOfDay();

        return $this->evaluated_at->copy()->startOfDay()->gte($cutoff);
    }

    /**
     * The “Just approved” chip is only for the same calendar day.
     * Older rows keep the relative line (Approved yesterday / N days ago).
     */
    public function showJustApprovedBadge(): bool
    {
        return $this->isJustApproved()
            && $this->evaluated_at !== null
            && $this->evaluated_at->isSameDay(now());
    }

    public function justApprovedLabel(): ?string
    {
        if (! $this->isJustApproved() || $this->evaluated_at === null) {
            return null;
        }

        if ($this->evaluated_at->isSameDay(now())) {
            return 'Approved today';
        }

        if ($this->evaluated_at->isSameDay(now()->subDay())) {
            return 'Approved yesterday';
        }

        $days = (int) abs($this->evaluated_at->copy()->startOfDay()->diffInDays(now()->copy()->startOfDay()));
        if ($days <= 0) {
            return 'Approved today';
        }
        if ($days === 1) {
            return 'Approved yesterday';
        }

        return 'Approved '.$days.' days ago';
    }

    public function needsCorrection(): bool
    {
        return in_array($this->moderation_status, [
            self::STATUS_NEEDS_IMPROVEMENT,
            self::STATUS_REJECTED,
            self::STATUS_ERROR,
        ], true);
    }

    public function canBeOrdered(): bool
    {
        // Uniqueness/quality are advisory only (same as ArticleEvaluationService):
        // approved + file + market + rights + not in use is enough to place an order.
        return $this->moderation_status === self::STATUS_APPROVED
            && $this->path
            && ! $this->ownerOrderBlocksOrdering()
            && ! $this->isArchived()
            && ($this->expires_at === null || $this->expires_at->isFuture())
            && filled($this->country)
            && filled($this->language)
            && $this->imageRightsCoverContent();
    }

    /**
     * True when another checkout already owns this article (direct order_id
     * or a paid/pending/failed, non-cancelled placement). Callers must lock
     * the row first. Cancelled leftovers stay reusable after refund/release.
     */
    public function isClaimedByAnotherOrder(?int $orderId = null): bool
    {
        if ($this->order_id !== null && ($orderId === null || (int) $this->order_id !== $orderId)) {
            $owner = $this->relatedOwnerOrder();
            if ($owner && $this->orderLooksLikeActiveClaim($owner, $orderId)) {
                return true;
            }
        }

        if (! Schema::hasColumn('order_items', 'content_submission_id')) {
            return false;
        }

        if ($this->relationLoaded('orderItems')) {
            return $this->orderItems->contains(function (OrderItem $item) use ($orderId) {
                if ($item->isClawedBack()) {
                    return false;
                }

                $order = $item->relationLoaded('order')
                    ? $item->order
                    : $item->order()->first();

                return $order instanceof Order
                    && $this->orderLooksLikeActiveClaim($order, $orderId);
            });
        }

        return $this->orderItems()
            ->whereHas('order', function ($q) use ($orderId) {
                $this->constrainActiveOrderClaim($q, $orderId);
            })
            ->tap(fn ($item) => $this->excludeClawedBackItems($item))
            ->exists();
    }

    /**
     * SQL mirror of hasCheckoutReadyLinks() — empty pair or a complete HTTPS pair.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeHasCheckoutReadyLinks($query)
    {
        return $query->whereRaw(self::checkoutReadyLinksSql($query->getModel()->getTable()));
    }

    /**
     * SQL negation of hasCheckoutReadyLinks() (half-filled or non-HTTPS target).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithoutCheckoutReadyLinks($query)
    {
        return $query->whereRaw('NOT ('.self::checkoutReadyLinksSql($query->getModel()->getTable()).')');
    }

    /**
     * SQL mirror of isReadyForCheckout() for list/exists queries.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCheckoutReady($query)
    {
        return $query->orderable()->hasCheckoutReadyLinks();
    }

    /**
     * Own unpaid leftover that Order / a new checkout may replace.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeReplaceableUnpaidLeftover($query)
    {
        return $query->where(function ($claim) {
            $claim->whereHas('order', function ($order) {
                $this->constrainReplaceableLeftoverOrder($order);
            });

            if (Schema::hasColumn('order_items', 'content_submission_id')) {
                $claim->orWhereHas('orderItems', function ($item) {
                    $item->whereHas('order', function ($order) {
                        $this->constrainReplaceableLeftoverOrder($order);
                    });
                });
            }
        });
    }

    /**
     * Catalog / wizard / cart pickers: free checkout-ready rows, plus this
     * advertiser's replaceable leftovers (unpaid pending, or failed card
     * kept for Pay again until they start a new checkout).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeAvailableForPicker($query)
    {
        return $query->where(function ($outer) {
            $outer->where(function ($ready) {
                $ready->checkoutReady();
            })->orWhere(function ($leftover) {
                $leftover->replaceableUnpaidLeftover()
                    ->where('moderation_status', self::STATUS_APPROVED)
                    ->hasCheckoutReadyLinks()
                    ->withImageRightsCover()
                    ->whereNotNull('path')
                    ->where('path', '!=', '')
                    ->whereNull('archived_at')
                    ->whereNotNull('country')->where('country', '!=', '')
                    ->whereNotNull('language')->where('language', '!=', '')
                    ->where(function ($exp) {
                        $exp->whereNull('expires_at')->orWhere('expires_at', '>', now());
                    });
            });
        });
    }

    /**
     * SQL mirror of canBeOrdered() for list/exists queries (cart, checkout, dashboard).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOrderable($query)
    {
        $query
            ->where('moderation_status', self::STATUS_APPROVED)
            ->withoutOpenOwnerOrder()
            ->whereNotNull('path')
            ->where('path', '!=', '')
            ->whereNull('archived_at')
            ->whereNotNull('country')->where('country', '!=', '')
            ->whereNotNull('language')->where('language', '!=', '')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->where(function ($q) {
                $q->where(function ($noImages) {
                    $noImages->whereNull('preview_html')
                        ->orWhere('preview_html', 'not like', '%<img%');
                })->orWhere('image_rights', self::IMAGE_RIGHTS_OWN)
                    ->orWhere(function ($licensed) {
                        $licensed->where('image_rights', self::IMAGE_RIGHTS_LICENSED)
                            ->whereNotNull('image_rights_source')
                            ->where('image_rights_source', '!=', '');
                    });
            });

        return $query->withoutActiveOrderClaim();
    }

    /**
     * Hide articles still pointed at by a paid, pending, or failed
     * (non-cancelled) order item. Cancelled leftovers stay orderable.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithoutActiveOrderClaim($query)
    {
        if (! Schema::hasColumn('order_items', 'content_submission_id')) {
            return $query;
        }

        return $query->whereDoesntHave('orderItems', function ($item) {
            $item->whereHas('order', function ($order) {
                $this->constrainActiveOrderClaim($order);
            });
            $this->excludeClawedBackItems($item);
        });
    }

    /**
     * Inverse of withoutActiveOrderClaim() for Needs corrections.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithActiveOrderClaim($query)
    {
        if (! Schema::hasColumn('order_items', 'content_submission_id')) {
            return $query->whereRaw('0 = 1');
        }

        return $query->whereHas('orderItems', function ($item) {
            $item->whereHas('order', function ($order) {
                $this->constrainActiveOrderClaim($order);
            });
            $this->excludeClawedBackItems($item);
        });
    }

    /**
     * Direct order_id still points at a non-cancelled order.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithOpenOwnerOrder($query)
    {
        return $query->whereHas('order', function ($order) {
            $order->where('status', '!=', 'cancelled');
        });
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithoutOpenOwnerOrder($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('order_id')
                ->orWhereDoesntHave('order', function ($order) {
                    $order->where('status', '!=', 'cancelled');
                });
        });
    }

    /**
     * No non-cancelled (and non-clawed) order item still points here.
     * Cancelled leftover rows keep content_submission_id on purpose —
     * they must not block retention strip of an unused expired file.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithoutOpenOrderItemLink($query)
    {
        $query->where(function ($q) {
            $q->whereNull('order_item_id')
                ->orWhereDoesntHave('orderItem', function ($item) {
                    $item->whereHas('order', function ($order) {
                        $order->where('status', '!=', 'cancelled');
                    });
                });
        });

        if (! Schema::hasColumn('order_items', 'content_submission_id')) {
            return $query;
        }

        return $query->whereDoesntHave('orderItems', function ($item) {
            $item->whereHas('order', function ($order) {
                $order->where('status', '!=', 'cancelled');
            });
            $this->excludeClawedBackItems($item);
        });
    }

    /**
     * Unused expired rows. A cancelled leftover's stale order_id is not a lock.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeExpiredUnused($query)
    {
        return $query->withoutOpenOwnerOrder()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }

    /**
     * Mid-evaluation uploads that are not on an open owner order.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeEvaluatingInLibrary($query)
    {
        return $query->whereIn('moderation_status', [
            self::STATUS_PENDING,
            self::STATUS_PROCESSING,
        ])->withoutOpenOwnerOrder()
            ->where(function ($exp) {
                $exp->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    /**
     * Approved unused articles approaching content:purge-expired.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeNearExpiryInLibrary($query, int $withinDays = 7)
    {
        return $query->where('moderation_status', self::STATUS_APPROVED)
            ->withoutOpenOwnerOrder()
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', now()->addDays(max(1, $withinDays)));
    }

    /**
     * SQL mirror of imageRightsCoverContent() — no images, or a covering claim.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithImageRightsCover($query)
    {
        return $query->where(function ($q) {
            $q->where(function ($noImages) {
                $noImages->whereNull('preview_html')
                    ->orWhere('preview_html', 'not like', '%<img%');
            })->orWhere('image_rights', self::IMAGE_RIGHTS_OWN)
                ->orWhere(function ($licensed) {
                    $licensed->where('image_rights', self::IMAGE_RIGHTS_LICENSED)
                        ->whereNotNull('image_rights_source')
                        ->where('image_rights_source', '!=', '');
                });
        });
    }

    /**
     * SQL negation of imageRightsCoverContent() for Needs corrections.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithoutImageRightsCover($query)
    {
        return $query->where(function ($img) {
            $img->where('preview_html', 'like', '%<img%')
                ->orWhere('preview_html', 'like', '%<IMG%');
        })->where(function ($claim) {
            $claim->whereNull('image_rights')
                ->orWhereNotIn('image_rights', [
                    self::IMAGE_RIGHTS_OWN,
                    self::IMAGE_RIGHTS_LICENSED,
                ])
                ->orWhere(function ($licensedNoSource) {
                    $licensedNoSource->where('image_rights', self::IMAGE_RIGHTS_LICENSED)
                        ->where(function ($src) {
                            $src->whereNull('image_rights_source')
                                ->orWhere('image_rights_source', '');
                        });
                });
        });
    }

    /**
     * SQL mirror of libraryAvailability() === 'in_progress'.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeInProgressInLibrary($query)
    {
        return $query->where('moderation_status', self::STATUS_APPROVED)
            ->whereNotNull('path')->where('path', '!=', '')
            ->whereNotNull('country')->where('country', '!=', '')
            ->whereNotNull('language')->where('language', '!=', '')
            ->whereNull('archived_at')
            ->withOpenOwnerOrder()
            ->hasCheckoutReadyLinks()
            ->withImageRightsCover()
            ->withoutCurrentLivePlacement();
    }

    /**
     * Current owner line is live. A sibling's live URL on the same order,
     * or a historical URL on a cancelled leftover, must not keep this
     * article in Completed.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithCurrentLivePlacement($query)
    {
        return $query->withOpenOwnerOrder()
            ->whereHas('orderItems', function ($item) use ($query) {
                $this->constrainCurrentOwnerLiveItem($item, $query->getModel()->getTable());
            });
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithoutCurrentLivePlacement($query)
    {
        return $query->whereDoesntHave('orderItems', function ($item) use ($query) {
            $this->constrainCurrentOwnerLiveItem($item, $query->getModel()->getTable());
        });
    }

    /**
     * Rejected / error articles, plus approved articles that still need image
     * rights, a complete checkout link, or release from a leftover order.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeNeedsLibraryFix($query)
    {
        $query
            ->where(function ($q) {
                $q->whereIn('moderation_status', [
                    self::STATUS_NEEDS_IMPROVEMENT,
                    self::STATUS_REJECTED,
                    self::STATUS_ERROR,
                ])->orWhere(function ($rights) {
                    $rights->where('moderation_status', self::STATUS_APPROVED)
                        ->withoutOpenOwnerOrder()
                        ->withoutImageRightsCover();
                })->orWhere(function ($links) {
                    $links->orderable()->withoutCheckoutReadyLinks();
                })->orWhere(function ($ownedUnready) {
                    $ownedUnready->where('moderation_status', self::STATUS_APPROVED)
                        ->withOpenOwnerOrder()
                        ->whereNull('archived_at')
                        ->where(function ($unready) {
                            $unready->withoutCheckoutReadyLinks()
                                ->orWhere(function ($rights) {
                                    $rights->withoutImageRightsCover();
                                });
                        });
                })->orWhere(function ($leftover) {
                    $leftover->where('moderation_status', self::STATUS_APPROVED)
                        ->withoutOpenOwnerOrder()
                        ->whereNull('archived_at')
                        ->withActiveOrderClaim();
                });
            })
            ->where(function ($exp) {
                $exp->where(function ($active) {
                    $active->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })->orWhere(function ($owned) {
                    $owned->withOpenOwnerOrder();
                });
            });

        return $query;
    }

    /**
     * Cart / wizard / catalog pickers only need identity + orderability fields.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForArticlePicker($query)
    {
        $table = $query->getModel()->getTable();

        return $query->select([
            $table.'.id',
            $table.'.user_id',
            $table.'.title',
            $table.'.original_filename',
            $table.'.language',
            $table.'.country',
            $table.'.word_count',
            $table.'.moderation_status',
            $table.'.path',
            $table.'.order_id',
            $table.'.archived_at',
            $table.'.expires_at',
            $table.'.anchor_text',
            $table.'.target_url',
            $table.'.image_rights',
            $table.'.image_rights_source',
        ])->selectRaw(
            'CASE WHEN '.$table.'.preview_html LIKE \'%<img%\' OR '.$table.'.preview_html LIKE \'%<IMG%\' THEN 1 ELSE 0 END as has_images'
        );
    }

    /**
     * List pages only need a preview flag — not the article body (10 MB HTML/text).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForLibraryList($query)
    {
        $table = $query->getModel()->getTable();
        $omit = ['extracted_text', 'preview_html'];
        $columns = array_values(array_filter(
            Schema::getColumnListing($table),
            fn (string $column) => ! in_array($column, $omit, true)
        ));
        $prefixed = array_map(fn (string $column) => $table.'.'.$column, $columns);

        return $query
            ->select($prefixed)
            ->selectRaw(
                'CASE WHEN '.$table.'.preview_html IS NOT NULL AND '.$table.'.preview_html != \'\' THEN 1 ELSE 0 END as has_preview_html'
            )
            ->selectRaw(
                'CASE WHEN '.$table.'.preview_html LIKE \'%<img%\' OR '.$table.'.preview_html LIKE \'%<IMG%\' THEN 1 ELSE 0 END as has_images'
            );
    }

    /**
     * Checkout cards need history + a short excerpt — not the full 10 MB body.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForCheckoutSummary($query)
    {
        $table = $query->getModel()->getTable();

        return $query->forLibraryList()
            ->selectRaw('substr('.$table.'.preview_html, 1, 1500) as preview_excerpt');
    }

    /**
     * Plain-text snippet for the clipped checkout article card.
     */
    public function checkoutPreviewText(int $limit = 280): string
    {
        $raw = (string) ($this->attributes['preview_excerpt'] ?? '');
        if ($raw === '' && array_key_exists('preview_html', $this->attributes)) {
            $raw = (string) ($this->preview_html ?? '');
        }
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($raw)));
        if ($text === '') {
            return '';
        }

        return Str::limit($text, $limit);
    }

    /**
     * True when the article has preview HTML. Uses the list-query flag when
     * preview_html was not selected.
     */
    public function hasPreviewHtml(): bool
    {
        if (array_key_exists('has_preview_html', $this->attributes)) {
            return (int) $this->attributes['has_preview_html'] === 1;
        }

        return filled($this->preview_html);
    }

    /**
     * @return list<string>
     */
    public static function imageRightsOptions(): array
    {
        return [self::IMAGE_RIGHTS_NONE, self::IMAGE_RIGHTS_OWN, self::IMAGE_RIGHTS_LICENSED];
    }

    /**
     * Licensed or sourced images have to name where they came from.
     */
    public static function imageRightsNeedsSource(?string $rights): bool
    {
        return $rights === self::IMAGE_RIGHTS_LICENSED;
    }

    /**
     * True when the article contains at least one image in its preview HTML.
     * Uses the list-query flag when preview_html was not selected.
     */
    public function hasImages(): bool
    {
        if (array_key_exists('preview_html', $this->attributes)) {
            return (bool) preg_match('/<img\b/i', (string) $this->preview_html);
        }

        if (array_key_exists('has_images', $this->attributes)) {
            return (int) $this->attributes['has_images'] === 1;
        }

        return false;
    }

    /**
     * The declaration must cover what the article actually contains: an article
     * declared image-free cannot keep images added later in the editor.
     * Articles with images and no covering claim (own / licensed) must declare
     * before save — including new uploads that skip rights until after parse.
     */
    public function imageRightsCoverContent(): bool
    {
        if (! $this->hasImages()) {
            return true;
        }

        if ($this->image_rights === self::IMAGE_RIGHTS_OWN) {
            return true;
        }

        return $this->image_rights === self::IMAGE_RIGHTS_LICENSED
            && filled($this->image_rights_source);
    }

    public function isInUse(): bool
    {
        if ($this->order_id === null) {
            return false;
        }

        $owner = $this->relatedOwnerOrder();

        return $owner instanceof Order && $owner->status !== 'cancelled';
    }

    /**
     * True when a non-cancelled, non-refunded order item still points here.
     * Upheld clawbacks are not a link — those lines already released the article.
     */
    public function isLinkedToOpenOrderItem(): bool
    {
        if ($this->isInUse()) {
            return true;
        }

        if (! Schema::hasColumn('order_items', 'content_submission_id')) {
            return false;
        }

        if ($this->relationLoaded('orderItems')) {
            return $this->orderItems->contains(function (OrderItem $item) {
                if ($item->isClawedBack()) {
                    return false;
                }

                $order = $item->relationLoaded('order')
                    ? $item->order
                    : $item->order()->first();

                return $order instanceof Order
                    && $order->status !== 'cancelled'
                    && $order->payment_status !== 'refunded';
            });
        }

        return $this->orderItems()
            ->whereHas('order', function ($q) {
                $q->where('status', '!=', 'cancelled')
                    ->where('payment_status', '!=', 'refunded');
            })
            ->tap(fn ($item) => $this->excludeClawedBackItems($item))
            ->exists();
    }

    public function isExpired(): bool
    {
        // Match content:purge-expired (`expires_at <= now()`). Carbon isPast() is
        // strictly before now, which would leave the exact expiry instant orderable
        // in the UI while the nightly strip already treats it as expired.
        return $this->expires_at !== null && ! $this->expires_at->isFuture();
    }

    public function hasStoredFile(): bool
    {
        return filled($this->path);
    }

    /**
     * Advertiser/publisher may download the original Word file.
     * Unused expired articles are preview-only even before the nightly strip.
     */
    public function canDownloadOriginal(): bool
    {
        if (! $this->hasStoredFile()) {
            return false;
        }

        if ($this->isInUse()) {
            return true;
        }

        return ! $this->isExpired();
    }

    public function canEditArticle(): bool
    {
        return ! $this->isLockedByPaidOrder() && ! $this->isArchived() && ! $this->isExpired();
    }

    /**
     * Paid, non-cancelled owner — the article is already in fulfillment.
     * Pending/failed leftovers stay editable so Pay again can be unblocked.
     * Also locks when a paid line still points here after a stale cancelled
     * order_id (legacy leftover that was reused without rewriting ownership).
     */
    public function isLockedByPaidOrder(): bool
    {
        $owner = $this->relatedOwnerOrder();
        if ($owner instanceof Order
            && $owner->status !== 'cancelled'
            && $owner->payment_status === 'paid') {
            return true;
        }

        if (! Schema::hasColumn('order_items', 'content_submission_id')) {
            return false;
        }

        if ($this->relationLoaded('orderItems')) {
            return $this->orderItems->contains(function (OrderItem $item) {
                if ($item->isClawedBack()) {
                    return false;
                }

                $order = $item->relationLoaded('order')
                    ? $item->order
                    : $item->order()->first();

                return $order instanceof Order
                    && $order->status !== 'cancelled'
                    && $order->payment_status === 'paid';
            });
        }

        return $this->orderItems()
            ->whereHas('order', function ($q) {
                $q->where('status', '!=', 'cancelled')
                    ->where('payment_status', 'paid');
            })
            ->tap(fn ($item) => $this->excludeClawedBackItems($item))
            ->exists();
    }

    /**
     * Checkout / revision may rewrite order_id when the row is free or already
     * owned by this order. A cancelled leftover's stale order_id is not a lock.
     */
    public function shouldAdoptOwnerOrder(int $orderId): bool
    {
        return ! $this->isInUse() || (int) ($this->order_id ?? 0) === $orderId;
    }

    /**
     * Own leftover (Wise/bank/crypto/legacy card, including failed card
     * kept for Pay again) that Order / a new checkout may replace.
     */
    public function canReplaceUnpaidLeftover(): bool
    {
        $owner = $this->relatedOwnerOrder();
        if ($owner instanceof Order) {
            return $this->orderLooksLikeReplaceableLeftover($owner);
        }

        if (! Schema::hasColumn('order_items', 'content_submission_id')) {
            return false;
        }

        if ($this->relationLoaded('orderItems')) {
            return $this->orderItems->contains(function (OrderItem $item) {
                $order = $item->relationLoaded('order')
                    ? $item->order
                    : $item->order()->first();

                return $order instanceof Order
                    && $this->orderLooksLikeReplaceableLeftover($order);
            });
        }

        return $this->orderItems()
            ->whereHas('order', function ($q) {
                $this->constrainReplaceableLeftoverOrder($q);
            })
            ->exists();
    }

    /**
     * Catalog / wizard / cart may list this row. Replace runs on assign,
     * checkout, or Order — not when the picker merely opens.
     */
    public function isAvailableForPicker(): bool
    {
        if ($this->isReadyForCheckout()) {
            return true;
        }

        if (! $this->canReplaceUnpaidLeftover()) {
            return false;
        }

        return $this->moderation_status === self::STATUS_APPROVED
            && filled($this->path)
            && ! $this->isArchived()
            && ($this->expires_at === null || $this->expires_at->isFuture())
            && filled($this->country)
            && filled($this->language)
            && $this->imageRightsCoverContent()
            && $this->hasCheckoutReadyLinks();
    }

    /**
     * @param  Builder<Order>|Builder  $orderQuery
     */
    protected function constrainReplaceableLeftoverOrder($orderQuery): void
    {
        $orderQuery->where('status', 'pending')
            ->where(function ($payment) {
                $payment->whereNull('payment_status')
                    ->orWhereNotIn('payment_status', ['paid', 'refunded']);
            });
    }

    protected function orderLooksLikeReplaceableLeftover(Order $order): bool
    {
        return $order->status === 'pending'
            && ! in_array((string) $order->payment_status, ['paid', 'refunded'], true);
    }

    /**
     * Unused approved articles approaching retention purge (content:purge-expired).
     */
    public function isNearExpiry(int $withinDays = 7): bool
    {
        if ($this->expires_at === null || $this->isExpired() || $this->isArchived() || $this->isInUse()) {
            return false;
        }

        return $this->expires_at->lessThanOrEqualTo(now()->addDays(max(1, $withinDays)));
    }

    /**
     * Whole days until expires_at (null when no expiry / already expired).
     */
    public function daysUntilExpiry(): ?int
    {
        if ($this->expires_at === null) {
            return null;
        }

        if ($this->isExpired()) {
            return 0;
        }

        return (int) now()->startOfDay()->diffInDays($this->expires_at->copy()->startOfDay());
    }

    /**
     * Split evaluation checks into blocking fails vs advisory warnings for library UX.
     *
     * @return array{blocking: list<string>, advisory: list<string>}
     */
    public function evaluationReasonGroups(): array
    {
        $report = is_array($this->evaluation_report) ? $this->evaluation_report : [];
        $blocking = [];
        $advisory = [];
        $checks = is_array($report['checks'] ?? null) ? $report['checks'] : [];

        foreach ($checks as $check) {
            if (! is_array($check)) {
                continue;
            }
            $status = strtolower(scalar_text($check['status'] ?? ''));
            $detail = trim(scalar_text($check['detail'] ?? $check['label'] ?? ''));
            if ($detail === '') {
                continue;
            }
            if ($status === 'fail') {
                $blocking[] = $detail;
            } elseif ($status === 'warn') {
                $advisory[] = $detail;
            }
        }

        $summary = trim(scalar_text($report['summary'] ?? ''));
        if ($blocking === [] && $summary !== '' && $this->needsCorrection()) {
            $blocking[] = $summary;
        }

        return [
            'blocking' => array_values(array_unique($blocking)),
            'advisory' => array_values(array_unique($advisory)),
        ];
    }

    /**
     * One-line library / email summary. Nested JSON must not reach Blade {{ }}.
     */
    public function evaluationSummary(): string
    {
        $report = is_array($this->evaluation_report) ? $this->evaluation_report : [];
        $summary = trim(scalar_text($report['summary'] ?? ''));

        return $summary !== '' ? $summary : 'Fix issues and resubmit.';
    }

    /**
     * Library Needs corrections copy. Do not show the approval/order sentence
     * when the only blocker is undeclared image rights.
     */
    public function libraryFixSummary(): string
    {
        if (! $this->needsCorrection()) {
            $notice = $this->editorNotice();
            if ($notice !== '') {
                return $notice;
            }
        }

        return $this->evaluationSummary();
    }

    /**
     * Shown in Edit article when the user reopens a rejected or undeclared article.
     */
    public function editorNotice(): string
    {
        if ($this->needsCorrection()) {
            return $this->evaluationSummary();
        }

        if ($this->hasImages() && ! $this->imageRightsCoverContent()) {
            return 'This article contains images. Confirm you own them, or add the source URL or copyright details.';
        }

        if (! $this->hasCheckoutReadyLinks() && ($this->canBeOrdered() || $this->canEditArticle())) {
            return self::CHECKOUT_LINK_MESSAGE;
        }

        if ($this->canBeOrdered() && $this->isClaimedByAnotherOrder()) {
            return self::ACTIVE_ORDER_CLAIM_MESSAGE;
        }

        return '';
    }

    /**
     * @return list<string>
     */
    public function evaluationMatchedTerms(): array
    {
        $report = is_array($this->evaluation_report) ? $this->evaluation_report : [];

        return scalar_list($report['matched_terms'] ?? []);
    }

    /**
     * @return list<string>
     */
    public function evaluationBlockedUrls(): array
    {
        $report = is_array($this->evaluation_report) ? $this->evaluation_report : [];

        return scalar_list($report['blocked_urls'] ?? []);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function archive(): void
    {
        if ($this->isArchived()) {
            return;
        }

        $this->forceFill(['archived_at' => now()])->save();
    }

    public function restoreFromArchive(): void
    {
        if (! $this->isArchived()) {
            return;
        }

        $this->forceFill(['archived_at' => null])->save();
    }

    /**
     * Primary placement item used for library status + live URL.
     */
    public function placementItem(): ?OrderItem
    {
        if ($this->order_item_id) {
            if ($this->relationLoaded('orderItem') && $this->orderItem) {
                return $this->orderItem;
            }
            if ($this->relationLoaded('orderItems')) {
                $owned = $this->orderItems->firstWhere('id', (int) $this->order_item_id);
                if ($owned) {
                    return $owned;
                }
            }

            return $this->orderItem()->with('site')->first();
        }

        $items = $this->relationLoaded('orderItems')
            ? $this->orderItems
            : $this->orderItems()->with(['site', 'order'])->orderBy('id')->get();

        if ($this->order_id) {
            $onOwner = $items->first(function (OrderItem $item) {
                return (int) $item->order_id === (int) $this->order_id
                    && (int) ($item->content_submission_id ?? 0) === (int) $this->id;
            });
            if ($onOwner) {
                return $onOwner;
            }
        }

        return $items->first(function (OrderItem $item) {
            if ($item->isClawedBack()) {
                return false;
            }

            $order = $item->relationLoaded('order')
                ? $item->order
                : $item->order()->first();

            return $order instanceof Order && $order->status !== 'cancelled';
        });
    }

    public function liveUrl(): ?string
    {
        if (! $this->isInUse()) {
            return null;
        }

        $item = $this->placementItem();
        if (! $item || ! $item->hasLiveUrl()) {
            return null;
        }

        return trim((string) $item->live_url) ?: null;
    }

    /**
     * Timeline events for order summary / library UX.
     *
     * @return array<int, array{at:?string, label:string, detail:?string}>
     */
    public function articleHistory(): array
    {
        $events = [];

        $events[] = [
            'at' => optional($this->created_at)?->toIso8601String(),
            'label' => 'Uploaded',
            'detail' => $this->original_filename ?: ($this->title ?: 'Article'),
        ];

        $payload = is_array($this->draft_payload) ? $this->draft_payload : [];
        $edits = is_array($payload['content_history'] ?? null) ? $payload['content_history'] : [];
        foreach ($edits as $edit) {
            if (! is_array($edit)) {
                continue;
            }
            $events[] = [
                'at' => $edit['at'] ?? null,
                'label' => 'Edited',
                'detail' => trim(implode(' · ', array_filter([
                    isset($edit['word_count']) ? ((int) $edit['word_count']).' words' : null,
                    ! empty($edit['has_images']) ? 'with images' : null,
                    isset($edit['link_count']) ? ((int) $edit['link_count']).' link(s)' : null,
                ]))) ?: null,
            ];
        }

        if ($this->evaluated_at) {
            $scoreBits = array_filter([
                $this->uniqueness_score !== null ? 'Uniqueness '.$this->uniqueness_score.'%' : null,
                $this->quality_score !== null ? 'Quality '.$this->quality_score.'%' : null,
                $this->moderation_status ? str_replace('_', ' ', (string) $this->moderation_status) : null,
            ]);
            $events[] = [
                'at' => $this->evaluated_at->toIso8601String(),
                'label' => 'Evaluated',
                'detail' => $scoreBits !== [] ? implode(' · ', $scoreBits) : null,
            ];
        }

        $items = $this->relationLoaded('orderItems')
            ? $this->orderItems
            : $this->orderItems()->with(['site', 'order'])->orderBy('id')->get();

        foreach ($items as $item) {
            $siteName = $item->site_name
                ?: $item->site?->site_name
                ?: $item->site_url
                ?: $item->site?->site_url
                ?: 'Website';
            $status = $item->publisher_status ?? $item->status ?? null;
            $events[] = [
                'at' => optional($item->created_at)?->toIso8601String(),
                'label' => 'Ordered',
                'detail' => trim($siteName.($status ? ' · '.str_replace('_', ' ', (string) $status) : '')),
            ];
            if ($item->hasLiveUrl()) {
                $events[] = [
                    'at' => optional($item->updated_at)?->toIso8601String(),
                    'label' => 'Published',
                    'detail' => $item->live_url,
                ];
            }
        }

        usort($events, function (array $a, array $b): int {
            return strcmp((string) ($a['at'] ?? ''), (string) ($b['at'] ?? ''));
        });

        return $events;
    }

    public function isPublished(): bool
    {
        if (! $this->isInUse()) {
            return false;
        }

        $item = $this->placementItem();
        if (! $item) {
            return false;
        }

        if ($item->hasLiveUrl()) {
            return true;
        }

        // publisher_status exists in some environments but is not guaranteed by migrations.
        if (Schema::hasColumn('order_items', 'publisher_status')) {
            return in_array((string) $item->publisher_status, ['completed'], true);
        }

        return false;
    }

    /**
     * Library-facing availability for filters and badges.
     *
     * @return 'available'|'evaluating'|'in_progress'|'published'|'expired'|'archived'|'needs_fix'|'unavailable'
     */
    public function libraryAvailability(): string
    {
        if ($this->isArchived()) {
            return 'archived';
        }

        if ($this->isPublished()) {
            return 'published';
        }

        if ($this->isInUse()) {
            $ownerId = (int) ($this->order_id ?? 0);
            if ($ownerId > 0 && ! $this->isReadyToFulfill($ownerId)) {
                return 'needs_fix';
            }

            return 'in_progress';
        }

        if ($this->isExpired()) {
            return 'expired';
        }

        if ($this->needsCorrection()) {
            return 'needs_fix';
        }

        if ($this->moderation_status === self::STATUS_APPROVED
            && $this->hasImages()
            && ! $this->imageRightsCoverContent()) {
            return 'needs_fix';
        }

        if ($this->isEvaluating()) {
            return 'evaluating';
        }

        if ($this->canBeOrdered() && ! $this->isReadyForCheckout()) {
            return 'needs_fix';
        }

        if ($this->isReadyForCheckout()) {
            return 'available';
        }

        return 'unavailable';
    }

    /**
     * Mid-evaluation upload (pending / processing). Not a library status tab.
     */
    public function isEvaluating(): bool
    {
        return in_array($this->moderation_status, [
            self::STATUS_PENDING,
            self::STATUS_PROCESSING,
        ], true) && ! $this->isInUse() && ! $this->isArchived();
    }

    /**
     * Soft language fit for prefer-sort / cart warnings.
     * Empty site language metadata = legacy listing → treat as fit.
     */
    public function languageFitsSite(Site $site): bool
    {
        return self::languageFitsSiteLanguages((string) ($this->language ?? ''), $site->languageCodes());
    }

    /**
     * Hard placement gate when require_same_language is enabled; otherwise always true.
     */
    public function matchesSite(Site $site, bool $requireSameLanguage = false): bool
    {
        if (! $requireSameLanguage) {
            return true;
        }

        return $this->languageFitsSite($site);
    }

    /**
     * @param  array<int, string>  $siteLanguages
     */
    public static function languageFitsSiteLanguages(string $articleLanguage, array $siteLanguages): bool
    {
        $article = strtolower(trim($articleLanguage));
        $langs = array_values(array_unique(array_filter(array_map(
            static fn ($c) => strtolower(trim((string) $c)),
            $siteLanguages
        ))));

        if ($article === '' || $langs === []) {
            return true;
        }

        return in_array($article, $langs, true);
    }

    /**
     * Human-readable mismatch for cart UI (null when languages fit or unknown).
     *
     * @param  array<int, string>  $siteLanguages
     */
    public static function languageMismatchLabel(string $articleLanguage, array $siteLanguages): ?string
    {
        if (self::languageFitsSiteLanguages($articleLanguage, $siteLanguages)) {
            return null;
        }

        $article = strtoupper(trim($articleLanguage));
        $site = strtoupper(implode('/', array_map(
            static fn ($c) => trim((string) $c),
            array_values(array_filter($siteLanguages))
        )));

        if ($article === '' || $site === '') {
            return null;
        }

        return "Site {$site} · article {$article}";
    }

    /**
     * Release library ownership so the article can be ordered again
     * (e.g. after Stripe cancel or scheduled-order cancel).
     */
    public function releaseFromOrder(): void
    {
        $this->forceFill([
            'order_id' => null,
            'order_item_id' => null,
        ])->save();
    }

    /**
     * Free every library article tied to an order (direct order_id or line link)
     * so it can be placed again after cancel / reject / refund.
     */
    public static function releaseAllForOrder(int $orderId): void
    {
        if ($orderId <= 0) {
            return;
        }

        static::query()
            ->where('order_id', $orderId)
            ->get()
            ->each(fn (self $submission) => $submission->releaseFromOrder());

        if (! Schema::hasColumn('order_items', 'content_submission_id')) {
            return;
        }

        $linkedIds = OrderItem::query()
            ->where('order_id', $orderId)
            ->whereNotNull('content_submission_id')
            ->pluck('content_submission_id')
            ->all();

        if ($linkedIds === []) {
            return;
        }

        // Only free rows still owned by this leftover. A cancelled leftover
        // item can keep content_submission_id after the article was reused
        // on a newer paid order — do not steal that ownership.
        static::query()
            ->whereIn('id', $linkedIds)
            ->where(function ($q) use ($orderId) {
                $q->whereNull('order_id')
                    ->orWhere('order_id', $orderId);
            })
            ->get()
            ->each(fn (self $submission) => $submission->releaseFromOrder());
    }

    /**
     * Free the library article on one placement (dispute clawback),
     * without unlocking sibling lines on the same order.
     */
    public static function releaseAllForOrderItem(int $orderItemId): void
    {
        if ($orderItemId <= 0) {
            return;
        }

        static::query()
            ->where('order_item_id', $orderItemId)
            ->get()
            ->each(fn (self $submission) => $submission->releaseFromOrder());

        if (! Schema::hasColumn('order_items', 'content_submission_id')) {
            return;
        }

        $item = OrderItem::query()->whereKey($orderItemId)->first();
        $linkedId = (int) ($item?->content_submission_id ?? 0);
        $ownerOrderId = (int) ($item?->order_id ?? 0);

        if ($linkedId <= 0) {
            return;
        }

        static::query()
            ->whereKey($linkedId)
            ->where(function ($q) use ($ownerOrderId) {
                $q->whereNull('order_id');
                if ($ownerOrderId > 0) {
                    $q->orWhere('order_id', $ownerOrderId);
                }
            })
            ->get()
            ->each(fn (self $submission) => $submission->releaseFromOrder());
    }

    public function hasLink(): bool
    {
        $anchor = trim((string) $this->anchor_text);
        $target = trim((string) $this->target_url);

        return $anchor !== '' && $target !== '';
    }

    /**
     * All detected / edited HTTPS links (multi-link preview metadata).
     *
     * @return array<int, array{anchor:string, url:string}>
     */
    public function detectedLinks(): array
    {
        $payload = is_array($this->draft_payload) ? $this->draft_payload : [];
        $hasStoredList = array_key_exists('detected_links', $payload);
        $stored = is_array($payload['detected_links'] ?? null) ? $payload['detected_links'] : [];
        $links = ArticleDetectedLinks::normalizeList($stored);

        if ($links === [] && ! $hasStoredList && $this->hasLink()) {
            $links = [[
                'anchor' => trim((string) $this->anchor_text),
                'url' => trim((string) $this->target_url),
            ]];
        }

        if ($links === [] && ! $hasStoredList && filled($this->preview_html)) {
            $links = ArticleDetectedLinks::fromHtml((string) $this->preview_html);
        }

        return $links;
    }

    /**
     * Persist multi-link metadata and keep the primary checkout pair in sync.
     *
     * @param  array<int, array{anchor?:string, url?:string}>  $links
     */
    public function syncDetectedLinks(array $links, ?string $previewHtml = null): void
    {
        $normalized = ArticleDetectedLinks::normalizeList($links);
        $payload = is_array($this->draft_payload) ? $this->draft_payload : [];
        $payload['detected_links'] = $normalized;

        $attrs = ['draft_payload' => $payload];
        if ($previewHtml !== null) {
            $sanitized = app(ArticleHtmlSanitizer::class)
                ->sanitize($previewHtml);
            $attrs['preview_html'] = ArticlePreviewHtml::normalize(
                ArticleDetectedLinks::applyToHtml($sanitized, $normalized)
            );
        }

        $first = $normalized[0] ?? null;
        if ($first) {
            $attrs['anchor_text'] = $first['anchor'];
            $attrs['target_url'] = $first['url'];
        } else {
            $attrs['anchor_text'] = null;
            $attrs['target_url'] = null;
        }

        $this->fill($attrs)->save();
    }

    /**
     * Approximate SQL for hasCheckoutReadyLinks() (empty pair or HTTPS pair).
     */
    protected static function checkoutReadyLinksSql(string $table): string
    {
        $anchor = 'TRIM(IFNULL('.$table.'.anchor_text, \'\'))';
        $target = 'TRIM(IFNULL('.$table.'.target_url, \'\'))';

        return '(( '.$anchor.' = \'\' AND '.$target.' = \'\')'
            .' OR ('
            .$anchor.' != \'\''
            .' AND '.$target.' != \'\''
            .' AND LOWER('.$target.') LIKE \'https://%\''
            .' AND LOWER('.$target.') NOT LIKE \'https:///%\''
            .' AND LENGTH('.$target.') >= 12'
            .'))';
    }

    /**
     * Checkout and revision attach allow no link, or a complete HTTPS pair.
     * A half-filled or http:// target is not usable on an order item.
     */
    public static function isCheckoutReadyTarget(string $target): bool
    {
        $target = trim($target);
        $lower = strtolower($target);

        return $target !== ''
            && strlen($target) >= 12
            && str_starts_with($lower, 'https://')
            && ! str_starts_with($lower, 'https:///')
            && (bool) filter_var($target, FILTER_VALIDATE_URL);
    }

    public function hasCheckoutReadyLinks(): bool
    {
        $anchor = trim((string) $this->anchor_text);
        $target = trim((string) $this->target_url);

        if ($anchor === '' && $target === '') {
            return true;
        }

        return $anchor !== '' && self::isCheckoutReadyTarget($target);
    }

    public function isReadyForCheckout(): bool
    {
        return $this->canBeOrdered()
            && $this->hasCheckoutReadyLinks()
            && ! $this->isClaimedByAnotherOrder();
    }

    /**
     * @param  Builder<OrderItem>  $itemQuery
     */
    protected function constrainCurrentOwnerLiveItem($itemQuery, string $submissionTable): void
    {
        $hasPublisherStatus = Schema::hasColumn('order_items', 'publisher_status');
        $hasSubmissionFk = Schema::hasColumn('order_items', 'content_submission_id');
        $itemQuery->whereColumn('order_items.order_id', $submissionTable.'.order_id')
            ->where(function ($ownerLine) use ($submissionTable, $hasSubmissionFk) {
                $ownerLine->whereColumn('order_items.id', $submissionTable.'.order_item_id');
                if ($hasSubmissionFk) {
                    $ownerLine->orWhere(function ($legacy) use ($submissionTable) {
                        $legacy->whereNull($submissionTable.'.order_item_id')
                            ->whereColumn('order_items.content_submission_id', $submissionTable.'.id');
                    });
                }
            })
            ->where(function ($q) use ($hasPublisherStatus) {
                $q->where(function ($live) {
                    $live->whereNotNull('live_url')->where('live_url', '!=', '');
                });
                if ($hasPublisherStatus) {
                    $q->orWhere('publisher_status', 'completed');
                }
            });
    }

    /**
     * @param  Builder<OrderItem>  $itemQuery
     */
    protected function excludeClawedBackItems($itemQuery): void
    {
        if (! OrderItemDispute::tableAvailable()) {
            return;
        }

        $itemQuery->whereDoesntHave('disputes', function ($dispute) {
            $dispute->where('status', OrderItemDispute::STATUS_UPHELD);
        });
    }

    /**
     * @param  Builder<Order>|Builder  $orderQuery
     */
    protected function constrainActiveOrderClaim($orderQuery, ?int $exceptOrderId = null): void
    {
        $orderQuery->whereIn('payment_status', self::ACTIVE_ORDER_CLAIM_PAYMENT_STATUSES)
            ->where('status', '!=', 'cancelled');

        if ($exceptOrderId !== null) {
            $orderQuery->where('orders.id', '!=', $exceptOrderId);
        }
    }

    protected function orderLooksLikeActiveClaim(Order $order, ?int $exceptOrderId = null): bool
    {
        if ($exceptOrderId !== null && (int) $order->id === $exceptOrderId) {
            return false;
        }

        return $order->status !== 'cancelled'
            && in_array($order->payment_status, self::ACTIVE_ORDER_CLAIM_PAYMENT_STATUSES, true);
    }

    protected function relatedOwnerOrder(): ?Order
    {
        if ($this->order_id === null) {
            return null;
        }

        $order = $this->relationLoaded('order')
            ? $this->order
            : $this->order()->first();

        return $order instanceof Order ? $order : null;
    }

    protected function ownerOrderBlocksOrdering(): bool
    {
        $owner = $this->relatedOwnerOrder();

        return $owner instanceof Order && $owner->status !== 'cancelled';
    }

    /**
     * Pay/attach gate that still works after this order has claimed the row
     * (canBeOrdered() is false once order_id is set).
     */
    public function isReadyToFulfill(?int $orderId = null): bool
    {
        if ($this->isClaimedByAnotherOrder($orderId)) {
            return false;
        }

        // Do not call isReadyForCheckout() here: that gate also rejects leftover
        // claims, including this order when submission.order_id is still null.
        // Expiry is a catalog-reuse clock. Once this order already owns the
        // row, Pay again / fulfill must not fail just because unused retention
        // elapsed — nightly purge clears `path` when the file is actually gone.
        $ownedByThisOrder = $orderId !== null && (int) $this->order_id === (int) $orderId;

        return $this->moderation_status === self::STATUS_APPROVED
            && filled($this->path)
            && ! $this->isArchived()
            && ($ownedByThisOrder || $this->expires_at === null || $this->expires_at->isFuture())
            && filled($this->country)
            && filled($this->language)
            && $this->imageRightsCoverContent()
            && $this->hasCheckoutReadyLinks();
    }

    /**
     * True after a staff approve when the advertiser can order it, or keep
     * paying / fulfilling the open owner order. isReadyForCheckout() is false
     * once order_id is set, which must not be treated as "still broken".
     */
    public function isUsableAfterStaffApproval(): bool
    {
        if ($this->isReadyForCheckout()) {
            return true;
        }

        $ownerId = (int) ($this->order_id ?? 0);

        return $ownerId > 0 && $this->isReadyToFulfill($ownerId);
    }

    /**
     * Advertiser library query for the post-approval notification / CTA.
     *
     * @return array<string, string>
     */
    public function staffApprovalLibraryParams(): array
    {
        if ($this->isReadyForCheckout()) {
            return [];
        }

        $availability = $this->libraryAvailability();
        if (in_array($availability, ['in_progress', 'needs_fix', 'expired', 'archived', 'evaluating', 'published'], true)) {
            return ['status' => 'all', 'availability' => $availability];
        }

        return ['status' => 'all', 'availability' => 'needs_fix'];
    }

    public function deleteStoredFile(): void
    {
        if ($this->path && Storage::disk($this->disk ?: 'local')->exists($this->path)) {
            Storage::disk($this->disk ?: 'local')->delete($this->path);
        }
    }

    /**
     * Remove the original Word file after unused expiry. Keep the row and preview.
     */
    public function stripStoredFileKeepPreview(): void
    {
        $this->deleteStoredFile();
        $this->forceFill([
            'path' => '',
            'size_bytes' => 0,
        ])->save();
    }
}
