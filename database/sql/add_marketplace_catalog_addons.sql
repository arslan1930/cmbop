USE u841082722_cmbop_db;

-- Hostinger phpMyAdmin / mysql client. Safe to re-run.
-- Catalog marketplace add-ons: 12-month link guarantee, writing fee,
-- publisher reliability cache, and saved searches.
-- Equivalent migration: 2026_10_15_120000_add_marketplace_catalog_addons

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- orders: link guarantee
-- ---------------------------------------------------------------------------
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'link_guarantee'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `orders` ADD COLUMN `link_guarantee` TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT ''orders.link_guarantee already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'link_guarantee_fee'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `orders` ADD COLUMN `link_guarantee_fee` DECIMAL(10,2) NOT NULL DEFAULT 0',
  'SELECT ''orders.link_guarantee_fee already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'link_guarantee_covered_amount'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `orders` ADD COLUMN `link_guarantee_covered_amount` DECIMAL(10,2) NOT NULL DEFAULT 0',
  'SELECT ''orders.link_guarantee_covered_amount already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'link_guarantee_status'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `orders` ADD COLUMN `link_guarantee_status` VARCHAR(32) NULL DEFAULT NULL',
  'SELECT ''orders.link_guarantee_status already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'link_guarantee_starts_at'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `orders` ADD COLUMN `link_guarantee_starts_at` TIMESTAMP NULL DEFAULT NULL',
  'SELECT ''orders.link_guarantee_starts_at already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'link_guarantee_ends_at'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `orders` ADD COLUMN `link_guarantee_ends_at` TIMESTAMP NULL DEFAULT NULL',
  'SELECT ''orders.link_guarantee_ends_at already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'link_guarantee_restore_due_at'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `orders` ADD COLUMN `link_guarantee_restore_due_at` TIMESTAMP NULL DEFAULT NULL',
  'SELECT ''orders.link_guarantee_restore_due_at already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'link_guarantee_refunded_at'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `orders` ADD COLUMN `link_guarantee_refunded_at` TIMESTAMP NULL DEFAULT NULL',
  'SELECT ''orders.link_guarantee_refunded_at already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- order_items: writing option
-- ---------------------------------------------------------------------------
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'content_source'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `order_items` ADD COLUMN `content_source` VARCHAR(20) NOT NULL DEFAULT ''buyer''',
  'SELECT ''order_items.content_source already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'writing_fee'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `order_items` ADD COLUMN `writing_fee` DECIMAL(10,2) NOT NULL DEFAULT 0',
  'SELECT ''order_items.writing_fee already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'listing_writing_fee'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `order_items` ADD COLUMN `listing_writing_fee` DECIMAL(12,2) NULL DEFAULT NULL',
  'SELECT ''order_items.listing_writing_fee already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- sites: writing fee
-- ---------------------------------------------------------------------------
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sites' AND COLUMN_NAME = 'writing_enabled'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `sites` ADD COLUMN `writing_enabled` TINYINT(1) NOT NULL DEFAULT 1',
  'SELECT ''sites.writing_enabled already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sites' AND COLUMN_NAME = 'writing_fee'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `sites` ADD COLUMN `writing_fee` DECIMAL(10,2) NULL DEFAULT NULL',
  'SELECT ''sites.writing_fee already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sites' AND COLUMN_NAME = 'listing_writing_fee'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `sites` ADD COLUMN `listing_writing_fee` DECIMAL(12,2) NULL DEFAULT NULL',
  'SELECT ''sites.listing_writing_fee already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- link_checks
-- ---------------------------------------------------------------------------
SET @tbl_exists := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'link_checks'
);
SET @sql := IF(@tbl_exists = 0,
  'CREATE TABLE `link_checks` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `order_id` bigint unsigned NOT NULL,
    `order_item_id` bigint unsigned NOT NULL,
    `checked_at` timestamp NOT NULL,
    `status` varchar(32) NOT NULL,
    `http_code` smallint unsigned DEFAULT NULL,
    `href_found` tinyint(1) DEFAULT NULL,
    `notes` text DEFAULT NULL,
    `restore_due_at` timestamp NULL DEFAULT NULL,
    `created_at` timestamp NULL DEFAULT NULL,
    `updated_at` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `link_checks_order_id_index` (`order_id`),
    KEY `link_checks_order_item_id_index` (`order_item_id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
  'SELECT ''link_checks already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- saved_searches
-- ---------------------------------------------------------------------------
SET @tbl_exists := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'saved_searches'
);
SET @sql := IF(@tbl_exists = 0,
  'CREATE TABLE `saved_searches` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `user_id` bigint unsigned NOT NULL,
    `name` varchar(120) NOT NULL,
    `query_json` json NOT NULL,
    `notify` tinyint(1) NOT NULL DEFAULT 0,
    `last_alerted_at` timestamp NULL DEFAULT NULL,
    `last_seen_max_id` bigint unsigned DEFAULT NULL,
    `created_at` timestamp NULL DEFAULT NULL,
    `updated_at` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `saved_searches_user_id_index` (`user_id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
  'SELECT ''saved_searches already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- publisher_reliability_stats
-- ---------------------------------------------------------------------------
SET @tbl_exists := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'publisher_reliability_stats'
);
SET @sql := IF(@tbl_exists = 0,
  'CREATE TABLE `publisher_reliability_stats` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `publisher_id` bigint unsigned NOT NULL,
    `sample_size` int unsigned NOT NULL DEFAULT 0,
    `completed_orders` int unsigned NOT NULL DEFAULT 0,
    `response_rate` decimal(5,2) DEFAULT NULL,
    `avg_response_hours` decimal(8,2) DEFAULT NULL,
    `publication_rate` decimal(5,2) DEFAULT NULL,
    `avg_delivery_hours` decimal(8,2) DEFAULT NULL,
    `badge_eligible` tinyint(1) NOT NULL DEFAULT 0,
    `computed_at` timestamp NULL DEFAULT NULL,
    `created_at` timestamp NULL DEFAULT NULL,
    `updated_at` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `publisher_reliability_stats_publisher_id_unique` (`publisher_id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
  'SELECT ''publisher_reliability_stats already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
