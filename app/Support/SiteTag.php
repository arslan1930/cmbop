<?php

namespace App\Support;

use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Exclusive listing disclosure tag — one of Sponsored / Partner article /
 * As you prefer, or none. Stored as three booleans; writes must keep one winner.
 */
class SiteTag
{
    public const SPONSORED = 'sponsored';

    public const PARTNER = 'partner_material';

    public const AS_YOU_PREFER = 'as_you_prefer';

    /** @var list<string> Priority when legacy rows have more than one flag. */
    public const PRIORITY = [self::SPONSORED, self::PARTNER, self::AS_YOU_PREFER];

    /** @var array<string, string> */
    public const LABELS = [
        self::SPONSORED => 'Sponsored',
        self::PARTNER => 'Partner article',
        self::AS_YOU_PREFER => 'As you prefer',
    ];

    public const NONE_LABEL = 'No tags';

    public const DETAILS_HEADING = 'Listing tag';

    public const FILTER_NONE = 'none';

    public const FILTER_TOOLTIP = 'Listing disclosure tag — not the DoFollow / NoFollow link attribute.';

    public const NONE_CHIP_TITLE = 'No listing disclosure tag.';

    public const CONFLICT_MESSAGE = 'Choose only one tag column (sponsored, partner_material, or as_you_prefer).';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return self::PRIORITY;
    }

    public static function normalize(mixed $tag): ?string
    {
        if (is_array($tag)) {
            $tag = reset($tag);
        }

        if (! is_scalar($tag) || is_bool($tag)) {
            return null;
        }

        $value = strtolower(trim((string) $tag));
        if ($value === '' || $value === 'none') {
            return null;
        }

        return in_array($value, self::PRIORITY, true) ? $value : null;
    }

    public static function label(?string $tag): ?string
    {
        $normalized = self::normalize($tag);

        return $normalized === null ? null : self::LABELS[$normalized];
    }

    /**
     * Publisher radio options, including empty = No tags.
     *
     * @return array<string, string>
     */
    public static function publisherFormOptions(): array
    {
        return ['' => self::NONE_LABEL] + self::LABELS;
    }

    /**
     * Staff / bulk-complete radios. Default is As you prefer (no empty option).
     *
     * @return array<string, string>
     */
    public static function staffFormOptions(): array
    {
        return [
            self::AS_YOU_PREFER => self::LABELS[self::AS_YOU_PREFER],
            self::SPONSORED => self::LABELS[self::SPONSORED],
            self::PARTNER => self::LABELS[self::PARTNER],
        ];
    }

    /**
     * Catalog More → Tag select, including empty = All tags and none = No tags.
     *
     * @return array<string, string>
     */
    public static function catalogFilterOptions(): array
    {
        return [
            '' => 'All tags',
            self::SPONSORED => self::LABELS[self::SPONSORED],
            self::PARTNER => self::LABELS[self::PARTNER],
            self::AS_YOU_PREFER => self::LABELS[self::AS_YOU_PREFER],
            self::FILTER_NONE => self::NONE_LABEL,
        ];
    }

    public static function catalogFilterLabel(?string $filter): ?string
    {
        $options = self::catalogFilterOptions();

        return $filter !== null && $filter !== '' && isset($options[$filter])
            ? $options[$filter]
            : null;
    }

    public static function catalogChipTitle(?string $tag): ?string
    {
        return match (self::normalize($tag)) {
            self::SPONSORED => 'Paid placement disclosed as sponsored — not the DoFollow / NoFollow link attribute',
            self::PARTNER => 'Publisher accepts a partner-supplied article',
            self::AS_YOU_PREFER => 'Placement terms are flexible — agree with the publisher',
            default => null,
        };
    }

    /**
     * Resolve catalog filter: tag= wins; sponsored=1 aliases to sponsored.
     * Returns sponsored|partner_material|as_you_prefer|none, or null for all tags.
     */
    public static function catalogFilterFromInput(mixed $tag, mixed $sponsored = null): ?string
    {
        if (is_array($tag)) {
            $tag = reset($tag);
        }

        if (is_scalar($tag) && ! is_bool($tag)) {
            $value = strtolower(trim((string) $tag));
            if ($value === self::FILTER_NONE) {
                return self::FILTER_NONE;
            }
            $known = self::normalize($value);
            if ($known !== null) {
                return $known;
            }
        }

        if ($sponsored === true || $sponsored === 1 || (is_scalar($sponsored) && (string) $sponsored === '1')) {
            return self::SPONSORED;
        }

        return null;
    }

    public static function catalogFilterFromRequest(Request $request): ?string
    {
        return self::catalogFilterFromInput($request->input('tag'), $request->input('sponsored'));
    }

    public static function constrainQuery(Builder $query, ?string $filter): void
    {
        // Same winner as tagValue() / chips: leftover multi-flag rows must not
        // appear under Partner article or As you prefer just because that flag is on.
        match ($filter) {
            self::SPONSORED => $query->where('sponsored', 1),
            self::PARTNER => $query
                ->where('partner_material', 1)
                ->where(function (Builder $q) {
                    $q->where('sponsored', 0)->orWhereNull('sponsored');
                }),
            self::AS_YOU_PREFER => $query
                ->where('as_you_prefer', 1)
                ->where(function (Builder $q) {
                    $q->where('sponsored', 0)->orWhereNull('sponsored');
                })
                ->where(function (Builder $q) {
                    $q->where('partner_material', 0)->orWhereNull('partner_material');
                }),
            self::FILTER_NONE => $query
                ->where(function (Builder $q) {
                    $q->where('sponsored', 0)->orWhereNull('sponsored');
                })
                ->where(function (Builder $q) {
                    $q->where('partner_material', 0)->orWhereNull('partner_material');
                })
                ->where(function (Builder $q) {
                    $q->where('as_you_prefer', 0)->orWhereNull('as_you_prefer');
                }),
            default => null,
        };
    }

    /**
     * Rows with more than one listing-tag flag (legacy / import leftovers).
     */
    public static function constrainConflicting(Builder $query): void
    {
        $query->where(function (Builder $outer) {
            $outer->where(function (Builder $q) {
                $q->where('sponsored', 1)->where('partner_material', 1);
            })->orWhere(function (Builder $q) {
                $q->where('sponsored', 1)->where('as_you_prefer', 1);
            })->orWhere(function (Builder $q) {
                $q->where('partner_material', 1)->where('as_you_prefer', 1);
            });
        });
    }

    /**
     * @return array{sponsored: bool, partner_material: bool, as_you_prefer: bool}
     */
    public static function flags(?string $tag): array
    {
        $normalized = self::normalize($tag);

        return [
            'sponsored' => $normalized === self::SPONSORED,
            'partner_material' => $normalized === self::PARTNER,
            'as_you_prefer' => $normalized === self::AS_YOU_PREFER,
        ];
    }

    public static function fromFlags(bool $sponsored, bool $partnerMaterial, bool $asYouPrefer): ?string
    {
        if ($sponsored) {
            return self::SPONSORED;
        }
        if ($partnerMaterial) {
            return self::PARTNER;
        }
        if ($asYouPrefer) {
            return self::AS_YOU_PREFER;
        }

        return null;
    }

    public static function flagCount(bool $sponsored, bool $partnerMaterial, bool $asYouPrefer): int
    {
        return (int) $sponsored + (int) $partnerMaterial + (int) $asYouPrefer;
    }

    public static function applyExclusive(Site $site, mixed $tag): void
    {
        foreach (self::flags(self::normalize($tag)) as $column => $value) {
            $site->{$column} = $value;
        }
    }

    public static function applyExclusiveFromFlags(Site $site, bool $sponsored, bool $partnerMaterial, bool $asYouPrefer): void
    {
        self::applyExclusive($site, self::fromFlags($sponsored, $partnerMaterial, $asYouPrefer));
    }

    /**
     * Radio `site_tag` wins. Missing field falls back to exclusive checkbox flags.
     */
    public static function applyFromRequest(Site $site, Request $request): void
    {
        if ($request->exists('site_tag')) {
            self::applyExclusive($site, $request->input('site_tag'));

            return;
        }

        self::applyExclusiveFromFlags(
            $site,
            $request->boolean('sponsored') || $request->has('sponsored'),
            $request->boolean('partner_material') || $request->has('partner_material'),
            $request->boolean('as_you_prefer') || $request->has('as_you_prefer'),
        );
    }

    /**
     * Staff create / bulk complete: omitted or blank tag defaults to As you prefer.
     */
    public static function applyStaffDefault(Site $site, mixed $tag): void
    {
        $normalized = self::normalize($tag);
        self::applyExclusive($site, $normalized ?? self::AS_YOU_PREFER);
    }

    /**
     * Force exclusive flags when any tag column is present in an attribute bag.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function exclusiveAttributePatch(array $attributes, ?Site $existing = null): array
    {
        if (! array_key_exists('sponsored', $attributes)
            && ! array_key_exists('partner_material', $attributes)
            && ! array_key_exists('as_you_prefer', $attributes)) {
            return $attributes;
        }

        $sponsored = array_key_exists('sponsored', $attributes)
            ? (bool) $attributes['sponsored']
            : (bool) ($existing?->sponsored);
        $partnerMaterial = array_key_exists('partner_material', $attributes)
            ? (bool) $attributes['partner_material']
            : (bool) ($existing?->partner_material);
        $asYouPrefer = array_key_exists('as_you_prefer', $attributes)
            ? (bool) $attributes['as_you_prefer']
            : (bool) ($existing?->as_you_prefer);

        return array_merge($attributes, self::flags(self::fromFlags(
            $sponsored,
            $partnerMaterial,
            $asYouPrefer
        )));
    }
}
