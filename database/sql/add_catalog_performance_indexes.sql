-- Hostinger phpMyAdmin / mysql client. Safe to re-run.
-- Generated country_norm / language_norm let catalog filters use btree indexes
-- instead of LOWER(TRIM(column)). Stored generated columns backfill themselves.

USE u841082722_cmbop_db;

-- country_norm
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sites'
    AND COLUMN_NAME = 'country_norm'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `sites` ADD COLUMN `country_norm` VARCHAR(16) GENERATED ALWAYS AS (LOWER(TRIM(`country`))) STORED',
  'SELECT ''sites.country_norm already exists'' AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- language_norm
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sites'
    AND COLUMN_NAME = 'language_norm'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `sites` ADD COLUMN `language_norm` VARCHAR(16) GENERATED ALWAYS AS (LOWER(TRIM(`language`))) STORED',
  'SELECT ''sites.language_norm already exists'' AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Indexes (skip when the name already exists)
SET @exists := (
  SELECT COUNT(1) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'sites' AND index_name = 'sites_country_norm_index'
);
SET @sql := IF(@exists = 0, 'CREATE INDEX sites_country_norm_index ON sites (country_norm)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @exists := (
  SELECT COUNT(1) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'sites' AND index_name = 'sites_language_norm_index'
);
SET @sql := IF(@exists = 0, 'CREATE INDEX sites_language_norm_index ON sites (language_norm)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @exists := (
  SELECT COUNT(1) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'sites' AND index_name = 'sites_active_da_index'
);
SET @sql := IF(@exists = 0, 'CREATE INDEX sites_active_da_index ON sites (active, da, id)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @exists := (
  SELECT COUNT(1) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'sites' AND index_name = 'sites_active_traffic_index'
);
SET @sql := IF(@exists = 0, 'CREATE INDEX sites_active_traffic_index ON sites (active, traffic, id)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @exists := (
  SELECT COUNT(1) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'sites' AND index_name = 'sites_active_price_index'
);
SET @sql := IF(@exists = 0, 'CREATE INDEX sites_active_price_index ON sites (active, price, id)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @exists := (
  SELECT COUNT(1) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'sites' AND index_name = 'sites_active_created_at_index'
);
SET @sql := IF(@exists = 0, 'CREATE INDEX sites_active_created_at_index ON sites (active, created_at, id)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @exists := (
  SELECT COUNT(1) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'sites' AND index_name = 'sites_featured_until_index'
);
SET @sql := IF(@exists = 0, 'CREATE INDEX sites_featured_until_index ON sites (featured_until)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @exists := (
  SELECT COUNT(1) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'sites' AND index_name = 'sites_country_index'
);
SET @sql := IF(@exists = 0, 'CREATE INDEX sites_country_index ON sites (country)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @exists := (
  SELECT COUNT(1) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'sites' AND index_name = 'sites_language_index'
);
SET @sql := IF(@exists = 0, 'CREATE INDEX sites_language_index ON sites (language)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @exists := (
  SELECT COUNT(1) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'sites' AND index_name = 'sites_category_index'
);
SET @sql := IF(@exists = 0, 'CREATE INDEX sites_category_index ON sites (category)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @exists := (
  SELECT COUNT(1) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'sites' AND index_name = 'sites_archived_at_index'
);
SET @sql := IF(@exists = 0, 'CREATE INDEX sites_archived_at_index ON sites (archived_at)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @exists := (
  SELECT COUNT(1) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'sites' AND index_name = 'sites_bulk_site_request_id_index'
);
SET @sql := IF(@exists = 0, 'CREATE INDEX sites_bulk_site_request_id_index ON sites (bulk_site_request_id)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @exists := (
  SELECT COUNT(1) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'bulk_site_requests' AND index_name = 'bulk_site_requests_status_index'
);
SET @sql := IF(@exists = 0, 'CREATE INDEX bulk_site_requests_status_index ON bulk_site_requests (status)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- last-published catalog join: MAX(completed_at) GROUP BY site_id
SET @col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'completed_at'
);
SET @exists := (
  SELECT COUNT(1) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'order_items' AND index_name = 'order_items_site_id_completed_at_index'
);
SET @sql := IF(
  @exists = 0 AND @col > 0,
  'CREATE INDEX order_items_site_id_completed_at_index ON order_items (site_id, completed_at)',
  'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
