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

UPDATE `sites` SET `listing_price` = `price`, `listing_currency` = 'EUR' WHERE `listing_price` IS NULL;
UPDATE `sites` SET `listing_sensitive_prices` = `sensitive_prices`
  WHERE `listing_sensitive_prices` IS NULL AND `sensitive_prices` IS NOT NULL;
UPDATE `sites` SET `listing_homepage_prices` = `homepage_placement_prices`
  WHERE `listing_homepage_prices` IS NULL AND `homepage_placement_prices` IS NOT NULL;

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

UPDATE `order_items`
SET
  `advertiser_eur_amount` = `price`,
  `publisher_amount` = COALESCE(`publisher_price`, ROUND((`price` - COALESCE(`additional_price`, 0) - COALESCE(`homepage_price`, 0)) / 1.15, 2))
    + COALESCE(`additional_price`, 0) + COALESCE(`homepage_price`, 0),
  `publisher_currency` = 'EUR'
WHERE `publisher_currency` IS NULL;

-- wallets unique (user_id, role_id, currency)
UPDATE `wallets` SET `currency` = 'EUR' WHERE `currency` IS NULL OR `currency` = '';

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

-- withdrawals.currency
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'withdrawals' AND COLUMN_NAME = 'currency'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `withdrawals` ADD COLUMN `currency` CHAR(3) NOT NULL DEFAULT ''EUR''',
  'SELECT ''withdrawals.currency already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE `withdrawals` w
LEFT JOIN `wallets` wa ON w.wallet_id = wa.id
SET w.currency = COALESCE(NULLIF(wa.currency, ''), 'EUR')
WHERE w.currency IS NULL OR w.currency = '' OR w.currency = 'EUR';

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
