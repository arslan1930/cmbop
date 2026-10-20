USE u841082722_cmbop_db;

-- Partner API gaps (webhook CRUD, live-link check storage, sandbox isolation).
-- phpMyAdmin / Hostinger: safe to re-run. Laravel twin:
-- database/migrations/2026_10_20_041500_add_partner_api_sandbox_and_link_check.php
-- Migrations are not auto-run on Hostinger. Do not run this against production
-- from the cloud agent — owner applies it in phpMyAdmin.

SET NAMES utf8mb4;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'is_sandbox'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `orders` ADD COLUMN `is_sandbox` TINYINT(1) NOT NULL DEFAULT 0, ADD INDEX `orders_is_sandbox_index` (`is_sandbox`)',
  'SELECT ''orders.is_sandbox already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'is_sandbox'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `order_items` ADD COLUMN `is_sandbox` TINYINT(1) NOT NULL DEFAULT 0, ADD INDEX `order_items_is_sandbox_index` (`is_sandbox`)',
  'SELECT ''order_items.is_sandbox already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'live_url_href_found'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `order_items` ADD COLUMN `live_url_href_found` TINYINT(1) NULL DEFAULT NULL',
  'SELECT ''order_items.live_url_href_found already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'live_url_rel'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `order_items` ADD COLUMN `live_url_rel` VARCHAR(64) NULL DEFAULT NULL',
  'SELECT ''order_items.live_url_rel already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'live_url_anchor_match'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `order_items` ADD COLUMN `live_url_anchor_match` TINYINT(1) NULL DEFAULT NULL',
  'SELECT ''order_items.live_url_anchor_match already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'partner_webhook_events' AND COLUMN_NAME = 'livemode'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `partner_webhook_events` ADD COLUMN `livemode` TINYINT(1) NOT NULL DEFAULT 1',
  'SELECT ''partner_webhook_events.livemode already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
