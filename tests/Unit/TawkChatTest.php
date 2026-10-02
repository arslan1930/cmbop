<?php

namespace Tests\Unit;

use App\Support\TawkChat;
use App\Support\VisitorSupportChat;
use Tests\TestCase;

class TawkChatTest extends TestCase
{
    public function test_embed_src_requires_valid_property_and_widget(): void
    {
        config([
            'services.tawk.property_id' => '6aa6a3693d02a53444168308',
            'services.tawk.widget_id' => 'default',
        ]);

        $this->assertTrue(TawkChat::enabled());
        $this->assertSame(
            'https://embed.tawk.to/6aa6a3693d02a53444168308/default',
            TawkChat::embedSrc()
        );
    }

    public function test_rejects_empty_or_unsafe_ids(): void
    {
        config(['services.tawk.property_id' => '', 'services.tawk.widget_id' => 'default']);
        $this->assertNull(TawkChat::embedSrc());

        config([
            'services.tawk.property_id' => '6aa6a3693d02a53444168308',
            'services.tawk.widget_id' => '../evil',
        ]);
        $this->assertNull(TawkChat::embedSrc());

        config([
            'services.tawk.property_id' => 'not-a-tawk-id',
            'services.tawk.widget_id' => 'default',
        ]);
        $this->assertNull(TawkChat::embedSrc());
    }

    public function test_missing_config_key_does_not_invent_a_property_id(): void
    {
        config(['services.tawk' => []]);

        $php = (string) file_get_contents(app_path('Support/TawkChat.php'));
        $this->assertStringNotContainsString('6aa6a3693d02a53444168308', $php);

        $config = (string) file_get_contents(config_path('services.php'));
        $this->assertStringNotContainsString("'TAWK_PROPERTY_ID', '6aa6a3693d02a53444168308'", $config);

        if (trim((string) env('TAWK_PROPERTY_ID', '')) === '') {
            $this->assertNull(TawkChat::embedSrc());
            $this->assertFalse(TawkChat::enabled());
        }
    }

    public function test_predefined_messages_follow_role_questions(): void
    {
        $this->assertSame(
            VisitorSupportChat::questionsForRole('guest'),
            TawkChat::predefinedMessages('guest')
        );
        $this->assertContains('How do I place an order?', TawkChat::predefinedMessages('advertiser'));
        $this->assertContains('How do I add a website?', TawkChat::predefinedMessages('publisher'));
    }
}
