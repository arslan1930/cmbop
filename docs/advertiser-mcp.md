# Advertiser MCP (Chat with AI assistants)

Remote Model Context Protocol server for verified advertisers. Same live catalog
and EUR prices as Advertiser → Catalog. Claude.ai connects with OAuth 2.1;
Cursor and Claude Code can use a personal Bearer token.

Dashboard: **Advertiser → API & MCP** (`/advertiser/api`).

## Endpoints

| Method | Path | Purpose |
| --- | --- | --- |
| `POST` | `/api/mcp/customer` | Streamable HTTP JSON-RPC 2.0 (`initialize`, `notifications/initialized`, `ping`, `tools/list`, `tools/call`) |
| `GET` | `/api/mcp/customer` | SSE listen (also `/api/mcp/customer/sse` for `--type sse`) |
| `GET` | `/.well-known/oauth-protected-resource` | RFC 9728 protected-resource metadata |
| `GET` | `/.well-known/oauth-authorization-server` | RFC 8414 authorization-server metadata |
| `POST` | `/oauth/register` | Dynamic client registration (RFC 7591) |
| `GET` | `/oauth/authorize` | Login + consent (existing users, PKCE S256) |
| `POST` | `/oauth/token` | Authorization-code + refresh-token exchange |
| `POST` | `/oauth/revoke` | Revoke an access or refresh token |

Unauthenticated MCP calls return `401` with
`WWW-Authenticate: Bearer … resource_metadata="…/.well-known/oauth-protected-resource/…"`.
Routes are outside CSRF/session. CORS is open for those paths. Rate limit:
`throttle:mcp` (60/min per user or IP).

## Auth

1. **OAuth 2.1 (Claude.ai Connect)** — discovery → DCR → authorization code + PKCE S256 → consent on SEOLinkBuildings → Bearer access token (`slb_oa_…`). Implemented in-app (not Laravel Passport) so production does not need Passport keys.
2. **Personal token (Cursor / Claude Code)** — create under API & MCP. Header `Authorization: Bearer slb_…` (same keys as the catalog REST API).

Advertiser role, verified email, not suspended. Buyer-safe data only: no publisher emails, payout wallets, or other users' orders.

## Tools

| Tool | What it does |
| --- | --- |
| `search_marketplace` | Live listings. Filters: country, language, niche/topic, min/max DR, DA, traffic, min/max EUR price, dofollow, sponsored label, sort, limit. |
| `get_site_details` | One listing by `site_id` or domain. |
| `list_countries_niches` | Marketplace country / language codes and niche names. |
| `save_to_favorites` | Add to Saved Sites (does not wipe existing favorites). |
| `save_to_cart` | Durable cart line; merged into the website cart on the next Catalog visit. Checkout stays on the site. |
| `list_my_orders` | The signed-in advertiser's recent orders (EUR). |
| `get_wallet_balance` | The signed-in advertiser's EUR wallet only. |

Hide-mode still masks host/name. Prices are advertiser-facing EUR.

## Local test

```bash
php artisan test --filter=McpCustomerServerTest
```

With a personal key against `composer serve`:

```bash
curl -sS -H "Authorization: Bearer slb_…" -H "Accept: application/json, text/event-stream" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl","version":"1"}}}' \
  http://127.0.0.1:8000/api/mcp/customer
```

Then `tools/list` and `tools/call` with the same Bearer header.

Claude.ai Connect needs a **public HTTPS** origin (not localhost). Use a tunnel
if you want to try the OAuth popup locally.

## Claude / Cursor

**Claude (browser or desktop)**

1. Customize → Connectors → Add custom connector
2. Name: `SEOLinkBuildings` (letters only — no spaces, dots, or accents; Claude’s chat tool index keys off this name)
3. URL: `https://YOUR-DOMAIN/api/mcp/customer`
4. Connect → sign in → Allow
5. After any MCP deploy: disconnect, Connect again, then start a **new chat**. Settings can list tools while an old chat still has an empty tool set.
6. Ask: “Find me 10 publishers for my client, DR over 40, price under 750, Ahrefs traffic over 1000, topic marketing.”

**Claude Code**

```bash
claude mcp add --transport http --scope user seolinkbuildings https://YOUR-DOMAIN/api/mcp/customer
```

SSE:

```bash
claude mcp add --transport sse --scope user seolinkbuildings https://YOUR-DOMAIN/api/mcp/customer/sse
```

**Cursor** — personal token in `.cursor/mcp.json`:

```json
{
  "mcpServers": {
    "seolinkbuildings": {
      "type": "http",
      "url": "https://YOUR-DOMAIN/api/mcp/customer",
      "headers": {
        "Authorization": "Bearer slb_…"
      }
    }
  }
}
```

Do not point Cursor at a local stdio catalog server. This remote URL replaces
the earlier `seolinkbuildings-catalog` connector that stayed on “Connecting…”.

## Deploy

1. `composer install` (adds `laravel/mcp`).
2. `php artisan migrate --force` — creates `mcp_oauth_clients`, `mcp_oauth_auth_codes`, `mcp_oauth_access_tokens`, `advertiser_saved_cart_items`. No extra SQL for the Claude chat-tool fix.
3. HTTPS on the public host (`APP_URL` / `PUBLIC_APP_URL`). Claude will not complete OAuth on plain HTTP.
4. No Passport keys. No new `.env` secrets. Optional: `MCP_ACCESS_TOKEN_TTL`, `MCP_REFRESH_TOKEN_TTL`.
5. `php artisan ops:production-ready --repair` already runs `migrate --force`.
6. After deploying the Claude chat-tool fix, also run:
   ```
   php artisan optimize:clear
   ```
   Then in claude.ai: disconnect the connector, Connect again, open a **new** chat.

The Streamable HTTP endpoint is **stateless**. It does not mint `Mcp-Session-Id` (it only echoes one the client already sent). `GET /api/mcp/customer` is `405` — SSE lives at `/api/mcp/customer/sse`. `initialize` echoes the client’s `protocolVersion` (`2025-03-26`, `2025-06-18`, `2025-11-25`, `2026-07-28`). `tools/list` is valid on every POST (not tied to a PHP worker session) and includes `ttlMs` > 0 so Claude does not expire the catalog immediately.

Stack: Laravel 13 `laravel/mcp` for JSON-RPC / tools. First-party OAuth 2.1 so
DCR + PKCE work without installing Laravel Passport.
