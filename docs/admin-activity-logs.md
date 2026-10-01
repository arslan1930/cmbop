# Admin → Activity History

Staff desk at `/admin/activity-logs` (System nav, not Growth). Append-only rows written by `ActivityLogger`. History cannot be deleted or edited here.

## What it is

Actions that call `ActivityLogger` / `tryLog` (sites, bulk onboarding, selected money and growth events, library staff actions, campaigns queued, and so on). Marketers use **`/marketing/history`** — they cannot open this page or export it.

## Filters

`user` (name / email, or a numeric actor id), `user_id` (exact actor pin — kept on Apply and theme-select submit), `q` (subject, details, action label), action, role, From / To (app timezone). Invalid or inverted dates are ignored with a warning; the list still loads.

Users → **History** pins `user_id`. Clear actor drops that pin only.

## Export

CSV is the same filter slice, capped at `activity_logs.export_limit` (default **10 000**). Over the cap, export is refused (a partial file would look complete). IP and raw `properties` JSON are in the CSV, not the table. Cells that start with `= + - @` are prefixed so spreadsheets do not execute them.

## Subjects

Live subjects deep-link when the row still exists (sites, orders, library articles, campaign **show**, blogs, promotions). Gone rows show **Removed** instead of a 404. Community problem / suggestion rows stay on the Community tab.
