-- Abandoned cart reminders + What's new campaign/banner support.
-- Safe to re-run. App code is Schema-guarded so the site works before this runs.
-- Equivalent migration: 2026_10_25_120000_add_abandoned_cart_and_whats_new

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- abandoned_cart_settings (admin master switch)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `abandoned_cart_settings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key` VARCHAR(255) NOT NULL,
  `value` JSON NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `abandoned_cart_settings_key_unique` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- abandoned_cart_reminder_sends (never resend the same stage for the same cart)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `abandoned_cart_reminder_sends` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `cart_fingerprint` VARCHAR(64) NOT NULL,
  `stage` TINYINT UNSIGNED NOT NULL,
  `sent_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `abandoned_cart_sends_user_fp_stage` (`user_id`, `cart_fingerprint`, `stage`),
  KEY `abandoned_cart_reminder_sends_user_id_index` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- announcement_dismissals (per-user What's new dismiss)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `announcement_dismissals` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `announcement_id` BIGINT UNSIGNED NOT NULL,
  `version` INT UNSIGNED NOT NULL DEFAULT 1,
  `dismissed_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `announcement_dismissals_user_announcement` (`user_id`, `announcement_id`),
  KEY `announcement_dismissals_announcement_id_index` (`announcement_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- email_campaigns: feature-update template + preference
-- ---------------------------------------------------------------------------
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_campaigns' AND COLUMN_NAME = 'template_key'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `email_campaigns` ADD COLUMN `template_key` VARCHAR(40) NULL DEFAULT NULL',
  'SELECT ''email_campaigns.template_key already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_campaigns' AND COLUMN_NAME = 'template_payload'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `email_campaigns` ADD COLUMN `template_payload` JSON NULL DEFAULT NULL',
  'SELECT ''email_campaigns.template_payload already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_campaigns' AND COLUMN_NAME = 'preference_key'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `email_campaigns` ADD COLUMN `preference_key` VARCHAR(40) NULL DEFAULT NULL',
  'SELECT ''email_campaigns.preference_key already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
