# Advertiser Catalog API

Parked on branch `feature/advertiser-catalog-api` until you merge it. Not on `master` / Hostinger until then.

## Phase 0 — locked product (v1)

These rules are the product. Do not change them in Phase A without a new lock.

1. **Who** — Advertisers only. Email must be verified. Suspended accounts are rejected. Publishers and admins do not get a client catalog key.
2. **What** — Search only. Same live inventory and advertiser prices as Advertiser → Catalog. Buyers still order on the website (cart / wallet). No place-order API in v1.
3. **Identity** — Same hide-mode as the catalog (mask host/name until the site would reveal it).
4. **Their website** — Optional `website=` is host → category / country / language from TLD and keywords. No crawl. No AI. A filled filter is never overwritten.
5. **Keys** — Create / copy-once / revoke under **Advertiser → API** (`/advertiser/api`). Menu is a link only; the secret is not in the dropdown.
6. **Profile** — Name and password stay on `/profile` (shared for every role). That page is not moved under `/advertiser` or `/publisher`.
7. **Gate** — Every advertiser can open API in v1. An “ask us” flag can wait.
8. **Stack** — Same Laravel app. No Redis. No second inventory. No Tawk / theme / catalog HTML change.

## Phase A — coded on this branch (not shipped)

- Bearer token (`slb_…`), hashed in `advertiser_api_tokens`
- `GET /api/v1/catalog`
- OpenAPI: `docs/openapi/advertiser-catalog-v1.yaml`

Ship checklist (Phase A + F): create the table once, click keys, curl vs the catalog, run the API tests, then merge this branch yourself.

## Later (do not start)

- **B** — create / list orders + wallet via API
- **C** — approve / track via API
- **E** — MCP / Claude connector
