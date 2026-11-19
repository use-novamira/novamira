<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Child-process runner for McpAdapterBuiltinAbilitiesRaceTest: php stock-mcp-adapter-policy-runner.php
 * <plugin root> <hub-disabled|hub-enabled>.
 *
 * An unprefixed MCP Adapter copy initializes after both Abilities registries booted, so Novamira registers
 * its built-ins late and applies helpers.php's real Ability policy to them, with or without an Abilities
 * Hub rule that turns mcp-adapter/execute-ability off.
 */

if ($argc !== 3 || !in_array($argv[2], ['hub-disabled', 'hub-enabled'], strict: true)) {
    exit(2);
}

[, $root, $scenario] = $argv;

require __DIR__ . '/read-mode-stubs.php';
require __DIR__ . '/options-wpdb.php';
require $root . '/includes/helpers.php';
require $root . '/includes/read-mode/policy.php';
require __DIR__ . '/read-mode-hub-stubs.php';

$GLOBALS['wp_current_filter'] = [];

/** Both registries have booted: each of their actions fired once. */
function did_action(string $hook): int
{
    return in_array($hook, ['wp_abilities_api_categories_init', 'wp_abilities_api_init'], strict: true) ? 1 : 0;
}

function doing_action(?string $hook = null): bool
{
    return $hook === null
        ? $GLOBALS['wp_current_filter'] !== []
        : in_array($hook, $GLOBALS['wp_current_filter'], strict: true);
}

function has_action(string $hook, mixed $callback = false): int|false
{
    foreach ($GLOBALS['hooks'] as [$hook_name, $attached, $priority]) {
        if ($hook_name === $hook && $attached === $callback) {
            return $priority;
        }
    }
    return false;
}

function remove_action(string $hook, mixed $callback, int $priority = 10): bool
{
    foreach ($GLOBALS['hooks'] as $index => [$hook_name, $attached, $attached_priority]) {
        if ($hook_name === $hook && $attached === $callback && $attached_priority === $priority) {
            unset($GLOBALS['hooks'][$index]);
            return true;
        }
    }
    return false;
}

require_once $root . '/includes/abilities/stock-mcp-adapter.php';
require __DIR__ . '/stock-mcp-adapter-stub.php';

if ($scenario === 'hub-disabled') {
    $GLOBALS['options']['novamira_ability_rules'] = ['mcp-adapter/execute-ability' => ['disabled' => true]];
}

$adapter = \WP\MCP\Core\McpAdapter::instance();
// What the adapter's init() attaches when it builds its default server, here after both actions fired.
add_action('wp_abilities_api_categories_init', [$adapter, 'register_default_category']);
add_action('wp_abilities_api_init', [$adapter, 'register_default_abilities']);
novamira_catch_up_stock_mcp_adapter_abilities($adapter);

echo json_encode([
    'category' => wp_has_ability_category('mcp-adapter'),
    'registered' => array_values(array_filter(
        array_keys($GLOBALS['abilities']),
        static fn(string $name): bool => str_starts_with($name, 'mcp-adapter/'),
    )),
    'unregistered' => $GLOBALS['unregistered'],
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
