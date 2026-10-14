-- Production helper (Hostinger / phpMyAdmin): site screenshot columns used by
-- enrichment jobs. Safe to re-run. Pair with
-- database/migrations/2026_07_16_230000_add_site_enrichment_system.php

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sites'
    AND COLUMN_NAME = 'screenshot_path'
);

SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `sites` ADD COLUMN `screenshot_path` VARCHAR(255) NULL DEFAULT NULL',
  'SELECT ''sites.screenshot_path already exists'' AS info'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @thumb_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sites'
    AND COLUMN_NAME = 'screenshot_thumb_path'
);

SET @sql := IF(
  @thumb_exists = 0,
  'ALTER TABLE `sites` ADD COLUMN `screenshot_thumb_path` VARCHAR(255) NULL DEFAULT NULL',
  'SELECT ''sites.screenshot_thumb_path already exists'' AS info'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fetched_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sites'
    AND COLUMN_NAME = 'screenshot_fetched_at'
);

SET @sql := IF(
  @fetched_exists = 0,
  'ALTER TABLE `sites` ADD COLUMN `screenshot_fetched_at` TIMESTAMP NULL DEFAULT NULL',
  'SELECT ''sites.screenshot_fetched_at already exists'' AS info'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
