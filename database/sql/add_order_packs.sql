-- Article packs: group N (3–10) placements bought together on one site.
-- Laravel twin: database/migrations/2026_10_28_190000_create_order_packs_table.php
-- Safe to re-run. Additive only. New columns are nullable/defaulted so app
-- code works before this is applied. Do not run against production from the
-- cloud agent — owner applies it in phpMyAdmin.
--
-- Optional backfill (commented): groups existing paid checkouts that look
-- like bulk packs (same reference_code + site_id, 3–10 open/paid rows).
-- Uncomment and run AFTER creating the table if you want historical packs.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `order_packs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `site_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `reference_code` VARCHAR(32) NULL DEFAULT NULL,
  `pack_size` TINYINT UNSIGNED NOT NULL,
  `discount_tier` TINYINT UNSIGNED NULL DEFAULT NULL,
  `discount_percent` DECIMAL(5,2) NULL DEFAULT NULL,
  `status` VARCHAR(40) NOT NULL DEFAULT 'in_progress',
  `payment_method` VARCHAR(40) NULL DEFAULT NULL,
  `total_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `published_count` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `refunded_count` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `completed_count` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `completed_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `order_packs_user_id_created_at_index` (`user_id`, `created_at`),
  KEY `order_packs_reference_code_index` (`reference_code`),
  KEY `order_packs_status_index` (`status`),
  KEY `order_packs_site_id_reference_code_index` (`site_id`, `reference_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'order_pack_id'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `orders` ADD COLUMN `order_pack_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `reference_code`, ADD INDEX `orders_order_pack_id_index` (`order_pack_id`)',
  'SELECT ''orders.order_pack_id already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices' AND COLUMN_NAME = 'order_pack_id'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `invoices` ADD COLUMN `order_pack_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `order_id`, ADD INDEX `invoices_order_pack_id_index` (`order_pack_id`)',
  'SELECT ''invoices.order_pack_id already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- OPTIONAL backfill (safe / additive). Groups reference_code + site_id where
-- 3–10 distinct paid/cancelled orders share the same site from one checkout.
-- Skips refs that already have order_pack_id set. Run only once if desired.
-- ---------------------------------------------------------------------------
/*
INSERT INTO order_packs (
  user_id, site_id, reference_code, pack_size, discount_tier, discount_percent,
  status, payment_method, total_amount, published_count, refunded_count,
  completed_count, completed_at, created_at, updated_at
)
SELECT
  o.user_id,
  oi.site_id,
  o.reference_code,
  COUNT(DISTINCT o.id) AS pack_size,
  CASE
    WHEN COUNT(DISTINCT o.id) >= 10 THEN 10
    WHEN COUNT(DISTINCT o.id) >= 5 THEN 5
    ELSE 3
  END AS discount_tier,
  NULL AS discount_percent,
  'in_progress' AS status,
  MIN(o.payment_method) AS payment_method,
  ROUND(SUM(o.total_amount), 2) AS total_amount,
  0, 0, 0, NULL,
  MIN(o.created_at), NOW()
FROM orders o
INNER JOIN order_items oi ON oi.order_id = o.id
WHERE o.reference_code IS NOT NULL
  AND o.reference_code <> ''
  AND (o.order_pack_id IS NULL)
  AND oi.site_id IS NOT NULL
GROUP BY o.user_id, oi.site_id, o.reference_code
HAVING COUNT(DISTINCT o.id) BETWEEN 3 AND 10
  AND COUNT(DISTINCT oi.site_id) = 1;

UPDATE orders o
INNER JOIN order_items oi ON oi.order_id = o.id
INNER JOIN order_packs p
  ON p.reference_code = o.reference_code
 AND p.site_id = oi.site_id
 AND p.user_id = o.user_id
SET o.order_pack_id = p.id
WHERE o.order_pack_id IS NULL
  AND o.reference_code IS NOT NULL;
*/
