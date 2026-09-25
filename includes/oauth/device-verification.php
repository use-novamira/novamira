<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\OAuth\DeviceVerification;

use Novamira\OAuth\Consent;
use Novamira\OAuth\Endpoints\Device;
use Novamira\OAuth\Repositories\ClientRepository;
use Novamira\OAuth\Repositories\DeviceCodeRepository;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The RFC 8628 verification page: where a device authorization actually becomes a grant.
 *
 * Like the consent screen it runs as a hidden wp-admin page rather than a REST route, so it
 * authenticates with the admin cookie and works on sites where REST cookie authentication is
 * disabled. Two steps, both on this page: type the code, then approve or deny it against the same
 * full-access disclosure the browser flow shows.
 *
 * The device flow's own weakness is that the code can arrive from somewhere else — someone talked
 * into typing a code they did not generate is approving an attacker's session. That is why there is
 * no one-click completion link, why a code is accepted only from this page's own manual-entry POST,
 * why the page says where the code must have come from, and why the decision screen names the
 * application and the access it is about to receive.
 */
function register(): void
{
    $hook = \novamira_add_hidden_admin_page('Authorize Device', Device\PAGE_SLUG, __NAMESPACE__ . '\\render');

    // Approve/Deny redirects, so it must run before the admin header is sent. See consent.php.
    if (is_string($hook) && $hook !== '') {
        add_action('load-' . $hook, __NAMESPACE__ . '\\handle_load');
    }
}

function handle_load(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return;
    }
    if (!is_user_logged_in() || !\novamira_current_user_can_manage()) {
        return;
    }
    $action = $_POST['novamira_action'] ?? '';
    if ($action !== 'decide') {
        return;
    }
    check_admin_referer('novamira_oauth_device_decide');
    handle_decision(get_current_user_id());
}

/**
 * Records the operator's decision. The user code is re-read from the POST rather than trusted from
 * a hidden pending-state token, so the decision applies to exactly the authorization that was shown.
 */
function handle_decision(int $user_id): void
{
    $user_code = Device\normalize_user_code(read_post('user_code'));
    $device_codes = new DeviceCodeRepository();
    $authorization = $user_code === '' ? null : $device_codes->find_by_user_code($user_code);
    if ($authorization === null || $device_codes->is_expired($authorization['expires_at'])) {
        redirect(['device_result' => 'unknown']);
        return;
    }
    if ($authorization['status'] !== DeviceCodeRepository::STATUS_PENDING) {
        redirect(['device_result' => 'settled']);
        return;
    }

    $approved = array_key_exists('approve', $_POST);
    $device_codes->decide($authorization['device_code_hash'], $approved, $user_id);
    redirect(['device_result' => $approved ? 'approved' : 'denied']);
}

function render(): void
{
    if (!is_user_logged_in()) {
        wp_die('You must be logged in.', title: '', args: ['response' => 403]);
        return;
    }
    if (!\novamira_current_user_can_manage()) {
        wp_die('You are not allowed to authorize Novamira applications.', title: '', args: ['response' => 403]);
        return;
    }

    // Resolved before any output, so a stale or forged nonce ends the request instead of dying
    // halfway through a rendered page.
    $user_code = submitted_user_code();

    \novamira_render_admin_header();
    echo '<div class="wrap">';
    echo '<h1>' . esc_html__('Authorize Device', domain: 'novamira') . '</h1>';
    render_notice();

    $device_codes = new DeviceCodeRepository();
    $authorization = $user_code === '' ? null : $device_codes->find_by_user_code($user_code);
    if ($authorization === null || $device_codes->is_expired($authorization['expires_at'])) {
        render_code_form($user_code !== '');
        echo '</div>';
        return;
    }
    if ($authorization['status'] !== DeviceCodeRepository::STATUS_PENDING) {
        echo
            '<p>'
                . esc_html__(
                    'That code has already been used. Start the login again if you still need it.',
                    domain: 'novamira',
                )
                . '</p>'
        ;
        render_code_form(false);
        echo '</div>';
        return;
    }

    $client = (new ClientRepository())->getClientEntity($authorization['client_id']);
    render_decision_form($user_code, $client === null ? '' : $client->getName(), $authorization['scopes']);
    echo '</div>';
}

