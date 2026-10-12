# Test catalog MCP in Cursor / Claude

Do **not** open `/api/v1/mcp` in the browser (GET). Tools use **POST** JSON-RPC + Bearer.

Create a key under **Advertiser → API**. Put it in an environment variable, not git.

```bash
export SLB_CATALOG_API_TOKEN=slb_your_key
```

## Cursor (remote URL — Hostinger or local serve)

1. Cursor **Settings → MCP → Add new MCP server** (or edit `~/.cursor/mcp.json`).
2. Paste (local first; after deploy switch the host):

```json
{
  "mcpServers": {
    "seolinkbuildings-catalog": {
      "url": "http://127.0.0.1:8000/api/v1/mcp",
      "headers": {
        "Authorization": "Bearer ${env:SLB_CATALOG_API_TOKEN}"
      }
    }
  }
}
```

Live:

`"url": "https://seolinkbuildings.com/api/v1/mcp"`

3. Restart Cursor or reload MCP. The server should show **catalog_search**.
4. New Agent chat: “Search the catalog for country Norway, 10 per page.”

## Cursor / Claude Desktop (stdio — if URL transport fails)

`php artisan serve` (or use the live URL). Then:

```json
{
  "mcpServers": {
    "seolinkbuildings-catalog": {
      "command": "php",
      "args": ["${workspaceFolder}/mcp/catalog/server.php"],
      "env": {
        "CATALOG_API_URL": "http://127.0.0.1:8000/api/v1/mcp",
        "CATALOG_API_TOKEN": "${env:SLB_CATALOG_API_TOKEN}"
      }
    }
  }
}
```

Claude Desktop: same `command` / `args` / `env` in `claude_desktop_config.json` (use an absolute path instead of `${workspaceFolder}`).

## Curl (proves the server without Cursor)

```bash
curl -sS -H "Authorization: Bearer $SLB_CATALOG_API_TOKEN" -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' \
  http://127.0.0.1:8000/api/v1/mcp
```

Expect one tool: `catalog_search`.
