<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Design System module entry point. Self-contained under includes/design/,
 * modeled on includes/skills/ so it can be lifted as a unit.
 */

namespace Novamira\Design;

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/parser.php';
require_once __DIR__ . '/tokens.php';
require_once __DIR__ . '/preflight.php';
require_once __DIR__ . '/markdown.php';
require_once __DIR__ . '/contract.php';
require_once __DIR__ . '/cpt.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/revisions.php';
require_once __DIR__ . '/library.php';
require_once __DIR__ . '/abilities/categories.php';
require_once __DIR__ . '/abilities/list-design-library.php';
require_once __DIR__ . '/abilities/get-active-design.php';
require_once __DIR__ . '/abilities/activate-design.php';
require_once __DIR__ . '/abilities/save-design.php';
require_once __DIR__ . '/abilities/check-design.php';
require_once __DIR__ . '/abilities/get-design.php';
require_once __DIR__ . '/abilities/delete-design.php';
require_once __DIR__ . '/admin.php';
require_once __DIR__ . '/notices.php';

add_action('init', __NAMESPACE__ . '\\Cpt\\register');
add_action('admin_menu', __NAMESPACE__ . '\\Admin\\register_menu', priority: 11);
add_action('admin_menu', __NAMESPACE__ . '\\Admin\\reorder_submenu', priority: 999);
add_action('admin_init', __NAMESPACE__ . '\\Admin\\register_post_handlers');
add_action('admin_notices', __NAMESPACE__ . '\\Notices\\render');
add_action('admin_enqueue_scripts', __NAMESPACE__ . '\\Admin\\enqueue_assets');
add_filter('novamira_building_context_lines', __NAMESPACE__ . '\\add_building_context');
add_filter(
    'wp_' . Cpt\POST_TYPE . '_revisions_to_keep',
    __NAMESPACE__ . '\\Revisions\\limit',
    priority: 10,
    accepted_args: 2,
);
add_action('wp_abilities_api_categories_init', __NAMESPACE__ . '\\Abilities\\register_category');
add_action('wp_abilities_api_init', __NAMESPACE__ . '\\Abilities\\ListLibrary\\register', priority: 999);
add_action('wp_abilities_api_init', __NAMESPACE__ . '\\Abilities\\GetActive\\register', priority: 999);
add_action('wp_abilities_api_init', __NAMESPACE__ . '\\Abilities\\Activate\\register', priority: 999);
add_action('wp_abilities_api_init', __NAMESPACE__ . '\\Abilities\\Save\\register', priority: 999);
add_action('wp_abilities_api_init', __NAMESPACE__ . '\\Abilities\\Check\\register', priority: 999);
add_action('wp_abilities_api_init', __NAMESPACE__ . '\\Abilities\\Get\\register', priority: 999);
add_action('wp_abilities_api_init', __NAMESPACE__ . '\\Abilities\\Delete\\register', priority: 999);

/**
 * Append the design-authority lines (see includes/design-authority.php): the
 * `novamira-design` skill directive at the `design` level (the default on
 * every site with the feature on, with or without an active design), the
 * builder-owned-design line at the `ask`, `hybrid`, and `builder` levels a
 * specialization declares, and nothing only when the feature is off.
 *
 * @param list<string> $lines
 * @return list<string>
 */
function add_building_context(array $lines): array
{
    return array_values(array_merge($lines, novamira_design_building_context_lines()));
}
