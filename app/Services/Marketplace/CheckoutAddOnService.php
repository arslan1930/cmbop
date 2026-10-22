<?php

namespace App\Services\Marketplace;

use App\Models\FeatureOfferSetting;
use App\Models\Site;
use App\Support\PublicationDuration;
use Illuminate\Http\Request;

/**
 * Advertiser writing option + optional 12-month link guarantee.
 * Cart drawer / wizard persist the choice on the session line; checkout
 * preselects it. Applied after CartPricingService expand for money.
 */
class CheckoutAddOnService
{
    public function __construct(private WritingFeeService $writing) {}

    /**
     * @param  array<int, array<string, mixed>>  $cart
     * @return array<int, array<string, mixed>>
     */
    public function applyRequestToCart(array $cart, Request $request): array
    {
        $sources = $request->input('content_source', []);
        $briefs = $request->input('writing_brief', []);
        $guarantee = $request->boolean('link_guarantee');

        foreach ($cart as $i => $item) {
            if (! is_array($item)) {
                continue;
            }
            $id = (int) ($item['id'] ?? 0);
            $site = $id > 0 ? Site::query()->find($id) : null;
            $raw = is_array($sources)
                ? (string) ($sources[$id] ?? $sources[(string) $id] ?? self::sourceFromItem($item))
                : self::sourceFromItem($item);
            $source = $this->normalizeRequestedSource($raw, $site instanceof Site ? $site : null);

            $item['content_source'] = $source;
            $item['link_guarantee'] = $guarantee && $this->siteQualifiesForGuarantee($site instanceof Site ? $site : null);
            if (self::isWrittenSource($item) && is_array($briefs)) {
                $brief = $briefs[$id] ?? $briefs[(string) $id] ?? [];
                $item['writing_target_url'] = is_array($brief) ? trim((string) ($brief['target_url'] ?? '')) : '';
                $item['writing_anchor_text'] = is_array($brief) ? trim((string) ($brief['anchor_text'] ?? '')) : '';
            }
            $cart[$i] = $item;
        }

        return array_values($cart);
    }

    /**
     * Publisher self-write when they offer it; otherwise SEOLinkBuildings writes.
     */
    public function requestedWriter(?Site $site): string
    {
        if ($site instanceof Site && $this->writing->writingOffered($site)) {
            return WritingFeeService::SOURCE_PUBLISHER;
        }

        return WritingFeeService::SOURCE_SLB;
    }

