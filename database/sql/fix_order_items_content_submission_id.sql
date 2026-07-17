-- Run this on Hostinger / phpMyAdmin if checkout fails with:
-- Unknown column 'content_submission_id' in 'where clause'
--
-- Ignore "Duplicate column name" errors for columns that already exist.

ALTER TABLE `order_items` ADD COLUMN `content_submission_id` BIGINT UNSIGNED NULL;
ALTER TABLE `order_items` ADD COLUMN `content_disk` VARCHAR(40) NULL;
ALTER TABLE `order_items` ADD COLUMN `content_path` VARCHAR(255) NULL;
ALTER TABLE `order_items` ADD COLUMN `content_original_name` VARCHAR(255) NULL;
ALTER TABLE `order_items` ADD COLUMN `content_mime` VARCHAR(120) NULL;
ALTER TABLE `order_items` ADD COLUMN `anchor_text` VARCHAR(160) NULL;
ALTER TABLE `order_items` ADD COLUMN `target_url` VARCHAR(1000) NULL;
ALTER TABLE `order_items` ADD COLUMN `feature_image_url` VARCHAR(1000) NULL;
ALTER TABLE `order_items` ADD COLUMN `moderation_status` VARCHAR(40) NULL;

-- Optional (only if `content_submissions` table already exists):
-- ALTER TABLE `order_items`
--   ADD CONSTRAINT `order_items_content_submission_id_foreign`
--   FOREIGN KEY (`content_submission_id`) REFERENCES `content_submissions` (`id`)
--   ON DELETE SET NULL;
