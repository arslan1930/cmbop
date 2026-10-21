# Advertiser Catalog + Ordering API

Live on `/api/v1`. Hostinger: run `database/sql/add_advertiser_ordering_api.sql` (or `php artisan migrate --force`).

## Product (v1)

1. **Who** — Advertisers only. Email must be verified. Suspended accounts are rejected. Publishers and admins do not get a client catalog key.
2. **What** — Search the live catalog, read one site, read the EUR wallet, place prepaid wallet orders, list/cancel own orders, and manage signed webhooks. Same inventory and advertiser prices as Advertiser → Catalog. No card payments on the API.
3. **Identity** — Same hide-mode as the catalog (mask host/name until the site would reveal it). Never partner/publisher rates, markup, publisher identity, or other advertisers’ data.
4. **Their website** — Optional `website=` is host → category / country / language from TLD and keywords. No crawl. No AI. A filled filter is never overwritten.
5. **Keys** — Create / copy-once / revoke under **Advertiser → API & MCP** (`/advertiser/api`). Live (`slb_live_…`) and test (`slb_test_…`) keys. Menu is a link only; the secret is not in the dropdown.
6. **Profile** — Name and password stay on `/profile` (shared for every role). That page is not moved under `/advertiser` or `/publisher`.
7. **Gate** — Every advertiser can open API in v1.
8. **Stack** — Same Laravel app. No Redis. Queues run via `schedule:run` (`advertisers:deliver-webhooks --max-seconds=40`). Public docs: `/api/docs/advertiser`.

## Endpoints

- `GET /api/v1/catalog` — listing facts (id, name/host, advertiser price, metrics, niches, pagination)
- `GET /api/v1/sites/{id}` — advertiser price, metrics, turnaround, writing option, rules
- `GET /api/v1/wallet` — spendable / reserved / bonus
- `POST /api/v1/orders` — wallet checkout path (frozen price, invoice, publisher notify, ledger). `402 insufficient_funds` when the balance is short. `Idempotency-Key` required.
- `GET /api/v1/orders`, `GET /api/v1/orders/{id}` — status, live_url, link-check
- `POST /api/v1/orders/{id}/cancel` — only while the website still allows it; refunds through `OrderRefundService`
- Webhooks CRUD + test. Events: `order.created` / `accepted` / `rejected` / `published` / `cancelled`. Signature: `SLB-Signature: v1=…` over `{timestamp}.{body}` plus `SLB-Webhook-Timestamp`.

Test keys create sandbox orders (`is_sandbox=1`): no wallet debit, no publisher email. Missing sandbox columns → `503`.

Catalog-only OpenAPI (search): `docs/openapi/advertiser-catalog-v1.yaml`. MCP: [`docs/advertiser-mcp.md`](advertiser-mcp.md).
