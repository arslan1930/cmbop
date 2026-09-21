<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Advertiser\AdvertiserProjectCheckout;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class AdvertiserProjectsCheckoutLeftoverTest extends TestCase
{
    use CreatesContentSubmissions;
    use RefreshDatabase;

    private User $advertiser;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);

        $advRole = Role::where('name', 'advertiser')->firstOrFail();
        $this->advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advRole->id,
        ]);
        $this->advertiser->roles()->attach($advRole->id);

        $pubRole = Role::where('name', 'publisher')->firstOrFail();
        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $pubRole->id,
        ]);
        $this->publisher->roles()->attach($pubRole->id);
    }

    private function site(array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Leftover Project Site',
            'site_url' => 'https://leftover-project-site.example',
            'domain' => 'leftover-project-site.example',
            'da' => 30,
            'dr' => 30,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 40,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Leftover project checkout site',
            'verified' => true,
            'active' => true,
        ], $overrides));
    }

    public function test_projects_page_survives_missing_projects_table(): void
    {
        Schema::dropIfExists('projects');

        $this->actingAs($this->advertiser)
            ->get(route('advertiser.projects.index'))
            ->assertOk()
            ->assertSee('Projects', false)
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('Something went wrong');
    }

    public function test_projects_page_survives_leftover_unparseable_created_at(): void
    {
        $project = Project::create([
            'user_id' => $this->advertiser->id,
            'project_name' => 'Leftover Clock Client',
            'project_url' => 'https://leftover-clock.example',
        ]);
        DB::table('projects')->where('id', $project->id)->update([
            'created_at' => 'not-a-date',
            'updated_at' => '???',
        ]);

        $this->actingAs($this->advertiser)
            ->get(route('advertiser.projects.index'))
            ->assertOk()
            ->assertSee('Leftover Clock Client', false)
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('not-a-date');
    }

    public function test_store_flashes_when_projects_table_is_gone(): void
    {
        Schema::dropIfExists('projects');

        $this->actingAs($this->advertiser)
            ->from(route('advertiser.catalog'))
            ->post(route('advertiser.projects.store'), [
                'project_name' => 'Ghost Client',
                'project_url' => 'https://ghost-client.example',
            ])
            ->assertRedirect(route('advertiser.catalog'))
            ->assertSessionHas('error')
            ->assertDontSee('SQLSTATE');
    }

    public function test_checkout_survives_missing_projects_table(): void
    {
        config(['content_moderation.enabled' => false]);

        $site = $this->site();
        $sub = $this->createApprovedSubmission($this->advertiser, null, 0, 'tools', 'https://acme.example/new');
        Schema::dropIfExists('projects');

        $this->actingAs($this->advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'content_submission_id' => $sub->id,
                    'language' => 'en',
                ]],
            ])
            ->get(route('advertiser.checkout'))
            ->assertOk()
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('Something went wrong');
    }

    public function test_checkout_survives_leftover_mixed_cart_and_junk_project_id(): void
    {
        config(['content_moderation.enabled' => false]);

        $site = $this->site();
        $owned = Project::create([
            'user_id' => $this->advertiser->id,
            'project_name' => 'Owned Client One',
            'project_url' => 'https://owned-one.example',
        ]);
        $sub = $this->createApprovedSubmission($this->advertiser, null, 0, 'tools', 'https://acme.example/new');

        $html = $this->actingAs($this->advertiser)
            ->withSession([
                'cart' => [
                    null,
                    '???',
                    [
                        'id' => $site->id,
                        'name' => $site->site_name,
                        'quantity' => 1,
                        'content_submission_id' => $sub->id,
                        'language' => 'en',
                    ],
                ],
                'checkout_project_id' => ['not-json'],
            ])
            ->get(route('advertiser.checkout'))
            ->assertOk()
            ->assertDontSee('SQLSTATE')
            ->getContent();

        $this->assertStringContainsString('Assign to a project', $html);
        $this->assertStringNotContainsString('selected>Owned Client One', $html);

        $resolved = app(AdvertiserProjectCheckout::class)->resolveId(
            (int) $this->advertiser->id,
            ['not-json'],
            ['https://acme.example/new']
        );
        $this->assertNotSame($owned->id, $resolved);
    }

    public function test_orders_list_survives_missing_projects_table(): void
    {
        Schema::dropIfExists('projects');

        $this->actingAs($this->advertiser)
            ->getJson(route('advertiser.orders.list', ['project' => 1]))
            ->assertOk()
            ->assertJsonMissingPath('exception')
            ->assertDontSee('SQLSTATE');
    }
}
