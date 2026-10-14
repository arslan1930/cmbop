<?php

namespace Tests\Unit;

use Tests\TestCase;

class ProductionSqlGuardTest extends TestCase
{
    public function test_listing_currency_sql_creates_new_wallet_unique_before_dropping_old(): void
    {
        $sql = (string) file_get_contents(base_path('database/sql/add_publisher_listing_currency.sql'));

        $addPos = strpos($sql, 'wallets_user_id_role_id_currency_unique');
        $dropPos = strpos($sql, 'DROP INDEX `wallets_user_id_role_id_unique`');

        $this->assertNotFalse($addPos);
        $this->assertNotFalse($dropPos);
        $this->assertLessThan($dropPos, $addPos, 'New unique index must be created before the old one is dropped (#1553).');
        $this->assertStringContainsString('ADD COLUMN `currency`', $sql);
        $this->assertStringContainsString('information_schema.COLUMNS', $sql);
    }

    public function test_listing_currency_sql_guards_withdrawals_wallet_id_update(): void
    {
        $sql = (string) file_get_contents(base_path('database/sql/add_publisher_listing_currency.sql'));

        $this->assertStringContainsString("COLUMN_NAME = 'wallet_id'", $sql);
        $this->assertStringContainsString('leaving currency default EUR', $sql);
        $this->assertDoesNotMatchRegularExpression(
            '/^UPDATE `withdrawals` w\s+LEFT JOIN `wallets` wa ON w\.wallet_id/m',
            $sql
        );
    }

    public function test_listing_currency_sql_guards_backfills(): void
    {
        $sql = (string) file_get_contents(base_path('database/sql/add_publisher_listing_currency.sql'));

        $this->assertStringContainsString('skip sites.listing_price backfill', $sql);
        $this->assertStringContainsString('skip order_items FX backfill', $sql);
        $this->assertStringContainsString('wallets.currency missing — skip backfill', $sql);
    }

    public function test_deposit_bank_tracking_sql_guards_charge_currency_updates(): void
    {
        $sql = (string) file_get_contents(base_path('database/sql/add_deposit_bank_tracking.sql'));

        $this->assertStringContainsString("COLUMN_NAME IN ('bank_account', 'charge_currency', 'payment_method')", $sql);
        $this->assertStringContainsString("COLUMN_NAME IN ('payment_reference', 'charge_currency', 'payment_method')", $sql);
        $this->assertStringContainsString('skip bank_account backfill', $sql);
        $this->assertStringContainsString('skip payment_reference backfill', $sql);
        $this->assertStringNotContainsString(
            "UPDATE `deposit_requests`\nSET `bank_account` = CASE",
            $sql
        );
    }
}
