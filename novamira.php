<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Plugin Name: Novamira
 * Plugin URI: https://www.novamira.ai
 * Description: MCP server that gives AI agents full access to WordPress through PHP execution and filesystem operations. For development and staging environments only.
 * Version: 1.2.0
 * Requires at least: 6.9
 * Requires PHP: 8.0
 * Author: Dynamic.ooo
 * Author URI: https://www.novamira.ai
 * License: AGPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/agpl-3.0.html
 * Text Domain: novamira
 * Copyright: Ovation S.r.l.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

if (!defined('ABSPATH')) {
    exit();
}

define(constant_name: 'NOVAMIRA_VERSION', value: '1.2.0');
define(constant_name: 'NOVAMIRA_MAX_EXECUTION_TIME', value: 30);
define('NOVAMIRA_PLUGIN_URL', plugin_dir_url(__FILE__));
define('NOVAMIRA_SANDBOX_DIR', WP_CONTENT_DIR . '/novamira-sandbox/');
define(constant_name: 'NOVAMIRA_VENDOR_AUTOLOAD', value: __DIR__ . '/vendor/autoload_packages.php');
define(constant_name: 'NOVAMIRA_MCP_ADAPTER_CLASS', value: 'WP\\MCP\\Core\\McpAdapter');

/**
 * Load bundled Composer dependencies and report the common source-ZIP install mistake clearly.
 *
 * @return WP_Error|null
 */
function novamira_load_bundled_dependencies()
{
    if (!file_exists(NOVAMIRA_VENDOR_AUTOLOAD)) {
        return new WP_Error('novamira_missing_vendor', __(
            'Novamira is installed without its bundled vendor directory. This usually means the GitHub/source ZIP was installed instead of the Novamira release build ZIP. The MCP Adapter cannot load, so Novamira will not register an MCP endpoint. Install the Novamira release build ZIP before using Novamira.',
            domain: 'novamira',
        ));
    }

    try {
        require_once NOVAMIRA_VENDOR_AUTOLOAD;
    } catch (\Throwable $e) {
        return new WP_Error('novamira_autoload_failed', sprintf(
            __(
                'Novamira could not load its bundled Composer dependencies. The MCP Adapter cannot load, so Novamira will not register an MCP endpoint. Reinstall the Novamira release build ZIP. Error: %s',
                domain: 'novamira',
            ),
            $e->getMessage(),
        ));
    }

    if (!class_exists(NOVAMIRA_MCP_ADAPTER_CLASS)) {
        return new WP_Error('novamira_mcp_adapter_missing', sprintf(
            __(
                'Novamira loaded its Composer autoloader, but the MCP Adapter class (%s) is not available. Novamira will not register an MCP endpoint. Reinstall the Novamira release build ZIP.',
                domain: 'novamira',
            ),
            NOVAMIRA_MCP_ADAPTER_CLASS,
        ));
    }

    return null;
}

/**
 * Store a runtime MCP dependency error.
 */
function novamira_set_mcp_dependency_error(WP_Error $error): void
{
    novamira_mcp_dependency_error($error);
}

/**
 * Return the current MCP dependency error, if any.
 *
 * @return WP_Error|null
 */
function novamira_get_mcp_dependency_error()
{
    return novamira_mcp_dependency_error();
}

/**
 * Shared storage for the current MCP dependency error.
 *
 * @return WP_Error|null
 */
function novamira_mcp_dependency_error(?WP_Error $error = null)
{
    static $current = null;

    if ($error !== null) {
        $current = $error;
    }

    return $current;
}

/**
 * Whether the bundled MCP Adapter is available for Novamira to initialize.
 */
function novamira_is_mcp_adapter_available(): bool
{
    return novamira_get_mcp_dependency_error() === null && class_exists(NOVAMIRA_MCP_ADAPTER_CLASS);
}

/**
 * Block activation when the distributable dependencies are missing.
 */
