<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Temporary signed admin access links for browser automation tools.
 */

if (!defined('ABSPATH')) {
    exit();
}

add_action('rest_api_init', callback: 'novamira_register_admin_access_route');

/**
 * Register the REST endpoint used by temporary admin access links.
 */
function novamira_register_admin_access_route(): void
{
    register_rest_route(route_namespace: 'novamira/v1', route: '/admin-access', args: [
        'methods' => ['GET'],
        'callback' => 'novamira_handle_admin_access_link',
        'permission_callback' => '__return_true',
    ]);
}

/**
 * Create a one-time admin access token.
 *
 * @return string|WP_Error
 */
function novamira_create_admin_access_token(int $user_id, int $expires_in, int $session_expires_in, string $admin_path)
{
    $user = get_user_by('id', $user_id);
    if (!$user instanceof WP_User || !user_can($user, capability: 'manage_options')) {
        return new WP_Error('invalid_admin_access_user', 'Admin access links can only be created for administrators.');
    }

    $redirect_url = novamira_resolve_admin_access_redirect($admin_path);
    if (is_wp_error($redirect_url)) {
        return $redirect_url;
    }

    $token = wp_generate_password(64, special_chars: false, extra_special_chars: false);
    $payload = [
        'user_id' => $user_id,
        'redirect_url' => $redirect_url,
        'expires_at' => time() + $expires_in,
        'session_expires_in' => $session_expires_in,
    ];

    if (!set_transient(novamira_admin_access_transient_key($token), $payload, $expires_in)) {
        return new WP_Error('admin_access_token_store_failed', 'Could not store admin access token.');
    }

    return $token;
}

/**
 * Consume a temporary admin access link and redirect to wp-admin.
 *
 * @return WP_REST_Response|WP_Error
 */
function novamira_handle_admin_access_link(WP_REST_Request $request)
{
    if (!novamira_is_enabled()) {
        return new WP_Error('novamira_disabled', 'Novamira abilities are disabled.', ['status' => 403]);
    }

    $token = novamira_get_admin_access_token_from_request($request);
    if ($token === '') {
        return new WP_Error('missing_admin_access_token', 'Missing admin access token.', ['status' => 401]);
    }

    // @mago-expect analysis:mixed-assignment
    $payload = get_transient(novamira_admin_access_transient_key($token));
    delete_transient(novamira_admin_access_transient_key($token));

    if (!is_array($payload)) {
        return new WP_Error('invalid_admin_access_token', 'Invalid or expired admin access token.', ['status' => 401]);
    }

    $expires_at = (int) ($payload['expires_at'] ?? 0);
    if ($expires_at < time()) {
        return new WP_Error('invalid_admin_access_token', 'Invalid or expired admin access token.', ['status' => 401]);
    }

    $user_id = (int) ($payload['user_id'] ?? 0);
    $user = get_user_by('id', $user_id);
    if (!$user instanceof WP_User || !user_can($user, capability: 'manage_options')) {
        return new WP_Error('invalid_admin_access_token', 'Invalid or expired admin access token.', ['status' => 401]);
    }

    if (!array_key_exists('redirect_url', $payload) || !is_string($payload['redirect_url'])) {
        return new WP_Error('invalid_admin_access_token', 'Invalid or expired admin access token.', ['status' => 401]);
    }

    $redirect_url = $payload['redirect_url'];
    if (!str_starts_with($redirect_url, admin_url())) {
        return new WP_Error('invalid_admin_access_token', 'Invalid or expired admin access token.', ['status' => 401]);
    }

    $session_expires_in = max(60, min(3_600, (int) ($payload['session_expires_in'] ?? 1_800)));
    $expire_session_soon = static fn(int $length): int => $session_expires_in;

    add_filter('auth_cookie_expiration', $expire_session_soon);
    try {
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id, remember: false, secure: is_ssl());
    } finally {
        remove_filter('auth_cookie_expiration', $expire_session_soon);
    }

    $response = new WP_REST_Response(null, 302);
    $response->header('Location', $redirect_url);
    $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    $response->header('Pragma', 'no-cache');

    return $response;
}

/**
 * Return the admin access token from query args or headers.
 */
function novamira_get_admin_access_token_from_request(WP_REST_Request $request): string
{
    $query_params = $request->get_query_params();
    if (array_key_exists('token', $query_params) && is_string($query_params['token'])) {
        return rawurldecode($query_params['token']);
    }

    $header_token = $request->get_header('x-novamira-admin-access-token');
    if (is_string($header_token)) {
        return $header_token;
    }

    return '';
}

/**
 * Resolve an optional admin-relative redirect target.
 *
 * @return string|WP_Error
 */
function novamira_resolve_admin_access_redirect(string $admin_path)
{
    $admin_path = trim($admin_path);
    if ($admin_path === '') {
        return admin_url();
    }

    if (
        str_contains($admin_path, "\r")
        || str_contains($admin_path, "\n")
        || preg_match('#^[a-z][a-z0-9+.-]*:#i', $admin_path) === 1
        || str_starts_with($admin_path, '//')
    ) {
        return new WP_Error(
            'invalid_admin_access_redirect',
            'Redirect path must be relative to wp-admin, not an absolute URL.',
        );
    }

    $admin_path = ltrim($admin_path, characters: '/');
    if (str_starts_with($admin_path, 'wp-admin/')) {
        $admin_path = substr($admin_path, strlen('wp-admin/'));
    }

    return admin_url($admin_path);
}

/**
 * Return the transient key for an admin access token.
 */
function novamira_admin_access_transient_key(string $token): string
{
    return 'novamira_admin_access_' . hash_hmac('sha256', $token, wp_salt('auth'));
}
