<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * My Sites embeds old_text() in the add/edit form. If the helper is not loaded
 * (stale composer classmap after deploy), the page 500s before any site row
 * can render.
 */
class PublisherMySitesOldTextTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_text_is_available_after_app_boot(): void
    {
        $this->assertTrue(function_exists('old_text'));
        $this->assertSame('', old_text('siteName'));
    }

    public function test_publisher_my_sites_page_renders_with_old_text_fields(): void
    {
        $role = Role::firstOrCreate(['name' => 'publisher']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        $this->actingAs($user)
            ->get(route('publisher.websites'))
            ->assertOk()
            ->assertSee('name="siteName"', false)
            ->assertDontSee('Call to undefined function old_text', false);
    }

    public function test_my_sites_survives_array_old_input(): void
    {
        $role = Role::firstOrCreate(['name' => 'publisher']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        $this->actingAs($user)
            ->withSession([
                '_old_input' => [
                    'siteName' => ['Poisoned Name'],
                    'siteUrl' => ['https://poisoned-url.example'],
                    'exampleUrl' => ['https://poisoned-url.example/post'],
                    'da' => ['40'],
                    'dr' => ['41'],
                    'traffic' => ['15000'],
                    'price' => [['99']],
                    'country' => ['de'],
                    'language' => ['de'],
                    'categories' => [1, ['News']],
                    'siteDescription' => ['<p>Poisoned description</p>'],
                    'price_homepage' => ['7' => ['25']],
                    'price_sensitive' => ['crypto' => ['15']],
                    'sites' => [
                        'not-a-row',
                        ['url' => ['https://bulk-one.example'], 'price' => [['15']]],
                        ['url' => ['https://bulk-two.example'], 'price' => ['20']],
                    ],
                ],
            ])
            ->get(route('publisher.websites'))
            ->assertOk()
            ->assertSee('value="Poisoned Name"', false)
            ->assertSee('value="https://poisoned-url.example"', false)
            ->assertSee('value="https://bulk-one.example"', false)
            ->assertSee('value="https://bulk-two.example"', false)
            ->assertDontSee('htmlspecialchars(): Argument #1', false)
            ->assertDontSee('TypeError', false);
    }

    public function test_scalar_text_flattens_arrays(): void
    {
        $this->assertTrue(function_exists('scalar_text'));
        $this->assertSame('de', scalar_text(['de']));
        $this->assertSame('pending', scalar_text([['pending']]));
        $this->assertSame('', scalar_text([]));
        $this->assertSame('ok', scalar_text('ok'));
        $this->assertTrue(function_exists('csv_text'));
        $this->assertSame('de,fr', csv_text(['de', 'fr']));
        $this->assertSame('de,us', csv_text([['de'], 'us']));
        $this->assertSame('de,us', csv_text('de,us'));
    }

    public function test_my_sites_ajax_survives_array_query_and_status(): void
    {
        $role = Role::firstOrCreate(['name' => 'publisher']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        $html = $this->actingAs($user)
            ->get(route('publisher.sites.ajax', [
                'status' => ['pending'],
                'query' => ['poisoned-search'],
                'page' => ['2'],
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Array to string conversion', $html);
        $this->assertStringNotContainsString('Could not load your sites', $html);
    }
}
