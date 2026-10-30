<?php

namespace App\Support;

/**
 * Browser titles for admin pages: "{Page} — Admin — SEOLinkBuildings".
 */
final class AdminPageTitle
{
    /** @var array<string, string> */
    private const ROUTES = [
        'admin.dashboard' => 'Dashboard',
        'admin.users.index' => 'Users',
        'admin.users.show' => 'User',
        'admin.users.export' => 'Users export',
        'admin.deposits' => 'Deposits',
        'admin.deposits.show' => 'Deposit',
        'admin.withdrawals' => 'Withdrawals',
        'admin.withdrawals.history' => 'Payout history',
        'admin.withdrawals.show' => 'Withdrawal',
        'admin.payments' => 'Payments',
        'admin.payments.show' => 'Payment',
        'admin.orders.index' => 'Orders',
        'admin.orders.show' => 'Order',
        'admin.invoices.index' => 'Invoices',
        'admin.invoices.show' => 'Invoice',
        'admin.finance' => 'Finance',
        'admin.finance.ledger' => 'Finance ledger',
        'admin.finance.user' => 'User finance',
        'admin.sites.index' => 'Sites',
        'admin.sites.records' => 'Records',
        'admin.sites.edit' => 'Edit site',
        'admin.sites.create' => 'Add site',
        'admin.sites.bulk-create' => 'Bulk add sites',
        'admin.sites.on-demand.index' => 'On-demand',
        'admin.site-ratings.index' => 'Site ratings',
        'admin.site-enrichment' => 'Site enrichment',
        'admin.community.index' => 'Community',
        'admin.activity-logs.index' => 'Activity history',
        'admin.catalog-activity' => 'Catalog activity',
        'admin.catalog-activity.show' => 'Catalog activity',
        'admin.emails.index' => 'Email Center',
        'admin.emails.log' => 'Email log',
        'admin.emails.preview' => 'Email preview',
        'admin.campaigns.index' => 'Campaigns',
        'admin.abandoned-carts.index' => 'Abandoned carts',
        'admin.campaigns.show' => 'Campaign',
        'admin.audiences.index' => 'Audiences',
        'admin.promotions.index' => 'Promotions',
        'admin.blogs.index' => 'Blogs',
        'admin.blogs.create' => 'New blog',
        'admin.blogs.edit' => 'Edit blog',
        'admin.blogs.show' => 'Blog',
        'admin.blogs.preview' => 'Blog preview',
        'admin.moderation.index' => 'Moderation',
        'admin.moderation.show' => 'Moderation',
        'admin.content-library.index' => 'Content Library',
        'admin.content-library.show' => 'Content Library',
        'admin.bulk-site-requests.index' => 'Bulk site requests',
        'admin.bulk-site-requests.show' => 'Bulk site request',
        'admin.dashboard.stalled-orders' => 'Stalled orders',
        'admin.staff-handbook' => 'Staff handbook',
    ];

    public static function document(?string $yielded, ?string $routeName): string
    {
        return self::pageLabel($yielded, $routeName).' — Admin — SEOLinkBuildings';
    }

    public static function pageLabel(?string $yielded, ?string $routeName): string
    {
        $yielded = trim((string) $yielded);
        $yielded = trim((string) preg_replace('/\s*[—–-]\s*(Admin\s*[—–-]\s*)?SEOLinkBuildings\s*$/iu', '', $yielded));
        if ($yielded !== '' && ! in_array($yielded, ['Admin Dashboard', 'Admin', 'Dashboard'], true)) {
            return $yielded;
        }

        if (is_string($routeName) && $routeName !== '' && isset(self::ROUTES[$routeName])) {
            return self::ROUTES[$routeName];
        }

        if (is_string($routeName) && str_starts_with($routeName, 'admin.')) {
            $tail = trim((string) preg_replace(
                '/\s+/',
                ' ',
                str_replace(['admin.', '.', '-', '_'], ['', ' ', ' ', ' '], $routeName)
            ));
            if ($tail !== '') {
                return ucwords($tail);
            }
        }

        return 'Dashboard';
    }
}
