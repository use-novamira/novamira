<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\OAuth\Consent;

use League\OAuth2\Server\Exception\OAuthServerException;
use Novamira\OAuth\Bridge;
use Novamira\OAuth\Keys\KeyBootstrapError;
use Novamira\OAuth\Repositories\ClientRepository;
use Novamira\OAuth\Repositories\PendingAuthorizationRepository;
use Novamira\OAuth\Repositories\UserEntity;
use Novamira\OAuth\ServerFactory;

if (!defined('ABSPATH')) {
    exit();
}

function register(): void
{
    $hook = add_submenu_page(
        parent_slug: '',
        page_title: 'Authorize Application',
        menu_title: '',
        capability: \novamira_manage_capability(),
        menu_slug: 'novamira-oauth-consent',
        callback: __NAMESPACE__ . '\\render',
    );

    // Approve/Deny must redirect back to the client before any admin HTML is sent. The page
    // callback runs after the admin header (headers already flushed, so wp_redirect is a no-op
    // and the browser is left on a blank consent page), so the POST is handled on the load hook,
    // which fires before any output.
    if (is_string($hook) && $hook !== '') {
        add_action('load-' . $hook, __NAMESPACE__ . '\\handle_load');
    }
}

/**
 * Fires before the admin header. Handles the Approve/Deny POST (validate, then redirect to the
 * client); GET requests fall through untouched so the page callback can draw the form.
 */
function handle_load(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return;
    }
    $ctx = resolve_pending();
    if ($ctx === null) {
        return;
    }
    render_post($ctx['token'], $ctx['pending'], $ctx['redirect_uri'], $ctx['state']);
}

function render(): void
{
    $ctx = resolve_pending();
    if ($ctx === null) {
        return;
    }
    render_form($ctx['token'], $ctx['client_name'], $ctx['redirect_uri'], $ctx['scope']);
}

/**
 * Validate the request and load the pending authorization, shared by the load hook and the page
 * callback. On any failure it calls wp_die (which exits the request); the null return exists only
 * to satisfy the return type and is never reached at runtime.
 *
 * @return array{
 *     token: string,
 *     pending: array<array-key, mixed>,
 *     redirect_uri: string,
 *     state: string,
 *     client_name: string,
 *     scope: string,
 * }|null
 */
function resolve_pending(): ?array
{
    if (!is_user_logged_in()) {
        wp_die('You must be logged in.', title: '', args: ['response' => 403]);
        return null;
    }
    if (!\novamira_current_user_can_manage()) {
        wp_die('You are not allowed to authorize Novamira applications.', title: '', args: ['response' => 403]);
        return null;
    }

    $raw_token = $_GET['token'] ?? '';
    $token = is_string($raw_token) ? sanitize_text_field($raw_token) : '';
    if ($token === '') {
        wp_die('Missing consent token.', title: '', args: ['response' => 400]);
        return null;
    }

    $pending_authorizations = new PendingAuthorizationRepository();
    $pending = $pending_authorizations->find($token);
    if ($pending === null) {
        wp_die('Invalid or expired consent token.', title: '', args: ['response' => 400]);
        return null;
    }
    if ($pending_authorizations->is_expired($pending['expires_at'])) {
        $pending_authorizations->delete($token);
        wp_die('Invalid or expired consent token.', title: '', args: ['response' => 400]);
        return null;
    }

    $stored_user_id = $pending['user_id'];
    if ($stored_user_id !== get_current_user_id()) {
        wp_die('Session mismatch.', title: '', args: ['response' => 403]);
        return null;
    }

    $client_id = $pending['client_id'];
    $client = (new ClientRepository())->getClientEntity($client_id);
    if ($client === null) {
        $pending_authorizations->delete($token);
        wp_die('The application is no longer registered.', title: '', args: ['response' => 400]);
        return null;
    }

    return [
        'token' => $token,
        'pending' => $pending,
        'redirect_uri' => $pending['redirect_uri'],
        'state' => $pending['state'],
        'client_name' => $client->getName(),
        'scope' => $pending['scope'],
    ];
}

