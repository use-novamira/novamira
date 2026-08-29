<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Plugin Name: Novamira
 * Plugin URI: https://novamira.ai
 * Description: MCP server that gives AI agents full access to WordPress through PHP execution and filesystem operations. For development and staging environments only.
 * Version: 1.12.0
 * Requires at least: 6.9
 * Requires PHP: 8.0
 * Author: Dynamic.ooo
 * Author URI: https://novamira.ai
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

require_once __DIR__ . '/includes/compatibility.php';

define(constant_name: 'NOVAMIRA_MAX_EXECUTION_TIME', value: 30);
define('NOVAMIRA_PLUGIN_URL', plugin_dir_url(__FILE__));
define('NOVAMIRA_SANDBOX_DIR', WP_CONTENT_DIR . '/novamira-sandbox/');
define(constant_name: 'NOVAMIRA_VENDOR_AUTOLOAD', value: __DIR__ . '/vendor/autoload_packages.php');
define(constant_name: 'NOVAMIRA_MCP_AUTOLOAD', value: __DIR__ . '/vendor/novamira/mcp-adapter/autoload.php');
define(constant_name: 'NOVAMIRA_MCP_ADAPTER_CLASS', value: 'Novamira\\Vendor\\WP\\MCP\\Core\\McpAdapter');

/**
 * Load bundled Composer dependencies and report the common source-ZIP install mistake clearly.
 *
 * @return WP_Error|null
 */
