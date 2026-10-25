-- Pack discount tiers beyond the original 3-article rate.
-- 3-article (qty 3–4): sites.bulk_discount_percent (already exists)
-- 5-article (qty 5–9): sites.bulk_discount_percent_5
-- 10-article (qty 10+): sites.bulk_discount_percent_10
-- Safe to re-run. App code is guarded with Site::hasSitesColumn so it works
-- before this SQL is applied.

SET @col5_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sites'
    AND COLUMN_NAME = 'bulk_discount_percent_5'
);

SET @sql5 := IF(
  @col5_exists = 0,
  'ALTER TABLE `sites` ADD COLUMN `bulk_discount_percent_5` DECIMAL(5,2) NULL DEFAULT NULL AFTER `bulk_discount_percent`',
  'SELECT ''sites.bulk_discount_percent_5 already exists'' AS info'
);

PREPARE stmt5 FROM @sql5;
EXECUTE stmt5;
DEALLOCATE PREPARE stmt5;

SET @col10_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sites'
    AND COLUMN_NAME = 'bulk_discount_percent_10'
);

SET @sql10 := IF(
  @col10_exists = 0,
  'ALTER TABLE `sites` ADD COLUMN `bulk_discount_percent_10` DECIMAL(5,2) NULL DEFAULT NULL AFTER `bulk_discount_percent_5`',
  'SELECT ''sites.bulk_discount_percent_10 already exists'' AS info'
);

PREPARE stmt10 FROM @sql10;
EXECUTE stmt10;
DEALLOCATE PREPARE stmt10;
