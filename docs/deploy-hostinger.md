# Hostinger deploy checklist (pinned)

Use this on **every** code update. Full media background:
[`hostinger-media.md`](hostinger-media.md).

## Do not touch

- `/home/USER/persistent/media/**` (all `/storage/...` uploads)
- Live `.env` secrets (DB, Stripe, PayPal, mail). `MEDIA_PATH` and a leftover loopback
  `APP_URL` are written automatically by `--repair` / the first production page view.
- Do not “replace all” in a way that deletes `public/storage` without recreating it

## Split layout (`public_html` + `laravel_app`)

If the domain root is `public_html` and Laravel lives in `../laravel_app`, **every** deploy must overwrite `public_html/assets/` and `public_html/js/` from the repo `public/` folder. Skipping those folders leaves new Blade HTML with 12-day-old CSS (half-styled dashboard). Keep the custom `index.php` (`usePublicPath`); do not replace it with repo `public/index.php`.

Details: [`hostinger-public-html.md`](hostinger-public-html.md). Template: `public/index.hostinger.php`.

## After upload / sync

This agent cannot SSH to live Hostinger. `HOSTINGER_WEB_HEAL` (default on) plus
`php artisan ops:production-ready --repair` cover migrate, `APP_URL`,
`MEDIA_PATH`, roles, `public/storage`, and `schedule:run` without a login.

1. Open any production page (or run `php artisan ops:production-ready --repair`).
   That writes `MEDIA_PATH=/home/USER/persistent/media` when it is empty or still
   under `public_html`, copies `APP_URL` from `PUBLIC_APP_URL` if it is still
   loopback, runs `migrate --force` (bootstrapping users/sites/orders/order_items
   first when the schema is empty), seeds roles, and repairs `public/storage`.
2. `grep '^MEDIA_PATH=' .env` — must be absolute path outside `public_html`
3. `ls -la public/storage` — must symlink to that path; if not:
   `rm -f public/storage && php artisan storage:link`
4. Pending SQL under `database/sql/` is still manual (e.g. `add_homepage_social_placement.sql`
   so catalog Site Details can show Homepage promotions + Social, and
   `restrict_order_items_site_id_on_delete.sql` so deleting a site cannot cascade-wipe orders)
5. `php artisan config:clear` (and `config:cache` if you normally cache) — `--repair`
   already clears a cached config after it writes `.env`
5b. PayPal checkout + Add Funds: set `PAYPAL_CLIENT_ID`, `PAYPAL_SECRET`, and
   `PAYPAL_WEBHOOK_ID` on Hostinger (Developer Dashboard app + webhook to
   `https://YOUR-DOMAIN/api/paypal/webhook`). `PAYPAL_MODE=live` on production.
   Production ignores leftover `PAYPAL_MODE=sandbox` from `.env.example` unless
   `PAYPAL_ALLOW_SANDBOX=true`. Omit `PAYPAL_ENABLED` or set it true — credentials turn the rail on. Set
   `PAYPAL_ENABLED=false` only to hide PayPal. Then `php artisan config:clear`
   and `php artisan paypal:status` (must print `OAuth: ok`; 401 means sandbox
   keys on live or the reverse).
6. Open 2 known image URLs (`/storage/sites/...`, `/storage/site-screenshots/...`)
7. Confirm a new upload lands under `persistent/media`, not a wiped folder
8. Article .docx uploads are fixed at 10 MB (admin cannot raise this). In hPanel →
   Advanced → PHP Configuration set `upload_max_filesize=64M` and
   `post_max_size=64M`. Confirm with
   `php -r 'echo ini_get("upload_max_filesize"), " ", ini_get("post_max_size"), "\n";'`
   from `public_html` (or `public/`). `public/.htaccess` and `public/.user.ini`
   already request 64M/64M; Hostinger LiteSpeed often ignores `php_value` until
   the same numbers are saved in hPanel. A 5 MB Word file is rejected as
   `UPLOAD_ERR_INI_SIZE` while PHP stays at the default 2M. This cannot be
   self-healed from PHP.
9. Blog / staff / banner / article-preview JPEG and PNG convert to WebP when
   PHP can encode it: `php-gd` with WebP, Imagick WEBP, or a `cwebp` binary
   (`SITE_CWEBP_PATH=/usr/bin/cwebp`). Without an encoder a validated original
   JPEG/PNG is stored (GIF still stays GIF). Enable one of those on Hostinger
   so new uploads become WebP.
10. Confirm MySQL, `APP_URL`, `MEDIA_PATH`, uploads, mail drain, and the scheduler:
   `php artisan ops:production-ready --repair --strict`
   Web traffic also runs `schedule:run` about once a minute. Add system cron if
   the site is quiet overnight. Then spot-check register → verify email →
   catalog image → wallet order → chat mail.

## Weekly

Back up `/home/USER/persistent/media` (zip / Hostinger backup / `tar`).
