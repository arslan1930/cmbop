-- Staff-only archive category (below_quality, duplicate, spam, publisher_request, other).
-- Never shown to publishers. Safe to re-run.

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sites'
    AND COLUMN_NAME = 'archive_reason'
);

SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `sites` ADD COLUMN `archive_reason` VARCHAR(32) NULL DEFAULT NULL, ADD INDEX `sites_archive_reason_index` (`archive_reason`)',
  'SELECT ''sites.archive_reason already exists'' AS info'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
