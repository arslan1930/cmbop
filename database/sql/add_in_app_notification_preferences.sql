-- In-app (bell) notification category preferences.
-- Laravel twin: database/migrations/2026_10_29_120000_create_in_app_notification_preferences_table.php
-- Safe to re-run. Additive only. App code defaults to "enabled" when this
-- table is missing, so deploy can land before you run the SQL in phpMyAdmin.
-- Do not run against production from the cloud agent — owner applies it.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `in_app_notification_preferences` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `category` VARCHAR(32) NOT NULL,
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `in_app_notif_prefs_user_category_unique` (`user_id`, `category`),
  KEY `in_app_notification_preferences_user_id_enabled_index` (`user_id`, `enabled`),
  CONSTRAINT `in_app_notification_preferences_user_id_foreign`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
