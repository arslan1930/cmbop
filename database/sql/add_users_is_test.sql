-- Production helper (Hostinger / phpMyAdmin): mark accounts as test so the
-- admin dashboard can hide them from queues and KPIs. Safe to re-run.

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'is_test'
);

SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `users` ADD COLUMN `is_test` TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT ''users.is_test already exists'' AS info'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
