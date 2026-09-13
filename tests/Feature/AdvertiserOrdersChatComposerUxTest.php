<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdvertiserOrdersChatComposerUxTest extends TestCase
{
    public function test_order_chat_js_has_emoticon_display_and_shortcuts(): void
    {
        $live = (string) file_get_contents(public_path('js/order-chat.js'));
        $assets = (string) file_get_contents(public_path('assets/js/order-chat.js'));

        foreach ([$live, $assets] as $js) {
            $this->assertStringContainsString('function formatChatMessageText', $js);
            $this->assertStringContainsString("[':(', '😞']", $js);
            $this->assertStringContainsString("[':)', '😊']", $js);
            $this->assertStringContainsString('formatChatMessageText(msg.message', $js);
            $this->assertStringContainsString('e.ctrlKey || e.metaKey', $js);
            $this->assertStringContainsString("e.key !== 'Escape'", $js);
            $this->assertStringContainsString('function focusChatComposer', $js);
            $this->assertStringContainsString('function chatModalIsOpen', $js);
            $this->assertStringContainsString("classList.contains('show')", $js);
            $this->assertStringContainsString('!incremental && self.currentOrderId', $js);
            $this->assertStringContainsString('chatMessageInput', $js);
            $this->assertStringContainsString('Enter / Shift+Enter stay as a new line', $js);
            $this->assertStringContainsString("replace(/\\r\\n|\\r|\\n/g, '<br>')", $js);
            $this->assertStringNotContainsString('emoji-mart', $js);
            $this->assertStringNotContainsString('data-emoji-picker', $js);
            $this->assertStringContainsString('document.body.appendChild(modalEl)', $js);
        }

        $this->assertSame($live, $assets);
    }

    public function test_chat_modal_hint_documents_newline_send_and_escape(): void
    {
        $blade = (string) file_get_contents(resource_path('views/partials/order-chat-modal.blade.php'));

        $this->assertStringContainsString('Enter for a new line', $blade);
        $this->assertStringContainsString('Ctrl+Enter or ⌘Enter to send', $blade);
        $this->assertStringContainsString('Esc to close', $blade);
        $this->assertStringNotContainsString('data-emoji-picker', $blade);
    }
}
