<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Mirrors of the canonical Novamira MCP server (the legacy alias and the OAuth endpoint).
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Keep old Novamira connections on our transport when another adapter registers the same URL.
 *
 * WordPress merges handlers for a route and uses the first matching HTTP method. Both adapters
 * register their transports on rest_api_init:16, so registration order otherwise decides which
 * server answers. Reorder the completed route map, retaining each handler's methods and permission
 * callback. Without an enabled Novamira transport, the public adapter's handlers stay unchanged.
 *
 * @param array<string, mixed> $endpoints
 * @return array<string, mixed>
 */
function novamira_prioritize_legacy_mcp_endpoint(array $endpoints): array
{
    $route = '/mcp/mcp-adapter-default-server';
    /** @var mixed $handlers */
    $handlers = $endpoints[$route] ?? null;
    if (!is_array($handlers) || !novamira_is_enabled()) {
        return $endpoints;
    }

    foreach (array_keys($handlers) as $key) {
        /** @var mixed $handler */
        $handler = $handlers[$key];
        if (!is_int($key) || !is_array($handler)) {
            continue;
        }

        if (!novamira_is_mcp_http_handler($handler)) {
            continue;
        }

        unset($handlers[$key]);
        $endpoints[$route] = array_merge([$handler], $handlers);
        break;
    }

    return $endpoints;
}

add_filter('rest_endpoints', callback: 'novamira_prioritize_legacy_mcp_endpoint', priority: PHP_INT_MAX);

/** Whether a REST handler belongs to Novamira's HTTP transport. */
function novamira_is_mcp_http_handler(mixed $handler): bool
{
    /** @var mixed $callback */
    $callback = is_array($handler) ? $handler['callback'] ?? null : null;

    return (
        is_array($callback)
        && ($callback[0] ?? null) instanceof \Novamira\Vendor\WP\MCP\Transport\HttpTransport
        && ($callback[1] ?? null) === 'handle_request'
    );
}

/** @return list<bool> */
function &novamira_mcp_request_stack(): array
{
    static $requests = [];

    return $requests;
}

/**
 * Scope connection guidance to the handler WordPress actually selected. A stack also keeps nested
 * REST requests from inheriting the outer connection's notice or clearing its context permanently.
 */
function novamira_enter_mcp_request(mixed $response, mixed $handler, WP_REST_Request $request): mixed
{
    $requests = &novamira_mcp_request_stack();
    $requests[] =
        $request->get_route() === '/mcp/mcp-adapter-default-server'
        && novamira_is_mcp_http_handler($handler)
        && novamira_is_enabled();

    return $response;
}

/** Restore the outer request context after WordPress finishes a REST handler, including errors. */
function novamira_leave_mcp_request(mixed $response): mixed
{
    $requests = &novamira_mcp_request_stack();
    array_pop($requests);

    return $response;
}

/** Connection guidance shared by agent-context and MCP discovery, including in read mode. */
function novamira_legacy_mcp_connection_notice(): string
{
    $requests = &novamira_mcp_request_stack();
    if ($requests === [] || end($requests) !== true) {
        return '';
    }

    return sprintf(
        'Connection notice: this client is using Novamira\'s legacy MCP endpoint. Tell the user that the current connection still works, but updating helps avoid future conflicts with other plugins. Direct them to WordPress → Novamira → Configuration (%1$s), where they can select their AI client and follow the instructions to reconfigure the connection. If they configured it manually, tell them to replace the server URL %2$s with %3$s in their AI client\'s MCP configuration. Continue the current task; no immediate action is required.',
        admin_url('admin.php?page=novamira-connect'),
        rest_url('mcp/mcp-adapter-default-server'),
        rest_url('mcp/novamira'),
    );
}

add_filter(
    'rest_request_before_callbacks',
    callback: 'novamira_enter_mcp_request',
    priority: PHP_INT_MIN,
    accepted_args: 3,
);
add_filter('rest_request_after_callbacks', callback: 'novamira_leave_mcp_request', priority: PHP_INT_MAX);

/**
 * Create an MCP server that mirrors the canonical Novamira server — same tools, resources, and
 * prompts — under a different id and route. Shared by the legacy alias and the OAuth endpoint so
 * neither drifts from the default server's exposed abilities.
 */
function novamira_create_mirror_mcp_server(
    \Novamira\Vendor\WP\MCP\Core\McpAdapter $adapter,
    string $server_id,
    string $route,
    string $name,
    string $description,
): void {
    $components = novamira_mcp_server_components(
        [
            'novamira-mcp-adapter/discover-abilities',
            'novamira-mcp-adapter/get-ability-info',
            'novamira-mcp-adapter/execute-ability',
        ],
        discover: 'novamira_discover_public_abilities',
    );

    $adapter->create_server(
        $server_id,
        'mcp',
        $route,
        $name,
        $description,
        'v1.0.0',
        [\Novamira\Vendor\WP\MCP\Transport\HttpTransport::class],
        \Novamira\Vendor\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
        \Novamira\Vendor\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
        $components['tools'],
        $components['resources'],
        $components['prompts'],
    );
}

/**
 * Replicate DefaultServerFactory::discover_abilities_by_type for reuse on the legacy alias.
 *
 * @return list<string>
 */
function novamira_discover_public_abilities(string $type): array
{
    if (!function_exists('wp_get_abilities')) {
        return [];
    }

    $abilities = wp_get_abilities();
    $filtered = [];
    foreach ($abilities as $ability) {
        if (function_exists('novamira_read_mode_hides_ability') && novamira_read_mode_hides_ability($ability)) {
            continue;
        }
        $meta = $ability->get_meta();
        if (!($meta['mcp']['public'] ?? false)) {
            continue;
        }
        $ability_type = (string) ($meta['mcp']['type'] ?? 'tool');
        if ($ability_type !== $type) {
            continue;
        }
        $filtered[] = $ability->get_name();
    }

    return $filtered;
}
