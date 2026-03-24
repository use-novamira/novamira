<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Plugin Name: Novamira
 * Plugin URI: https://www.novamira.ai
 * Description: MCP server that gives AI agents full access to WordPress through PHP execution and filesystem operations. For development and staging environments only.
 * Version: 1.0.0
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

define(constant_name: 'NOVAMIRA_VERSION', value: '1.0.0');
define(constant_name: 'NOVAMIRA_MAX_EXECUTION_TIME', value: 30);
define('NOVAMIRA_PLUGIN_URL', plugin_dir_url(__FILE__));
define('NOVAMIRA_SANDBOX_DIR', WP_CONTENT_DIR . '/novamira-sandbox/');

if (file_exists(__DIR__ . '/vendor/autoload_packages.php')) {
    require_once __DIR__ . '/vendor/autoload_packages.php';
}

require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/updater.php';
require_once __DIR__ . '/includes/admin-page.php';
require_once __DIR__ . '/includes/connect-page.php';

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

// Handle form actions early (before headers are sent) for PRG redirect.
add_action('admin_init', static function () {
    $page = $_GET['page'] ?? null;
    if ($page === 'novamira-sandbox') {
        novamira_handle_sandbox_actions();
    }
    if ($page === 'novamira-connect') {
        novamira_handle_revoke_password();
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

    // Settings sub-page.
    add_submenu_page(
        parent_slug: 'novamira-connect',
        page_title: __('Settings', domain: 'novamira'),
        menu_title: __('Settings', domain: 'novamira'),
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
                    'Novamira AI Abilities were disabled because the site domain changed (enabled on %s). Re-enable them from the settings page if this is intentional.',
                    domain: 'novamira',
                ),
                '<code>' . esc_html($locked) . '</code>',
            ),
            ['type' => 'warning', 'dismissible' => true],
        );
    });
}

if ($is_enabled) {
    // Customize the MCP server instructions sent to AI agents during initialization.
    add_filter('mcp_adapter_default_server_config', static function (mixed $config): mixed {
        if (!is_array($config)) {
            return $config;
        }
        $config['server_name'] = 'Novamira';
        $config['server_description'] = novamira_build_server_instructions();
        return $config;
    });

    // Initialize bundled MCP Adapter — its default server exposes our abilities automatically.
    if (class_exists('WP\MCP\Core\McpAdapter')) {
        \WP\MCP\Core\McpAdapter::instance();
    }

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
            $wp_admin_bar->add_node([
                'id' => 'novamira-mcp-status',
                'title' => esc_html__('Novamira ON', domain: 'novamira'),
                'href' => admin_url('admin.php?page=novamira'),
                'meta' => ['class' => 'novamira-mcp-on'],
            ]);
        },
        priority: 999,
    );

    add_action('admin_head', static function () {
        echo
            '<style>#wp-admin-bar-novamira-mcp-status > .ab-item { background:#c00 !important; color:#fff !important; }</style>'
        ;
    });

    add_action('wp_head', static function () {
        if (is_admin_bar_showing()) {
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

    $hasBundledMcpAdapter = class_exists('WP\\MCP\\Core\\McpAdapter');

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
    });

    // Register abilities.
    add_action('wp_abilities_api_init', static function () use ($hasBundledMcpAdapter) {
        $dir = __DIR__ . '/includes/abilities/';
        require_once $dir . 'execute-php.php';
        require_once $dir . 'read-file.php';
        require_once $dir . 'write-file.php';
        require_once $dir . 'edit-file.php';
        require_once $dir . 'delete-file.php';
        require_once $dir . 'disable-file.php';
        require_once $dir . 'enable-file.php';
        require_once $dir . 'list-directory.php';

        // The bundled MCP Adapter already registers the MCP category and meta-abilities.
        if ($hasBundledMcpAdapter) {
            return;
        }

        require_once $dir . 'mcp-discover-abilities.php';
        require_once $dir . 'mcp-get-ability-info.php';
        require_once $dir . 'mcp-execute-ability.php';
    });
}

// Ensure sandbox directory exists.
wp_mkdir_p(NOVAMIRA_SANDBOX_DIR);

// Load sandbox plugins.
require_once __DIR__ . '/includes/sandbox-loader.php';
