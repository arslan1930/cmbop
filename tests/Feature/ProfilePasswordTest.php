<?php

namespace Tests\Feature;

use App\Mail\PasswordChangedMail;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ProfilePasswordTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);

        $role = Role::where('name', 'advertiser')->firstOrFail();
        $this->user = User::factory()->create([
            'email_verified_at' => now(),
            'password' => 'password',
            'active_role_id' => $role->id,
        ]);
        $this->user->roles()->attach($role->id);
    }

    public function test_change_password_copy_is_not_a_red_error_on_load(): void
    {
        $html = $this->actingAs($this->user)
            ->get(route('profile'))
            ->assertOk()
            ->assertSee('At least 8 characters.', false)
            ->assertDontSee('Must be at least 8 characters', false)
            ->assertDontSee('validation-feedback', false)
            ->getContent();

        $this->assertSame(3, preg_match_all('/<button[^>]*\btoggle-password\b/', $html));
    }

    public function test_user_can_change_password_and_mail_is_queued(): void
    {
        Mail::fake();

        $this->actingAs($this->user)
            ->from(route('profile'))
            ->post(route('profile.password'), [
                'current_password' => 'password',
                'password' => 'newpass99',
                'password_confirmation' => 'newpass99',
            ])
            ->assertRedirect(route('profile'))
            ->assertSessionHas('success');

        $this->assertAuthenticatedAs($this->user);
        $this->get(route('profile'))
            ->assertOk()
            ->assertSee('Password changed successfully', false);

        $this->assertTrue(Hash::check('newpass99', $this->user->fresh()->password));
        Mail::assertQueued(PasswordChangedMail::class, 1);

        $this->post(route('logout'));
        $this->postJson(route('login.post'), [
            'email' => $this->user->email,
            'password' => 'newpass99',
        ])->assertOk()->assertJsonPath('status', 'success');
    }

    public function test_wrong_current_password_does_not_send_mail(): void
    {
        Mail::fake();

        $this->actingAs($this->user)
            ->from(route('profile'))
            ->post(route('profile.password'), [
                'current_password' => 'wrong-password',
                'password' => 'newpass99',
                'password_confirmation' => 'newpass99',
            ])
            ->assertRedirect(route('profile'))
            ->assertSessionHas('error');

        $this->assertTrue(Hash::check('password', $this->user->fresh()->password));
        Mail::assertNothingQueued();
    }

    public function test_short_new_password_is_rejected_without_mail(): void
    {
        Mail::fake();

        $this->actingAs($this->user)
            ->from(route('profile'))
            ->post(route('profile.password'), [
                'current_password' => 'password',
                'password' => 'short',
                'password_confirmation' => 'short',
            ])
            ->assertRedirect(route('profile'))
            ->assertSessionHasErrors('password');

        Mail::assertNothingQueued();
    }

    public function test_mismatched_confirmation_is_rejected_without_mail(): void
    {
        Mail::fake();

        $this->actingAs($this->user)
            ->from(route('profile'))
            ->post(route('profile.password'), [
                'current_password' => 'password',
                'password' => 'newpass99',
                'password_confirmation' => 'otherpass99',
            ])
            ->assertRedirect(route('profile'))
            ->assertSessionHasErrors('password');

        Mail::assertNothingQueued();
    }
}
