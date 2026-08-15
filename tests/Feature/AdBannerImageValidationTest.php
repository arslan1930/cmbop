<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AdBannerImageValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        $role = Role::where('name', 'admin')->firstOrFail();
        $this->admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $this->admin->roles()->attach($role->id);
    }

    public function test_svg_upload_is_rejected(): void
    {
        $svg = UploadedFile::fake()->create('ad.svg', 20, 'image/svg+xml');

        $this->actingAs($this->admin)
            ->from(route('admin.promotions.banners.create'))
            ->post(route('admin.promotions.banners.store'), [
                'name' => 'SVG ad',
                'size_key' => 'custom',
                'width' => 300,
                'height' => 250,
                'placement' => 'header',
                'audience' => 'all',
                'image' => $svg,
                'is_active' => 1,
            ])
            ->assertRedirect(route('admin.promotions.banners.create'))
            ->assertSessionHasErrors('image');
    }

    public function test_tiny_image_on_leaderboard_is_rejected(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD is required for dimension checks');
        }

        $this->actingAs($this->admin)
            ->from(route('admin.promotions.banners.create'))
            ->post(route('admin.promotions.banners.store'), [
                'name' => 'Tiny',
                'size_key' => 'leaderboard',
                'placement' => 'header',
                'audience' => 'all',
                'image' => $this->png(10, 10),
                'is_active' => 1,
            ])
            ->assertRedirect(route('admin.promotions.banners.create'))
            ->assertSessionHasErrors('image');
    }

    public function test_matching_leaderboard_image_is_accepted(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD is required for dimension checks');
        }

        $this->actingAs($this->admin)
            ->post(route('admin.promotions.banners.store'), [
                'name' => 'Fit',
                'size_key' => 'leaderboard',
                'placement' => 'header',
                'audience' => 'all',
                'image' => $this->png(728, 90),
                'is_active' => 1,
            ])
            ->assertRedirect(route('admin.promotions.banners.index'));
    }

    private function png(int $width, int $height): UploadedFile
    {
        $img = imagecreatetruecolor($width, $height);
        $path = sys_get_temp_dir().'/promo-'.$width.'x'.$height.'-'.uniqid().'.png';
        imagepng($img, $path);
        imagedestroy($img);

        return new UploadedFile($path, 'banner.png', 'image/png', null, true);
    }
}
