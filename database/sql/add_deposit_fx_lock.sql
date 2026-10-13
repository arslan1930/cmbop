-- Production helper (Hostinger / phpMyAdmin): bank-transfer FX lock on deposits.
-- Safe to re-run. Ledger amount stays EUR; fx_rate is charge-currency units per €1.

SET @fx_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'deposit_requests'
    AND COLUMN_NAME = 'fx_rate'
);

SET @sql_fx := IF(
  @fx_exists = 0,
  'ALTER TABLE `deposit_requests` ADD COLUMN `fx_rate` DECIMAL(18,8) NULL DEFAULT NULL',
  'SELECT ''deposit_requests.fx_rate already exists'' AS info'
);

PREPARE stmt FROM @sql_fx;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @lock_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'deposit_requests'
    AND COLUMN_NAME = 'rate_locked_until'
);

SET @sql_lock := IF(
  @lock_exists = 0,
  'ALTER TABLE `deposit_requests` ADD COLUMN `rate_locked_until` TIMESTAMP NULL DEFAULT NULL',
  'SELECT ''deposit_requests.rate_locked_until already exists'' AS info'
);

PREPARE stmt FROM @sql_lock;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
