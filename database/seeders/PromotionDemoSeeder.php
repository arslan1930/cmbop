<?php

namespace Database\Seeders;

use App\Models\AdBanner;
use App\Models\SiteAnnouncement;
use App\Models\User;
use Illuminate\Database\Seeder;

class PromotionDemoSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->where('email', 'admin@test.com')->first()
            ?: User::query()->first();

        SiteAnnouncement::query()->updateOrCreate(
            ['title' => '20% OFF this week'],
            [
                'message' => 'Limited-time offer — save 20% on guest posts until Sunday. Inventory is limited.',
                'type' => 'limited_offer',
                'style' => 'promo',
                'audience' => 'all',
                'cta_label' => 'Shop the offer',
                'cta_url' => url('/advertiser/catalog'),
                'is_active' => true,
                'is_dismissible' => true,
                'priority' => 10,
                'starts_at' => now()->subHour(),
                'ends_at' => now()->addDays(7),
                'created_by' => $admin?->id,
            ]
        );

        SiteAnnouncement::query()->updateOrCreate(
            ['title' => 'New Spending Analytics is now live!'],
            [
                'message' => 'Track spend by order, day, and month from your advertiser dashboard.',
                'type' => 'new_feature',
                'style' => 'success',
                'audience' => 'advertiser',
                'cta_label' => 'Open analytics',
                'cta_url' => url('/advertiser/analytics'),
                'is_active' => true,
                'is_dismissible' => true,
                'priority' => 20,
                'starts_at' => now()->subHour(),
                'ends_at' => now()->addDays(30),
                'created_by' => $admin?->id,
            ]
        );

        SiteAnnouncement::query()->updateOrCreate(
            ['title' => 'Scheduled maintenance'],
            [
                'message' => 'We will perform maintenance this weekend. Some services may be briefly unavailable.',
                'type' => 'maintenance',
                'style' => 'warning',
                'audience' => 'all',
                'is_active' => true,
                'is_dismissible' => true,
                'priority' => 5,
                'starts_at' => now()->subHour(),
                'ends_at' => now()->addDays(3),
                'created_by' => $admin?->id,
            ]
        );

        SiteAnnouncement::query()->firstOrCreate(
            ['title' => 'Black Friday Early Access'],
            [
                'message' => 'Save 25% on guest posts this week. Limited marketplace inventory.',
                'type' => 'black_friday',
                'style' => 'promo',
                'audience' => 'all',
                'cta_label' => 'Browse offers',
                'cta_url' => url('/advertiser/catalog'),
                'is_active' => true,
                'is_dismissible' => true,
                'priority' => 15,
                'starts_at' => now()->subDay(),
                'ends_at' => now()->addDays(14),
                'created_by' => $admin?->id,
            ]
        );

        $dir = storage_path('app/public/banners');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $leaderboard = $dir . '/bf-leaderboard.svg';
        if (!file_exists($leaderboard)) {
            file_put_contents($leaderboard, '<svg xmlns="http://www.w3.org/2000/svg" width="728" height="90"><defs><linearGradient id="g" x1="0" x2="1"><stop offset="0%" stop-color="#0b6266"/><stop offset="100%" stop-color="#4ECDCB"/></linearGradient></defs><rect width="728" height="90" fill="url(#g)"/><text x="36" y="54" fill="#ffffff" font-family="Arial,sans-serif" font-size="28" font-weight="700">Black Friday - 25% off guest posts</text></svg>');
        }

        AdBanner::query()->firstOrCreate(
            ['name' => 'BF Leaderboard'],
            [
                'title' => 'Black Friday offer',
                'alt_text' => 'Black Friday 25% off',
                'size_key' => 'leaderboard',
                'width' => 728,
                'height' => 90,
                'image_path' => 'banners/bf-leaderboard.svg',
                'link_url' => url('/advertiser/catalog'),
                'placement' => 'header',
                'audience' => 'all',
                'is_active' => true,
                'open_in_new_tab' => false,
                'priority' => 10,
                'created_by' => $admin?->id,
            ]
        );

        $rect = $dir . '/marketplace-rect.svg';
        if (!file_exists($rect)) {
            file_put_contents($rect, '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="250"><rect width="300" height="250" fill="#0b6266"/><text x="24" y="120" fill="#ffffff" font-family="Arial" font-size="22">Marketplace</text><text x="24" y="155" fill="#4ECDCB" font-family="Arial" font-size="18">Featured offer</text></svg>');
        }

        AdBanner::query()->firstOrCreate(
            ['name' => 'Marketplace Rectangle'],
            [
                'alt_text' => 'Marketplace featured offer',
                'size_key' => 'medium_rectangle',
                'width' => 300,
                'height' => 250,
                'image_path' => 'banners/marketplace-rect.svg',
                'link_url' => url('/advertiser/catalog'),
                'placement' => 'marketplace',
                'audience' => 'advertiser',
                'is_active' => true,
                'priority' => 20,
                'created_by' => $admin?->id,
            ]
        );

        AdBanner::query()->firstOrCreate(
            ['name' => 'Mobile Leaderboard'],
            [
                'alt_text' => 'Mobile promo',
                'size_key' => 'mobile_leaderboard',
                'width' => 320,
                'height' => 50,
                'image_url' => 'https://placehold.co/320x50/0b6266/ffffff?text=Mobile+Offer',
                'link_url' => url('/'),
                'placement' => 'content_top',
                'audience' => 'all',
                'is_active' => true,
                'priority' => 30,
                'created_by' => $admin?->id,
            ]
        );
    }
}
