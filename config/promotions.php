<?php

/**
 * Admin-managed announcements and advertisement banner sizes/placements.
 */
return [

    /*
    | Featured notice categories shown as quick-create cards in Promotions Center.
    */
    'featured_notices' => [
        'limited_offer' => [
            'label' => 'Limited-Time Offer',
            'emoji' => '🎉',
            'icon' => 'fa-percent',
            'description' => 'Flash sales and timed discounts, e.g. “20% OFF this week”.',
            'default_style' => 'promo',
            'default_title' => '20% OFF this week',
            'default_message' => 'Limited-time offer — save 20% on guest posts until Sunday. Inventory is limited.',
            'default_cta_label' => 'Shop the offer',
            'default_cta_url' => '/advertiser/catalog',
            'default_priority' => 10,
            'default_ends_in_days' => 7,
        ],
        'new_feature' => [
            'label' => 'New Feature Announcement',
            'emoji' => '🚀',
            'icon' => 'fa-rocket',
            'description' => 'Product launches and updates, e.g. “New Spending Analytics is now live!”.',
            'default_style' => 'success',
            'default_title' => 'New Spending Analytics is now live!',
            'default_message' => 'Track spend by order, day, and month from your advertiser dashboard.',
            'default_cta_label' => 'Open analytics',
            'default_cta_url' => '/advertiser/analytics',
            'default_priority' => 20,
            'default_ends_in_days' => 30,
        ],
        'maintenance' => [
            'label' => 'Maintenance Notice',
            'emoji' => '📢',
            'icon' => 'fa-tools',
            'description' => 'Planned downtime or system maintenance windows.',
            'default_style' => 'warning',
            'default_title' => 'Scheduled maintenance',
            'default_message' => 'We will perform maintenance this weekend. Some services may be briefly unavailable.',
            'default_cta_label' => null,
            'default_cta_url' => null,
            'default_priority' => 5,
            'default_ends_in_days' => 3,
        ],
    ],

    'announcement_types' => [
        'limited_offer' => ['label' => 'Limited-Time Offer', 'icon' => 'fa-percent', 'featured' => true],
        'new_feature' => ['label' => 'New Feature', 'icon' => 'fa-rocket', 'featured' => true],
        'maintenance' => ['label' => 'Maintenance Notice', 'icon' => 'fa-tools', 'featured' => true],
        'discount' => ['label' => 'Discount', 'icon' => 'fa-tag'],
        'black_friday' => ['label' => 'Black Friday', 'icon' => 'fa-bolt'],
        'offer' => ['label' => 'Special Offer', 'icon' => 'fa-tags'],
        'change' => ['label' => 'Platform Change', 'icon' => 'fa-bullhorn'],
        'general' => ['label' => 'General Update', 'icon' => 'fa-info-circle'],
    ],

    'announcement_styles' => [
        'info' => 'Info (teal)',
        'success' => 'Success (green)',
        'warning' => 'Warning (amber)',
        'danger' => 'Urgent (red)',
        'promo' => 'Promo (gradient)',
    ],

    'audiences' => [
        'all' => 'Everyone',
        'public' => 'Public website only',
        'advertiser' => 'Advertisers only',
        'publisher' => 'Publishers only',
    ],

    /*
    | Standard IAB-friendly sizes that fit common website slots.
    */
    'banner_sizes' => [
        'leaderboard' => ['label' => 'Leaderboard', 'width' => 728, 'height' => 90, 'hint' => 'Header / top of page'],
        'billboard' => ['label' => 'Billboard', 'width' => 970, 'height' => 250, 'hint' => 'Wide hero / content top'],
        'medium_rectangle' => ['label' => 'Medium Rectangle', 'width' => 300, 'height' => 250, 'hint' => 'Sidebar / in-content'],
        'large_rectangle' => ['label' => 'Large Rectangle', 'width' => 336, 'height' => 280, 'hint' => 'Sidebar / in-content'],
        'half_page' => ['label' => 'Half Page', 'width' => 300, 'height' => 600, 'hint' => 'Tall sidebar'],
        'wide_skyscraper' => ['label' => 'Wide Skyscraper', 'width' => 160, 'height' => 600, 'hint' => 'Narrow sidebar'],
        'mobile_leaderboard' => ['label' => 'Mobile Leaderboard', 'width' => 320, 'height' => 50, 'hint' => 'Mobile header'],
        'mobile_banner' => ['label' => 'Mobile Banner', 'width' => 320, 'height' => 100, 'hint' => 'Mobile content'],
        'square' => ['label' => 'Square', 'width' => 250, 'height' => 250, 'hint' => 'Compact slot'],
        'small_square' => ['label' => 'Small Square', 'width' => 200, 'height' => 200, 'hint' => 'Small ad unit'],
        'custom' => ['label' => 'Custom size', 'width' => 0, 'height' => 0, 'hint' => 'Set width & height manually'],
    ],

    'banner_placements' => [
        'header' => 'Header (below nav)',
        'content_top' => 'Content top',
        'content_bottom' => 'Content bottom',
        'sidebar' => 'Sidebar',
        'footer' => 'Above footer',
        'marketplace' => 'Marketplace / catalog',
        'dashboard' => 'User dashboard',
    ],
];
