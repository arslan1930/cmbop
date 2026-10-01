<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminGrowthDocsTest extends TestCase
{
    public function test_growth_desk_docs_exist(): void
    {
        $docs = [
            'docs/admin-audiences.md' => ['/admin/audiences', 'AudienceInventoryService::EXPORT_LIMIT'],
            'docs/admin-blogs.md' => ['/admin/blogs', 'blog:upsert-curated'],
            'docs/admin-emails.md' => ['/admin/emails', 'ops-mail-reminders.md'],
            'docs/admin-promotions.md' => ['data-admin-filter-live=1', 'theme-selects submit'],
            'docs/admin-content-library.md' => ['file=missing', 'activity_logs', 'unset flags'],
            'docs/admin-activity-logs.md' => ['/admin/activity-logs', 'activity_logs.export_limit', 'user_id'],
            'docs/admin-catalog-activity.md' => ['/admin/catalog-activity', 'user', 'Lift hide', '14 days'],
        ];

        foreach ($docs as $relative => $needles) {
            $path = base_path($relative);
            $this->assertFileExists($path, $relative);
            $body = (string) file_get_contents($path);
            foreach ($needles as $needle) {
                $this->assertStringContainsString($needle, $body, $relative);
            }
        }

        $agents = (string) file_get_contents(base_path('AGENTS.md'));
        $this->assertStringContainsString('docs/admin-audiences.md', $agents);
        $this->assertStringContainsString('docs/admin-blogs.md', $agents);
        $this->assertStringContainsString('docs/admin-emails.md', $agents);
        $this->assertStringContainsString('docs/admin-activity-logs.md', $agents);
        $this->assertStringContainsString('docs/admin-catalog-activity.md', $agents);
    }

    public function test_promotions_list_filters_submit_and_editors_stay_live(): void
    {
        $banners = (string) file_get_contents(resource_path('views/admin/promotions/banners/index.blade.php'));
        $announcements = (string) file_get_contents(resource_path('views/admin/promotions/announcements/index.blade.php'));
        $bannerForm = (string) file_get_contents(resource_path('views/admin/promotions/banners/form.blade.php'));
        $announcementForm = (string) file_get_contents(resource_path('views/admin/promotions/announcements/form.blade.php'));

        $this->assertStringNotContainsString('data-admin-filter-live="1"', $banners);
        $this->assertStringNotContainsString('data-admin-filter-live="1"', $announcements);
        $this->assertStringContainsString('data-admin-filter-live="1"', $bannerForm);
        $this->assertStringContainsString('data-admin-filter-live="1"', $announcementForm);
    }
}
