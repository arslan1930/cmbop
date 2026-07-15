# AGENTS.md

## Cursor Cloud specific instructions

### What this app is
A Laravel 13 (PHP 8.3) web app — a "Niche" backlink / guest‑post **marketplace** with three roles (advertiser, publisher, admin). Server‑rendered Blade views styled with Bootstrap, with a Vite + Tailwind asset pipeline. Payments use Stripe. It is a single application (not a monorepo).

### Database: MySQL is required (not SQLite)
Although `.env.example` defaults to `sqlite`, the migrations contain **MySQL‑specific raw SQL** (e.g. `ALTER TABLE ... MODIFY COLUMN ... ENUM(...)` in `2026_05_03_064024_add_refunded_to_payment_status.php`) and foreign keys that assume MySQL. The app must run on MySQL/MariaDB.

The dev DB used during setup: database `niche`, user `niche`, password `niche` (host `127.0.0.1:3306`). These values are already written into the (gitignored) `.env`.

MySQL is installed in the VM image but is **not** started automatically (no systemd). Start it before running the app, migrations, or tests that hit the real DB:

```bash
sudo service mysql start   # or: sudo mysqld_safe &
```

If the `niche` database/user is missing after a fresh VM, recreate it:

```bash
sudo mysql -e "CREATE DATABASE IF NOT EXISTS niche CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'niche'@'127.0.0.1' IDENTIFIED BY 'niche';
CREATE USER IF NOT EXISTS 'niche'@'localhost' IDENTIFIED BY 'niche';
GRANT ALL PRIVILEGES ON niche.* TO 'niche'@'127.0.0.1';
GRANT ALL PRIVILEGES ON niche.* TO 'niche'@'localhost'; FLUSH PRIVILEGES;"
```

### First‑time app setup (not done by the update script)
The update script only refreshes dependencies. After the VM is up, do the one‑time app setup if `.env`/DB are not already present:

```bash
cp .env.example .env          # only if .env is missing
php artisan key:generate      # only if APP_KEY is empty
php artisan migrate           # runs after MySQL is started
php artisan db:seed --class=RolesTableSeeder   # REQUIRED: roles are NOT in DatabaseSeeder
php artisan db:seed           # countries / languages / categories / country_language
```

Gotcha: `DatabaseSeeder` does **not** seed the `roles` table, but registration and login both look up the `advertiser`/`publisher`/`admin` roles. Always run `RolesTableSeeder` or new users cannot be created / logged in.

### Auth requires reCAPTCHA + verified email
Both `/login` and `/register` require a Google reCAPTCHA token that is verified **server‑side** against `https://www.google.com/recaptcha/api/siteverify` (needs outbound network). For local dev, `.env` is configured with Google's official reCAPTCHA v2 **test keys** (`GOOGLE_RECAPTCHA_SITE_KEY` / `GOOGLE_RECAPTCHA_SECRET_KEY`) which always pass — the browser shows a "for testing purposes only" banner; just click the checkbox. Login also enforces `hasVerifiedEmail()`.

To get a usable logged‑in account without email delivery, create a verified user via tinker (mail is set to `log`, so no real email is sent). A demo advertiser used during setup:
- email `advertiser@example.com`, password `Password123!` (verified, has advertiser+publisher roles and wallets).

### Running in development
- All‑in‑one (server + queue + logs + vite): `composer run dev`
- Or individually: `php artisan serve --host=0.0.0.0 --port=8000` and `npm run dev` (Vite dev server on :5173).
- `npm run build` produces `public/build` assets; Blade `@vite` uses the built manifest when the Vite dev server is not running.

### Lint / test / build
- Lint: `./vendor/bin/pint` (use `--test` to check without fixing). Note: the existing codebase is **not** currently Pint‑clean, so `pint --test` reports many pre‑existing style deviations — this is expected, not a regression.
- Tests: `php artisan test`. The test suite is configured (in `phpunit.xml`) to use `sqlite :memory:` and currently only contains the default example tests (they do not run the MySQL‑specific migrations).
- Build: `npm run build`.
