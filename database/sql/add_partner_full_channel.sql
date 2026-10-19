USE u841082722_cmbop_db;

-- Partner full channel (Appendix C.1 steps 4, 6–11).
-- phpMyAdmin / Hostinger: safe to re-run. Laravel twin:
-- database/migrations/2026_10_19_180000_create_partner_full_channel_tables.php
-- Migrations are not auto-run on Hostinger.

SET NAMES utf8mb4;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'partners' AND COLUMN_NAME = 'payout_mode'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `partners` ADD COLUMN `payout_mode` VARCHAR(20) NOT NULL DEFAULT ''held''',
  'SELECT ''partners.payout_mode already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'partners' AND COLUMN_NAME = 'payout_mode_effective_from'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `partners` ADD COLUMN `payout_mode_effective_from` TIMESTAMP NULL DEFAULT NULL',
  'SELECT ''partners.payout_mode_effective_from already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'partners' AND COLUMN_NAME = 'payout_mode_requested'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `partners` ADD COLUMN `payout_mode_requested` VARCHAR(20) NULL DEFAULT NULL',
  'SELECT ''partners.payout_mode_requested already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'partners' AND COLUMN_NAME = 'instant_exposure_cap_minor'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `partners` ADD COLUMN `instant_exposure_cap_minor` INT UNSIGNED NOT NULL DEFAULT 50000',
  'SELECT ''partners.instant_exposure_cap_minor already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'partners' AND COLUMN_NAME = 'keep_live_months'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `partners` ADD COLUMN `keep_live_months` TINYINT UNSIGNED NOT NULL DEFAULT 12',
  'SELECT ''partners.keep_live_months already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'partners' AND COLUMN_NAME = 'fulfilment_channel_default'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `partners` ADD COLUMN `fulfilment_channel_default` VARCHAR(32) NOT NULL DEFAULT ''dashboard''',
  'SELECT ''partners.fulfilment_channel_default already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'partners' AND COLUMN_NAME = 'public_id'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `partners` ADD COLUMN `public_id` VARCHAR(32) NULL DEFAULT NULL',
  'SELECT ''partners.public_id already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'hold_until'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `order_items` ADD COLUMN `hold_until` TIMESTAMP NULL DEFAULT NULL',
  'SELECT ''order_items.hold_until already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'keep_live_until'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `order_items` ADD COLUMN `keep_live_until` TIMESTAMP NULL DEFAULT NULL',
  'SELECT ''order_items.keep_live_until already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'payout_mode_at_submission'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `order_items` ADD COLUMN `payout_mode_at_submission` VARCHAR(20) NULL DEFAULT NULL',
  'SELECT ''order_items.payout_mode_at_submission already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'first_submitted_at'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `order_items` ADD COLUMN `first_submitted_at` TIMESTAMP NULL DEFAULT NULL',
  'SELECT ''order_items.first_submitted_at already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'partner_corrections_used'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `order_items` ADD COLUMN `partner_corrections_used` TINYINT UNSIGNED NOT NULL DEFAULT 0',
  'SELECT ''order_items.partner_corrections_used already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'partner_dispute_status'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `order_items` ADD COLUMN `partner_dispute_status` VARCHAR(32) NULL DEFAULT NULL',
  'SELECT ''order_items.partner_dispute_status already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'partner_earnings_status'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `order_items` ADD COLUMN `partner_earnings_status` VARCHAR(32) NULL DEFAULT NULL',
  'SELECT ''order_items.partner_earnings_status already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'hold_released_at'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `order_items` ADD COLUMN `hold_released_at` TIMESTAMP NULL DEFAULT NULL',
  'SELECT ''order_items.hold_released_at already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'link_alert'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `order_items` ADD COLUMN `link_alert` TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT ''order_items.link_alert already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'keep_live_terms_version'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `order_items` ADD COLUMN `keep_live_terms_version` VARCHAR(32) NULL DEFAULT NULL',
  'SELECT ''order_items.keep_live_terms_version already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'partner_awaiting_relay'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `order_items` ADD COLUMN `partner_awaiting_relay` TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT ''order_items.partner_awaiting_relay already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'partner_payout_eur'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `order_items` ADD COLUMN `partner_payout_eur` DECIMAL(10,2) NULL DEFAULT NULL',
  'SELECT ''order_items.partner_payout_eur already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'slb_price_eur_internal'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `order_items` ADD COLUMN `slb_price_eur_internal` DECIMAL(10,2) NULL DEFAULT NULL',
  'SELECT ''order_items.slb_price_eur_internal already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'partner_connections' AND COLUMN_NAME = 'api_base_url'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `partner_connections` ADD COLUMN `api_base_url` TEXT NULL',
  'SELECT ''partner_connections.api_base_url already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'partner_connections' AND COLUMN_NAME = 'auth_header_name'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `partner_connections` ADD COLUMN `auth_header_name` VARCHAR(120) NULL DEFAULT NULL',
  'SELECT ''partner_connections.auth_header_name already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'partner_connections' AND COLUMN_NAME = 'auth_key_encrypted'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `partner_connections` ADD COLUMN `auth_key_encrypted` TEXT NULL',
  'SELECT ''partner_connections.auth_key_encrypted already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'partner_connections' AND COLUMN_NAME = 'pagination'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `partner_connections` ADD COLUMN `pagination` JSON NULL',
  'SELECT ''partner_connections.pagination already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'partner_connections' AND COLUMN_NAME = 'items_path'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `partner_connections` ADD COLUMN `items_path` VARCHAR(191) NULL DEFAULT NULL',
  'SELECT ''partner_connections.items_path already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'withdrawals' AND COLUMN_NAME = 'partner_ledger_transaction_id'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `withdrawals` ADD COLUMN `partner_ledger_transaction_id` BIGINT UNSIGNED NULL DEFAULT NULL',
  'SELECT ''withdrawals.partner_ledger_transaction_id already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `partner_api_keys` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `partner_id` bigint unsigned NOT NULL,
  `name` varchar(120) NOT NULL,
  `prefix` varchar(24) NOT NULL,
  `key_hash` varchar(64) NOT NULL,
  `scopes` json DEFAULT NULL,
  `mode` varchar(12) NOT NULL DEFAULT 'live',
  `last_used_at` timestamp NULL DEFAULT NULL,
  `last_used_ip` varchar(45) DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `partner_api_keys_key_hash_unique` (`key_hash`),
  KEY `partner_api_keys_partner_id_revoked_at_index` (`partner_id`,`revoked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `partner_webhook_endpoints` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `partner_id` bigint unsigned NOT NULL,
  `url` text NOT NULL,
  `events` json DEFAULT NULL,
  `secret_encrypted` text NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'enabled',
  `failure_streak` int unsigned NOT NULL DEFAULT 0,
  `disabled_at` timestamp NULL DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `partner_webhook_endpoints_partner_id_index` (`partner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `partner_webhook_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `partner_id` bigint unsigned NOT NULL,
  `public_id` varchar(40) DEFAULT NULL,
  `type` varchar(80) NOT NULL,
  `payload` json NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `partner_webhook_events_partner_id_type_index` (`partner_id`,`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `partner_webhook_deliveries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `event_id` bigint unsigned NOT NULL,
  `endpoint_id` bigint unsigned NOT NULL,
  `attempt` int unsigned NOT NULL DEFAULT 0,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `response_code` smallint unsigned DEFAULT NULL,
  `error` text DEFAULT NULL,
  `next_attempt_at` timestamp NULL DEFAULT NULL,
  `delivered_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `partner_webhook_deliveries_status_next_attempt_at_index` (`status`,`next_attempt_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `partner_idempotency_keys` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `partner_id` bigint unsigned NOT NULL,
  `key` varchar(191) NOT NULL,
  `request_hash` varchar(64) NOT NULL,
  `response_code` smallint unsigned NOT NULL,
  `response_body` longtext NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `partner_idempotency_keys_partner_id_key_unique` (`partner_id`,`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `partner_order_messages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `order_item_id` bigint unsigned NOT NULL,
  `author_type` varchar(20) NOT NULL,
  `author_id` bigint unsigned DEFAULT NULL,
  `item_type` varchar(32) NOT NULL DEFAULT 'message',
  `visibility` varchar(20) NOT NULL DEFAULT 'all',
  `body_masked` text NOT NULL,
  `body_original_encrypted` text DEFAULT NULL,
  `masked` tinyint(1) NOT NULL DEFAULT 0,
  `mask_rules` json DEFAULT NULL,
  `relay_status` varchar(32) NOT NULL DEFAULT 'relayed',
  `edited_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `partner_order_messages_order_item_id_id_index` (`order_item_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `partner_order_attachments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `message_id` bigint unsigned DEFAULT NULL,
  `order_item_id` bigint unsigned NOT NULL,
  `uploaded_by` bigint unsigned DEFAULT NULL,
  `filename_original` varchar(255) NOT NULL,
  `filename_masked` varchar(255) NOT NULL,
  `mime` varchar(120) DEFAULT NULL,
  `size` int unsigned NOT NULL DEFAULT 0,
  `storage_path` varchar(255) NOT NULL,
  `scan_status` varchar(20) NOT NULL DEFAULT 'skipped',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `partner_order_attachments_order_item_id_index` (`order_item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `partner_order_corrections` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `order_item_id` bigint unsigned NOT NULL,
  `number` tinyint unsigned NOT NULL DEFAULT 1,
  `instructions` text NOT NULL,
  `requested_by_message_id` bigint unsigned DEFAULT NULL,
  `relayed_by` bigint unsigned DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending_relay',
  `due_by` timestamp NULL DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `decline_reason` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `partner_order_corrections_order_item_id_status_index` (`order_item_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `partner_order_disputes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `order_item_id` bigint unsigned NOT NULL,
  `opened_by_type` varchar(32) NOT NULL,
  `correction_id` bigint unsigned DEFAULT NULL,
  `reason` varchar(80) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'open',
  `decided_by` bigint unsigned DEFAULT NULL,
  `decided_at` timestamp NULL DEFAULT NULL,
  `decision_note` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `partner_order_disputes_order_item_id_status_index` (`order_item_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `partner_link_incidents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `order_item_id` bigint unsigned NOT NULL,
  `partner_id` bigint unsigned NOT NULL,
  `failure_code` varchar(40) DEFAULT NULL,
  `restore_by` timestamp NULL DEFAULT NULL,
  `restored_at` timestamp NULL DEFAULT NULL,
  `resolved` varchar(20) DEFAULT NULL,
  `fail_count` int unsigned NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `partner_link_incidents_order_item_id_resolved_index` (`order_item_id`,`resolved`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `partner_ledger_accounts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(80) NOT NULL,
  `owner_type` varchar(20) NOT NULL,
  `owner_id` bigint unsigned DEFAULT NULL,
  `currency` varchar(3) NOT NULL DEFAULT 'EUR',
  `allow_negative` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `partner_ledger_accounts_code_unique` (`code`),
  KEY `partner_ledger_accounts_owner_type_owner_id_index` (`owner_type`,`owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `partner_ledger_transactions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(40) NOT NULL,
  `order_item_id` bigint unsigned DEFAULT NULL,
  `payout_id` bigint unsigned DEFAULT NULL,
  `idempotency_key` varchar(80) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `partner_ledger_transactions_idempotency_key_unique` (`idempotency_key`),
  KEY `partner_ledger_transactions_order_item_id_index` (`order_item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `partner_ledger_entries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `transaction_id` bigint unsigned NOT NULL,
  `account_id` bigint unsigned NOT NULL,
  `direction` varchar(8) NOT NULL,
  `amount_minor` int unsigned NOT NULL,
  `currency` varchar(3) NOT NULL DEFAULT 'EUR',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `partner_ledger_entries_account_id_id_index` (`account_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `partner_ledger_balances` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint unsigned NOT NULL,
  `balance_minor` int NOT NULL DEFAULT 0,
  `version` int unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `partner_ledger_balances_account_id_unique` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `partner_clawbacks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `order_item_id` bigint unsigned NOT NULL,
  `partner_id` bigint unsigned NOT NULL,
  `amount_minor` int unsigned NOT NULL,
  `reason` varchar(40) NOT NULL,
  `incident_id` bigint unsigned DEFAULT NULL,
  `dispute_id` bigint unsigned DEFAULT NULL,
  `ledger_transaction_id` bigint unsigned DEFAULT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'open',
  `opened_at` timestamp NULL DEFAULT NULL,
  `recovered_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `partner_clawbacks_partner_id_status_index` (`partner_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `partner_statements` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `partner_id` bigint unsigned NOT NULL,
  `period` varchar(7) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'final',
  `totals` json DEFAULT NULL,
  `lines` json DEFAULT NULL,
  `issued_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `partner_statements_partner_id_period_unique` (`partner_id`,`period`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `partner_payout_mode_acceptances` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `partner_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `terms_type` varchar(40) NOT NULL,
  `terms_version` varchar(40) NOT NULL,
  `terms_text_sha256` varchar(64) DEFAULT NULL,
  `keep_live_months` tinyint unsigned DEFAULT NULL,
  `accepted_at` timestamp NULL DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `method` varchar(32) NOT NULL DEFAULT 'click_accept',
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `revoked_reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `partner_payout_mode_acceptances_partner_id_index` (`partner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `partner_payout_mode_changes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `partner_id` bigint unsigned NOT NULL,
  `from_mode` varchar(20) DEFAULT NULL,
  `to_mode` varchar(20) NOT NULL,
  `reason` text DEFAULT NULL,
  `requested_by` bigint unsigned DEFAULT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `second_approver` bigint unsigned DEFAULT NULL,
  `effective_from` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `partner_payout_mode_changes_partner_id_index` (`partner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
