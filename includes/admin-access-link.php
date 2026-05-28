<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Temporary signed admin access exchange for browser automation tools.
 */

if (!defined('ABSPATH')) {
    exit();
}

add_action('rest_api_init', callback: 'novamira_register_admin_access_route');

/**
 * Register the REST endpoints used by temporary admin access.
 */
function novamira_register_admin_access_route(): void
{
    register_rest_route(route_namespace: 'novamira/v1', route: '/admin-access', args: [
        'methods' => ['POST'],
        'callback' => 'novamira_handle_admin_access_exchange',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route(route_namespace: 'novamira/v1', route: '/admin-access/(?P<nonce>[A-Za-z0-9_-]+)', args: [
        'methods' => ['GET'],
        'callback' => 'novamira_handle_admin_access_login',
        'permission_callback' => '__return_true',
    ]);
}

/**
 * Create a one-time admin access token and binding nonce.
 *
 * @return array{token: string, nonce: string, expires_at: int}|WP_Error
 */
function novamira_create_admin_access_token(
    int $user_id,
    int $expires_in,
    int $session_expires_in,
    string $admin_path,
): array|WP_Error {
    $user = get_user_by('id', $user_id);
    if (!$user instanceof WP_User || !novamira_user_can_manage($user)) {
        return new WP_Error('invalid_admin_access_user', 'Admin access links can only be created for administrators.');
    }

    $redirect_url = novamira_resolve_admin_access_redirect($admin_path);
    if (is_wp_error($redirect_url)) {
        return $redirect_url;
    }

    $token = wp_generate_password(64, special_chars: false, extra_special_chars: false);
    $nonce = wp_generate_password(32, special_chars: false, extra_special_chars: false);
    $expires_at = time() + $expires_in;
    $payload = [
        'user_id' => $user_id,
        'redirect_url' => $redirect_url,
        'expires_at' => $expires_at,
        'session_expires_in' => $session_expires_in,
        'nonce_hash' => novamira_admin_access_nonce_hash($nonce),
    ];

    if (!set_transient(novamira_admin_access_transient_key($token), $payload, $expires_in)) {
        return new WP_Error('admin_access_token_store_failed', 'Could not store admin access token.');
    }

    return [
        'token' => $token,
        'nonce' => $nonce,
        'expires_at' => $expires_at,
    ];
}

/**
 * Exchange a one-time header token for a short-lived browser login nonce.
 *
 * @return WP_REST_Response|WP_Error
 */
function novamira_handle_admin_access_exchange(WP_REST_Request $request)
{
    if (!novamira_is_enabled()) {
        return new WP_Error('novamira_disabled', 'Novamira abilities are disabled.', ['status' => 403]);
    }

    $token = novamira_get_admin_access_token_from_request($request);
    if ($token === '') {
        return new WP_Error('missing_admin_access_token', 'Missing admin access token.', ['status' => 401]);
    }

    $nonce = novamira_get_admin_access_nonce_from_request($request);
    if ($nonce === '') {
        return new WP_Error('missing_admin_access_nonce', 'Missing admin access nonce.', ['status' => 401]);
    }

    // @mago-expect analysis:mixed-assignment
    $payload = get_transient(novamira_admin_access_transient_key($token));
    delete_transient(novamira_admin_access_transient_key($token));

    if (!is_array($payload)) {
        return new WP_Error('invalid_admin_access_token', 'Invalid or expired admin access token.', ['status' => 401]);
    }

    if (!array_key_exists('nonce_hash', $payload) || !is_string($payload['nonce_hash'])) {
        return new WP_Error('invalid_admin_access_token', 'Invalid or expired admin access token.', ['status' => 401]);
    }

    if (!hash_equals($payload['nonce_hash'], novamira_admin_access_nonce_hash($nonce))) {
        return new WP_Error('invalid_admin_access_token', 'Invalid or expired admin access token.', ['status' => 401]);
    }

    $access = novamira_validate_admin_access_payload($payload);
    if (is_wp_error($access)) {
        return $access;
    }

    $login_nonce = wp_generate_password(48, special_chars: false, extra_special_chars: false);
    $login_expires_at = min($access['expires_at'], time() + 60);
    $login_payload = [
        'user_id' => $access['user_id'],
        'redirect_url' => $access['redirect_url'],
        'expires_at' => $login_expires_at,
        'session_expires_in' => $access['session_expires_in'],
    ];

    $login_expires_in = max(1, $login_expires_at - time());
    if (!set_transient(
        novamira_admin_access_login_nonce_transient_key($login_nonce),
        $login_payload,
        $login_expires_in,
    )) {
        return new WP_Error('admin_access_nonce_store_failed', 'Could not store admin access login nonce.');
    }

    $response = new WP_REST_Response([
        'login_url' => rest_url('novamira/v1/admin-access/' . rawurlencode($login_nonce)),
        'expires_at' => $login_expires_at,
        'session_expires_in' => $access['session_expires_in'],
        'redirect_url' => $access['redirect_url'],
        'one_time' => true,
    ]);
    novamira_admin_access_no_store_headers($response);

    return $response;
}

/**
 * Consume a one-time browser login nonce and redirect to wp-admin.
 *
 * @return WP_REST_Response|WP_Error
 */
function novamira_handle_admin_access_login(WP_REST_Request $request)
{
    if (!novamira_is_enabled()) {
        return new WP_Error('novamira_disabled', 'Novamira abilities are disabled.', ['status' => 403]);
    }

    $params = $request->get_url_params();
    $nonce = '';
    if (array_key_exists('nonce', $params) && is_string($params['nonce'])) {
        $nonce = $params['nonce'];
    }
    if ($nonce === '') {
        return new WP_Error('missing_admin_access_nonce', 'Missing admin access nonce.', ['status' => 401]);
    }

    // @mago-expect analysis:mixed-assignment
    $payload = get_transient(novamira_admin_access_login_nonce_transient_key($nonce));
    delete_transient(novamira_admin_access_login_nonce_transient_key($nonce));

    if (!is_array($payload)) {
        return new WP_Error('invalid_admin_access_nonce', 'Invalid or expired admin access nonce.', ['status' => 401]);
    }

    $access = novamira_validate_admin_access_payload($payload);
    if (is_wp_error($access)) {
        return $access;
    }

    return novamira_create_admin_access_redirect_response($access);
}

/**
 * Return the admin access token from request headers.
 */
function novamira_get_admin_access_token_from_request(WP_REST_Request $request): string
{
    return novamira_rest_header_token($request, header_name: 'x-novamira-admin-access-token');
}

/**
 * Return the admin access binding nonce from request headers.
 */
function novamira_get_admin_access_nonce_from_request(WP_REST_Request $request): string
{
    $header_nonce = $request->get_header('x-novamira-admin-access-nonce');
    if (!is_string($header_nonce)) {
        return '';
    }

    return trim($header_nonce);
}

/**
 * Validate a stored admin access payload.
 *
 * @param array<array-key, mixed> $payload
 * @return array{user_id: int, redirect_url: string, expires_at: int, session_expires_in: int}|WP_Error
 */
function novamira_validate_admin_access_payload(array $payload): array|WP_Error
{
    $expires_at = (int) ($payload['expires_at'] ?? 0);
    if ($expires_at < time()) {
        return new WP_Error('invalid_admin_access_token', 'Invalid or expired admin access token.', ['status' => 401]);
    }

    $user_id = (int) ($payload['user_id'] ?? 0);
    $user = get_user_by('id', $user_id);
    if (!$user instanceof WP_User || !novamira_user_can_manage($user)) {
        return new WP_Error('invalid_admin_access_token', 'Invalid or expired admin access token.', ['status' => 401]);
    }

    if (!array_key_exists('redirect_url', $payload) || !is_string($payload['redirect_url'])) {
        return new WP_Error('invalid_admin_access_token', 'Invalid or expired admin access token.', ['status' => 401]);
    }

    $redirect_url = $payload['redirect_url'];
    if (!str_starts_with($redirect_url, admin_url())) {
        return new WP_Error('invalid_admin_access_token', 'Invalid or expired admin access token.', ['status' => 401]);
    }

    return [
        'user_id' => $user_id,
        'redirect_url' => $redirect_url,
        'expires_at' => $expires_at,
        'session_expires_in' => max(60, min(3_600, (int) ($payload['session_expires_in'] ?? 1_800))),
    ];
}

/**
 * Create the redirect response that establishes a short admin session.
 *
 * @param array{user_id: int, redirect_url: string, expires_at: int, session_expires_in: int} $access
 */
function novamira_create_admin_access_redirect_response(array $access): WP_REST_Response
{
    $session_expires_in = $access['session_expires_in'];
    $expire_session_soon = static fn(int $length): int => $session_expires_in;

    add_filter('auth_cookie_expiration', $expire_session_soon);
    try {
        wp_set_current_user($access['user_id']);
        wp_set_auth_cookie($access['user_id'], remember: false, secure: is_ssl());
    } finally {
        remove_filter('auth_cookie_expiration', $expire_session_soon);
    }

    $response = new WP_REST_Response(null, 302);
    $response->header('Location', $access['redirect_url']);
    novamira_admin_access_no_store_headers($response);
    $response->header('Referrer-Policy', 'no-referrer');

    return $response;
}

/**
 * Set no-store headers on admin access responses.
 */
function novamira_admin_access_no_store_headers(WP_REST_Response $response): void
{
    $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    $response->header('Pragma', 'no-cache');
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

/**
 * Return the transient key for a one-time admin access browser nonce.
 */
function novamira_admin_access_login_nonce_transient_key(string $nonce): string
{
    return 'novamira_admin_access_login_' . hash_hmac('sha256', $nonce, wp_salt('auth'));
}

/**
 * Return the stored hash for an admin access binding nonce.
 */
function novamira_admin_access_nonce_hash(string $nonce): string
{
    return hash_hmac('sha256', $nonce, wp_salt('nonce') . '|novamira-admin-access');
}
