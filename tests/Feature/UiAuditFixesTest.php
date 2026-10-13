<?php

namespace Tests\Feature;

use App\Mail\ContactFormMail;
use App\Models\Role;
use App\Models\User;
use App\Services\Marketing\GuestPostPriceIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class UiAuditFixesTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $role): User
    {
        $roleModel = Role::firstOrCreate(['name' => $role]);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $roleModel->id,
        ]);
        $user->roles()->attach($roleModel->id);

        return $user->fresh();
    }

    public function test_homepage_search_posts_to_marketplace_without_advertiser_catalog(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('slb-hero-search', $html);
        $this->assertMatchesRegularExpression('/<form[^>]+class="slb-hero-search"[^>]+action="[^"]*marketplace/', $html);
        $this->assertStringNotContainsString('advertiser/catalog', $html);
        $this->assertStringNotContainsString('235vw', $html);
    }

    public function test_french_homepage_uses_localized_catalog_preview(): void
    {
        $html = $this->get('/fr')->assertOk()->getContent();

        $this->assertStringContainsString('paris**.fr', $html);
        $this->assertStringContainsString('France', $html);
        $this->assertStringContainsString('Ajouter au panier', $html);
        $this->assertStringNotContainsString('berlin**.de', $html);
    }

    public function test_advertiser_sidebar_includes_api_and_hamburger(): void
    {
        $html = $this->actingAs($this->userWithRole('advertiser'))
            ->get(route('advertiser.dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('API &amp; MCP', $html);
        $this->assertStringContainsString('id="toggleSidebar"', $html);
        $this->assertStringContainsString('id="sidebarOverlay"', $html);
        $this->assertStringContainsString('<h1', $html);
    }

    public function test_profile_uses_advertiser_shell_and_title(): void
    {
        $html = $this->actingAs($this->userWithRole('advertiser'))
            ->get(route('profile'))
            ->assertOk()
            ->assertSee('Profile', false)
            ->getContent();

        $this->assertStringContainsString('id="sidebar"', $html);
        $this->assertStringContainsString('<h1', $html);
        $this->assertStringContainsString('>Profile<', $html);
    }

    public function test_contact_form_sends_mail_and_ignores_honeypot(): void
    {
        Mail::fake();

        $this->from('/contact')
            ->post(route('contact.store'), [
                'name' => 'Ada Lovelace',
                'email' => 'ada@example.com',
                'message' => 'Please tell me about guest posts in France.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        Mail::assertQueued(ContactFormMail::class, 1);

        Mail::fake();

        $this->from('/contact')
            ->post(route('contact.store'), [
                'name' => 'Bot',
                'email' => 'bot@example.com',
                'message' => 'This should be ignored by the honeypot.',
                'website' => 'https://spam.example',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        Mail::assertNothingQueued();
    }

    public function test_contact_page_has_form_and_contrast_email_link(): void
    {
        $html = $this->get('/contact')->assertOk()->getContent();

        $this->assertStringContainsString('name="name"', $html);
        $this->assertStringContainsString('name="email"', $html);
        $this->assertStringContainsString('name="message"', $html);
        $this->assertStringContainsString('name="website"', $html);
        $this->assertStringContainsString('color:#1a585e', $html);
        $this->assertStringNotContainsString('style="color:#5bc4c7; text-decoration:none; font-weight:500;"', $html);
    }

    public function test_add_funds_hides_card_when_stripe_is_offline(): void
    {
        config(['services.stripe.secret' => null, 'services.stripe.key' => null]);

        $html = $this->actingAs($this->userWithRole('advertiser'))
            ->get(route('advertiser.add-funds'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('data-method="card"', $html);
        $this->assertStringNotContainsString('id="savedCardsSection"', $html);
        $this->assertStringNotContainsString('id="cardPaymentDetails"', $html);
        $this->assertStringContainsString('<h1', $html);
    }

    public function test_catalog_uses_short_placeholder_and_merged_bulk_search(): void
    {
        $html = $this->actingAs($this->userWithRole('advertiser'))
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Name, domain…', $html);
        $this->assertStringNotContainsString('Name, domain, category… or da>40', $html);
        $this->assertStringContainsString('Applies as you type', $html);
        $this->assertStringContainsString('<h1', $html);
    }

    public function test_content_library_hides_bulk_delete_until_selection(): void
    {
        $html = $this->actingAs($this->userWithRole('advertiser'))
            ->get(route('advertiser.content-library'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('library-bulk-bar d-none', $html);
        $this->assertStringContainsString('Delete selected', $html);
        $this->assertStringContainsString('<h1', $html);
    }

    public function test_projects_and_claims_have_empty_states_and_h1(): void
    {
        $advertiser = $this->userWithRole('advertiser');

        $projects = $this->actingAs($advertiser)
            ->get(route('advertiser.projects.index'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('No projects yet', $projects);
        $this->assertStringContainsString('<h1', $projects);

        $publisher = $this->userWithRole('publisher');
        $publisher->roles()->attach(Role::firstOrCreate(['name' => 'advertiser'])->id);
        $publisher->forceFill(['active_role_id' => Role::where('name', 'advertiser')->value('id')])->save();

        $claims = $this->actingAs($publisher->fresh())
            ->get(route('advertiser.site-claims'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('No ownership claims yet', $claims);
        $this->assertStringContainsString('<h1', $claims);
    }

    public function test_reports_has_search_export_and_invoice_date_copy(): void
    {
        $html = $this->actingAs($this->userWithRole('advertiser'))
            ->get(route('advertiser.reports'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="repSearch"', $html);
        $this->assertStringContainsString('reports/export', $html);
        $this->assertStringContainsString('invoice date', $html);
        $this->assertStringContainsString('display_date', $html);
        $this->assertStringContainsString('<h1', $html);
    }

    public function test_spending_explains_monthly_budget(): void
    {
        $html = $this->actingAs($this->userWithRole('advertiser'))
            ->get(route('advertiser.analytics'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Optional cap for this calendar month', $html);
        $this->assertStringContainsString('<h1', $html);
    }

    public function test_pricing_page_renders_from_table(): void
    {
        $html = $this->get('/pricing')->assertOk()->getContent();

        $this->assertStringContainsString('Marketplace prices from live catalog listings', $html);
    }

    public function test_price_index_includes_from_amount(): void
    {
        $snapshot = app(GuestPostPriceIndex::class)->snapshot();

        $this->assertArrayHasKey('countries', $snapshot);
        foreach ($snapshot['countries'] as $row) {
            $this->assertArrayHasKey('from', $row);
        }
    }

    public function test_chat_css_uses_safe_area_and_footer_offset(): void
    {
        $css = (string) file_get_contents(public_path('assets/css/visitor-support-chat.css'));
        $shell = (string) file_get_contents(public_path('assets/css/app-shell.css'));

        $this->assertStringContainsString('safe-area-inset-bottom', $css);
        $this->assertStringContainsString('--slb-live-chat-footer-offset', $css);
        $this->assertStringContainsString('--slb-live-chat-footer-offset', $shell);
        $this->assertStringContainsString('safe-area-inset-bottom', $shell);
        $this->assertStringContainsString('--shell-footer-height', $shell);
    }

    public function test_upload_button_is_brand_teal(): void
    {
        $css = (string) file_get_contents(public_path('assets/css/brand-colors.css'));

        $this->assertStringContainsString('--bs-btn-bg: var(--brand-primary, #1a585e)', $css);
        $this->assertStringNotContainsString('--bs-btn-bg: #2563eb', $css);
    }

    public function test_orders_filter_is_apply_not_contradictory_filter(): void
    {
        $html = $this->actingAs($this->userWithRole('advertiser'))
            ->get(route('advertiser.orders'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="ordersApplyFiltersBtn"', $html);
        $this->assertStringContainsString('Results update as you type.', $html);
        $this->assertStringContainsString('<h1', $html);
    }
}
