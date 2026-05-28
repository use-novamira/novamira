<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Ability: Discover Abilities (Novamira replacement).
 *
 * Replaces the MCP Adapter's bundled discover-abilities tool so the response
 * includes Novamira's environment/usage instructions alongside the list of
 * abilities. These used to be sent via the MCP initialize handshake's
 * server_description, but some clients drop that; returning them here
 * guarantees the agent sees them on first tool discovery.
 */

if (!defined('ABSPATH')) {
    exit();
}

$existing_ability = wp_get_ability('mcp-adapter/discover-abilities');
if ($existing_ability !== null) {
    wp_unregister_ability('mcp-adapter/discover-abilities');
}

if (wp_get_ability('mcp-adapter/discover-abilities') !== null) {
    return;
}

wp_register_ability('mcp-adapter/discover-abilities', [
    'label' => __('Discover Abilities', domain: 'novamira'),
    'description' => __(
        'Discover all available WordPress abilities in the system. Returns a list of all registered abilities with their basic information, plus Novamira environment instructions.',
        domain: 'novamira',
    ),
    'category' => 'mcp-adapter',
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'novamira_instructions' => [
                'type' => 'string',
                'description' => 'Novamira environment and usage guidance for the agent.',
            ],
            'abilities' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'label' => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                    ],
                    'required' => ['name', 'label', 'description'],
                ],
            ],
        ],
        'required' => ['novamira_instructions', 'abilities'],
    ],
    'permission_callback' => static function (): bool|\WP_Error {
        if (!is_user_logged_in()) {
            return new \WP_Error('authentication_required', 'User must be authenticated to access this ability');
        }
        /** @var string $cap */
        $cap = apply_filters('mcp_adapter_discover_abilities_capability', value: 'read');
        if (!current_user_can($cap)) {
            return new \WP_Error('insufficient_capability', sprintf('User lacks required capability: %s', $cap));
        }
        return true;
    },
    'execute_callback' => static function (): array {
        $ability_list = [];
        foreach (wp_get_abilities() as $ability) {
            $meta = $ability->get_meta();
            if (!($meta['mcp']['public'] ?? false)) {
                continue;
            }
            if (($meta['mcp']['type'] ?? 'tool') !== 'tool') {
                continue;
            }
            $ability_list[] = [
                'name' => $ability->get_name(),
                'label' => $ability->get_label(),
                'description' => $ability->get_description(),
            ];
        }

        $instructions = '';
        if (novamira_current_user_can_manage()) {
            $instructions = (string) apply_filters(
                'novamira_discover_abilities_instructions',
                novamira_build_server_instructions(),
            );
        }

        return [
            'novamira_instructions' => $instructions,
            'abilities' => $ability_list,
        ];
    },
    'meta' => [
        'annotations' => [
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
        'mcp' => [
            'public' => true,
            'type' => 'tool',
        ],
    ],
]);
