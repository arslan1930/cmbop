# Admin → Email Center

Staff desk at `/admin/emails`. Delivery monitor, template preview, and **test-to-me** — it does not replace Campaigns and it does not change checkout or wallet.

## KPIs and recent log

Tiles are logged-today / open pending / open failed / delivered-today. They deep-link into the recent log with the same filters (`AdminEmails` session). SMTP accepted is not inbox proof.

Retry and bulk retry re-queue failed rows. Campaign leftover rules stay in [`docs/admin-campaigns.md`](admin-campaigns.md). Do not click **Send campaign** from here.

## Templates and settings

The catalog is preview + send-test-to-the-signed-in-admin. **Notification Settings** is the global on/off (user profile prefs still apply). Campaigns also honor marketing opt-out when respect-preferences is on. The settings audience dropdown is client-side (`live=1`); it must not POST the settings form.

## Ops

Queue worker, `MAIL_QUEUE_AUTO_DRAIN`, cron, and `APP_URL` / `PUBLIC_APP_URL` live in [`docs/ops-mail-reminders.md`](ops-mail-reminders.md).
