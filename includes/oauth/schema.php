<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\OAuth\Schema;

if (!defined('ABSPATH')) {
    exit();
}

const SCHEMA_VERSION_OPTION = 'novamira_oauth_schema_version';

/**
 * Bumped when a site needs one more pass of the installer. The installer runs only while the
 * recorded version differs from this one, so a site that has recorded it pays for a single option
 * read per request and nothing else.
 */
const CURRENT_SCHEMA_VERSION = '5';

/**
 * The version whose installs already have every table, so upgrading from it needs the column pass
 * and nothing more — as long as every table is still there. Handing those tables to dbDelta again is
 * exactly what repair_missing_columns() exists to avoid.
 */
const TABLES_COMPLETE_SINCE_VERSION = '4';

function maybe_install(): void
{
    // @mago-expect analysis:mixed-assignment
    $recorded = get_option(SCHEMA_VERSION_OPTION);
    if ($recorded === CURRENT_SCHEMA_VERSION) {
        return;
    }
    // @mago-expect lint:no-global
    global $wpdb;
    /** @var \wpdb $wpdb */
    $prefix = $wpdb->prefix . 'novamira_oauth_';

    // The recorded version only says every table existed when it was written. A table dropped since,
    // or a table prefix changed since, leaves a site that is not complete any more, and recording the
    // current version there would make the fast path above suppress the installer for good. Such a
    // site goes through install() instead, exactly like one with nothing recorded: the missing table
    // is created, and nothing is recorded until every table exists.
    if ($recorded === TABLES_COMPLETE_SINCE_VERSION && tables_installed($prefix)) {
        repair_missing_columns($prefix);
        record_schema_version();
        return;
    }

    install($prefix);
}

/**
 * Installs the schema on a site that has no install with every table recorded: nothing recorded, a
 * version older than TABLES_COMPLETE_SINCE_VERSION, or that version with a table missing.
 *
 * This path deliberately keeps the installer's long-standing dbDelta behaviour, existing tables
 * included: sites coming from older schema versions got their migrations through dbDelta, and those
 * can rely on more than adding a column. The ADD COLUMN-only guarantee of repair_missing_columns()
 * applies to installs recorded at TABLES_COMPLETE_SINCE_VERSION whose tables are all present, and to
 * the on-demand repairs.
 */
function install(string $prefix): void
{
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    foreach (table_definitions($prefix) as $definition) {
        dbDelta($definition);
    }

    // dbDelta reports failures rather than throwing. Do not mark a partial migration complete:
    // leaving the old version in place makes the installer retry on the next eligible request.
    if (!tables_installed($prefix)) {
        return;
    }

    // dbDelta can leave a table that already existed without a column it declares — a database user
    // who may not ALTER keeps the old table while every table name is present — so the same
    // additive pass an upgrade runs follows here.
    repair_missing_columns($prefix);
    record_schema_version();
}

/**
 * Records this schema version once the pass that belongs to it has run, whatever its ALTER
 * statements achieved.
 *
 * A column the database user may not add is not something to retry on every request: recording
 * the version is what keeps the per-request cost of a site at one option read. The columns are
 * added on demand instead — by the registration that fails for want of one, and by the Troubleshoot
 * storage check — both of which call repair_missing_columns() again.
 */
function record_schema_version(): void
{
    update_option(SCHEMA_VERSION_OPTION, CURRENT_SCHEMA_VERSION, autoload: false);
}

/**
 * Adds the columns the installed tables are missing, and is incapable of doing anything else.
 * Returns the report it acted on — what was missing before the attempt — so a caller can tell
 * whether there was anything to add.
 *
 * Installed tables are never handed back to dbDelta by this. dbDelta does not only add: for every
 * column in the statement it is given whose installed definition differs from the declared one it
 * issues `ALTER TABLE ... CHANGE COLUMN`, so submitting a whole table because one column is absent
 * would also rewrite the type, width, nullability, default or collation of every other column that
 * had drifted — narrowing a VARCHAR that had been widened, and truncating what is stored in it.
 *
 * The columns that are absent are already known, and so are their declared definitions, so those
 * are all that is submitted: one `ALTER TABLE ... ADD COLUMN` per missing column. ADD COLUMN names
 * nothing else in the table and cannot alter what is already there — a column that exists makes its
 * own statement fail and leaves the table as it was, which also makes two requests running this at
 * once harmless. The guarantee is in the statement, not in the care taken around it.
 *
 * Only columns that are provably absent are added. A table the database would not describe proves
 * nothing — it may be gone, or the metadata query may have failed — and a repair on a guess is how a
 * table that was never broken gets written to.
 *
 * @return array{missing: array<string, list<string>>, unreadable: list<string>}
 */
