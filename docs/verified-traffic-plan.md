# Verified traffic (GA4 / Search Console)

Live feature. Advertisers see one monthly-visits number (`sites.traffic`) plus a **Verified traffic** badge when Google last succeeded recently. No last-sync date, no Google email, no publisher payout/markup, and no “via GA4 / via GSC” subtitle on the catalog.

This supersedes draft PR #1355 (preview-only mockups). Preview routes and `VERIFIED_TRAFFIC_PREVIEW` are not part of this build.

## Locked decisions

- Hide the grey Manual chip on catalog entirely (no tag for non-verified sites).
- Badge text is exactly **Verified traffic**. Admin still sees the source (GA4 / GSC / ADMIN / MANUAL).
- If GA4 organic sessions are 0 but total sessions > 0, use total sessions and keep the listing verified.
- Admin override sets `sites.traffic` and **drops** the badge until cleared.
- Login Google OAuth (`GOOGLE_CLIENT_ID`) is unchanged. Traffic uses a separate client (`GOOGLE_TRAFFIC_*`).

## Listing number priority

1. Admin override (badge off)
2. GA4 sessions last 30 days (organic, else total)
3. Search Console clicks last 28 days
4. Publisher manual fallback (`traffic_manual`)

`sites.traffic` remains the catalog number. Filters, quality bar, and mobile cards keep reading that column.

## Env vars

```
GOOGLE_TRAFFIC_CLIENT_ID=
GOOGLE_TRAFFIC_CLIENT_SECRET=
GOOGLE_TRAFFIC_REDIRECT_URI="${APP_URL}/publisher/sites/google/callback"
```

Leave empty to hide Connect Google. Do not reuse `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET`.

If the SQL below has not been run yet, the feature degrades: no 500s, no Connect card, no badge.

## Google Cloud checklist

1. Create a **new** OAuth 2.0 Client ID (Web application), separate from Google sign-in.
2. Enable APIs on that project: **Google Analytics Data API**, **Google Analytics Admin API**, **Search Console API**.
3. OAuth consent screen: External. Scopes:
   - `https://www.googleapis.com/auth/analytics.readonly`
   - `https://www.googleapis.com/auth/webmasters.readonly`
4. Authorised redirect URI: `https://seolinkbuildings.com/publisher/sites/google/callback` (and the staging host if used).
5. App Verification: add the privacy policy Limited Use wording (live on `/privacy-policy`), a demo video of connect → picker → catalog badge, and domain verification. Keep test users until Google approves.
6. Put the client id/secret in Hostinger env as `GOOGLE_TRAFFIC_*`, then `php artisan config:clear`.

## phpMyAdmin SQL (Hostinger)

Run this once if you are not applying the Laravel migration. Skip any statement whose object already exists.

```sql
CREATE TABLE `site_traffic_connections` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `site_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `google_account_email` varchar(255) DEFAULT NULL,
  `google_account_id` varchar(64) DEFAULT NULL,
  `access_token_encrypted` text NOT NULL,
  `refresh_token_encrypted` text DEFAULT NULL,
  `token_expires_at` timestamp NULL DEFAULT NULL,
  `scopes` json DEFAULT NULL,
  `ga4_property_id` varchar(64) DEFAULT NULL,
  `ga4_property_name` varchar(191) DEFAULT NULL,
  `gsc_site_url` varchar(255) DEFAULT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'pending',
  `last_error` varchar(500) DEFAULT NULL,
  `last_synced_at` timestamp NULL DEFAULT NULL,
  `last_notified_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `site_traffic_connections_site_id_unique` (`site_id`),
  KEY `site_traffic_connections_user_id_foreign` (`user_id`),
  KEY `site_traffic_connections_status_synced_index` (`status`,`last_synced_at`),
  CONSTRAINT `site_traffic_connections_site_id_foreign` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE,
  CONSTRAINT `site_traffic_connections_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `sites`
  ADD COLUMN `traffic_manual` int unsigned DEFAULT NULL,
  ADD COLUMN `traffic_ga4` int unsigned DEFAULT NULL,
  ADD COLUMN `traffic_gsc_clicks` int unsigned DEFAULT NULL,
  ADD COLUMN `traffic_admin_override` int unsigned DEFAULT NULL,
  ADD COLUMN `traffic_source` varchar(16) NOT NULL DEFAULT 'manual',
  ADD COLUMN `traffic_verified_at` timestamp NULL DEFAULT NULL;
```

Laravel: `php artisan migrate` (`database/migrations/2026_11_27_140000_add_verified_traffic.php`). The migration is sqlite-safe (no ENUM).

## Sync

- First sync is queued immediately after the publisher saves a matching GA4 and/or GSC property (`SyncSiteVerifiedTraffic` on `default`).
- Weekly: Tuesday 05:00 `sites:sync-verified-traffic` (`withoutOverlapping` + `onOneServer`).
- Manual: `php artisan sites:sync-verified-traffic --site=ID --sync`
- Token failure: keep the last number, clear `traffic_verified_at` (badge off), notify the publisher in-app + email at most once per 24 hours.
- Disconnect revokes Google tokens and restores `traffic_manual`.
