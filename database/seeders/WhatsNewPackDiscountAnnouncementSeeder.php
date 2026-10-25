<?php

namespace Database\Seeders;

use App\Models\SiteAnnouncement;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class WhatsNewPackDiscountAnnouncementSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('site_announcements')) {
            return;
        }

        $admin = User::query()->where('email', 'admin@test.com')->first()
            ?: User::query()->first();

        SiteAnnouncement::query()->updateOrCreate(
            ['title' => "What's new: 3, 5 and 10-article packs"],
            [
                'message' => 'Publishers can set 3, 5, and 10-article pack prices on their sites. Advertisers see a volume discount on selected sites that offer these packs. Open a listing to check whether a pack is available.',
                'type' => 'new_feature',
                'style' => 'success',
                'audience' => 'all',
                'cta_label' => 'Browse the catalog',
                'cta_url' => '/advertiser/catalog',
                'is_active' => false,
                'is_dismissible' => true,
                'priority' => 15,
                'version' => 1,
                'created_by' => $admin?->id,
            ]
        );
    }
}
