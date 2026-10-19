# Partner dashboard

Web dashboard for catalog partners on **`partners.seolinkbuildings.com`**, served by the same Laravel app as the marketplace. A catalog partner is a normal publisher account (PR #1301 onboard / set-password invite, PR #1303 catalog import). Orders, accept / reject / live URL reuse the publisher accept/reject path. Completing a live URL, the order thread, and money (held / available / statements) use the partner channel. Teqno Ltd (17392540) charges **no fees**.

English only. Partners never see the SLB price, commission, margin, markup, buyer identity, advertiser counts or order volumes.

## URLs

On the partners host (or `/partners/…` locally):

| Screen | Path |
|---|---|
| Sign in | `/login` |
| Forgot / reset password | `/forgot-password`, `/reset-password/{token}` |
| Home (live counts) | `/` |
| Orders | `/orders`, `/orders/{id}` |
| My sites | `/sites` |
| Money | `/money` |
| Settings | `/settings` |
| One-click email link | `/a/{token}` |

Set-password invites for catalog partners use the partners host; after saving a password they land on this dashboard.

## Owner actions (Hostinger)

1. **SQL.** Run `database/sql/add_partner_dashboard.sql` then `database/sql/add_partner_full_channel.sql` in phpMyAdmin (each starts with `USE u841082722_cmbop_db;`). Safe to re-run. Laravel twins: `database/migrations/2026_10_19_160000_create_partner_dashboard_tables.php` and `database/migrations/2026_10_19_180000_create_partner_full_channel_tables.php`. Migrations are **not** auto-run.
2. **DNS / hosting.** Point `partners.seolinkbuildings.com` at the **same public directory** as `seolinkbuildings.com` (Addon domain / alias, document root = main site `public`). No second deploy.
3. **Env** (optional; defaults in `config/partners.php`):

```
PARTNER_DASHBOARD_DOMAIN=partners.seolinkbuildings.com
SESSION_DOMAIN=
```

Leave `SESSION_DOMAIN` empty so the partner session cookie is host-only (`…-partner-session`) and does not mix with apex login.

4. **Google (optional).** Add `https://partners.seolinkbuildings.com/auth/google/callback` as an authorized redirect URI.

Optional: `PARTNER_API_RATE_PER_MINUTE=60`. Partner REST lives at `/api/partner/v1` (Bearer `slb_live_…` / `slb_test_…`).

No Redis. Cron is still `schedule:run` only. Mail still uses the `emails` queue.

## Behaviour

- Any path on the partners host that is not a dashboard route redirects to `/`. The marketing site is never rendered there. Pages send `noindex, nofollow`.
- Login is limited to catalog-partner publisher accounts. Others see a message with a link to the main site.
- Home tiles: new/pending, in progress, submitted, awaiting correction, disputed, completed, rejected.
- Order actions call the existing publisher accept / reject path. Live URL submit uses `POST /complete` semantics (link check, frozen payout, held vs instant). Other partners' IDs return 404.
- Order thread is relayed through SLB. Contact details in messages and attachment names are masked; originals are admin-only.
- My sites show live / not listed / paused and **only** the partner rate.
- Money uses the **partner ledger** (held / available / statements). Withdrawals stay €20 minimum with **no fees**. Instant payouts need a stored keep-live acceptance and admin approval.
- Settings can issue hashed API keys and a signed webhook URL. Security and payout emails cannot be switched off.
- Email change: confirm link to the new address, cancel link to the old address; the address changes only after confirmation (AC-P13).
- New-order, correction, and thread emails include a 72-hour, single-use, order-scoped link. GET never changes order state. Money and settings always need a full login.
