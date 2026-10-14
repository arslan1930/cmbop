-- Production helper (Hostinger / phpMyAdmin): duplicate-article fingerprint.
-- Safe to re-run. Application code tolerates the column being missing.

USE u841082722_cmbop_db;

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'content_submissions'
    AND COLUMN_NAME = 'content_fingerprint'
);

SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `content_submissions` ADD COLUMN `content_fingerprint` CHAR(64) NULL DEFAULT NULL, ADD INDEX `content_submissions_content_fingerprint_index` (`content_fingerprint`)',
  'SELECT ''content_submissions.content_fingerprint already exists'' AS info'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
