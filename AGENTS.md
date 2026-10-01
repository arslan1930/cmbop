# AGENTS.md

## Project overview

This is a Laravel 13 / PHP 8.3 application — a two-sided guest-post / backlink
marketplace ("Seolinkbuildings") connecting **advertisers** (buy placements) with
**publishers** (sell placements on their sites), plus an **admin** role. It has an
internal EUR wallet system with optional Stripe card payments. The UI is server-
rendered Blade with hand-written CSS in `public/assets/css` (Bootstrap 5 + jQuery
from CDN); Vite/Tailwind are configured but not wired into any view yet.
New advertisers do not get a welcome bonus unless an admin turns it on under Promotions.

Standard commands live in `composer.json` (`scripts`) and `package.json`
(`scripts`). Common ones:
- Serve: `composer serve` (or `php -d max_input_vars=10000 artisan serve` — needed so marketer bulk Done can post up to 200 site rows; plain `php artisan serve` keeps PHP’s default 1000 and truncates large forms)
- Dev (all processes): `composer dev` (serve + queue + pail + vite)
- Queue worker (required for email): `php artisan queue:work --queue=default,emails`
- Tests: `php artisan test` (or `composer test`)
- Lint: `./vendor/bin/pint` (add `--test` to check without rewriting)
- Build assets: `npm run build`; hot reload: `npm run dev`

## Cursor Cloud specific instructions

The update script installs PHP deps (`composer install`) and JS deps
(`npm install`). PHP 8.3, Composer, Node, and MariaDB are pre-installed in the VM
snapshot. The `.env`, `vendor/`, `node_modules/`, and `public/build/` are gitignored
and persist in the snapshot, so they usually already exist on startup. The notes
below are non-obvious gotchas discovered during setup.

### Database: use MySQL/MariaDB, not SQLite
`.env.example` defaults to **MySQL** (`laravel` / `laravel` / `secret` on
`127.0.0.1:3306`). Several migrations use raw MySQL DDL
(`ALTER TABLE ... MODIFY COLUMN ... ENUM(...)`) that SQLite cannot run.
PHPUnit uses `.env.testing` (sqlite) and is fine.
On Hostinger set `DB_HOST=localhost` (unix socket). `127.0.0.1` is TCP and
fails with `SQLSTATE[HY000] [2002] Operation not permitted`.

MariaDB does not auto-start. Start it each session (it is not in the update script
because the update script must not start services):
```
sudo mysqld_safe --datadir=/var/lib/mysql &
```
The data dir `/var/lib/mysql` persists in the snapshot, so an already-migrated
database is normally still present after restart.

After migrate + seed (or `composer setup`), confirm the launch path:
```
php artisan ops:production-ready --repair
```
`--repair` runs `migrate --force`, seeds roles, sets Hostinger `MEDIA_PATH` /
`APP_URL`, and recreates `public/storage`. Production page views
(`HOSTINGER_WEB_HEAL`, default on) refresh `MEDIA_PATH`, `APP_URL`, and
`public/storage` only. They do not migrate and they do not run the scheduler.
`--strict` fails on warnings too.

### Seeders
`php artisan db:seed` now includes `RolesTableSeeder` (advertiser, publisher,
admin, marketing) plus countries/languages/categories/blogs. Registration fails
with “temporarily unavailable” if roles are missing — run
`php artisan ops:production-ready --repair` or `db:seed --force`.
There is no default user/admin seeder; an admin must be promoted manually in the DB.

### Auth: email verification
- **There is no captcha.** reCAPTCHA was removed: the widget had been commented
  out, the token was never verified server-side, and the page was still loading
  Google's bundle for nothing. Brute-force protection is rate limiting only
  (see `LoginController` / `ForgotPasswordController`), so keep those limits in
  place. Do not reintroduce a captcha without wiring server-side verification.
- Login is blocked until the email is verified. Expired or missing links are
  resent from `/email/verify` (no login required); login has a backup
  “Need a verification email?” control. Links last
  `AUTH_VERIFICATION_EXPIRE_MINUTES` (default 24 hours). With `MAIL_MAILER=log`,
  the verification link is written to `storage/logs/laravel.log` (search for
  `email/verify`). Visiting that link (no auth required) verifies the account.

### Email is queued, not synchronous
`PlatformMailable` implements `ShouldQueue`, so `Mail::to(...)->send(...)` enqueues
rather than sending inline. Mail rides `QUEUE_CONNECTION` (database) on the
**`emails`** queue, so a worker must include that queue or mail silently backs up:
```
php artisan queue:work --queue=default,emails
```
Set `MAIL_QUEUE_CONNECTION=sync` if you need inline delivery without a worker.

### Frontend assets
**No Blade view uses `@vite`.** Styles are plain files under `public/assets/css`
loaded with `asset('assets/css/...')`, so `npm run build` is not required to render
pages. `vite.config.js` and `resources/css|js` exist but are not referenced yet —
leave them alone unless you are deliberately migrating to a bundle.

`public/assets/css` is the **only** stylesheet directory. A byte-identical
`public/css` mirror used to exist; nothing loaded it, so edits silently landed in
the dead copy. It was deleted and `PortalWrappingCssTest` guards against it coming
back. Legacy `/css/*` URLs are served from `assets/css` by a route in `web.php`.

`public/js` **is** live and referenced via `asset('js/...')` — do not remove it.

### Catalog live search kill switch
Advertiser catalog live results (`GET /advertiser/catalog/results`) default **on**.
Set `CATALOG_LIVE_SEARCH=false` in `.env` to force classic full-page navigation and
404 the fragment endpoint (safe rollback without redeploying JS).

### Production mail / reminders
See [`docs/ops-mail-reminders.md`](docs/ops-mail-reminders.md) for `APP_URL` /
`PUBLIC_APP_URL`, `CRON_SECRET`, scheduler vs `/cron/run`, and queue worker vs
`MAIL_QUEUE_AUTO_DRAIN`. Canonical schedule lives in `bootstrap/app.php`.
Admin → Campaigns (queued marketing mail + signed unsubscribe):
[`docs/admin-campaigns.md`](docs/admin-campaigns.md).
Admin → Promotions (notices, banners, welcome bonus, feature credits):
[`docs/admin-promotions.md`](docs/admin-promotions.md).
Admin → Moderation (policy, scan queue, test scan):
[`docs/admin-moderation.md`](docs/admin-moderation.md).
Admin → Content Library (article desk, overrides, archive):
[`docs/admin-content-library.md`](docs/admin-content-library.md).

### Durable media (Hostinger)
Public uploads use the `public` disk (`/storage/...`). Leave `MEDIA_PATH` empty
locally (defaults to `storage/app/public`). On Hostinger the first production
page view (or `--repair`) creates `/home/USER/persistent/media` outside
`public_html` and writes `MEDIA_PATH` so code deploys cannot wipe images.

- Ops runbook (one-time migrate + weekly backup): [`docs/hostinger-media.md`](docs/hostinger-media.md)
- **Pinned every-deploy checklist:** [`docs/deploy-hostinger.md`](docs/deploy-hostinger.md)
