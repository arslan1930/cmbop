<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Role;
use App\Models\Site;
use App\Models\StaffCapability;
use App\Models\User;
use App\Services\Auth\StaffCapabilityService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCategoryCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        StaffCapability::ensureTable();
    }

    public function test_admin_can_create_a_niche(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->from(route('admin.categories.index'))
            ->post(route('admin.categories.store'), [
                'name' => 'SaaS & B2B Software',
                'group' => 'Technology',
            ])
            ->assertRedirect(route('admin.categories.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('categories', [
            'name' => 'SaaS & B2B Software',
            'group' => 'Technology',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.categories.index'))
            ->assertOk()
            ->assertSee('SaaS & B2B Software');
    }

    public function test_rename_updates_site_category_and_json(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');
        $category = Category::query()->create([
            'name' => 'Old Niche',
            'group' => 'Test',
        ]);
        $site = $this->siteFor($publisher, [
            'category' => 'Old Niche',
            'categories' => ['Old Niche', 'Keep Me'],
        ]);

        $this->actingAs($admin)
            ->put(route('admin.categories.update', $category), [
                'name' => 'New Niche',
                'group' => 'Renamed Group',
            ])
            ->assertRedirect(route('admin.categories.index'))
            ->assertSessionHas('success');

        $site->refresh();
        $this->assertSame('New Niche', $site->category);
        $this->assertSame(['New Niche', 'Keep Me'], $site->getCategoriesArrayAttribute());
        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'name' => 'New Niche',
            'group' => 'Renamed Group',
        ]);
    }

    public function test_delete_is_blocked_when_a_site_uses_the_niche(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');
        $category = Category::query()->create([
            'name' => 'In Use Niche',
            'group' => 'Test',
        ]);
        $this->siteFor($publisher, ['category' => 'In Use Niche']);

        $this->actingAs($admin)
            ->from(route('admin.categories.index'))
            ->delete(route('admin.categories.destroy', $category))
            ->assertRedirect(route('admin.categories.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('categories', ['name' => 'In Use Niche']);
    }

    public function test_unused_niche_can_be_deleted(): void
    {
        $admin = $this->userWithRole('admin');
        $category = Category::query()->create([
            'name' => 'Spare Niche',
            'group' => 'Test',
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.categories.destroy', $category))
            ->assertRedirect(route('admin.categories.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('categories', ['name' => 'Spare Niche']);
    }

    public function test_pipe_and_new_commas_are_rejected(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->from(route('admin.categories.index'))
            ->post(route('admin.categories.store'), [
                'name' => 'Split|Me',
                'group' => 'Test',
            ])
            ->assertRedirect(route('admin.categories.index'))
            ->assertSessionHasErrors('name');

        $this->actingAs($admin)
            ->from(route('admin.categories.index'))
            ->post(route('admin.categories.store'), [
                'name' => 'New, Comma Niche',
                'group' => 'Test',
            ])
            ->assertRedirect(route('admin.categories.index'))
            ->assertSessionHasErrors('name');

        $this->assertDatabaseMissing('categories', ['name' => 'Split|Me']);
        $this->assertDatabaseMissing('categories', ['name' => 'New, Comma Niche']);
    }

    public function test_grandfathered_comma_niche_can_keep_its_name(): void
    {
        $admin = $this->userWithRole('admin');
        $category = Category::query()->create([
            'name' => Category::NICHES_CONTAINING_COMMA[0],
            'group' => 'Other',
        ]);

        $this->actingAs($admin)
            ->put(route('admin.categories.update', $category), [
                'name' => Category::NICHES_CONTAINING_COMMA[0],
                'group' => 'Events',
            ])
            ->assertRedirect(route('admin.categories.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'group' => 'Events',
        ]);
    }

    public function test_support_can_open_niches_and_finance_only_cannot(): void
    {
        $support = $this->userWithRole('admin', ['email' => 'niche-support@example.com']);
        $this->restrict($support, [StaffCapability::SUPPORT]);
        $finance = $this->userWithRole('admin', ['email' => 'niche-finance@example.com']);
        $this->restrict($finance, [StaffCapability::FINANCE]);

        $this->actingAs($support)
            ->get(route('admin.categories.index'))
            ->assertOk()
            ->assertSee('Catalog niches');

        $this->actingAs($finance)
            ->get(route('admin.categories.index'))
            ->assertRedirect(route('admin.dashboard'));
    }

    /**
     * @param  list<string>  $capabilities
     */
    private function restrict(User $user, array $capabilities): void
    {
        StaffCapability::query()->where('user_id', $user->id)->delete();
        foreach ($capabilities as $capability) {
            StaffCapability::query()->create([
                'user_id' => $user->id,
                'capability' => $capability,
            ]);
        }
        app()->forgetInstance(StaffCapabilityService::class);
    }

    private function userWithRole(string $roleName, array $overrides = []): User
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $user = User::factory()->create(array_merge([
            'email_verified_at' => now(),
            'password' => 'password',
            'active_role_id' => $role->id,
        ], $overrides));
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function siteFor(User $publisher, array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $publisher->id,
            'site_name' => 'Niche Site',
            'site_url' => 'https://niche-crud.example',
            'domain' => 'niche-crud.example',
            'da' => 20,
            'dr' => 20,
            'traffic' => 100,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 40,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Test site',
            'verified' => true,
            'active' => true,
        ], $overrides));
    }
}
