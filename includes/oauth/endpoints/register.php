<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\OAuth\Endpoints\Register;

use Novamira\OAuth\ClientValidation;
use Novamira\OAuth\Repositories\ClientRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit();
}

function register(): void
{
    register_rest_route('novamira/v1', route: '/oauth/register', args: [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => __NAMESPACE__ . '\\handle',
    ]);
}

/**
 * The troubleshooter's registration self-test authenticates itself with a single-use token
 * (minted server-side into a short transient right before the probe). A matching request skips
 * the per-IP limits: every self-test comes from the server's own address, so counting it would
 * let repeated diagnostics runs saturate that bucket and make the check warn about a rate limit
 * the diagnostics themselves caused. Unforgeable (random, single-use, 60s TTL) and it grants
 * nothing else — caps on live connections still apply.
 */
function is_self_test_request(WP_REST_Request $req): bool
{
    $token = trim((string) $req->get_header('x-novamira-self-test'));
    if ($token === '') {
        return false;
    }
    $key = 'novamira_oauth_selftest_' . hash('sha256', $token);
    /** @var mixed $found */
    $found = get_transient($key);
    delete_transient($key);
    return $found === '1' || $found === 1;
}

/**
 * RFC 7591 requires redirect_uris only for grants that redirect. A device client never does — that
 * is the whole point of the grant — so it registers without one, and having none is also what keeps
 * it out of the authorization-code flow.
 */
function register_device_client(string $client_name, string $client_ip): WP_REST_Response|WP_Error
{
    $grants = [\Novamira\OAuth\DEVICE_CODE_GRANT_TYPE, 'refresh_token'];
    $client_id = store_client($client_name, [], $client_ip, $grants);
    if ($client_id === null) {
        return client_store_failure();
    }

    return new WP_REST_Response([
        'client_id' => $client_id,
        'client_name' => $client_name,
        'redirect_uris' => [],
        'token_endpoint_auth_method' => 'none',
        'grant_types' => $grants,
        'response_types' => [],
    ], 201);
}

/**
 * Whether a registration request describes a device-authorization client.
 *
 * A client advertises every grant it supports, so device_code on its own does not make one
 * device-only: VS Code lists it next to authorization_code and supplies the loopback redirect_uri
 * that second grant needs, and registering it as device-only threw that URI away and left it unable
 * to authorize at all. Answer true only when the client could not run the authorization code flow
 * regardless, having asked for neither that grant nor a redirect_uri to send the user back to.
 *
 * @param mixed $requested_grants The request's `grant_types`, still unvalidated.
 * @param mixed $redirect_uris    The request's `redirect_uris`, still unvalidated.
 */
function is_device_only_registration(mixed $requested_grants, mixed $redirect_uris): bool
{
    $grants = is_array($requested_grants) ? $requested_grants : [];
    if (!in_array(\Novamira\OAuth\DEVICE_CODE_GRANT_TYPE, $grants, strict: true)) {
        return false;
    }

    $has_redirect_uris = is_array($redirect_uris) && $redirect_uris !== [];

    return !in_array('authorization_code', $grants, strict: true) || !$has_redirect_uris;
}

/**
 * Per-IP and per-site limits on anonymous registration, applied before anything is written. Returns
 * the refusal to send, or null when the registration may proceed.
 */
function registration_refusal(WP_REST_Request $req, string $client_ip): ?WP_Error
{
    ClientValidation\prune_dead_clients();
    $self_test = is_self_test_request($req);
    if ($client_ip !== '' && !$self_test && !ClientValidation\check_and_increment_rate_limit($client_ip)) {
        return new WP_Error('rate_limited', 'Too many registrations', ['status' => 429]);
    }
    if (
        $client_ip !== ''
        && !$self_test
        && ClientValidation\client_count_for_ip($client_ip) >= ClientValidation\MAX_CLIENTS_PER_IP
    ) {
        return new WP_Error('rate_limited', 'Too many registered clients from this address', ['status' => 429]);
    }
    // Cap only live connections, not total rows: active_client_count() ignores pending registrations,
    // which are admin-ungated, so an anonymous DCR flood can no longer exhaust the slots.
    if (ClientValidation\active_client_count() >= ClientValidation\max_clients_per_site()) {
        return new WP_Error('cap_reached', 'Client cap reached', ['status' => 503]);
    }
    return null;
}

