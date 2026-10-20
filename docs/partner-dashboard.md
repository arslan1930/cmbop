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

1. **SQL.** Run `database/sql/add_partner_dashboard.sql`, then `database/sql/add_partner_full_channel.sql`, then `database/sql/add_partner_api_gaps.sql` in phpMyAdmin (each starts with `USE u841082722_cmbop_db;`). Safe to re-run. Laravel twins: `database/migrations/2026_10_19_160000_create_partner_dashboard_tables.php`, `database/migrations/2026_10_19_180000_create_partner_full_channel_tables.php`, and `database/migrations/2026_10_20_041500_add_partner_api_sandbox_and_link_check.php`. Migrations are **not** auto-run.
2. **DNS / hosting.** Point `partners.seolinkbuildings.com` at the **same public directory** as `seolinkbuildings.com` (Addon domain / alias, document root = main site `public`). No second deploy.
3. **Env** (optional; defaults in `config/partners.php`):

```
PARTNER_DASHBOARD_DOMAIN=partners.seolinkbuildings.com
SESSION_DOMAIN=
```

Leave `SESSION_DOMAIN` empty so the partner session cookie is host-only (`…-partner-session`) and does not mix with apex login.

4. **Google (optional).** Add `https://partners.seolinkbuildings.com/auth/google/callback` as an authorized redirect URI.

Optional: `PARTNER_API_RATE_PER_MINUTE=60`. Partner REST lives at `/api/partner/v1` (Bearer `slb_live_…` / `slb_test_…`).

## Partner API (`/api/partner/v1`)

Bearer key auth (`Authorization: Bearer slb_live_…` or `slb_test_…`). Partners never see advertiser prices, markup, or SLB commission. Partners pay no fees.

| Area | Notes |
|---|---|
| Webhooks | `GET/POST /webhooks`, `GET/PATCH/DELETE /webhooks/{id}`, `POST /webhooks/{id}/test`. Create returns the `whsec_…` signing secret **once**. Payloads are HMAC-SHA256 (`SLB-Signature: v1=…` over `{timestamp}.{body}`). Dashboard Settings has the same **Send test event** button (`webhook.test`, `livemode: false`). |
| Order events | `order.created` always fires (even if the partner turned off new-order email). `order.accepted` / `order.rejected` fire from the API and the dashboard. |
| Complete | `POST /orders/{id}/complete` fetches the live page (HTTP 200–399), confirms the target URL is linked, and stores the real `rel` (dofollow / nofollow / sponsored / ugc) plus whether the anchor text matches. |
| Test keys | `slb_test_` keys only see and act on sandbox data. `POST /sandbox/orders` (test keys only) creates a fake paid order for one of the partner’s own sites: no money, ledger, invoices, advertiser email, marketplace, or admin revenue. Live keys never see those rows. Sandbox accept / reject / complete fire webhooks with `"livemode": false`. |

| Method | Path | Auth |
|---|---|---|
| GET | `/me` | live or test |
| GET/POST | `/webhooks` | `webhooks:write` |
| GET/PATCH/DELETE | `/webhooks/{id}` | `webhooks:write` |
| POST | `/webhooks/{id}/test` | `webhooks:write` |
| POST | `/sandbox/orders` | test key + `orders:write` |
| GET | `/orders`, `/orders/summary`, `/orders/{id}` | live sees live rows; test sees sandbox only |
| POST | `/orders/{id}/accept`, `/reject`, `/complete` | same isolation |
| GET | `/balance`, `/ledger`, `/statements` | test keys return empty/zero |
| POST | `/payouts` | live only |

No Redis. Cron is still `schedule:run` only. Mail still uses the `emails` queue.

## Behaviour

- Any path on the partners host that is not a dashboard route redirects to `/`. The marketing site is never rendered there. Pages send `noindex, nofollow`.
- Login is limited to catalog-partner publisher accounts. Others see a message with a link to the main site.
- Home tiles: new/pending, in progress, submitted, awaiting correction, disputed, completed, rejected.
- Order actions call the existing publisher accept / reject path. Live URL submit uses `POST /complete` semantics (link check, frozen payout, held vs instant). Other partners' IDs return 404.
- Order thread is relayed through SLB. Contact details in messages and attachment names are masked; originals are admin-only.
- My sites show live / not listed / paused and **only** the partner rate.
- Money uses the **partner ledger** (held / available / statements). Withdrawals stay €20 minimum with **no fees**. Instant payouts need a stored keep-live acceptance and admin approval.
- Settings can issue hashed API keys and a signed webhook URL, and send a signed `webhook.test` event. Security and payout emails cannot be switched off.
- Email change: confirm link to the new address, cancel link to the old address; the address changes only after confirmation (AC-P13).
- New-order, correction, and thread emails include a 72-hour, single-use, order-scoped link. GET never changes order state. Money and settings always need a full login.