function novamira_activation_check(): void
{
    $error = novamira_get_mcp_dependency_error();
    if ($error === null) {
        return;
    }

    if (function_exists('deactivate_plugins')) {
        deactivate_plugins(plugin_basename(__FILE__));
    }

    wp_die(
        '<p>' . esc_html($error->get_error_message()) . '</p>',
        esc_html__('Novamira installation is incomplete', domain: 'novamira'),
        ['back_link' => true],
    );
}

/**
 * Show a persistent admin error when Novamira cannot expose MCP.
 */
function novamira_render_mcp_dependency_notice(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $page = $_GET['page'] ?? null;
    if (is_string($page) && in_array($page, ['novamira-connect', 'novamira', 'novamira-sandbox'], strict: true)) {
        return;
    }

    $error = novamira_get_mcp_dependency_error();
    if ($error === null) {
        return;
    }

    wp_admin_notice(esc_html($error->get_error_message()), [
        'type' => 'error',
        'dismissible' => false,
    ]);
}

/**
 * Return a clear REST error at the MCP endpoint when the adapter cannot register its own route.
 */
function novamira_register_missing_mcp_endpoint(): void
{
    $error = novamira_get_mcp_dependency_error();
    if ($error === null) {
        return;
    }

    $routes = rest_get_server()->get_routes();
    $callback = static fn() => new WP_Error('novamira_mcp_adapter_unavailable', $error->get_error_message(), [
        'status' => 500,
    ]);

    foreach (['novamira', 'mcp-adapter-default-server'] as $route_slug) {
        if (array_key_exists('/mcp/' . $route_slug, $routes)) {
            continue;
        }
        register_rest_route('mcp', '/' . $route_slug, [
            'methods' => WP_REST_Server::ALLMETHODS,
            'callback' => $callback,
            'permission_callback' => '__return_true',
        ]);
    }
}

/**
 * Initialize the MCP Adapter and convert runtime failures into visible admin notices.
 */
function novamira_initialize_mcp_adapter(): bool
{
    if (!novamira_is_mcp_adapter_available()) {
        return false;
    }

    try {
        \WP\MCP\Core\McpAdapter::instance();
        return true;
    } catch (\Throwable $e) {
        novamira_set_mcp_dependency_error(
            new WP_Error('novamira_mcp_adapter_init_failed', sprintf(
                __(
                    'Novamira found the MCP Adapter, but it failed during initialization. Novamira will not register an MCP endpoint. Error: %s',
                    domain: 'novamira',
                ),
                $e->getMessage(),
            )),
        );
        return false;
    }
}

$novamira_dependency_error = novamira_load_bundled_dependencies();
if ($novamira_dependency_error !== null) {
    novamira_set_mcp_dependency_error($novamira_dependency_error);
}

register_activation_hook(__FILE__, callback: 'novamira_activation_check');
add_action('admin_notices', callback: 'novamira_render_mcp_dependency_notice');
add_action('network_admin_notices', callback: 'novamira_render_mcp_dependency_notice');
add_action('rest_api_init', callback: 'novamira_register_missing_mcp_endpoint', priority: 999);

require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/updater.php';
require_once __DIR__ . '/includes/admin-page.php';
require_once __DIR__ . '/includes/connect-page.php';
require_once __DIR__ . '/includes/pro-upsell.php';
require_once __DIR__ . '/includes/upload-link.php';

// Dependency check: Abilities API must be active.
if (!class_exists('WP_Ability')) {
    add_action('admin_notices', static function () {
        wp_admin_notice(
            esc_html__('Novamira requires the Abilities API plugin to be installed and activated.', domain: 'novamira'),
            [
                'type' => 'error',
                'dismissible' => false,
            ],
        );
    });
    return;
}

// Add "Community" link to the plugin row meta on the Plugins page.
add_filter(
    'plugin_row_meta',
    /** @param string[] $plugin_meta */
    static function (array $plugin_meta, string $plugin_file): array {
        if ($plugin_file === plugin_basename(__FILE__)) {
            $plugin_meta[] =
                '<a href="https://www.facebook.com/groups/dynamic.ooo" target="_blank" rel="noopener noreferrer">'
                . esc_html__('Community', domain: 'novamira')
                . '</a>';
        }
        return $plugin_meta;
    },
    priority: 10,
    accepted_args: 2,
);

