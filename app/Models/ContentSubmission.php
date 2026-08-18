<?php

namespace App\Models;

use App\Models\Concerns\ToleratesUnparseableDates;
use App\Services\ContentUpload\ArticleDetectedLinks;
use App\Services\ContentUpload\ArticleHtmlSanitizer;
use App\Services\ContentUpload\ArticlePreviewHtml;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ContentSubmission extends Model
{
    use ToleratesUnparseableDates;

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

    public const PAID_ORDER_CLAIM_MESSAGE = 'This article is already used on a paid order and cannot start a new catalog checkout.';

    /** Canonical leftover payment states for Pay again / settle. */
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

    public function moderationLogs(): HasMany
    {
        return $this->hasMany(ContentModerationLog::class, 'content_submission_id');
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

    /**
     * Schema-safe column probe for leftover Hostinger databases.
     */
    public static function submissionsHasColumn(string $column): bool
    {
        try {
            $table = (new static)->getTable();

            return Schema::hasTable($table) && Schema::hasColumn($table, $column);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Image-rights columns arrived after the first library deploy.
     */
    public static function hasImageRightsColumns(): bool
    {
        return self::submissionsHasColumn('image_rights')
            && self::submissionsHasColumn('image_rights_source');
    }

    public function isApproved(): bool
    {
        return $this->moderation_status === self::STATUS_APPROVED;
    }

    /**
     * True when we approved this article recently and it is still waiting to be ordered.
     */
    public function isJustApproved(): bool
    {
        try {
            if (! $this->isReadyForCheckout() || $this->evaluated_at === null) {
                return false;
            }

            $cutoff = now()->copy()->subDays(self::JUST_APPROVED_DAYS)->startOfDay();

            return $this->evaluated_at->copy()->startOfDay()->gte($cutoff);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The “Just approved” chip is only for the same calendar day.
     * Older rows keep the relative line (Approved yesterday / N days ago).
     */
    public function showJustApprovedBadge(): bool
    {
        try {
            return $this->isJustApproved()
                && $this->evaluated_at !== null
                && $this->evaluated_at->isSameDay(now());
        } catch (\Throwable) {
            return false;
        }
    }

    public function justApprovedLabel(): ?string
    {
        try {
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
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * True when the visible title is the leftover Word filename (or blank).
     * Rename is offered in the table for these rows; real titles stay as-is.
     */
    public function usesFilenameAsTitle(): bool
    {
        $title = trim((string) ($this->title ?? ''));
        $filename = trim((string) ($this->original_filename ?? ''));
        if ($title === '') {
            return true;
        }
        if ($filename === '') {
            return false;
        }

        return $this->normalizedLibraryTitle($title) === $this->normalizedLibraryTitle($filename);
    }

    private function normalizedLibraryTitle(string $value): string
    {
        $normalized = mb_strtolower(trim($value));
        if (str_ends_with($normalized, '.docx')) {
            $normalized = substr($normalized, 0, -5);
        }

        return $normalized;
    }

    /**
     * Relative "Uploaded …" clock. Leftover created_at fails closed.
     */
    public function uploadedAgoLabel(): string
    {
        if (! $this->created_at) {
            return '';
        }

        try {
            return $this->created_at->diffForHumans();
        } catch (\Throwable) {
            return '';
        }
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
            && ! $this->isExpired()
            && filled($this->country)
            && filled($this->language)
            && $this->imageRightsCoverContent();
    }

    /**
     * True when another checkout already owns this article (direct order_id
     * or a non-cancelled, non-refunded placement). Legacy null / unpaid
     * payment_status still locks the row. Callers must lock the row first.
     * Cancelled leftovers stay reusable after refund/release.
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
        return $query->withoutPaidOrderClaim()
            ->where(function ($claim) {
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
     * SQL mirror of !isLockedByPaidOrder() — a paid line still owns this row.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithoutPaidOrderClaim($query)
    {
        $query->where(function ($owner) {
            $owner->withoutOpenOwnerOrder()
                ->orWhereHas('order', function ($order) {
                    $order->where(function ($payment) {
                        $payment->whereNull('payment_status')
                            ->orWhere('payment_status', '!=', 'paid');
                    });
                });
        });

        if (Schema::hasColumn('order_items', 'content_submission_id')) {
            $query->whereDoesntHave('orderItems', function ($item) {
                $this->constrainPaidOpenPlacement($item);
            });
        }

        return $query;
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
                    ->tap(fn ($ready) => self::constrainRequiredMarketFields($ready))
                    ->notArchived()
                    ->whereNotExpired();
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
            ->notArchived()
            ->whereNotExpired();

        self::constrainRequiredMarketFields($query);
        self::constrainImageRightsCover($query);

        return $query->withoutActiveOrderClaim();
    }

    /**
     * Hide articles still pointed at by a non-cancelled, non-refunded
     * order item (including legacy null / unpaid). Cancelled leftovers stay orderable.
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
            ->withoutOpenOrderItemLink()
            ->whereExpired();
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
            ->withoutActiveOrderClaim()
            ->whereNotExpired();
    }

    /**
     * Approved unused articles approaching content:purge-expired.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeNearExpiryInLibrary($query, int $withinDays = 7)
    {
        if (! Schema::hasColumn($query->getModel()->getTable(), 'expires_at')) {
            return $query->whereRaw('0 = 1');
        }

        return $query->where('moderation_status', self::STATUS_APPROVED)
            ->withoutOpenOwnerOrder()
            ->withoutOpenOrderItemLink()
            ->whereExpiryIsRecorded()
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
        return self::constrainImageRightsCover($query);
    }

    /**
     * SQL negation of imageRightsCoverContent() for Needs corrections.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithoutImageRightsCover($query)
    {
        if (! self::hasImageRightsColumns()) {
            return $query->whereRaw('0 = 1');
        }

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
     * Skip leftover image-rights columns so Available does not 500.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public static function constrainImageRightsCover($query)
    {
        if (! self::hasImageRightsColumns()) {
            return $query;
        }

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
     * Path / country / language arrived as required later on some hosts.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public static function constrainRequiredMarketFields($query)
    {
        foreach (['path', 'country', 'language'] as $column) {
            if (! self::submissionsHasColumn($column)) {
                continue;
            }

            $query->whereNotNull($column)->where($column, '!=', '');
        }

        return $query;
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
            ->tap(fn ($ready) => self::constrainRequiredMarketFields($ready))
            ->notArchived()
            ->hasCheckoutReadyLinks()
            ->withImageRightsCover()
            ->withoutCurrentLivePlacement()
            ->where(function ($claim) {
                $claim->withOpenOwnerOrder()
                    ->orWhere(function ($paidItemOnly) {
                        $paidItemOnly->withoutOpenOwnerOrder()
                            ->whereHas('orderItems', function ($item) {
                                $this->constrainPaidOpenPlacement($item);
                            });
                    });
            });
    }

    /**
     * Current paid owner line is live. A sibling's live URL on the same
     * order, or a historical URL on a cancelled leftover, must not keep
     * this article in Completed. A paid live line still counts when a
     * stale leftover still owns order_id, or when order_id was never written.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithCurrentLivePlacement($query)
    {
        $table = $query->getModel()->getTable();

        return $query->where(function ($outer) use ($table) {
            $outer->where(function ($owned) use ($table) {
                $owned->withOpenOwnerOrder()
                    ->whereHas('orderItems', function ($item) use ($table) {
                        $this->constrainCurrentOwnerLiveItem($item, $table);
                    });
            })->orWhere(function ($paidLive) use ($table) {
                $paidLive->whereHas('orderItems', function ($item) use ($table) {
                    $this->constrainPaidLiveItem($item, $table);
                });
            });
        });
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithoutCurrentLivePlacement($query)
    {
        $table = $query->getModel()->getTable();

        return $query->where(function ($notOwnedLive) use ($table) {
            $notOwnedLive->withoutOpenOwnerOrder()
                ->orWhereDoesntHave('orderItems', function ($item) use ($table) {
                    $this->constrainCurrentOwnerLiveItem($item, $table);
                });
        })->whereDoesntHave('orderItems', function ($item) use ($table) {
            $this->constrainPaidLiveItem($item, $table);
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
                        ->notArchived()
                        ->where(function ($unready) {
                            $unready->withoutCheckoutReadyLinks()
                                ->orWhere(function ($rights) {
                                    $rights->withoutImageRightsCover();
                                })
                                ->orWhere(function ($file) {
                                    $file->whereNull('path')->orWhere('path', '');
                                })
                                ->orWhere(function ($country) {
                                    $country->whereNull('country')->orWhere('country', '');
                                })
                                ->orWhere(function ($language) {
                                    $language->whereNull('language')->orWhere('language', '');
                                });
                        });
                })->orWhere(function ($leftover) {
                    $leftover->withoutOpenOwnerOrder()
                        ->notArchived()
                        ->withActiveOrderClaim()
                        ->withoutCurrentLivePlacement()
                        ->where(function ($unpaidOrUnready) {
                            $unpaidOrUnready->whereDoesntHave('orderItems', function ($item) {
                                $this->constrainPaidOpenPlacement($item);
                            })->orWhere(function ($paidUnready) {
                                $paidUnready->where(function ($unready) {
                                    $unready->withoutCheckoutReadyLinks()
                                        ->orWhere(function ($rights) {
                                            $rights->withoutImageRightsCover();
                                        })
                                        ->orWhere(function ($file) {
                                            $file->whereNull('path')->orWhere('path', '');
                                        })
                                        ->orWhere(function ($country) {
                                            $country->whereNull('country')->orWhere('country', '');
                                        })
                                        ->orWhere(function ($language) {
                                            $language->whereNull('language')->orWhere('language', '');
                                        });
                                });
                            });
                        });
                })->orWhere(function ($incomplete) {
                    $incomplete->where('moderation_status', self::STATUS_APPROVED)
                        ->withoutOpenOwnerOrder()
                        ->where(function ($gap) {
                            $gap->whereNull('path')->orWhere('path', '')
                                ->orWhereNull('country')->orWhere('country', '')
                                ->orWhereNull('language')->orWhere('language', '');
                        });
                });
            })
            ->where(function ($exp) {
                $exp->whereNotExpired()
                    ->orWhere(function ($owned) {
                        $owned->withOpenOwnerOrder();
                    })->orWhere(function ($claimed) {
                        $claimed->withActiveOrderClaim();
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
        $wanted = [
            'id',
            'user_id',
            'title',
            'original_filename',
            'language',
            'country',
            'word_count',
            'moderation_status',
            'path',
            'order_id',
            'archived_at',
            'expires_at',
            'anchor_text',
            'target_url',
            'image_rights',
            'image_rights_source',
        ];
        $prefixed = [];
        foreach ($wanted as $column) {
            if (! self::submissionsHasColumn($column)) {
                continue;
            }
            $prefixed[] = $table.'.'.$column;
        }

        return $query->select($prefixed)->selectRaw(
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
        if (! self::hasImageRightsColumns()) {
            return true;
        }

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
                    && $this->orderLooksLikeActiveClaim($order);
            });
        }

        return $this->orderItems()
            ->whereHas('order', function ($q) {
                $this->constrainActiveOrderClaim($q);
            })
            ->tap(fn ($item) => $this->excludeClawedBackItems($item))
            ->exists();
    }

    /**
     * Parseable expires_at in the Gregorian window. Leftover Hostinger
     * strings compare as unexpired on SQLite (`> now()`) and as expired
     * on MySQL (zero-date), and 500 PHP casts on the library pages.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWhereExpiryIsRecorded($query)
    {
        if (! Schema::hasColumn($query->getModel()->getTable(), 'expires_at')) {
            return $query->whereRaw('0 = 1');
        }

        return $query->whereNotNull('expires_at')
            ->where('expires_at', '>=', static::PLAUSIBLE_SQL_DATETIME_FLOOR)
            ->where('expires_at', '<=', static::PLAUSIBLE_SQL_DATETIME_CEIL);
    }

    /**
     * Missing or leftover expires_at (same as PHP null after cast).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWhereExpiryIsMissing($query)
    {
        if (! Schema::hasColumn($query->getModel()->getTable(), 'expires_at')) {
            return $query;
        }

        return $query->where(function ($inner) {
            $inner->whereNull('expires_at')
                ->orWhere('expires_at', '>', static::PLAUSIBLE_SQL_DATETIME_CEIL)
                ->orWhere('expires_at', '<', static::PLAUSIBLE_SQL_DATETIME_FLOOR);
        });
    }

    /**
     * SQL counterpart of ! isExpired().
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWhereNotExpired($query)
    {
        if (! Schema::hasColumn($query->getModel()->getTable(), 'expires_at')) {
            return $query;
        }

        return $query->where(function ($q) {
            $q->whereExpiryIsMissing()
                ->orWhere(function ($live) {
                    $live->whereExpiryIsRecorded()
                        ->where('expires_at', '>', now());
                });
        });
    }

    /**
     * SQL counterpart of isExpired() — used by content:purge-expired.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWhereExpired($query)
    {
        if (! Schema::hasColumn($query->getModel()->getTable(), 'expires_at')) {
            return $query->whereRaw('0 = 1');
        }

        return $query->whereExpiryIsRecorded()
            ->where('expires_at', '<=', now());
    }

    /**
     * Leftover archived_at is not a staff archive (same as Site).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeNotArchived($query)
    {
        if (! Schema::hasColumn($query->getModel()->getTable(), 'archived_at')) {
            return $query;
        }

        return $query->where(function ($q) {
            $q->whereNull('archived_at')
                ->orWhere('archived_at', '>', static::PLAUSIBLE_SQL_DATETIME_CEIL)
                ->orWhere('archived_at', '<', static::PLAUSIBLE_SQL_DATETIME_FLOOR);
        });
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeArchived($query)
    {
        if (! Schema::hasColumn($query->getModel()->getTable(), 'archived_at')) {
            return $query->whereRaw('0 = 1');
        }

        return $query->whereNotNull('archived_at')
            ->where('archived_at', '>=', static::PLAUSIBLE_SQL_DATETIME_FLOOR)
            ->where('archived_at', '<=', static::PLAUSIBLE_SQL_DATETIME_CEIL);
    }

    public function isExpired(): bool
    {
        // Match content:purge-expired (`expires_at <= now()`). Carbon isPast() is
        // strictly before now, which would leave the exact expiry instant orderable
        // in the UI while the nightly strip already treats it as expired.
        try {
            $at = $this->expires_at;
        } catch (\Throwable) {
            return false;
        }

        return $at !== null && ! $at->isFuture();
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

        return ! $this->isUnusedExpired();
    }

    public function canEditArticle(): bool
    {
        if ($this->isLockedByPaidOrder() || $this->isArchived()) {
            return false;
        }

        // Catalog expiry is unused-inventory only. A leftover still on an
        // open order must stay editable so Pay again can be unblocked.
        return ! $this->isUnusedExpired();
    }

    /**
     * Retention clock for unused inventory. Claimed leftovers are not this.
     */
    public function isUnusedExpired(): bool
    {
        return $this->isExpired() && ! $this->isLinkedToOpenOrderItem();
    }

    /**
     * Paid, non-cancelled owner — the article is already in fulfillment.
     * Pending/failed leftovers stay editable so Pay again can be unblocked.
     * Also locks when a paid line still points here after a stale cancelled
     * order_id (legacy leftover that was reused without rewriting ownership).
     */
    public function isLockedByPaidOrder(): bool
    {
        return $this->paidClaimOrderId() !== null;
    }

    /**
     * Paid, non-cancelled owner or item. Prefers the live placement over a
     * stale leftover that still has order_id / a failed line pointing here.
     */
    public function paidClaimOrderId(): ?int
    {
        $owner = $this->relatedOwnerOrder();
        if ($owner instanceof Order
            && $owner->status !== 'cancelled'
            && $owner->payment_status === 'paid') {
            return (int) $owner->id;
        }

        if (! Schema::hasColumn('order_items', 'content_submission_id')) {
            return null;
        }

        if ($this->relationLoaded('orderItems')) {
            foreach ($this->orderItems as $item) {
                if ($item->isClawedBack()) {
                    continue;
                }

                $order = $item->relationLoaded('order')
                    ? $item->order
                    : $item->order()->first();

                if ($order instanceof Order
                    && $order->status !== 'cancelled'
                    && $order->payment_status === 'paid') {
                    return (int) $order->id;
                }
            }

            return null;
        }

        $item = $this->orderItems()
            ->whereHas('order', function ($q) {
                $q->where('status', '!=', 'cancelled')
                    ->where('payment_status', 'paid');
            })
            ->tap(fn ($row) => $this->excludeClawedBackItems($row))
            ->first();

        return $item ? (int) $item->order_id : null;
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
        if ($this->isLockedByPaidOrder()) {
            return false;
        }

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
     * Approved file + market + rights + a complete HTTPS link pair.
     * Ignores leftover/paid claims and catalog expiry so Pay again / attach
     * on an already-claimed leftover can still settle after the listing ages out.
     */
    public function hasFulfillableContent(): bool
    {
        return $this->moderation_status === self::STATUS_APPROVED
            && filled($this->path)
            && ! $this->isArchived()
            && filled($this->country)
            && filled($this->language)
            && $this->imageRightsCoverContent()
            && $this->hasCheckoutReadyLinks();
    }

    /**
     * Approved file + market + rights + a complete HTTPS link pair.
     * Ignores leftover/paid claims so Order / replace can see whether the
     * article would be usable after the leftover is released.
     */
    public function isContentReadyForOrder(): bool
    {
        return $this->hasFulfillableContent()
            && ! $this->isExpired();
    }

    /**
     * Library Order button: free checkout-ready rows, or a leftover whose
     * article is already content-ready. Card leftovers that can Pay again
     * stay on that order when the listing has expired.
     */
    public function canOrderFromLibrary(): bool
    {
        // A stale leftover item must not make a paid placement look reusable.
        if ($this->isLockedByPaidOrder()) {
            return false;
        }

        if ($this->isReadyForCheckout()) {
            return true;
        }

        if (! $this->canReplaceUnpaidLeftover() || ! $this->hasFulfillableContent()) {
            return false;
        }

        // Unused-inventory expiry. Claimed leftovers that cannot Pay again
        // must still start a replacement checkout or the article is stuck.
        if ($this->isExpired() && $this->leftoverCanPayAgain()) {
            return false;
        }

        return true;
    }

    /**
     * Card + failed + pending — the only leftover Orders → Pay again settles.
     * Wallet / Wise / bank / legacy unpaid rows cannot use that button.
     */
    public function leftoverCanPayAgain(): bool
    {
        $order = $this->libraryOrder();
        if (! $order instanceof Order) {
            return false;
        }

        return $order->payment_method === 'card'
            && $order->payment_status === 'failed'
            && $order->status === 'pending';
    }

    /**
     * Catalog / wizard / cart may list this row. Replace runs when a cart
     * assign or add-to-cart actually attaches the article, or when checkout
     * is about to charge — not when Order opens the catalog or the picker
     * merely opens.
     */
    public function isAvailableForPicker(): bool
    {
        return $this->canOrderFromLibrary();
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
        if ($this->expires_at === null || $this->isExpired() || $this->isArchived() || $this->isLinkedToOpenOrderItem()) {
            return false;
        }

        try {
            return $this->expires_at->lessThanOrEqualTo(now()->addDays(max(1, $withinDays)));
        } catch (\Throwable) {
            return false;
        }
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

        try {
            return (int) now()->startOfDay()->diffInDays($this->expires_at->copy()->startOfDay());
        } catch (\Throwable) {
            return null;
        }
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

        if ($this->isLockedByPaidOrder()) {
            return self::PAID_ORDER_CLAIM_MESSAGE;
        }

        if ($this->hasImages() && ! $this->imageRightsCoverContent()) {
            return 'This article contains images. Confirm you own them, or add the source URL or copyright details.';
        }

        if (! $this->hasCheckoutReadyLinks() && ($this->canBeOrdered() || $this->canEditArticle())) {
            return self::CHECKOUT_LINK_MESSAGE;
        }

        // order_id leftovers cannot be ordered again, but they stay editable
        // until paid. Do not hide the Pay-again notice just because
        // canBeOrdered() is false.
        if ($this->isClaimedByAnotherOrder()) {
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
        try {
            $at = $this->archived_at;
        } catch (\Throwable) {
            return false;
        }

        return $at instanceof \DateTimeInterface;
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

    /**
     * Owner order, or the leftover line still pointing here when order_id
     * was never written. Admin library "View order" must not go blank.
     */
    public function libraryOrder(): ?Order
    {
        $paidId = $this->paidClaimOrderId();
        if ($paidId) {
            $owner = $this->relatedOwnerOrder();
            if ($owner instanceof Order && (int) $owner->id === $paidId) {
                return $owner;
            }

            return Order::query()->find($paidId);
        }

        $claimId = $this->activeClaimOrderId();
        if ($claimId) {
            $owner = $this->relatedOwnerOrder();
            if ($owner instanceof Order && (int) $owner->id === $claimId) {
                return $owner;
            }

            $item = $this->placementItem();
            if ($item && (int) $item->order_id === $claimId) {
                $order = $item->relationLoaded('order')
                    ? $item->order
                    : $item->order()->first();
                if ($order instanceof Order) {
                    return $order;
                }
            }

            return Order::query()->find($claimId);
        }

        $owner = $this->relatedOwnerOrder();
        if ($owner instanceof Order && $owner->status !== 'cancelled') {
            return $owner;
        }

        $item = $this->placementItem();
        if ($item) {
            $order = $item->relationLoaded('order')
                ? $item->order
                : $item->order()->first();
            if ($order instanceof Order && $order->status !== 'cancelled') {
                return $order;
            }
        }

        return null;
    }

    public function liveUrl(): ?string
    {
        $item = $this->currentPaidPlacementItem();
        if (! $item || ! $item->hasLiveUrl()) {
            return null;
        }

        return trim((string) $item->live_url) ?: null;
    }

    /**
     * Library / admin placement row. Prefer the paid live line over a stale
     * leftover that still owns order_item_id.
     */
    public function libraryPlacementItem(): ?OrderItem
    {
        return $this->currentPaidPlacementItem() ?: $this->placementItem();
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
        $item = $this->currentPaidPlacementItem();
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

        if ($this->isInUse() || $this->isLockedByPaidOrder()) {
            $claimId = (int) ($this->paidClaimOrderId() ?? $this->order_id ?? 0);
            if ($claimId <= 0) {
                $claimId = (int) ($this->activeClaimOrderId() ?? 0);
            }
            if ($claimId > 0 && ! $this->isReadyToFulfill($claimId)) {
                return 'needs_fix';
            }

            return 'in_progress';
        }

        // Unpaid/failed item-only leftover (order_id never written). Not unused
        // inventory — Expired / purge must not treat it as a dead row.
        if ($this->isClaimedByAnotherOrder()) {
            return 'needs_fix';
        }

        if ($this->isExpired()) {
            return 'expired';
        }

        if ($this->needsCorrection()) {
            return 'needs_fix';
        }

        if ($this->moderation_status === self::STATUS_APPROVED
            && (! filled($this->path) || ! filled($this->country) || ! filled($this->language))) {
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
        if ($orderId <= 0 || ! static::submissionsTableAvailable()) {
            return;
        }

        try {
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
        } catch (\Throwable) {
            // Leftover Hostinger: still complete the money move without library rows.
        }
    }

    /**
     * Free the library article on one placement (dispute clawback),
     * without unlocking sibling lines on the same order.
     */
    public static function releaseAllForOrderItem(int $orderItemId): void
    {
        if ($orderItemId <= 0 || ! static::submissionsTableAvailable()) {
            return;
        }

        try {
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
        } catch (\Throwable) {
            // Leftover Hostinger: still complete the money move without library rows.
        }
    }

    private static function submissionsTableAvailable(): bool
    {
        try {
            $table = (new static)->getTable();
            if (! Schema::hasTable($table)) {
                return false;
            }
            DB::table($table)->limit(1)->exists();

            return true;
        } catch (\Throwable) {
            return false;
        }
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
        $this->constrainPaidOpenPlacement($itemQuery);
    }

    /**
     * Paid, non-cancelled line that still points here. Used when order_id
     * was never written (item-only leftover that later settled).
     *
     * @param  Builder<OrderItem>  $itemQuery
     */
    protected function constrainPaidOpenPlacement($itemQuery): void
    {
        $itemQuery->whereHas('order', function ($order) {
            $order->where('payment_status', 'paid')
                ->where('status', '!=', 'cancelled');
        });
        $this->excludeClawedBackItems($itemQuery);
    }

    /**
     * @param  Builder<OrderItem>  $itemQuery
     */
    protected function constrainPaidLiveItem($itemQuery, string $submissionTable): void
    {
        if (! Schema::hasColumn('order_items', 'content_submission_id')) {
            $itemQuery->whereRaw('0 = 1');

            return;
        }

        $hasPublisherStatus = Schema::hasColumn('order_items', 'publisher_status');
        $itemQuery->whereColumn('order_items.content_submission_id', $submissionTable.'.id');
        $this->constrainPaidOpenPlacement($itemQuery);
        $itemQuery->where(function ($q) use ($hasPublisherStatus) {
            $q->where(function ($live) {
                $live->whereNotNull('live_url')->where('live_url', '!=', '');
            });
            if ($hasPublisherStatus) {
                $q->orWhere('publisher_status', 'completed');
            }
        });
    }

    /**
     * Paid placement for Completed / live URL. Prefers the current line, but
     * ignores a stale cancelled leftover when a later paid item still points here.
     */
    public function currentPaidPlacementItem(): ?OrderItem
    {
        $item = $this->placementItem();
        if ($item && $this->orderItemIsPaidOpen($item)) {
            return $item;
        }

        if (! Schema::hasColumn('order_items', 'content_submission_id')) {
            return null;
        }

        $items = $this->relationLoaded('orderItems')
            ? $this->orderItems
            : $this->orderItems()->with('order')->orderBy('id')->get();

        foreach ($items as $row) {
            if ($this->orderItemIsPaidOpen($row)) {
                return $row;
            }
        }

        return null;
    }

    protected function orderItemIsPaidOpen(OrderItem $item): bool
    {
        if ($item->isClawedBack()) {
            return false;
        }

        $order = $item->relationLoaded('order')
            ? $item->order
            : $item->order()->first();

        return $order instanceof Order
            && $order->status !== 'cancelled'
            && $order->payment_status === 'paid';
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
        // Include legacy null / unpaid. SQL `!= refunded` alone drops NULL.
        $orderQuery->where('status', '!=', 'cancelled')
            ->where(function ($payment) {
                $payment->whereNull('payment_status')
                    ->orWhere('payment_status', '!=', 'refunded');
            });

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
            && ($order->payment_status === null || $order->payment_status !== 'refunded');
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

    /**
     * Direct order_id or a non-clawed line on this order still points here.
     */
    public function isOwnedByOrder(?int $orderId): bool
    {
        if ($orderId === null || $orderId <= 0) {
            return false;
        }

        if ((int) $this->order_id === (int) $orderId) {
            return true;
        }

        if (! Schema::hasColumn('order_items', 'content_submission_id')) {
            return false;
        }

        if ($this->relationLoaded('orderItems')) {
            return $this->orderItems->contains(function (OrderItem $item) use ($orderId) {
                if ($item->isClawedBack() || (int) $item->order_id !== (int) $orderId) {
                    return false;
                }

                $order = $item->relationLoaded('order')
                    ? $item->order
                    : $item->order()->first();

                return $order instanceof Order && $order->status !== 'cancelled';
            });
        }

        return $this->orderItems()
            ->where('order_id', $orderId)
            ->whereHas('order', function ($order) {
                $order->where('status', '!=', 'cancelled');
            })
            ->tap(fn ($item) => $this->excludeClawedBackItems($item))
            ->exists();
    }

    /**
     * Open owner order, or the first paid/pending/failed leftover still pointing here.
     */
    public function activeClaimOrderId(): ?int
    {
        $paidId = $this->paidClaimOrderId();
        if ($paidId) {
            return $paidId;
        }

        $owner = $this->relatedOwnerOrder();
        if ($owner instanceof Order && $this->orderLooksLikeActiveClaim($owner)) {
            return (int) $owner->id;
        }

        if (! Schema::hasColumn('order_items', 'content_submission_id')) {
            return null;
        }

        if ($this->relationLoaded('orderItems')) {
            foreach ($this->orderItems as $item) {
                if ($item->isClawedBack()) {
                    continue;
                }

                $order = $item->relationLoaded('order')
                    ? $item->order
                    : $item->order()->first();
                if ($order instanceof Order && $this->orderLooksLikeActiveClaim($order)) {
                    return (int) $order->id;
                }
            }

            return null;
        }

        $item = $this->orderItems()
            ->whereHas('order', function ($order) {
                $this->constrainActiveOrderClaim($order);
            })
            ->tap(fn ($row) => $this->excludeClawedBackItems($row))
            ->first();

        return $item ? (int) $item->order_id : null;
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
            $paidId = $this->paidClaimOrderId();
            // A stale leftover line must not block the paid placement.
            if ($orderId === null || $paidId === null || (int) $orderId !== (int) $paidId) {
                return false;
            }
        }

        // Do not call isReadyForCheckout() here: that gate also rejects leftover
        // claims, including this order when submission.order_id is still null.
        if (! $this->hasFulfillableContent()) {
            return false;
        }

        // Catalog expiry is unused-inventory only. The same leftover can
        // still be paid. A replacement checkout may attach after that
        // leftover is released — do not require isOwnedByOrder for that.
        if ($this->isExpired()) {
            return $this->isOwnedByOrder($orderId)
                || ($orderId !== null && ! $this->isLockedByPaidOrder());
        }

        return true;
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
        if ($ownerId > 0 && $this->isReadyToFulfill($ownerId)) {
            return true;
        }

        $claimId = $this->activeClaimOrderId();

        return $claimId !== null && $this->isReadyToFulfill($claimId);
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
