USE u841082722_cmbop_db;

-- Advertiser ordering API (keys with scopes/test mode, webhooks, idempotency, sandbox).
-- phpMyAdmin / Hostinger: safe to re-run. Laravel twin:
-- database/migrations/2026_10_20_120000_add_advertiser_ordering_api_tables.php
-- Migrations are not auto-run on Hostinger. Do not run this against production
-- from the cloud agent — owner applies it in phpMyAdmin.

SET NAMES utf8mb4;

-- Existing advertiser_api_tokens: scopes + live/test mode + revoke.
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'advertiser_api_tokens' AND COLUMN_NAME = 'scopes'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `advertiser_api_tokens` ADD COLUMN `scopes` JSON NULL AFTER `token_hash`',
  'SELECT ''advertiser_api_tokens.scopes already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'advertiser_api_tokens' AND COLUMN_NAME = 'mode'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `advertiser_api_tokens` ADD COLUMN `mode` VARCHAR(8) NOT NULL DEFAULT ''live'' AFTER `scopes`',
  'SELECT ''advertiser_api_tokens.mode already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'advertiser_api_tokens' AND COLUMN_NAME = 'last_used_ip'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `advertiser_api_tokens` ADD COLUMN `last_used_ip` VARCHAR(45) NULL AFTER `last_used_at`',
  'SELECT ''advertiser_api_tokens.last_used_ip already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'advertiser_api_tokens' AND COLUMN_NAME = 'revoked_at'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `advertiser_api_tokens` ADD COLUMN `revoked_at` TIMESTAMP NULL AFTER `last_used_ip`',
  'SELECT ''advertiser_api_tokens.revoked_at already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Sandbox isolation (also added by add_partner_api_gaps.sql). Re-ensure so test keys fail closed only when missing.
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

CREATE TABLE IF NOT EXISTS `advertiser_webhook_endpoints` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `url` VARCHAR(2000) NOT NULL,
  `events` JSON NULL,
  `secret_encrypted` TEXT NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'enabled',
  `failure_streak` INT UNSIGNED NOT NULL DEFAULT 0,
  `disabled_at` TIMESTAMP NULL DEFAULT NULL,
  `description` VARCHAR(255) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `advertiser_webhook_endpoints_user_id_index` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `advertiser_webhook_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `public_id` VARCHAR(32) NOT NULL,
  `type` VARCHAR(80) NOT NULL,
  `payload` JSON NOT NULL,
  `livemode` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `advertiser_webhook_events_public_id_unique` (`public_id`),
  KEY `advertiser_webhook_events_user_id_type_index` (`user_id`, `type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `advertiser_webhook_deliveries` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_id` BIGINT UNSIGNED NOT NULL,
  `endpoint_id` BIGINT UNSIGNED NOT NULL,
  `attempt` INT UNSIGNED NOT NULL DEFAULT 0,
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `response_code` SMALLINT UNSIGNED NULL DEFAULT NULL,
  `error` TEXT NULL,
  `next_attempt_at` TIMESTAMP NULL DEFAULT NULL,
  `delivered_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `advertiser_webhook_deliveries_status_next_attempt_at_index` (`status`, `next_attempt_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `advertiser_idempotency_keys` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `key` VARCHAR(191) NOT NULL,
  `request_hash` VARCHAR(64) NOT NULL,
  `response_code` SMALLINT UNSIGNED NOT NULL,
  `response_body` LONGTEXT NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `advertiser_idempotency_keys_user_id_key_unique` (`user_id`, `key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
