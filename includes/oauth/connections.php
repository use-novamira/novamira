<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\OAuth\Connections;

if (!defined('ABSPATH')) {
    exit();
}

const PAGE_SLUG = 'novamira-connections';

function page_url(string $fragment = ''): string
{
    $url = admin_url('admin.php?page=' . PAGE_SLUG);
    return $fragment !== '' ? $url . '#' . $fragment : $url;
}

function oauth_storage_available(): bool
{
    return get_option(option: 'novamira_oauth_schema_version', default_value: false) !== false;
}

function load_client_repository(): bool
{
    if (
        !oauth_storage_available()
        || !interface_exists(\League\OAuth2\Server\Repositories\ClientRepositoryInterface::class)
    ) {
        return false;
    }
    require_once __DIR__ . '/repositories/client-repository.php';
    return class_exists(\Novamira\OAuth\Repositories\ClientRepository::class);
}

function format_utc_datetime(string $value): string
{
    $timestamp = strtotime($value . ' UTC');
    if ($timestamp === false) {
        return $value;
    }
    $formatted = wp_date(\novamira_get_datetime_format('Y-m-d H:i'), $timestamp);
    return $formatted !== false ? $formatted : $value;
}

function register(): void
{
    $hook = \novamira_add_hidden_admin_page(
        __('Manage Connections', domain: 'novamira'),
        PAGE_SLUG,
        __NAMESPACE__ . '\\render',
    );

    // The Revoke POST must redirect back before any admin HTML is sent. The page callback runs
    // after the admin header (headers already flushed, so wp_redirect is a no-op and the browser is
    // left on a blank page), so the POST is handled on the load hook, which fires before any output.
    if (is_string($hook) && $hook !== '') {
        add_action('load-' . $hook, __NAMESPACE__ . '\\handle_load');
    }
}

/**
 * Fires before the admin header. Handles the Revoke POST (nonce check, revoke, redirect); GET
 * requests fall through untouched so the page callback can draw the list.
 */
function handle_load(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return;
    }
    if (!is_user_logged_in() || !\novamira_current_user_can_manage()) {
        return;
    }
    if (($_POST['novamira_revoke_password'] ?? null) !== null) {
        \novamira_handle_revoke_password(PAGE_SLUG);
        return;
    }
    handle_post(get_current_user_id());
}

function render(): void
{
    if (!is_user_logged_in()) {
        wp_die('You must be logged in.', title: '', args: ['response' => 403]);
        return;
    }
    if (!\novamira_current_user_can_manage()) {
        wp_die('You are not allowed to manage Novamira connections.', title: '', args: ['response' => 403]);
        return;
    }

    render_page(get_current_user_id());
}

function handle_post(int $user_id): void
{
    $action = $_POST['novamira_action'] ?? '';
    if ($action === 'delete_admin_client') {
        check_admin_referer('novamira_connected_apps_delete');
        $raw = $_POST['client_id'] ?? null;
        $client_id = is_string($raw) ? sanitize_key($raw) : '';
        if ($client_id !== '') {
            // Belt and braces: revoke any tokens the row may have picked up, then drop it.
            revoke_client_access($client_id, $user_id);
            if (load_client_repository()) {
                (new \Novamira\OAuth\Repositories\ClientRepository())->revoke($client_id);
            }
        }
        wp_redirect(add_query_arg(['deleted' => '1'], page_url()));
        exit();
    }

    check_admin_referer('novamira_connected_apps_revoke');

    $raw = $_POST['client_id'] ?? null;
    $client_id = is_string($raw) ? sanitize_key($raw) : '';
    if ($client_id !== '') {
        revoke_client_access($client_id, $user_id);
        if (load_client_repository()) {
            (new \Novamira\OAuth\Repositories\ClientRepository())->delete_if_unused($client_id);
        }
    }

    wp_redirect(add_query_arg(['revoked' => '1'], page_url()));
    exit();
}

