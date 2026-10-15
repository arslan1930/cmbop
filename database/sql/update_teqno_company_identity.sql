-- Production helper (Hostinger / phpMyAdmin): switch leftover Topurlz Ltd
-- (16607074) identity rows to Teqno Ltd (17392540).
--
-- The live app reads company identity from env/config, not from a settings
-- table in this repo. These statements no-op when `settings` is missing.
-- Do NOT update invoices, invoice sequences, or stored PDFs.
--
-- Safe to re-run. Review the preview SELECTs before relying on the UPDATEs.

USE u841082722_cmbop_db;

-- ---------------------------------------------------------------------------
-- 1) Preview: is there a settings / options table?
-- ---------------------------------------------------------------------------
SELECT TABLE_SCHEMA, TABLE_NAME
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('settings', 'options', 'configs', 'app_settings', 'site_settings');

-- ---------------------------------------------------------------------------
-- 2) Preview leftover identity values in `settings` (key/value), if present.
-- ---------------------------------------------------------------------------
SET @settings_exists := (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'settings'
);

SET @preview_sql := IF(
    @settings_exists = 0,
    'SELECT ''settings table not present — company identity is env/config only'' AS info',
    'SELECT id, `key`, `value` FROM `settings` WHERE `key` IN (''BILLING_REGISTRATION_NO'', ''COMPANY_REGISTRATION_NO'', ''COMPANY_LEGAL_NAME'', ''BILLING_LEGAL_NAME'', ''billing.company.registration_no'', ''billing.company.legal_name'', ''company.registration_no'', ''company.legal_name'') OR `value` LIKE ''%16607074%'' OR `value` LIKE ''%Topurlz%'' OR `value` LIKE ''%TopURLz%'' OR `value` LIKE ''%TOPURLZ%'''
);

PREPARE preview_stmt FROM @preview_sql;
EXECUTE preview_stmt;
DEALLOCATE PREPARE preview_stmt;

-- ---------------------------------------------------------------------------
-- 3) Update leftover settings keys / values. Address columns are not touched.
-- ---------------------------------------------------------------------------
SET @update_reg_sql := IF(
    @settings_exists = 0,
    'SELECT ''skip registration UPDATE — no settings table'' AS info',
    'UPDATE `settings` SET `value` = ''17392540'' WHERE `key` IN (''BILLING_REGISTRATION_NO'', ''COMPANY_REGISTRATION_NO'', ''billing.company.registration_no'', ''company.registration_no'') AND `value` = ''16607074'''
);

PREPARE update_reg_stmt FROM @update_reg_sql;
EXECUTE update_reg_stmt;
DEALLOCATE PREPARE update_reg_stmt;

SET @update_name_sql := IF(
    @settings_exists = 0,
    'SELECT ''skip legal-name UPDATE — no settings table'' AS info',
    'UPDATE `settings` SET `value` = ''Teqno Ltd'' WHERE `key` IN (''COMPANY_LEGAL_NAME'', ''company.legal_name'') AND (`value` LIKE ''%Topurlz%'' OR `value` LIKE ''%TopURLz%'' OR `value` LIKE ''%TOPURLZ%'')'
);

PREPARE update_name_stmt FROM @update_name_sql;
EXECUTE update_name_stmt;
DEALLOCATE PREPARE update_name_stmt;

-- Keep the invoice seller stamp ("SEOLinkBuildings Partners with (Teqno LTD)")
-- if that key is already Teqno. Only rewrite leftover Topurlz legal names.
SET @update_billing_sql := IF(
    @settings_exists = 0,
    'SELECT ''skip billing legal-name UPDATE — no settings table'' AS info',
    'UPDATE `settings` SET `value` = ''SEOLinkBuildings Partners with (Teqno LTD)'' WHERE `key` IN (''BILLING_LEGAL_NAME'', ''billing.company.legal_name'') AND (`value` LIKE ''%Topurlz%'' OR `value` LIKE ''%TopURLz%'' OR `value` LIKE ''%TOPURLZ%'')'
);

PREPARE update_billing_stmt FROM @update_billing_sql;
EXECUTE update_billing_stmt;
DEALLOCATE PREPARE update_billing_stmt;

-- ---------------------------------------------------------------------------
-- 4) Preview only: leftover number still stored on issued invoices.
--    Do NOT UPDATE these rows. Already-issued PDFs / numbers stay as written.
-- ---------------------------------------------------------------------------
SELECT id, invoice_number, type, status, created_at
FROM `invoices`
WHERE notes LIKE '%16607074%'
   OR notes LIKE '%Topurlz%'
   OR CAST(billing_snapshot AS CHAR) LIKE '%16607074%'
   OR CAST(meta AS CHAR) LIKE '%16607074%'
LIMIT 50;
