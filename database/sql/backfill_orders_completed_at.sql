-- Stamp orders.completed_at on completed orders that never received one.
-- Safe to re-run: adds the column only if missing, then backfills NULLs.

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'orders'
    AND COLUMN_NAME = 'completed_at'
);

SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `orders` ADD COLUMN `completed_at` TIMESTAMP NULL DEFAULT NULL, ADD INDEX `orders_completed_at_index` (`completed_at`)',
  'SELECT ''orders.completed_at already exists'' AS info'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Prefer a placement stamp, then paid_at, then last update.
UPDATE `orders` o
LEFT JOIN (
  SELECT `order_id`, MAX(`completed_at`) AS item_completed_at
  FROM `order_items`
  WHERE `completed_at` IS NOT NULL
  GROUP BY `order_id`
) oi ON oi.order_id = o.id
SET o.completed_at = COALESCE(oi.item_completed_at, o.paid_at, o.updated_at)
WHERE o.status = 'completed'
  AND o.completed_at IS NULL;
