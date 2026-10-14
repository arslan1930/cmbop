-- One-time country cleanup (Hostinger / phpMyAdmin).
-- Canonical value is lowercase ISO-2 (`uk` not `gb`). Preview SELECTs first,
-- then run the UPDATEs. Safe to re-run; already-canonical rows are no-ops.
--
-- Application code also normalizes on save via App\Support\CountryCode.

USE u841082722_cmbop_db;

-- ========== PREVIEW: users ==========
SELECT id, country AS raw_country,
  CASE
    WHEN LOWER(TRIM(country)) IN ('gb', 'gbr', 'united kingdom', 'great britain', 'uk') THEN 'uk'
    WHEN LOWER(TRIM(country)) IN ('es', 'esp', 'spain', 'españa', 'espana') THEN 'es'
    WHEN LOWER(TRIM(country)) IN ('it', 'ita', 'italy', 'italia') THEN 'it'
    WHEN LOWER(TRIM(country)) IN ('de', 'deu', 'germany', 'deutschland') THEN 'de'
    WHEN LOWER(TRIM(country)) IN ('fr', 'fra', 'france') THEN 'fr'
    WHEN LOWER(TRIM(country)) IN ('us', 'usa', 'united states', 'united states of america') THEN 'us'
    WHEN LOWER(TRIM(country)) IN ('nl', 'nld', 'netherlands', 'holland', 'nederland') THEN 'nl'
    WHEN LOWER(TRIM(country)) IN ('pl', 'pol', 'poland', 'polska') THEN 'pl'
    WHEN LOWER(TRIM(country)) IN ('pt', 'portugal') THEN 'pt'
    WHEN LOWER(TRIM(country)) IN ('ie', 'ireland') THEN 'ie'
    WHEN LOWER(TRIM(country)) IN ('be', 'belgium') THEN 'be'
    WHEN LOWER(TRIM(country)) IN ('at', 'austria') THEN 'at'
    WHEN LOWER(TRIM(country)) IN ('ch', 'switzerland') THEN 'ch'
    WHEN LOWER(TRIM(country)) IN ('ca', 'canada') THEN 'ca'
    WHEN LOWER(TRIM(country)) IN ('au', 'australia') THEN 'au'
    WHEN LOWER(TRIM(country)) IN ('in', 'india') THEN 'in'
    WHEN CHAR_LENGTH(LOWER(TRIM(country))) = 2 THEN LOWER(TRIM(country))
    ELSE NULL
  END AS normalized
FROM users
WHERE country IS NOT NULL AND TRIM(country) <> ''
  AND LOWER(TRIM(country)) <> COALESCE(
    CASE
      WHEN LOWER(TRIM(country)) IN ('gb', 'gbr', 'united kingdom', 'great britain', 'uk') THEN 'uk'
      WHEN LOWER(TRIM(country)) IN ('es', 'esp', 'spain', 'españa', 'espana') THEN 'es'
      WHEN LOWER(TRIM(country)) IN ('it', 'ita', 'italy', 'italia') THEN 'it'
      WHEN LOWER(TRIM(country)) IN ('de', 'deu', 'germany', 'deutschland') THEN 'de'
      WHEN LOWER(TRIM(country)) IN ('fr', 'fra', 'france') THEN 'fr'
      WHEN LOWER(TRIM(country)) IN ('us', 'usa', 'united states', 'united states of america') THEN 'us'
      WHEN LOWER(TRIM(country)) IN ('nl', 'nld', 'netherlands', 'holland', 'nederland') THEN 'nl'
      WHEN LOWER(TRIM(country)) IN ('pl', 'pol', 'poland', 'polska') THEN 'pl'
      WHEN CHAR_LENGTH(LOWER(TRIM(country))) = 2 THEN LOWER(TRIM(country))
      ELSE NULL
    END, ''
  )
ORDER BY id;

