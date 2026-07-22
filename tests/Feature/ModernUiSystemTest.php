<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModernUiSystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_design_system_assets_exist(): void
    {
        foreach ([
            'css/brand-colors.css',
            'css/app-shell.css',
            'css/interaction.css',
            'css/chat.css',
            'css/cart.css',
        ] as $asset) {
            $this->assertFileExists(public_path($asset), "Missing {$asset}");
        }

        $shell = file_get_contents(public_path('css/app-shell.css'));
        $this->assertStringContainsString('--hover-tint', $shell);
        $this->assertStringContainsString('brand-primary-bg', $shell);
        $this->assertStringNotContainsString('background-color: #5bc4c7', $shell);

        $brand = file_get_contents(public_path('css/brand-colors.css'));
        $this->assertStringContainsString('--surface-1', $brand);
        $this->assertStringContainsString('--motion-fast', $brand);
        $this->assertStringContainsString('--bs-code-color: #185054', $brand);
        $this->assertStringContainsString('--brand-primary: #185054', $brand);
        $this->assertStringContainsString('--brand-warning-bg: #fffbeb', $brand);
        $this->assertStringContainsString('--brand-warning: #b45309', $brand);
    }

    public function test_homepage_loads_with_interaction_css(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('css/interaction.css', false)
            ->assertSee('css/brand-colors.css', false);
    }

    public function test_chat_partial_exists(): void
    {
        $this->assertFileExists(resource_path('views/partials/order-chat-modal.blade.php'));
        $html = file_get_contents(resource_path('views/partials/order-chat-modal.blade.php'));
        $this->assertStringContainsString('chat-modal', $html);
        $this->assertStringContainsString('chatMessages', $html);
    }
}
