<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Ability: Create a temporary one-time admin access exchange.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('novamira/create-admin-access-link', [
    'label' => __('Create Admin Access Link', domain: 'novamira'),
    'description' => __(
        'Creates a temporary, one-time WordPress admin access exchange for browser automation tools. Use this when an agent needs to inspect or operate wp-admin through a browser MCP without asking the user for a password. POST the returned token and nonce in headers to receive a short-lived one-time login URL.',
        domain: 'novamira',
    ),
    'category' => 'admin-access',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'expires_in' => [
                'type' => 'integer',
                'description' => 'Seconds before the admin access exchange expires. Minimum 30, maximum 600.',
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
            'exchange_url' => [
                'type' => 'string',
                'description' => 'Admin access exchange endpoint. Send access_token and access_nonce with POST headers.',
            ],
            'exchange_method' => ['type' => 'string', 'description' => 'HTTP method for the exchange request.'],
            'access_token' => [
                'type' => 'string',
                'description' => 'One-time admin access token. Send as the token_header value.',
            ],
            'token_header' => ['type' => 'string', 'description' => 'HTTP header that must carry access_token.'],
            'access_nonce' => [
                'type' => 'string',
                'description' => 'One-time binding nonce. Send as the nonce_header value.',
            ],
            'nonce_header' => ['type' => 'string', 'description' => 'HTTP header that must carry access_nonce.'],
            'expires_at' => ['type' => 'integer', 'description' => 'Unix timestamp when the exchange expires.'],
            'session_expires_in' => [
                'type' => 'integer',
                'description' => 'Browser admin session duration in seconds after the URL is opened.',
            ],
            'redirect_url' => ['type' => 'string', 'description' => 'Admin URL opened after the token is consumed.'],
            'one_time' => ['type' => 'boolean', 'description' => 'Whether the URL can only be used once.'],
            'curl_example' => [
                'type' => 'string',
                'description' => 'Example exchange request. It returns JSON containing a one-time login_url.',
            ],
        ],
        'required' => [
            'exchange_url',
            'exchange_method',
            'access_token',
            'token_header',
            'access_nonce',
            'nonce_header',
            'expires_at',
            'session_expires_in',
            'redirect_url',
            'one_time',
        ],
    ],
    'execute_callback' => 'novamira_create_admin_access_link',
    'permission_callback' => 'novamira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Use only when browser automation needs a WordPress admin session.',
                'POST to exchange_url with token_header/access_token and nonce_header/access_nonce to receive a one-time login_url.',
                'Open the exchanged login_url immediately in the browser tool. The login_url nonce expires after at most 60 seconds.',
                'Tokens are not accepted in query strings. Do not paste token values into public logs, issue trackers, or user-visible pages.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

/**
 * Create a temporary one-time admin access exchange.
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

    $access = novamira_create_admin_access_token($user_id, $expires_in, $session_expires_in, $admin_path);
    if (is_wp_error($access)) {
        return $access;
    }

    $exchange_url = rest_url('novamira/v1/admin-access');
    $token_header = 'X-Novamira-Admin-Access-Token';
    $nonce_header = 'X-Novamira-Admin-Access-Nonce';

    return [
        'exchange_url' => $exchange_url,
        'exchange_method' => 'POST',
        'access_token' => $access['token'],
        'token_header' => $token_header,
        'access_nonce' => $access['nonce'],
        'nonce_header' => $nonce_header,
        'expires_at' => $access['expires_at'],
        'session_expires_in' => $session_expires_in,
        'redirect_url' => $redirect_url,
        'one_time' => true,
        'curl_example' => sprintf(
            'curl -s -X POST -H "%s: $access_token" -H "%s: $access_nonce" %s',
            $token_header,
            $nonce_header,
            escapeshellarg($exchange_url),
        ),
    ];
}
