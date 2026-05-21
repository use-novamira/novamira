<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Skills module entry point.
 *
 * Loads the submodules (CPT, parser, sources, catalog, prompts, admin, abilities)
 * and wires the top-level WordPress hooks. The module is self-contained: every
 * piece of code it needs lives under `includes/skills/`, so it can be lifted as
 * a unit when a portable extraction is needed.
 */

namespace Novamira\Skills;

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/cpt.php';
require_once __DIR__ . '/parser.php';
require_once __DIR__ . '/sources.php';
require_once __DIR__ . '/built-in.php';
require_once __DIR__ . '/catalog.php';
require_once __DIR__ . '/prompts.php';
require_once __DIR__ . '/notices.php';
require_once __DIR__ . '/admin.php';
require_once __DIR__ . '/abilities/skill-get.php';
require_once __DIR__ . '/abilities/skill-write.php';
require_once __DIR__ . '/abilities/skill-edit.php';
require_once __DIR__ . '/abilities/skill-delete.php';

add_action('init', __NAMESPACE__ . '\\Cpt\\register');
// Skills is registered as a submenu under the Novamira top-level menu;
// run after Novamira's main menu callback (priority 10) attaches the
// parent slug `novamira-connect`. A second pass at a higher priority
// reorders the entry so Skills sits directly after AI Abilities.
add_action('admin_menu', __NAMESPACE__ . '\\Admin\\register_menu', priority: 11);
add_action('admin_menu', __NAMESPACE__ . '\\Admin\\reorder_submenu', priority: 999);
add_action('admin_init', __NAMESPACE__ . '\\Admin\\register_post_handlers');
add_action('admin_notices', __NAMESPACE__ . '\\Notices\\render');
add_action('admin_enqueue_scripts', __NAMESPACE__ . '\\Admin\\enqueue_assets');

add_filter('novamira_skill_lookup_sources', __NAMESPACE__ . '\\BuiltIn\\register');
add_filter('novamira_discover_abilities_instructions', __NAMESPACE__ . '\\Catalog\\inject', priority: 10);

// MCP prompt-mode skill abilities and the canonical skill-get must register
// after any pre-existing owner so we can save a reference and delegate when
// our lookup misses (priority 999). See abilities/skill-get.php for details.
add_action('wp_abilities_api_init', __NAMESPACE__ . '\\Abilities\\register_categories', priority: 5);
add_action('wp_abilities_api_init', __NAMESPACE__ . '\\Prompts\\register_dynamic_abilities', priority: 500);
add_action('wp_abilities_api_init', __NAMESPACE__ . '\\Abilities\\SkillGet\\register', priority: 999);
add_action('wp_abilities_api_init', __NAMESPACE__ . '\\Abilities\\SkillWrite\\register', priority: 999);
add_action('wp_abilities_api_init', __NAMESPACE__ . '\\Abilities\\SkillEdit\\register', priority: 999);
add_action('wp_abilities_api_init', __NAMESPACE__ . '\\Abilities\\SkillDelete\\register', priority: 999);
