USE u841082722_cmbop_db;

-- Hostinger phpMyAdmin / mysql client. Safe to re-run.
-- Written-article workflow + publisher choice on the 12-month guarantee.
-- Equivalent migration: 2026_10_21_120000_add_article_writing_workflow

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- order_items: writing workflow
-- ---------------------------------------------------------------------------
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'writing_status'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `order_items` ADD COLUMN `writing_status` VARCHAR(32) NULL DEFAULT NULL',
  'SELECT ''order_items.writing_status already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'writing_assignee_id'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `order_items` ADD COLUMN `writing_assignee_id` BIGINT UNSIGNED NULL DEFAULT NULL',
  'SELECT ''order_items.writing_assignee_id already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'writing_deadline_at'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `order_items` ADD COLUMN `writing_deadline_at` TIMESTAMP NULL DEFAULT NULL',
  'SELECT ''order_items.writing_deadline_at already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'writing_brief_notes'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `order_items` ADD COLUMN `writing_brief_notes` TEXT NULL',
  'SELECT ''order_items.writing_brief_notes already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'writing_language'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `order_items` ADD COLUMN `writing_language` VARCHAR(16) NULL DEFAULT NULL',
  'SELECT ''order_items.writing_language already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'article_revision_used'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `order_items` ADD COLUMN `article_revision_used` TINYINT UNSIGNED NOT NULL DEFAULT 0',
  'SELECT ''order_items.article_revision_used already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'article_submitted_at'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `order_items` ADD COLUMN `article_submitted_at` TIMESTAMP NULL DEFAULT NULL',
  'SELECT ''order_items.article_submitted_at already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'article_approved_at'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `order_items` ADD COLUMN `article_approved_at` TIMESTAMP NULL DEFAULT NULL',
  'SELECT ''order_items.article_approved_at already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'writing_reminder_sent_at'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `order_items` ADD COLUMN `writing_reminder_sent_at` TIMESTAMP NULL DEFAULT NULL',
  'SELECT ''order_items.writing_reminder_sent_at already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- orders: publisher restore-or-credit choice before guarantee debit
-- ---------------------------------------------------------------------------
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'link_guarantee_publisher_choice'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `orders` ADD COLUMN `link_guarantee_publisher_choice` VARCHAR(32) NULL DEFAULT NULL',
  'SELECT ''orders.link_guarantee_publisher_choice already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
