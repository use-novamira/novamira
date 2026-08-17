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
function register_device_client(string $client_name, string $client_ip): WP_REST_Response
{
    $grants = [\Novamira\OAuth\DEVICE_CODE_GRANT_TYPE, 'refresh_token'];
    $client_id = (new ClientRepository())->create(
        $client_name,
        [],
        $client_ip,
        admin_created: false,
        grant_types: $grants,
    );

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
    // A client that supplies redirect_uris needs the Authorization Code flow even if it also lists
    // device_code among its supported grants, so only the redirect-less case is device-only.
    $device_client =
        is_array($requested_grants)
        && in_array(\Novamira\OAuth\DEVICE_CODE_GRANT_TYPE, $requested_grants, strict: true)
        && (!is_array($redirect_uris) || $redirect_uris === []);

    if ($device_client) {
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
    $client_id = (new ClientRepository())->create($client_name, $clean_uris, $client_ip);

    return new WP_REST_Response([
        'client_id' => $client_id,
        'client_name' => $client_name,
        'redirect_uris' => $clean_uris,
        'token_endpoint_auth_method' => 'none',
        'grant_types' => ['authorization_code', 'refresh_token'],
        'response_types' => ['code'],
    ], 201);
}
