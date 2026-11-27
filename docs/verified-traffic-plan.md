# Verified traffic — build plan (draft)

Owner review of HTML mockups first. **Do not build until this plan is approved.**
This document is the implementation brief. Mockups live at `/preview/verified-traffic`
(local/testing, or `VERIFIED_TRAFFIC_PREVIEW=true` on staging). Production 404s them.

## What already exists

- `sites.traffic` is an unsigned integer, “monthly visits”, required on publisher add/edit.
- Publisher traffic is typed in the My Sites wizard (`publisher.websites`). There is no dedicated site-settings page.
- Catalog shows that number with a spark + generic “Analytics” icon. No Google proof.
- `sites.verified` is **publisher identity** (lottie chip), not traffic. Do not overload it.
- `metrics_provider` / `metrics_manual` / `metrics_fetched_at` are **Ahrefs/Moz/Semrush** enrichment. Leave them alone.
- Login Google OAuth (`GOOGLE_CLIENT_ID`, `/auth/google/callback`, Socialite) is **sign-in only**. Do not add Analytics scopes to it.
- Scheduler already uses `withoutOverlapping` + `onOneServer` (Hostinger process cap). Weekly `sites:enrich` runs Tuesday 04:15.
- Secrets are encrypted with `Crypt::encryptString` (partner webhooks, advertiser webhooks). Same pattern for tokens.
- Mail is queued (`emails` queue). Token-failure notices must go through that path.

## Metric to display

Advertisers see **one integer**: monthly visits, still stored in `sites.traffic` so catalog sort/filter/`GOOD_MIN_TRAFFIC` keep working.

Priority when writing that integer:

1. **Admin override** (`sites.traffic_admin_override` not null) — listing number = override. **No Verified badge** (admin figure is not Google-proven).
2. Else **GA4 organic sessions, last 30 days** (`sessionDefaultChannelGroup` = Organic Search). If organic is 0 but total sessions > 0, fall back to total sessions and still badge as GA4.
3. Else **Search Console clicks, last 28 days** (GSC has no “sessions”; clicks are the honest metric). Badge as GSC.
4. Else **publisher manual** (`sites.traffic_manual`, else current `sites.traffic`). No badge.

Catalog copy stays “monthly visits”. Do **not** show last-sync dates, Google email, GSC clicks, or property IDs to advertisers. Optional later (not in v1): tooltip “via GA4” / “via GSC” — the mockups already show that subtitle.

Publisher + admin **may** see last sync, both raw metrics, and the connected Google email.

## Verified vs manual

A listing is **verified** when:

- no admin override,
- a `site_traffic_connections` row is `connected`,
- `last_synced_at` is within 21 days,
- and the listing number came from GA4 or GSC (not the manual fallback).

If the weekly job fails (revoked token, 401, property gone): keep the last number, clear verified, set status `error`, notify the publisher once per incident (throttle 24h). Manual fallback is **not** written back until the publisher disconnects or an admin clears the connection — that avoids a sudden jump that looks like fraud.

On **disconnect**: restore `sites.traffic` from `traffic_manual` (if set), wipe connection tokens, drop the badge.

## phpMyAdmin SQL (run on MariaDB, not SQLite)

