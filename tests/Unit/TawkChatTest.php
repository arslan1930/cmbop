<?php

namespace Tests\Unit;

use App\Support\TawkChat;
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

    public function test_missing_config_key_falls_back_to_env_default(): void
    {
        config(['services.tawk' => []]);

        $this->assertTrue(TawkChat::enabled());
        $this->assertSame(
            'https://embed.tawk.to/6aa6a3693d02a53444168308/default',
            TawkChat::embedSrc()
        );
    }
}
