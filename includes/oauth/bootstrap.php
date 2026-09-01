<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\OAuth;

if (!defined('ABSPATH')) {
    exit();
}

// Loaded outside boot() on purpose: the transport and abilities gates below decide whether the
// OAuth *endpoints* exist, not whether its read-only helpers are callable. Diagnostics ask how
// many connections are active precisely when the gates are closed, and must not fatal doing so.
require_once __DIR__ . '/client-validation.php';

// Also outside boot(): the key-bootstrap command exists to repair an installation whose web PHP
// cannot create the OAuth keys at all, so it must not depend on the gates below. WP-CLI defines
// WP_CLI before plugins load, so nothing extra is read on a web request.
if (defined('WP_CLI') && constant('WP_CLI') === true) {
    require_once __DIR__ . '/keys.php';
    require_once __DIR__ . '/cli.php';
    Cli\register();
}

/**
 * RFC 8628 grant identifier, named once here because discovery advertises it, the registration
 * endpoint accepts it, and the grant answers to it — three files that must never disagree.
 */
const DEVICE_CODE_GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:device_code';

/**
 * Canonical identifier of the OAuth-protected MCP resource: the audience minted into every access
 * token (RFC 8707) and validated by the middleware. Derived in one place so the signer and the
 * verifier cannot drift. Matches the server route registered in novamira.php and advertised by the
 * protected-resource metadata.
 */
function resource_identifier(): string
{
    return rest_url('mcp/novamira-oauth');
}

/**
 * RFC 8707: an authorization or token request MAY carry a `resource`. This server exposes exactly
 * one resource, so an empty value is accepted (the audience is bound regardless) and any present
 * value must name that resource (a trailing slash is ignored). Any other target is refused.
 */
function resource_request_allowed(string $requested, string $expected): bool
{
    if ($requested === '') {
        return true;
    }
    return rtrim($requested, characters: '/') === rtrim($expected, characters: '/');
}

/**
 * Scopes accepted by the authorization and token servers. `mcp` is the only scope issued.
 * The other values remain accepted as migration aliases for clients authenticated before
 * Novamira switched to one full-access grant.
 *
 * @return list<string>
 */
function supported_scopes(): array
{
    return ['mcp', 'read', 'write', 'abilities:read', 'abilities'];
}

/**
 * Validate and normalize the scope string captured before consent.
 *
 * Every recognized request maps to the one full-access `mcp` grant.
 */
function normalize_authorization_scope(string $requested): ?string
{
    $requested = trim($requested);
    if ($requested === '') {
        return 'mcp';
    }
    $parts = preg_split('/\s+/', $requested);
    if ($parts === false || $parts === []) {
        return null;
    }
    $parts = array_values(array_unique($parts));
    foreach ($parts as $scope) {
        if (!in_array($scope, supported_scopes(), strict: true)) {
            return null;
        }
    }

    return 'mcp';
}

function boot(): void
{
    if (!\novamira_is_enabled()) {
        return;
    }

    // Never expose the OAuth flow over a public plain-HTTP site: authorization codes and bearer
    // tokens would travel in cleartext. Registering nothing here means there is no token,
    // authorize, or revoke endpoint to hit at all. HTTPS and local dev sites are allowed.
    if (!\novamira_oauth_transport_allowed()) {
        return;
    }

    require_once __DIR__ . '/schema.php';
    Schema\maybe_install();

    require_once __DIR__ . '/endpoints/discovery.php';
    Endpoints\Discovery\register();

    // Older MCP clients fall back to /authorize, /register and /token when authorization-server
    // metadata cannot be read. Serve those paths only when WordPress would otherwise return 404,
    // so existing pages and custom routes keep ownership of the generic slugs.
    require_once __DIR__ . '/endpoints/legacy-fallback.php';
    Endpoints\LegacyFallback\register();

    require_once __DIR__ . '/repositories/client-repository.php';
    require_once __DIR__ . '/repositories/PendingAuthorizationRepository.php';
    require_once __DIR__ . '/repositories/access-token-repository.php';
    require_once __DIR__ . '/repositories/auth-code-repository.php';
    require_once __DIR__ . '/repositories/refresh-token-repository.php';
    require_once __DIR__ . '/repositories/scope-repository.php';
    require_once __DIR__ . '/repositories/user-repository.php';
    require_once __DIR__ . '/repositories/device-code-repository.php';
    require_once __DIR__ . '/keys.php';
    // The device endpoint carries the shared code constants, so it is loaded before the factory
    // that reads the polling interval, not with the other REST routes below.
    require_once __DIR__ . '/endpoints/device.php';
    require_once __DIR__ . '/grants/device-code-grant.php';
    require_once __DIR__ . '/server-factory.php';
    require_once __DIR__ . '/bridge.php';

    require_once __DIR__ . '/endpoints/register.php';
    add_action('rest_api_init', __NAMESPACE__ . '\\Endpoints\\Register\\register');

    require_once __DIR__ . '/endpoints/authorize.php';
    // Repair a folded authorization URL now, on plugins_loaded, before admin.php resolves
    // $_GET['page'] and routes the request. See endpoints/authorize.php for the rationale.
    Endpoints\Authorize\repair_folded_request();
    // Registered as a hidden admin page (not a REST route) so the interactive login/consent
    // step authenticates with the wp-admin cookie, which works even where REST cookie auth
    // is disabled. See endpoints/authorize.php for the rationale.
    add_action('admin_menu', __NAMESPACE__ . '\\Endpoints\\Authorize\\register');

    require_once __DIR__ . '/endpoints/token.php';
    add_action('rest_api_init', __NAMESPACE__ . '\\Endpoints\\Token\\register');

    add_action('rest_api_init', __NAMESPACE__ . '\\Endpoints\\Device\\register');

    require_once __DIR__ . '/middleware.php';
    Middleware\register();

    require_once __DIR__ . '/endpoints/revoke.php';
    add_action('rest_api_init', __NAMESPACE__ . '\\Endpoints\\Revoke\\register');

    require_once __DIR__ . '/endpoints/introspect.php';
    add_action('rest_api_init', __NAMESPACE__ . '\\Endpoints\\Introspect\\register');

    require_once __DIR__ . '/consent.php';
    add_action('admin_menu', __NAMESPACE__ . '\\Consent\\register');

    // The device verification page reuses the consent disclosure, so it loads after consent.php.
    require_once __DIR__ . '/device-verification.php';
    add_action('admin_menu', __NAMESPACE__ . '\\DeviceVerification\\register');
}

add_action('plugins_loaded', __NAMESPACE__ . '\\boot', priority: 20);
