<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\VisitorChatEmbed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TawkChatWidgetTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $name): User
    {
        $role = Role::firstOrCreate(['name' => $name]);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    public function test_public_home_embeds_tawk_and_hides_help_fab_when_configured(): void
    {
        config([
            'services.support_chat.enabled' => false,
            'services.tawk.property_id' => '6aa6a3693d02a53444168308',
            'services.tawk.widget_id' => 'default',
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('https://embed.tawk.to/6aa6a3693d02a53444168308/default', false)
            ->assertSee('Tawk_API', false)
            ->assertSee('Tawk_API.onLoad', false)
            ->assertSee('Tawk_API.minimize', false)
            ->assertSee('slbPinTawk', false)
            ->assertSee("classList.toggle('tawk-open'", false)
            ->assertDontSee('slb-tawk-launcher', false)
            ->assertDontSee('aria-label="Open customer support"', false)
            ->assertSee("setProperty('top', 'auto', 'important')", false)
            ->assertSee("removeProperty('max-width')", false)
            ->assertSee('slbTawkKeepOpen = false', false)
            ->assertDontSee('aria-label="Open help and feedback"', false)
            ->assertSee('slbOpenSupport', false)
            ->assertDontSee("helpFeedbackToggle')?.click()", false)
            ->assertDontSee('SQLSTATE', false);
    }

    public function test_public_home_keeps_help_fab_when_tawk_is_off(): void
    {
        config([
            'services.support_chat.enabled' => false,
            'services.tawk.property_id' => '',
            'services.tawk.widget_id' => '',
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('aria-label="Open help and feedback"', false)
            ->assertDontSee('embed.tawk.to', false);
    }

    public function test_public_and_portal_layouts_include_tawk_partial(): void
    {
        foreach ([
            resource_path('views/layouts/app.blade.php'),
            resource_path('views/advertiser/layouts/app.blade.php'),
            resource_path('views/publisher/layouts/app.blade.php'),
        ] as $path) {
            $layout = (string) file_get_contents($path);
            $this->assertStringContainsString('partials.tawk', $layout);
            $this->assertStringContainsString('TawkChat::enabled()', $layout);
            $this->assertStringContainsString('class_exists(\\App\\Support\\VisitorSupportChat::class)', $layout);
            $this->assertStringContainsString('class_exists(\\App\\Support\\TawkChat::class)', $layout);
            $this->assertStringContainsString("view()->exists('partials.tawk')", $layout);
        }

        $tawk = (string) file_get_contents(resource_path('views/partials/tawk.blade.php'));
        $this->assertStringContainsString('class_exists(\\App\\Support\\VisitorSupportChat::class)', $tawk);
        $this->assertStringContainsString('class_exists(\\App\\Support\\TawkChat::class)', $tawk);
        $this->assertStringContainsString("view()->exists('partials.visitor-support-chat')", $tawk);

        $admin = (string) file_get_contents(resource_path('views/admin/layouts/app.blade.php'));
        $this->assertStringNotContainsString('partials.tawk', $admin);
    }

    public function test_advertiser_and_publisher_dashboards_embed_tawk_when_configured(): void
    {
        config([
            'services.support_chat.enabled' => false,
            'services.tawk.property_id' => '6aa6a3693d02a53444168308',
            'services.tawk.widget_id' => 'default',
        ]);

        $advertiser = $this->userWithRole('advertiser');
        $this->actingAs($advertiser)
            ->get(route('advertiser.dashboard'))
            ->assertOk()
            ->assertSee('https://embed.tawk.to/6aa6a3693d02a53444168308/default', false)
            ->assertSee('Tawk_API.visitor', false)
            ->assertSee($advertiser->email, false)
            ->assertSee('slbOpenSupport', false)
            ->assertSee('Tawk_API.onLoad', false)
            ->assertSee('Tawk_API.minimize', false)
            ->assertSee('slbTawkKeepOpen', false)
            ->assertSee('slbPinTawk', false)
            ->assertDontSee('slb-tawk-launcher', false)
            ->assertDontSee('aria-label="Open customer support"', false)
            ->assertDontSee('aria-label="Open help and feedback"', false);

        $publisher = $this->userWithRole('publisher');
        $this->actingAs($publisher)
            ->get(route('publisher.dashboard'))
            ->assertOk()
            ->assertSee('https://embed.tawk.to/6aa6a3693d02a53444168308/default', false)
            ->assertSee('Tawk_API.visitor', false)
            ->assertSee($publisher->email, false)
            ->assertSee('Tawk_API.onLoad', false)
            ->assertSee('Tawk_API.minimize', false)
            ->assertSee('slbPinTawk', false)
            ->assertDontSee('slb-tawk-launcher', false)
            ->assertDontSee('aria-label="Open help and feedback"', false);
    }

    public function test_app_shell_keeps_tawk_out_of_the_page_flex_column(): void
    {
        $css = (string) file_get_contents(public_path('assets/css/app-shell.css'));
        $this->assertStringContainsString('iframe[title="chat widget"]', $css);
        $this->assertStringContainsString('iframe[src*="tawk.to"]', $css);
        $this->assertStringContainsString('flex: 0 0 auto', $css);
        $this->assertStringContainsString('top: auto !important', $css);
        $this->assertStringContainsString('left: auto !important', $css);
        $this->assertStringContainsString('right: 16px !important', $css);
        $this->assertStringContainsString('bottom: 20px !important', $css);
        $this->assertStringNotContainsString('.slb-tawk-launcher', $css);
        $this->assertStringNotContainsString('html:not(.tawk-open) iframe[title="chat widget"]', $css);
        $this->assertStringContainsString('html.tawk-open iframe[title="chat widget"]', $css);
        $this->assertStringContainsString('div:has(> iframe[title="chat widget"])', $css);
        $this->assertStringNotContainsString('div:has( iframe[src*="tawk.to"])', $css);
    }

    public function test_leftover_html_without_tawk_include_gets_the_widget(): void
    {
        config([
            'services.support_chat.enabled' => false,
            'services.tawk.property_id' => '6aa6a3693d02a53444168308',
            'services.tawk.widget_id' => 'default',
        ]);

        $html = '<html><body><div class="help-fab" id="helpFeedbackWidget"></div></body></html>';
        $out = VisitorChatEmbed::inject($html);

        $this->assertStringContainsString('https://embed.tawk.to/6aa6a3693d02a53444168308/default', $out);
        $this->assertStringContainsString('id="slb-visitor-chat-overflow"', $out);
        $this->assertStringContainsString('.help-fab { display: none !important; }', $out);
        $this->assertStringContainsString('overflow-x: clip !important', $out);
        $this->assertSame($out, VisitorChatEmbed::inject($out));
    }

    public function test_leftover_html_gets_first_party_chat_when_enabled(): void
    {
        config([
            'services.support_chat.enabled' => true,
            'services.tawk.property_id' => '6aa6a3693d02a53444168308',
            'services.tawk.widget_id' => 'default',
        ]);

        $html = '<html><body><p>Home</p></body></html>';
        $out = VisitorChatEmbed::inject($html);

        $this->assertStringContainsString('id="slbLiveChat"', $out);
        $this->assertStringContainsString('aria-label="Open live chat"', $out);
        $this->assertStringNotContainsString('embed.tawk.to', $out);
    }
}
