# Admin → Partners

Staff hub at `/admin/partners`. A **catalog partner** is a normal SLB publisher account (same onboarding, My Sites, orders, withdrawals). Imported sites, once put live, become ordinary marketplace listings owned by that publisher. Teqno LTD (17392540) charges **no fees** to publishers or partners. Partners never see the SLB price, commission, margin, advertiser counts or order volumes.

This ships Appendix C.1 steps 2–3 only (catalog import, admin selection, dual pricing). The partners subdomain dashboard, chat relay, new ledger, API keys/webhooks, payout modes and reseller API are **not** built.

## URLs

| Screen | Path |
|---|---|
| Partner list / mark publisher | `/admin/partners` |
| Partner record + feed URL | `/admin/partners/{id}` |
| Selection (filter, bulk live/unlist) | `/admin/partners/catalog` |
| CSV/XLSX/JSON/XML upload + jobs | `/admin/partners/imports` |
| Pricing rules + preview | `/admin/partners/pricing-rules`, `/admin/partners/pricing-rules/preview` |
| Auto-approve rules + dry-run | `/admin/partners/auto-approve-rules` |
| Partner rate-rise queue | `/admin/partners/rate-changes` |
| Existing onboard (tick Catalog partner) | `/admin/publishers/onboard` |

## Owner actions (Hostinger)

1. Run `database/sql/add_partner_catalog_import.sql` in phpMyAdmin (starts with `USE u841082722_cmbop_db;`). Safe to re-run. Migrations are **not** auto-run.
2. Cron already calls `schedule:run`. New daily jobs: `partners:refresh-fx` at 03:40, `partners:sync-feeds` at 04:00. No extra crontab line.
3. Optional env (all have defaults in `config/partners.php`):

```
PARTNER_MARGIN_FLOOR=0.35
PARTNER_HARD_FLOOR=0.15
PARTNER_MIN_COMMISSION_EUR=25
PARTNER_FEED_DROP_PERCENT=30
PARTNER_RATE_INCREASE_LIMIT=4
CZK_PER_EUR=25.0
PLN_PER_EUR=4.30
PARTNER_FX_ECB_URL=https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml
```

No Redis. No new queue. Mail still uses `emails`.

## How it works

1. Mark a publisher as partner, or tick **Catalog partner** on Add publisher.
2. Upload a file or save a feed URL. New rows land as `unused`. Casino is `blocked`.
3. Filter on the selection screen and put sites live. Go-live refuses blocked, duplicate, unreachable, missing SLB price, or below the 35% floor (unless override).
4. Live listings use `sites.price` = partner EUR rate and `sites.slb_price_publish_eur` as the advertiser catalog price.
5. Re-sync matches by domain. Missing sites and feeds that disappear or drop >30% stay live until admin confirms pause on the import job. Open orders keep frozen `publisher_amount` / `advertiser_eur_amount`.
6. Partner rate cuts apply at once. Rises that would break the margin floor wait on `/admin/partners/rate-changes`.

## Confidentiality

Publisher dashboard, emails and exports must never show SLB price / commission / margin. `Site` and `PartnerCatalogItem` hide those columns on `toArray()`. Admin screens are the only place both prices appear.
