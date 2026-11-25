<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\OAuth\Endpoints\Revoke;

use Defuse\Crypto\Crypto;
use Novamira\OAuth\Bridge;
use Novamira\OAuth\ClientValidation;
use Novamira\OAuth\Keys;
use Novamira\OAuth\Repositories\AccessTokenRepository;
use Novamira\OAuth\Repositories\RefreshTokenRepository;
use Novamira\OAuth\ServerFactory;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit();
}

function register(): void
{
    register_rest_route('novamira/v1', route: '/oauth/revoke', args: [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => __NAMESPACE__ . '\\handle',
    ]);
}

function handle(WP_REST_Request $req): WP_REST_Response
{
    $client_ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!ClientValidation\within_endpoint_rate_limit('revoke', $client_ip === '' ? 'unattributed' : $client_ip)) {
        return new WP_REST_Response(null, 429);
    }

    $body = $req->get_body_params();
    $token = (string) ($body['token'] ?? '');
    if ($token === '') {
        return new WP_REST_Response(null, 200);
    }
    $client_id = (string) ($body['client_id'] ?? '');

    // Try both types per RFC 7009 §2.1 regardless of token_type_hint; each cascades onto its pair.
    try_revoke_access($token, $client_id);
    try_revoke_refresh($token, $client_id);
    return new WP_REST_Response(null, 200);
}

function try_revoke_access(string $token, string $clientId): void
{
    try {
        $server = ServerFactory\build_resource_server();
        $fake = Bridge\psr7_from_globals()->withHeader('Authorization', 'Bearer ' . $token);
        $validated = $server->validateAuthenticatedRequest($fake);
        $jti = (string) $validated->getAttribute('oauth_access_token_id');
        if ($jti !== '') {
            (new AccessTokenRepository())->revokeGrantByAccessHash(hash('sha256', $jti), $clientId);
        }

        // @mago-expect lint:no-empty-catch-clause
    } catch (\Throwable $e) {
        // Not a valid/active access token — ignore per RFC 7009.
    }
}

function try_revoke_refresh(string $token, string $clientId): void
{
    // Our refresh tokens are hex Defuse ciphertext; skip the deliberately slow password KDF for
    // anything that cannot be one, so a flood of garbage tokens cannot force expensive decryptions.
    if (!ctype_xdigit($token)) {
        return;
    }

    try {
        $keys = Keys\get();
        $decrypted = Crypto::decryptWithPassword($token, $keys['encryption']);
        // @mago-expect analysis:mixed-assignment
        $payload = json_decode($decrypted, associative: true);
        if (!is_array($payload)) {
            return;
        }
        // @mago-expect analysis:mixed-assignment
        $jti = $payload['refresh_token_id'] ?? null;
        if (!is_string($jti) || $jti === '') {
            return;
        }
        // Cascade: resolve the paired access token and revoke both members of the grant.
        $access_hash = (new RefreshTokenRepository())->accessTokenHashFor($jti);
        if ($access_hash !== '') {
            (new AccessTokenRepository())->revokeGrantByAccessHash($access_hash, $clientId);
        }

        // @mago-expect lint:no-empty-catch-clause
    } catch (\Throwable $e) {
        // Not a valid refresh token — ignore per RFC 7009.
    }
}
