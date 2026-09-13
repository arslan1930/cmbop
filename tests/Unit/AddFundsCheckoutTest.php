<?php

namespace Tests\Unit;

use App\Support\AddFundsCheckout;
use Tests\TestCase;

class AddFundsCheckoutTest extends TestCase
{
    public function test_query_includes_from_needed_and_ceiled_amount(): void
    {
        $query = AddFundsCheckout::query(12.34, 'wise');

        $this->assertSame('checkout', $query['from']);
        $this->assertSame(12.34, $query['needed']);
        $this->assertSame(13, $query['amount']);
        $this->assertSame('wise', $query['method']);
    }

    public function test_query_floors_amount_at_ten_and_omits_empty_method(): void
    {
        $query = AddFundsCheckout::query(8.2);

        $this->assertSame('checkout', $query['from']);
        $this->assertSame(8.2, $query['needed']);
        $this->assertSame(10, $query['amount']);
        $this->assertArrayNotHasKey('method', $query);
    }

    public function test_query_keeps_exact_needed_when_already_an_integer(): void
    {
        $query = AddFundsCheckout::query(40);

        $this->assertSame(40.0, $query['needed']);
        $this->assertSame(40, $query['amount']);
    }
}
