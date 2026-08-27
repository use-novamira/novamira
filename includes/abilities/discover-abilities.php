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

/** Check whether the current user may view an Ability's MCP metadata. */
function novamira_current_user_can_view_ability_metadata(string $ability_name): bool
{
    return !str_starts_with($ability_name, 'novamira/') || novamira_current_user_can_manage();
}

/** Keep the MCP Adapter's standard permission for the shared discovery tool. */
function novamira_discover_abilities_permission(): bool|\WP_Error
{
    if (!is_user_logged_in()) {
        return new \WP_Error('authentication_required', 'User must be authenticated to access this ability');
    }

    /** @var string $capability */
    $capability = apply_filters('novamira_mcp_adapter_discover_abilities_capability', value: 'read');
    if (!current_user_can($capability)) {
        return new \WP_Error('insufficient_capability', sprintf('User lacks required capability: %s', $capability));
    }

    return true;
}

/** Apply Novamira metadata visibility to the shared get-ability-info tool. */
function novamira_get_ability_info_permission(mixed $input = []): bool|\WP_Error
{
    $arguments = is_array($input) ? $input : [];
    $adapter_permission = \Novamira\Vendor\WP\MCP\Abilities\GetAbilityInfoAbility::check_permission($arguments);
    if (is_wp_error($adapter_permission) || !$adapter_permission) {
        return $adapter_permission;
    }

    $ability_name = is_string($arguments['ability_name'] ?? null) ? $arguments['ability_name'] : '';
    if (!novamira_current_user_can_view_ability_metadata($ability_name)) {
        return new \WP_Error('insufficient_capability', 'You are not allowed to inspect Novamira ability metadata.');
    }

    return true;
}

/** Register get-ability-info with the Novamira metadata visibility check. */
function novamira_protect_get_ability_info(): void
{
    $ability_name = 'novamira-mcp-adapter/get-ability-info';
    $ability = wp_has_ability($ability_name) ? wp_get_ability($ability_name) : null;
    $execute_callback = [\Novamira\Vendor\WP\MCP\Abilities\GetAbilityInfoAbility::class, 'execute'];
    if (!$ability instanceof \WP_Ability) {
        return;
    }

    wp_unregister_ability($ability_name);
    wp_register_ability($ability_name, [
        'label' => $ability->get_label(),
        'description' => $ability->get_description(),
        'category' => $ability->get_category(),
        'input_schema' => $ability->get_input_schema(),
        'output_schema' => $ability->get_output_schema(),
        'permission_callback' => 'novamira_get_ability_info_permission',
        'execute_callback' => $execute_callback,
        'meta' => $ability->get_meta(),
    ]);
}

/** Declare execute-ability parameters as an open object for strict MCP clients. */
function novamira_open_execute_ability_parameters(): void
{
    $ability_name = 'novamira-mcp-adapter/execute-ability';
    $ability = wp_has_ability($ability_name) ? wp_get_ability($ability_name) : null;
    if (!$ability instanceof \WP_Ability) {
        return;
    }

    $input_schema = $ability->get_input_schema();
    /** @var array<string, mixed>|null $properties */
    $properties = $input_schema['properties'] ?? null;
    if (!is_array($properties)) {
        return;
    }

    /** @var array<string, mixed>|null $parameters_schema */
    $parameters_schema = $properties['parameters'] ?? null;
    if (!is_array($parameters_schema)) {
        return;
    }

    // WordPress expects schemas as arrays. novamira_normalize_empty_schema_properties(), called by
    // novamira.php's rest_pre_echo_response filter, converts this empty map to a JSON object.
    $parameters_schema['properties'] = [];
    $parameters_schema['additionalProperties'] = true;
    $properties['parameters'] = $parameters_schema;
    $input_schema['properties'] = $properties;

    wp_unregister_ability($ability_name);
    wp_register_ability($ability_name, [
        'label' => $ability->get_label(),
        'description' => $ability->get_description(),
        'category' => $ability->get_category(),
        'input_schema' => $input_schema,
        'output_schema' => $ability->get_output_schema(),
        'permission_callback' => [
            \Novamira\Vendor\WP\MCP\Abilities\ExecuteAbilityAbility::class,
            'check_permission',
        ],
        'execute_callback' => [\Novamira\Vendor\WP\MCP\Abilities\ExecuteAbilityAbility::class, 'execute'],
        'meta' => $ability->get_meta(),
    ]);
}

/** Recursively make empty JSON Schema property maps encode as JSON objects. */
function novamira_normalize_empty_schema_properties(mixed $schema): mixed
{
    if (is_array($schema)) {
        return novamira_normalize_empty_schema_property_array($schema);
    }

    if ($schema instanceof \stdClass) {
        return (object) novamira_normalize_empty_schema_property_array((array) $schema);
    }

    return $schema;
}

/**
 * @param array<array-key, mixed> $schema
 * @return array<array-key, mixed>
 */
function novamira_normalize_empty_schema_property_array(array $schema): array
{
    // @mago-expect analysis:mixed-assignment -- JSON Schema values are intentionally heterogeneous.
    foreach ($schema as $key => $value) {
        if (
            is_string($key)
            && in_array(
                $key,
                ['properties', 'additionalProperties', 'patternProperties', '$defs', 'definitions'],
                strict: true,
            )
            && $value === []
        ) {
            $schema[$key] = new \stdClass();
            continue;
        }

        $schema[$key] = novamira_normalize_empty_schema_properties($value);
    }
    return $schema;
}

$novamira_existing_ability = wp_has_ability('novamira-mcp-adapter/discover-abilities')
    ? wp_get_ability('novamira-mcp-adapter/discover-abilities')
    : null;
if ($novamira_existing_ability !== null) {
    wp_unregister_ability('novamira-mcp-adapter/discover-abilities');
}

if (wp_has_ability('novamira-mcp-adapter/discover-abilities')) {
    return;
}

wp_register_ability('novamira-mcp-adapter/discover-abilities', [
    'label' => __('Discover Abilities', domain: 'novamira'),
    'description' => __(
        'Discover all available WordPress abilities in the system. Returns a list of all registered abilities with their basic information, plus Novamira environment instructions.',
        domain: 'novamira',
    ),
    'category' => 'novamira-mcp-adapter',
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
    'permission_callback' => 'novamira_discover_abilities_permission',
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
            if (!novamira_current_user_can_view_ability_metadata($ability->get_name())) {
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

novamira_protect_get_ability_info();
novamira_open_execute_ability_parameters();
