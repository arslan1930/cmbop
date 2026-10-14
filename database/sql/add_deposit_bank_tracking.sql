-- Production helper (Hostinger / phpMyAdmin): bank-transfer tracking on deposits.
-- Safe to re-run. Stores the locked currency rail (gbp_wise / eur_wise),
-- the unique payment reference (SLB-<id>-GBP), and the account money arrived in.

SET @bank_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'deposit_requests'
    AND COLUMN_NAME = 'bank_account'
);

SET @sql_bank := IF(
  @bank_exists = 0,
  'ALTER TABLE `deposit_requests` ADD COLUMN `bank_account` VARCHAR(32) NULL DEFAULT NULL',
  'SELECT ''deposit_requests.bank_account already exists'' AS info'
);

PREPARE stmt FROM @sql_bank;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @pref_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'deposit_requests'
    AND COLUMN_NAME = 'payment_reference'
);

SET @sql_pref := IF(
  @pref_exists = 0,
  'ALTER TABLE `deposit_requests` ADD COLUMN `payment_reference` VARCHAR(48) NULL DEFAULT NULL',
  'SELECT ''deposit_requests.payment_reference already exists'' AS info'
);

PREPARE stmt FROM @sql_pref;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @recv_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'deposit_requests'
    AND COLUMN_NAME = 'received_bank'
);

SET @sql_recv := IF(
  @recv_exists = 0,
  'ALTER TABLE `deposit_requests` ADD COLUMN `received_bank` VARCHAR(32) NULL DEFAULT NULL',
  'SELECT ''deposit_requests.received_bank already exists'' AS info'
);

PREPARE stmt FROM @sql_recv;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'deposit_requests'
    AND INDEX_NAME = 'deposit_requests_payment_reference_unique'
);

SET @sql_idx := IF(
  @idx_exists = 0,
  'ALTER TABLE `deposit_requests` ADD UNIQUE INDEX `deposit_requests_payment_reference_unique` (`payment_reference`)',
  'SELECT ''deposit_requests.payment_reference unique index already exists'' AS info'
);

PREPARE stmt FROM @sql_idx;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Backfill bank rail from the stored charge currency (bank deposits only).
SET @can_backfill_bank := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'deposit_requests'
    AND COLUMN_NAME IN ('bank_account', 'charge_currency', 'payment_method')
);
SET @sql := IF(@can_backfill_bank = 3,
  'UPDATE `deposit_requests` SET `bank_account` = CASE WHEN UPPER(TRIM(COALESCE(`charge_currency`, ''''))) = ''GBP'' THEN ''gbp_wise'' WHEN `payment_method` = ''bank'' THEN ''eur_wise'' ELSE `bank_account` END WHERE `bank_account` IS NULL AND `payment_method` = ''bank''',
  'SELECT ''skip bank_account backfill — missing columns'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill SLB-<id>-<currency> when the reference is still empty.
SET @can_backfill_ref := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'deposit_requests'
    AND COLUMN_NAME IN ('payment_reference', 'charge_currency', 'payment_method')
);
SET @sql := IF(@can_backfill_ref = 3,
  'UPDATE `deposit_requests` SET `payment_reference` = CONCAT(''SLB-'', `id`, ''-'', CASE WHEN UPPER(TRIM(COALESCE(`charge_currency`, ''''))) IN (''GBP'', ''USD'', ''EUR'') THEN UPPER(TRIM(`charge_currency`)) ELSE ''EUR'' END) WHERE `payment_reference` IS NULL AND `payment_method` IN (''bank'', ''wise'', ''crypto'')',
  'SELECT ''skip payment_reference backfill — missing columns'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