-- ========== PREVIEW: sites ==========
SELECT id, country AS raw_country, domain,
  CASE
    WHEN LOWER(TRIM(country)) IN ('gb', 'gbr', 'united kingdom', 'great britain', 'uk') THEN 'uk'
    WHEN LOWER(TRIM(country)) IN ('es', 'esp', 'spain', 'españa', 'espana') THEN 'es'
    WHEN LOWER(TRIM(country)) IN ('it', 'ita', 'italy', 'italia') THEN 'it'
    WHEN LOWER(TRIM(country)) IN ('de', 'deu', 'germany', 'deutschland') THEN 'de'
    WHEN LOWER(TRIM(country)) IN ('fr', 'fra', 'france') THEN 'fr'
    WHEN LOWER(TRIM(country)) IN ('us', 'usa', 'united states', 'united states of america') THEN 'us'
    WHEN CHAR_LENGTH(LOWER(TRIM(country))) = 2 THEN LOWER(TRIM(country))
    ELSE NULL
  END AS normalized
FROM sites
WHERE country IS NOT NULL AND TRIM(country) <> ''
ORDER BY id;

-- ========== UPDATE: users (run after reviewing the preview) ==========
UPDATE users
SET country = CASE
    WHEN LOWER(TRIM(country)) IN ('gb', 'gbr', 'united kingdom', 'great britain', 'uk') THEN 'uk'
    WHEN LOWER(TRIM(country)) IN ('es', 'esp', 'spain', 'españa', 'espana') THEN 'es'
    WHEN LOWER(TRIM(country)) IN ('it', 'ita', 'italy', 'italia') THEN 'it'
    WHEN LOWER(TRIM(country)) IN ('de', 'deu', 'germany', 'deutschland') THEN 'de'
    WHEN LOWER(TRIM(country)) IN ('fr', 'fra', 'france') THEN 'fr'
    WHEN LOWER(TRIM(country)) IN ('us', 'usa', 'united states', 'united states of america') THEN 'us'
    WHEN LOWER(TRIM(country)) IN ('nl', 'nld', 'netherlands', 'holland', 'nederland') THEN 'nl'
    WHEN LOWER(TRIM(country)) IN ('pl', 'pol', 'poland', 'polska') THEN 'pl'
    WHEN LOWER(TRIM(country)) IN ('pt', 'portugal') THEN 'pt'
    WHEN LOWER(TRIM(country)) IN ('ie', 'ireland') THEN 'ie'
    WHEN LOWER(TRIM(country)) IN ('be', 'belgium') THEN 'be'
    WHEN LOWER(TRIM(country)) IN ('at', 'austria') THEN 'at'
    WHEN LOWER(TRIM(country)) IN ('ch', 'switzerland') THEN 'ch'
    WHEN LOWER(TRIM(country)) IN ('ca', 'canada') THEN 'ca'
    WHEN LOWER(TRIM(country)) IN ('au', 'australia') THEN 'au'
    WHEN LOWER(TRIM(country)) IN ('in', 'india') THEN 'in'
    WHEN CHAR_LENGTH(LOWER(TRIM(country))) = 2 THEN LOWER(TRIM(country))
    ELSE country
  END
WHERE country IS NOT NULL AND TRIM(country) <> '';

-- ========== UPDATE: sites ==========
UPDATE sites
SET country = CASE
    WHEN LOWER(TRIM(country)) IN ('gb', 'gbr', 'united kingdom', 'great britain', 'uk') THEN 'uk'
    WHEN LOWER(TRIM(country)) IN ('es', 'esp', 'spain', 'españa', 'espana') THEN 'es'
    WHEN LOWER(TRIM(country)) IN ('it', 'ita', 'italy', 'italia') THEN 'it'
    WHEN LOWER(TRIM(country)) IN ('de', 'deu', 'germany', 'deutschland') THEN 'de'
    WHEN LOWER(TRIM(country)) IN ('fr', 'fra', 'france') THEN 'fr'
    WHEN LOWER(TRIM(country)) IN ('us', 'usa', 'united states', 'united states of america') THEN 'us'
    WHEN LOWER(TRIM(country)) IN ('nl', 'nld', 'netherlands', 'holland', 'nederland') THEN 'nl'
    WHEN LOWER(TRIM(country)) IN ('pl', 'pol', 'poland', 'polska') THEN 'pl'
    WHEN CHAR_LENGTH(LOWER(TRIM(country))) = 2 THEN LOWER(TRIM(country))
    ELSE country
  END
WHERE country IS NOT NULL AND TRIM(country) <> '';