/** @param array<array-key, mixed> $pending */
function render_post(string $token, array $pending, string $redirect_uri, string $state): void
{
    check_admin_referer('novamira_oauth_consent_' . $token);

    $pending_authorizations = new PendingAuthorizationRepository();
    if (!$pending_authorizations->consume($token)) {
        wp_die('Invalid or expired consent token.', title: '', args: ['response' => 400]);
        return;
    }

    if (array_key_exists('deny', $_POST)) {
        wp_redirect(add_query_arg(['error' => 'access_denied', 'state' => $state], $redirect_uri));
        exit();
    }

    try {
        $code_challenge = (string) ($pending['code_challenge'] ?? '');
        $code_challenge_method = (string) ($pending['code_challenge_method'] ?? '');
        $scope = (string) ($pending['scope'] ?? 'mcp');
        $client_id = (string) ($pending['client_id'] ?? '');
        $user_id = (int) ($pending['user_id'] ?? 0);

        $server = ServerFactory\build_authorization_server();
        $fakeRequest = Bridge\psr7_from_globals()->withQueryParams([
            'response_type' => 'code',
            'client_id' => $client_id,
            'redirect_uri' => $redirect_uri,
            'code_challenge' => $code_challenge,
            'code_challenge_method' => $code_challenge_method,
            'scope' => $scope,
            'state' => $state,
        ]);
        $authRequest = $server->validateAuthorizationRequest($fakeRequest);

        $userEntity = new UserEntity();
        $userEntity->setIdentifier((string) $user_id);
        $authRequest->setUser($userEntity);
        $authRequest->setAuthorizationApproved(true);

        $psr7Response = $server->completeAuthorizationRequest($authRequest, Bridge\new_psr7_response());

        wp_redirect($psr7Response->getHeaderLine('Location'));
        exit();
    } catch (OAuthServerException $e) {
        wp_redirect(add_query_arg([
            'error' => $e->getErrorType(),
            'error_description' => $e->getMessage(),
            'state' => $state,
        ], $redirect_uri));
        exit();
    } catch (KeyBootstrapError $e) {
        // The generic message below would hide the one failure an operator can act on: this site
        // has no OAuth signing key and its PHP cannot make one. The reason (OpenSSL error strings
        // and configuration paths, never key material) goes to the PHP error log.
        error_log('Novamira OAuth: ' . $e->getMessage());
        wp_die(
            esc_html__(
                'Novamira could not create the OAuth signing keys for this site. The PHP error log records the OpenSSL reason. Run "wp novamira oauth-keys generate" from WP-CLI to create the keys, then authorize again.',
                domain: 'novamira',
            ),
            title: '',
            args: ['response' => 500],
        );
    } catch (\Throwable $e) {
        wp_die('An error occurred during authorization. Please try again.', title: '', args: ['response' => 500]);
    }
}

function render_form(string $token, string $client_name, string $redirect_uri, string $scope): void
{
    $redirect_destination = redirect_destination_label($redirect_uri);
    $grant = consent_grant_details($scope);

    \novamira_render_admin_header();
    echo '<div class="wrap">';
    echo '<h1>' . esc_html__('Authorize Application', domain: 'novamira') . '</h1>';
    echo
        sprintf(
            '<p>' . esc_html__('%1$s is requesting %2$s.', domain: 'novamira') . '</p>',
            '<strong>' . esc_html($client_name) . '</strong>',
            '<strong>' . esc_html($grant['label']) . '</strong>',
        )
    ;
    echo '<p>' . esc_html($grant['description']) . '</p>';
    echo
        '<p><strong>'
            . esc_html__('Requested OAuth scope:', domain: 'novamira')
            . '</strong> <code>'
            . esc_html($scope)
            . '</code></p>'
    ;
    if ($grant['risks'] !== []) {
        echo '<p><strong>' . esc_html__('This grant can:', domain: 'novamira') . '</strong></p><ul>';
        foreach ($grant['risks'] as $risk) {
            echo '<li>' . esc_html($risk) . '</li>';
        }
        echo '</ul>';
    }
    echo
        '<p><strong>'
            . esc_html__('Redirect destination:', domain: 'novamira')
            . '</strong> '
            . esc_html($redirect_destination)
            . '</p>'
    ;
    echo
        '<p class="description">'
            . esc_html__(
                'Only authorize applications you trust. The application name is provided by the connecting client.',
                domain: 'novamira',
            )
            . '</p>'
    ;
    echo '<form method="post">';
    wp_nonce_field('novamira_oauth_consent_' . $token);
    echo
        '<button type="submit" name="approve" value="1" class="button button-primary">'
            . esc_html__('Authorize', domain: 'novamira')
            . '</button> '
    ;
    echo
        '<button type="submit" name="deny" value="1" class="button">'
            . esc_html__('Deny', domain: 'novamira')
            . '</button>'
    ;
    echo '</form>';
    echo '</div>';
}

/**
 * @return array{label: string, description: string, risks: list<string>}
 */
function consent_grant_details(string $scope): array
{
    return [
        'label' => __('full access to your WordPress site', domain: 'novamira'),
        'description' => __(
            'Full access permits execution of Novamira capabilities through MCP and REST, including REST-visible abilities registered by compatible third-party plugins.',
            domain: 'novamira',
        ),
        'risks' => [
            __('Execute PHP and WP-CLI.', domain: 'novamira'),
            __('Read, write, and delete server files.', domain: 'novamira'),
            __('Change WordPress content and settings.', domain: 'novamira'),
            __('Create temporary administrator access.', domain: 'novamira'),
            __('Execute REST-visible abilities registered by compatible plugins.', domain: 'novamira'),
        ],
    ];
}

function redirect_destination_label(string $redirect_uri): string
{
    $parsed = parse_url($redirect_uri);
    if (!is_array($parsed)) {
        return $redirect_uri;
    }

    $scheme = strtolower($parsed['scheme'] ?? '');
    $host = strtolower($parsed['host'] ?? '');
    if ($host === '') {
        return $scheme !== '' ? $scheme . ':' : $redirect_uri;
    }

    return $scheme . '://' . $host;
}
