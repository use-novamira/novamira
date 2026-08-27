<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit();
}

const NOVAMIRA_UNINSTALL_PLAN_OPTION = 'novamira_uninstall_plan';

/**
 * @return array{delete_oauth: bool, delete_application_passwords: bool, delete_memories: bool, delete_user_skills: bool, delete_chat_sessions: bool}
 */
function novamira_uninstall_plan(): array
{
    /** @var mixed $stored */
    $stored = get_site_option(NOVAMIRA_UNINSTALL_PLAN_OPTION, default_value: []);
    if (!is_array($stored)) {
        $stored = [];
    }

    return [
        // Preserve the uninstall behavior used before cleanup choices were introduced.
        'delete_oauth' => !array_key_exists('delete_oauth', $stored) || $stored['delete_oauth'] === true,
        'delete_application_passwords' => ($stored['delete_application_passwords'] ?? false) === true,
        'delete_memories' => ($stored['delete_memories'] ?? false) === true,
        'delete_user_skills' => ($stored['delete_user_skills'] ?? false) === true,
        // Preserve the legacy behavior only when no explicit Chat cleanup choice was stored.
        'delete_chat_sessions' => !array_key_exists('delete_chat_sessions', $stored)
            || $stored['delete_chat_sessions'] === true,
    ];
}

/**
 * @param array{delete_oauth: bool, delete_application_passwords: bool, delete_memories: bool, delete_user_skills: bool, delete_chat_sessions: bool} $plan
 */
function novamira_uninstall_current_site(array $plan): void
{
    $wpdb = novamira_uninstall_wpdb();

    if ($plan['delete_chat_sessions']) {
        novamira_uninstall_drop_table($wpdb->prefix . 'novamira_chat_sessions');

        delete_option('novamira_chat_schema_version');
        delete_option('novamira_chat_sessions');
    }

    if ($plan['delete_user_skills']) {
        novamira_uninstall_post_type('novamira_skill');
    }

    if ($plan['delete_memories']) {
        novamira_uninstall_post_type('novamira_memory');
    }

    if ($plan['delete_oauth']) {
        foreach (novamira_uninstall_oauth_tables() as $table) {
            novamira_uninstall_drop_table($table);
        }

        delete_option('novamira_oauth_schema_version');
        delete_option('novamira_oauth_private_key');
        delete_option('novamira_oauth_public_key');
        delete_option('novamira_oauth_encryption_key');
        delete_option('novamira_oauth_dcr_epoch');
    }

    delete_option('novamira_feature_preferences');

    wp_clear_scheduled_hook('novamira_oauth_gc');
    wp_clear_scheduled_hook('novamira_gutenberg_cleanup');
}

function novamira_uninstall_post_type(string $post_type): void
{
    $wpdb = novamira_uninstall_wpdb();
    $query = $wpdb->prepare('SELECT ID FROM %i WHERE post_type = %s', $wpdb->posts, $post_type);
    if (!is_string($query)) {
        return;
    }

    $post_ids = $wpdb->get_col($query);
    foreach (array_map('intval', $post_ids) as $post_id) {
        wp_delete_post($post_id, force_delete: true);
    }
}

function novamira_uninstall_drop_table(string $table): void
{
    $wpdb = novamira_uninstall_wpdb();
    $query = $wpdb->prepare('DROP TABLE IF EXISTS %i', $table);
    if (is_string($query)) {
        $wpdb->query($query);
    }
}

/**
 * @return list<string>
 */
function novamira_uninstall_oauth_tables(): array
{
    $wpdb = novamira_uninstall_wpdb();

    return [
        $wpdb->prefix . 'novamira_oauth_clients',
        $wpdb->prefix . 'novamira_oauth_auth_codes',
        $wpdb->prefix . 'novamira_oauth_access_tokens',
        $wpdb->prefix . 'novamira_oauth_device_codes',
        $wpdb->prefix . 'novamira_oauth_refresh_tokens',
    ];
}

function novamira_uninstall_wpdb(): wpdb
{
    // @mago-expect lint:no-global -- $wpdb is WordPress' database handle.
    global $wpdb;

    /** @var wpdb $wpdb */
    return $wpdb;
}

function novamira_uninstall_application_passwords(): void
{
    $wpdb = novamira_uninstall_wpdb();
    $query = $wpdb->prepare(
        'SELECT user_id FROM %i WHERE meta_key = %s',
        $wpdb->usermeta,
        WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS,
    );
    if (!is_string($query)) {
        return;
    }
    $user_ids = $wpdb->get_col($query);

    foreach (array_unique(array_map('intval', $user_ids)) as $user_id) {
        $passwords = WP_Application_Passwords::get_user_application_passwords($user_id);
        foreach ($passwords as $password) {
            if (!novamira_uninstall_is_application_password($password)) {
                continue;
            }

            $uuid = is_string($password['uuid'] ?? null) ? $password['uuid'] : '';
            if ($uuid !== '') {
                WP_Application_Passwords::delete_application_password($user_id, $uuid);
            }
        }
    }
}

/** @param array<string, mixed> $password */
function novamira_uninstall_is_application_password(array $password): bool
{
    $name = is_string($password['name'] ?? null) ? $password['name'] : '';
    return str_starts_with($name, 'Novamira');
}

$novamira_uninstall_plan = novamira_uninstall_plan();

if ($novamira_uninstall_plan['delete_application_passwords']) {
    novamira_uninstall_application_passwords();
}

if (is_multisite()) {
    // @mago-expect analysis:mixed-assignment -- WordPress returns site ids when fields=ids.
    $site_ids = get_sites(['fields' => 'ids', 'number' => 0]);
    if (!is_array($site_ids)) {
        return;
    }

    // @mago-expect analysis:mixed-assignment
    foreach ($site_ids as $site_id) {
        switch_to_blog((int) $site_id);
        novamira_uninstall_current_site($novamira_uninstall_plan);
        restore_current_blog();
    }
    delete_site_option(NOVAMIRA_UNINSTALL_PLAN_OPTION);
    return;
}

novamira_uninstall_current_site($novamira_uninstall_plan);
delete_site_option(NOVAMIRA_UNINSTALL_PLAN_OPTION);
