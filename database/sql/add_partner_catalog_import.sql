USE u841082722_cmbop_db;

-- Teqno LTD catalog-partner import + dual pricing (Appendix C.1 steps 2–3).
-- phpMyAdmin / Hostinger: safe to re-run. Laravel migration is
-- 2026_10_19_140000_create_partner_catalog_tables.php (not auto-run on Hostinger).
-- Publishers/partners are never charged a fee.

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- partners
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `partners` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'active',
  `trusted` tinyint(1) NOT NULL DEFAULT 0,
  `agreement_signed` tinyint(1) NOT NULL DEFAULT 0,
  `agreement_signed_at` timestamp NULL DEFAULT NULL,
  `legal_name` varchar(255) DEFAULT NULL,
  `notes_internal` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `partners_user_id_unique` (`user_id`),
  KEY `partners_status_index` (`status`),
  CONSTRAINT `partners_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `partner_connections` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `partner_id` bigint unsigned NOT NULL,
  `type` varchar(32) NOT NULL DEFAULT 'feed_url',
  `feed_url` text DEFAULT NULL,
  `schedule` varchar(32) NOT NULL DEFAULT 'daily',
  `status` varchar(32) NOT NULL DEFAULT 'active',
  `last_sync_at` timestamp NULL DEFAULT NULL,
  `last_sync_status` varchar(32) DEFAULT NULL,
  `last_job_id` bigint unsigned DEFAULT NULL,
  `fail_count` int unsigned NOT NULL DEFAULT 0,
  `field_mapping` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `partner_connections_partner_id_status_index` (`partner_id`,`status`),
  CONSTRAINT `partner_connections_partner_id_foreign` FOREIGN KEY (`partner_id`) REFERENCES `partners` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `partner_import_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `partner_id` bigint unsigned NOT NULL,
  `connection_id` bigint unsigned DEFAULT NULL,
  `source` varchar(32) NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'running',
  `started_at` timestamp NULL DEFAULT NULL,
  `finished_at` timestamp NULL DEFAULT NULL,
  `counts` json DEFAULT NULL,
  `raw_payload_path` varchar(255) DEFAULT NULL,
  `error` text DEFAULT NULL,
  `pending_removals` json DEFAULT NULL,
  `actor_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `partner_import_jobs_partner_id_status_index` (`partner_id`,`status`),
  CONSTRAINT `partner_import_jobs_partner_id_foreign` FOREIGN KEY (`partner_id`) REFERENCES `partners` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `partner_import_rows` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `job_id` bigint unsigned NOT NULL,
  `row_number` int unsigned NOT NULL DEFAULT 0,
  `domain` varchar(255) DEFAULT NULL,
  `result` varchar(32) NOT NULL,
  `reason_code` varchar(64) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `raw` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `partner_import_rows_job_id_result_index` (`job_id`,`result`),
  KEY `partner_import_rows_domain_index` (`domain`),
  CONSTRAINT `partner_import_rows_job_id_foreign` FOREIGN KEY (`job_id`) REFERENCES `partner_import_jobs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `partner_pricing_rules` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `tiers` json NOT NULL,
  `min_commission_eur` decimal(10,2) NOT NULL DEFAULT 25.00,
  `rounding` varchar(16) NOT NULL DEFAULT 'end_9',
  `jitter_percent` decimal(5,2) NOT NULL DEFAULT 5.00,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `partner_auto_approve_rules` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `priority` int unsigned NOT NULL DEFAULT 100,
  `partner_id` bigint unsigned DEFAULT NULL,
  `categories_any` json DEFAULT NULL,
  `categories_none` json DEFAULT NULL,
  `min_dr` int unsigned DEFAULT NULL,
  `min_da` int unsigned DEFAULT NULL,
  `min_monthly_traffic` int unsigned DEFAULT NULL,
  `countries` json DEFAULT NULL,
  `languages` json DEFAULT NULL,
  `max_partner_rate_eur` decimal(10,2) DEFAULT NULL,
  `link_types` json DEFAULT NULL,
  `require_sample_post` tinyint(1) NOT NULL DEFAULT 1,
  `reject_sensitive_only` tinyint(1) NOT NULL DEFAULT 1,
  `action` varchar(32) NOT NULL DEFAULT 'send_to_review',
  `pricing_rule_id` bigint unsigned DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `partner_auto_approve_rules_active_priority_index` (`active`,`priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `partner_catalog_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `partner_id` bigint unsigned NOT NULL,
  `site_id` bigint unsigned DEFAULT NULL,
  `last_job_id` bigint unsigned DEFAULT NULL,
  `matched_rule_id` bigint unsigned DEFAULT NULL,
  `pricing_rule_id` bigint unsigned DEFAULT NULL,
  `listing_status` varchar(32) NOT NULL DEFAULT 'unused',
  `pause_reason` varchar(64) DEFAULT NULL,
  `block_reason` varchar(64) DEFAULT NULL,
  `domain` varchar(255) NOT NULL,
  `site_url` varchar(255) NOT NULL,
  `site_name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `contact_email` varchar(255) DEFAULT NULL,
  `contact_name` varchar(255) DEFAULT NULL,
  `country` varchar(8) DEFAULT NULL,
  `language` varchar(8) DEFAULT NULL,
  `niches` json DEFAULT NULL,
  `raw_niches` json DEFAULT NULL,
  `da` int unsigned NOT NULL DEFAULT 0,
  `dr` int unsigned NOT NULL DEFAULT 0,
  `traffic` int unsigned NOT NULL DEFAULT 0,
  `turnaround_days` int unsigned DEFAULT NULL,
  `turnaround_time` varchar(20) DEFAULT NULL,
  `publication_time` varchar(20) DEFAULT NULL,
  `link_type` varchar(16) NOT NULL DEFAULT 'dofollow',
  `sponsored_label` varchar(16) DEFAULT NULL,
  `example_url` varchar(255) DEFAULT NULL,
  `partner_rate_currency` varchar(3) NOT NULL DEFAULT 'EUR',
  `partner_rate_publish` decimal(12,2) NOT NULL DEFAULT 0.00,
  `partner_rate_writing` decimal(12,2) DEFAULT NULL,
  `partner_rate_sensitive_extra` decimal(12,2) DEFAULT NULL,
  `partner_rate_publish_eur` decimal(12,2) NOT NULL DEFAULT 0.00,
  `partner_rate_writing_eur` decimal(12,2) DEFAULT NULL,
  `partner_rate_sensitive_extra_eur` decimal(12,2) DEFAULT NULL,
  `fx_rate` decimal(12,6) DEFAULT NULL,
  `fx_rate_date` date DEFAULT NULL,
  `partner_rate_version` int unsigned NOT NULL DEFAULT 1,
  `partner_rate_effective_from` timestamp NULL DEFAULT NULL,
  `slb_price_publish_eur` decimal(12,2) DEFAULT NULL,
  `slb_price_writing_eur` decimal(12,2) DEFAULT NULL,
  `slb_price_sensitive_extra_eur` decimal(12,2) DEFAULT NULL,
  `slb_price_source` varchar(16) DEFAULT NULL,
  `slb_jitter` decimal(8,6) DEFAULT NULL,
  `slb_price_overridden` tinyint(1) NOT NULL DEFAULT 0,
  `margin_override` tinyint(1) NOT NULL DEFAULT 0,
  `margin_override_reason` varchar(255) DEFAULT NULL,
  `duplicate` tinyint(1) NOT NULL DEFAULT 0,
  `duplicate_site_id` bigint unsigned DEFAULT NULL,
  `unreachable` tinyint(1) NOT NULL DEFAULT 0,
  `last_reachable_at` timestamp NULL DEFAULT NULL,
  `casino` tinyint(1) NOT NULL DEFAULT 0,
  `unmapped_niche` tinyint(1) NOT NULL DEFAULT 0,
  `niches_truncated` tinyint(1) NOT NULL DEFAULT 0,
  `below_margin_floor` tinyint(1) NOT NULL DEFAULT 0,
  `removed_at_source_at` timestamp NULL DEFAULT NULL,
  `flags` json DEFAULT NULL,
  `source_hash` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `partner_catalog_items_partner_id_domain_unique` (`partner_id`,`domain`),
  KEY `partner_catalog_items_listing_status_index` (`listing_status`),
  KEY `partner_catalog_items_domain_index` (`domain`),
  KEY `partner_catalog_items_site_id_index` (`site_id`),
  CONSTRAINT `partner_catalog_items_partner_id_foreign` FOREIGN KEY (`partner_id`) REFERENCES `partners` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `partner_catalog_item_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `item_id` bigint unsigned NOT NULL,
  `from_status` varchar(32) DEFAULT NULL,
  `to_status` varchar(32) DEFAULT NULL,
  `actor_type` varchar(32) NOT NULL DEFAULT 'system',
  `actor_id` bigint unsigned DEFAULT NULL,
  `reason` varchar(64) DEFAULT NULL,
  `payload` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `partner_catalog_item_events_item_id_index` (`item_id`),
  CONSTRAINT `partner_catalog_item_events_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `partner_catalog_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `partner_site_rate_changes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `item_id` bigint unsigned NOT NULL,
  `old_values` json NOT NULL,
  `new_values` json NOT NULL,
  `source` varchar(32) NOT NULL DEFAULT 'feed',
  `status` varchar(32) NOT NULL DEFAULT 'pending',
  `decided_by` bigint unsigned DEFAULT NULL,
  `decided_at` timestamp NULL DEFAULT NULL,
  `effective_from` timestamp NULL DEFAULT NULL,
  `slb_price_action` varchar(32) DEFAULT NULL,
  `decision_reason` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `partner_site_rate_changes_status_item_id_index` (`status`,`item_id`),
  CONSTRAINT `partner_site_rate_changes_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `partner_catalog_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `partner_fx_rates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `rate_date` date NOT NULL,
  `quote` varchar(3) NOT NULL,
  `rate` decimal(16,8) NOT NULL,
  `source` varchar(16) NOT NULL DEFAULT 'ECB',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `partner_fx_rates_rate_date_quote_unique` (`rate_date`,`quote`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- sites dual-price / partner columns
-- ---------------------------------------------------------------------------
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sites' AND COLUMN_NAME = 'partner_id'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE `sites` ADD COLUMN `partner_id` BIGINT UNSIGNED NULL', 'SELECT ''sites.partner_id already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sites' AND COLUMN_NAME = 'partner_catalog_item_id'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE `sites` ADD COLUMN `partner_catalog_item_id` BIGINT UNSIGNED NULL', 'SELECT ''sites.partner_catalog_item_id already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sites' AND COLUMN_NAME = 'slb_price_publish_eur'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE `sites` ADD COLUMN `slb_price_publish_eur` DECIMAL(12,2) NULL', 'SELECT ''sites.slb_price_publish_eur already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sites' AND COLUMN_NAME = 'slb_price_writing_eur'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE `sites` ADD COLUMN `slb_price_writing_eur` DECIMAL(12,2) NULL', 'SELECT ''sites.slb_price_writing_eur already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sites' AND COLUMN_NAME = 'slb_price_sensitive_extra_eur'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE `sites` ADD COLUMN `slb_price_sensitive_extra_eur` DECIMAL(12,2) NULL', 'SELECT ''sites.slb_price_sensitive_extra_eur already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sites' AND COLUMN_NAME = 'partner_rate_version'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE `sites` ADD COLUMN `partner_rate_version` INT UNSIGNED NULL', 'SELECT ''sites.partner_rate_version already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sites' AND INDEX_NAME = 'sites_partner_id_index'
);
SET @sql := IF(@idx_exists = 0, 'ALTER TABLE `sites` ADD INDEX `sites_partner_id_index` (`partner_id`)', 'SELECT ''sites_partner_id_index already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sites' AND INDEX_NAME = 'sites_partner_catalog_item_id_index'
);
SET @sql := IF(@idx_exists = 0, 'ALTER TABLE `sites` ADD INDEX `sites_partner_catalog_item_id_index` (`partner_catalog_item_id`)', 'SELECT ''sites_partner_catalog_item_id_index already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- default pricing + auto-approve rules (idempotent)
-- ---------------------------------------------------------------------------
INSERT INTO `partner_pricing_rules`
  (`name`, `tiers`, `min_commission_eur`, `rounding`, `jitter_percent`, `is_default`, `active`, `created_at`, `updated_at`)
SELECT
  'Standard tiers',
  '[{"max_eur":50,"multiplier":2.0},{"max_eur":150,"multiplier":1.8},{"max_eur":400,"multiplier":1.6},{"max_eur":null,"multiplier":1.45}]',
  25.00, 'end_9', 5.00, 1, 1, NOW(), NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `partner_pricing_rules` WHERE `is_default` = 1
);

INSERT INTO `partner_auto_approve_rules`
  (`name`, `priority`, `min_dr`, `min_monthly_traffic`, `link_types`, `require_sample_post`, `reject_sensitive_only`, `action`, `pricing_rule_id`, `active`, `created_at`, `updated_at`)
SELECT
  'DR 30+ traffic 2k dofollow',
  10, 30, 2000, '["dofollow"]', 1, 1, 'send_to_review',
  (SELECT `id` FROM `partner_pricing_rules` WHERE `is_default` = 1 ORDER BY `id` ASC LIMIT 1),
  1, NOW(), NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `partner_auto_approve_rules` WHERE `name` = 'DR 30+ traffic 2k dofollow'
);
