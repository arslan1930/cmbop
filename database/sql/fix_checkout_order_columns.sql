-- Hostinger / phpMyAdmin: columns required for card/wallet checkout order INSERT
-- Fixes: "Unable to start card payment. Please try again."
-- Underlying cause is usually Unknown column on orders / order_items.
-- Safe to re-run: ignore "Duplicate column name" errors.

-- orders (schedule + stripe)
ALTER TABLE `orders` ADD COLUMN `stripe_session_id` varchar(255) NULL;
ALTER TABLE `orders` ADD COLUMN `stripe_payment_intent_id` varchar(255) NULL;
ALTER TABLE `orders` ADD COLUMN `publication_mode` varchar(20) NOT NULL DEFAULT 'immediate';
ALTER TABLE `orders` ADD COLUMN `scheduled_publish_at` timestamp NULL;
ALTER TABLE `orders` ADD COLUMN `schedule_timezone` varchar(64) NULL;
ALTER TABLE `orders` ADD COLUMN `sensitive_type` varchar(50) NULL;
ALTER TABLE `orders` ADD COLUMN `additional_price` decimal(10,2) NULL DEFAULT 0;

-- order_items (content library + fee snapshot + publisher workflow)
ALTER TABLE `order_items` ADD COLUMN `content_submission_id` BIGINT UNSIGNED NULL;
ALTER TABLE `order_items` ADD COLUMN `content_disk` VARCHAR(40) NULL;
ALTER TABLE `order_items` ADD COLUMN `content_path` VARCHAR(255) NULL;
ALTER TABLE `order_items` ADD COLUMN `content_original_name` VARCHAR(255) NULL;
ALTER TABLE `order_items` ADD COLUMN `content_mime` VARCHAR(120) NULL;
ALTER TABLE `order_items` ADD COLUMN `anchor_text` VARCHAR(160) NULL;
ALTER TABLE `order_items` ADD COLUMN `target_url` VARCHAR(1000) NULL;
ALTER TABLE `order_items` ADD COLUMN `feature_image_url` VARCHAR(1000) NULL;
ALTER TABLE `order_items` ADD COLUMN `moderation_status` VARCHAR(40) NULL;
ALTER TABLE `order_items` ADD COLUMN `publisher_price` decimal(10,2) NULL;
ALTER TABLE `order_items` ADD COLUMN `platform_fee_percent` decimal(5,2) NULL;
ALTER TABLE `order_items` ADD COLUMN `platform_fee_amount` decimal(10,2) NULL;
ALTER TABLE `order_items` ADD COLUMN `publisher_status` varchar(40) NULL DEFAULT 'pending';
ALTER TABLE `order_items` ADD COLUMN `accepted_at` timestamp NULL;
ALTER TABLE `order_items` ADD COLUMN `rejected_at` timestamp NULL;
ALTER TABLE `order_items` ADD COLUMN `completed_at` timestamp NULL;
ALTER TABLE `order_items` ADD COLUMN `rejection_reason` text NULL;
ALTER TABLE `order_items` ADD COLUMN `completion_notes` text NULL;
ALTER TABLE `order_items` ADD COLUMN `live_url` varchar(1000) NULL;
ALTER TABLE `order_items` ADD COLUMN `live_url_submitted_at` timestamp NULL;
