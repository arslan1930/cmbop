<?php

namespace Tests\Feature;

use App\Models\LegalPageOverride;
use App\Models\Role;
use App\Models\StaffCapability;
use App\Models\User;
use App\Services\Auth\StaffCapabilityService;
use App\Support\LocalizedPublicPath;
use App\Support\ProductionRepair;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminLegalPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        StaffCapability::ensureTable();
        LegalPageOverride::forgetTableAvailabilityCache();
        LegalPageOverride::ensureTable();
    }

    public function test_public_legal_pages_stay_on_built_in_translations_by_default(): void
    {
        $this->get('/privacy-policy')
            ->assertOk()
            ->assertSee(__('messages.privacy_hero_title'))
            ->assertSee(__('messages.quick_navigation'))
            ->assertDontSee('legal-cms-body', false);

        $this->get('/cookie-policy')
            ->assertOk()
            ->assertSee(__('messages.cookie_title'))
            ->assertSee(__('messages.cookie_section_1_title'));

        $this->get('/refund-policy')
            ->assertOk()
            ->assertSee('FAQPage', false);
    }

    public function test_published_override_replaces_that_locale_only(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->put(route('admin.legal.update', 'cookie-policy'), [
                'locale' => 'en',
                'title' => 'Custom cookie heading',
                'body_html' => '<p>Custom cookie override UNIQUE-CMS-TOKEN</p><script>alert(1)</script>',
                'publish' => '1',
            ])
            ->assertRedirect(route('admin.legal.edit', ['slug' => 'cookie-policy', 'locale' => 'en']))
            ->assertSessionHas('success');

        $this->get('/cookie-policy')
            ->assertOk()
            ->assertSee('Custom cookie heading')
            ->assertSee('UNIQUE-CMS-TOKEN')
            ->assertSee('legal-cms-body', false)
            ->assertDontSee('alert(1)', false)
            ->assertDontSee(__('messages.cookie_section_1_title'));

        $this->get(LocalizedPublicPath::publicPath('cookie-policy', 'de'))
            ->assertOk()
            ->assertSee('Was sind Cookies?')
            ->assertDontSee('UNIQUE-CMS-TOKEN');
    }

    public function test_unpublished_draft_does_not_replace_the_public_page(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->put(route('admin.legal.update', 'cookie-policy'), [
                'locale' => 'en',
                'title' => 'Draft only',
                'body_html' => '<p>DRAFT-COOKIE-TOKEN</p>',
            ])
            ->assertRedirect();

        $this->get('/cookie-policy')
            ->assertOk()
            ->assertSee(__('messages.cookie_section_1_title'))
            ->assertDontSee('DRAFT-COOKIE-TOKEN');

        $this->actingAs($admin)
            ->get(route('admin.legal.edit', ['slug' => 'cookie-policy', 'locale' => 'en']))
            ->assertOk()
            ->assertSee('DRAFT-COOKIE-TOKEN');
    }

    public function test_revert_deletes_the_override_and_restores_translations(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->put(route('admin.legal.update', 'privacy-policy'), [
                'locale' => 'en',
                'body_html' => '<p>PRIVACY-OVERRIDE-TOKEN</p>',
                'publish' => '1',
            ])
            ->assertRedirect();

        $this->get('/privacy-policy')->assertSee('PRIVACY-OVERRIDE-TOKEN');

        $this->actingAs($admin)
            ->post(route('admin.legal.revert', 'privacy-policy'), ['locale' => 'en'])
            ->assertRedirect(route('admin.legal.edit', ['slug' => 'privacy-policy', 'locale' => 'en']));

        $this->get('/privacy-policy')
            ->assertOk()
            ->assertSee(__('messages.quick_navigation'))
            ->assertDontSee('PRIVACY-OVERRIDE-TOKEN');
    }

    public function test_support_can_open_legal_pages_and_finance_only_cannot(): void
    {
        $support = $this->userWithRole('admin', ['email' => 'legal-support@example.com']);
        $this->restrict($support, [StaffCapability::SUPPORT]);
        $finance = $this->userWithRole('admin', ['email' => 'legal-finance@example.com']);
        $this->restrict($finance, [StaffCapability::FINANCE]);

        $this->actingAs($support)
            ->get(route('admin.legal.index'))
            ->assertOk()
            ->assertSee('Privacy policy');

        $this->actingAs($finance)
            ->get(route('admin.legal.index'))
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_unknown_slug_is_not_found(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->get(route('admin.legal.edit', 'not-a-legal-page'))
            ->assertNotFound();
    }

    public function test_repair_creates_legal_page_overrides_table_when_missing(): void
    {
        Schema::dropIfExists('legal_page_overrides');
        LegalPageOverride::forgetTableAvailabilityCache();
        $this->assertFalse(ProductionRepair::legalPageOverrideStorageReady());

        $notes = [];
        app(ProductionRepair::class)->ensureLegalPageOverrides($notes);

        $this->assertTrue(Schema::hasTable('legal_page_overrides'));
        $this->assertTrue(collect($notes)->contains('legal page overrides table ready'));
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
}
