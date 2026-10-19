USE u841082722_cmbop_db;

-- Production helper (Hostinger / phpMyAdmin): staff-created publisher onboarding.
-- Safe to re-run. Adds invite/activated tracking used by Admin → Add publisher.

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'staff_created'
);

SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `users` ADD COLUMN `staff_created` TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT ''users.staff_created already exists'' AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'staff_created_by'
);

SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `users` ADD COLUMN `staff_created_by` BIGINT UNSIGNED NULL',
  'SELECT ''users.staff_created_by already exists'' AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'staff_invite_sent_at'
);

SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `users` ADD COLUMN `staff_invite_sent_at` TIMESTAMP NULL DEFAULT NULL',
  'SELECT ''users.staff_invite_sent_at already exists'' AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'staff_onboarded_at'
);

SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `users` ADD COLUMN `staff_onboarded_at` TIMESTAMP NULL DEFAULT NULL',
  'SELECT ''users.staff_onboarded_at already exists'' AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND INDEX_NAME = 'users_staff_created_index'
);

SET @sql := IF(
  @idx_exists = 0,
  'ALTER TABLE `users` ADD INDEX `users_staff_created_index` (`staff_created`)',
  'SELECT ''users_staff_created_index already exists'' AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
