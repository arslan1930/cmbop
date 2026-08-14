<?php

namespace Tests\Feature;

use App\Models\AdBanner;
use App\Models\Role;
use App\Models\SiteAnnouncement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Blade compiles {{ old('title') }} to htmlspecialchars(), which throws on an
 * array and takes the page with it. The old-input bag is shared across the
 * session and keyed only by field name, so one request posting `title[]=` — a
 * scanner, or a malformed submit anywhere in the app — left every promotions
 * form returning a 500 on a plain GET, to a page that had never been posted to.
 */
class PromotionsFormOldInputTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $role = Role::firstOrCreate(['name' => 'admin']);
        $u = User::factory()->create(['email_verified_at' => now(), 'active_role_id' => $role->id]);
        $u->roles()->attach($role->id);

        return $u->fresh();
    }

    private function announcement(): SiteAnnouncement
    {
        return SiteAnnouncement::create([
            'title' => 'Existing announcement',
            'message' => 'Body text',
            'type' => 'general',
            'style' => 'info',
            'audience' => 'all',
            'is_active' => true,
        ]);
    }

    private function banner(): AdBanner
    {
        return AdBanner::create([
            'name' => 'Existing banner',
            'size_key' => 'leaderboard',
            'width' => 728,
            'height' => 90,
            'placement' => 'header',
            'audience' => 'all',
            'is_active' => true,
        ]);
    }

    /**
     * Every scalar field on both forms, fuzzed one at a time.
     *
     * @return list<string>
     */
    private function scalarFields(): array
    {
        return [
            'title', 'message', 'cta_label', 'cta_url', 'priority', 'starts_at', 'ends_at',
            'name', 'width', 'height', 'image_url', 'link_url', 'alt_text',
        ];
    }

    public function test_the_forms_survive_an_array_in_the_old_input_bag(): void
    {
        $admin = $this->admin();
        $announcement = $this->announcement();
        $banner = $this->banner();

        $pages = [
            'admin.promotions.announcements.create' => [],
            'admin.promotions.announcements.edit' => [$announcement],
            'admin.promotions.banners.create' => [],
            'admin.promotions.banners.edit' => [$banner],
        ];

        $failures = [];

        foreach ($this->scalarFields() as $field) {
            foreach ($pages as $route => $params) {
                try {
                    $status = $this->actingAs($admin)
                        ->withSession(['_old_input' => [$field => ['first', 'second']]])
                        ->get(route($route, $params))
                        ->status();

                    if ($status !== 200) {
                        $failures[] = "{$route} with {$field}[] -> HTTP {$status}";
                    }
                } catch (\Throwable $e) {
                    $failures[] = "{$route} with {$field}[] -> ".class_basename($e).': '.$e->getMessage();
                }
            }
        }

        $this->assertSame([], $failures, "Poisoned old input still breaks:\n".implode("\n", $failures));
    }

    public function test_a_real_value_is_still_shown_back_to_the_user(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->withSession(['_old_input' => ['title' => 'Half typed headline']])
            ->get(route('admin.promotions.announcements.create'))
            ->assertOk()
            ->assertSee('Half typed headline', false);
    }

    public function test_an_array_value_keeps_the_first_entry_rather_than_blanking_the_field(): void
    {
        $admin = $this->admin();

        // The person still has to see and correct what they submitted.
        $this->actingAs($admin)
            ->withSession(['_old_input' => ['title' => ['Recoverable headline', 'ignored']]])
            ->get(route('admin.promotions.announcements.create'))
            ->assertOk()
            ->assertSee('Recoverable headline', false);
    }

    public function test_the_promotions_index_pages_still_render(): void
    {
        $admin = $this->admin();
        $this->announcement();
        $this->banner();

        foreach ([
            'admin.promotions.index',
            'admin.promotions.announcements.index',
            'admin.promotions.banners.index',
        ] as $route) {
            $this->actingAs($admin)->get(route($route))->assertOk();
        }
    }

    public function test_old_text_helper_flattens_only_what_it_must(): void
    {
        $this->assertSame('plain', old_text('missing', 'plain'));
        $this->assertSame('', old_text('missing'));
        $this->assertSame('0', old_text('missing', 0));
        $this->assertSame('', session_text('missing'));
        $this->assertSame('ok', session_text('missing', 'ok'));
    }
}
