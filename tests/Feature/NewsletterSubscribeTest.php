<?php

namespace Tests\Feature;

use App\Models\NewsletterSubscriber;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NewsletterSubscribeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('newsletter_subscribers');
        Schema::create('newsletter_subscribers', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('locale', 10)->default('en');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('consented_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('newsletter_subscribers');
        parent::tearDown();
    }

    public function test_newsletter_subscribe_persists_email(): void
    {
        $response = $this->postJson('/newsletter/subscribe', [
            'email' => 'reader@example.com',
            'newsletter_opt_in' => '1',
            'int_com_lang' => 'en',
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
            ]);

        $this->assertDatabaseHas('newsletter_subscribers', [
            'email' => 'reader@example.com',
        ]);
    }

    public function test_newsletter_subscribe_requires_consent(): void
    {
        $response = $this->postJson('/newsletter/subscribe', [
            'email' => 'reader@example.com',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
            ]);

        $this->assertSame(0, NewsletterSubscriber::count());
    }

    public function test_newsletter_resubscribe_is_idempotent(): void
    {
        NewsletterSubscriber::create([
            'email' => 'reader@example.com',
            'locale' => 'en',
            'consented_at' => now()->subDay(),
        ]);

        $response = $this->postJson('/newsletter/subscribe', [
            'email' => 'reader@example.com',
            'newsletter_opt_in' => '1',
            'int_com_lang' => 'en',
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
            ]);

        $this->assertSame(1, NewsletterSubscriber::count());
    }
}
