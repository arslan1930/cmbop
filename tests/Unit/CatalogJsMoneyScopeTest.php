<?php

namespace Tests\Unit;

use Tests\TestCase;

class CatalogJsMoneyScopeTest extends TestCase
{
    public function test_money_label_is_file_scope_for_expand_price_sync(): void
    {
        $js = (string) file_get_contents(public_path('assets/js/catalog.js'));

        $this->assertMatchesRegularExpression(
            '/^function catalogMoneyLabel\(/m',
            $js
        );
        $this->assertStringContainsString('function syncSensitiveSelectionUi(', $js);
        $this->assertStringContainsString('function syncDefaultHomepagePrices(', $js);
        $this->assertStringContainsString('catalogMoneyLabel(payTotal)', $js);
        $this->assertStringContainsString("typeof syncDefaultHomepagePrices === 'function'", $js);
        $this->assertSame(1, preg_match_all('/function catalogMoneyLabel\(/', $js));
    }
}
