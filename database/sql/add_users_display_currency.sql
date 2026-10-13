-- Production helper (Hostinger / phpMyAdmin): viewer display-currency preference.
-- Safe to re-run.

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'display_currency'
);

SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `users` ADD COLUMN `display_currency` VARCHAR(3) NULL DEFAULT NULL',
  'SELECT ''users.display_currency already exists'' AS info'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