function novamira_load_bundled_dependencies()
{
    if (!file_exists(NOVAMIRA_VENDOR_AUTOLOAD) || !file_exists(NOVAMIRA_MCP_AUTOLOAD)) {
        return new WP_Error('novamira_missing_vendor', __(
            'This Novamira installation is incomplete, so AI clients cannot connect to this site. Download the official Novamira plugin ZIP from novamira.ai, then reinstall it from Plugins → Add Plugin → Upload Plugin. Do not use a GitHub “Source code” ZIP.',
            domain: 'novamira',
        ));
    }

    try {
        require_once NOVAMIRA_VENDOR_AUTOLOAD;
        require_once NOVAMIRA_MCP_AUTOLOAD;
    } catch (\Throwable $e) {
        return new WP_Error('novamira_autoload_failed', sprintf(
            __(
                'Novamira could not load its required files, so AI clients cannot connect to this site. Reinstall Novamira using the official plugin ZIP from novamira.ai. Technical error: %s',
                domain: 'novamira',
            ),
            $e->getMessage(),
        ));
    }

    if (!class_exists(NOVAMIRA_MCP_ADAPTER_CLASS)) {
        return new WP_Error('novamira_mcp_adapter_missing', sprintf(
            __(
                'This Novamira installation is incomplete, so AI clients cannot connect to this site. Reinstall Novamira using the official plugin ZIP from novamira.ai. Missing component: %s',
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
 * Show a persistent admin error when Novamira cannot expose MCP.
 */
function novamira_render_mcp_dependency_notice(): void
{
    if (!novamira_current_user_can_manage()) {
        return;
    }

    $page = $_GET['page'] ?? null;
    if (
        is_string($page)
        && in_array(
            $page,
            [
                'novamira-connect',
                'novamira-connections',
                'novamira-abilities',
                'novamira-chat',
                'novamira-sandbox',
                'novamira-uninstall',
            ],
            strict: true,
        )
    ) {
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
        \Novamira\Vendor\WP\MCP\Core\McpAdapter::instance();
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

require_once __DIR__ . '/includes/chat-schema.php';

register_activation_hook(__FILE__, callback: 'novamira_chat_schema_install');
register_deactivation_hook(__FILE__, callback: 'novamira_unschedule_gutenberg_cron');
add_action('admin_notices', callback: 'novamira_render_mcp_dependency_notice');
add_action('network_admin_notices', callback: 'novamira_render_mcp_dependency_notice');
add_action('rest_api_init', callback: 'novamira_register_missing_mcp_endpoint', priority: 999);

require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/design-authority.php';
require_once __DIR__ . '/includes/features/bootstrap.php';
require_once __DIR__ . '/includes/admin-notices.php';
require_once __DIR__ . '/includes/abilities/bootstrap.php';
require_once __DIR__ . '/includes/updater.php';
require_once __DIR__ . '/includes/admin-page.php';
require_once __DIR__ . '/includes/uninstall-review.php';
register_activation_hook(__FILE__, callback: 'novamira_clear_uninstall_plan');
require_once __DIR__ . '/includes/hosting/detector.php';
require_once __DIR__ . '/includes/connect-page.php';
require_once __DIR__ . '/includes/oauth/connections.php';
add_action('admin_menu', callback: static function (): void {
    \Novamira\OAuth\Connections\register();
});
require_once __DIR__ . '/includes/pro-upsell.php';
require_once __DIR__ . '/includes/upload-link.php';
require_once __DIR__ . '/includes/admin-access-link.php';
require_once __DIR__ . '/includes/skills/bootstrap.php';
require_once __DIR__ . '/includes/oauth/bootstrap.php';
require_once __DIR__ . '/includes/troubleshoot/bootstrap.php';
require_once __DIR__ . '/includes/instructions-admin.php';

\Novamira\Context\boot_context_admin();
novamira_register_wordpress_compatibility_notice();
novamira_boot_ability_rest_surface();
novamira_register_ability_policy_hook();

add_action('admin_post_novamira_toggle_ai_abilities', callback: 'novamira_handle_admin_bar_toggle');
add_action('admin_post_novamira_download_mcpb', callback: 'novamira_handle_download_mcpb');

function novamira_unschedule_gutenberg_cron(): void
{
    require_once __DIR__ . '/includes/abilities/gutenberg/bootstrap.php';
    \Novamira\Abilities\Gutenberg\unschedule_cleanup();
}

function novamira_boot_design_feature(): void
{
    require_once __DIR__ . '/includes/design/bootstrap.php';
}

function novamira_boot_visual_feature(): void
{
    require_once __DIR__ . '/novamira-visual/bootstrap.php';
}

function novamira_boot_chat_feature(): void
{
    novamira_chat_schema_maybe_install();
    require_once __DIR__ . '/includes/chat.php';
}

function novamira_boot_block_editor_queue_feature(): void
{
    if (novamira_is_enabled() && novamira_wordpress_abilities_supported()) {
        novamira_load_gutenberg_runtime();
        add_action(
            'wp_abilities_api_categories_init',
            callback: 'novamira_register_gutenberg_ability_category',
            priority: 20,
        );
        add_action('wp_abilities_api_init', callback: 'novamira_load_gutenberg_abilities', priority: 20);
    }
}

function novamira_deactivate_block_editor_queue_feature(): void
{
    require_once __DIR__ . '/includes/abilities/gutenberg/bootstrap.php';
    \Novamira\Abilities\Gutenberg\unschedule_cleanup();
}

function novamira_load_gutenberg_runtime(): void
{
    require_once __DIR__ . '/includes/abilities/gutenberg/bootstrap.php';
    require_once __DIR__ . '/includes/abilities/gutenberg/runtime.php';
    require_once __DIR__ . '/includes/abilities/gutenberg/rest.php';
    require_once __DIR__ . '/includes/gutenberg-finalizer-admin.php';
    \Novamira\GutenbergFinalizer\boot_gutenberg_finalizer_admin();
}

function novamira_load_gutenberg_abilities(): void
{
    $gutenberg_dir = __DIR__ . '/includes/abilities/gutenberg/';
    require_once $gutenberg_dir . 'bootstrap.php';
    require_once $gutenberg_dir . 'runtime.php';
    require_once $gutenberg_dir . 'get-finalizer-runtime.php';
    require_once $gutenberg_dir . 'list-block-types.php';
    require_once $gutenberg_dir . 'get-block-type.php';
    require_once $gutenberg_dir . 'get-content.php';
    require_once $gutenberg_dir . 'write-content.php';
    require_once $gutenberg_dir . 'create-pending-batch.php';
    require_once $gutenberg_dir . 'add-pending-change.php';
    require_once $gutenberg_dir . 'enable-batch-finalization.php';
    require_once $gutenberg_dir . 'get-pending-batch.php';
    require_once $gutenberg_dir . 'list-pending-batches.php';
    require_once $gutenberg_dir . 'delete-pending-batch.php';
    require_once $gutenberg_dir . 'delete-pending-change.php';
    require_once $gutenberg_dir . 'get-finalization-url.php';
}

function novamira_inject_custom_instructions(mixed $instructions): mixed
{
    if (!is_string($instructions)) {
        return $instructions;
    }

    // Stay out while a Novamira Pro that still manages custom instructions is
    // active: it injects its own copy (priority 5), so the base must not add a
    // second one. The base takes over once that Pro is gone or updated.
    if (\Novamira\Context\legacy_pro_context_loaded()) {
        return $instructions;
    }

    if (\Novamira\Context\instructions_custom_injection_suppressed()) {
        return $instructions;
    }

    if (!\Novamira\Context\instructions_is_enabled()) {
        return $instructions;
    }

    $custom = \Novamira\Context\instructions_get_content();
    if (trim($custom) === '') {
        return $instructions;
    }

    if (str_starts_with($instructions, $custom . "\n\n")) {
        return $instructions;
    }

    return $custom . "\n\n" . $instructions;
}

add_filter('novamira_discover_abilities_instructions', callback: 'novamira_inject_custom_instructions', priority: 6);

/**
 * Add the Novamira AI Abilities status and toggle to the WordPress admin bar.
 */
function novamira_register_admin_bar_toggle(\WP_Admin_Bar $wp_admin_bar): void
{
    if (!novamira_current_user_can_manage()) {
        return;
    }

    $dependency_error = novamira_get_mcp_dependency_error();
    $configured_enabled = novamira_is_enabled();
    $active = $configured_enabled && $dependency_error === null;
    $can_enable = $configured_enabled || $dependency_error === null;
    $target = $configured_enabled ? 'off' : 'on';
    $toggle_url = wp_nonce_url(
        admin_url('admin-post.php?action=novamira_toggle_ai_abilities&novamira_target=' . $target),
        action: 'novamira_toggle_ai_abilities',
    );

    $wp_admin_bar->add_node([
        'id' => 'novamira-mcp-status',
        'title' => match (true) {
            $active => esc_html__('Novamira ON', domain: 'novamira'),
            $configured_enabled => esc_html__('Novamira ERROR', domain: 'novamira'),
            default => esc_html__('Novamira', domain: 'novamira'),
        },
        'href' => admin_url('admin.php?page=novamira-connect'),
        'meta' => [
            'class' => match (true) {
                $active => 'novamira-mcp-on',
                $configured_enabled => 'novamira-mcp-error',
                default => 'novamira-mcp-off',
            },
        ],
    ]);

    $wp_admin_bar->add_node([
        'id' => 'novamira-mcp-status-label',
        'parent' => 'novamira-mcp-status',
        'title' => match (true) {
            $active => esc_html__('AI Abilities: On', domain: 'novamira'),
            $configured_enabled => esc_html__('AI Abilities: Error', domain: 'novamira'),
            default => esc_html__('AI Abilities: Off', domain: 'novamira'),
        },
    ]);

    if (!$can_enable) {
        $wp_admin_bar->add_node([
            'id' => 'novamira-mcp-unavailable',
            'parent' => 'novamira-mcp-status',
            'title' => esc_html__('AI Abilities unavailable', domain: 'novamira'),
            'href' => admin_url('admin.php?page=novamira-connect'),
        ]);
    }

    if ($can_enable) {
        $wp_admin_bar->add_node([
            'id' => 'novamira-mcp-toggle',
            'parent' => 'novamira-mcp-status',
            'title' => $configured_enabled
                ? esc_html__('Turn Off AI Abilities', domain: 'novamira')
                : esc_html__('Turn On AI Abilities', domain: 'novamira'),
            'href' => $toggle_url,
            'meta' => [
                'class' => $configured_enabled ? 'novamira-mcp-toggle-off' : 'novamira-mcp-toggle-on',
            ],
        ]);
    }

    $wp_admin_bar->add_node([
        'id' => 'novamira-mcp-config',
        'parent' => 'novamira-mcp-status',
        'title' => esc_html__('Configuration', domain: 'novamira'),
        'href' => admin_url('admin.php?page=novamira-connect'),
    ]);
}

/**
 * Style the admin-bar status chip and require confirmation before enabling from the dropdown.
 */
function novamira_render_admin_bar_toggle_assets(): void
{
    if (!novamira_current_user_can_manage() || !is_admin_bar_showing()) {
        return;
    }

    $looks_production = novamira_looks_like_production();
    $confirm_message = $looks_production
        ? __(
            'This looks like a production site. AI Abilities are intended for staging or development sites. Continue anyway?',
            domain: 'novamira',
        )
        : __('AI agents will be able to execute PHP code and access the filesystem. Continue?', domain: 'novamira');
    ?>
    <style>
    #wp-admin-bar-novamira-mcp-status.novamira-mcp-on > .ab-item {
        background: #c00 !important;
        color: #fff !important;
    }
    #wp-admin-bar-novamira-mcp-status.novamira-mcp-error > .ab-item {
        background: #996800 !important;
        color: #fff !important;
    }
    #wp-admin-bar-novamira-mcp-status-label > .ab-item {
        cursor: default;
        font-weight: 600;
    }
    </style>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var toggle = document.querySelector('#wp-admin-bar-novamira-mcp-toggle.novamira-mcp-toggle-on > .ab-item');
        if (!toggle) {
            return;
        }
        toggle.addEventListener('click', function (event) {
            if (!window.confirm(<?php echo wp_json_encode($confirm_message); ?>)) {
                event.preventDefault();
            }
        });
    });
    </script>
    <?php
}

add_action('admin_bar_menu', callback: 'novamira_register_admin_bar_toggle', priority: 999);
add_action('admin_head', callback: 'novamira_render_admin_bar_toggle_assets');
add_action('wp_head', callback: 'novamira_render_admin_bar_toggle_assets');

// Optional dev mock for the external-skills source. Gitignored. Loaded
// only when the constant is set (e.g. in wp-config.php) so it never ships
// to production builds.
if (
    defined('NOVAMIRA_DEV_MOCK_PRO')
    && constant('NOVAMIRA_DEV_MOCK_PRO') === true
    && file_exists(__DIR__ . '/includes/skills/dev-mock.php')
) {
    require_once __DIR__ . '/includes/skills/dev-mock.php';
}

// Add community links to the plugin row meta on the Plugins page.
add_filter(
    'plugin_row_meta',
    /** @param string[] $plugin_meta */
    static function (array $plugin_meta, string $plugin_file): array {
        if ($plugin_file === plugin_basename(__FILE__)) {
            $plugin_meta[] = '<a href="https://www.facebook.com/groups/novamira" target="_blank" rel="noopener noreferrer">Facebook Community</a>';
            $plugin_meta[] = '<a href="https://discord.gg/novamira" target="_blank" rel="noopener noreferrer">Discord</a>';
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
    if ($page === 'novamira-abilities') {
        novamira_handle_ability_hub_actions();
    }
});

// Single-row toggle over AJAX so the page state (open sections) is preserved.
add_action('wp_ajax_novamira_toggle_ability', callback: 'novamira_handle_ability_toggle_ajax');

// Admin page stylesheets — card layouts matching Skills.
add_action('admin_enqueue_scripts', static function (string $hook): void {
    $debug_assets = defined('WP_DEBUG') && constant('WP_DEBUG') === true;
    $asset_version = static fn(string $path): string => $debug_assets && is_file($path)
        ? (string) filemtime($path)
        : NOVAMIRA_VERSION;

    if (in_array($hook, ['novamira_page_novamira-abilities', 'novamira_page_novamira-sandbox'], strict: true)) {
        wp_enqueue_style(
            'novamira-admin-list',
            (string) NOVAMIRA_PLUGIN_URL . 'includes/assets/admin-list.css',
            [],
            $asset_version(__DIR__ . '/includes/assets/admin-list.css'),
        );
    }

    if ($hook === 'novamira_page_novamira-abilities') {
        wp_enqueue_style(
            'novamira-hub-admin',
            (string) NOVAMIRA_PLUGIN_URL . 'includes/assets/hub.css',
            ['novamira-admin-list'],
            $asset_version(__DIR__ . '/includes/assets/hub.css'),
        );
        wp_enqueue_script(
            'novamira-hub-admin',
            (string) NOVAMIRA_PLUGIN_URL . 'includes/assets/hub.js',
            [],
            $asset_version(__DIR__ . '/includes/assets/hub.js'),
            args: true,
        );
    }

    if ($hook === 'novamira_page_novamira-sandbox') {
        wp_enqueue_style(
            'novamira-sandbox-admin',
            (string) NOVAMIRA_PLUGIN_URL . 'includes/assets/sandbox.css',
            ['novamira-admin-list'],
            $asset_version(__DIR__ . '/includes/assets/sandbox.css'),
        );
    }
});

// Register admin menus.
// Menu order uses spaced admin_menu priorities (multiples of 10) so entries
// can be positioned without post-hoc reordering.
add_action(
    'admin_menu',
    static function () {
        // Top-level menu item (shows the Connect page).
        add_menu_page(
            page_title: __('Configuration', domain: 'novamira'),
            menu_title: 'Novamira',
            capability: novamira_manage_capability(),
            menu_slug: 'novamira-connect',
            callback: 'novamira_render_connect_page',
            // Official Novamira N, encoded so WordPress applies its native SVG menu sizing.
            icon_url: 'data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iVVRGLTgiPz4KPHN2ZyBpZD0iTGF5ZXJfMiIgZGF0YS1uYW1lPSJMYXllciAyIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9Ii0xNSAtMTUgMTQ4LjU4IDE0OC41OSIgd2lkdGg9IjIwIiBoZWlnaHQ9IjIwIj4KICA8ZGVmcz4KICAgIDxzdHlsZT4KICAgICAgLmNscy0xIHsKICAgICAgICBmaWxsOiAjZmZmOwogICAgICB9CiAgICA8L3N0eWxlPgogIDwvZGVmcz4KICA8ZyBpZD0iTGF5ZXJfMS0yIiBkYXRhLW5hbWU9IkxheWVyIDEiPgogICAgPHBhdGggY2xhc3M9ImNscy0xIiBkPSJNMCwwaDM3LjFsNDIuMTgsNTIuMzVWMGgzOS4zdjExOC41OWgtMzUuMjRsLTQ0LjA0LTU0LjcydjU0LjcySDBWMFoiLz4KICA8L2c+Cjwvc3ZnPgo=',
            position: 3,
        );

        // Rename the auto-created first submenu entry to match the page title.
        add_submenu_page(
            parent_slug: 'novamira-connect',
            page_title: __('Configuration', domain: 'novamira'),
            menu_title: __('Configuration', domain: 'novamira'),
            capability: novamira_manage_capability(),
            menu_slug: 'novamira-connect',
            callback: 'novamira_render_connect_page',
        );
    },
    priority: 10,
);

// Abilities Hub — priority 25 places it after Troubleshoot (20) rather than in the priority-10
// Configuration group, so the connection diagnostics sit directly below Connect. (30 is taken by
// Context, so 25 keeps it between Troubleshoot and Context without renumbering the rest.)
add_action(
    'admin_menu',
    static function () {
        add_submenu_page(
            parent_slug: 'novamira-connect',
            page_title: __('Abilities Hub', domain: 'novamira'),
            menu_title: __('Abilities Hub', domain: 'novamira'),
            capability: novamira_manage_capability(),
            menu_slug: 'novamira-abilities',
            callback: 'novamira_render_settings_page',
        );
    },
    priority: 25,
);

// Sandbox sub-page — priority 50 places it after Context (30) and Skills (40).
add_action(
    'admin_menu',
    static function () {
        add_submenu_page(
            parent_slug: 'novamira-connect',
            page_title: __('Sandbox', domain: 'novamira'),
            menu_title: __('Sandbox', domain: 'novamira'),
            capability: novamira_manage_capability(),
            menu_slug: 'novamira-sandbox',
            callback: 'novamira_render_sandbox_page',
        );
    },
    priority: 50,
);

$is_enabled = novamira_is_enabled();

if (!$is_enabled && novamira_is_domain_mismatch()) {
    add_action('admin_notices', static function () {
        if (!novamira_current_user_can_manage()) {
            return;
        }
        /** @var string $locked */
        $locked = get_option('novamira_ai_abilities_domain', default_value: '');
        novamira_render_persistent_admin_notice(
            sprintf(
                esc_html__(
                    'Novamira AI Abilities were disabled because the site domain changed (enabled on %s). Re-enable them from the Configuration page if this is intentional.',
                    domain: 'novamira',
                ),
                '<code>' . esc_html($locked) . '</code>',
            ),
            meta_key: 'novamira_domain_mismatch_notice_dismissed',
            dismiss_value: md5($locked),
            args: ['type' => 'warning'],
        );
    });
}

$novamira_abilities_supported = novamira_wordpress_abilities_supported();
$novamira_adapter_initialized = false;

if ($is_enabled && $novamira_abilities_supported) {
    novamira_register_ability_hooks();

    // MCP clients commonly leave sessions behind when they disconnect. Keep enough short-lived
    // sessions to avoid the adapter evicting active sessions when its default 32-session cap is reached.
    add_filter('novamira_mcp_adapter_session_max_per_user', static fn(): int => 128);
    add_filter('novamira_mcp_adapter_session_inactivity_timeout', static fn(): int => 4 * HOUR_IN_SECONDS);

    // Brand the default MCP server. Usage instructions are returned from the
    // discover-abilities tool instead of the initialize handshake.
    add_filter(
        'novamira_mcp_adapter_tool_name',
        static function (string $tool_name, mixed $ability): string {
            if (!$ability instanceof \WP_Ability) {
                return $tool_name;
            }
            return match ($ability->get_name()) {
                'novamira-mcp-adapter/discover-abilities' => 'mcp-adapter-discover-abilities',
                'novamira-mcp-adapter/get-ability-info' => 'mcp-adapter-get-ability-info',
                'novamira-mcp-adapter/execute-ability' => 'mcp-adapter-execute-ability',
                default => $tool_name,
            };
        },
        accepted_args: 2,
    );

    add_filter('novamira_mcp_adapter_default_server_config', static function (mixed $config): mixed {
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
    add_action('novamira_mcp_adapter_init', callback: 'novamira_register_legacy_mcp_server', priority: 20);

    // Register the OAuth-only server at /mcp/novamira-oauth. Keeping the OAuth Bearer flow on a
    // route of its own means the OAuth middleware never touches the canonical /mcp/novamira
    // endpoint that the existing Application Password installs use. Gated on the same transport
    // check as the OAuth bootstrap so the endpoint never exists without its token/authorize peers.
    add_action('novamira_mcp_adapter_init', callback: 'novamira_register_oauth_mcp_server', priority: 20);

    // Initialize the optional bundled adapter after the transport-neutral Ability and REST hooks.
    // An adapter failure must not remove those hooks or make the REST Ability surface disappear.
    $novamira_adapter_initialized = novamira_initialize_mcp_adapter();
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
    if (!$adapter instanceof \Novamira\Vendor\WP\MCP\Core\McpAdapter) {
        return;
    }

    if ($adapter->get_server('novamira') === null) {
        return;
    }

    novamira_create_mirror_mcp_server(
        $adapter,
        server_id: 'mcp-adapter-default-server',
        route: 'mcp-adapter-default-server',
        name: 'Novamira (legacy alias)',
        description: 'Legacy alias for the Novamira MCP server. New client configurations should use /wp-json/mcp/novamira.',
    );
}

/**
 * Register the OAuth-authenticated Novamira MCP server at `/mcp/novamira-oauth`.
 *
 * The OAuth Bearer flow lives on this dedicated route so the canonical `/mcp/novamira` endpoint —
 * used by the existing Application Password installs — is never seen by the OAuth challenge
 * middleware (see includes/oauth/middleware.php::is_mcp_route). Registered only when the OAuth
 * transport is permitted, mirroring includes/oauth/bootstrap.php so the endpoint never exists
 * without the token/authorize endpoints that make it usable.
 */
function novamira_register_oauth_mcp_server(mixed $adapter): void
{
    if (!$adapter instanceof \Novamira\Vendor\WP\MCP\Core\McpAdapter) {
        return;
    }

    if (!novamira_oauth_transport_allowed()) {
        return;
    }

    if ($adapter->get_server('novamira') === null) {
        return;
    }

    novamira_create_mirror_mcp_server(
        $adapter,
        server_id: 'novamira-oauth',
        route: 'novamira-oauth',
        name: 'Novamira (OAuth)',
        description: 'OAuth-authenticated Novamira MCP endpoint. Application Password clients use /wp-json/mcp/novamira.',
    );
}

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
        [
            'novamira-mcp-adapter/discover-abilities',
            'novamira-mcp-adapter/get-ability-info',
            'novamira-mcp-adapter/execute-ability',
        ],
        novamira_discover_public_abilities('resource'),
        novamira_discover_public_abilities('prompt'),
    );
}

/**
 * Tell administrators that Novamira already includes the MCP Adapter.
 */
function novamira_render_mcp_adapter_plugin_notice(): void
{
    if (!novamira_current_user_can_manage()) {
        return;
    }

    novamira_render_persistent_admin_notice(
        esc_html__(
            'Novamira bundles the MCP Adapter. You can safely deactivate the standalone MCP Adapter plugin.',
            domain: 'novamira',
        ),
        meta_key: 'novamira_mcp_adapter_notice_dismissed',
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

if ($novamira_adapter_initialized) {
    // The `novamira-mcp-adapter/execute-ability` dispatcher wraps every ability return in
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
        'novamira_mcp_adapter_tool_call_result',
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
                if (!is_array($schema)) {
                    continue;
                }
                $tool[$key] = novamira_normalize_empty_schema_properties($schema);
            }
        }
        $resultObj->tools = $tools;
        return $result;
    });

    // Info notice if the standalone MCP Adapter plugin is still active.
    if (function_exists('is_plugin_active') && is_plugin_active('mcp-adapter/mcp-adapter.php')) {
        add_action('admin_notices', callback: 'novamira_render_mcp_adapter_plugin_notice');
    }
}
add_filter(
    'novamira_mcp_adapter_tool_call_result',
    callback: 'novamira_enrich_unavailable_wp_cli_error',
    priority: 5,
    accepted_args: 2,
);
add_filter(
    'novamira_mcp_adapter_tool_call_result',
    callback: 'novamira_enrich_disabled_ability_error',
    priority: 10,
    accepted_args: 2,
);

// Ensure the sandbox exists, cannot be served directly, and uses safe disabled-file markers.
novamira_prepare_sandbox_directory();

// Load sandbox plugins.
require_once __DIR__ . '/includes/sandbox-loader.php';
