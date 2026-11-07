<?php

namespace App\Support;

use App\Models\Order;
use App\Models\OrderItem;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Shared mapping for transactional mail: human status labels, timezone stamps,
 * placement + live-URL rows, and audience-safe summary fields.
 *
 * Notification summary tables never include counterparty names. Live article
 * URLs are shown only when a real http(s) value exists and the context is
 * review/completed — pending placements are never labelled live.
 */
class MailNotificationPresenter
{
    public const CONTEXT_REVIEW_READY = 'review_ready';

    public const CONTEXT_STATUS = 'status';

    public const CONTEXT_PAYMENT = 'payment';

    public const CONTEXT_CREATED = 'created';

    public const CONTEXT_COMPLETED = 'completed';

    public const CONTEXT_RECEIPT = 'receipt';

    public static function orderStatusLabel(?string $status): string
    {
        $status = strtolower(trim((string) $status));

        return match ($status) {
            'pending' => 'Pending',
            'processing', 'in_progress' => 'Processing',
            'review', 'in_review' => 'In review',
            'completed' => 'Completed',
            'cancelled', 'canceled' => 'Cancelled',
            'scheduled' => 'Scheduled',
            '' => '—',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    public static function paymentStatusLabel(?string $status): string
    {
        $status = strtolower(trim((string) $status));

        return match ($status) {
            'paid', 'completed' => 'Paid',
            'pending' => 'Pending',
            'failed' => 'Failed',
            'refunded' => 'Refunded',
            'unpaid' => 'Unpaid',
            '' => '—',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    public static function timezone(): string
    {
        $tz = trim((string) config('app.timezone', 'UTC'));

        return $tz !== '' ? $tz : 'UTC';
    }

    public static function formatDateTime(mixed $value, ?string $timezone = null): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $tz = $timezone ?: static::timezone();

        try {
            $date = $value instanceof CarbonInterface
                ? $value->copy()
                : Carbon::parse((string) $value);

            return $date->timezone($tz)->format('M j, Y g:i A T');
        } catch (\Throwable) {
            return '—';
        }
    }

    public static function safeLiveUrl(mixed $url): ?string
    {
        $safe = function_exists('safe_href_url') ? safe_href_url($url) : null;
        if (! is_string($safe) || $safe === '') {
            return null;
        }

        if (preg_match('~^https?://~i', $safe) !== 1) {
            return null;
        }

        return $safe;
    }

    public static function siteName(?OrderItem $item): string
    {
        $name = trim((string) ($item?->site?->site_name ?? $item?->site_name ?? ''));

        return $name !== '' ? $name : 'Website';
    }

    public static function siteDomain(?OrderItem $item): ?string
    {
        $candidates = [
            $item?->site?->domain ?? null,
            $item?->site?->site_url ?? null,
            $item?->site_url ?? null,
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value === '') {
                continue;
            }

            $prefixed = preg_match('~^https?://~i', $value) === 1 ? $value : 'https://'.$value;
            $host = parse_url($prefixed, PHP_URL_HOST);
            $host = is_string($host) ? strtolower($host) : '';
            $host = preg_replace('/^www\./', '', $host) ?: $host;
            if ($host !== '') {
                return $host;
            }
        }

        return null;
    }

    public static function siteLabel(?OrderItem $item): string
    {
        $name = static::siteName($item);
        $domain = static::siteDomain($item);
        if ($domain && ! str_contains(strtolower($name), $domain)) {
            return $name.' ('.$domain.')';
        }

        return $name;
    }

    public static function liveUrlRelevant(?string $orderStatus, string $context): bool
    {
        if (in_array($context, [self::CONTEXT_REVIEW_READY, self::CONTEXT_COMPLETED], true)) {
            return true;
        }

        return in_array(strtolower((string) $orderStatus), ['review', 'completed'], true);
    }

    public static function liveUrlForItem(?OrderItem $item, bool $relevant): ?string
    {
        if (! $relevant || ! $item) {
            return null;
        }

        return static::safeLiveUrl($item->live_url ?? null);
    }

    /**
     * @return list<array{site_label: string, site_name: string, domain: ?string, live_url: ?string, pending: bool}>
     */
    public static function placements(Order $order, string $context = self::CONTEXT_STATUS, ?string $statusOverride = null): array
    {
        $order->loadMissing(['items.site']);
        $relevant = static::liveUrlRelevant($statusOverride ?? (string) $order->status, $context);
        $rows = [];

        foreach ($order->items as $item) {
            if (! $item instanceof OrderItem) {
                continue;
            }

            $url = static::liveUrlForItem($item, $relevant);
            $rows[] = [
                'site_label' => static::siteLabel($item),
                'site_name' => static::siteName($item),
                'domain' => static::siteDomain($item),
                'live_url' => $url,
                'pending' => $relevant && $url === null,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array{live_url?: ?string}>  $placements
     */
    public static function hasAnyLiveUrl(array $placements): bool
    {
        foreach ($placements as $row) {
            if (! empty($row['live_url'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{label: string, value: string, url?: string}>
     */
    public static function summaryRows(
        Order $order,
        string $audience,
        string $context,
        ?string $statusLabel = null,
        ?string $paymentLabel = null,
        ?string $statusOverride = null,
    ): array {
        $placements = static::placements($order, $context, $statusOverride);
        $websiteValue = '—';
        if (count($placements) === 1) {
            $websiteValue = $placements[0]['site_label'];
        } elseif (count($placements) > 1) {
            $websiteValue = collect($placements)->pluck('site_label')->filter()->implode(', ');
        }

        $rows = [
            ['label' => 'Order number', 'value' => '#'.$order->order_number],
            ['label' => 'Website', 'value' => $websiteValue],
        ];

        if ($context !== self::CONTEXT_REVIEW_READY && $context !== self::CONTEXT_PAYMENT) {
            $rows[] = [
                'label' => 'Order status',
                'value' => $statusLabel ?: static::orderStatusLabel((string) $order->status),
            ];
        }

        $showMoney = in_array($context, [self::CONTEXT_PAYMENT, self::CONTEXT_RECEIPT], true)
            || ($context === self::CONTEXT_CREATED && in_array($audience, ['advertiser', 'admin'], true));

        // Publishers must never see advertiser order totals / markup.
        if ($showMoney && $audience !== 'publisher') {
            if ($context !== self::CONTEXT_CREATED) {
                $rows[] = [
                    'label' => 'Payment status',
                    'value' => $paymentLabel ?: static::paymentStatusLabel((string) $order->payment_status),
                ];
            }
            $rows[] = [
                'label' => 'Amount',
                'value' => static::euro((float) $order->total_amount),
            ];
        }

        return $rows;
    }

    public static function euro(float $amount, string $symbol = '€'): string
    {
        return $symbol.number_format($amount, 2);
    }

    public static function repliesAreMonitored(): bool
    {
        $brand = config('email_notifications.brand', []);
        $replyTo = strtolower(trim((string) ($brand['reply_to'] ?? '')));
        $from = strtolower(trim((string) ($brand['sender_email'] ?? config('mail.from.address', ''))));
        $support = strtolower(trim((string) ($brand['support_email'] ?? '')));

        if ($replyTo === '' || $from === '') {
            return false;
        }

        if ($replyTo === $from) {
            return false;
        }

        return $support !== '' && $replyTo === $support;
    }

    public static function supportEmail(): ?string
    {
        $email = trim((string) config('email_notifications.brand.support_email', ''));

        return $email !== '' ? $email : null;
    }

    public static function supportLine(string $intent = 'order'): string
    {
        if ($intent === 'order') {
            return 'Questions or corrections belong on the order page. Replies to this email are not delivered to the order.';
        }

        $support = static::supportEmail();
        if ($intent === 'bulk') {
            return $support
                ? 'If this looks like a mistake, contact support at '.$support.'.'
                : 'If this looks like a mistake, open your sites page or contact support.';
        }

        return $support
            ? 'If you have questions, contact support at '.$support.'.'
            : 'If you have questions, contact support from your account.';
    }

    public static function isReviewReady(string $changeKind, string $newValue, Order $order): bool
    {
        if ($changeKind !== 'status' || strtolower($newValue) !== 'review') {
            return false;
        }

        return static::hasAnyLiveUrl(static::placements($order, self::CONTEXT_REVIEW_READY));
    }
}
