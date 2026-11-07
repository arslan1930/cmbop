<?php

namespace App\Support;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;

/**
 * Display mapping for invoice line items. Does not rewrite stored snapshots.
 */
class InvoiceLinePresenter
{
    /**
     * @param  array<string, mixed>  $line
     */
    public static function websiteLabel(array $line, ?OrderItem $item = null): string
    {
        $name = trim((string) (
            $line['site_name']
            ?? ($item?->site_name)
            ?? ''
        ));
        if ($name === '' && isset($line['publisher_website']) && ! str_contains((string) $line['publisher_website'], '://')) {
            $name = trim((string) $line['publisher_website']);
        }

        $url = trim((string) ($line['site_url'] ?? $line['publisher_website'] ?? $item?->site_url ?? ''));
        $domain = self::hostFromUrl($url) ?: self::hostFromUrl($name);
        if ($domain && $name !== '' && ! str_contains(strtolower($name), $domain)) {
            return $name.' ('.$domain.')';
        }
        if ($domain) {
            return $domain;
        }

        return $name !== '' ? $name : '—';
    }

    /**
     * @param  array<string, mixed>  $line
     */
    public static function liveUrl(array $line, ?OrderItem $item = null): ?string
    {
        foreach ([$line['live_url'] ?? null, $item?->live_url ?? null] as $candidate) {
            $safe = MailNotificationPresenter::safeLiveUrl($candidate);
            if ($safe) {
                return $safe;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $line
     */
    public static function description(array $line): string
    {
        $description = trim((string) ($line['description'] ?? ''));

        return $description !== '' ? $description : 'Guest post / sponsored placement';
    }

    /**
     * @return list<array{description: string, website: string, live_url: ?string, quantity: mixed, unit_price: mixed, line_total: mixed, reference: ?string}>
     */
    public static function displayLines(Invoice $invoice): array
    {
        $items = self::orderItemsByHint($invoice);
        $rows = [];

        foreach ((array) ($invoice->line_items ?? []) as $index => $line) {
            if (! is_array($line)) {
                continue;
            }
            $item = $items[$index] ?? self::matchItem($line, $items);
            $rows[] = [
                'description' => self::description($line),
                'website' => self::websiteLabel($line, $item),
                'live_url' => self::liveUrl($line, $item),
                'quantity' => $line['quantity'] ?? 1,
                'unit_price' => $line['unit_price'] ?? $line['price'] ?? 0,
                'line_total' => $line['line_total'] ?? $line['total'] ?? 0,
                'reference' => isset($line['reference']) ? (string) $line['reference'] : null,
            ];
        }

        return $rows;
    }

    /**
     * Human label for invoice.reference_code, or null when it is redundant.
     *
     * @return array{label: string, value: string}|null
     */
    public static function checkoutReference(Invoice $invoice): ?array
    {
        $ref = trim((string) ($invoice->reference_code ?? ''));
        if ($ref === '') {
            return null;
        }

        $orderNumber = trim((string) ($invoice->order_number ?? ''));
        if ($orderNumber !== '' && strcasecmp($ref, $orderNumber) === 0) {
            return null;
        }

        $paymentRef = trim((string) data_get($invoice->meta, 'payment_reference', ''));
        if ($paymentRef !== '' && strcasecmp($ref, $paymentRef) === 0) {
            return null;
        }

        $label = $invoice->type === Invoice::TYPE_DEPOSIT_RECEIPT
            ? 'Checkout reference'
            : 'Checkout reference';

        return ['label' => $label, 'value' => $ref];
    }

    /**
     * @return array<int, OrderItem>
     */
    private static function orderItemsByHint(Invoice $invoice): array
    {
        try {
            $order = $invoice->relationLoaded('order') ? $invoice->order : $invoice->order()->first();
        } catch (\Throwable) {
            $order = null;
        }
        if (! $order instanceof Order) {
            return [];
        }
        $order->loadMissing('items');

        return $order->items->values()->all();
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  array<int, OrderItem>  $items
     */
    private static function matchItem(array $line, array $items): ?OrderItem
    {
        $url = strtolower(trim((string) ($line['site_url'] ?? '')));
        $name = strtolower(trim((string) ($line['site_name'] ?? $line['publisher_website'] ?? '')));
        foreach ($items as $item) {
            if ($url !== '' && strtolower((string) $item->site_url) === $url) {
                return $item;
            }
            if ($name !== '' && (
                strtolower((string) $item->site_name) === $name
                || strtolower((string) $item->site_url) === $name
            )) {
                return $item;
            }
        }

        return null;
    }

    private static function hostFromUrl(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $prefixed = preg_match('~^https?://~i', $value) === 1 ? $value : 'https://'.$value;
        $host = parse_url($prefixed, PHP_URL_HOST);
        $host = is_string($host) ? strtolower($host) : '';
        $host = preg_replace('/^www\./', '', $host) ?: $host;

        return $host !== '' ? $host : null;
    }
}
