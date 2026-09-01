<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\OAuth\Schema;

if (!defined('ABSPATH')) {
    exit();
}

const SCHEMA_VERSION_OPTION = 'novamira_oauth_schema_version';

const CURRENT_SCHEMA_VERSION = '4';

function maybe_install(): void
{
    if (get_option(SCHEMA_VERSION_OPTION) === CURRENT_SCHEMA_VERSION) {
        return;
    }
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    // @mago-expect lint:no-global
    global $wpdb;
    /** @var \wpdb $wpdb */
    $c = $wpdb->get_charset_collate();
    $p = $wpdb->prefix . 'novamira_oauth_';

    dbDelta("CREATE TABLE {$p}clients (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        client_id VARCHAR(64) NOT NULL,
        client_name VARCHAR(191) NOT NULL,
        redirect_uris TEXT NOT NULL,
        is_confidential TINYINT(1) NOT NULL DEFAULT 0,
        client_secret_hash VARCHAR(255) DEFAULT NULL,
        created_at DATETIME NOT NULL,
        last_used_at DATETIME DEFAULT NULL,
        registered_by_ip_hash CHAR(64) NOT NULL,
        admin_created TINYINT(1) NOT NULL DEFAULT 0,
        grant_types VARCHAR(191) NOT NULL DEFAULT '',
        PRIMARY KEY (id),
        UNIQUE KEY client_id (client_id)
    ) {$c};");

    // Short-lived browser consent state is kept in the database rather than a transient. External
    // object caches can route consecutive requests to different cache nodes, making a freshly
    // written transient appear to have expired on the very next wp-admin request.
    dbDelta("CREATE TABLE {$p}pending_authorizations (
        token_hash CHAR(64) NOT NULL,
        client_id VARCHAR(64) NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        redirect_uri TEXT NOT NULL,
        code_challenge VARCHAR(128) NOT NULL,
        code_challenge_method VARCHAR(16) NOT NULL,
        scope TEXT NOT NULL,
        state TEXT NOT NULL,
        expires_at DATETIME NOT NULL,
        PRIMARY KEY (token_hash),
        KEY expires_at (expires_at)
    ) {$c};");

    dbDelta("CREATE TABLE {$p}auth_codes (
        identifier_hash CHAR(64) NOT NULL,
        client_id VARCHAR(64) NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        expires_at DATETIME NOT NULL,
        scopes TEXT NOT NULL,
        redirect_uri TEXT NOT NULL,
        revoked TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (identifier_hash),
        KEY expires_at (expires_at)
    ) {$c};");

    dbDelta("CREATE TABLE {$p}access_tokens (
        identifier_hash CHAR(64) NOT NULL,
        client_id VARCHAR(64) NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        expires_at DATETIME NOT NULL,
        scopes TEXT NOT NULL,
        revoked TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (identifier_hash),
        KEY expires_at (expires_at),
        KEY user_id (user_id)
    ) {$c};");

    // RFC 8628 device authorization. Both codes are stored only as SHA-256 hashes, like auth codes
    // and access tokens: a database read must not hand out a pending grant. The user code is unique
    // so the verification page can resolve exactly one pending authorization from what is typed.
    dbDelta("CREATE TABLE {$p}device_codes (
        device_code_hash CHAR(64) NOT NULL,
        user_code_hash CHAR(64) NOT NULL,
        client_id VARCHAR(64) NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        scopes TEXT NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'pending',
        expires_at DATETIME NOT NULL,
        last_polled_at DATETIME DEFAULT NULL,
        PRIMARY KEY (device_code_hash),
        UNIQUE KEY user_code_hash (user_code_hash),
        KEY expires_at (expires_at)
    ) {$c};");

    dbDelta("CREATE TABLE {$p}refresh_tokens (
        identifier_hash CHAR(64) NOT NULL,
        access_token_hash CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        revoked TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (identifier_hash),
        KEY expires_at (expires_at)
    ) {$c};");

    // dbDelta reports failures rather than throwing. Do not mark a partial migration complete:
    // leaving the old version in place makes the installer retry on the next eligible request.
    foreach (required_tables($p) as $table) {
        $sql = $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table));
        if (!is_string($sql) || $wpdb->get_var($sql) !== $table) {
            return;
        }
    }

    update_option(SCHEMA_VERSION_OPTION, CURRENT_SCHEMA_VERSION, autoload: false);
}

/** @return list<string> */
function required_tables(string $prefix): array
{
    return array_map(static fn(string $suffix): string => $prefix . $suffix, [
        'clients',
        'pending_authorizations',
        'auth_codes',
        'access_tokens',
        'device_codes',
        'refresh_tokens',
    ]);
}

function gc(): void
{
    // @mago-expect lint:no-global
    global $wpdb;
    /** @var \wpdb $wpdb */
    $cutoff = gmdate('Y-m-d H:i:s', time() - (30 * DAY_IN_SECONDS));
    $p = $wpdb->prefix . 'novamira_oauth_';
    foreach (['auth_codes', 'access_tokens', 'refresh_tokens'] as $t) {
        $table = $p . $t;
        // @mago-expect analysis:possibly-invalid-argument
        $sql = $wpdb->prepare("DELETE FROM `{$table}` WHERE expires_at < %s", $cutoff);
        // @mago-expect analysis:possibly-invalid-argument
        $wpdb->query($sql);
    }

    // Pending browser grants contain no useful audit history and are invalid as soon as they
    // expire, so remove them immediately rather than retaining them with issued credentials.
    $pending_table = $p . 'pending_authorizations';
    // @mago-expect analysis:possibly-invalid-argument
    $pending_sql = $wpdb->prepare("DELETE FROM `{$pending_table}` WHERE expires_at < %s", gmdate('Y-m-d H:i:s'));
    if (is_string($pending_sql)) {
        $wpdb->query($pending_sql);
    }

    // Device codes are the one table an unauthenticated request can write to, so they are not kept
    // for thirty days like the rows that only an approved grant produces. The repository owns that
    // retention and prunes on every device request too; this run is the backstop for a site whose
    // device endpoint has gone quiet.
    require_once __DIR__ . '/repositories/device-code-repository.php';
    (new \Novamira\OAuth\Repositories\DeviceCodeRepository())->prune_expired();
}

if (!wp_next_scheduled('novamira_oauth_gc')) {
    wp_schedule_event(timestamp: time() + HOUR_IN_SECONDS, recurrence: 'daily', hook: 'novamira_oauth_gc');
}
add_action('novamira_oauth_gc', __NAMESPACE__ . '\\gc');
