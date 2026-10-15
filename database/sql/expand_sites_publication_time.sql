-- Production helper (Hostinger / phpMyAdmin): allow 2-year and 3-year listing terms.
-- Safe to re-run. No-op when publication_time already accepts 2years/3years
-- or is an unconstrained VARCHAR(20+).
--
-- USE u841082722_cmbop_db;

SET @col_type := (
  SELECT COLUMN_TYPE
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sites'
    AND COLUMN_NAME = 'publication_time'
  LIMIT 1
);

-- ENUM leftover: expand the allowed set (keep existing values).
SET @sql := IF(
  @col_type IS NOT NULL AND @col_type LIKE 'enum%' AND @col_type NOT LIKE '%2years%',
  'ALTER TABLE `sites` MODIFY COLUMN `publication_time` ENUM(''6months'',''1year'',''2years'',''3years'',''permanent'') NULL',
  'SELECT ''sites.publication_time enum already includes 2years/3years or is not an enum'' AS info'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- VARCHAR leftover shorter than the slug length: widen to 20.
SET @col_type := (
  SELECT COLUMN_TYPE
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sites'
    AND COLUMN_NAME = 'publication_time'
  LIMIT 1
);

SET @sql := IF(
  @col_type IS NOT NULL AND @col_type LIKE 'varchar(%'
    AND CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(@col_type, '(', -1), ')', 1) AS UNSIGNED) < 20,
  'ALTER TABLE `sites` MODIFY COLUMN `publication_time` VARCHAR(20) NULL',
  'SELECT ''sites.publication_time varchar already wide enough or is not varchar'' AS info'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
