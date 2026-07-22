<?php

namespace Tests\Feature;

use App\Http\Controllers\Advertiser\GuestPostWizardController;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CountriesTableSeeder;
use Database\Seeders\LanguagesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class GuestPostWizardTest extends TestCase
{
    use CreatesContentSubmissions;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CountriesTableSeeder::class);
        $this->seed(LanguagesTableSeeder::class);
    }

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach($role->id);
        $user->active_role_id = $role->id;
        $user->save();

        return $user->fresh();
    }

    private function publisher(): User
    {
        $role = Role::firstOrCreate(['name' => 'publisher']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function activeSite(User $publisher): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Wizard Site',
            'site_url' => 'https://wizard-site.example',
            'domain' => 'wizard-site.example',
            'da' => 30,
            'dr' => 40,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'Marketing, PR & Advertising',
            'price' => 40,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Test',
            'verified' => true,
            'active' => true,
        ]);
    }

    public function test_new_advertiser_dashboard_cta_opens_catalog_with_guided_secondary(): void
    {
        $advertiser = $this->advertiser();

        $this->actingAs($advertiser)
            ->get(route('advertiser.dashboard'))
            ->assertOk()
            ->assertSee('Browse catalog', false)
            ->assertSee(route('advertiser.catalog'), false)
            ->assertSee('Prefer a guided flow?', false)
            ->assertSee(route('advertiser.wizard.start'), false);
    }

    public function test_wizard_start_without_market_goes_to_market_step(): void
    {
        $advertiser = $this->advertiser();

        $this->actingAs($advertiser)
            ->get(route('advertiser.wizard.start'))
            ->assertRedirect(route('advertiser.wizard.market'));
    }

    public function test_wizard_start_with_cart_still_opens_publishers(): void
    {
        $advertiser = $this->advertiser();
        $site = $this->activeSite($this->publisher());
        $article = $this->createApprovedSubmission($advertiser, null, 0, 'anchor', 'https://example.com/a', 'us', 'en');

        // Incomplete cart — previously forced content step; must stay on publishers.
        $this->actingAs($advertiser)
            ->withSession([
                GuestPostWizardController::SESSION_KEY => [
                    'language' => 'en',
                    'categories' => [],
                    'country' => null,
                ],
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'price' => 46,
                    'quantity' => 1,
                    'language' => 'en',
                    'country' => 'us',
                ]],
            ])
            ->get(route('advertiser.wizard.start'))
            ->assertRedirect(route('advertiser.wizard.publishers'));

        // Fully assigned cart — still allow browsing publishers instead of forcing pay.
        $this->actingAs($advertiser)
            ->withSession([
                GuestPostWizardController::SESSION_KEY => [
                    'language' => 'en',
                    'categories' => [],
                ],
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'price' => 46,
                    'quantity' => 1,
                    'language' => 'en',
                    'country' => 'us',
                    'content_submission_id' => $article->id,
                ]],
            ])
            ->get(route('advertiser.wizard.start'))
            ->assertRedirect(route('advertiser.wizard.publishers'));
    }

    public function test_market_save_stores_session_and_opens_publishers(): void
    {
        $advertiser = $this->advertiser();

        $this->actingAs($advertiser)
            ->post(route('advertiser.wizard.market.save'), [
                'language' => 'en',
                'categories' => ['Marketing, PR & Advertising'],
                'country' => 'us',
            ])
            ->assertRedirect(route('advertiser.wizard.publishers'));

        $this->assertSame('en', session(GuestPostWizardController::SESSION_KEY)['language'] ?? null);
        $this->assertContains('Marketing, PR & Advertising', session(GuestPostWizardController::SESSION_KEY)['categories'] ?? []);

        $this->actingAs($advertiser)
            ->get(route('advertiser.wizard.publishers'))
            ->assertRedirect();

        $response = $this->actingAs($advertiser)->get(route('advertiser.wizard.publishers'));
        $response->assertRedirect();
        $target = (string) $response->headers->get('Location');
        $this->assertStringContainsString('wizard=1', $target);
        $this->assertStringContainsString('language=en', $target);
    }

    public function test_content_step_requires_cart(): void
    {
        $advertiser = $this->advertiser();

        $this->actingAs($advertiser)
            ->withSession([
                GuestPostWizardController::SESSION_KEY => [
                    'language' => 'en',
                    'categories' => [],
                    'country' => null,
                ],
            ])
            ->get(route('advertiser.wizard.content'))
            ->assertRedirect(route('advertiser.wizard.publishers'));
    }

    public function test_content_step_blocks_pay_until_articles_assigned(): void
    {
        $advertiser = $this->advertiser();
        $site = $this->activeSite($this->publisher());

        $html = $this->actingAs($advertiser)
            ->withSession([
                GuestPostWizardController::SESSION_KEY => [
                    'language' => 'en',
                    'categories' => [],
                    'country' => null,
                ],
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'price' => 46,
                    'quantity' => 1,
                    'language' => 'en',
                    'country' => 'us',
                ]],
            ])
            ->get(route('advertiser.wizard.content'))
            ->assertOk()
            ->assertSee('Write for me', false)
            ->assertSee('Coming later', false)
            ->assertSee('Needs article', false);

        $html->assertSee('disabled', false);

        $this->actingAs($advertiser)
            ->withSession([
                GuestPostWizardController::SESSION_KEY => [
                    'language' => 'en',
                    'categories' => [],
                ],
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'price' => 46,
                    'quantity' => 1,
                    'language' => 'en',
                    'country' => 'us',
                ]],
            ])
            ->get(route('advertiser.wizard.pay'))
            ->assertRedirect(route('advertiser.wizard.content'));
    }

    public function test_pay_redirects_to_checkout_when_cart_ready(): void
    {
        $advertiser = $this->advertiser();
        $site = $this->activeSite($this->publisher());
        $article = $this->createApprovedSubmission($advertiser, null, 0, 'anchor', 'https://example.com/a', 'us', 'en');

        $this->actingAs($advertiser)
            ->withSession([
                GuestPostWizardController::SESSION_KEY => [
                    'language' => 'en',
                    'categories' => [],
                ],
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'price' => 46,
                    'quantity' => 1,
                    'language' => 'en',
                    'country' => 'us',
                    'content_submission_id' => $article->id,
                ]],
            ])
            ->get(route('advertiser.wizard.pay'))
            ->assertRedirect(route('advertiser.checkout', ['wizard' => 1]));
    }

    public function test_catalog_and_library_still_work_without_wizard(): void
    {
        $advertiser = $this->advertiser();

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->assertSee('Guided', false);

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library'))
            ->assertOk()
            ->assertSee('Place a guest post', false);
    }

    public function test_publishers_without_market_redirects_to_market(): void
    {
        $advertiser = $this->advertiser();

        $this->actingAs($advertiser)
            ->get(route('advertiser.wizard.publishers'))
            ->assertRedirect(route('advertiser.wizard.market'));
    }
}
