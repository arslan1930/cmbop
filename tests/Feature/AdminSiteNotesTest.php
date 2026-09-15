<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\SiteAdminNote;
use App\Models\StaffCapability;
use App\Models\User;
use App\Services\Auth\StaffCapabilityService;
use App\Support\ProductionRepair;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminSiteNotesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        StaffCapability::ensureTable();
        SiteAdminNote::forgetTableAvailabilityCache();
        SiteAdminNote::ensureTable();
    }

    public function test_admin_can_add_a_site_note_and_see_it_with_history(): void
    {
        $admin = $this->userWithRole('admin', ['name' => 'Notes Admin']);
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);

        $this->actingAs($admin)
            ->get(route('admin.sites.edit', $site->id))
            ->assertOk()
            ->assertSee('Internal notes')
            ->assertSee('No notes yet.')
            ->assertSee('Activity');

        $this->actingAs($admin)
            ->from(route('admin.sites.edit', $site->id))
            ->post(route('admin.sites.notes.store', $site->id), [
                'body' => 'DA looks inflated — recheck before activate.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('site_admin_notes', [
            'site_id' => $site->id,
            'admin_id' => $admin->id,
            'body' => 'DA looks inflated — recheck before activate.',
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'site.note_added',
            'subject_id' => $site->id,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.sites.edit', $site->id))
            ->assertOk()
            ->assertSee('DA looks inflated — recheck before activate.')
            ->assertSee('Added site note')
            ->assertSee('Notes Admin');
    }

    public function test_support_can_add_notes_and_finance_only_cannot(): void
    {
        $support = $this->userWithRole('admin', ['email' => 'site-notes-support@example.com']);
        $this->restrict($support, [StaffCapability::SUPPORT]);
        $finance = $this->userWithRole('admin', ['email' => 'site-notes-finance@example.com']);
        $this->restrict($finance, [StaffCapability::FINANCE]);
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);

        $this->actingAs($support)
            ->post(route('admin.sites.notes.store', $site->id), [
                'body' => 'Support flagged this listing.',
            ])
            ->assertRedirect();
        $this->assertDatabaseHas('site_admin_notes', [
            'site_id' => $site->id,
            'body' => 'Support flagged this listing.',
        ]);

        $this->actingAs($finance)
            ->get(route('admin.sites.edit', $site->id))
            ->assertOk()
            ->assertSee('Support flagged this listing.')
            ->assertDontSee('Add a note (not visible to the publisher)');

        $this->actingAs($finance)
            ->post(route('admin.sites.notes.store', $site->id), [
                'body' => 'Finance should not write this.',
            ])
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('error');
        $this->assertDatabaseMissing('site_admin_notes', [
            'body' => 'Finance should not write this.',
        ]);
    }

    public function test_marketing_can_add_a_site_note(): void
    {
        $marketer = $this->userWithRole('marketing', ['name' => 'Listing Marketer']);
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher, [
            'verified' => false,
            'active' => false,
        ]);

        $this->actingAs($marketer)
            ->from(route('marketing.sites.edit', $site->id))
            ->post(route('marketing.sites.notes.store', $site->id), [
                'body' => 'Asked publisher for a better cover.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('site_admin_notes', [
            'site_id' => $site->id,
            'admin_id' => $marketer->id,
            'body' => 'Asked publisher for a better cover.',
        ]);
    }

    public function test_repair_creates_site_admin_notes_table_when_missing(): void
    {
        Schema::dropIfExists('site_admin_notes');
        SiteAdminNote::forgetTableAvailabilityCache();
        $this->assertFalse(ProductionRepair::siteAdminNoteStorageReady());

        $notes = [];
        app(ProductionRepair::class)->ensureSiteAdminNotes($notes);

        $this->assertTrue(Schema::hasTable('site_admin_notes'));
        $this->assertTrue(collect($notes)->contains('site admin notes table ready'));
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
            'site_name' => 'Notes Site',
            'site_url' => 'https://notes-site.example',
            'domain' => 'notes-site.example',
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