function repair_missing_columns(string $prefix): array
{
    $report = column_report($prefix);
    if ($report['missing'] === []) {
        return $report;
    }

    $definitions = table_definitions($prefix);
    foreach ($report['missing'] as $suffix => $columns) {
        // Both maps are keyed by the same six suffixes; a table with no statement to read a column
        // definition from is not something to fatal over.
        if (!array_key_exists($suffix, $definitions)) {
            continue;
        }
        add_columns($prefix . $suffix, $definitions[$suffix], $columns);
    }

    return $report;
}

/**
 * Adds named columns to an installed table, each with the definition its CREATE TABLE statement
 * declares for it.
 *
 * Everything interpolated here is this file's own literal: the table name is the prefix plus a
 * suffix declared above, and each column definition is read out of the statement declared above by
 * declared_column(), which only returns a line that statement contains. A column the statement does
 * not declare is not added at all rather than guessed at.
 *
 * @param list<string> $columns
 */
function add_columns(string $table, string $definition, array $columns): void
{
    // @mago-expect lint:no-global
    global $wpdb;
    /** @var \wpdb $db */
    $db = $wpdb;
    foreach ($columns as $column) {
        $declared = declared_column($definition, $column);
        if ($declared === null) {
            continue;
        }
        // A concurrent request can add the same column first, and the database then refuses the
        // duplicate. That refusal is the expected outcome of losing the race, so it must not be
        // printed into the page of a site that displays database errors. Display only: wpdb logs
        // the error before it consults this setting (wpdb::print_error), so a genuine failure is
        // still recorded, and the site's own setting is put back on every exit.
        $showing_errors = $db->hide_errors();
        try {
            $db->query(add_column_statement($table, $declared));
        } finally {
            $db->show_errors($showing_errors);
        }
    }
}

/**
 * The statement that adds one column with its declared definition — the only kind of schema change
 * repair_missing_columns() ever issues. Shared with the Troubleshoot page, which hands the same statements
 * to an administrator whose database user may not run them, so the two can never disagree.
 */
function add_column_statement(string $table, string $declared): string
{
    return "ALTER TABLE `{$table}` ADD COLUMN {$declared}";
}

/**
 * The declared definition of one column, taken from the CREATE TABLE statement of its table, or
 * null when that statement does not declare it.
 *
 * The statements above declare one column per line, which is what makes this a lookup rather than a
 * parser: the line whose first word is the column name, without its trailing comma. Index lines
 * (PRIMARY KEY, UNIQUE KEY, KEY) begin with a word no column is named after, so they never match.
 */
function declared_column(string $definition, string $column): ?string
{
    foreach (explode("\n", $definition) as $line) {
        $line = rtrim(trim($line), characters: ',');
        $name = substr($line, offset: 0, length: strcspn($line, characters: " \t("));
        if (strtolower($name) !== strtolower($column)) {
            continue;
        }
        return $line;
    }

    return null;
}

/**
 * The CREATE TABLE statement for each table, keyed by table suffix.
 *
 * Keyed per table so that a repair can read the declared definition of one column out of the
 * statement that declares it; the statements themselves are submitted only by install(). An install
 * recorded at TABLES_COMPLETE_SINCE_VERSION with every table present, and the on-demand repairs,
 * never submit them — see repair_missing_columns() for why — while an unrecorded or older install,
 * and a recorded one with a table missing, keep the installer's dbDelta behaviour (see install()).
 *
 * @return array<string, string>
 */
function table_definitions(string $prefix): array
{
    // @mago-expect lint:no-global
    global $wpdb;
    /** @var \wpdb $wpdb */
    $c = $wpdb->get_charset_collate();
    $p = $prefix;

    return [
        'clients' => "CREATE TABLE {$p}clients (
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
        ) {$c};",
        // Short-lived browser consent state is kept in the database rather than a transient. External
        // object caches can route consecutive requests to different cache nodes, making a freshly
        // written transient appear to have expired on the very next wp-admin request.
        'pending_authorizations' => "CREATE TABLE {$p}pending_authorizations (
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
        ) {$c};",
        'auth_codes' => "CREATE TABLE {$p}auth_codes (
            identifier_hash CHAR(64) NOT NULL,
            client_id VARCHAR(64) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            expires_at DATETIME NOT NULL,
            scopes TEXT NOT NULL,
            redirect_uri TEXT NOT NULL,
            revoked TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (identifier_hash),
            KEY expires_at (expires_at)
        ) {$c};",
        'access_tokens' => "CREATE TABLE {$p}access_tokens (
            identifier_hash CHAR(64) NOT NULL,
            client_id VARCHAR(64) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            expires_at DATETIME NOT NULL,
            scopes TEXT NOT NULL,
            revoked TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (identifier_hash),
            KEY expires_at (expires_at),
            KEY user_id (user_id)
        ) {$c};",
        // RFC 8628 device authorization. Both codes are stored only as SHA-256 hashes, like auth codes
        // and access tokens: a database read must not hand out a pending grant. The user code is unique
        // so the verification page can resolve exactly one pending authorization from what is typed.
        'device_codes' => "CREATE TABLE {$p}device_codes (
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
        ) {$c};",
        'refresh_tokens' => "CREATE TABLE {$p}refresh_tokens (
            identifier_hash CHAR(64) NOT NULL,
            access_token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            revoked TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (identifier_hash),
            KEY expires_at (expires_at)
        ) {$c};",
    ];
}

