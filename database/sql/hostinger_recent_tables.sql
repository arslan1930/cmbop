-- Hostinger / phpMyAdmin: recent tables from marketplace updates
-- Safe to re-run: uses IF NOT EXISTS / column existence checks where practical.
-- Requires existing tables: users, wallets, orders

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------------
-- site_announcements
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `site_announcements` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `type` varchar(40) NOT NULL DEFAULT 'general',
  `style` varchar(40) NOT NULL DEFAULT 'info',
  `audience` varchar(40) NOT NULL DEFAULT 'all',
  `cta_label` varchar(255) DEFAULT NULL,
  `cta_url` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_dismissible` tinyint(1) NOT NULL DEFAULT 1,
  `priority` int unsigned NOT NULL DEFAULT 100,
  `starts_at` timestamp NULL DEFAULT NULL,
  `ends_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `site_announcements_is_active_audience_priority_index` (`is_active`,`audience`,`priority`),
  KEY `site_announcements_starts_at_ends_at_index` (`starts_at`,`ends_at`),
  KEY `site_announcements_created_by_foreign` (`created_by`),
  CONSTRAINT `site_announcements_created_by_foreign`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- ad_banners
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ad_banners` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `alt_text` varchar(255) DEFAULT NULL,
  `size_key` varchar(40) NOT NULL,
  `width` smallint unsigned NOT NULL,
  `height` smallint unsigned NOT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `image_url` varchar(255) DEFAULT NULL,
  `link_url` varchar(255) DEFAULT NULL,
  `placement` varchar(40) NOT NULL DEFAULT 'content_top',
  `audience` varchar(40) NOT NULL DEFAULT 'all',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `open_in_new_tab` tinyint(1) NOT NULL DEFAULT 1,
  `priority` int unsigned NOT NULL DEFAULT 100,
  `impressions` bigint unsigned NOT NULL DEFAULT 0,
  `clicks` bigint unsigned NOT NULL DEFAULT 0,
  `starts_at` timestamp NULL DEFAULT NULL,
  `ends_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ad_banners_is_active_placement_audience_priority_index` (`is_active`,`placement`,`audience`,`priority`),
  KEY `ad_banners_size_key_is_active_index` (`size_key`,`is_active`),
  KEY `ad_banners_starts_at_ends_at_index` (`starts_at`,`ends_at`),
  KEY `ad_banners_created_by_foreign` (`created_by`),
  CONSTRAINT `ad_banners_created_by_foreign`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- blogs (public blog + Admin → Blogs)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `blogs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `excerpt` varchar(255) DEFAULT NULL,
  `content` longtext NOT NULL,
  `featured_image` varchar(255) DEFAULT NULL,
  `author` varchar(255) DEFAULT NULL,
  `tags` text DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'draft',
  `published_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `blogs_slug_unique` (`slug`),
  KEY `blogs_status_index` (`status`),
  KEY `blogs_published_at_index` (`published_at`),
  KEY `blogs_created_by_foreign` (`created_by`),
  KEY `blogs_updated_by_foreign` (`updated_by`),
  CONSTRAINT `blogs_created_by_foreign`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `blogs_updated_by_foreign`
    FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- wallet_transactions
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wallet_transactions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `wallet_id` bigint unsigned DEFAULT NULL,
  `type` varchar(40) NOT NULL,
  `direction` varchar(10) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `bonus_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `balance_after` decimal(12,2) DEFAULT NULL,
  `bonus_balance_after` decimal(12,2) DEFAULT NULL,
  `currency` varchar(3) NOT NULL DEFAULT 'EUR',
  `status` varchar(40) NOT NULL DEFAULT 'completed',
  `description` varchar(255) DEFAULT NULL,
  `reference` varchar(255) DEFAULT NULL,
  `payment_method` varchar(255) DEFAULT NULL,
  `related_type` varchar(255) DEFAULT NULL,
  `related_id` bigint unsigned DEFAULT NULL,
  `meta` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `wallet_transactions_user_id_created_at_index` (`user_id`,`created_at`),
  KEY `wallet_transactions_user_id_type_status_index` (`user_id`,`type`,`status`),
  KEY `wallet_transactions_reference_index` (`reference`),
  KEY `wallet_transactions_related_type_related_id_index` (`related_type`,`related_id`),
  KEY `wallet_transactions_wallet_id_foreign` (`wallet_id`),
  CONSTRAINT `wallet_transactions_user_id_foreign`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `wallet_transactions_wallet_id_foreign`
    FOREIGN KEY (`wallet_id`) REFERENCES `wallets` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Billing / invoices
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `invoice_sequences` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `year` smallint unsigned NOT NULL,
  `last_number` int unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoice_sequences_year_unique` (`year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `invoices` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `invoice_number` varchar(32) NOT NULL,
  `type` varchar(40) NOT NULL,
  `status` varchar(40) NOT NULL DEFAULT 'issued',
  `user_id` bigint unsigned NOT NULL,
  `order_id` bigint unsigned DEFAULT NULL,
  `reference_code` varchar(255) DEFAULT NULL,
  `order_number` varchar(255) DEFAULT NULL,
  `currency` varchar(3) NOT NULL DEFAULT 'EUR',
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax_rate` decimal(8,4) NOT NULL DEFAULT 0.0000,
  `tax_label` varchar(255) DEFAULT NULL,
  `coupon_code` varchar(255) DEFAULT NULL,
  `payment_method` varchar(255) DEFAULT NULL,
  `payment_status` varchar(255) DEFAULT NULL,
  `transaction_id` varchar(255) DEFAULT NULL,
  `invoice_date` timestamp NULL DEFAULT NULL,
  `due_date` timestamp NULL DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `customer_name` varchar(255) DEFAULT NULL,
  `customer_email` varchar(255) DEFAULT NULL,
  `billing_snapshot` json DEFAULT NULL,
  `line_items` json DEFAULT NULL,
  `pdf_disk` varchar(255) NOT NULL DEFAULT 'local',
  `pdf_path` varchar(255) DEFAULT NULL,
  `emailed_at` timestamp NULL DEFAULT NULL,
  `email_count` int unsigned NOT NULL DEFAULT 0,
  `download_count` int unsigned NOT NULL DEFAULT 0,
  `parent_invoice_id` bigint unsigned DEFAULT NULL,
  `cancelled_by` bigint unsigned DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `cancel_reason` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `meta` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoices_invoice_number_unique` (`invoice_number`),
  KEY `invoices_reference_code_index` (`reference_code`),
  KEY `invoices_order_number_index` (`order_number`),
  KEY `invoices_transaction_id_index` (`transaction_id`),
  KEY `invoices_user_id_type_status_index` (`user_id`,`type`,`status`),
  KEY `invoices_order_id_type_index` (`order_id`,`type`),
  KEY `invoices_created_at_index` (`created_at`),
  KEY `invoices_parent_invoice_id_foreign` (`parent_invoice_id`),
  KEY `invoices_cancelled_by_foreign` (`cancelled_by`),
  CONSTRAINT `invoices_user_id_foreign`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `invoices_order_id_foreign`
    FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `invoices_parent_invoice_id_foreign`
    FOREIGN KEY (`parent_invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `invoices_cancelled_by_foreign`
    FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `billing_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `event_type` varchar(80) NOT NULL,
  `invoice_id` bigint unsigned DEFAULT NULL,
  `order_id` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `meta` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `billing_events_event_type_index` (`event_type`),
  KEY `billing_events_created_at_index` (`created_at`),
  KEY `billing_events_invoice_id_foreign` (`invoice_id`),
  KEY `billing_events_order_id_foreign` (`order_id`),
  KEY `billing_events_user_id_foreign` (`user_id`),
  CONSTRAINT `billing_events_invoice_id_foreign`
    FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `billing_events_order_id_foreign`
    FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `billing_events_user_id_foreign`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------------
-- ALTERs (run once; ignore error if column already exists / wrong type)
-- ---------------------------------------------------------------------------

-- Wallet bonus columns
ALTER TABLE `wallets`
  ADD COLUMN IF NOT EXISTS `bonus_balance` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `reserved_balance`,
  ADD COLUMN IF NOT EXISTS `bonus_reserved` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `bonus_balance`;

-- Allow long multi-category CSV on sites
ALTER TABLE `sites` MODIFY `category` TEXT NULL;

-- Drop Apple Sign-In columns if present (ignore if already gone)
-- ALTER TABLE `users` DROP COLUMN `apple_id`;
-- ALTER TABLE `users` DROP COLUMN `apple_token`;