```sql
CREATE TABLE IF NOT EXISTS `site_traffic_connections` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `site_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `google_account_email` VARCHAR(255) NULL,
  `google_account_id` VARCHAR(64) NULL,
  `access_token_encrypted` TEXT NOT NULL,
  `refresh_token_encrypted` TEXT NULL,
  `token_expires_at` DATETIME NULL,
  `scopes` JSON NULL,
  `ga4_property_id` VARCHAR(64) NULL,
  `ga4_property_name` VARCHAR(191) NULL,
  `gsc_site_url` VARCHAR(255) NULL,
  `status` ENUM('pending','connected','error','disconnected') NOT NULL DEFAULT 'pending',
  `last_error` VARCHAR(500) NULL,
  `last_synced_at` DATETIME NULL,
  `last_notified_at` DATETIME NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `site_traffic_connections_site_id_unique` (`site_id`),
  KEY `site_traffic_connections_user_id_index` (`user_id`),
  KEY `site_traffic_connections_status_synced_index` (`status`, `last_synced_at`),
  CONSTRAINT `site_traffic_connections_site_id_foreign`
    FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE,
  CONSTRAINT `site_traffic_connections_user_id_foreign`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `sites`
  ADD COLUMN `traffic_manual` INT UNSIGNED NULL
    COMMENT 'Last publisher-typed monthly visits; restored on Google disconnect'
    AFTER `traffic`,
  ADD COLUMN `traffic_ga4` INT UNSIGNED NULL
    COMMENT 'GA4 sessions last 30 days (organic if available)'
    AFTER `traffic_manual`,
  ADD COLUMN `traffic_gsc_clicks` INT UNSIGNED NULL
    COMMENT 'Search Console clicks last 28 days'
    AFTER `traffic_ga4`,
  ADD COLUMN `traffic_admin_override` INT UNSIGNED NULL
    COMMENT 'When set, listing traffic uses this and Verified badge is off'
    AFTER `traffic_gsc_clicks`,
  ADD COLUMN `traffic_source` ENUM('manual','ga4','gsc','admin') NOT NULL DEFAULT 'manual'
    AFTER `traffic_admin_override`,
  ADD COLUMN `traffic_verified_at` DATETIME NULL
    AFTER `traffic_source`;
