# Changelog

## v1.0.0
### 17-04-2026
* Initial release
* Exposes 9 MCP tools via JSON-RPC 2.0 (list_pages, get_page, create_page,
  update_page, delete_page, clear_cache, list_plugins, list_themes, toggle_plugin)
* MCP spec 2025-03-26 annotations on all tools
* Multilingual support (language parameter on page tools)
* Automatic numeric folder prefix on create_page
* Fires onMcpAfterSave event after create_page and update_page
