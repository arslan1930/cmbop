# Admin → Audiences

Staff desk at `/admin/audiences`. This is the **marketplace census** (advertisers and publishers), not a send button.

## What it is

Tabs are segments (advertisers, publishers, unique, never checked out, paid customers, sites, deposits). Each tab shows **all / emailable (verified)**. Campaigns email verified users only unless you tick **include unverified**.

Filters (search, verified, country, marketing opt-in, registered dates, dual-role exclude) apply to the table, CSV, and **Updates / Campaigns** handoff. Sort does not change who is emailed.

## Export and email

CSV is the same slice as the table, capped at `AudienceInventoryService::EXPORT_LIMIT`. There is **no Send** on this page — use Campaigns with the handoff query (`q`, `verified`, `registered_from` / `to`, `country`, `marketing`, `exclude_dual_role`).

## Out of scope

Admins and marketing staff are never in these lists. Do not treat this as Email Center (templates / SMTP logs) or Promotions (on-site notices).