```

Keep writing the catalog number into existing `sites.traffic`. Snapshot `traffic_manual` the first time a site connects (if the current source is `manual`).

Laravel migration should mirror this SQL. PHPUnit sqlite: use Schema builder, not ENUM raw DDL (same lesson as other MySQL-only migrations).

## Routes (proposed, not built)

All publisher Google routes under `auth` + publisher role. Separate from `/auth/google/*`.

| Method | Path | Name | Purpose |
|---|---|---|---|
| GET | `/publisher/sites/{site}/google/redirect` | `publisher.sites.google.redirect` | OAuth start, `prompt=consent`, `access_type=offline` |
| GET | `/publisher/sites/google/callback` | `publisher.sites.google.callback` | Token exchange; site id in signed state |
| GET | `/publisher/sites/{site}/google/properties` | `publisher.sites.google.properties` | List matching GA4 + GSC |
| POST | `/publisher/sites/{site}/google/properties` | `publisher.sites.google.properties.save` | Save picks + first sync |
| POST | `/publisher/sites/{site}/google/disconnect` | `publisher.sites.google.disconnect` | Revoke + wipe tokens |
| POST | `/admin/sites/{id}/traffic-override` | `admin.sites.traffic-override` | Set/clear override |

Catalog needs **no new advertiser route**. Badge is a Blade partial next to `catalog-metric` for traffic.

Preview mockups (this PR only): `/preview/verified-traffic/*`.

## Jobs / scheduler

- `App\Jobs\SyncSiteVerifiedTraffic` — one site. Refresh token, GA4 Data API runReport, optional GSC searchAnalytics.query, update columns, set `traffic_source`.
- `App\Console\Commands\SyncVerifiedTraffic` (`sites:sync-verified-traffic`) — queued dispatch of stale `connected` rows (`last_synced_at` null or older than 6 days). Limit + chunk so Hostinger’s process cap is safe.
- Schedule next to `sites:enrich`: **Tuesday 05:00** (after enrichment), `withoutOverlapping` + `onOneServer`, mutex ~180 min.
- Queue: `default` (not `emails`). Failure mail: `Mail::to` existing `PlatformMailable` on `emails`.
- First sync: dispatch the job from the properties-save request (do not block the HTTP request on Google).

## UI placement

- **Publisher:** a card on My Sites, inside the existing add/edit wizard after the Traffic field, and on the site row (Connected / Connect). Mockups use a dedicated edit-site frame because the live UI is a modal.
- **Catalog table + mobile card + details expand:** badge under the traffic spark. Do **not** put it in the identity `site-chip--verified` slot (that is publisher verification).
- **Admin site edit:** panel under Traffic, distinct from SEO manual lock. Sites list: optional tiny “GA4”/“GSC”/“manual” mark on `row-traffic` (admin only).

Manual chip on catalog: **recommended off** in v1. Mockups show a grey “Manual” chip so review can compare; production should only add chrome when verified.

## Security

- **Second OAuth client** (`GOOGLE_TRAFFIC_*`). Login client stays identity-only.
- Scopes: `https://www.googleapis.com/auth/analytics.readonly` and `https://www.googleapis.com/auth/webmasters.readonly` only. No write, no ads, no Gmail.
- Encrypt access + refresh tokens with `Crypt::encryptString`. Never send them to Blade, logs, or ActivityLogger.
- Hide `access_token_encrypted` / `refresh_token_encrypted` on the model.
- State CSRF: signed payload `{site_id, user_id, nonce}` in session. Reject if site is not owned by the user.
- Property hostname must match `sites.domain` (registrable domain, ignore www). Reject mismatches.
- Rate-limit redirect + callback (e.g. 10/hour/user).
- Disconnect revokes at Google (`oauth2.googleapis.com/revoke`) then deletes ciphertext.
- Advertisers never see publisher price/markup (unchanged `catalogPricesForViewer`). Never expose Google email, tokens, or raw API JSON on catalog.
- Do not change payments, login, or wallet code.
- Partner dashboard host must not reuse these routes unless we explicitly add `partners.` later.

## Edge cases

- Site has GA4 but no GSC (or the reverse): one source is enough.
- GA4 property is a rollup of several hosts: block unless the data stream hostname matches.
- Publisher connects a Google account that does not contain this domain: picker is empty; keep manual traffic.
- Refresh token missing (Google only sends it on first consent): force `prompt=consent`.
- Token revoked: graceful fallback + notify. Do not 500 catalog.
- Admin override while connected: keep the connection for later, but listing uses override and drops badge.
- Two sites, one Google account: allowed. Tokens stored per site (duplicate ciphertext is OK).
- Archived / inactive site: skip the weekly job.
- Traffic 0 from Google: still verified if the API succeeded; do not treat 0 as failure.
- Huge properties: GA4 Data API quota. Job must catch 429 and retry with backoff.
- SQLite tests: fake HTTP to Google; never call live APIs in PHPUnit.

## i18n

English keys in `resources/lang/en/messages.php` (and `at`/`ch` if those screens are public — they are not; publisher/admin/catalog SaaS is English). Suggested keys: `verified_traffic_badge`, `verified_traffic_via_ga4`, `verified_traffic_via_gsc`, `verified_traffic_connect`, `verified_traffic_disconnect`. Do not edit public marketing copy that already says “verified organic traffic” on pricing cards until the feature ships.

## Privacy policy (required for Google verification)

Add a subsection under “Information We Collect” / “Trusted Third-Party Providers”:

> **Google Analytics and Search Console (publishers)**
> If you are a publisher and you choose to connect Google, we request read-only access to Google Analytics and Search Console for the website you list. We store encrypted access tokens on our servers. We use this access only to read aggregate monthly traffic (sessions and/or search clicks) for that website, to show a “Verified traffic” mark on your listing, and to refresh that figure about once a week. We do not change your Google account, we do not read page-level reports for advertisers, and we do not sell this data. You can disconnect at any time in My Sites; we then delete the tokens and the listing falls back to the traffic figure you typed. Google’s use of data is described at https://policies.google.com/privacy and https://policies.google.com/terms.

Also name Google as a processor in section 3 (already has “Analytics providers”).

## Google Cloud setup (owner)

1. Open [Google Cloud Console](https://console.cloud.google.com/) and create (or reuse) a project, e.g. `seolinkbuildings-publisher-traffic`. Prefer a **new** project so login OAuth stays isolated.
2. Enable APIs:
   - **Google Analytics Data API** (`analyticsdata.googleapis.com`) — sessions/users.
   - **Google Analytics Admin API** (`analyticsadmin.googleapis.com`) — list GA4 properties.
   - **Search Console API** (`searchconsole.googleapis.com`) — list sites + query clicks.
3. OAuth consent screen:
   - User type **External**.
   - App name: SEOLinkBuildings.
   - Support email + developer contact: owner inbox.
   - App domain: `seolinkbuildings.com`.
   - Homepage: `https://seolinkbuildings.com`
   - Privacy: `https://seolinkbuildings.com/privacy-policy`
   - Terms: `https://seolinkbuildings.com/terms`
   - Authorized domains: `seolinkbuildings.com`
   - Scopes: `analytics.readonly`, `webmasters.readonly` (both **sensitive**).
   - Test users: owner Google account + 1–2 publisher testers (100 user cap while in Testing).
4. Credentials → Create OAuth client ID → **Web application**:
   - Name: `SEOLinkBuildings publisher traffic`
   - Authorized JavaScript origins:
     - `https://seolinkbuildings.com`
     - `http://127.0.0.1:8000` (local)
     - `http://localhost:8000` (local)
   - Authorized redirect URIs:
     - `https://seolinkbuildings.com/publisher/sites/google/callback`
     - `http://127.0.0.1:8000/publisher/sites/google/callback`
     - `http://localhost:8000/publisher/sites/google/callback`
   - Do **not** put `/auth/google/callback` on this client.
5. Copy Client ID + Secret into Hostinger env (see below). Never commit them.
6. **Verification** (needed before any publisher who is not a test user can connect):
   - Privacy policy wording above must be live.
   - Homepage + terms live on the same domain.
   - **Demo video** (unlisted YouTube): show a publisher clicking Connect, Google consent with the two read-only scopes visible, property picker, catalog badge, disconnect. Google reviewers will reject a video that only shows login.
   - **Domain verification** in Search Console for `seolinkbuildings.com` (DNS TXT or HTML tag; the app already supports `GOOGLE_SITE_VERIFICATION`).
   - Justify sensitive scopes: “Read-only aggregate traffic so marketplace listings can show a Verified traffic mark. No write access, no ads, no Gmail.”
   - Brand verification (logo) if you want the app name/logo on the consent screen.
   - Review often takes 3–6 weeks. Until then, keep the consent screen in **Testing**.

## Env vars

```
# Separate from GOOGLE_CLIENT_ID (login). Do not reuse login secrets here.
GOOGLE_TRAFFIC_CLIENT_ID=
GOOGLE_TRAFFIC_CLIENT_SECRET=
GOOGLE_TRAFFIC_REDIRECT_URI="${APP_URL}/publisher/sites/google/callback"

# Staging-only: show the HTML mockups on a non-local host. Leave unset in production.
# VERIFIED_TRAFFIC_PREVIEW=false
```

`config/services.php` should grow a `google_traffic` array. Login `google` array stays untouched.

## Out of scope for v1

- Showing GSC clicks or last-sync dates on the catalog.
- Exposing publisher price/markup.
- Changing payments, login Google, or SEO enrichment.
- Partner-dashboard Google connect.
- Historical traffic charts (the catalog spark stays decorative).
- Forcing every publisher to connect (manual remains valid).

## Approval checklist

- [ ] Badge copy and “via GA4 / via GSC” subtitle
- [ ] Hide vs show grey Manual chip on catalog
- [ ] Admin override drops the badge (recommended)
- [ ] Organic-only vs total sessions fallback
- [ ] Owner completes Google Cloud + verification
- [ ] Then a separate build PR (migrations, OAuth, jobs)