/** Whether every table this schema declares exists. */
function tables_installed(string $prefix): bool
{
    // @mago-expect lint:no-global
    global $wpdb;
    /** @var \wpdb $db */
    $db = $wpdb;
    foreach (required_tables($prefix) as $table) {
        $sql = $db->prepare('SHOW TABLES LIKE %s', $db->esc_like($table));
        if (!is_string($sql) || $db->get_var($sql) !== $table) {
            return false;
        }
    }

    return true;
}

/** @return list<string> */
function required_tables(string $prefix): array
{
    return array_map(static fn(string $suffix): string => $prefix . $suffix, array_keys(required_columns()));
}

/**
 * The columns each table must carry, keyed by table suffix and mirroring the CREATE TABLE
 * statements above. One source of truth: the installer refuses to record a migration that did not
 * apply them, it repairs the table that is missing one, and the Troubleshoot storage check names
 * them.
 *
 * @return array<string, list<string>>
 */
function required_columns(): array
{
    return [
        'clients' => [
            'id',
            'client_id',
            'client_name',
            'redirect_uris',
            'is_confidential',
            'client_secret_hash',
            'created_at',
            'last_used_at',
            'registered_by_ip_hash',
            'admin_created',
            'grant_types',
        ],
        'pending_authorizations' => [
            'token_hash',
            'client_id',
            'user_id',
            'redirect_uri',
            'code_challenge',
            'code_challenge_method',
            'scope',
            'state',
            'expires_at',
        ],
        'auth_codes' => [
            'identifier_hash',
            'client_id',
            'user_id',
            'expires_at',
            'scopes',
            'redirect_uri',
            'revoked',
        ],
        'access_tokens' => ['identifier_hash', 'client_id', 'user_id', 'expires_at', 'scopes', 'revoked'],
        'device_codes' => [
            'device_code_hash',
            'user_code_hash',
            'client_id',
            'user_id',
            'scopes',
            'status',
            'expires_at',
            'last_polled_at',
        ],
        'refresh_tokens' => ['identifier_hash', 'access_token_hash', 'expires_at', 'revoked'],
    ];
}

/**
 * What the installed tables are missing, keyed by table suffix.
 *
 * `missing` holds the required columns of a table that could be described and does not have them.
 * `unreadable` holds the tables that could not be described at all: the table is gone, or the
 * metadata query failed. The two are kept apart because they mean opposite things — a missing column
 * is something to repair and to report, while a table that could not be read means the schema was
 * not verified, which must never be recorded as complete, reported as healthy, or repaired blind.
 *
 * @return array{missing: array<string, list<string>>, unreadable: list<string>}
 */
function column_report(string $prefix): array
{
    $missing = [];
    $unreadable = [];
    foreach (required_columns() as $suffix => $required) {
        $installed = installed_columns($prefix . $suffix);
        if ($installed === null) {
            $unreadable[] = $prefix . $suffix;
            continue;
        }
        $absent = array_values(array_diff($required, $installed));
        if ($absent === []) {
            continue;
        }
        $missing[$suffix] = $absent;
    }

    return ['missing' => $missing, 'unreadable' => $unreadable];
}

/**
 * Column names of an installed table, lower-cased, or null when the table could not be described.
 *
 * Every table has at least one column, so an empty answer never means a table without columns: it
 * means the table is not there or the metadata query failed, and the callers treat that as
 * unverified rather than as complete.
 *
 * @return list<string>|null
 */
function installed_columns(string $table): ?array
{
    // @mago-expect lint:no-global
    global $wpdb;
    /** @var \wpdb $wpdb */
    $names = [];
    /** @var mixed $column */
    foreach ($wpdb->get_col("SHOW COLUMNS FROM `{$table}`") as $column) {
        if (!is_string($column) || $column === '') {
            continue;
        }
        $names[] = strtolower($column);
    }

    return $names === [] ? null : $names;
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

/**
 * Schedules the daily cleanup and registers its handler.
 *
 * Called by boot() rather than on include. The Troubleshoot page loads this file for its schema
 * definitions on sites where boot() deliberately did not load it, and reading a definition must not
 * leave a scheduled event behind on a site whose OAuth endpoints are switched off.
 */
function schedule_gc(): void
{
    if (!wp_next_scheduled('novamira_oauth_gc')) {
        wp_schedule_event(timestamp: time() + HOUR_IN_SECONDS, recurrence: 'daily', hook: 'novamira_oauth_gc');
    }
    add_action('novamira_oauth_gc', __NAMESPACE__ . '\\gc');
}