    public function siteQualifiesForGuarantee(?Site $site): bool
    {
        if (! $site instanceof Site) {
            return false;
        }

        return PublicationDuration::qualifiesForLinkGuarantee($site->publication_time);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public static function isPublisherWritten(array $item): bool
    {
        return ($item['content_source'] ?? '') === WritingFeeService::SOURCE_PUBLISHER;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public static function isWrittenSource(array $item): bool
    {
        return in_array((string) ($item['content_source'] ?? ''), [
            WritingFeeService::SOURCE_PUBLISHER,
            WritingFeeService::SOURCE_SLB,
            WritingFeeService::SOURCE_WRITTEN,
        ], true);
    }

    /**
     * @param  array<int, array<string, mixed>>  $expanded
     * @param  array<int, array<string, mixed>>  $cart
     * @return array<int, array<string, mixed>>
     */
    public function decorateExpandedOrders(array $expanded, array $cart, Request $request): array
    {
        $guarantee = $request->boolean('link_guarantee');

        return array_map(function (array $orderItem) use ($cart, $guarantee, $request) {
            $site = $orderItem['site'] ?? null;
            $siteId = (int) ($orderItem['id'] ?? ($site instanceof Site ? $site->id : 0));
            $cartLine = $this->matchingCartLine($cart, $siteId, $orderItem);

            return $this->decorateOrderItem($orderItem, $cartLine, $guarantee, $request);
        }, $expanded);
    }

    /**
     * @param  array<string, mixed>  $orderItem
     * @param  array<string, mixed>|null  $cartLine
     * @return array<string, mixed>
     */
    public function decorateOrderItem(array $orderItem, ?array $cartLine, bool $linkGuarantee, ?Request $request = null): array
    {
        $site = $orderItem['site'] ?? null;
        $raw = $cartLine['content_source']
            ?? $orderItem['content_source']
            ?? WritingFeeService::SOURCE_BUYER;
        $source = $this->normalizeRequestedSource((string) $raw, $site instanceof Site ? $site : null);

        $orderItem['content_source'] = WritingFeeService::SOURCE_BUYER;
        $orderItem['writing_fee'] = 0.0;
        $orderItem['listing_writing_fee'] = 0.0;
        $orderItem['writing_target_url'] = trim((string) ($cartLine['writing_target_url'] ?? $orderItem['writing_target_url'] ?? ''));
        $orderItem['writing_anchor_text'] = trim((string) ($cartLine['writing_anchor_text'] ?? $orderItem['writing_anchor_text'] ?? ''));

        if ($site instanceof Site && self::isWrittenSource(['content_source' => $source])) {
            $fee = $this->advertiserWritingFeeForSource($site, $source);
            $slbWrites = $source === WritingFeeService::SOURCE_SLB;
            $listingFee = $slbWrites ? 0.0 : $this->writing->resolvedListingWritingFee($site);
            $orderItem['content_source'] = $source;
            $orderItem['writing_fee'] = $fee;
            $orderItem['listing_writing_fee'] = $listingFee;
            $orderItem['price'] = round((float) $orderItem['price'] + $fee, 2);
            if (! $slbWrites) {
                $orderItem['publisher_amount'] = round((float) ($orderItem['publisher_amount'] ?? 0) + $listingFee, 2);
            }
            $orderItem['advertiser_eur_amount'] = $orderItem['price'];
        }

        $placement = round((float) $orderItem['price'], 2);
        $orderItem['link_guarantee'] = false;
        $orderItem['link_guarantee_fee'] = 0.0;
        $orderItem['link_guarantee_covered_amount'] = 0.0;
        $orderItem['link_guarantee_offered'] = $this->siteQualifiesForGuarantee($site instanceof Site ? $site : null);

        if ($linkGuarantee && $placement > 0 && $orderItem['link_guarantee_offered']) {
            $percent = max(0, (float) config('orders.link_guarantee.percent', 10));
            $fee = round($placement * ($percent / 100), 2);
            $orderItem['link_guarantee'] = true;
            $orderItem['link_guarantee_fee'] = $fee;
            $orderItem['link_guarantee_covered_amount'] = $placement;
            $orderItem['price'] = round($placement + $fee, 2);
            $orderItem['advertiser_eur_amount'] = $orderItem['price'];
        }

        return $orderItem;
    }

    /**
     * @param  array<string, mixed>  $orderItem
     * @return array<string, mixed>
     */
    public function orderColumns(array $orderItem): array
    {
        if (empty($orderItem['link_guarantee'])) {
            return [
                'link_guarantee' => false,
                'link_guarantee_fee' => 0,
                'link_guarantee_covered_amount' => 0,
                'link_guarantee_status' => null,
            ];
        }

        return [
            'link_guarantee' => true,
            'link_guarantee_fee' => $orderItem['link_guarantee_fee'] ?? 0,
            'link_guarantee_covered_amount' => $orderItem['link_guarantee_covered_amount'] ?? 0,
            'link_guarantee_status' => 'pending',
        ];
    }

    /**
     * @param  array<string, mixed>  $orderItem
     * @return array<string, mixed>
     */
    public function orderItemColumns(array $orderItem): array
    {
        return [
            'content_source' => $orderItem['content_source'] ?? WritingFeeService::SOURCE_BUYER,
            'writing_fee' => $orderItem['writing_fee'] ?? 0,
            'listing_writing_fee' => $orderItem['listing_writing_fee'] ?? null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $cartItems
     * @return array<int, array<string, mixed>>
     */
    public function checkoutViewItems(array $cartItems): array
    {
        return array_map(fn (array $item) => $this->decorateCartLine($item), $cartItems);
    }

    /**
     * Advertiser-facing writing metadata for a cart / checkout line.
     * Never attaches partner rates, listing markup, or publisher payout.
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    public function decorateCartLine(array $line, ?Site $site = null): array
    {
        $site = $site instanceof Site ? $site : Site::query()->find($line['id'] ?? 0);
        $publisherOffered = $site instanceof Site && $this->writing->writingOffered($site);
        $writer = $this->requestedWriter($site instanceof Site ? $site : null);
        $fee = $site instanceof Site
            ? $this->advertiserWritingFeeForSource($site, $writer)
            : WritingFeeService::SLB_FLAT_EUR;
        $source = $this->normalizeRequestedSource(
            (string) ($line['content_source'] ?? ''),
            $site instanceof Site ? $site : null
        );

        $line['content_source'] = $source;
        $line['writing_offered'] = $publisherOffered;
        $line['slb_writing_offered'] = true;
        $line['writing_writer'] = $writer;
        $line['writing_fee'] = $fee;
        $line['writing_fee_label'] = $fee > 0 ? format_money_pay($fee) : '';
        $line['writing_target_url'] = trim((string) ($line['writing_target_url'] ?? ''));
        $line['writing_anchor_text'] = trim((string) ($line['writing_anchor_text'] ?? ''));
        $line['guarantee_offered'] = $this->siteQualifiesForGuarantee($site instanceof Site ? $site : null);
        $line['writing_turnaround_text'] = $this->writingTurnaroundText();
        $line['writing_explainer'] = $this->writingExplainer();
        $line['writing_guarantee_note'] = $this->writingGuaranteeNote();

        return $line;
    }

    public function writingTurnaroundText(): string
    {
        return FeatureOfferSetting::writingTurnaroundText();
    }

    public function writingExplainer(): string
    {
        $text = trim((string) config(
            'orders.writing.explainer',
            "Written by the SEOLinkBuildings team (or the publisher, if they write for this site) in the site's language and sent to you for approval before publishing."
        ));

        return $text !== ''
            ? $text
            : "Written by the SEOLinkBuildings team (or the publisher, if they write for this site) in the site's language and sent to you for approval before publishing.";
    }

    public function writingGuaranteeNote(): string
    {
        return '12-month link guarantee available at checkout';
    }

    public function writingStepsWrite(): string
    {
        return 'We write → you approve → publisher publishes';
    }

    public function writingStepsUpload(): string
    {
        return 'You upload → you assign → publisher publishes';
    }

    /**
     * Advertiser-facing writing copy shared by the cart drawer and wizard.
     *
     * @return array{explainer: string, turnaround_text: string, guarantee_note: string, steps_write: string, steps_upload: string}
     */
    public function writingChoiceCopy(): array
    {
        return [
            'explainer' => $this->writingExplainer(),
            'turnaround_text' => $this->writingTurnaroundText(),
            'guarantee_note' => $this->writingGuaranteeNote(),
            'steps_write' => $this->writingStepsWrite(),
            'steps_upload' => $this->writingStepsUpload(),
        ];
    }

    /**
     * Store the cart-drawer writing choice (publisher if they self-write, else SLB).
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    public function applyWritingChoiceToLine(
        array $line,
        ?Site $site,
        string $rawSource,
        ?string $targetUrl = null,
        ?string $anchor = null
    ): array {
        $line['content_source'] = $this->normalizeRequestedSource($rawSource, $site);
        if ($targetUrl !== null) {
            $line['writing_target_url'] = trim($targetUrl);
        }
        if ($anchor !== null) {
            $line['writing_anchor_text'] = trim($anchor);
        }

        return $this->decorateCartLine($line, $site);
    }

    public function normalizeSourceForSite(string $raw, ?Site $site): string
    {
        return $this->normalizeRequestedSource($raw, $site);
    }

    /**
     * @param  array<int, array<string, mixed>>  $cartItems
     */
    public function anyLineQualifiesForGuarantee(array $cartItems): bool
    {
        foreach ($cartItems as $item) {
            if (! empty($item['guarantee_offered'])) {
                return true;
            }
        }

        return false;
    }

    public function guaranteePercent(): float
    {
        return max(0, (float) config('orders.link_guarantee.percent', 10));
    }

    public function termsText(): string
    {
        $months = (int) config('orders.link_guarantee.months', 12);
        $days = (int) config('orders.link_guarantee.restore_days', 7);
        $percent = rtrim(rtrim(number_format($this->guaranteePercent(), 2), '0'), '.');

        return 'Optional '.$months.'-month link guarantee from SEOLinkBuildings. Available only on listings whose publication period is at least 12 months or Permanent — not on 6-month listings. Once a month we fetch the live URL and check that your target URL still appears as a hyperlink. If it is missing, we ask for restore within '.$days.' days. If it is not restored, we refund the guaranteed placement amount to your wallet. This is SEOLinkBuildings’ promise, not the publisher’s. The guarantee fee is '.$percent.'% of the placement total and is paid by you — publishers pay nothing. This is not a ranking, traffic, or indexation promise.';
    }

    public function eligibilityHelpText(): string
    {
        return 'The 12-month link guarantee is only offered on sites that stay live for at least 12 months or permanently.';
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function sourceFromItem(array $item): string
    {
        $source = (string) ($item['content_source'] ?? '');

        return self::isWrittenSource(['content_source' => $source])
            ? $source
            : WritingFeeService::SOURCE_BUYER;
    }

    private function normalizeRequestedSource(string $raw, ?Site $site): string
    {
        $raw = strtolower(trim($raw));
        if (in_array($raw, [
            WritingFeeService::SOURCE_PUBLISHER,
            WritingFeeService::SOURCE_SLB,
            WritingFeeService::SOURCE_WRITTEN,
        ], true)) {
            return $this->requestedWriter($site);
        }

        return WritingFeeService::SOURCE_BUYER;
    }

    /**
     * Publisher self-write uses the listing fee. SEOLinkBuildings writing is a
     * flat EUR 35 in every language and never lands on publisher_amount.
     */
    private function advertiserWritingFeeForSource(Site $site, string $source): float
    {
        if ($source === WritingFeeService::SOURCE_PUBLISHER && $this->writing->writingOffered($site)) {
            return $this->writing->resolvedAdvertiserWritingFee($site);
        }

        return WritingFeeService::SLB_FLAT_EUR;
    }

    /**
     * @param  array<int, array<string, mixed>>  $cart
     * @param  array<string, mixed>  $orderItem
     * @return array<string, mixed>|null
     */
    private function matchingCartLine(array $cart, int $siteId, array $orderItem): ?array
    {
        foreach ($cart as $row) {
            if (! is_array($row)) {
                continue;
            }
            if ((int) ($row['id'] ?? 0) !== $siteId) {
                continue;
            }

            return $row;
        }

        return null;
    }
}
