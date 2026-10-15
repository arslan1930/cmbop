# Off-site backups (Google Drive)

Daily dump of the MariaDB database plus user uploads (`storage/app/public` /
`MEDIA_PATH`, and `public/uploads` if it exists). Cache, logs, and vendor are
skipped. Files land in a Drive folder owned by **henrybrown1930@gmail.com**.

The app timezone is UTC. The job is scheduled at **03:00 Asia/Karachi**
(`backup:drive` in `bootstrap/app.php`). Page views do **not** run the
scheduler.

## hPanel cron (required)

Hostinger Business has no Redis and usually no resident `queue:work`. The
scheduler must run **every minute**; Laravel then fires `backup:drive` at 03:00
PKT.

hPanel → Advanced → Cron Jobs → add:

```
* * * * * cd /home/u841082722/domains/seolinkbuildings.com/public_html && php artisan schedule:run >> /dev/null 2>&1
```

If the Laravel root is one level above `public/` (document root = `public_html/public`), `cd` that parent instead.

Confirm with `php artisan schedule:list` — you should see `backup:drive` with
`03:00` and timezone `Asia/Karachi`.

HTTP fallback (only if system cron is unavailable): set `CRON_SECRET` (≥ 32
chars) and POST `/cron/run` every minute with header `X-Cron-Key`.

## Env vars (live `.env`)

```
ADMIN_EMAIL=henrybrown1930@gmail.com
GOOGLE_DRIVE_CLIENT_ID=
GOOGLE_DRIVE_CLIENT_SECRET=
GOOGLE_DRIVE_REFRESH_TOKEN=
GOOGLE_DRIVE_FOLDER_ID=
BACKUP_KEEP=30
BACKUP_PREFIX=seolinkbuildings-backup
```

`ADMIN_EMAIL` receives `BackupFailedMail` (sent immediately, not queued) when
the dump/upload throws. Laravel also emails command output on schedule failure.

## Obtain a refresh token

Use a **Desktop** OAuth client so the token can be minted from a personal
computer. Scope is `drive.file` (only files this app creates).

1. Sign in to [Google Cloud Console](https://console.cloud.google.com/) as
   `henrybrown1930@gmail.com`.
2. Create (or pick) a project → APIs & Services → **Enable Google Drive API**.
3. APIs & Services → OAuth consent screen: External (or Internal if Workspace).
   Add your Gmail as a test user. Scopes: `https://www.googleapis.com/auth/drive.file`.
4. APIs & Services → Credentials → **Create credentials** → OAuth client ID →
   Application type **Desktop app**. Copy Client ID and Client Secret into
   `.env` (`GOOGLE_DRIVE_CLIENT_ID` / `GOOGLE_DRIVE_CLIENT_SECRET`).
5. In Drive, create a folder (e.g. `seolinkbuildings-backups`). Open it and copy
   the id from the URL (`https://drive.google.com/drive/folders/FOLDER_ID`).
   Set `GOOGLE_DRIVE_FOLDER_ID`.
6. On your laptop (not Hostinger), run:

```bash
# Replace CLIENT_ID. Opens a browser; approve access for henrybrown1930@gmail.com.
# Redirect URI for Desktop clients is http://127.0.0.1 (OAuth Playground also works).

python3 - <<'PY'
import urllib.parse
cid = "YOUR_CLIENT_ID"
scope = "https://www.googleapis.com/auth/drive.file"
# access_type=offline + prompt=consent is what mints a refresh_token
print("https://accounts.google.com/o/oauth2/v2/auth?" + urllib.parse.urlencode({
    "client_id": cid,
    "redirect_uri": "http://127.0.0.1:1",
    "response_type": "code",
    "scope": scope,
    "access_type": "offline",
    "prompt": "consent",
}))
PY
```

Or use [OAuth 2.0 Playground](https://developers.google.com/oauthplayground):
gear icon → **Use your own OAuth credentials** → paste Client ID/Secret →
authorize `https://www.googleapis.com/auth/drive.file` → Exchange authorization
code for tokens → copy **Refresh token**.

7. Exchange the `code` (if you used the browser URL) :

```bash
curl -s -X POST https://oauth2.googleapis.com/token \
  -d client_id=YOUR_CLIENT_ID \
  -d client_secret=YOUR_CLIENT_SECRET \
  -d code=THE_CODE \
  -d grant_type=authorization_code \
  -d redirect_uri=http://127.0.0.1:1
```

Put `refresh_token` in `GOOGLE_DRIVE_REFRESH_TOKEN`. Never commit it.

8. On Hostinger, after saving `.env`:

```bash
php artisan config:clear
php artisan backup:drive --test
```

`--test` refreshes the token and reads the folder metadata. It does not dump
the database.

9. First real run (optional, during a quiet window):

```bash
php artisan backup:drive
```

The zip is named `{BACKUP_PREFIX}-YYYYMMDD-HHMMSS.zip` (Karachi clock). Only
files with that prefix in `GOOGLE_DRIVE_FOLDER_ID` are pruned; the last 30 are
kept. Local temp files under `storage/app/backup-tmp/` are deleted after upload.

## What is inside the zip

- `database.sql.gz` — `mysqldump` when `/usr/bin/mysqldump` exists, otherwise a
  chunked PHP dump
- copies of files from the public disk (`MEDIA_PATH` or `storage/app/public`)
  and `public/uploads` if present (`cache` / `logs` / `vendor` paths skipped)

## Restore (manual)

Download the zip from Drive, gunzip `database.sql.gz`, import in phpMyAdmin
(or `mysql … < database.sql`). Copy media files back onto `MEDIA_PATH` /
`public/storage`.
