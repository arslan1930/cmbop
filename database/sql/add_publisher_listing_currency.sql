-- Production helper (Hostinger / phpMyAdmin): publisher listing currency,
-- order FX snapshots, per-currency publisher wallets, withdrawal currency,
-- and USD withdrawal conversion_fee columns.
-- Safe to re-run. Existing UK sites stay EUR until the publisher confirms GBP.

-- sites.listing_currency
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sites' AND COLUMN_NAME = 'listing_currency'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `sites` ADD COLUMN `listing_currency` CHAR(3) NOT NULL DEFAULT ''EUR''',
  'SELECT ''sites.listing_currency already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- sites.listing_price
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sites' AND COLUMN_NAME = 'listing_price'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `sites` ADD COLUMN `listing_price` DECIMAL(10,2) NULL DEFAULT NULL',
  'SELECT ''sites.listing_price already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- sites.listing_sensitive_prices
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sites' AND COLUMN_NAME = 'listing_sensitive_prices'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `sites` ADD COLUMN `listing_sensitive_prices` JSON NULL',
  'SELECT ''sites.listing_sensitive_prices already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- sites.listing_homepage_prices
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sites' AND COLUMN_NAME = 'listing_homepage_prices'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `sites` ADD COLUMN `listing_homepage_prices` JSON NULL',
  'SELECT ''sites.listing_homepage_prices already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- sites.listing_gbp_prompt_dismissed_at
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sites' AND COLUMN_NAME = 'listing_gbp_prompt_dismissed_at'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `sites` ADD COLUMN `listing_gbp_prompt_dismissed_at` TIMESTAMP NULL DEFAULT NULL',
  'SELECT ''sites.listing_gbp_prompt_dismissed_at already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @can_price := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sites'
    AND COLUMN_NAME IN ('listing_price', 'listing_currency', 'price')
);
SET @sql := IF(@can_price = 3,
  'UPDATE `sites` SET `listing_price` = `price`, `listing_currency` = ''EUR'' WHERE `listing_price` IS NULL',
  'SELECT ''skip sites.listing_price backfill — missing columns'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @can_sensitive := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sites'
    AND COLUMN_NAME IN ('listing_sensitive_prices', 'sensitive_prices')
);
SET @sql := IF(@can_sensitive = 2,
  'UPDATE `sites` SET `listing_sensitive_prices` = `sensitive_prices` WHERE `listing_sensitive_prices` IS NULL AND `sensitive_prices` IS NOT NULL',
  'SELECT ''skip sites.listing_sensitive_prices backfill — missing columns'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @can_homepage := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sites'
    AND COLUMN_NAME IN ('listing_homepage_prices', 'homepage_placement_prices')
);
SET @sql := IF(@can_homepage = 2,
  'UPDATE `sites` SET `listing_homepage_prices` = `homepage_placement_prices` WHERE `listing_homepage_prices` IS NULL AND `homepage_placement_prices` IS NOT NULL',
  'SELECT ''skip sites.listing_homepage_prices backfill — missing columns'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- order_items.advertiser_eur_amount
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'advertiser_eur_amount'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `order_items` ADD COLUMN `advertiser_eur_amount` DECIMAL(12,2) NULL DEFAULT NULL',
  'SELECT ''order_items.advertiser_eur_amount already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- order_items.publisher_amount
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'publisher_amount'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `order_items` ADD COLUMN `publisher_amount` DECIMAL(12,2) NULL DEFAULT NULL',
  'SELECT ''order_items.publisher_amount already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- order_items.publisher_currency
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'publisher_currency'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `order_items` ADD COLUMN `publisher_currency` CHAR(3) NULL DEFAULT NULL',
  'SELECT ''order_items.publisher_currency already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- order_items.fx_rate
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'fx_rate'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `order_items` ADD COLUMN `fx_rate` DECIMAL(16,8) NULL DEFAULT NULL',
  'SELECT ''order_items.fx_rate already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- order_items.fx_buffer_percent
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'fx_buffer_percent'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `order_items` ADD COLUMN `fx_buffer_percent` DECIMAL(5,2) NULL DEFAULT NULL',
  'SELECT ''order_items.fx_buffer_percent already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @oi_full := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items'
    AND COLUMN_NAME IN ('advertiser_eur_amount', 'publisher_amount', 'publisher_currency', 'price', 'publisher_price', 'additional_price', 'homepage_price')
);
SET @oi_min := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items'
    AND COLUMN_NAME IN ('advertiser_eur_amount', 'publisher_amount', 'publisher_currency', 'price')
);
SET @sql := IF(@oi_full = 7,
  'UPDATE `order_items` SET `advertiser_eur_amount` = `price`, `publisher_amount` = COALESCE(`publisher_price`, ROUND((`price` - COALESCE(`additional_price`, 0) - COALESCE(`homepage_price`, 0)) / 1.15, 2)) + COALESCE(`additional_price`, 0) + COALESCE(`homepage_price`, 0), `publisher_currency` = ''EUR'' WHERE `publisher_currency` IS NULL',
  IF(@oi_min = 4,
    'UPDATE `order_items` SET `advertiser_eur_amount` = `price`, `publisher_amount` = `price`, `publisher_currency` = ''EUR'' WHERE `publisher_currency` IS NULL',
    'SELECT ''skip order_items FX backfill — missing columns'' AS info'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- wallets.currency (needed before the unique index)
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wallets' AND COLUMN_NAME = 'currency'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `wallets` ADD COLUMN `currency` CHAR(3) NOT NULL DEFAULT ''EUR''',
  'SELECT ''wallets.currency already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wallets' AND COLUMN_NAME = 'currency'
);
SET @sql := IF(@col_exists > 0,
  'UPDATE `wallets` SET `currency` = ''EUR'' WHERE `currency` IS NULL OR `currency` = ''''',
  'SELECT ''wallets.currency missing — skip backfill'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Create the new unique FIRST so MySQL can use it as the FK-supporting
-- index, then drop the old (user_id, role_id) unique (#1553).
SET @idx := (
  SELECT INDEX_NAME FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wallets'
    AND INDEX_NAME = 'wallets_user_id_role_id_currency_unique'
  LIMIT 1
);
SET @sql := IF(@idx IS NULL,
  'ALTER TABLE `wallets` ADD UNIQUE `wallets_user_id_role_id_currency_unique` (`user_id`, `role_id`, `currency`)',
  'SELECT ''wallets_user_id_role_id_currency_unique already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (
  SELECT INDEX_NAME FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wallets'
    AND INDEX_NAME = 'wallets_user_id_role_id_unique'
  LIMIT 1
);
SET @sql := IF(@idx IS NOT NULL,
  'ALTER TABLE `wallets` DROP INDEX `wallets_user_id_role_id_unique`',
  'SELECT ''wallets_user_id_role_id_unique already dropped'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- withdrawals.currency
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'withdrawals' AND COLUMN_NAME = 'currency'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `withdrawals` ADD COLUMN `currency` CHAR(3) NOT NULL DEFAULT ''EUR''',
  'SELECT ''withdrawals.currency already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @wallet_id_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'withdrawals' AND COLUMN_NAME = 'wallet_id'
);
SET @sql := IF(@wallet_id_exists > 0,
  'UPDATE `withdrawals` w LEFT JOIN `wallets` wa ON w.wallet_id = wa.id SET w.currency = COALESCE(NULLIF(wa.currency, ''''), ''EUR'') WHERE w.currency IS NULL OR w.currency = '''' OR w.currency = ''EUR''',
  'SELECT ''withdrawals.wallet_id missing — leaving currency default EUR'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- withdrawals.conversion_fee (USD payout conversion; 0 for EUR/GBP)
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'withdrawals' AND COLUMN_NAME = 'conversion_fee'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `withdrawals` ADD COLUMN `conversion_fee` DECIMAL(10,2) NOT NULL DEFAULT 0',
  'SELECT ''withdrawals.conversion_fee already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- withdrawals.conversion_fee_percent
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'withdrawals' AND COLUMN_NAME = 'conversion_fee_percent'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `withdrawals` ADD COLUMN `conversion_fee_percent` DECIMAL(5,2) NOT NULL DEFAULT 0',
  'SELECT ''withdrawals.conversion_fee_percent already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
