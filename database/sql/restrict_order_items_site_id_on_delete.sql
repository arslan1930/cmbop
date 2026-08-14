-- Prevent deleting a site from wiping order_items (was ON DELETE CASCADE).
-- Safe to re-run. Prefer the Laravel migration when migrate is available.

SET @fk := (
  SELECT CONSTRAINT_NAME
  FROM information_schema.REFERENTIAL_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'order_items'
    AND REFERENCED_TABLE_NAME = 'sites'
    AND CONSTRAINT_NAME IN (
      SELECT CONSTRAINT_NAME
      FROM information_schema.KEY_COLUMN_USAGE
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'order_items'
        AND COLUMN_NAME = 'site_id'
        AND REFERENCED_TABLE_NAME = 'sites'
    )
  LIMIT 1
);

SET @sql := IF(
  @fk IS NOT NULL,
  CONCAT('ALTER TABLE `order_items` DROP FOREIGN KEY `', @fk, '`'),
  'SELECT ''order_items.site_id FK already dropped or missing'' AS info'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_exists := (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'order_items'
    AND CONSTRAINT_NAME = 'order_items_site_id_foreign'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);

SET @sql := IF(
  @fk_exists = 0,
  'ALTER TABLE `order_items` ADD CONSTRAINT `order_items_site_id_foreign` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE RESTRICT',
  'SELECT ''order_items_site_id_foreign already exists'' AS info'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
