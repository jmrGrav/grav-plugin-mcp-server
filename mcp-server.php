<?php
namespace Grav\Plugin;

use Grav\Common\Plugin;
use RocketTheme\Toolbox\Event\Event;

class McpServerPlugin extends Plugin
{
    private string $sessionFile;

    public static function getSubscribedEvents(): array
    {
        return ['onPluginsInitialized' => ['onPluginsInitialized', 0]];
    }

    public function onPluginsInitialized(): void
    {
        if ($this->isAdmin()) return;
        $path = $this->grav['uri']->path();
        if (strpos($path, '/api/mcp') !== 0) return;
        $this->enable(['onPageInitialized' => ['handleRequest', 0]]);
    }

    public function handleRequest(): void
    {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, HEAD, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, Mcp-Session-Id');
        header('MCP-Protocol-Version: 2025-03-26');

        $method = $_SERVER['REQUEST_METHOD'];
        $path   = preg_replace('#^/api/mcp#', '', $this->grav['uri']->path());

        if ($method === 'OPTIONS') {
            http_response_code(200);
            echo json_encode(['status' => 'ok']);
            exit;
        }

        if ($path === '/.well-known/oauth-protected-resource/api/mcp' ||
            strpos($path, 'oauth-protected-resource') !== false) {
            http_response_code(200);
            echo json_encode([
                'resource'                  => 'https://www.arleo.eu/api/mcp',
                'authorization_servers'     => [],
                'bearer_methods_supported'  => ['header', 'query'],
                'scopes_supported'          => ['mcp'],
            ]);
            exit;
        }

        if ($method === 'HEAD' && $path === '') { http_response_code(200); exit; }
        if ($method === 'GET'  && $path === '') {
            http_response_code(200);
            echo json_encode(['protocol' => 'mcp', 'version' => '2025-03-26']);
            exit;
        }

        $bodyRaw   = file_get_contents('php://input');
        $body      = json_decode($bodyRaw, true) ?? [];
        $mcpMethod = $body['method'] ?? '';

        $strictAuth    = (bool)$this->config->get('plugins.mcp-server.strict_auth_on_initialize', false);
        $noAuthMethods = $strictAuth ? [] : ['initialize', 'notifications/initialized', 'tools/list'];
        if (!in_array($mcpMethod, $noAuthMethods) && !$this->authenticateRequest($body['id'] ?? null)) {
            exit;
        }

        $sessionId = $_SERVER['HTTP_MCP_SESSION_ID'] ?? bin2hex(random_bytes(16));
        header('Mcp-Session-Id: ' . $sessionId);

        $id = $body['id'] ?? null;
        switch ($mcpMethod) {
            case 'initialize':
                $this->rpcResponse($id, [
                    'protocolVersion' => '2025-03-26',
                    'capabilities'    => ['tools' => ['listChanged' => false]],
                    'serverInfo'      => ['name' => 'arleo-grav-mcp', 'version' => '1.0.0'],
                ]);
                break;
            case 'notifications/initialized':
                http_response_code(202); echo ''; break;
            case 'tools/list':
                $this->rpcResponse($id, ['tools' => $this->getToolsList()]); break;
            case 'tools/call':
                $toolName = $body['params']['name'] ?? '';
                $args     = $body['params']['arguments'] ?? [];
                $this->rpcResponse($id, $this->callTool($toolName, $args)); break;
            default:
                http_response_code(501);
                $this->rpcError($id, -32601, 'Method not found: ' . $mcpMethod);
        }
        exit;
    }

    private function authenticateRequest($requestId): bool
    {
        $authMode = $this->config->get('plugins.mcp-server.auth_mode', 'api_key');
        if ($authMode === 'oauth') {
            return $this->authenticateOAuth($requestId);
        }
        return $this->authenticateApiKey($requestId);
    }

    private function authenticateApiKey($requestId): bool
    {
        $apiKey = $this->config->get('plugins.mcp-server.api_key', '');
        if (empty($apiKey)) {
            $this->grav['log']->warning('[MCP Server] api_key is not configured. Set a secret key in Grav Admin â Plugins â MCP Server.');
            http_response_code(401);
            echo json_encode(['jsonrpc' => '2.0', 'error' => ['code' => -32001, 'message' => 'API key not configured'], 'id' => $requestId]);
            return false;
        }
        $headers = getallheaders();
        if (isset($headers['Authorization'])) {
            $bearer = (strncasecmp($headers['Authorization'], 'Bearer ', 7) === 0)
                ? substr($headers['Authorization'], 7) : '';
            if ($bearer !== '' && hash_equals($apiKey, $bearer)) return true;
        }
        if (isset($headers['X-API-Key']) && hash_equals($apiKey, $headers['X-API-Key'])) {
            return true;
        }
        http_response_code(401);
        echo json_encode(['jsonrpc' => '2.0', 'error' => ['code' => -32001, 'message' => 'Invalid API key'], 'id' => $requestId]);
        return false;
    }

