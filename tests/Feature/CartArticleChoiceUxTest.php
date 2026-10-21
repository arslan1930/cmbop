<?php

namespace Tests\Feature;

use App\Http\Controllers\Advertiser\GuestPostWizardController;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\WritingSetting;
use App\Services\Marketplace\CheckoutAddOnService;
use App\Services\Marketplace\WritingFeeService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartArticleChoiceUxTest extends TestCase
{
    use RefreshDatabase;

    private User $advertiser;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);

        $advertiserRole = Role::where('name', 'advertiser')->firstOrFail();
        $publisherRole = Role::where('name', 'publisher')->firstOrFail();

        $this->advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiserRole->id,
        ]);
        $this->advertiser->roles()->attach($advertiserRole->id);

        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $this->publisher->roles()->attach($publisherRole->id);
    }

    private function makeSite(string $slug, array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Choice '.$slug,
            'site_url' => 'https://'.$slug.'.example',
            'domain' => $slug.'.example',
            'da' => 40,
            'dr' => 45,
            'traffic' => 8000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 100,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Cart article choice listing.',
            'verified' => true,
            'active' => true,
            'writing_enabled' => false,
        ], $overrides));
    }

    public function test_cart_copy_is_neutral_and_hides_partner_rates(): void
    {
        $copy = app(CheckoutAddOnService::class)->cartArticleCopy();

        $this->assertSame("Choose how you'll get the article", $copy['heading']);
        $this->assertSame('Have the article written', $copy['write_label']);
        $this->assertSame('Upload article', $copy['upload_label']);
        $this->assertSame('Have all articles written', $copy['write_all']);
        $this->assertSame('12-month link guarantee available at checkout', $copy['guarantee_note']);
        $this->assertSame('Choose an article option above to continue', $copy['proceed_hint']);
        $this->assertSame('We write → you approve → publisher publishes', $copy['write_steps']);
        $this->assertSame('You upload → we approve → publisher publishes', $copy['upload_steps']);
        $this->assertSame('2–3 working days', $copy['turnaround']);
        $this->assertStringContainsString('SEOLinkBuildings team', $copy['write_help']);
        $this->assertStringContainsString('sent to you for approval', $copy['write_help']);
        $this->assertStringNotContainsString('word', strtolower($copy['write_help']));
        $this->assertStringNotContainsString('partner', strtolower(implode(' ', $copy)));
        $this->assertStringNotContainsString('markup', strtolower(implode(' ', $copy)));
        $this->assertStringNotContainsString('listing_writing_fee', implode(' ', $copy));
    }

    public function test_cart_payload_includes_with_writing_total_and_guarantee_flag(): void
    {
        $site = $this->makeSite('preview-total');

        $payload = $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.add'), [
                'id' => $site->id,
                'homepage_days' => 'none',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('article_copy.heading', "Choose how you'll get the article")
            ->assertJsonPath('article_copy.proceed_hint', 'Choose an article option above to continue')
            ->json();

        $line = $payload['cart'][0];
        $this->assertEquals(WritingFeeService::SLB_FLAT_EUR, (float) $line['writing_fee']);
        $this->assertEquals(
            round((float) $line['price'] + (float) $line['writing_fee'], 2),
            (float) $line['writing_total']
        );
        $this->assertNotSame('', (string) $line['writing_total_label']);
        $this->assertTrue((bool) $line['guarantee_offered']);
        $this->assertSame('', (string) ($line['article_choice'] ?? ''));
        $this->assertArrayNotHasKey('listing_writing_fee', $line);
        $this->assertArrayNotHasKey('publisher_price', $line);
        $this->assertArrayNotHasKey('partner_rate', $line);
        $this->assertArrayNotHasKey('platform_fee_amount', $line);
    }

    public function test_short_publication_hides_guarantee_flag(): void
    {
        $site = $this->makeSite('six-months', ['publication_time' => '6months']);

        $line = $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.add'), [
                'id' => $site->id,
                'homepage_days' => 'none',
            ])
            ->assertOk()
            ->json('cart.0');

        $this->assertFalse((bool) $line['guarantee_offered']);
    }

    public function test_bulk_write_orders_writing_on_unready_lines_only(): void
    {
        $first = $this->makeSite('bulk-a');
        $second = $this->makeSite('bulk-b', [
            'domain' => 'bulk-b.example',
            'site_url' => 'https://bulk-b.example',
        ]);

        $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.add'), ['id' => $first->id, 'homepage_days' => 'none'])
            ->assertOk();
        $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.add'), ['id' => $second->id, 'homepage_days' => 'none'])
            ->assertOk();

        $payload = $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.write-unready'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('written_count', 2)
            ->json();

        $this->assertCount(2, $payload['cart']);
        foreach ($payload['cart'] as $line) {
            $this->assertTrue(CheckoutAddOnService::isWrittenSource($line));
            $this->assertSame('written', $line['article_choice']);
            $this->assertArrayNotHasKey('listing_writing_fee', $line);
            $this->assertArrayNotHasKey('partner_rate', $line);
        }
    }

    public function test_admin_can_override_writing_turnaround_text(): void
    {
        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
        ]);
        $admin->roles()->attach($adminRole->id);

        $this->actingAs($admin)
            ->get(route('admin.writing-queue.index'))
            ->assertOk()
            ->assertSee('Cart writing turnaround', false)
            ->assertSee('2–3 working days', false);

        $this->actingAs($admin)
            ->post(route('admin.writing-queue.turnaround'), [
                'turnaround_text' => '4 working days',
            ])
            ->assertRedirect();

        $this->assertSame('4 working days', WritingSetting::turnaroundText());
        $this->assertSame('4 working days', app(CheckoutAddOnService::class)->cartArticleCopy()['turnaround']);

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('4 working days', $html);
        $this->assertStringNotContainsString('partner_rate', $html);
        $this->assertStringNotContainsString('listing_writing_fee', $html);
    }

    public function test_wizard_content_page_uses_equal_weight_article_options(): void
    {
        $site = $this->makeSite('wizard-choice');

        $html = $this->actingAs($this->advertiser)
            ->withSession([
                GuestPostWizardController::SESSION_KEY => [
                    'language' => 'en',
                    'categories' => [],
                ],
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'price' => 100,
                    'quantity' => 1,
                    'language' => 'en',
                    'country' => 'us',
                ]],
            ])
            ->get(route('advertiser.wizard.content'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString("Choose how you'll get the article", $html);
        $this->assertStringContainsString('Have the article written', $html);
        $this->assertStringContainsString('Upload article', $html);
        $this->assertStringContainsString('With writing:', $html);
        $this->assertStringContainsString('We write → you approve → publisher publishes', $html);
        $this->assertStringContainsString('12-month link guarantee available at checkout', $html);
        $this->assertStringContainsString('Choose an article option above to continue', $html);
        $this->assertStringContainsString('Typical turnaround: 2–3 working days', $html);
        $this->assertStringNotContainsString('1 article still needed', $html);
        $this->assertStringNotContainsString('partner_rate', $html);
        $this->assertStringNotContainsString('listing_writing_fee', $html);

        $writePos = strpos($html, 'Have the article written');
        $uploadPos = strpos($html, 'cart-choice-option wizard-upload-cta');
        $this->assertNotFalse($writePos);
        $this->assertNotFalse($uploadPos);
        $this->assertLessThan($uploadPos, $writePos);
    }

    public function test_hostinger_sql_creates_writing_settings(): void
    {
        $sql = file_get_contents(base_path('database/sql/add_writing_settings.sql'));

        $this->assertIsString($sql);
        $this->assertStringStartsWith('USE u841082722_cmbop_db;', trim($sql));
        $this->assertStringContainsString('writing_settings', $sql);
        $this->assertStringContainsString('information_schema.TABLES', $sql);
    }
}
