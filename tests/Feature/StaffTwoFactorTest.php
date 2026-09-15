<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\StaffTwoFactorService;
use App\Support\ProductionRepair;
use App\Support\Totp;
use App\Support\UserMessages;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StaffTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
    }

    public function test_advertiser_login_does_not_challenge(): void
    {
        $user = $this->userWithRole('advertiser');

        $this->postJson(route('login.post'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()->assertJsonPath('status', 'success');

        $this->assertAuthenticatedAs($user);
    }

    public function test_admin_without_two_factor_still_logs_in(): void
    {
        $admin = $this->userWithRole('admin');

        $this->postJson(route('login.post'), [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertOk()->assertJsonPath('status', 'success');

        $this->assertAuthenticatedAs($admin);
    }

    public function test_admin_with_two_factor_is_challenged_and_not_authenticated_yet(): void
    {
        $admin = $this->userWithRole('admin');
        $this->enableTwoFactor($admin, $secret);

        $this->postJson(route('login.post'), [
            'email' => $admin->email,
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('status', 'two_factor')
            ->assertJsonPath('redirect', route('login.two-factor', absolute: false));

        $this->assertGuest();
        $this->get(route('login.two-factor'))->assertOk()->assertSee('Authentication code');
    }

    public function test_valid_app_code_completes_staff_login(): void
    {
        $admin = $this->userWithRole('admin');
        $this->enableTwoFactor($admin, $secret);

        $this->postJson(route('login.post'), [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertJsonPath('status', 'two_factor');

        $code = Totp::at($secret, time());
        $this->post(route('login.two-factor.store'), ['code' => $code])
            ->assertRedirect(route('admin.dashboard', absolute: false));

        $this->assertAuthenticatedAs($admin);
    }

    public function test_backup_code_works_once(): void
    {
        $admin = $this->userWithRole('admin');
        $backup = [];
        $this->enableTwoFactor($admin, $secret, $backup);

        $this->postJson(route('login.post'), [
            'email' => $admin->email,
            'password' => 'password',
        ]);

        $this->post(route('login.two-factor.store'), ['code' => $backup[0]])
            ->assertRedirect(route('admin.dashboard', absolute: false));
        $this->assertAuthenticatedAs($admin);

        Auth::logout();
        $this->flushSession();

        $this->postJson(route('login.post'), [
            'email' => $admin->email,
            'password' => 'password',
        ]);
        $this->from(route('login.two-factor'))
            ->post(route('login.two-factor.store'), ['code' => $backup[0]])
            ->assertRedirect(route('login.two-factor'))
            ->assertSessionHas('error');
        $this->assertGuest();
    }

    public function test_wrong_code_does_not_log_in(): void
    {
        $admin = $this->userWithRole('admin');
        $this->enableTwoFactor($admin, $secret);

        $this->postJson(route('login.post'), [
            'email' => $admin->email,
            'password' => 'password',
        ]);

        $this->from(route('login.two-factor'))
            ->post(route('login.two-factor.store'), ['code' => '000000'])
            ->assertRedirect(route('login.two-factor'))
            ->assertSessionHas('error', UserMessages::get('login.two_factor_invalid'));

        $this->assertGuest();
    }

    public function test_advertiser_cannot_start_staff_two_factor(): void
    {
        $user = $this->userWithRole('advertiser');

        $this->actingAs($user)
            ->post(route('profile.two-factor.start'))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('profile'))
            ->assertOk()
            ->assertDontSee('id="staff-two-factor"', false);
    }

    public function test_admin_can_enable_two_factor_from_profile(): void
    {
        $admin = $this->userWithRole('admin');
        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Protect staff sign-in', false);

        $this->actingAs($admin)
            ->post(route('profile.two-factor.start'))
            ->assertRedirect();

        $row = app(StaffTwoFactorService::class)->record($admin);
        $this->assertNotNull($row?->secret);
        $this->assertNull($row->confirmed_at);

        $code = Totp::at($row->secret, time());
        $this->actingAs($admin)
            ->post(route('profile.two-factor.confirm'), ['code' => $code])
            ->assertRedirect(route('profile'))
            ->assertSessionHas('success')
            ->assertSessionHas('staff_2fa_recovery_codes');

        $this->assertTrue(app(StaffTwoFactorService::class)->isConfirmed($admin));
        $this->assertNotNull(ActivityLog::query()->where('action', 'staff_two_factor.enabled')->first());

        $this->actingAs($admin)
            ->withSession([StaffTwoFactorService::PASSED_KEY => $admin->id])
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('Protect staff sign-in', false);
    }

    public function test_marketing_login_is_challenged_when_two_factor_is_on(): void
    {
        $marketer = $this->userWithRole('marketing');
        $this->enableTwoFactor($marketer, $secret);

        $this->postJson(route('login.post'), [
            'email' => $marketer->email,
            'password' => 'password',
        ])->assertJsonPath('status', 'two_factor');

        $this->post(route('login.two-factor.store'), ['code' => Totp::at($secret, time())])
            ->assertRedirect(route('marketing.dashboard', absolute: false));
        $this->assertAuthenticatedAs($marketer);
    }

    public function test_admin_can_clear_another_staff_members_two_factor(): void
    {
        $admin = $this->userWithRole('admin');
        $other = $this->userWithRole('marketing', ['email' => 'mkt.2fa@example.com']);
        $this->enableTwoFactor($other, $secret);

        $this->actingAs($admin)
            ->from(route('admin.users.show', $other))
            ->post(route('admin.users.two-factor.clear', $other))
            ->assertRedirect(route('admin.users.show', $other))
            ->assertSessionHas('success');

        $this->assertFalse(app(StaffTwoFactorService::class)->isConfirmed($other->fresh()));
        $this->assertNotNull(ActivityLog::query()->where('action', 'staff_two_factor.cleared')->first());
    }

    public function test_admin_cannot_clear_own_two_factor_from_user_360(): void
    {
        $admin = $this->userWithRole('admin');
        $this->enableTwoFactor($admin, $secret);

        $this->actingAs($admin)
            ->withSession([StaffTwoFactorService::PASSED_KEY => $admin->id])
            ->from(route('admin.users.show', $admin))
            ->post(route('admin.users.two-factor.clear', $admin))
            ->assertRedirect(route('admin.users.show', $admin))
            ->assertSessionHas('error');

        $this->assertTrue(app(StaffTwoFactorService::class)->isConfirmed($admin));
    }

    public function test_staff_session_without_two_factor_pass_is_rejected(): void
    {
        $admin = $this->userWithRole('admin');
        $this->enableTwoFactor($admin, $secret);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_repair_creates_staff_two_factor_table_when_missing(): void
    {
        Schema::dropIfExists('staff_two_factor');
        $this->assertFalse(ProductionRepair::staffTwoFactorStorageReady());

        $notes = [];
        app(ProductionRepair::class)->ensureStaffTwoFactor($notes);

        $this->assertTrue(Schema::hasTable('staff_two_factor'));
        $this->assertTrue(collect($notes)->contains('staff two-factor table ready'));
    }

    /**
     * @param  list<string>  $backup
     */
    private function enableTwoFactor(User $user, ?string &$secret = null, array &$backup = []): void
    {
        $service = app(StaffTwoFactorService::class);
        $row = $service->startSetup($user);
        $secret = $row->secret;
        $backup = ['BACKUPCODE1'];
        $row->recovery_codes = array_map(fn (string $code) => Hash::make($code), $backup);
        $row->confirmed_at = now();
        $row->save();
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
