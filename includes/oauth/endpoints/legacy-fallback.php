<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\OAuth\Endpoints\LegacyFallback;

if (!defined('ABSPATH')) {
    exit();
}

const AUTHORIZE_PATH = '/authorize';

const REGISTER_PATH = '/register';

const TOKEN_PATH = '/token';

/** @var list<string> */
const AUTHORIZATION_QUERY_PARAMETERS = [
    'response_type',
    'client_id',
    'redirect_uri',
    'code_challenge',
    'code_challenge_method',
    'scope',
    'state',
    'resource',
];

function register(): void
{
    // Run before canonical redirects and the template loader, but after WordPress has resolved the
    // request. is_404() lets real pages and custom rewrite routes keep these generic path names.
    add_action('template_redirect', __NAMESPACE__ . '\\maybe_serve', priority: 0);
}

/**
 * Return the legacy MCP fallback endpoint named by an HTTP request.
 */
function endpoint_for_request(string $request_uri, string $method): ?string
{
    $path = parse_url($request_uri, PHP_URL_PATH);
    if (!is_string($path)) {
        return null;
    }

    $method = strtoupper($method);
    return match ([$path, $method]) {
        [AUTHORIZE_PATH, 'GET'] => 'authorize',
        [REGISTER_PATH, 'POST'] => 'register',
        [TOKEN_PATH, 'POST'] => 'token',
        default => null,
    };
}

function maybe_serve(): void
{
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if (is_front_authorize_request($_GET, $_SERVER['REQUEST_METHOD'] ?? 'GET')) {
        redirect_to_authorization_page();
    }

    // Do not take these deliberately generic slugs away from a page, plugin, theme, or custom
    // rewrite rule. The fallback exists only for paths WordPress could not otherwise resolve.
    if (!is_404()) {
        return;
    }

    $endpoint = endpoint_for_request($_SERVER['REQUEST_URI'] ?? '', $_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($endpoint === null) {
        return;
    }

    if ($endpoint === 'authorize') {
        redirect_to_authorization_page();
    }

    serve_rest_endpoint('/novamira/v1/oauth/' . $endpoint);
}

/**
 * Whether the request is the front-end authorization entry advertised to sites with a custom login
 * URL. A client that appends its parameters with "?" folds them into the value
 * (novamira_oauth=authorize?response_type=...), which authorization_parameters() recovers.
 *
 * @param array<array-key, mixed> $query
 */
function is_front_authorize_request(array $query, string $method): bool
{
    $entry = string_parameter($query, \Novamira\OAuth\Endpoints\Discovery\FRONT_AUTHORIZE_QUERY_VAR);
    if (strtoupper($method) !== 'GET' || $entry === null) {
        return false;
    }

    return $entry === 'authorize' || str_starts_with($entry, 'authorize?');
}

/**
 * The parameters forwarded to the authorization page: only those it consumes, including any a
 * client folded into the front-end entry's value.
 *
 * @param array<array-key, mixed> $query
 * @return array<string, string>
 */
function authorization_parameters(array $query): array
{
    $entry = string_parameter($query, \Novamira\OAuth\Endpoints\Discovery\FRONT_AUTHORIZE_QUERY_VAR) ?? '';
    $folded = [];
    if (str_starts_with($entry, 'authorize?')) {
        parse_str(substr($entry, strlen('authorize?')), $folded);
    }

    $parameters = [];
    foreach (AUTHORIZATION_QUERY_PARAMETERS as $name) {
        $value = string_parameter($query, $name) ?? string_parameter($folded, $name);
        if ($value !== null) {
            $parameters[$name] = wp_unslash($value);
        }
    }

    return $parameters;
}

/**
 * @param array<array-key, mixed> $query
 */
function string_parameter(array $query, string $name): ?string
{
    if (!array_key_exists($name, $query) || !is_string($query[$name])) {
        return null;
    }

    return $query[$name];
}

/**
 * Move the browser into wp-admin so WordPress can authenticate the user with its normal admin
 * cookie. Only parameters consumed by the authorization endpoint are forwarded.
 */
function redirect_to_authorization_page(): void
{
    // OAuth authorization requests are cross-site GETs and intentionally carry no WP nonce.
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $parameters = authorization_parameters($_GET);

    $url = add_query_arg($parameters, admin_url('admin.php?page=novamira-oauth-authorize'));
    // Logged-out visitors go through wp_login_url(), which login-hiding plugins rewrite to their
    // custom page; requesting wp-admin directly would get their 404 instead of a login form.
    if (!is_user_logged_in()) {
        $url = wp_login_url($url);
    }
    nocache_headers();
    if (!wp_safe_redirect($url)) {
        wp_die(esc_html__('Could not open the Novamira authorization page.', domain: 'novamira'), title: '', args: [
            'response' => 500,
        ]);
    }
    exit();
}

/**
 * Dispatch a fallback POST through WordPress's REST server. This preserves the original headers
 * and body while reusing the canonical endpoint's validation, rate limits and response handling.
 */
function serve_rest_endpoint(string $route): void
{
    if (!defined('REST_REQUEST')) {
        define('REST_REQUEST', value: true);
    }

    rest_get_server()->serve_request($route);
    exit();
}
