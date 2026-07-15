# AGENTS.md

## Project overview

This is a Laravel 13 / PHP 8.3 application — a two-sided guest-post / backlink
marketplace ("Seolinkbuildings") connecting **advertisers** (buy placements) with
**publishers** (sell placements on their sites), plus an **admin** role. It has an
internal EUR wallet system with optional Stripe card payments. Frontend assets are
built with Vite + Tailwind v4. New advertisers get a €20 welcome bonus.

Standard commands live in `composer.json` (`scripts`) and `package.json`
(`scripts`). Common ones:
- Serve: `php artisan serve`
- Dev (all processes): `composer dev` (serve + queue + pail + vite)
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
Despite `.env.example` defaulting to `DB_CONNECTION=sqlite`, the app requires
**MySQL/MariaDB**. Migration `2026_05_03_064024_add_refunded_to_payment_status`
uses raw MySQL DDL (`ALTER TABLE ... MODIFY COLUMN ... ENUM(...)`) that SQLite
cannot run. The committed `.env` is configured for MySQL (db `laravel`, user
`laravel`, password `secret` on `127.0.0.1:3306`).

MariaDB does not auto-start. Start it each session (it is not in the update script
because the update script must not start services):
```
sudo mysqld_safe --datadir=/var/lib/mysql &
```
The data dir `/var/lib/mysql` persists in the snapshot, so an already-migrated
database is normally still present after restart.

### Migration ordering is broken for a fresh `migrate`
The migration filename timestamps are out of dependency order, so a clean
`php artisan migrate` / `migrate:fresh` FAILS:
- `2024_01_01_000001_create_order_chat_messages_table` has an FK to `orders`
  (created only in `2026_04_21_...`).
- `2024_01_01_000002_add_live_url_to_order_items` alters `order_items`
  (also created in `2026_04_21_...`).

To build the schema from scratch, pre-create the out-of-order tables in dependency
order, then run the rest (do NOT modify the migration files):
```
php artisan db:wipe --force
for m in 0001_01_01_000000_create_users_table \
         2026_04_06_094704_create_sites_table \
         2026_04_21_070134_create_orders_table \
         2026_04_21_070217_create_order_items_table; do
  php artisan migrate --force --path=database/migrations/$m.php
done
php artisan migrate --force
```

### Seeders: roles are NOT in DatabaseSeeder
`php artisan db:seed` only seeds countries/languages/categories. Registration needs
the `roles` table populated, so also run:
```
php artisan db:seed --class=RolesTableSeeder --force
```
There is no default user/admin seeder; an admin must be promoted manually in the DB.

### Auth: reCAPTCHA + email verification
- Login (and forgot-password) verify Google reCAPTCHA **server-side** against
  `google.com/recaptcha/api/siteverify`. The committed `.env` uses Google's official
  **test keys** (`GOOGLE_RECAPTCHA_SITE_KEY` / `GOOGLE_RECAPTCHA_SECRET_KEY`) which
  always validate, so automated/manual login works locally.
- Login is blocked until the email is verified. With `MAIL_MAILER=log`, the
  verification link is written to `storage/logs/laravel.log` (search for
  `email/verify`). Visiting that link (no auth required) verifies the account.

### Frontend assets
Blade uses `@vite`, so `public/build/manifest.json` must exist or pages error. It is
gitignored but persists in the snapshot. If it is missing (or you changed JS/CSS),
run `npm run build` (build is intentionally not in the update script).