/**
 * Stores a registered client, repairing the client store once if that is why the write failed.
 *
 * A clients table that lacks a column refuses every insert, and this is where that surfaces: the
 * installer records its version even when its own ADD COLUMN was denied, so it does not retry on
 * every request. So the repair runs here, on demand — additive only, see
 * Schema\repair_missing_columns() — and the insert is retried exactly once, and only when the clients
 * table was provably missing a column. A write that failed for any other reason is not retried, and
 * nothing here loops.
 *
 * @param list<string> $redirect_uris
 * @param list<string>|null $grant_types Null registers the default grants.
 */
function store_client(string $client_name, array $redirect_uris, string $client_ip, ?array $grant_types = null): ?string
{
    $clients = new ClientRepository();
    $client_id = $clients->create($client_name, $redirect_uris, $client_ip, grant_types: $grant_types);
    if ($client_id !== null) {
        return $client_id;
    }

    // @mago-expect lint:no-global
    global $wpdb;
    /** @var \wpdb $wpdb */
    $report = \Novamira\OAuth\Schema\repair_missing_columns($wpdb->prefix . 'novamira_oauth_');
    if (!array_key_exists('clients', $report['missing'])) {
        return null;
    }

    return $clients->create($client_name, $redirect_uris, $client_ip, grant_types: $grant_types);
}

/**
 * The registration could not be stored.
 *
 * Answering 201 with an identifier the client store never accepted is worse than refusing: the
 * client keeps a client_id that the very next authorization request cannot resolve, and the site
 * reports nothing wrong. 500 says the failure is on this server, which is where it is.
 */
function client_store_failure(): WP_Error
{
    return new WP_Error(
        'server_error',
        __(
            'The client could not be stored, so no registration was created. See the OAuth storage check on the Novamira Troubleshoot page, and the database error in the PHP error log.',
            domain: 'novamira',
        ),
        ['status' => 500],
    );
}

// @mago-expect lint:cyclomatic-complexity
function handle(WP_REST_Request $req): WP_REST_Response|WP_Error
{
    $client_ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $refusal = registration_refusal($req, $client_ip);
    if ($refusal !== null) {
        return $refusal;
    }

    $body = $req->get_json_params();
    // @mago-expect analysis:mixed-assignment
    $client_name = sanitize_text_field(trim((string) ($body['client_name'] ?? '')));
    if ($client_name === '' || strlen($client_name) > 191) {
        return new WP_Error('invalid_request', 'client_name must be 1..191 chars', ['status' => 400]);
    }

    // @mago-expect analysis:mixed-assignment
    $requested_grants = $body['grant_types'] ?? null;
    // @mago-expect analysis:mixed-assignment
    $redirect_uris = $body['redirect_uris'] ?? null;

    if (is_device_only_registration($requested_grants, $redirect_uris)) {
        return register_device_client($client_name, $client_ip);
    }

    if (!is_array($redirect_uris) || $redirect_uris === []) {
        return new WP_Error('invalid_request', 'redirect_uris must be a non-empty array', ['status' => 400]);
    }
    if (count($redirect_uris) > 5) {
        return new WP_Error('invalid_request', 'Max 5 redirect_uris', ['status' => 400]);
    }

    // @mago-expect analysis:mixed-operand
    $dev_mode = defined('WP_DEBUG') && (bool) \WP_DEBUG;
    $clean_uris = [];
    foreach ($redirect_uris as $uri) {
        $uri = is_string($uri) ? trim($uri) : '';
        if (
            $uri === ''
            || strlen($uri) > ClientValidation\MAX_REDIRECT_URI_LENGTH
            || !ClientValidation\is_allowed_redirect_uri($uri, $dev_mode)
        ) {
            return new WP_Error('invalid_redirect_uri', sprintf('redirect_uri not allowed: %s', esc_html($uri)), [
                'status' => 400,
            ]);
        }
        $clean_uris[] = $uri;
    }

    $clean_uris = array_values(array_unique($clean_uris));
    $client_id = store_client($client_name, $clean_uris, $client_ip);
    if ($client_id === null) {
        return client_store_failure();
    }

    return new WP_REST_Response([
        'client_id' => $client_id,
        'client_name' => $client_name,
        'redirect_uris' => $clean_uris,
        'token_endpoint_auth_method' => 'none',
        'grant_types' => ['authorization_code', 'refresh_token'],
        'response_types' => ['code'],
    ], 201);
}
