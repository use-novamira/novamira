<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Visual\BackendTools;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Backend-owned engine that exposes WordPress abilities to Novamira Visual.
 * Discovery and execution live here (free). Plugins exclude abilities through
 * the novamira_visual_excluded_backend_abilities filter; Novamira Pro uses it
 * to keep its builder abilities (Elementor/Bricks) out of backend discovery.
 */

\add_action('rest_api_init', __NAMESPACE__ . '\register_routes');
\add_action('wp_ajax_novamira_visual_safe_backend_tools_discover', __NAMESPACE__ . '\ajax_discover');
\add_action('wp_ajax_novamira_visual_safe_backend_tools_call', __NAMESPACE__ . '\ajax_call');

const SYSTEM_ABILITY_PREFIXES = [
    'novamira-mcp-adapter/',
    'mcp-adapter/',
];

function register_routes(): void
{
    \register_rest_route('novamira/v1', route: '/visual-safe-backend-tools/discover', args: [
        'methods' => 'POST',
        'callback' => __NAMESPACE__ . '\rest_discover',
        'permission_callback' => __NAMESPACE__ . '\check_permission',
    ]);

    \register_rest_route('novamira/v1', route: '/visual-safe-backend-tools/call', args: [
        'methods' => 'POST',
        'callback' => __NAMESPACE__ . '\rest_call',
        'permission_callback' => __NAMESPACE__ . '\check_permission',
    ]);
}

/**
 * Novamira Visual does not work while read mode is on: every backend tool request is refused, whatever the
 * Ability, with the read-mode wording.
 */
function read_mode_refusal(): ?\WP_Error
{
    if (
        !function_exists('novamira_is_read_mode')
        || !function_exists('novamira_read_mode_blocked_error')
        || !\novamira_is_read_mode()
    ) {
        return null;
    }

    return \novamira_read_mode_blocked_error('Novamira Visual');
}

function check_permission(): bool
{
    // Visual always loads inside the core plugin, so this helper is always
    // defined. Fail closed if it ever is not, rather than fall back to a check
    // that is weaker than super admin on multisite.
    if (!function_exists('novamira_current_user_can_manage') || !function_exists('novamira_is_enabled')) {
        return false;
    }

    return \novamira_is_enabled() && \novamira_current_user_can_manage();
}