function revoke_client_access(string $client_id, int $user_id): void
{
    if (!oauth_storage_available()) {
        return;
    }
    // @mago-expect lint:no-global
    global $wpdb;
    /** @var \wpdb $wpdb */
    $t = $wpdb->prefix . 'novamira_oauth_access_tokens';
    $r = $wpdb->prefix . 'novamira_oauth_refresh_tokens';

    // Revoke refresh tokens linked to this client's access tokens.
    // @mago-expect analysis:possibly-invalid-argument
    // @mago-expect analysis:possibly-invalid-argument
    $wpdb->query($wpdb->prepare(
        "UPDATE `{$r}` rt
         JOIN `{$t}` at ON at.identifier_hash = rt.access_token_hash
         SET rt.revoked = 1
         WHERE at.client_id = %s AND at.user_id = %d",
        $client_id,
        $user_id,
    ));

    // Revoke all access tokens for this client and user.
    $wpdb->update($t, ['revoked' => 1], ['client_id' => $client_id, 'user_id' => $user_id]);
}

// @mago-expect lint:halstead
function render_page(int $user_id): void
{
    // @mago-expect lint:no-global
    global $wpdb;
    /** @var \wpdb $wpdb */
    $apps = [];
    if (oauth_storage_available()) {
        $t = $wpdb->prefix . 'novamira_oauth_access_tokens';
        $r = $wpdb->prefix . 'novamira_oauth_refresh_tokens';
        $c = $wpdb->prefix . 'novamira_oauth_clients';
        $now = gmdate('Y-m-d H:i:s');

        // Key the list off refresh tokens, not access tokens. Access tokens live one hour, so
        // basing the list on them would drop a still-connected app from the view an hour after
        // its last use and show a misleading one-hour expiry. The refresh token (renewed on
        // each use) is the real connection lifetime, so its expiry is what we surface.
        // @mago-expect analysis:possibly-invalid-argument
        // @mago-expect analysis:non-existent-constant
        // @mago-expect analysis:mixed-argument
        // @mago-expect analysis:possibly-invalid-argument
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT c.client_name, at.client_id, MAX(rt.expires_at) AS expires_at
         FROM `{$r}` rt
         JOIN `{$t}` at ON at.identifier_hash = rt.access_token_hash
         JOIN `{$c}` c ON c.client_id = at.client_id
         WHERE at.user_id = %d AND rt.revoked = 0 AND rt.expires_at > %s
         GROUP BY at.client_id, c.client_name
         ORDER BY expires_at DESC",
                $user_id,
                $now,
            ),
            ARRAY_A,
        );

        $apps = is_array($rows) ? $rows : [];
    }

    $raw_revoked = $_GET['revoked'] ?? null;
    $was_revoked = is_string($raw_revoked) && $raw_revoked === '1';
    $raw_password_result = $_GET['novamira_result'] ?? null;
    $password_was_revoked = is_string($raw_password_result) && $raw_password_result === 'revoked';

    \novamira_render_admin_header();
    echo '<style>
        .novamira-connections-table { table-layout:fixed; }
        .novamira-connections-table .novamira-primary-column { width:50%; }
        .novamira-connections-table .novamira-action-column { width:6%; }
        .novamira-connected-apps-table .novamira-date-column { width:44%; }
        .novamira-passwords-table .novamira-date-column { width:22%; }
        .novamira-admin-clients-table .novamira-primary-column,
        .novamira-admin-clients-table .novamira-client-id-column { width:28%; }
        .novamira-admin-clients-table .novamira-date-column { width:19%; }
        .novamira-connections-table th:last-child,
        .novamira-connections-table td:last-child { text-align:center; }
        .novamira-connections-table td { vertical-align:middle; }
        @media screen and (max-width:782px) {
            .novamira-connections-table { table-layout:auto; }
            .novamira-connections-table .novamira-primary-column,
            .novamira-connections-table .novamira-client-id-column,
            .novamira-connections-table .novamira-date-column,
            .novamira-connections-table .novamira-action-column { width:auto; }
        }
    </style>';
    echo '<div class="wrap">';
    echo '<h1 class="wp-heading-inline">' . esc_html__('Manage Connections', domain: 'novamira') . '</h1>';
    echo
        '<a class="page-title-action" style="margin-left:12px;" href="'
            . esc_url(admin_url('admin.php?page=novamira-connect'))
            . '">'
            . esc_html__('Back to configuration', domain: 'novamira')
            . '</a>'
    ;
    echo '<hr class="wp-header-end">';
    echo
        '<p class="description" style="margin:12px 0 20px;">'
            . esc_html__(
                'The connected apps and application passwords shown here belong to your current WordPress user account.',
                domain: 'novamira',
            )
            . '</p>'
    ;

    if ($password_was_revoked) {
        echo
            '<div class="notice notice-success is-dismissible"><p>'
                . esc_html__('Application password revoked.', domain: 'novamira')
                . '</p></div>'
        ;
    }

    echo '<section id="connected-apps">';
    echo '<h2>' . esc_html__('Connected apps', domain: 'novamira') . '</h2>';
    echo
        '<p>'
            . esc_html__(
                'These apps are connected to your current WordPress user account through Novamira. Each connection renews automatically whenever the app reconnects. The date below is when access expires only if the app is not used again.',
                domain: 'novamira',
            )
            . '</p>'
    ;

    if ($was_revoked) {
        echo
            '<div class="notice notice-success is-dismissible"><p>'
                . esc_html__('Access revoked successfully.', domain: 'novamira')
                . '</p></div>'
        ;
    }

    if ($apps === []) {
        echo '<p>' . esc_html__('No apps are currently connected to your account.', domain: 'novamira') . '</p>';
    }
    if ($apps !== []) {
        echo
            '<table class="wp-list-table widefat fixed striped novamira-connections-table novamira-connected-apps-table">'
        ;
        echo '<colgroup>';
        echo '<col class="novamira-primary-column">';
        echo '<col class="novamira-date-column">';
        echo '<col class="novamira-action-column">';
        echo '</colgroup>';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Application', domain: 'novamira') . '</th>';
        echo '<th>' . esc_html__('Expires if unused', domain: 'novamira') . '</th>';
        echo '<th>' . esc_html__('Actions', domain: 'novamira') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($apps as $app) {
            // @mago-expect analysis:invalid-array-access
            $name = (string) $app['client_name'];
            // @mago-expect analysis:invalid-array-access
            $cid = (string) $app['client_id'];
            // @mago-expect analysis:invalid-array-access
            $expires = format_utc_datetime((string) $app['expires_at']);

            echo '<tr>';
            echo '<td><strong>' . esc_html($name) . '</strong></td>';
            echo '<td>' . esc_html($expires) . '</td>';
            echo '<td>';
            echo '<form method="post" style="margin:0;">';
            wp_nonce_field('novamira_connected_apps_revoke');
            echo '<input type="hidden" name="client_id" value="' . esc_attr($cid) . '">';
            echo
                '<button type="submit" class="button button-small">'
                    . esc_html__('Revoke', domain: 'novamira')
                    . '</button>'
            ;
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    render_admin_clients_section();
    echo '</section>';
    render_application_passwords_section();
    echo '</div>';
}

/**
 * List client IDs minted from the troubleshooter. They are exempt from the pending cleanup, so
 * this table is where an admin sees and deletes the ones that were never used.
 */
function render_admin_clients_section(): void
{
    if (!load_client_repository()) {
        return;
    }
    $clients = (new \Novamira\OAuth\Repositories\ClientRepository())->list_admin_clients();

    $raw_deleted = $_GET['deleted'] ?? null;
    if (is_string($raw_deleted) && $raw_deleted === '1') {
        echo
            '<div class="notice notice-success is-dismissible"><p>'
                . esc_html__('Client ID deleted.', domain: 'novamira')
                . '</p></div>'
        ;
    }

    if ($clients === []) {
        return;
    }

    echo '<h2 style="margin-top:24px;">' . esc_html__('Manually created client IDs', domain: 'novamira') . '</h2>';
    echo
        '<p>'
            . esc_html__(
                'Client IDs issued by hand rather than by automatic registration. Each stays valid until deleted here.',
                domain: 'novamira',
            )
            . '</p>'
    ;
    echo '<table class="wp-list-table widefat fixed striped novamira-connections-table novamira-admin-clients-table">';
    echo '<colgroup>';
    echo '<col class="novamira-primary-column">';
    echo '<col class="novamira-client-id-column">';
    echo '<col class="novamira-date-column">';
    echo '<col class="novamira-date-column">';
    echo '<col class="novamira-action-column">';
    echo '</colgroup>';
    echo '<thead><tr>';
    echo '<th>' . esc_html__('Application', domain: 'novamira') . '</th>';
    echo '<th>' . esc_html__('Client ID', domain: 'novamira') . '</th>';
    echo '<th>' . esc_html__('Created', domain: 'novamira') . '</th>';
    echo '<th>' . esc_html__('First used', domain: 'novamira') . '</th>';
    echo '<th>' . esc_html__('Actions', domain: 'novamira') . '</th>';
    echo '</tr></thead><tbody>';
    foreach ($clients as $client) {
        echo '<tr>';
        echo '<td><strong>' . esc_html($client['client_name']) . '</strong> ';
        echo
            '<span style="font-size:11px; font-weight:600; color:#646970; background:#f0f0f1; border-radius:10px; padding:1px 8px;">'
                . esc_html__('manually created', domain: 'novamira')
                . '</span></td>'
        ;
        echo '<td><code>' . esc_html($client['client_id']) . '</code></td>';
        echo '<td>' . esc_html(format_utc_datetime($client['created_at'])) . '</td>';
        echo '<td>';
        echo
            esc_html(
                $client['last_used_at'] !== null
                    ? format_utc_datetime($client['last_used_at'])
                    : __('Never', domain: 'novamira'),
            )
        ;
        echo '</td>';
        echo '<td>';
        echo '<form method="post">';
        wp_nonce_field('novamira_connected_apps_delete');
        echo '<input type="hidden" name="novamira_action" value="delete_admin_client">';
        echo '<input type="hidden" name="client_id" value="' . esc_attr($client['client_id']) . '">';
        echo '<button type="submit" class="button">' . esc_html__('Delete', domain: 'novamira') . '</button>';
        echo '</form>';
        echo '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}

function render_application_passwords_section(): void
{
    $passwords = \novamira_get_mcp_passwords();

    echo '<section id="application-passwords" style="margin-top:32px; padding-top:8px; border-top:1px solid #dcdcde;">';
    echo '<h2>' . esc_html__('Application passwords', domain: 'novamira') . '</h2>';
    echo
        '<p>'
            . esc_html__(
                'These WordPress Application Passwords belong to your current user account and do not expire. Revoke any Novamira credentials you no longer use.',
                domain: 'novamira',
            )
            . '</p>'
    ;

    if ($passwords === []) {
        echo
            '<p>' . esc_html__('No Novamira application passwords exist for your account.', domain: 'novamira') . '</p>'
        ;
        echo '</section>';
        return;
    }

    $dt_format = \novamira_get_datetime_format('Y-m-d H:i');
    echo '<table class="wp-list-table widefat fixed striped novamira-connections-table novamira-passwords-table">';
    echo '<colgroup>';
    echo '<col class="novamira-primary-column">';
    echo '<col class="novamira-date-column">';
    echo '<col class="novamira-date-column">';
    echo '<col class="novamira-action-column">';
    echo '</colgroup>';
    echo '<thead><tr>';
    echo '<th>' . esc_html__('Name', domain: 'novamira') . '</th>';
    echo '<th>' . esc_html__('Created', domain: 'novamira') . '</th>';
    echo '<th>' . esc_html__('Last Used', domain: 'novamira') . '</th>';
    echo '<th>' . esc_html__('Actions', domain: 'novamira') . '</th>';
    echo '</tr></thead><tbody>';
    foreach ($passwords as $password) {
        \novamira_render_password_row($password, $dt_format);
    }
    echo '</tbody></table>';
    echo '</section>';
}
