<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\OAuth\Endpoints\Discovery;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The well-known base segments shared by every discovery URL. Kept here as the single source so the
 * handler, the WWW-Authenticate challenge (middleware.php) and the troubleshooter probes (checks.php)
 * can never drift onto different paths.
 */
const PROTECTED_RESOURCE = '/.well-known/oauth-protected-resource';

const AUTHORIZATION_SERVER = '/.well-known/oauth-authorization-server';

const OPENID_CONFIGURATION = '/.well-known/openid-configuration';

function register(): void
{
    add_action('init', __NAMESPACE__ . '\\maybe_serve', priority: 1);
}

/**
 * Absolute URL of the protected-resource metadata document, as advertised in the WWW-Authenticate
 * challenge. It is the append form under the site's own path, so this install serves it directly.
 */
function protected_resource_metadata_url(): string
{
    return home_url(PROTECTED_RESOURCE);
}

/**
 * The exact request paths each discovery document answers on, derived from the site's home URL
 * and the protected resource identifier.
 *
 * When WordPress lives under a path (subdirectory install), `home_url()` carries that path, so the
 * root-only paths the previous handler matched never lined up with what the site advertised. Rather
 * than accept any suffix — which would answer for unrelated resources on the same host — every
 * documented client form is computed and matched exactly.
 *
 * Protected-resource metadata (RFC 9728):
 *  - append form `{home}/.well-known/oauth-protected-resource` — the URL advertised in the
 *    WWW-Authenticate challenge; served by this install, so no root bridge is needed.
 *  - insert form `/.well-known/oauth-protected-resource{resource path}` — the canonical RFC 9728
 *    construction current Claude connectors use; only reaches this install when it owns the domain
 *    root.
 *
 * Authorization-server metadata (RFC 8414 / OpenID Connect Discovery):
 *  - OIDC append form `{home}/.well-known/openid-configuration` — served by this install; lets a
 *    spec-compliant client finish discovery without a root bridge when WordPress is in a
 *    subdirectory.
 *  - append form `{home}/.well-known/oauth-authorization-server` — tolerance for clients that
 *    append instead of insert.
 *  - insert forms `/.well-known/oauth-authorization-server{issuer path}` and
 *    `/.well-known/openid-configuration{issuer path}` — the RFC 8414 constructions; only reach this
 *    install when it owns the domain root, otherwise a root-level bridge must serve them.
 *
 * @return array{protected_resource: list<string>, authorization_server: list<string>}
 */
function discovery_paths(string $home_url, string $resource): array
{
    $home_path = rtrim((string) parse_url($home_url, PHP_URL_PATH), characters: '/');
    $resource_path = (string) parse_url($resource, PHP_URL_PATH);
    $map = discovery_url_paths($home_path, $resource_path);

    return [
        'protected_resource' => array_values(array_unique([$map['pr_append'], $map['pr_insert']])),
        'authorization_server' => array_values(array_unique([
            $map['as_oidc_append'],
            $map['as_oauth_append'],
            $map['as_oauth_insert'],
            $map['as_oidc_insert'],
        ])),
    ];
}

/**
 * Every discovery URL path this server recognizes, keyed by role, composed once from the site's
 * home path and resource path. The request handler (discovery_paths) and the troubleshooter
 * (discovery_probes) both build on this, so a path can never be served on one side and probed on
 * the other in a different shape.
 *
 * `pr_` = protected-resource (RFC 9728), `as_` = authorization-server (RFC 8414 / OIDC). `append`
 * forms sit under the site's own path; `insert` forms sit at the domain root.
 *
 * @return array{pr_append: string, pr_insert: string, as_oidc_append: string, as_oauth_append: string, as_oauth_insert: string, as_oidc_insert: string}
 */
function discovery_url_paths(string $home_path, string $resource_path): array
{
    return [
        'pr_append' => $home_path . PROTECTED_RESOURCE,
        'pr_insert' => PROTECTED_RESOURCE . $resource_path,
        'as_oidc_append' => $home_path . OPENID_CONFIGURATION,
        'as_oauth_append' => $home_path . AUTHORIZATION_SERVER,
        'as_oauth_insert' => AUTHORIZATION_SERVER . $home_path,
        'as_oidc_insert' => OPENID_CONFIGURATION . $home_path,
    ];
}

/**
 * The absolute discovery URLs the troubleshooter probes, in client-attempt order, each tagged with
 * the metadata field that proves the document is ours (and its exact expected value) and whether a
 * miss is a real failure.
 *
 * URLs are built from the site origin (`scheme://host[:port]`) plus the computed path, never
 * `home_url($path)`: passing a root path such as `/.well-known/...` to `home_url()` would prepend the
 * subdirectory again and probe a doubled `/subsite/.well-known/...` URL that nothing serves.
 *
 * Each probe carries a `requirement`:
 *  - `required` — this exact URL must answer, or discovery fails. Only the advertised
 *    protected-resource append form is required: it is the URL the WWW-Authenticate challenge names.
 *  - `any` — one of a `group` must answer. The authorization-server document is reachable through
 *    several interchangeable forms (OIDC append, the non-standard OAuth append, and the root insert
 *    forms); a compliant client succeeds as soon as one works, so the group fails only when they all
 *    do. This is why a blocked OAuth-append form is not a failure when the OIDC append answers.
 *  - `optional` — informational; a miss never fails discovery (e.g. the protected-resource insert
 *    form, which on a subdirectory install lands on the domain root this install does not own).
 *
 * URLs are built from the site origin plus the computed path, never home_url($path).
 *
 * @return list<array{url: truthy-string, field: string, expected: string, requirement: string, group: string, label: string}>
 */
