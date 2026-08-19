# Hostinger `public_html` (split Laravel layout)

Live Hostinger for this site uses two folders:

```
public_html/                 ← domain document root (what the browser sees)
  index.php                  ← boots ../laravel_app (see public/index.hostinger.php)
  .htaccess
  assets/                    ← MUST match repo public/assets/ on every deploy
  js/                        ← MUST match repo public/js/
  storage                    ← symlink to durable media

laravel_app/                 ← PHP app (app/, vendor/, .env, bootstrap/)
```

Updating `laravel_app` without replacing `public_html/assets` leaves **new HTML + old CSS**. The site looks half-styled. `https://seolinkbuildings.com/assets/css/app-shell.css` serving CSS does **not** mean it is current.

Current `app-shell.css` in git starts with `@property --shell-rail`. An old copy starts with `:root { --shell-sidebar-width`.

## Every code deploy

1. Keep live `public_html/index.php` (laravel_app + `usePublicPath`). Do not overwrite it with repo `public/index.php`.
2. Copy **repo** `public/assets/` → **live** `public_html/assets/` (overwrite).
3. Copy **repo** `public/js/` → **live** `public_html/js/` (overwrite).
4. Copy **repo** `public/.htaccess` → **live** `public_html/.htaccess`.
5. Leave `public_html/storage` and live `.env` alone.

Do not upload into `laravel_app/public/` and expect the domain to serve it. The domain only serves `public_html`.

## Confirm

Open:

`https://YOUR-DOMAIN/assets/css/app-shell.css`

The first rules must include `@property --shell-rail`. Then hard-refresh the site. View source: `app-shell.css?v=` should be a long timestamp, not `1`.

CSS lives in `public/assets/css/` in git. There is no `public/css` folder.

Template for the split `index.php`: [`public/index.hostinger.php`](../public/index.hostinger.php).
