<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use App\Support\UserMessages;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserOpsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
    }

    private function userWithRole(string $roleName, array $overrides = []): User
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $user = User::factory()->create(array_merge([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ], $overrides));
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    public function test_server_search_finds_user_not_on_first_page(): void
    {
        $admin = $this->userWithRole('admin', [
            'name' => 'Admin Operator',
            'email' => 'admin.operator@example.com',
        ]);
        $advertiser = Role::where('name', 'advertiser')->firstOrFail();
        $needle = $this->userWithRole('advertiser', [
            'name' => 'Needle Person',
            'email' => 'needle.person@example.com',
            'company_name' => 'Needle Co',
        ]);
        for ($i = 0; $i < 30; $i++) {
            $member = User::factory()->create([
                'email_verified_at' => now(),
                'active_role_id' => $advertiser->id,
                'name' => 'Filler '.$i,
                'email' => 'filler'.$i.'@example.com',
            ]);
            $member->roles()->attach($advertiser->id);
        }

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertDontSee('needle.person@example.com');

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['q' => 'needle.person']))
            ->assertOk()
            ->assertSee('Needle Person')
            ->assertSee('needle.person@example.com')
            ->assertDontSee('filler0@example.com')
            ->assertSee('Clear filters', false);

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['q' => (string) $needle->id]))
            ->assertOk()
            ->assertSee('needle.person@example.com');
    }

    public function test_role_and_status_filters(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher', [
            'name' => 'Pub Only',
            'email' => 'pub-only@example.com',
        ]);
        $unverified = $this->userWithRole('advertiser', [
            'name' => 'Unverified Buyer',
            'email' => 'unverified-buyer@example.com',
            'email_verified_at' => null,
        ]);
        $suspended = $this->userWithRole('advertiser', [
            'name' => 'Suspended Buyer',
            'email' => 'suspended-buyer@example.com',
            'suspended_at' => now(),
            'suspended_reason' => 'Chargebacks',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['role' => 'publisher']))
            ->assertOk()
            ->assertSee('pub-only@example.com')
            ->assertDontSee('unverified-buyer@example.com');

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['status' => 'unverified']))
            ->assertOk()
            ->assertSee('unverified-buyer@example.com')
            ->assertDontSee('pub-only@example.com');

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['status' => 'suspended']))
            ->assertOk()
            ->assertSee('suspended-buyer@example.com')
            ->assertSee('Suspended', false)
            ->assertDontSee('unverified-buyer@example.com');

        $this->assertNotNull($publisher->id);
        $this->assertNotNull($unverified->id);
        $this->assertNotNull($suspended->id);
    }

    public function test_joined_and_last_seen_date_filters(): void
    {
        $admin = $this->userWithRole('admin');
        $old = $this->userWithRole('advertiser', [
            'name' => 'Old Joiner',
            'email' => 'old.joiner@example.com',
            'created_at' => now()->subDays(40),
            'last_seen_at' => now()->subDays(30),
        ]);
        $recent = $this->userWithRole('advertiser', [
            'name' => 'Recent Joiner',
            'email' => 'recent.joiner@example.com',
            'created_at' => now()->subDay(),
            'last_seen_at' => now()->subHours(2),
        ]);

        $from = now()->subDays(7)->toDateString();

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['joined_from' => $from]))
            ->assertOk()
            ->assertSee('recent.joiner@example.com')
            ->assertDontSee('old.joiner@example.com')
            ->assertSee('Clear filters', false);

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['seen_from' => $from]))
            ->assertOk()
            ->assertSee('recent.joiner@example.com')
            ->assertDontSee('old.joiner@example.com');

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['joined_from' => 'not-a-date', 'seen_to' => '13/40/2026']))
            ->assertOk()
            ->assertSee('old.joiner@example.com')
            ->assertSee('recent.joiner@example.com');

        $csv = $this->actingAs($admin)
            ->get(route('admin.users.export', ['joined_from' => $from]))
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString('recent.joiner@example.com', $csv);
        $this->assertStringNotContainsString('old.joiner@example.com', $csv);
        $this->assertSame($from, data_get(
            ActivityLog::query()->where('action', 'user.exported')->latest('id')->first()?->properties,
            'joined_from'
        ));

        $this->assertNotNull($old->id);
        $this->assertNotNull($recent->id);
    }

    public function test_users_page_uses_same_origin_ajax_urls(): void
    {
        $html = $this->actingAs($this->userWithRole('admin'))
            ->get(route('admin.users.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('ROLE_UPDATE_URL = "\/admin\/users\/', $html);
        $this->assertStringNotContainsString(
            'ROLE_UPDATE_URL = "'.str_replace('/', '\/', rtrim((string) config('app.url'), '/').'/admin/users/'),
            $html
        );
    }

    public function test_profile_shows_360_and_accepts_note(): void
    {
        $admin = $this->userWithRole('admin', ['name' => 'Admin Operator']);
        $member = $this->userWithRole('advertiser', [
            'name' => 'Profile Subject',
            'email' => 'profile.subject@example.com',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.users.show', $member))
            ->assertOk()
            ->assertSee('Profile Subject')
            ->assertSee('profile.subject@example.com')
            ->assertSee('Internal notes')
            ->assertDontSee('Mark email verified')
            ->assertSee('Suspend account')
            ->assertSee('Finance dossier');

        $this->actingAs($admin)
            ->from(route('admin.users.show', $member))
            ->post(route('admin.users.notes.store', $member), [
                'body' => 'Called about a missing invoice.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('user_admin_notes', [
            'user_id' => $member->id,
            'admin_id' => $admin->id,
            'body' => 'Called about a missing invoice.',
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'user.note_added',
            'subject_id' => $member->id,
        ]);
    }

    public function test_admin_can_force_verify_and_suspend(): void
    {
        $admin = $this->userWithRole('admin');
        $member = $this->userWithRole('advertiser', [
            'name' => 'Lock Me',
            'email' => 'lock.me@example.com',
            'email_verified_at' => null,
        ]);

        $this->actingAs($admin)
            ->from(route('admin.users.show', $member))
            ->post(route('admin.users.verify-email', $member))
            ->assertRedirect();

        $this->assertNotNull($member->fresh()->email_verified_at);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'user.email_verified',
            'subject_id' => $member->id,
        ]);

        $this->actingAs($admin)
            ->from(route('admin.users.show', $member))
            ->post(route('admin.users.suspend', $member), [
                'reason' => 'Repeated chargebacks on deposits.',
            ])
            ->assertRedirect();

        $member = $member->fresh();
        $this->assertTrue($member->isSuspended());
        $this->assertSame('Repeated chargebacks on deposits.', $member->suspended_reason);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'user.suspended',
            'subject_id' => $member->id,
        ]);

        $this->actingAs($admin)
            ->from(route('admin.users.show', $member))
            ->post(route('admin.users.unsuspend', $member))
            ->assertRedirect();

        $this->assertFalse($member->fresh()->isSuspended());
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'user.unsuspended',
            'subject_id' => $member->id,
        ]);
    }

    public function test_cannot_suspend_self_or_another_admin(): void
    {
        $admin = $this->userWithRole('admin', [
            'name' => 'First Admin',
            'email' => 'first.admin@example.com',
        ]);
        $otherAdmin = $this->userWithRole('admin', [
            'name' => 'Second Admin',
            'email' => 'second.admin@example.com',
        ]);

        $this->actingAs($admin)
            ->from(route('admin.users.show', $admin))
            ->post(route('admin.users.suspend', $admin), [
                'reason' => 'Should not work on myself.',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->actingAs($admin)
            ->from(route('admin.users.show', $otherAdmin))
            ->post(route('admin.users.suspend', $otherAdmin), [
                'reason' => 'Should not lock the other admin.',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertFalse($admin->fresh()->isSuspended());
        $this->assertFalse($otherAdmin->fresh()->isSuspended());
        $this->assertSame(0, ActivityLog::where('action', 'user.suspended')->count());
    }

    public function test_suspended_user_cannot_login_or_use_session(): void
    {
        $advertiser = $this->userWithRole('advertiser', [
            'email' => 'locked.out@example.com',
            'password' => 'password',
            'suspended_at' => now(),
            'suspended_reason' => 'Fraud',
        ]);

        $this->postJson(route('login.post'), [
            'email' => $advertiser->email,
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', UserMessages::get('login.suspended'));

        $this->assertGuest();

        $this->actingAs($advertiser)
            ->get(route('advertiser.dashboard'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }
}
