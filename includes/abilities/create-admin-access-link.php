<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Ability: Create a temporary one-time admin access link.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('novamira/create-admin-access-link', [
    'label' => __('Create Admin Access Link', domain: 'novamira'),
    'description' => __(
        'Creates a temporary, one-time WordPress admin login URL for browser automation tools. Use this when an agent needs to inspect or operate wp-admin through a browser MCP without asking the user for a password.',
        domain: 'novamira',
    ),
    'category' => 'admin-access',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'expires_in' => [
                'type' => 'integer',
                'description' => 'Seconds before the admin access URL expires. Minimum 30, maximum 600.',
                'default' => 300,
                'minimum' => 30,
                'maximum' => 600,
            ],
            'session_expires_in' => [
                'type' => 'integer',
                'description' => 'Seconds before the browser admin session expires after the URL is opened. Minimum 60, maximum 3600.',
                'default' => 1800,
                'minimum' => 60,
                'maximum' => 3600,
            ],
            'admin_path' => [
                'type' => 'string',
                'description' => 'Optional wp-admin-relative path to open after login, such as "plugins.php" or "admin.php?page=novamira-connect". External URLs are rejected.',
                'default' => '',
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'login_url' => ['type' => 'string', 'description' => 'Temporary one-time admin login URL.'],
            'expires_at' => ['type' => 'integer', 'description' => 'Unix timestamp when the URL expires.'],
            'session_expires_in' => [
                'type' => 'integer',
                'description' => 'Browser admin session duration in seconds after the URL is opened.',
            ],
            'redirect_url' => ['type' => 'string', 'description' => 'Admin URL opened after the token is consumed.'],
            'one_time' => ['type' => 'boolean', 'description' => 'Whether the URL can only be used once.'],
        ],
        'required' => ['login_url', 'expires_at', 'session_expires_in', 'redirect_url', 'one_time'],
    ],
    'execute_callback' => 'novamira_create_admin_access_link',
    'permission_callback' => 'novamira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Use only when browser automation needs a WordPress admin session.',
                'Open the returned login_url directly in the browser tool.',
                'The URL is one-time use and expires quickly. Create a new link if the browser fails to open it in time.',
                'Do not paste this URL into public logs, issue trackers, or user-visible pages.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

/**
 * Create a temporary one-time admin access URL.
 *
 * @param array $input Input with optional expiry and admin path.
 * @return array|WP_Error
 */
function novamira_create_admin_access_link(array $input = [])
{
    $user_id = get_current_user_id();
    if ($user_id <= 0 || !current_user_can('manage_options')) {
        return new WP_Error('admin_access_forbidden', 'Only administrators can create admin access links.');
    }

    $expires_in = max(30, min(600, (int) ($input['expires_in'] ?? 300)));
    $session_expires_in = max(60, min(3_600, (int) ($input['session_expires_in'] ?? 1_800)));
    $admin_path = (string) ($input['admin_path'] ?? '');
    $redirect_url = novamira_resolve_admin_access_redirect($admin_path);
    if (is_wp_error($redirect_url)) {
        return $redirect_url;
    }

    $token = novamira_create_admin_access_token($user_id, $expires_in, $session_expires_in, $admin_path);
    if (is_wp_error($token)) {
        return $token;
    }

    return [
        'login_url' => add_query_arg('token', rawurlencode($token), rest_url('novamira/v1/admin-access')),
        'expires_at' => time() + $expires_in,
        'session_expires_in' => $session_expires_in,
        'redirect_url' => $redirect_url,
        'one_time' => true,
    ];
}
