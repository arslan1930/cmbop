USE u841082722_cmbop_db;

-- Partner web dashboard (Appendix C.1 steps 1 and 5).
-- phpMyAdmin / Hostinger: safe to re-run. Laravel twin:
-- database/migrations/2026_10_19_160000_create_partner_dashboard_tables.php
-- (migrations are not auto-run on Hostinger).

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- partners extra columns
-- ---------------------------------------------------------------------------
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'partners' AND COLUMN_NAME = 'notification_preferences'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `partners` ADD COLUMN `notification_preferences` JSON NULL',
  'SELECT ''partners.notification_preferences already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'partners' AND COLUMN_NAME = 'security_review_at'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `partners` ADD COLUMN `security_review_at` TIMESTAMP NULL DEFAULT NULL',
  'SELECT ''partners.security_review_at already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'partners' AND COLUMN_NAME = 'one_click_links'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `partners` ADD COLUMN `one_click_links` TINYINT(1) NULL DEFAULT NULL',
  'SELECT ''partners.one_click_links already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- partner_email_action_tokens
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `partner_email_action_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `order_item_id` bigint unsigned DEFAULT NULL,
  `token_hash` varchar(64) NOT NULL,
  `actions` json DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `used_at` timestamp NULL DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `bound_email` varchar(255) DEFAULT NULL,
  `bound_password_hash` varchar(255) DEFAULT NULL,
  `created_ip` varchar(45) DEFAULT NULL,
  `used_ip` varchar(45) DEFAULT NULL,
  `used_user_agent` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `partner_email_action_tokens_token_hash_unique` (`token_hash`),
  KEY `partner_email_action_tokens_user_id_expires_at_index` (`user_id`,`expires_at`),
  KEY `partner_email_action_tokens_order_item_id_index` (`order_item_id`),
  CONSTRAINT `partner_email_action_tokens_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- partner_email_change_requests
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `partner_email_change_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `old_email` varchar(255) NOT NULL,
  `new_email` varchar(255) NOT NULL,
  `confirm_hash` varchar(64) NOT NULL,
  `cancel_hash` varchar(64) NOT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `confirmed_at` timestamp NULL DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `partner_email_change_requests_confirm_hash_unique` (`confirm_hash`),
  UNIQUE KEY `partner_email_change_requests_cancel_hash_unique` (`cancel_hash`),
  KEY `partner_email_change_requests_user_id_expires_at_index` (`user_id`,`expires_at`),
  CONSTRAINT `partner_email_change_requests_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
