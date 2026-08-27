<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Load the Ability execution REST shim only when this WordPress version supports Abilities.
 */
function novamira_boot_ability_rest_surface(): bool
{
    if (!novamira_wordpress_abilities_supported()) {
        return false;
    }

    require_once dirname(__DIR__) . '/rest-shim.php';
    add_action('rest_api_init', callback: 'novamira_register_ability_run_rest_shim');

    return true;
}

/**
 * Register Novamira Ability hooks independently of protocol-adapter initialization.
 */
function novamira_register_ability_hooks(): bool
{
    if (!novamira_wordpress_abilities_supported()) {
        return false;
    }

    add_action('wp_abilities_api_categories_init', callback: 'novamira_register_ability_categories', priority: 20);
    add_action('wp_abilities_api_init', callback: 'novamira_register_builtin_abilities', priority: 20);

    return true;
}

/**
 * Keep policy enforcement active even when Novamira is disabled so independently registered skill
 * or extension Abilities are removed according to the existing rules.
 */
function novamira_register_ability_policy_hook(): bool
{
    if (!novamira_wordpress_abilities_supported()) {
        return false;
    }

    add_action('wp_abilities_api_init', callback: 'novamira_apply_ability_policy', priority: PHP_INT_MAX);

    return true;
}

/**
 * Register categories owned by Novamira's built-in Abilities.
 */
function novamira_register_ability_categories(): void
{
    wp_register_ability_category('code-execution', [
        'label' => __('Code Execution', domain: 'novamira'),
        'description' => __('Abilities that execute code on the WordPress server.', domain: 'novamira'),
    ]);

    wp_register_ability_category('filesystem', [
        'label' => __('Filesystem', domain: 'novamira'),
        'description' => __('Server filesystem operations.', domain: 'novamira'),
    ]);

    wp_register_ability_category('admin-access', [
        'label' => __('Admin Access', domain: 'novamira'),
        'description' => __('Temporary browser access to WordPress admin.', domain: 'novamira'),
    ]);

    if (!wp_has_ability_category('novamira-mcp-adapter')) {
        wp_register_ability_category('novamira-mcp-adapter', [
            'label' => __('MCP Adapter', domain: 'novamira'),
            'description' => __('Meta-abilities for MCP protocol bridging.', domain: 'novamira'),
        ]);
    }
}

function novamira_register_gutenberg_ability_category(): void
{
    wp_register_ability_category('gutenberg', [
        'label' => __('Gutenberg', domain: 'novamira'),
        'description' => __(
            'Gutenberg content abilities, including the Block Editor Queue for native/static blocks that need browser JS finalization. At the start of Gutenberg work, check the queue runtime and ask the user to keep the Block Editor Queue page open when static/native blocks may be queued.',
            domain: 'novamira',
        ),
    ]);
}

/**
 * Register every built-in Ability. The optional adapter may consume these registrations later but
 * is not a prerequisite for them.
 */
function novamira_register_builtin_abilities(): void
{
    $dir = __DIR__ . '/';
    require_once $dir . 'execute-php.php';
    require_once $dir . 'read-file.php';
    require_once $dir . 'write-file.php';
    require_once $dir . 'edit-file.php';
    require_once $dir . 'delete-file.php';
    require_once $dir . 'create-upload-link.php';
    require_once $dir . 'create-admin-access-link.php';
    require_once $dir . 'disable-file.php';
    require_once $dir . 'enable-file.php';
    require_once $dir . 'list-directory.php';
    require_once $dir . 'discover-abilities.php';
    require_once $dir . 'agent-context.php';
    require_once $dir . 'run-wp-cli.php';
}
