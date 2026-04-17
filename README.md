# grav-plugin-mcp-server

> Grav CMS plugin — Model Context Protocol (MCP) server exposing Grav CMS pages and tools to AI assistants like Claude.

## Installation

### Via GPM (recommended)
```bash
bin/gpm install mcp-server
```

### Manual
```bash
git clone https://github.com/jmrGrav/grav-plugin-mcp-server.git \
  /var/www/grav/user/plugins/mcp-server
```

## Configuration

### API Key mode (quick start)

1. Enable the plugin in Grav Admin → Plugins → MCP Server
2. Select mode **API Key**
3. Enter a secret key (min. 32 characters)
4. Save

Usage with curl:
```bash
curl -X POST https://your-site.com/api/mcp \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"tools/list","id":1}'
```

Both `Authorization: Bearer <key>` and `X-API-Key: <key>` headers are accepted.

### OAuth 2.1 mode (production — Claude.ai)

To connect Claude.ai to your site via the standard MCP protocol, OAuth 2.1 mode
requires the dedicated proxy:

**[jmrGrav/mcp-oauth-proxy](https://github.com/jmrGrav/mcp-oauth-proxy)** — FastAPI OAuth 2.1 + PKCE S256 proxy with SHA-256 token hashing and systemd hardening.

#### Architecture

```
Claude.ai → Cloudflare → nginx → mcp-oauth-proxy → grav-plugin-mcp-server
```

Full tutorial: https://arleo.eu/fr/grav-mcp-server

## Configuration parameters

| Parameter | Default | Description |
|-----------|---------|-------------|
| `enabled` | `false` | Enable/disable the plugin |
| `auth_mode` | `api_key` | Authentication mode: `api_key` or `oauth` |
| `api_key` | — | Secret key for API Key mode (min. 32 chars) |
| `token` | — | Bearer token for OAuth mode (set by mcp-oauth-proxy) |

## Available Tools

| Tool | Description |
|------|-------------|
| `list_pages` | List all Grav pages with routes, titles and language variants |
| `get_page` | Retrieve a page by route, optionally for a specific language |
| `create_page` | Create a new page with title and Markdown content |
| `update_page` | Update an existing page's content or header |
| `delete_page` | Permanently delete a page by route |
| `clear_cache` | Clear the Grav cache |
| `list_plugins` | List installed plugins with enabled status |
| `list_themes` | List installed themes |
| `toggle_plugin` | Enable or disable a plugin by name |

## License

MIT — Jm Rohmer / [arleo.eu](https://arleo.eu)
