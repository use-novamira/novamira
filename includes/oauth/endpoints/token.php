<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\OAuth\Endpoints\Token;

use League\OAuth2\Server\Exception\OAuthServerException;
use Novamira\OAuth\Bridge;
use Novamira\OAuth\ClientValidation;
use Novamira\OAuth\Repositories\ClientRepository;
use Novamira\OAuth\ServerFactory;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit();
}

function register(): void
{
    register_rest_route('novamira/v1', route: '/oauth/token', args: [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => __NAMESPACE__ . '\\handle',
    ]);
}

function handle(WP_REST_Request $req): WP_REST_Response
{
    $client_ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!ClientValidation\within_endpoint_rate_limit('token', $client_ip === '' ? 'unattributed' : $client_ip)) {
        return new WP_REST_Response(['error' => 'temporarily_unavailable'], 429);
    }

    // RFC 8707: refuse a token request that explicitly targets a resource this server does not
    // expose. An absent resource is fine — the issued token's audience is bound to ours regardless.
    $resource = (string) $req->get_param('resource');
    if (!\Novamira\OAuth\resource_request_allowed($resource, \Novamira\OAuth\resource_identifier())) {
        return new WP_REST_Response([
            'error' => 'invalid_target',
            'error_description' => 'The requested resource is not served here.',
        ], 400);
    }

    // Single use is enforced durably in the repositories: revokeAuthCode()/revokeRefreshToken()
    // atomically claim the credential (UPDATE ... WHERE revoked = 0) after league has validated it,
    // so a concurrent or repeated redemption cannot complete. No advisory lock is involved.
    try {
        $server = ServerFactory\build_authorization_server();
        $psr7Response = $server->respondToAccessTokenRequest(Bridge\to_psr7($req), Bridge\new_psr7_response());

        $body = $req->get_body_params();
        $client_id = (string) ($body['client_id'] ?? '');
        if ($client_id !== '') {
            (new ClientRepository())->touchLastUsed($client_id);
        }

        return Bridge\from_psr7($psr7Response);
    } catch (OAuthServerException $e) {
        return Bridge\from_psr7($e->generateHttpResponse(Bridge\new_psr7_response()));
    } catch (\Exception $e) {
        return Bridge\from_psr7(
            OAuthServerException::serverError('Internal server error', $e)->generateHttpResponse(
                Bridge\new_psr7_response(),
            ),
        );
    }
}
