<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\PostalCode;
use App\Support\SignupCountry;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileBillingValidationTest extends TestCase
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
            'active_role_id' => $role->id,
            'billing_name' => 'Jane Advertiser',
            'company_name' => 'Acme SEO Ltd',
            'city' => 'London',
            'address' => '1 Test Street',
        ]);
        $this->user->roles()->attach($role->id);
    }

    public function test_profile_billing_form_uses_signup_country_dropdown(): void
    {
        $html = $this->actingAs($this->user)
            ->get(route('profile'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="billing_country"', $html);
        $this->assertStringContainsString('<select name="country"', $html);
        $this->assertStringContainsString('value="uk"', $html);
        $this->assertStringContainsString('United Kingdom', $html);
        $this->assertStringContainsString('id="billing_postal_code"', $html);
        $this->assertStringContainsString(PostalCode::MESSAGE, $html);
        $this->assertStringNotContainsString('Postal code can only contain numbers.', $html);
    }

    public function test_uk_postal_code_is_accepted_and_uppercased(): void
    {
        $this->actingAs($this->user)
            ->from(route('profile'))
            ->post(route('profile.billing'), [
                'billing_name' => 'Jane Advertiser',
                'country' => 'uk',
                'city' => 'London',
                'address' => '10 Downing Street',
                'postal_code' => 'sw1a 0aa',
            ])
            ->assertRedirect(route('profile'))
            ->assertSessionHas('success')
            ->assertSessionHasNoErrors();

        $fresh = $this->user->fresh();
        $this->assertSame('uk', $fresh->country);
        $this->assertSame('SW1A 0AA', $fresh->postal_code);
    }

    public function test_numeric_and_hyphenated_postal_codes_are_accepted(): void
    {
        $this->actingAs($this->user)
            ->post(route('profile.billing'), [
                'country' => 'us',
                'postal_code' => '78701',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('78701', $this->user->fresh()->postal_code);

        $this->actingAs($this->user)
            ->post(route('profile.billing'), [
                'country' => 'ca',
                'postal_code' => 'K1A-0B1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('K1A-0B1', $this->user->fresh()->postal_code);
    }

    public function test_invalid_postal_code_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->from(route('profile'))
            ->post(route('profile.billing'), [
                'country' => 'uk',
                'postal_code' => 'A',
            ])
            ->assertRedirect(route('profile'))
            ->assertSessionHasErrors('postal_code');

        $this->assertStringContainsString(
            'letters, numbers, spaces and hyphens',
            session('errors')->first('postal_code')
        );
        $this->assertStringNotContainsString(
            'can only contain numbers',
            session('errors')->first('postal_code')
        );

        $this->actingAs($this->user)
            ->post(route('profile.billing'), [
                'country' => 'uk',
                'postal_code' => 'SW1A 0AA!!!!',
            ])
            ->assertSessionHasErrors('postal_code');
    }

    public function test_unknown_country_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->post(route('profile.billing'), [
                'country' => 'zz',
                'postal_code' => 'SW1A 0AA',
            ])
            ->assertSessionHasErrors('country');

        $this->assertContains('uk', SignupCountry::codes());
    }

    public function test_add_funds_billing_accepts_uk_postal_code(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('advertiser.save-billing-info'), [
                'billing_name' => 'Jane Advertiser',
                'company_name' => 'Acme SEO Ltd',
                'country' => 'GB',
                'city' => 'London',
                'address' => '10 Downing Street',
                'postal_code' => 'SW1A 0AA',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $fresh = $this->user->fresh();
        $this->assertSame('uk', $fresh->country);
        $this->assertSame('SW1A 0AA', $fresh->postal_code);
    }

    public function test_add_funds_billing_rejects_digits_only_error_path(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('advertiser.save-billing-info'), [
                'billing_name' => 'Jane Advertiser',
                'company_name' => 'Acme SEO Ltd',
                'country' => 'uk',
                'city' => 'London',
                'address' => '10 Downing Street',
                'postal_code' => '!',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $message = (string) $this->postJson(route('advertiser.save-billing-info'), [
            'billing_name' => 'Jane Advertiser',
            'company_name' => 'Acme SEO Ltd',
            'country' => 'uk',
            'city' => 'London',
            'address' => '10 Downing Street',
            'postal_code' => '!',
        ])->json('message');

        $this->assertStringContainsString('letters, numbers, spaces and hyphens', $message);
        $this->assertStringNotContainsString('can only contain numbers', $message);
    }
}