// Suppress noisy admin notices on the Configuration page via CSS: hide notices that are not
// emitted by Novamira or Novamira Pro. Cheap and side-effect free, unlike iterating $wp_filter
// with Reflection (which causes memory blow-ups when Query Monitor captures every remove_action).
add_action('admin_head', static function () {
    if (($_GET['page'] ?? null) !== 'novamira-connect') {
        return;
    }
    ?>
    <style id="novamira-suppress-foreign-notices">
        .wrap > .notice:not(.novamira-pro-notice):not(.novamira-keep),
        #wpbody-content > .notice:not(.novamira-pro-notice):not(.novamira-keep),
        #wpbody-content > .updated:not(.novamira-keep),
        #wpbody-content > .error:not(.novamira-keep) {
            display: none !important;
        }
    </style>
    <?php
});

// Handle form actions early (before headers are sent) for PRG redirect.
add_action('admin_init', static function () {
    $page = $_GET['page'] ?? null;
    if ($page === 'novamira-sandbox') {
        novamira_handle_sandbox_actions();
    }
    if ($page === 'novamira-connect') {
        novamira_handle_revoke_password();
        novamira_handle_dismiss_production_warning();
    }
});

// Register admin menus.
add_action('admin_menu', static function () {
    // Top-level menu item (shows the Connect page).
    add_menu_page(
        page_title: __('Configuration', domain: 'novamira'),
        menu_title: 'Novamira',
        capability: 'manage_options',
        menu_slug: 'novamira-connect',
        callback: 'novamira_render_connect_page',
        icon_url: 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAzMiAzMiI+PHBhdGggZmlsbD0iYmxhY2siIGQ9Ik01IDRoNi41bDkuNSAxNi41VjRIMjd2MjRoLTYuNUwxMSAxMS41VjI4SDVWNHoiLz48L3N2Zz4=',
        position: 3,
    );

    // Rename the auto-created first submenu entry to match the page title.
    add_submenu_page(
        parent_slug: 'novamira-connect',
        page_title: __('Configuration', domain: 'novamira'),
        menu_title: __('Configuration', domain: 'novamira'),
        capability: 'manage_options',
        menu_slug: 'novamira-connect',
        callback: 'novamira_render_connect_page',
    );

    // AI Abilities sub-page.
    add_submenu_page(
        parent_slug: 'novamira-connect',
        page_title: __('AI Abilities', domain: 'novamira'),
        menu_title: __('AI Abilities', domain: 'novamira'),
        capability: 'manage_options',
        menu_slug: 'novamira',
        callback: 'novamira_render_settings_page',
    );

    // Sandbox sub-page.
    add_submenu_page(
        parent_slug: 'novamira-connect',
        page_title: __('Sandbox', domain: 'novamira'),
        menu_title: __('Sandbox', domain: 'novamira'),
        capability: 'manage_options',
        menu_slug: 'novamira-sandbox',
        callback: 'novamira_render_sandbox_page',
    );
});

$is_enabled = novamira_is_enabled();

if (!$is_enabled && novamira_is_domain_mismatch()) {
    add_action('admin_notices', static function () {
        /** @var string $locked */
        $locked = get_option('novamira_ai_abilities_domain', default_value: '');
        wp_admin_notice(
            sprintf(
                esc_html__(
                    'Novamira AI Abilities were disabled because the site domain changed (enabled on %s). Re-enable them from the Configuration page if this is intentional.',
                    domain: 'novamira',
                ),
                '<code>' . esc_html($locked) . '</code>',
            ),
            ['type' => 'warning', 'dismissible' => true],
        );
    });
}

if ($is_enabled) {
    // Brand the default MCP server. Usage instructions are returned from the
    // discover-abilities tool instead of the initialize handshake.
    add_filter('mcp_adapter_default_server_config', static function (mixed $config): mixed {
        if (!is_array($config)) {
            return $config;
        }
        $config['server_id'] = 'novamira';
        $config['server_route'] = 'novamira';
        $config['server_name'] = 'Novamira';
        return $config;
    });

    // Register a legacy alias server at the old slug so configs that still point at
    // /wp-json/mcp/mcp-adapter-default-server keep working after the rename.
    add_action('mcp_adapter_init', callback: 'novamira_register_legacy_mcp_server', priority: 20);

    // Initialize bundled MCP Adapter — its default server exposes our abilities automatically.
    if (!novamira_initialize_mcp_adapter()) {
        $is_enabled = false;
    }
}

