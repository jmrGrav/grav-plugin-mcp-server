# grav-plugin-mcp-server

> Grav CMS plugin — Model Context Protocol (MCP) server exposing Grav CMS pages and tools to AI assistants like Claude.

## Prerequisites

Direct use of this plugin requires a Bearer token for every request.
To connect **Claude.ai** (or any OAuth 2.1 MCP client), you need the companion proxy:

**[jmrGrav/mcp-oauth-proxy](https://github.com/jmrGrav/mcp-oauth-proxy)** — FastAPI OAuth 2.1 proxy that handles authentication and forwards requests to this plugin.

## Installation

```bash
cp -r grav-plugin-mcp-server /var/www/grav/user/plugins/mcp-server
```

Then enable the plugin and configure a bearer token in Grav Admin → Plugins → MCP Server.

## Configuration

| Parameter | Default | Description |
|-----------|---------|-------------|
| `enabled` | `false` | Enable/disable the plugin |
| `token` | — | Bearer token required to authenticate MCP requests |

## Available Tools

| Tool | Description |
|------|-------------|
| `list_pages` | List all Grav pages with routes, titles and language variants |
| `get_page` | Retrieve a page by route, optionally for a specific language |
| `create_page` | Create a new page with title and Markdown content |
| `update_page` | Update an existing page's content or header |
| `delete_page` | Permanently delete a page by route |

## Hooks

| Event | Description |
|-------|-------------|
| `onPluginsInitialized` | Registers the MCP HTTP endpoint at `/api/mcp` |

## License

MIT — Jm Rohmer / [arleo.eu](https://arleo.eu)
