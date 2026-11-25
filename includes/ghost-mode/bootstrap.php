<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\GhostMode;

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/policy.php';
require_once __DIR__ . '/visibility.php';
require_once __DIR__ . '/api.php';
require_once __DIR__ . '/enforcement.php';
require_once __DIR__ . '/notices.php';
require_once __DIR__ . '/page.php';
require_once __DIR__ . '/connections.php';
require_once __DIR__ . '/cli.php';

Cli\register();

add_action('admin_menu', __NAMESPACE__ . '\\register_menu', priority: 90);
add_action('admin_post_' . DISABLE_ACTION, __NAMESPACE__ . '\\handle_disable');
add_action('admin_enqueue_scripts', __NAMESPACE__ . '\\enqueue_page_assets');
add_action('admin_menu', __NAMESPACE__ . '\\hide_menus', priority: PHP_INT_MAX);
add_action('network_admin_menu', __NAMESPACE__ . '\\hide_menus', priority: PHP_INT_MAX);
add_action('admin_init', __NAMESPACE__ . '\\guard_request', priority: PHP_INT_MIN);
add_filter('all_plugins', __NAMESPACE__ . '\\filter_plugin_list', priority: PHP_INT_MAX);
add_filter('site_transient_update_plugins', __NAMESPACE__ . '\\project_update_transient', priority: PHP_INT_MAX);
add_action('admin_notices', __NAMESPACE__ . '\\render_bypass_notice');
add_action('network_admin_notices', __NAMESPACE__ . '\\render_bypass_notice');

foreach ([
    'load-plugins.php',
    'load-update.php',
    'load-update-core.php',
    'admin_init',
    'upgrader_process_complete',
] as $novamira_ghost_mode_hook) {
    add_action($novamira_ghost_mode_hook, __NAMESPACE__ . '\\suspend_update_projection', priority: 9);
    add_action($novamira_ghost_mode_hook, __NAMESPACE__ . '\\resume_update_projection', priority: 11);
}
