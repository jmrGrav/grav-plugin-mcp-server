# Changelog

## v1.1.1
### 17-04-2026
* Add default mcp-server.yaml for GPM compliance (defaults only, no secrets)
* Remove mcp-server.yaml from .gitignore — user override goes in user/config/plugins/

## v1.1.0
### 17-04-2026
* Add API key authentication mode (simple, no proxy required)
* Add auth_mode config option (api_key / oauth)
* api_key mode supports Authorization: Bearer and X-API-Key headers
* Warns in grav.log if api_key mode is active but no key is configured
* OAuth mode unchanged — fully compatible with mcp-oauth-proxy

## v1.0.0
### 17-04-2026
* Initial release
* Exposes 9 MCP tools via JSON-RPC 2.0 (list_pages, get_page, create_page,
  update_page, delete_page, clear_cache, list_plugins, list_themes, toggle_plugin)
* MCP spec 2025-03-26 annotations on all tools
* Multilingual support (language parameter on page tools)
* Automatic numeric folder prefix on create_page
* Fires onMcpAfterSave event after create_page and update_page
