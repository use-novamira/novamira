<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Troubleshoot\Notice;

if (!defined('ABSPATH')) {
    exit();
}

const DISMISS_META = 'novamira_troubleshoot_dismissed';

/**
 * Regressions worth interrupting an admin for: conditions that (a) certainly break connections
 * that were working, and (b) cost nothing to detect (no HTTP, one small indexed query at most).
 * Anything fuzzier belongs in the on-demand troubleshooter, not a nag.
 *
 * @return list<string> Human-readable regression descriptions; empty when all is well.
 */
function regressions(): array
{
    $found = [];

    // Application Passwords turned off while Novamira passwords have actually been used.
    $status = \novamira_app_passwords_status();
    if (!$status['available'] && $status['reason'] === 'filtered') {
        foreach (\novamira_get_mcp_passwords() as $password) {
            if (($password['last_used'] ?? null) === null) {
                continue;
            }
            $found[] = __(
                'Application Passwords have been disabled (likely by a security plugin), so AI clients connected with the password method cannot authenticate anymore.',
                domain: 'novamira',
            );
            break;
        }
    }

    // Site dropped to plain HTTP while OAuth connections are active: the endpoints are gone.
    if (!\novamira_oauth_transport_allowed() && \Novamira\OAuth\ClientValidation\active_client_count() > 0) {
        $found[] = __(
            'This site is no longer served over HTTPS, so the OAuth endpoints are disabled and connected AI clients cannot authenticate anymore.',
            domain: 'novamira',
        );
    }

    return $found;
}

function maybe_render(): void
{
    if (!\novamira_current_user_can_manage()) {
        return;
    }
    $found = regressions();
    if ($found === []) {
        return;
    }
    $hash = md5((string) wp_json_encode($found));
    if (get_user_meta(get_current_user_id(), DISMISS_META, single: true) === $hash) {
        return;
    }
    $dismiss_url = wp_nonce_url(
        add_query_arg('novamira_troubleshoot_dismiss', $hash),
        action: 'novamira_troubleshoot_dismiss',
    );
    $troubleshoot_url = admin_url('admin.php?page=novamira-troubleshoot');
    echo
        '<div class="notice notice-error"><p><strong>'
            . esc_html__('Novamira connections are broken.', domain: 'novamira')
            . '</strong></p>'
    ;
    foreach ($found as $message) {
        echo '<p>' . esc_html($message) . '</p>';
    }
    echo
        '<p><a href="'
            . esc_url($troubleshoot_url)
            . '">'
            . esc_html__('Troubleshoot', domain: 'novamira')
            . '</a> · <a href="'
            . esc_url($dismiss_url)
            . '">'
            . esc_html__('Dismiss', domain: 'novamira')
            . '</a></p></div>'
    ;
}

/**
 * Persist the dismissal keyed to the exact regression set, so the notice stays gone for this
 * state but re-arms the moment a different regression appears.
 */
function handle_dismiss(): void
{
    $raw = $_GET['novamira_troubleshoot_dismiss'] ?? null;
    if (!is_string($raw) || $raw === '') {
        return;
    }
    check_admin_referer('novamira_troubleshoot_dismiss');
    if (!\novamira_current_user_can_manage()) {
        return;
    }
    update_user_meta(get_current_user_id(), DISMISS_META, sanitize_key($raw));
    wp_safe_redirect(remove_query_arg(['novamira_troubleshoot_dismiss', '_wpnonce']));
    exit();
}
