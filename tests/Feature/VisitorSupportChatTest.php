<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\VisitorSupportChat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VisitorSupportChatTest extends TestCase
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

    public function test_public_home_renders_first_party_widget_and_skips_tawk(): void
    {
        config([
            'services.support_chat.enabled' => true,
            'services.tawk.property_id' => '6aa6a3693d02a53444168308',
            'services.tawk.widget_id' => 'default',
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('id="slbLiveChat"', false)
            ->assertSee('aria-label="Open live chat"', false)
            ->assertSee('Hi! 👋 How can we help you today?', false)
            ->assertSee('visitor-support-chat.js', false)
            ->assertSee(route('support.chat'), false)
            ->assertDontSee('embed.tawk.to', false)
            ->assertDontSee('aria-label="Open help and feedback"', false);

        $js = (string) file_get_contents(public_path('js/visitor-support-chat.js'));
        $this->assertStringContainsString('function sendChatMessage', $js);
        $this->assertStringContainsString('window.sendChatMessage', $js);
        $this->assertStringContainsString('localStorage', $js);
    }

    public function test_advertiser_dashboard_renders_first_party_widget(): void
    {
        config(['services.support_chat.enabled' => true]);

        $advertiser = $this->userWithRole('advertiser');
        $this->actingAs($advertiser)
            ->get(route('advertiser.dashboard'))
            ->assertOk()
            ->assertSee('id="slbLiveChat"', false)
            ->assertSee('aria-label="Open live chat"', false)
            ->assertDontSee('embed.tawk.to', false);
    }

    public function test_local_provider_returns_a_reply(): void
    {
        config([
            'services.support_chat.enabled' => true,
            'services.support_chat.provider' => 'local',
        ]);

        $this->postJson(route('support.chat'), [
            'message' => 'How do I add funds?',
        ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonFragment(['ok' => true]);

        $reply = $this->postJson(route('support.chat'), [
            'message' => 'Hello',
        ])->json('reply');

        $this->assertIsString($reply);
        $this->assertNotSame('', trim((string) $reply));
    }

    public function test_rejects_empty_and_disabled_chat(): void
    {
        config(['services.support_chat.enabled' => true]);
        $this->postJson(route('support.chat'), ['message' => '   '])
            ->assertStatus(422);

        config(['services.support_chat.enabled' => false]);
        $this->postJson(route('support.chat'), ['message' => 'Hello'])
            ->assertNotFound();
    }

    public function test_http_provider_uses_server_side_endpoint_without_exposing_the_key(): void
    {
        config([
            'services.support_chat.enabled' => true,
            'services.support_chat.provider' => 'http',
            'services.support_chat.endpoint' => 'https://chat.example.test/v1/reply',
            'services.support_chat.api_key' => 'secret-test-key',
        ]);

        Http::fake([
            'https://chat.example.test/v1/reply' => Http::response(['reply' => 'Happy to help with catalog filters.'], 200),
        ]);

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('secret-test-key', $html);

        $this->postJson(route('support.chat'), [
            'message' => 'How do filters work?',
            'history' => [
                ['role' => 'assistant', 'content' => 'Hi! 👋 How can we help you today?'],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('reply', 'Happy to help with catalog filters.');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://chat.example.test/v1/reply'
                && $request->hasHeader('Authorization', 'Bearer secret-test-key')
                && $request['message'] === 'How do filters work?';
        });
    }

    public function test_enabled_helper_reads_config(): void
    {
        config(['services.support_chat.enabled' => true]);
        $this->assertTrue(VisitorSupportChat::enabled());

        config(['services.support_chat.enabled' => false]);
        $this->assertFalse(VisitorSupportChat::enabled());
    }

    public function test_missing_config_key_does_not_assume_first_party_files(): void
    {
        config(['services.support_chat' => []]);
        $this->assertFalse(VisitorSupportChat::enabled());
    }
}
