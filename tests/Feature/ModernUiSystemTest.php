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
            'assets/css/brand-colors.css',
            'assets/css/app-shell.css',
            'assets/css/interaction.css',
            'assets/css/chat.css',
            'assets/css/cart.css',
        ] as $asset) {
            $this->assertFileExists(public_path($asset), "Missing {$asset}");
        }

        $shell = file_get_contents(public_path('assets/css/app-shell.css'));
        $this->assertStringContainsString('--hover-tint', $shell);
        $this->assertStringContainsString('brand-primary-bg', $shell);
        $this->assertStringNotContainsString('background-color: #5bc4c7', $shell);

        $brand = file_get_contents(public_path('assets/css/brand-colors.css'));
        $this->assertStringContainsString('--surface-1', $brand);
        $this->assertStringContainsString('--motion-fast', $brand);
        $this->assertStringContainsString('--bs-code-color: #1a585e', $brand);
        $this->assertStringContainsString('--brand-primary: #1a585e', $brand);
        $this->assertStringContainsString('--brand-warning-bg: #fff7ed', $brand);
        // Warning is amber now; sharing the danger red made a caution read
        // as a destructive action.
        $this->assertStringContainsString('--brand-warning: #b45309', $brand);
        $this->assertStringNotContainsString('--brand-warning: #dc2626', $brand);
        $this->assertStringContainsString('.btn-upload', $brand);
        $this->assertStringContainsString('.btn-upload__icon', $brand);
        $this->assertStringContainsString('.btn-upload:focus-visible', $brand);
        $this->assertStringNotContainsString('.btn-upload:focus {', $brand);
        $this->assertStringContainsString('.upload-zone', $brand);
        $this->assertStringContainsString('#eff6ff', $brand);
        $this->assertStringContainsString('#2563eb', $brand);
    }

    public function test_homepage_loads_with_interaction_css(): void
    {
        $html = $this->get('/')
            ->assertOk()
            ->getContent();

        $this->assertTrue(
            str_contains($html, 'css/marketing-bundle.css')
            || (str_contains($html, 'css/interaction.css') && str_contains($html, 'css/brand-colors.css')),
            'Homepage must load interaction + brand colors, either bundled or as separate sheets.'
        );
    }

    public function test_chat_partial_exists(): void
    {
        $this->assertFileExists(resource_path('views/partials/order-chat-modal.blade.php'));
        $html = file_get_contents(resource_path('views/partials/order-chat-modal.blade.php'));
        $this->assertStringContainsString('chat-modal', $html);
        $this->assertStringContainsString('chatMessages', $html);
    }
}