function rest_discover(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
{
    /** @var mixed $json_params */
    $json_params = $request->get_json_params();
    /** @var array<string, mixed> $params */
    $params = is_array($json_params) ? $json_params : [];
    $data = discover_data($params);
    if (is_wp_error($data)) {
        return $data;
    }

    return new \WP_REST_Response($data, 200);
}

function ajax_discover(): void
{
    ajax_check_permission();
    $data = discover_data(json_request_params());
    ajax_send_result($data);
}

/**
 * @param array<string, mixed> $params
 * @return array<string, mixed>|\WP_Error
 */
function discover_data(array $params): array|\WP_Error
{
    $refusal = read_mode_refusal();
    if ($refusal !== null) {
        return $refusal;
    }

    if (function_exists('novamira_enforce_read_mode_registry')) {
        \novamira_enforce_read_mode_registry();
    }

    if (!function_exists('wp_get_abilities')) {
        return abilities_unavailable_error();
    }

    $filters = discovery_filters($params);
    $excluded = excluded_backend_abilities();
    $tools = [];

    foreach (\wp_get_abilities() as $ability) {
        if (!is_discoverable($ability, $filters['dangerous'], $filters['category'], $filters['search'])) {
            continue;
        }
        if (($excluded[$ability->get_name()] ?? false) === true) {
            continue;
        }
        if (function_exists('novamira_read_mode_hides_ability') && \novamira_read_mode_hides_ability($ability)) {
            continue;
        }

        if ($filters['include_tool_instructions']) {
            $tools[] = $filters['include_schemas']
                ? serialize_with_schema_and_instructions($ability)
                : serialize_summary_with_instructions($ability);
            continue;
        }

        $tools[] = $filters['include_schemas'] ? serialize_with_schema($ability) : serialize_summary($ability);
    }

    usort($tools, static fn(array $a, array $b): int => [$a['category'], $a['name']] <=> [$b['category'], $b['name']]);

    return [
        'novamira_instructions' => $filters['include_instructions'] ? discovery_instructions() : '',
        'tools' => $tools,
        'count' => count($tools),
        'instructions' => __(
            'Use workspace_call_backend_tool with name, args, and reason to run one of these backend MCP tools. Discovery is summary-first; pass search/category to narrow results, include_schemas:true only when exact input schemas are needed, include_tool_instructions:true only when per-tool instructions are needed, and include_instructions:true only when site-wide Novamira instructions are needed. The reason must be one short line posed as a question for the user, for example: "May I run this to inspect the site settings?" Tools that need approval show your reason in Novamira Visual before they run.',
            domain: 'novamira',
        ),
    ];
}

/**
 * Abilities excluded from Visual backend discovery. Plugins (e.g. Novamira Pro
 * for builder abilities) hook this filter to opt abilities out.
 *
 * @return array<string, bool> ability name => true to exclude
 */
function excluded_backend_abilities(): array
{
    /** @var mixed $excluded */
    $excluded = \apply_filters('novamira_visual_excluded_backend_abilities', []);
    if (!is_array($excluded)) {
        return [];
    }

    $names = [];
    foreach (array_keys($excluded) as $name) {
        if (!is_string($name) || $excluded[$name] !== true) {
            continue;
        }

        $names[$name] = true;
    }

    return $names;
}

function discovery_instructions(): string
{
    if (!check_permission()) {
        return '';
    }

    // In read mode agents get the read-mode guidance alone, without extensions' additions.
    if (function_exists('novamira_is_read_mode') && \novamira_is_read_mode()) {
        return \novamira_build_server_instructions();
    }

    $base = '';
    if (function_exists('novamira_build_server_instructions')) {
        /** @var mixed $built */
        $built = \call_user_func('novamira_build_server_instructions');
        $base = is_string($built) ? $built : '';
    }

    /** @var mixed $instructions */
    $instructions = \apply_filters('novamira_visual_backend_discovery_instructions', $base);
    if (!is_string($instructions)) {
        return '';
    }

    return function_exists('novamira_without_rules_code') ? \novamira_without_rules_code($instructions) : $instructions;
}

/**
 * @param array<string, mixed> $params
 * @return array{search: string, category: string, include_schemas: bool, include_instructions: bool, include_tool_instructions: bool, dangerous: string}
 */
function discovery_filters(array $params): array
{
    return [
        'search' => is_string($params['search'] ?? null) ? strtolower(trim($params['search'])) : '',
        'category' => is_string($params['category'] ?? null) ? trim($params['category']) : '',
        'include_schemas' => ($params['include_schemas'] ?? false) === true,
        'include_instructions' => ($params['include_instructions'] ?? false) === true,
        'include_tool_instructions' => ($params['include_tool_instructions'] ?? false) === true,
        'dangerous' => ($params['include_dangerous'] ?? true) !== false ? 'all' : 'safe',
    ];
}

function rest_call(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
{
    /** @var mixed $json_params */
    $json_params = $request->get_json_params();
    /** @var array<string, mixed> $params */
    $params = is_array($json_params) ? $json_params : [];
    $data = call_data($params);
    if (is_wp_error($data)) {
        return $data;
    }

    return new \WP_REST_Response($data, 200);
}

function ajax_call(): void
{
    ajax_check_permission();
    $data = call_data(json_request_params());
    ajax_send_result($data);
}

/**
 * @param array<string, mixed> $params
 * @return array<string, mixed>|\WP_Error
 */
function call_data(array $params): array|\WP_Error
{
    $refusal = read_mode_refusal();
    if ($refusal !== null) {
        return $refusal;
    }

    if (function_exists('novamira_enforce_read_mode_registry')) {
        \novamira_enforce_read_mode_registry();
    }

    $name = is_string($params['name'] ?? null) ? trim($params['name']) : '';
    /** @var array<string, mixed> $args */
    $args = is_array($params['args'] ?? null) ? $params['args'] : [];
    if ($name === '') {
        return new \WP_Error(
            'novamira_visual_backend_tool_missing_name',
            __('Backend tool name is required.', domain: 'novamira'),
            ['status' => 400],
        );
    }

    $ability = get_backend_tool($name);
    if (is_wp_error($ability)) {
        return $ability;
    }

    // The AI's one line about the call, kept as what it said it would do in the AI change history.
    $says = is_string($params['reason'] ?? null) ? mb_substr(trim($params['reason']), start: 0, length: 240) : '';
    $gate = approval_gate($name, $args, attention_param($params), $says);
    if ($gate !== null) {
        return $gate;
    }

    return run_backend_tool($ability, $name, $args, $says);
}

function ajax_check_permission(): void
{
    \check_ajax_referer(action: 'wp_rest', query_arg: 'nonce');
    if (!check_permission()) {
        \wp_send_json_error([
            'message' => __('You do not have permission to use Novamira Visual backend tools.', domain: 'novamira'),
        ], status_code: 403);
    }
}

/**
 * @return array<string, mixed>
 */
function json_request_params(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || $raw === '') {
        return [];
    }

    /** @var mixed $decoded */
    $decoded = json_decode($raw, associative: true);
    if (!is_array($decoded)) {
        return [];
    }

    $params = [];
    foreach (array_keys($decoded) as $key) {
        if (!is_string($key)) {
            continue;
        }

        $params[$key] = $decoded[$key];
    }

    return $params;
}

function ajax_send_result(array|\WP_Error $data): void
{
    if (is_wp_error($data)) {
        /** @var mixed $error_data */
        $error_data = $data->get_error_data();
        $status = is_array($error_data) && is_int($error_data['status'] ?? null) ? $error_data['status'] : 500;
        \wp_send_json_error(['message' => $data->get_error_message()], status_code: $status);
    }

    \wp_send_json_success($data);
}

function is_discoverable(\WP_Ability $ability, string $dangerous_filter, string $category_filter, string $search): bool
{
    if (!is_public_backend_tool($ability)) {
        return false;
    }

    $tool = serialize_summary($ability);
    if ($dangerous_filter === 'safe' && ($tool['dangerous'] ?? false) === true) {
        return false;
    }
    if ($category_filter !== '' && ($tool['category'] ?? '') !== $category_filter) {
        return false;
    }

    return $search === '' || matches_search($tool, $search);
}

function is_public_backend_tool(\WP_Ability $ability): bool
{
    $meta = $ability->get_meta();
    if (!($meta['mcp']['public'] ?? false)) {
        return false;
    }
    if (($meta['mcp']['type'] ?? 'tool') !== 'tool') {
        return false;
    }

    foreach (SYSTEM_ABILITY_PREFIXES as $prefix) {
        if (str_starts_with($ability->get_name(), $prefix)) {
            return false;
        }
    }

    return true;
}

function get_backend_tool(string $name): \WP_Ability|\WP_Error
{
    if (!function_exists('wp_get_ability')) {
        return abilities_unavailable_error();
    }

    // Checked at execution time, whatever the registry holds: an Ability registered after the read-mode
    // sweep is still refused.
    if (function_exists('novamira_read_mode_unavailable_ability_error')) {
        $read_mode_error = \novamira_read_mode_unavailable_ability_error($name);
        if ($read_mode_error !== null) {
            return $read_mode_error;
        }
    }

    $ability = \wp_get_ability($name);
    if ($ability instanceof \WP_Ability && function_exists('novamira_read_mode_ability_refusal')) {
        $untrusted = \novamira_read_mode_ability_refusal($ability);
        if ($untrusted !== null) {
            return $untrusted;
        }
    }

    $excluded = excluded_backend_abilities();
    if (!$ability instanceof \WP_Ability || !is_public_backend_tool($ability) || ($excluded[$name] ?? false) === true) {
        return new \WP_Error(
            'novamira_visual_backend_tool_unavailable',
            sprintf(
                /* translators: %s: backend tool name. */
                __(
                    'Backend tool is not available through the Novamira Visual backend tools contract: %s',
                    domain: 'novamira',
                ),
                $name,
            ),
            ['status' => 404],
        );
    }

    return $ability;
}

/**
 * @return array<string, mixed>
 */
function serialize_with_schema(\WP_Ability $ability): array
{
    return serialize_summary($ability) + ['input_schema' => $ability->get_input_schema()];
}

/**
 * @return array<string, mixed>
 */
function serialize_with_schema_and_instructions(\WP_Ability $ability): array
{
    return serialize_summary_with_instructions($ability) + ['input_schema' => $ability->get_input_schema()];
}

/**
 * @return array<string, mixed>
 */
function serialize_summary(\WP_Ability $ability): array
{
    $meta = $ability->get_meta();
    $annotations = is_array($meta['annotations'] ?? null) ? $meta['annotations'] : [];
    unset($annotations['instructions']);

    return serialize_from_annotations($ability, $annotations);
}

/**
 * @return array<string, mixed>
 */
function serialize_summary_with_instructions(\WP_Ability $ability): array
{
    $meta = $ability->get_meta();
    $annotations = is_array($meta['annotations'] ?? null) ? $meta['annotations'] : [];

    return serialize_from_annotations($ability, $annotations);
}

/**
 * @param array<array-key, mixed> $annotations
 * @return array<string, mixed>
 */
function serialize_from_annotations(\WP_Ability $ability, array $annotations): array
{
    $category = $ability->get_category();

    return [
        'name' => $ability->get_name(),
        'label' => $ability->get_label(),
        'description' => $ability->get_description(),
        'category' => $category,
        'category_label' => category_label($category),
        'annotations' => $annotations,
        'dangerous' => ($annotations['destructive'] ?? false) === true,
    ];
}

function category_label(string $category): string
{
    if ($category === '' || !function_exists('wp_get_ability_category')) {
        return $category;
    }

    $definition = \wp_get_ability_category($category);
    if (!is_object($definition) || !method_exists($definition, 'get_label')) {
        return $category;
    }

    return $definition->get_label();
}

/**
 * @param array<string, mixed> $tool
 */
function matches_search(array $tool, string $search): bool
{
    foreach (['name', 'label', 'description', 'category'] as $key) {
        if (str_contains(strtolower((string) ($tool[$key] ?? '')), $search)) {
            return true;
        }
    }

    return false;
}

function abilities_unavailable_error(): \WP_Error
{
    return new \WP_Error(
        'novamira_visual_abilities_unavailable',
        __('The WordPress Abilities API is not available.', domain: 'novamira'),
        ['status' => 501],
    );
}