/**
 * The code is read only from this page's own manual-entry POST.
 *
 * RFC 8628 rests on the operator having typed a code they were just shown on the device they
 * started — that comparison is the flow's whole defence against approving a stranger's session. A
 * code accepted from the query string erases it: a prefilled link drops the manager straight onto
 * the decision screen for an authorization they never began, and social engineering does the rest.
 * So the code must arrive by POST carrying a nonce, which WordPress binds to this user's login
 * session — the same thing that binds the resolved authorization to the browser about to decide it.
 */
function submitted_user_code(): string
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return '';
    }
    if (read_post('novamira_action') !== 'lookup') {
        return '';
    }
    check_admin_referer('novamira_oauth_device_lookup');
    return Device\normalize_user_code(read_post('user_code'));
}

/** Step one: enter the code the command line printed. */
// @mago-expect lint:no-boolean-flag-parameter
function render_code_form(bool $was_rejected): void
{
    if ($was_rejected) {
        echo
            '<div class="notice notice-error"><p>'
                . esc_html__('That code is not valid or has expired.', domain: 'novamira')
                . '</p></div>'
        ;
    }
    echo
        '<p>'
            . esc_html__(
                'Enter the code shown by the application that is waiting to connect. Only enter a code you started yourself — a code someone else sent you would connect their session to this site.',
                domain: 'novamira',
            )
            . '</p>'
    ;
    echo '<form method="post" action="' . esc_url(page_url()) . '">';
    wp_nonce_field('novamira_oauth_device_lookup');
    echo '<input type="hidden" name="novamira_action" value="lookup">';
    echo
        '<p><input type="text" name="user_code" size="20" autocomplete="off" spellcheck="false" placeholder="'
            . esc_attr__('XXXX-XXXX', domain: 'novamira')
            . '"></p>'
    ;
    echo
        '<button type="submit" class="button button-primary">'
            . esc_html__('Continue', domain: 'novamira')
            . '</button>'
    ;
    echo '</form>';
}

/** Step two: the same full-access disclosure the browser consent screen shows. */
function render_decision_form(string $user_code, string $client_name, string $scope): void
{
    $grant = Consent\consent_grant_details($scope);
    $name = $client_name === '' ? __('An application', domain: 'novamira') : $client_name;

    echo
        sprintf(
            '<p>' . esc_html__('%1$s is requesting %2$s.', domain: 'novamira') . '</p>',
            '<strong>' . esc_html($name) . '</strong>',
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
        '<p class="description">'
            . esc_html__(
                'Authorize only if you started this login yourself, on a device you control. The application name is provided by the connecting client.',
                domain: 'novamira',
            )
            . '</p>'
    ;

    echo '<form method="post" action="' . esc_url(page_url()) . '">';
    wp_nonce_field('novamira_oauth_device_decide');
    echo '<input type="hidden" name="novamira_action" value="decide">';
    echo '<input type="hidden" name="user_code" value="' . esc_attr($user_code) . '">';
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
}

function render_notice(): void
{
    $result = read_get('device_result');
    $messages = [
        'approved' => __('Device authorized. You can return to the application.', domain: 'novamira'),
        'denied' => __('Device authorization denied.', domain: 'novamira'),
        'settled' => __('That code was already used.', domain: 'novamira'),
        'unknown' => __('That code is not valid or has expired.', domain: 'novamira'),
    ];
    if (!array_key_exists($result, $messages)) {
        return;
    }
    $class = $result === 'approved' ? 'notice-success' : 'notice-warning';
    echo '<div class="notice ' . esc_attr($class) . '"><p>' . esc_html($messages[$result]) . '</p></div>';
}

/** Both forms post here rather than to the current URL, so a stale `device_result` is left behind. */
function page_url(): string
{
    return admin_url('admin.php?page=' . Device\PAGE_SLUG);
}

/** @param array<string, string> $args */
function redirect(array $args): void
{
    wp_safe_redirect(add_query_arg($args, page_url()));
    exit();
}

function read_post(string $key): string
{
    // Every value this returns is either the action name used to pick which nonce to check, or is
    // read after that nonce has been verified in handle_load() or submitted_user_code().
    // phpcs:ignore WordPress.Security.NonceVerification.Missing
    $raw = $_POST[$key] ?? '';
    return is_string($raw) ? sanitize_text_field($raw) : '';
}

/**
 * Only the post-redirect result banner is read from the query string. The user code never is — see
 * submitted_user_code().
 */
function read_get(string $key): string
{
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $raw = $_GET[$key] ?? '';
    return is_string($raw) ? sanitize_text_field($raw) : '';
}