function discovery_probes(string $home_url, string $resource): array
{
    $parts = parse_url($home_url);
    $port = $parts['port'] ?? null;
    $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . ($port !== null ? ':' . $port : '');
    $home_path = rtrim((string) parse_url($home_url, PHP_URL_PATH), characters: '/');
    $resource_path = (string) parse_url($resource, PHP_URL_PATH);
    $map = discovery_url_paths($home_path, $resource_path);

    $as = 'authorization_server';
    $candidates = [
        [$origin . $map['pr_append'], 'resource', $resource, 'required', '', 'protected-resource (append)'],
        [$origin . $map['pr_insert'], 'resource', $resource, 'optional', '', 'protected-resource (insert)'],
        [$origin . $map['as_oidc_append'], 'issuer', $home_url, 'any', $as, 'authorization-server (OIDC append)'],
        [$origin . $map['as_oauth_append'], 'issuer', $home_url, 'any', $as, 'authorization-server (OAuth append)'],
        [$origin . $map['as_oauth_insert'], 'issuer', $home_url, 'any', $as, 'authorization-server (RFC 8414 insert)'],
        [$origin . $map['as_oidc_insert'], 'issuer', $home_url, 'any', $as, 'authorization-server (OIDC insert)'],
    ];

    // Root installs collapse append and insert onto the same URL; keep the first-seen probe. No
    // requirement conflict arises, since collapses only happen within the same authorization-server
    // `any` group.
    $probes = [];
    $seen = [];
    foreach ($candidates as [$url, $field, $expected, $requirement, $group, $label]) {
        if (array_key_exists($url, $seen)) {
            continue;
        }
        $seen[$url] = true;
        $probes[] = [
            'url' => $url,
            'field' => $field,
            'expected' => $expected,
            'requirement' => $requirement,
            'group' => $group,
            'label' => $label,
        ];
    }

    return $probes;
}

/**
 * @return array<string, mixed>
 */
function protected_resource_document(): array
{
    return [
        'resource' => \Novamira\OAuth\resource_identifier(),
        'authorization_servers' => [home_url()],
        'bearer_methods_supported' => ['header'],
        'scopes_supported' => ['mcp'],
        'novamira' => \novamira_server_compatibility(),
    ];
}

/**
 * @return array<string, mixed>
 */
function authorization_server_document(): array
{
    $base = rest_url('novamira/v1/oauth');

    // The authorization endpoint runs in the wp-admin cookie context (see endpoints/authorize.php)
    // rather than under /wp-json, so it keeps working on sites where REST cookie authentication is
    // disabled. The remaining endpoints stay on REST.
    return [
        'issuer' => home_url(),
        'authorization_endpoint' => admin_url('admin.php?page=novamira-oauth-authorize'),
        'token_endpoint' => $base . '/token',
        'registration_endpoint' => $base . '/register',
        'revocation_endpoint' => $base . '/revoke',
        'introspection_endpoint' => $base . '/introspect',
        'device_authorization_endpoint' => $base . '/device',
        'response_types_supported' => ['code'],
        'grant_types_supported' => ['authorization_code', 'refresh_token', \Novamira\OAuth\DEVICE_CODE_GRANT_TYPE],
        'code_challenge_methods_supported' => ['S256'],
        'token_endpoint_auth_methods_supported' => ['none'],
        'scopes_supported' => ['mcp'],
    ];
}

function serve(array $document): void
{
    header('Content-Type: application/json; charset=UTF-8');
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo wp_json_encode($document);
    exit();
}

/**
 * Which discovery document a request path asks for, or null when it is not a discovery path.
 *
 * A single trailing slash is ignored. Hosts that enforce trailing slashes redirect these URLs before
 * WordPress runs, so the client lands on the slashed form and would otherwise get a 404 page. The
 * match stays exact apart from that: any other suffix is still not ours.
 *
 * @param array{protected_resource: list<string>, authorization_server: list<string>} $paths
 * @return 'protected_resource'|'authorization_server'|null
 */
function document_for_path(string $path, array $paths): ?string
{
    if (str_ends_with($path, '/')) {
        $path = substr($path, offset: 0, length: -1);
    }
    if (in_array($path, $paths['protected_resource'], strict: true)) {
        return 'protected_resource';
    }
    if (in_array($path, $paths['authorization_server'], strict: true)) {
        return 'authorization_server';
    }
    return null;
}

function maybe_serve(): void
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if (!is_string($path)) {
        return;
    }
    $document = document_for_path($path, discovery_paths(home_url(), \Novamira\OAuth\resource_identifier()));
    if ($document === 'protected_resource') {
        serve(protected_resource_document());
    }
    if ($document === 'authorization_server') {
        serve(authorization_server_document());
    }
}
