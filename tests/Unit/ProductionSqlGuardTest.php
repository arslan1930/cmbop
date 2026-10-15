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

    public function test_screenshot_path_sql_is_idempotent(): void
    {
        $sql = (string) file_get_contents(base_path('database/sql/add_sites_screenshot_path.sql'));

        $this->assertStringContainsString('information_schema.COLUMNS', $sql);
        $this->assertStringContainsString("COLUMN_NAME = 'screenshot_path'", $sql);
        $this->assertStringContainsString('ALTER TABLE `sites` ADD COLUMN `screenshot_path`', $sql);
        $this->assertStringContainsString("already exists'' AS info", $sql);
    }

    public function test_publication_time_sql_is_idempotent_and_adds_multi_year_terms(): void
    {
        $sql = (string) file_get_contents(base_path('database/sql/expand_sites_publication_time.sql'));

        $this->assertStringContainsString('information_schema.COLUMNS', $sql);
        $this->assertStringContainsString("COLUMN_NAME = 'publication_time'", $sql);
        $this->assertStringContainsString('2years', $sql);
        $this->assertStringContainsString('3years', $sql);
        $this->assertStringContainsString('PREPARE stmt FROM @sql', $sql);
        $this->assertStringContainsString('ENUM(', $sql);
        $this->assertStringContainsString('VARCHAR(20)', $sql);
        $this->assertStringContainsString('already includes 2years/3years', $sql);
        $this->assertStringContainsString('already wide enough', $sql);
    }

    public function test_users_is_test_sql_is_idempotent(): void
    {
        $sql = (string) file_get_contents(base_path('database/sql/add_users_is_test.sql'));

        $this->assertStringContainsString('information_schema.COLUMNS', $sql);
        $this->assertStringContainsString("COLUMN_NAME = 'is_test'", $sql);
        $this->assertStringContainsString('ALTER TABLE `users` ADD COLUMN `is_test`', $sql);
        $this->assertStringContainsString("already exists'' AS info", $sql);
    }
}