    private function authenticateOAuth($requestId): bool
    {
        $token = $this->config->get('plugins.mcp-server.token', '');
        $auth  = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!$token || $auth !== 'Bearer ' . $token) {
            http_response_code(401);
            echo json_encode(['jsonrpc' => '2.0', 'error' => ['code' => -32001, 'message' => 'Unauthorized'], 'id' => $requestId]);
            return false;
        }
        return true;
    }

    private function rpcResponse($id, array $result): void
    {
        echo json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    private function rpcError($id, int $code, string $message): void
    {
        echo json_encode(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // TOOLS LIST
    // ═══════════════════════════════════════════════════════════════════════

    private function getToolsList(): array
    {
        return [
            // ── Grav ──────────────────────────────────────────────────────
            ['name' => 'list_pages', 'title' => 'List pages',
             'description' => 'List all Grav pages with their routes, titles and available language variants',
             'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
             'annotations' => ['title' => 'List pages', 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false]],

            ['name' => 'get_page', 'title' => 'Get page',
             'description' => 'Retrieve a Grav page by its route, optionally for a specific language variant',
             'inputSchema' => ['type' => 'object', 'properties' => [
                 'route' => ['type' => 'string', 'description' => 'Page route, e.g. /blog/my-article'],
                 'lang'  => ['type' => 'string', 'description' => "Optional language code (e.g. 'en', 'fr'). Omit for default language (fr)."],
             ], 'required' => ['route']],
             'annotations' => ['title' => 'Get page', 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false]],

            ['name' => 'create_page', 'title' => 'Create page',
             'description' => 'Create a new Grav page at the given route with title and Markdown content, optionally as a language variant',
             'inputSchema' => ['type' => 'object', 'properties' => [
                 'route'   => ['type' => 'string', 'description' => 'New page route, e.g. /blog/new-article'],
                 'title'   => ['type' => 'string', 'description' => 'Page title'],
                 'content' => ['type' => 'string', 'description' => 'Markdown content of the page'],
                 'lang'    => ['type' => 'string', 'description' => "Optional language code (e.g. 'en', 'fr'). Omit for default version (default.md)."],
             ], 'required' => ['route', 'title', 'content']],
             'annotations' => ['title' => 'Create page', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false]],

            ['name' => 'update_page', 'title' => 'Update page',
             'description' => 'Update an existing Grav page content and/or title, optionally targeting a language variant',
             'inputSchema' => ['type' => 'object', 'properties' => [
                 'route'   => ['type' => 'string', 'description' => 'Existing page route'],
                 'content' => ['type' => 'string', 'description' => 'New Markdown content'],
                 'title'   => ['type' => 'string', 'description' => 'New page title'],
                 'lang'    => ['type' => 'string', 'description' => "Optional language code (e.g. 'en', 'fr'). Omit for default version (default.md)."],
             ], 'required' => ['route']],
             'annotations' => ['title' => 'Update page', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false]],

            ['name' => 'delete_page', 'title' => 'Delete page',
             'description' => 'Permanently delete a Grav page (or a single language variant) by its route',
             'inputSchema' => ['type' => 'object', 'properties' => [
                 'route' => ['type' => 'string', 'description' => 'Route of the page to delete'],
                 'lang'  => ['type' => 'string', 'pattern' => '^[a-z]{2,3}$',
                             'description' => 'Optional language code. If provided, deletes only this variant; deletes the folder only when no variants remain.'],
             ], 'required' => ['route']],
             'annotations' => ['title' => 'Delete page', 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false]],

            ['name' => 'clear_cache', 'title' => 'Clear Grav cache',
             'description' => 'Clear the Grav cache (equivalent to bin/grav clearcache)',
             'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
             'annotations' => ['title' => 'Clear Grav cache', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false]],

            ['name' => 'list_plugins', 'title' => 'List plugins',
             'description' => 'List all installed Grav plugins with their enabled status',
             'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
             'annotations' => ['title' => 'List plugins', 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false]],

            ['name' => 'list_themes', 'title' => 'List themes',
             'description' => 'List all installed Grav themes',
             'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
             'annotations' => ['title' => 'List themes', 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false]],

            ['name' => 'toggle_plugin', 'title' => 'Toggle plugin',
             'description' => 'Enable or disable a Grav plugin by name',
             'inputSchema' => ['type' => 'object', 'properties' => [
                 'name'    => ['type' => 'string',  'description' => 'Plugin slug, e.g. admin'],
                 'enabled' => ['type' => 'boolean', 'description' => 'true to enable, false to disable'],
             ], 'required' => ['name', 'enabled']],
             'annotations' => ['title' => 'Toggle plugin', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false]],

            // ── Cloudflare — cache ────────────────────────────────────────
            ['name' => 'cf_purge_cache', 'title' => 'Purge Cloudflare cache',
             'description' => 'Purge the entire Cloudflare CDN cache for arleo.eu (purge_everything)',
             'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
             'annotations' => ['title' => 'Purge Cloudflare cache', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false]],

            // ── Cloudflare — WAF ──────────────────────────────────────────
            ['name' => 'cf_list_waf_rules', 'title' => 'List Cloudflare WAF custom rules',
             'description' => 'List all Cloudflare WAF custom rules for arleo.eu',
             'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
             'annotations' => ['title' => 'List WAF rules', 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false]],

            ['name' => 'cf_list_banned_ips', 'title' => 'List banned IPs (Cloudflare WAF)',
             'description' => 'List all IPs currently blocked via Cloudflare WAF custom rules on arleo.eu',
             'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
             'annotations' => ['title' => 'List banned IPs', 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false]],

            ['name' => 'cf_ban_ip', 'title' => 'Ban IP (Cloudflare WAF)',
             'description' => 'Block an IP address via a Cloudflare WAF custom rule.',
             'inputSchema' => ['type' => 'object', 'properties' => [
                 'ip'      => ['type' => 'string', 'description' => 'IP address to block, e.g. 1.2.3.4'],
                 'comment' => ['type' => 'string', 'description' => 'Optional reason for the block'],
             ], 'required' => ['ip']],
             'annotations' => ['title' => 'Ban IP', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false]],

            ['name' => 'cf_unban_ip', 'title' => 'Unban IP (Cloudflare WAF)',
             'description' => 'Remove a Cloudflare WAF custom block rule for a given IP address.',
             'inputSchema' => ['type' => 'object', 'properties' => [
                 'ip' => ['type' => 'string', 'description' => 'IP address to unblock, e.g. 1.2.3.4'],
             ], 'required' => ['ip']],
             'annotations' => ['title' => 'Unban IP', 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false]],

            // ── Cloudflare — analytics ────────────────────────────────────
            ['name' => 'cf_get_analytics', 'title' => 'Get Cloudflare analytics',
             'description' => 'Retrieve recent traffic analytics for arleo.eu via Cloudflare GraphQL API (last N hours)',
             'inputSchema' => ['type' => 'object', 'properties' => [
                 'hours' => ['type' => 'integer', 'description' => 'Number of hours to look back (default: 24, max: 168)'],
             ]],
             'annotations' => ['title' => 'Get analytics', 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false]],

            // ── Cloudflare — page rules ───────────────────────────────────
            ['name' => 'cf_list_page_rules', 'title' => 'List Cloudflare page rules',
             'description' => 'List all Cloudflare page rules for arleo.eu',
             'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
             'annotations' => ['title' => 'List page rules', 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false]],

            ['name' => 'cf_create_page_rule', 'title' => 'Create Cloudflare page rule',
             'description' => 'Create a new Cloudflare page rule. Supports all actions: forwarding_url (redirect), cache_level, browser_cache_ttl, always_use_https, disable_cache, rocket_loader, security_level, ssl, response_buffering, cache_on_cookie, etc.',
             'inputSchema' => ['type' => 'object', 'properties' => [
                 'url'      => ['type' => 'string', 'description' => 'URL pattern to match, e.g. arleo.eu/blog/*'],
                 'actions'  => ['type' => 'array',  'description' => 'Array of action objects: [{id: "forwarding_url", value: {url: "https://...", status_code: 301}}]', 'items' => ['type' => 'object']],
                 'status'   => ['type' => 'string', 'description' => 'active or disabled (default: active)'],
                 'priority' => ['type' => 'integer','description' => 'Rule priority'],
             ], 'required' => ['url', 'actions']],
             'annotations' => ['title' => 'Create page rule', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false]],

            ['name' => 'cf_update_page_rule', 'title' => 'Update Cloudflare page rule',
             'description' => 'Update an existing Cloudflare page rule by id: change URL pattern, actions (forwarding_url, cache_level, browser_cache_ttl, always_use_https, disable_cache, rocket_loader, security_level, ssl, cache_on_cookie, etc.), status or priority',
             'inputSchema' => ['type' => 'object', 'properties' => [
                 'rule_id'  => ['type' => 'string', 'description' => 'Page rule ID (from cf_list_page_rules)'],
                 'url'      => ['type' => 'string', 'description' => 'New URL pattern, e.g. arleo.eu/old/*'],
                 'actions'  => ['type' => 'array',  'description' => 'Array of action objects', 'items' => ['type' => 'object']],
                 'status'   => ['type' => 'string', 'description' => 'active or disabled'],
                 'priority' => ['type' => 'integer','description' => 'Rule priority'],
             ], 'required' => ['rule_id']],
             'annotations' => ['title' => 'Update page rule', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false]],

            ['name' => 'cf_delete_page_rule', 'title' => 'Delete Cloudflare page rule',
             'description' => 'Delete a Cloudflare page rule by id',
             'inputSchema' => ['type' => 'object', 'properties' => [
                 'rule_id' => ['type' => 'string', 'description' => 'Page rule ID (from cf_list_page_rules)'],
             ], 'required' => ['rule_id']],
             'annotations' => ['title' => 'Delete page rule', 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false]],

            // ── Cloudflare — cache rules ──────────────────────────────────
            ['name' => 'cf_list_cache_rules', 'title' => 'List Cloudflare cache rules',
             'description' => 'List all cache rules (http_request_cache_settings phase) for arleo.eu',
             'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
             'annotations' => ['title' => 'List cache rules', 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false]],

            ['name' => 'cf_upsert_cache_rule', 'title' => 'Create or update Cloudflare cache rule',
             'description' => 'Create or update a rule in the Cloudflare cache rules ruleset (http_request_cache_settings). Supports cache eligibility, TTL overrides (edge, browser, stale-while-revalidate), cache keys, bypass conditions.',
             'inputSchema' => ['type' => 'object', 'properties' => [
                 'rule_id'     => ['type' => 'string',  'description' => 'Existing rule ID to update (omit to create)'],
                 'expression'  => ['type' => 'string',  'description' => 'Firewall expression, e.g. (http.request.uri.path matches "^/blog/")'],
                 'action'      => ['type' => 'string',  'description' => 'set_cache_settings or bypass_cache'],
                 'description' => ['type' => 'string',  'description' => 'Human-readable description'],
                 'settings'    => ['type' => 'object',  'description' => 'Cache settings object: {cache: true, edge_ttl: {mode:"override", value:3600}, browser_ttl: {mode:"override", value:300}}'],
                 'enabled'     => ['type' => 'boolean', 'description' => 'true to enable, false to disable'],
             ], 'required' => ['expression', 'action']],
             'annotations' => ['title' => 'Upsert cache rule', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false]],

            ['name' => 'cf_delete_cache_rule', 'title' => 'Delete Cloudflare cache rule',
             'description' => 'Delete a rule from the Cloudflare cache rules ruleset by rule id',
             'inputSchema' => ['type' => 'object', 'properties' => [
                 'rule_id' => ['type' => 'string', 'description' => 'Rule ID to delete (from cf_list_cache_rules)'],
             ], 'required' => ['rule_id']],
             'annotations' => ['title' => 'Delete cache rule', 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false]],

            // ── Cloudflare — redirect rules ───────────────────────────────
            ['name' => 'cf_list_redirect_rules', 'title' => 'List Cloudflare redirect rules',
             'description' => 'List all redirect rules (http_request_redirect phase) for arleo.eu',
             'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
             'annotations' => ['title' => 'List redirect rules', 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false]],

            ['name' => 'cf_upsert_redirect_rule', 'title' => 'Create or update Cloudflare redirect rule',
             'description' => 'Create or update a redirect rule (http_request_redirect phase). Use for 301/302 redirects with full dynamic URL rewriting support.',
             'inputSchema' => ['type' => 'object', 'properties' => [
                 'rule_id'     => ['type' => 'string',  'description' => 'Existing rule ID to update (omit to create)'],
                 'expression'  => ['type' => 'string',  'description' => 'Firewall expression, e.g. (http.request.uri.path eq "/old-page")'],
                 'target_url'  => ['type' => 'string',  'description' => 'Redirect destination URL, supports dynamic expressions e.g. concat("https://arleo.eu/new/", http.request.uri.path)'],
                 'status_code' => ['type' => 'integer', 'description' => '301 or 302 (default: 301)'],
                 'description' => ['type' => 'string',  'description' => 'Human-readable description'],
                 'enabled'     => ['type' => 'boolean', 'description' => 'true to enable (default), false to disable'],
             ], 'required' => ['expression', 'target_url']],
             'annotations' => ['title' => 'Upsert redirect rule', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false]],

            ['name' => 'cf_delete_redirect_rule', 'title' => 'Delete Cloudflare redirect rule',
             'description' => 'Delete a redirect rule by id from the http_request_redirect ruleset',
             'inputSchema' => ['type' => 'object', 'properties' => [
                 'rule_id' => ['type' => 'string', 'description' => 'Rule ID to delete (from cf_list_redirect_rules)'],
             ], 'required' => ['rule_id']],
             'annotations' => ['title' => 'Delete redirect rule', 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false]],

            // ── Cloudflare — header rules ─────────────────────────────────
            ['name' => 'cf_list_header_rules', 'title' => 'List Cloudflare header modification rules',
             'description' => 'List all HTTP response/request header modification rules (http_response_headers_transform and http_request_late_transform phases) for arleo.eu',
             'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
             'annotations' => ['title' => 'List header rules', 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false]],

            ['name' => 'cf_upsert_header_rule', 'title' => 'Create or update Cloudflare header rule',
             'description' => 'Create or update an HTTP header modification rule. Can set, add or remove request or response headers. Phase: http_response_headers_transform (response) or http_request_late_transform (request).',
             'inputSchema' => ['type' => 'object', 'properties' => [
                 'rule_id'     => ['type' => 'string',  'description' => 'Existing rule ID to update (omit to create)'],
                 'phase'       => ['type' => 'string',  'description' => 'http_response_headers_transform or http_request_late_transform'],
                 'expression'  => ['type' => 'string',  'description' => 'Firewall expression, e.g. (true) for all requests'],
                 'description' => ['type' => 'string',  'description' => 'Human-readable description'],
                 'headers'     => ['type' => 'array',   'description' => 'Array of header operations: [{operation: "set"|"add"|"remove", name: "X-My-Header", value: "foo"}]', 'items' => ['type' => 'object']],
                 'enabled'     => ['type' => 'boolean', 'description' => 'true to enable (default), false to disable'],
             ], 'required' => ['phase', 'expression', 'headers']],
             'annotations' => ['title' => 'Upsert header rule', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false]],

            ['name' => 'cf_delete_header_rule', 'title' => 'Delete Cloudflare header rule',
             'description' => 'Delete an HTTP header modification rule by id and phase',
             'inputSchema' => ['type' => 'object', 'properties' => [
                 'rule_id' => ['type' => 'string', 'description' => 'Rule ID to delete'],
                 'phase'   => ['type' => 'string', 'description' => 'http_response_headers_transform or http_request_late_transform'],
             ], 'required' => ['rule_id', 'phase']],
             'annotations' => ['title' => 'Delete header rule', 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false]],
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ROUTER
    // ═══════════════════════════════════════════════════════════════════════

    private function callTool(string $tool, array $args): array
    {
        switch ($tool) {
            // Grav
            case 'list_pages':    return $this->toolListPages();
            case 'get_page':      return $this->toolGetPage($args);
            case 'create_page':   return $this->toolCreatePage($args);
            case 'update_page':   return $this->toolUpdatePage($args);
            case 'delete_page':   return $this->toolDeletePage($args);
            case 'clear_cache':   return $this->toolClearCache();
            case 'list_plugins':  return $this->toolListPlugins();
            case 'list_themes':   return $this->toolListThemes();
            case 'toggle_plugin': return $this->toolTogglePlugin($args);
            // Cloudflare — cache
            case 'cf_purge_cache':        return $this->toolCfPurgeCache();
            // Cloudflare — WAF
            case 'cf_list_waf_rules':     return $this->toolCfListWafRules();
            case 'cf_list_banned_ips':    return $this->toolCfListBannedIps();
            case 'cf_ban_ip':             return $this->toolCfBanIp($args);
            case 'cf_unban_ip':           return $this->toolCfUnbanIp($args);
            // Cloudflare — analytics
            case 'cf_get_analytics':      return $this->toolCfGetAnalytics($args);
            // Cloudflare — page rules
            case 'cf_list_page_rules':    return $this->toolCfListPageRules();
            case 'cf_create_page_rule':   return $this->toolCfCreatePageRule($args);
            case 'cf_update_page_rule':   return $this->toolCfUpdatePageRule($args);
            case 'cf_delete_page_rule':   return $this->toolCfDeletePageRule($args);
            // Cloudflare — cache rules
            case 'cf_list_cache_rules':   return $this->toolCfListRulesetPhase('http_request_cache_settings');
            case 'cf_upsert_cache_rule':  return $this->toolCfUpsertCacheRule($args);
            case 'cf_delete_cache_rule':  return $this->toolCfDeleteRuleFromPhase($args, 'http_request_cache_settings');
            // Cloudflare — redirect rules
            case 'cf_list_redirect_rules':  return $this->toolCfListRulesetPhase('http_request_redirect');
            case 'cf_upsert_redirect_rule': return $this->toolCfUpsertRedirectRule($args);
            case 'cf_delete_redirect_rule': return $this->toolCfDeleteRuleFromPhase($args, 'http_request_redirect');
            // Cloudflare — header rules
            case 'cf_list_header_rules':  return $this->toolCfListHeaderRules();
            case 'cf_upsert_header_rule': return $this->toolCfUpsertHeaderRule($args);
            case 'cf_delete_header_rule': return $this->toolCfDeleteRuleFromPhaseByArg($args);
            default:
                return ['content' => [['type' => 'text', 'text' => "Unknown tool: $tool"]], 'isError' => true];
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // GRAV TOOLS
    // ═══════════════════════════════════════════════════════════════════════

    private function toolListPages(): array
    {
        $pages  = $this->grav['pages']->all();
        $result = [];
        foreach ($pages as $page) {
            $pageDir   = $page->path();
            $langSet   = [];
            // Detect any {template}.{lang}.md file (item.en.md, default.fr.md, blog.en.md, etc.)
            foreach (glob($pageDir . '/*.md') ?: [] as $f) {
                $stem = basename($f, '.md'); // e.g. "default.fr", "item.en"
                if (preg_match('/^.+\.([a-z]{2,3})$/', $stem, $m)) {
                    $langSet[$m[1]] = true;
                }
            }
            $languages = array_keys($langSet);
            sort($languages);
            $result[] = ['route' => $page->route(), 'title' => $page->title(), 'published' => $page->published(), 'path' => $page->filePath(), 'languages' => $languages];
        }
        return ['content' => [['type' => 'text', 'text' => json_encode($result, JSON_PRETTY_PRINT)]]];
    }

    private function toolGetPage(array $args): array
    {
        // Accept both 'lang' (preferred) and 'language' (legacy) parameter names
        $lang = $args['lang'] ?? $args['language'] ?? '';
        if ($lang !== '' && !preg_match('/^[a-z]{2,3}$/', $lang))
            return ['content' => [['type' => 'text', 'text' => "Invalid language code: $lang"]], 'isError' => true];
        $page = $this->grav['pages']->find($args['route'] ?? '');
        if (!$page) return ['content' => [['type' => 'text', 'text' => 'Page not found']], 'isError' => true];
        if ($lang !== '') {
            // Try {template}.{lang}.md first (item.en.md, blog.en.md, etc.), then default.{lang}.md
            $candidates = [
                $page->path() . '/' . $page->template() . '.' . $lang . '.md',
                $page->path() . '/default.' . $lang . '.md',
            ];
            $filePath = null;
            foreach ($candidates as $c) {
                if (file_exists($c)) { $filePath = $c; break; }
            }
            if ($filePath === null)
                return ['content' => [['type' => 'text', 'text' => "Page variant not found: {$args['route']} (lang: $lang)"]], 'isError' => true];
            $raw = file_get_contents($filePath);
            $header = []; $content = $raw;
            if (preg_match('/^---\r?\n(.*?)\r?\n---\r?\n(.*)/s', $raw, $m)) {
                $header = \Grav\Common\Yaml::parse($m[1]) ?: [];
                $content = ltrim($m[2]);
            }
            return ['content' => [['type' => 'text', 'text' => json_encode(['route' => $page->route(), 'lang' => $lang, 'title' => $header['title'] ?? '', 'content' => $content, 'header' => $header], JSON_PRETTY_PRINT)]]];
        }
        return ['content' => [['type' => 'text', 'text' => json_encode(['route' => $page->route(), 'title' => $page->title(), 'content' => $page->rawMarkdown(), 'header' => (array)$page->header()], JSON_PRETTY_PRINT)]]];
    }

    private static $VALID_TAGS = ['crowdsec', 'nginx', 'vector', 'cloudflare', 'incident', 'sécurité', 'infrastructure', 'réseau', 'monitoring', 'homelab', 'grav', 'seo', 'indexnow', 'mcp', 'claude', 'javascript'];

    private function validatePageFrontmatter(array $header): ?string
    {
        $author = $header['author'] ?? null;
        if (empty($author))
            return "Champ obligatoire manquant : 'author'. Ajouter 'author: Jm Rohmer' (ou nom réel) dans le frontmatter.";
        $tags = $header['taxonomy']['tag'] ?? [];
        if (empty($tags))
            return "Champ obligatoire manquant : 'taxonomy.tag'. Ajouter au moins un tag parmi : " . implode(', ', self::$VALID_TAGS);
        $unknown = array_filter((array)$tags, fn($t) => !in_array($t, self::$VALID_TAGS, true));
        if (!empty($unknown))
            return "Tags inconnus : " . implode(', ', $unknown) . ". Tags valides : " . implode(', ', self::$VALID_TAGS);
        return null;
    }

    private function toolCreatePage(array $args): array
    {
        $route = $args['route'] ?? ''; $title = $args['title'] ?? 'New Page'; $content = $args['content'] ?? ''; $lang = $args['lang'] ?? $args['language'] ?? '';
        if ($lang !== '' && !preg_match('/^[a-z]{2,3}$/', $lang))
            return ['content' => [['type' => 'text', 'text' => "Invalid language code: $lang"]], 'isError' => true];
        $pagesRoot = $this->grav['locator']->findResource('page://');
        try {
            $routePath = $this->_safe_route_path($route, $pagesRoot);
        } catch (\RuntimeException $e) {
            return ['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true];
        }
        if (strpos($routePath, '/') === false) {
            $existingDir = null;
            foreach (glob($pagesRoot . '/*/') as $dir) {
                $base = basename($dir);
                if ($base === $routePath || preg_match('/^\d+\.' . preg_quote($routePath, '/') . '$/', $base)) {
                    $existingDir = $base;
                    break;
                }
            }
            if ($existingDir !== null) {
                $dirName = $existingDir;
            } else {
                $prefix  = $this->_nextPagePrefix($pagesRoot);
                $dirName = $prefix !== null ? $prefix . '.' . $routePath : $routePath;
            }
        } else {
            $dirName = $routePath;
        }
        $pageDir  = $pagesRoot . '/' . $dirName;
        $filename = $lang !== '' ? "default.$lang.md" : 'default.md';
        $filePath = $pageDir . '/' . $filename;
        if (file_exists($filePath))
            return ['content' => [['type' => 'text', 'text' => $lang !== '' ? "Page variant already exists: $route (language: $lang)" : "Page already exists: $route"]], 'isError' => true];

        // Detect embedded frontmatter in content — avoid double-wrapping
        if (preg_match('/^---\r?\n(.*?)\r?\n---\r?\n/s', $content, $fm)) {
            $header = \Grav\Common\Yaml::parse($fm[1]) ?: [];
            $fileContent = $content;
        } else {
            $header = ['title' => $title];
            $fileContent = "---\ntitle: $title\n---\n\n$content";
        }

        $err = $this->validatePageFrontmatter($header);
        if ($err !== null)
            return ['content' => [['type' => 'text', 'text' => $err]], 'isError' => true];

        $this->ensureTagPagesExist((array)($header['taxonomy']['tag'] ?? []));

        // Render raw markdown inside HTML div blocks before writing to disk
        if (preg_match('/^(---\r?\n.*?\r?\n---\r?\n)(.*)$/s', $fileContent, $parts)) {
            $fileContent = $parts[1] . $this->renderMarkdownContent($parts[2]);
        } else {
            $fileContent = $this->renderMarkdownContent($fileContent);
        }

        if (!is_dir($pageDir)) mkdir($pageDir, 0755, true);
        file_put_contents($filePath, $fileContent);
        $this->clearCacheFiles();
        $this->notifyPageSaved($route);
        return ['content' => [['type' => 'text', 'text' => $lang !== '' ? "Page variant created: $route (language: $lang)" : "Page created: $route"]]];
    }


    private function _safe_route_path(string $route, string $pagesRoot): string
    {
        $clean = trim($route, '/');
        if ($clean === '') {
            throw new \RuntimeException("Empty route");
        }
        foreach (explode('/', $clean) as $segment) {
            if ($segment === '..' || $segment === '.') {
                throw new \RuntimeException("Invalid route (path traversal): $route");
            }
        }
        $realRoot = realpath($pagesRoot);
        if ($realRoot === false) {
            throw new \RuntimeException("Pages root inaccessible");
        }
        return $clean;
    }

    private function _nextPagePrefix(string $pagesRoot): ?int
    {
        $max = null;
        foreach (glob($pagesRoot . '/*/') as $dir) {
            if (preg_match('/^(\d+)\./', basename($dir), $m)) {
                $n = (int)$m[1];
                if ($max === null || $n > $max) $max = $n;
            }
        }
        return $max !== null ? $max + 1 : null;
    }

    private function toolUpdatePage(array $args): array
    {
        $lang = $args['lang'] ?? $args['language'] ?? '';
        if ($lang !== '' && !preg_match('/^[a-z]{2,3}$/', $lang))
            return ['content' => [['type' => 'text', 'text' => "Invalid language code: $lang"]], 'isError' => true];
        $page = $this->grav['pages']->find($args['route'] ?? '');
        if (!$page) return ['content' => [['type' => 'text', 'text' => 'Page not found']], 'isError' => true];
        // Resolve the language-variant file: prefer {template}.{lang}.md, fall back to default.{lang}.md
        if ($lang !== '') {
            $templateFile = $page->path() . '/' . $page->template() . '.' . $lang . '.md';
            $defaultFile  = $page->path() . '/default.' . $lang . '.md';
            $filePath = file_exists($templateFile) ? $templateFile : $defaultFile;
        } else {
            $filePath = $page->filePath();
        }
        if ($lang !== '' && !file_exists($filePath))
            return ['content' => [['type' => 'text', 'text' => "Page variant not found: {$args['route']} (language: $lang)"]], 'isError' => true];
        $current = file_get_contents($filePath);
        if (!empty($args['title'])) $current = preg_replace('/^title:.*$/m', 'title: ' . $args['title'], $current);
        if (isset($args['content'])) {
            // Extract tags from incoming content and ensure their tag pages exist
            $newBody = $args['content'];
            if (preg_match('/^---\r?\n(.*?)\r?\n---\r?\n/s', $newBody, $fmMatch)) {
                $fmHeader = \Grav\Common\Yaml::parse($fmMatch[1]) ?: [];
                $this->ensureTagPagesExist((array)($fmHeader['taxonomy']['tag'] ?? []));
            }
            $newBody = $args['content'];
            // Strip embedded frontmatter sent by the caller — prevents double-frontmatter bug
            if (preg_match('/^---\r?\n.*?\r?\n---\r?\n(.*)$/s', $newBody, $stripped)) {
                $newBody = $stripped[1];
            }
            // Render raw markdown inside HTML div blocks before writing to disk
            $newBody = $this->renderMarkdownContent($newBody);
            $current = preg_replace('/^---\r?\n(.*?\r?\n)---\r?\n.*/s', "---\n$1---\n\n" . $newBody, $current);
        }
        file_put_contents($filePath, $current);
        $this->clearCacheFiles();
        $this->notifyPageSaved($args['route']);
        return ['content' => [['type' => 'text', 'text' => $lang !== '' ? "Page variant updated: {$args['route']} (language: $lang)" : 'Page updated: ' . $args['route']]]];
    }

    private function ensureTagPagesExist(array $tags): void
    {
        $tagsDir = '/var/www/grav/user/pages/12.tag';

        $maxPrefix = 0;
        foreach (glob("$tagsDir/*/") as $dir) {
            $name = basename($dir);
            if (preg_match('/^(\d+)\./', $name, $m)) {
                $maxPrefix = max($maxPrefix, (int)$m[1]);
            }
        }

        foreach ($tags as $tag) {
            $exists = false;
            foreach (glob("$tagsDir/*/") as $dir) {
                $base = basename($dir);
                if ($base === $tag || preg_match('/^\d+\.' . preg_quote($tag, '/') . '$/', $base)) {
                    $exists = true;
                    break;
                }
            }

            if (!$exists) {
                $maxPrefix++;
                $tagDir = "$tagsDir/$maxPrefix.$tag";
                mkdir($tagDir, 0755, true);
                chown($tagDir, 'www-data');

                $content = "---\ntitle: '$tag'\nslug: $tag\n" .
                           "visible: false\nroutable: true\n" .
                           "template: tag\nsitemap:\n" .
                           "    ignore: true\n---\n";
                file_put_contents("$tagDir/default.md", $content);
                chown("$tagDir/default.md", 'www-data');

                if (!in_array($tag, self::$VALID_TAGS, true)) {
                    self::$VALID_TAGS[] = $tag;
                }

                $this->grav['log']->info("[MCP Server] Tag page created: $tag → $tagDir");
            }
        }
    }

    /**
     * Convert raw Markdown inside HTML div blocks to HTML before writing to disk.
     * Grav's ParsedownExtra does not process markdown="1" in this configuration.
     * Processes ANY div whose inner content contains raw Markdown headers (## / ###)
     * and has not already been converted to HTML.
     */
    private function renderMarkdownContent(string $body): string
    {
        return preg_replace_callback(
            '/(<div\b[^>]*>)(.*?)(<\/div>)/si',
            static function (array $m): string {
                $inner = trim($m[2]);
                if ($inner === '') return $m[0];
                // Process if any raw markdown syntax is present
                $hasMarkdown = preg_match('/^#{1,6}\s/m', $inner)
                             || preg_match('/\[.+?\]\(.+?\)/', $inner)
                             || preg_match('/^\s*[-*]\s/m', $inner)
                             || preg_match('/^\s*\d+\.\s/m', $inner);
                if (!$hasMarkdown) return $m[0];
                // Skip nested divs — avoid mangling complex structures
                if (stripos($inner, '<div') !== false) return $m[0];
                // ParsedownExtra handles mixed HTML+markdown: passes HTML through, converts markdown
                try {
                    $pd = new \ParsedownExtra();
                    $html = $pd->text($inner);
                    // Strip markdown="1" from the opening tag (now redundant)
                    $openTag = preg_replace('/\s+markdown="1"/', '', $m[1]);
                    return $openTag . "\n" . $html . "\n" . $m[3];
                } catch (\Throwable $e) {
                    return $m[0]; // pass through unchanged on any error
                }
            },
            $body
        );
    }

    private function toolDeletePage(array $args): array
    {
        $route = $args['route'] ?? '';
        $lang  = $args['lang']  ?? '';

        if ($lang !== '' && !preg_match('/^[a-z]{2,3}$/', $lang))
            return ['content' => [['type' => 'text', 'text' => "Invalid lang: $lang"]], 'isError' => true];

        $page = $this->grav['pages']->find($route);
        if (!$page) return ['content' => [['type' => 'text', 'text' => 'Page not found']], 'isError' => true];

        if ($lang !== '') {
            $template   = $page->template();
            $candidates = [
                $page->path() . '/' . $template . '.' . $lang . '.md',
                $page->path() . '/default.' . $lang . '.md',
            ];
            $deleted = false;
            foreach ($candidates as $f) {
                if (file_exists($f)) {
                    unlink($f);
                    $deleted = true;
                    break;
                }
            }
            if (!$deleted)
                return ['content' => [['type' => 'text', 'text' => "Variant not found: $lang"]], 'isError' => true];

            $remaining = glob($page->path() . '/*.md') ?: [];
            if (count($remaining) === 0) {
                \Grav\Common\Filesystem\Folder::delete($page->path());
                $this->clearCacheFiles();
                return ['content' => [['type' => 'text', 'text' => "Deleted variant '$lang' and empty folder for: $route"]]];
            }
            $this->clearCacheFiles();
            return ['content' => [['type' => 'text', 'text' => "Deleted variant '$lang' for: $route (other variants kept)"]]];
        }

        \Grav\Common\Filesystem\Folder::delete($page->path());
        $this->clearCacheFiles();
        return ['content' => [['type' => 'text', 'text' => 'Page deleted: ' . $route]]];
    }

    private function toolClearCache(): array
    {
        $this->clearCacheFiles();
        return ['content' => [['type' => 'text', 'text' => 'Cache cleared']]];
    }

    private function toolListPlugins(): array
    {
        $result = []; $plugins = \Grav\Common\Plugins::all(); $config = $this->grav['config'];
        foreach ($plugins as $name => $plugin) {
            $bp = $plugin->blueprints();
            $result[] = ['name' => $name, 'enabled' => (bool)$config->get("plugins.{$name}.enabled", false), 'version' => $bp ? $bp->get('version', '?') : '?', 'description' => $bp ? $bp->get('description', '') : ''];
        }
        usort($result, fn($a, $b) => strcmp($a['name'], $b['name']));
        return ['content' => [['type' => 'text', 'text' => json_encode($result, JSON_PRETTY_PRINT)]]];
    }

    private function toolListThemes(): array
    {
        $themesDir = $this->grav['locator']->findResource('user://themes');
        $themes = [];
        foreach (glob($themesDir . '/*', GLOB_ONLYDIR) as $dir) {
            $name = basename($dir);
            $themes[] = ['name' => $name, 'active' => ($name === $this->config->get('system.theme'))];
        }
        return ['content' => [['type' => 'text', 'text' => json_encode($themes, JSON_PRETTY_PRINT)]]];
    }

    private function toolTogglePlugin(array $args): array
    {
        $name = $args['name'] ?? ''; $enabled = (bool)($args['enabled'] ?? true);
        $yaml = $this->grav['locator']->findResource('user://config/plugins') . '/' . $name . '.yaml';
        if (!file_exists($yaml)) return ['content' => [['type' => 'text', 'text' => "Plugin not found: $name"]], 'isError' => true];
        file_put_contents($yaml, preg_replace('/^enabled:.*$/m', 'enabled: ' . ($enabled ? 'true' : 'false'), file_get_contents($yaml)));
        $this->clearCacheFiles();
        return ['content' => [['type' => 'text', 'text' => "Plugin $name " . ($enabled ? 'enabled' : 'disabled')]]];
    }

    private function notifyPageSaved(string $route): void
    {
        $this->grav->fireEvent('onMcpAfterSave', new Event(['route' => $route]));
    }

    private function clearCacheFiles(): void
    {
        \Grav\Common\Cache::clearCache('standard');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // CLOUDFLARE HELPERS
    // ═══════════════════════════════════════════════════════════════════════

    private function cfRequest(string $method, string $path, ?array $data = null): array
    {
        $cfToken = $this->config->get('plugins.mcp-server.cf_token', '');
        $url     = 'https://api.cloudflare.com/client/v4' . $path;
        $body    = $data !== null ? json_encode($data) : null;
        $opts    = ['http' => [
            'method'        => $method,
            'header'        => "Authorization: Bearer {$cfToken}\r\nContent-Type: application/json",
            'content'       => $body,
            'timeout'       => 15,
            'ignore_errors' => true,
        ]];
        $raw    = file_get_contents($url, false, stream_context_create($opts));
        if ($raw === false) return ['success' => false, 'errors' => [['message' => 'HTTP request failed']]];
        $result = json_decode($raw, true);
        if (!is_array($result)) return ['success' => false, 'errors' => [['message' => 'Invalid JSON response']]];
        return $result;
    }

    private function cfZoneId(): string
    {
        return $this->config->get('plugins.mcp-server.cf_zone_id', '');
    }

    private function cfGetOrCreateRuleset(string $zoneId, string $phase): array
    {
        $rsResult = $this->cfRequest('GET', "/zones/{$zoneId}/rulesets");
        if (!($rsResult['success'] ?? false))
            return ['error' => 'Cannot list rulesets: ' . json_encode($rsResult['errors'] ?? [])];
        foreach ($rsResult['result'] ?? [] as $rs) {
            if (($rs['phase'] ?? '') === $phase && ($rs['kind'] ?? '') === 'zone')
                return ['id' => $rs['id']];
        }
        $create = $this->cfRequest('POST', "/zones/{$zoneId}/rulesets", ['name' => $phase, 'kind' => 'zone', 'phase' => $phase, 'rules' => []]);
        if (!($create['success'] ?? false))
            return ['error' => 'Cannot create ruleset: ' . json_encode($create['errors'] ?? [])];
        return ['id' => $create['result']['id']];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // CLOUDFLARE TOOLS
    // ═══════════════════════════════════════════════════════════════════════

    private function toolCfPurgeCache(): array
    {
        $zoneId = $this->cfZoneId();
        if (!$zoneId) return ['content' => [['type' => 'text', 'text' => 'cf_zone_id not configured']], 'isError' => true];
        $result = $this->cfRequest('POST', "/zones/{$zoneId}/purge_cache", ['purge_everything' => true]);
        if (!($result['success'] ?? false))
            return ['content' => [['type' => 'text', 'text' => 'Purge failed: ' . json_encode($result['errors'] ?? [])]], 'isError' => true];
        return ['content' => [['type' => 'text', 'text' => 'Cloudflare cache purged successfully (purge_everything)']]];
    }

    private function toolCfListWafRules(): array
    {
        $zoneId = $this->cfZoneId();
        $rs = $this->cfGetOrCreateRuleset($zoneId, 'http_request_firewall_custom');
        if (isset($rs['error'])) return ['content' => [['type' => 'text', 'text' => $rs['error']]], 'isError' => true];
        $detail = $this->cfRequest('GET', "/zones/{$zoneId}/rulesets/{$rs['id']}");
        if (!($detail['success'] ?? false)) return ['content' => [['type' => 'text', 'text' => 'Cannot get ruleset detail']], 'isError' => true];
        $rules = array_map(fn($r) => ['id' => $r['id'] ?? '', 'description' => $r['description'] ?? '', 'expression' => $r['expression'] ?? '', 'action' => $r['action'] ?? '', 'enabled' => $r['enabled'] ?? true], $detail['result']['rules'] ?? []);
        return ['content' => [['type' => 'text', 'text' => json_encode($rules, JSON_PRETTY_PRINT)]]];
    }

    private function toolCfListBannedIps(): array
    {
        $zoneId = $this->cfZoneId();
        $rs = $this->cfGetOrCreateRuleset($zoneId, 'http_request_firewall_custom');
        if (isset($rs['error'])) return ['content' => [['type' => 'text', 'text' => $rs['error']]], 'isError' => true];
        $detail = $this->cfRequest('GET', "/zones/{$zoneId}/rulesets/{$rs['id']}");
        if (!($detail['success'] ?? false)) return ['content' => [['type' => 'text', 'text' => 'Cannot get ruleset detail']], 'isError' => true];
        $banned = [];
        foreach ($detail['result']['rules'] ?? [] as $rule) {
            if (($rule['action'] ?? '') === 'block')
                $banned[] = ['id' => $rule['id'], 'expression' => $rule['expression'] ?? '', 'description' => $rule['description'] ?? '', 'enabled' => $rule['enabled'] ?? true];
        }
        return ['content' => [['type' => 'text', 'text' => json_encode($banned, JSON_PRETTY_PRINT)]]];
    }

    private function toolCfBanIp(array $args): array
    {
        $ip      = trim($args['ip'] ?? '');
        $comment = $args['comment'] ?? 'Banned via MCP';
        if (!$ip) return ['content' => [['type' => 'text', 'text' => 'Missing ip parameter']], 'isError' => true];
        $bare = preg_replace('/\/\d+$/', '', $ip);
        if (!filter_var($bare, FILTER_VALIDATE_IP))
            return ['content' => [['type' => 'text', 'text' => "Invalid IP: $ip"]], 'isError' => true];
        $zoneId = $this->cfZoneId();
        $rs = $this->cfGetOrCreateRuleset($zoneId, 'http_request_firewall_custom');
        if (isset($rs['error'])) return ['content' => [['type' => 'text', 'text' => $rs['error']]], 'isError' => true];
        $addResult = $this->cfRequest('POST', "/zones/{$zoneId}/rulesets/{$rs['id']}/rules", ['action' => 'block', 'expression' => "(ip.src eq {$ip})", 'description' => $comment, 'enabled' => true]);
        if (!($addResult['success'] ?? false))
            return ['content' => [['type' => 'text', 'text' => 'Cannot add rule: ' . json_encode($addResult['errors'] ?? [])]], 'isError' => true];
        return ['content' => [['type' => 'text', 'text' => "IP {$ip} blocked (rule id: " . ($addResult['result']['id'] ?? 'unknown') . ")"]]];
    }

    private function toolCfUnbanIp(array $args): array
    {
        $ip = trim($args['ip'] ?? '');
        if (!$ip) return ['content' => [['type' => 'text', 'text' => 'Missing ip parameter']], 'isError' => true];
        $zoneId = $this->cfZoneId();
        $rs = $this->cfGetOrCreateRuleset($zoneId, 'http_request_firewall_custom');
        if (isset($rs['error'])) return ['content' => [['type' => 'text', 'text' => $rs['error']]], 'isError' => true];
        $detail = $this->cfRequest('GET', "/zones/{$zoneId}/rulesets/{$rs['id']}");
        if (!($detail['success'] ?? false)) return ['content' => [['type' => 'text', 'text' => 'Cannot get ruleset detail']], 'isError' => true];
        $removed = 0;
        foreach ($detail['result']['rules'] ?? [] as $rule) {
            if (strpos($rule['expression'] ?? '', $ip) !== false && ($rule['action'] ?? '') === 'block') {
                $del = $this->cfRequest('DELETE', "/zones/{$zoneId}/rulesets/{$rs['id']}/rules/{$rule['id']}");
                if ($del['success'] ?? false) $removed++;
            }
        }
        if ($removed === 0) return ['content' => [['type' => 'text', 'text' => "No block rule found for IP: {$ip}"]], 'isError' => true];
        return ['content' => [['type' => 'text', 'text' => "IP {$ip} unblocked ({$removed} rule(s) removed)"]]];
    }

    private function toolCfGetAnalytics(array $args): array
    {
        $hours   = min((int)($args['hours'] ?? 24), 168);
        $zoneId  = $this->cfZoneId();
        $cfToken = $this->config->get('plugins.mcp-server.cf_token', '');
        $since   = date('Y-m-d\TH:i:s\Z', strtotime("-{$hours} hours"));
        $until   = date('Y-m-d\TH:i:s\Z');
        $query   = json_encode(['query' => "{ viewer { zones(filter: {zoneTag: \"{$zoneId}\"}) { httpRequests1hGroups(limit: 168, filter: {datetime_geq: \"{$since}\", datetime_leq: \"{$until}\"}) { sum { requests threats cachedRequests bytes } dimensions { datetime } } } } }"]);
        $opts    = ['http' => ['method' => 'POST', 'header' => "Authorization: Bearer {$cfToken}\r\nContent-Type: application/json", 'content' => $query, 'timeout' => 15, 'ignore_errors' => true]];
        $raw     = file_get_contents('https://api.cloudflare.com/client/v4/graphql', false, stream_context_create($opts));
        if ($raw === false) return ['content' => [['type' => 'text', 'text' => 'GraphQL request failed']], 'isError' => true];
        $data   = json_decode($raw, true);
        $groups = $data['data']['viewer']['zones'][0]['httpRequests1hGroups'] ?? [];
        $total  = ['requests' => 0, 'threats' => 0, 'cachedRequests' => 0, 'bytes' => 0];
        foreach ($groups as $g) { foreach ($total as $k => $_) $total[$k] += $g['sum'][$k] ?? 0; }
        return ['content' => [['type' => 'text', 'text' => json_encode(['period_hours' => $hours, 'since' => $since, 'total' => $total, 'hourly_buckets' => $groups], JSON_PRETTY_PRINT)]]];
    }

    private function toolCfListPageRules(): array
    {
        $zoneId = $this->cfZoneId();
        $result = $this->cfRequest('GET', "/zones/{$zoneId}/pagerules?status=active&order=priority&direction=asc");
        if (!($result['success'] ?? false))
            return ['content' => [['type' => 'text', 'text' => 'Cannot list page rules: ' . json_encode($result['errors'] ?? [])]], 'isError' => true];
        $rules = array_map(fn($r) => ['id' => $r['id'], 'status' => $r['status'], 'priority' => $r['priority'], 'targets' => array_column($r['targets'] ?? [], 'constraint'), 'actions' => array_column($r['actions'] ?? [], 'id')], $result['result'] ?? []);
        return ['content' => [['type' => 'text', 'text' => json_encode($rules, JSON_PRETTY_PRINT)]]];
    }

    private function toolCfCreatePageRule(array $args): array
    {
        $zoneId  = $this->cfZoneId();
        $payload = ['targets' => [['target' => 'url', 'constraint' => ['operator' => 'matches', 'value' => $args['url']]]], 'actions' => $args['actions'], 'status' => $args['status'] ?? 'active'];
        if (isset($args['priority'])) $payload['priority'] = (int)$args['priority'];
        $result = $this->cfRequest('POST', "/zones/{$zoneId}/pagerules", $payload);
        if (!($result['success'] ?? false))
            return ['content' => [['type' => 'text', 'text' => 'Cannot create page rule: ' . json_encode($result['errors'] ?? [])]], 'isError' => true];
        return ['content' => [['type' => 'text', 'text' => 'Page rule created: ' . ($result['result']['id'] ?? '')]]];
    }

    private function toolCfUpdatePageRule(array $args): array
    {
        $zoneId = $this->cfZoneId(); $ruleId = $args['rule_id']; $payload = [];
        if (isset($args['url']))      $payload['targets']  = [['target' => 'url', 'constraint' => ['operator' => 'matches', 'value' => $args['url']]]];
        if (isset($args['actions']))  $payload['actions']  = $args['actions'];
        if (isset($args['status']))   $payload['status']   = $args['status'];
        if (isset($args['priority'])) $payload['priority'] = (int)$args['priority'];
        $result = $this->cfRequest('PATCH', "/zones/{$zoneId}/pagerules/{$ruleId}", $payload);
        if (!($result['success'] ?? false))
            return ['content' => [['type' => 'text', 'text' => 'Cannot update page rule: ' . json_encode($result['errors'] ?? [])]], 'isError' => true];
        return ['content' => [['type' => 'text', 'text' => "Page rule {$ruleId} updated"]]];
    }

    private function toolCfDeletePageRule(array $args): array
    {
        $zoneId = $this->cfZoneId(); $ruleId = $args['rule_id'];
        $result = $this->cfRequest('DELETE', "/zones/{$zoneId}/pagerules/{$ruleId}");
        if (!($result['success'] ?? false))
            return ['content' => [['type' => 'text', 'text' => 'Cannot delete page rule: ' . json_encode($result['errors'] ?? [])]], 'isError' => true];
        return ['content' => [['type' => 'text', 'text' => "Page rule {$ruleId} deleted"]]];
    }

    private function toolCfListRulesetPhase(string $phase): array
    {
        $zoneId = $this->cfZoneId();
        $rs = $this->cfGetOrCreateRuleset($zoneId, $phase);
        if (isset($rs['error'])) return ['content' => [['type' => 'text', 'text' => $rs['error']]], 'isError' => true];
        $detail = $this->cfRequest('GET', "/zones/{$zoneId}/rulesets/{$rs['id']}");
        if (!($detail['success'] ?? false)) return ['content' => [['type' => 'text', 'text' => "Cannot get ruleset for phase {$phase}"]], 'isError' => true];
        $rules = array_map(fn($r) => ['id' => $r['id'] ?? '', 'description' => $r['description'] ?? '', 'expression' => $r['expression'] ?? '', 'action' => $r['action'] ?? '', 'action_parameters' => $r['action_parameters'] ?? [], 'enabled' => $r['enabled'] ?? true], $detail['result']['rules'] ?? []);
        return ['content' => [['type' => 'text', 'text' => json_encode($rules, JSON_PRETTY_PRINT)]]];
    }

    private function toolCfDeleteRuleFromPhase(array $args, string $phase): array
    {
        $zoneId = $this->cfZoneId(); $ruleId = $args['rule_id'];
        $rs = $this->cfGetOrCreateRuleset($zoneId, $phase);
        if (isset($rs['error'])) return ['content' => [['type' => 'text', 'text' => $rs['error']]], 'isError' => true];
        $result = $this->cfRequest('DELETE', "/zones/{$zoneId}/rulesets/{$rs['id']}/rules/{$ruleId}");
        if (!($result['success'] ?? false))
            return ['content' => [['type' => 'text', 'text' => "Cannot delete rule {$ruleId}: " . json_encode($result['errors'] ?? [])]], 'isError' => true];
        return ['content' => [['type' => 'text', 'text' => "Rule {$ruleId} deleted from phase {$phase}"]]];
    }

    private function toolCfDeleteRuleFromPhaseByArg(array $args): array
    {
        return $this->toolCfDeleteRuleFromPhase($args, $args['phase'] ?? '');
    }

    private function toolCfUpsertCacheRule(array $args): array
    {
        $zoneId = $this->cfZoneId();
        $rs = $this->cfGetOrCreateRuleset($zoneId, 'http_request_cache_settings');
        if (isset($rs['error'])) return ['content' => [['type' => 'text', 'text' => $rs['error']]], 'isError' => true];
        $rule = ['action' => $args['action'], 'expression' => $args['expression'], 'description' => $args['description'] ?? '', 'enabled' => $args['enabled'] ?? true, 'action_parameters' => $args['settings'] ?? []];
        if (!empty($args['rule_id'])) {
            $result = $this->cfRequest('PATCH', "/zones/{$zoneId}/rulesets/{$rs['id']}/rules/{$args['rule_id']}", $rule); $verb = 'updated';
        } else {
            $result = $this->cfRequest('POST', "/zones/{$zoneId}/rulesets/{$rs['id']}/rules", $rule); $verb = 'created';
        }
        if (!($result['success'] ?? false))
            return ['content' => [['type' => 'text', 'text' => "Cannot upsert cache rule: " . json_encode($result['errors'] ?? [])]], 'isError' => true];
        $id = $result['result']['id'] ?? ($args['rule_id'] ?? 'unknown');
        return ['content' => [['type' => 'text', 'text' => "Cache rule {$id} {$verb}"]]];
    }

    private function toolCfUpsertRedirectRule(array $args): array
    {
        $zoneId = $this->cfZoneId();
        $rs = $this->cfGetOrCreateRuleset($zoneId, 'http_request_redirect');
        if (isset($rs['error'])) return ['content' => [['type' => 'text', 'text' => $rs['error']]], 'isError' => true];
        $rule = ['action' => 'redirect', 'expression' => $args['expression'], 'description' => $args['description'] ?? '', 'enabled' => $args['enabled'] ?? true, 'action_parameters' => ['from_value' => ['target_url' => ['expression' => $args['target_url']], 'status_code' => (int)($args['status_code'] ?? 301), 'preserve_query_string' => true]]];
        if (!empty($args['rule_id'])) {
            $result = $this->cfRequest('PATCH', "/zones/{$zoneId}/rulesets/{$rs['id']}/rules/{$args['rule_id']}", $rule); $verb = 'updated';
        } else {
            $result = $this->cfRequest('POST', "/zones/{$zoneId}/rulesets/{$rs['id']}/rules", $rule); $verb = 'created';
        }
        if (!($result['success'] ?? false))
            return ['content' => [['type' => 'text', 'text' => "Cannot upsert redirect rule: " . json_encode($result['errors'] ?? [])]], 'isError' => true];
        $id = $result['result']['id'] ?? ($args['rule_id'] ?? 'unknown');
        return ['content' => [['type' => 'text', 'text' => "Redirect rule {$id} {$verb}"]]];
    }

    private function toolCfListHeaderRules(): array
    {
        $zoneId = $this->cfZoneId();
        $all    = [];
        foreach (['http_response_headers_transform', 'http_request_late_transform'] as $phase) {
            $rs = $this->cfGetOrCreateRuleset($zoneId, $phase);
            if (isset($rs['error'])) continue;
            $detail = $this->cfRequest('GET', "/zones/{$zoneId}/rulesets/{$rs['id']}");
            foreach ($detail['result']['rules'] ?? [] as $rule) {
                $all[] = ['phase' => $phase, 'id' => $rule['id'] ?? '', 'description' => $rule['description'] ?? '', 'expression' => $rule['expression'] ?? '', 'headers' => $rule['action_parameters']['headers'] ?? [], 'enabled' => $rule['enabled'] ?? true];
            }
        }
        return ['content' => [['type' => 'text', 'text' => json_encode($all, JSON_PRETTY_PRINT)]]];
    }

    private function toolCfUpsertHeaderRule(array $args): array
    {
        $zoneId = $this->cfZoneId();
        $phase  = $args['phase'];
        if (!in_array($phase, ['http_response_headers_transform', 'http_request_late_transform']))
            return ['content' => [['type' => 'text', 'text' => "Invalid phase: {$phase}"]], 'isError' => true];
        $rs = $this->cfGetOrCreateRuleset($zoneId, $phase);
        if (isset($rs['error'])) return ['content' => [['type' => 'text', 'text' => $rs['error']]], 'isError' => true];
        $headersMap = new \stdClass();
        foreach ($args['headers'] ?? [] as $h) {
            $op = $h['operation'] ?? 'set'; $name = $h['name'] ?? '';
            if (!$name) continue;
            $headersMap->$name = $op === 'remove' ? ['operation' => 'remove'] : ['operation' => $op, 'value' => $h['value'] ?? ''];
        }
        $rule = ['action' => 'rewrite', 'expression' => $args['expression'], 'description' => $args['description'] ?? '', 'enabled' => $args['enabled'] ?? true, 'action_parameters' => ['headers' => $headersMap]];
        if (!empty($args['rule_id'])) {
            $result = $this->cfRequest('PATCH', "/zones/{$zoneId}/rulesets/{$rs['id']}/rules/{$args['rule_id']}", $rule); $verb = 'updated';
        } else {
            $result = $this->cfRequest('POST', "/zones/{$zoneId}/rulesets/{$rs['id']}/rules", $rule); $verb = 'created';
        }
        if (!($result['success'] ?? false))
            return ['content' => [['type' => 'text', 'text' => "Cannot upsert header rule: " . json_encode($result['errors'] ?? [])]], 'isError' => true];
        $id = $result['result']['id'] ?? ($args['rule_id'] ?? 'unknown');
        return ['content' => [['type' => 'text', 'text' => "Header rule {$id} {$verb} (phase: {$phase})"]]];
    }
}
