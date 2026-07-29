-- Hostinger / phpMyAdmin: widen sites.onboarding_status
-- Fixes: SQLSTATE[01000] Warning 1265 Data truncated for column 'onboarding_status'
-- Cause: column is ENUM (missing ready_for_review) or VARCHAR shorter than 17 chars.
-- Safe to re-run.

ALTER TABLE `sites`
  MODIFY `onboarding_status` VARCHAR(32) NULL;
