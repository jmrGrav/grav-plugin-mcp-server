# Changelog

## v1.4.0
### 07-05-2026
* **G-03 MEDIUM** : mode strict auth optionnel — nouvelle config `strict_auth_on_initialize` (défaut OFF) ; si ON, toutes les méthodes MCP (y compris `initialize`/`tools/list`) exigent un token valide (⚠️ casse la compat MCP standard)
* Blueprints mis à jour pour exposer le toggle dans l'interface admin Grav

## v1.3.0
### 07-05-2026
* **G-01 CRITICAL** : `_safe_route_path()` — bloque les segments `..` et `.` dans le paramètre `route` de `toolCreatePage`; RuntimeException converti en `isError: true` HTTP 400
* **G-02 HIGH** : `toolDeletePage` — suppression sélective par langue avec paramètre `lang` optionnel; supprime uniquement le fichier de la variante ciblée, supprime le dossier seulement si plus aucun fichier `.md` ne subsiste
* Schéma JSON-RPC du tool `delete_page` mis à jour pour déclarer le paramètre `lang`

## v1.2.0
### 07-05-2026
* `get_page`, `create_page`, `update_page` : rename schema param `language` → `lang` to match Claude.ai argument serialization
* Accept both `lang` and `language` arg keys for backward compat (legacy `language` key still works)
* `get_page` : try `{template}.{lang}.md` before `default.{lang}.md` — fixes pages using item/blog template names (item.en.md, blog.en.md, etc.)
* `list_pages` : detect language variants from any `*.{lang}.md` file, not only `default.*.md` — pages with item/blog templates now report correct `languages` array

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
