<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CategoriesTableSeeder;
use Database\Seeders\CountriesTableSeeder;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MarketingSiteImageUploadTest extends TestCase
{
    use RefreshDatabase;

    private User $marketer;

    private User $admin;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        $this->seed(CountriesTableSeeder::class);
        $this->seed(LanguagesTableSeeder::class);
        $this->seed(CategoriesTableSeeder::class);

        Storage::fake('public');

        $marketingRole = Role::where('name', 'marketing')->firstOrFail();
        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $publisherRole = Role::where('name', 'publisher')->firstOrFail();

        $this->marketer = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $marketingRole->id,
        ]);
        $this->marketer->roles()->attach($marketingRole->id);

        $this->admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
        ]);
        $this->admin->roles()->attach($adminRole->id);

        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $this->publisher->roles()->attach($publisherRole->id);
    }

    private function makeSite(array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Image Upload Site',
            'site_url' => 'https://image-upload.example',
            'domain' => 'image-upload.example',
            'da' => 20,
            'dr' => 20,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'News',
            'categories' => ['News'],
            'price' => 40,
            'publication_time' => 'permanent',
            'description' => 'Image upload test site',
            'link_type' => 'dofollow',
            'verified' => false,
            'active' => false,
        ], $overrides));
    }

    private function nicheName(): string
    {
        return (Category::query()->where('name', 'Business & Finance')->first()
            ?? Category::query()->firstOrFail())->name;
    }

    public function test_marketer_cannot_upload_image_for_live_site(): void
    {
        $site = $this->makeSite([
            'domain' => 'live-image.example',
            'site_url' => 'https://live-image.example',
            'active' => true,
        ]);
        $file = UploadedFile::fake()->image('blocked.jpg', 320, 200);

        $this->actingAs($this->marketer)
            ->postJson(route('marketing.sites.upload-image', $site->id), [
                'site_image' => $file,
            ])
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->assertEmpty($site->fresh()->site_image);
    }

    public function test_marketer_upload_image_endpoint_persists_site_image(): void
    {
        $site = $this->makeSite();
        $file = UploadedFile::fake()->image('marketer-cover.jpg', 320, 200);

        $this->actingAs($this->marketer)
            ->postJson(route('marketing.sites.upload-image', $site->id), [
                'site_image' => $file,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $site->refresh();
        $this->assertNotEmpty($site->site_image);
        $this->assertStringStartsWith('sites/', $site->site_image);
        Storage::disk('public')->assertExists($site->site_image);
    }

    public function test_marketer_can_attach_image_via_site_update(): void
    {
        $site = $this->makeSite([
            'da' => 10,
            'dr' => 12,
            'traffic' => 900,
        ]);
        $file = UploadedFile::fake()->image('from-edit.webp', 400, 300);

        $this->actingAs($this->marketer)
            ->put(route('marketing.sites.update', $site->id), [
                'da' => 33,
                'dr' => 44,
                'traffic' => 5000,
                'language' => 'de',
                'country' => 'de',
                'categories' => $this->nicheName(),
                'site_image' => $file,
            ])
            ->assertRedirect(route('marketing.sites.index', [
                'publisher' => $site->publisher_id,
                'site' => $site->id,
            ]));

        $site->refresh();
        $this->assertSame(33, (int) $site->da);
        $this->assertNotEmpty($site->site_image);
        $this->assertStringStartsWith('sites/', $site->site_image);
        Storage::disk('public')->assertExists($site->site_image);
        // Restricted fields stay untouched.
        $this->assertSame('Image Upload Site', $site->site_name);
        $this->assertSame('https://image-upload.example', $site->site_url);
    }

    public function test_marketer_update_keeps_existing_site_image_path_string(): void
    {
        $site = $this->makeSite([
            'site_image' => 'sites/existing-cover.webp',
            'da' => 10,
            'dr' => 12,
            'traffic' => 900,
        ]);

        $this->actingAs($this->marketer)
            ->putJson(route('marketing.sites.update', $site->id), [
                'da' => 33,
                'dr' => 44,
                'traffic' => 5000,
                'language' => 'de',
                'country' => 'de',
                'categories' => [$this->nicheName()],
                'site_image' => 'sites/existing-cover.webp',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('sites/existing-cover.webp', $site->fresh()->site_image);
    }

    public function test_admin_upload_image_endpoint_still_persists_site_image(): void
    {
        $site = $this->makeSite(['domain' => 'admin-image.example', 'site_url' => 'https://admin-image.example']);
        $file = UploadedFile::fake()->image('admin-cover.png', 200, 150);

        $response = $this->actingAs($this->admin)
            ->postJson(route('admin.sites.upload-image', $site->id), [
                'site_image' => $file,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['image_path', 'image_url']);

        $site->refresh();
        $this->assertNotEmpty($site->site_image);
        Storage::disk('public')->assertExists($site->site_image);

        $imageUrl = (string) $response->json('image_url');
        $this->assertStringContainsString('/admin/sites/media/sites/', $imageUrl);
        $this->assertStringContainsString('?v=', $imageUrl);
    }

    public function test_marketer_upload_replaces_previous_image_file(): void
    {
        $oldPath = UploadedFile::fake()->image('old.jpg')->store('sites', 'public');
        $site = $this->makeSite(['site_image' => $oldPath]);
        Storage::disk('public')->assertExists($oldPath);

        $this->actingAs($this->marketer)
            ->postJson(route('marketing.sites.upload-image', $site->id), [
                'site_image' => UploadedFile::fake()->image('new.jpg'),
            ])
            ->assertOk();

        $site->refresh();
        $this->assertNotSame($oldPath, $site->site_image);
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($site->site_image);
    }

    public function test_marketing_and_admin_edit_pages_use_desktop_image_preview(): void
    {
        $site = $this->makeSite([
            'site_image' => 'sites/existing-cover.webp',
        ]);

        $marketingEdit = $this->actingAs($this->marketer)
            ->get(route('marketing.sites.edit', $site->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('site-image-desktop-preview', $marketingEdit);
        $this->assertStringContainsString('staff-sites.css', $marketingEdit);
        $this->assertStringContainsString('name="site_image"', $marketingEdit);
        $this->assertStringContainsString('enctype="multipart/form-data"', $marketingEdit);
        $this->assertStringContainsString('desktop screenshot', strtolower($marketingEdit));
        $this->assertStringContainsString('/marketing/sites/media/sites/existing-cover.webp', $marketingEdit);
        $this->assertStringContainsString('data-media-fallback', $marketingEdit);

        $adminEdit = $this->actingAs($this->admin)
            ->get(route('admin.sites.edit', $site->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('site-image-desktop-preview', $adminEdit);
        $this->assertStringContainsString('staff-sites.css', $adminEdit);
        $this->assertStringContainsString('name="site_image"', $adminEdit);

        $staffCss = file_get_contents(public_path('assets/css/staff-sites.css'));
        $this->assertStringContainsString('.site-image-desktop-preview', $staffCss);
        $this->assertStringContainsString('padding-top: 62.5%', $staffCss);
        $this->assertStringContainsString('max-width: 720px', $staffCss);
        $this->assertStringContainsString('object-fit: contain', $staffCss);
        $this->assertStringNotContainsString('@supports (aspect-ratio: 16 / 10)', $staffCss);
    }

    public function test_admin_sites_list_edit_dialog_uses_desktop_image_preview(): void
    {
        $html = file_get_contents(resource_path('views/admin/sites.blade.php'));
        $this->assertIsString($html);
        $this->assertStringContainsString('site-image-desktop-preview', $html);
        $this->assertStringContainsString('Desktop-size preview (16:10)', $html);
        $this->assertStringContainsString('width: 960', $html);
        $this->assertStringContainsString('SITE_IMAGE_MAX_KB', $html);
        $this->assertStringContainsString("X-CSRF-TOKEN': CSRF_TOKEN", $html);
        $this->assertStringContainsString("Accept': 'application/json'", $html);
        $this->assertStringContainsString('data-media-fallback', $html);
        $this->assertStringContainsString('function siteMediaUrl', $html);

        $staffCss = file_get_contents(public_path('assets/css/staff-sites.css'));
        $this->assertStringContainsString('.site-image-desktop-preview', $staffCss);
        $this->assertStringContainsString('max-width: 720px', $staffCss);
        $this->assertStringContainsString('object-fit: contain', $staffCss);
        $this->assertStringContainsString('.swal2-popup .site-image-desktop-preview', $staffCss);
        $this->assertStringContainsString('.site-preview-zoom-pop', $staffCss);
        // Row thumbs must contain the full desktop frame (not crop/zoom like mobile).
        $this->assertMatchesRegularExpression(
            '/\.site-row-preview img\s*\{[^}]*object-fit:\s*contain/s',
            $staffCss
        );
    }

    public function test_admin_upload_keeps_image_when_public_storage_probe_fails(): void
    {
        $site = $this->makeSite(['domain' => 'broken-link.example', 'site_url' => 'https://broken-link.example']);
        $file = UploadedFile::fake()->image('kept.png', 180, 120);

        $link = public_path('storage');
        $previous = is_link($link) ? readlink($link) : null;
        $backup = $link.'.audit-bak-'.uniqid('', true);
        $wrong = sys_get_temp_dir().'/cmbop-wrong-storage-'.uniqid('', true);

        try {
            if (file_exists($link) || is_link($link)) {
                rename($link, $backup);
            }

            mkdir($wrong, 0777, true);
            symlink($wrong, $link);

            $response = $this->actingAs($this->admin)
                ->postJson(route('admin.sites.upload-image', $site->id), [
                    'site_image' => $file,
                ])
                ->assertOk()
                ->assertJsonPath('success', true);

            $site->refresh();
            $this->assertNotEmpty($site->site_image);
            Storage::disk('public')->assertExists($site->site_image);

            $imageUrl = (string) $response->json('image_url');
            $this->assertNotSame('', $imageUrl);
            $this->assertTrue(
                str_contains($imageUrl, '/storage/') || str_contains($imageUrl, '/media/'),
                'Expected /storage or /media image_url, got: '.$imageUrl
            );
        } finally {
            if (is_link($link) || file_exists($link)) {
                @unlink($link);
            }
            if (is_dir($wrong)) {
                @rmdir($wrong);
            }
            if (is_string($previous) && $previous !== '' && ! file_exists($link) && ! is_link($link)) {
                @symlink($previous, $link);
            } elseif ((is_link($backup) || file_exists($backup)) && ! file_exists($link) && ! is_link($link)) {
                @rename($backup, $link);
            }
            if (is_link($backup) || file_exists($backup)) {
                @unlink($backup);
            }
        }
    }
}
