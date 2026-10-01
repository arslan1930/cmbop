# Ops: mail, reminders, and cron

Production checklist for Seolinkbuildings email delivery and scheduled reminders.

## Must-haves

| Setting / process | Why |
|---|---|
| `APP_URL` = real public origin (`https://…`) | Named routes in mail and signed verify links use this host. |
| `PUBLIC_APP_URL` | Fallback only when `APP_ENV=production` and `APP_URL` is still loopback (misconfigured deploy). Prefer fixing `APP_URL`. |
| Queue worker **or** auto-drain | Mailables **and** `SendEmailCampaignJob` queue on `emails`. Run `php artisan queue:work --queue=default,emails`, **or** leave `MAIL_QUEUE_AUTO_DRAIN=true` (default) so web traffic + `mail:drain-queue` clear the backlog. |
| Scheduler every minute | `* * * * * cd /path/to/app && php artisan schedule:run`. Page views do not run the scheduler. |
| `CRON_SECRET` ≥ 32 chars **only if** using HTTP cron | `POST /cron/run` with header `X-Cron-Key`. The secret is not accepted in the URL. Short/empty secret keeps the route disabled. Never log the key. |

## Scheduled reminder commands (canonical list)

Defined in `bootstrap/app.php` (not `app/Console/Kernel.php`):

- `orders:auto-approve` — every 15 minutes
- `orders:nudge-publishers` / `orders:nudge-advertisers` — hourly
- `emails:send-deposit-reminders` / `emails:send-publisher-add-site-reminders` — daily
- `emails:send-digests` — weekly / monthly
- `sites:send-new-sites-digest` — daily
- `mail:drain-queue` — every minute (when no resident worker)

Verify with `php artisan schedule:list` — each command should appear once.

## Noise control

Accept / reject / live-URL flows send a **dedicated** advertiser mailable. Those paths register `OrderLifecycleMailSuppressor` so the generic `OrderStatusChanged` is not also sent to the advertiser. Admin/support status changes that skip the dedicated mail still get the generic lifecycle email.

## Quick health checks

```bash
php artisan ops:production-ready --repair
php artisan schedule:list
php artisan queue:failed
tail -n 50 storage/logs/laravel.log
```

Live Hostinger cannot be SSH’d from the cloud agent. The first production page
view (or `--repair`) writes a leftover loopback `APP_URL` from `PUBLIC_APP_URL`,
and web traffic drains mail + runs due schedule events.

Confirm a test registration writes a welcome/verify link (with `MAIL_MAILER=log`, search `storage/logs/laravel.log` for `email/verify`).

Verification mail (`VerifyEmail`) is **synchronous** — it does not wait on the
`emails` queue. Welcome mail is queued separately. Guests resend from
`/email/verify` (or login → “Need a verification email?”). Both paths are
enumeration-safe and do not confirm whether the address exists. If
`MAIL_MAILER=log`, nothing reaches an inbox. Staff can resend or mark verified
on Admin → Users.

## Admin campaigns

Admin → Updates & Campaigns is queued bulk marketing mail (`AudienceCampaignMail`),
not advertiser `/campaigns`. Signed marketing unsubscribe lives at
`/email/unsubscribe/{user}`. Full send path, audiences, stale window, and
preference rules: [`admin-campaigns.md`](admin-campaigns.md).
