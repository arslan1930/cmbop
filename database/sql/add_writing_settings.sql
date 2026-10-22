USE u841082722_cmbop_db;

-- Hostinger phpMyAdmin / mysql client. Safe to re-run.
-- Advertiser cart writing turnaround override (admin setting).
-- Equivalent migration: 2026_10_21_160000_create_writing_settings_table

SET NAMES utf8mb4;

SET @table_exists := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'writing_settings'
);
SET @sql := IF(@table_exists = 0,
  'CREATE TABLE `writing_settings` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `key` VARCHAR(255) NOT NULL,
    `value` JSON NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `writing_settings_key_unique` (`key`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
  'SELECT ''writing_settings already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