/**
 * Register a legacy alias of the canonical Novamira MCP server at the pre-rename slug.
 *
 * The canonical server is registered under `/mcp/novamira`. Older client configs may still
 * point at `/mcp/mcp-adapter-default-server` from before the rename — this alias keeps them
 * working with identical behavior (same tools, same auto-discovered resources and prompts).
 */
function novamira_register_legacy_mcp_server(mixed $adapter): void
{
    if (!$adapter instanceof \WP\MCP\Core\McpAdapter) {
        return;
    }

    if ($adapter->get_server('novamira') === null) {
        return;
    }

    $adapter->create_server(
        'mcp-adapter-default-server',
        'mcp',
        'mcp-adapter-default-server',
        'Novamira (legacy alias)',
        'Legacy alias for the Novamira MCP server. New client configurations should use /wp-json/mcp/novamira.',
        'v1.0.0',
        [\WP\MCP\Transport\HttpTransport::class],
        \WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
        \WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
        [
            'mcp-adapter/discover-abilities',
            'mcp-adapter/get-ability-info',
            'mcp-adapter/execute-ability',
        ],
        novamira_discover_public_abilities('resource'),
        novamira_discover_public_abilities('prompt'),
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

if ($is_enabled) {
    // The `mcp-adapter/execute-ability` dispatcher wraps every ability return in
    // `{ success: true, data: <inner> }`. When the inner value is itself
    // `{ success: false, error: "..." }` the outer `success: true` masks a real
    // logical failure, and agents that check the top-level flag — a very
    // reasonable default — silently march past the error. Unwrap that shape
    // here so the adapter's backward-compat path (ToolsHandler) turns it into a
    // proper `isError: true` CallToolResult.
    //
    // ToolsHandler::create_error_result flattens the response to a bare
    // `content: [text(error)], structuredContent: null, isError: true` — every
    // sibling field on the ability's return is discarded. Validators attach
    // structured repair hints (`invalid_values`, `unknown_properties`,
    // `collision_paths`, `suggested_name`, `failed_paths`, `overwritten_paths`,
    // `errors`, `schemas`, `style_errors`, `dynamic_tag_errors`, `dropped_keys`,
    // `schema`, …) that the agent needs to self-correct without a
    // round-trip — so embed whatever else the ability returned as a JSON
    // suffix on the error message. The suffix rides inside the string and
    // survives the downstream flatten.
    add_filter(
        'mcp_adapter_tool_call_result',
        static function (mixed $result, array $args, string $tool_name): mixed {
            // Tool names are MCP-sanitized from ability slugs — `/` becomes `-`.
            if ($tool_name !== 'mcp-adapter-execute-ability') {
                return $result;
            }
            if (!is_array($result) || ($result['success'] ?? null) !== true) {
                return $result;
            }
            /** @var array<array-key, mixed>|null $data */
            $data = $result['data'] ?? null;
            if (!is_array($data) || ($data['success'] ?? null) !== false) {
                return $result;
            }
            /** @var string|null $error */
            $error = $data['error'] ?? null;
            if (!is_string($error) || trim($error) === '') {
                return $result;
            }

            $detail = $data;
            unset($detail['success'], $detail['error']);
            if ($detail !== []) {
                $encoded = wp_json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (is_string($encoded)) {
                    $data['error'] = $error . "\n\nStructured detail (JSON):\n" . $encoded;
                }
            }

            return $data;
        },
        priority: 10,
        accepted_args: 3,
    );

    // Fix empty "properties" in JSON Schema: PHP json_encode outputs [] instead of {}.
    // MCP clients reject tools with invalid schemas, so we fix this in the REST response.
    add_filter('rest_pre_echo_response', static function (mixed $result): mixed {
        if (!is_array($result)) {
            return $result;
        }
        /** @var \stdClass|null $resultObj */
        $resultObj = $result['result'] ?? null;
        if (!$resultObj instanceof \stdClass) {
            return $result;
        }
        /** @var list<array<string, mixed>>|null $tools */
        $tools = $resultObj->tools ?? null;
        if (!is_array($tools)) {
            return $result;
        }
        foreach ($tools as &$tool) {
            foreach (['inputSchema', 'outputSchema'] as $key) {
                /** @var array<string, mixed>|null $schema */
                $schema = $tool[$key] ?? null;
                if (!is_array($schema) || ($schema['properties'] ?? null) !== []) {
                    continue;
                }
                $schema['properties'] = new \stdClass();
                $tool[$key] = $schema;
            }
        }
        $resultObj->tools = $tools;
        return $result;
    });

    // Admin bar indicator when MCP is active.
    add_action(
        'admin_bar_menu',
        static function (\WP_Admin_Bar $wp_admin_bar) {
            if (!current_user_can('manage_options')) {
                return;
            }

            $wp_admin_bar->add_node([
                'id' => 'novamira-mcp-status',
                'title' => esc_html__('Novamira ON', domain: 'novamira'),
                'href' => admin_url('admin.php?page=novamira-connect'),
                'meta' => ['class' => 'novamira-mcp-on'],
            ]);
        },
        priority: 999,
    );

    add_action('admin_head', static function () {
        if (!current_user_can('manage_options')) {
            return;
        }

        echo
            '<style>#wp-admin-bar-novamira-mcp-status > .ab-item { background:#c00 !important; color:#fff !important; }</style>'
        ;
    });

    add_action('wp_head', static function () {
        if (current_user_can('manage_options') && is_admin_bar_showing()) {
            echo
                '<style>#wp-admin-bar-novamira-mcp-status > .ab-item { background:#c00 !important; color:#fff !important; }</style>'
            ;
        }
    });

    // Info notice if the standalone MCP Adapter plugin is still active.
    if (function_exists('is_plugin_active') && is_plugin_active('mcp-adapter/mcp-adapter.php')) {
        add_action('admin_notices', static function () {
            wp_admin_notice(
                esc_html__(
                    'Novamira bundles the MCP Adapter. You can safely deactivate the standalone MCP Adapter plugin.',
                    domain: 'novamira',
                ),
                [
                    'type' => 'info',
                    'dismissible' => true,
                ],
            );
        });
    }

    // Register ability categories.
    add_action('wp_abilities_api_categories_init', static function () {
        wp_register_ability_category('code-execution', [
            'label' => __('Code Execution', domain: 'novamira'),
            'description' => __('Abilities that execute code on the WordPress server.', domain: 'novamira'),
        ]);

        wp_register_ability_category('filesystem', [
            'label' => __('Filesystem', domain: 'novamira'),
            'description' => __('Server filesystem operations.', domain: 'novamira'),
        ]);

        if (wp_get_ability_category('mcp-adapter') === null) {
            wp_register_ability_category('mcp-adapter', [
                'label' => __('MCP Adapter', domain: 'novamira'),
                'description' => __('Meta-abilities for MCP protocol bridging.', domain: 'novamira'),
            ]);
        }
    });

    // Register abilities.
    add_action('wp_abilities_api_init', static function () {
        $dir = __DIR__ . '/includes/abilities/';
        require_once $dir . 'execute-php.php';
        require_once $dir . 'read-file.php';
        require_once $dir . 'write-file.php';
        require_once $dir . 'edit-file.php';
        require_once $dir . 'delete-file.php';
        require_once $dir . 'create-upload-link.php';
        require_once $dir . 'disable-file.php';
        require_once $dir . 'enable-file.php';
        require_once $dir . 'list-directory.php';
        require_once $dir . 'discover-abilities.php';
        require_once $dir . 'run-wp-cli.php';
    });
}

// Ensure sandbox directory exists.
wp_mkdir_p(NOVAMIRA_SANDBOX_DIR);

// Load sandbox plugins.
require_once __DIR__ . '/includes/sandbox-loader.php';
