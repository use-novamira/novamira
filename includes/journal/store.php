<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Storage for the journal: one table holding every recorded change and held email, plus one
 * row per execution describing it. Every query issued from here runs as "internal", so the journal's own
 * filter lets it through untouched.
 */

namespace Novamira\Journal;

if (!defined('ABSPATH')) {
    exit();
}

const TABLE_SUFFIX = 'novamira_journal';

const SCHEMA_OPTION = 'novamira_journal_schema';

const SCHEMA_VERSION = 5;

/** The emails a person still has to decide about. The code that sent them was told they went out, so they are never dropped on their own. */
const WAITING_MAIL = "kind = 'mail' AND status IN ('held', 'sending')";

/** Kinds of journal row that hold a change to the site. */
const SAVED_KINDS = ['update', 'delete', 'insert', 'table'];

/** Journal rows are kept this long by default (filter novamira_journal_retention_days). */
const RETENTION_DAYS = 30;

/** The journal keeps at most this many rows; the oldest go first (filter novamira_journal_max_rows). */
const MAX_ROWS = 200_000;

/** Rows per multi-row statement. */
const CHUNK = 200;

/** What $wpdb reports about the last query. The insert id is kept separately: see restore_insert_id(). */
const WPDB_RESULT_FIELDS = ['last_error', 'last_query', 'last_result', 'rows_affected', 'num_rows'];

/** Stored in place of a value the journal never keeps. */
const NOT_KEPT = '[not kept in the AI change history]';

/** A composite key stored in the journal must fit the "pk" column. */
const KEY_LIMIT = 191;

/** The journal's "pk_col" column holds the key's columns joined by commas. */
const KEY_COLUMNS_LIMIT = 64;

/** Options whose value is a secret: WordPress keys and salts and Novamira's OAuth keys. */
const SECRET_OPTIONS = [
    'auth_key',
    'auth_salt',
    'secure_auth_key',
    'secure_auth_salt',
    'logged_in_key',
    'logged_in_salt',
    'nonce_key',
    'nonce_salt',
    'novamira_oauth_private_key',
    'novamira_oauth_encryption_key',
];

/** User meta whose value is a credential: login sessions and application passwords. */
const SECRET_USER_META = ['session_tokens', '_application_passwords'];

/**
 * What $wpdb reports about the last query, kept while the journal runs queries of its own.
 *
 * @return array<string, mixed>
 */
function &kept_results(): array
{
    /** @var array<string, mixed> $kept */
    static $kept = [];
    return $kept;
}

/**
 * Mark the queries that follow as the journal's own. Returns the previous mark for leave_internal(). What $wpdb
 * reports about the last query of the AI's code is put back afterwards, so its code never reads the journal's
 * results, whenever the journal runs (holding an email between its query and its check of rows_affected).
 */
function enter_internal(): bool
{
    $state = &state();
    $previous = $state['internal'];
    $state['internal'] = true;
    if (!$previous) {
        $wpdb = db();
        $kept = &kept_results();
        foreach (WPDB_RESULT_FIELDS as $field) {
            $kept[$field] = $wpdb->{$field};
        }
    }
    return $previous;
}

// @mago-expect lint:no-boolean-flag-parameter -- The mark enter_internal() returned.
function leave_internal(bool $previous): void
{
    $state = &state();
    $state['internal'] = $previous;
    if (!$previous) {
        $wpdb = db();
        $kept = &kept_results();
        // @mago-expect analysis:mixed-assignment -- Whatever $wpdb held is put back as it was.
        foreach ($kept as $field => $value) {
            // @mago-expect analysis:string-member-selector -- A fixed list of $wpdb's own fields.
            $wpdb->{$field} = $value;
        }
        $kept = [];
    }
}

function db(): \wpdb
{
    // @mago-expect lint:no-global -- WordPress provides the database handle globally.
    global $wpdb;
    /** @var \wpdb $wpdb */
    return $wpdb;
}

function table(): string
{
    return db()->prefix . TABLE_SUFFIX;
}

/** Run one statement of the journal's own. Returns whether it ran without error. */
function own_query(string $sql): bool
{
    return own_exec($sql) >= 0;
}

/** Run one statement of the journal's own and return the rows it affected, or -1 when it failed. */
function own_exec(string $sql): int
{
    $previous = enter_internal();
    try {
        $wpdb = db();
        $result = $wpdb->query($sql);
        return $result === false || $wpdb->last_error !== '' ? -1 : (int) $wpdb->rows_affected;
    } finally {
        leave_internal($previous);
    }
}

/**
 * Rows of the journal's own SELECT.
 *
 * @return list<array<string, string|null>>|null Null when the query failed.
 */
function own_rows(string $sql): ?array
{
    $previous = enter_internal();
    try {
        $wpdb = db();
        /** @var list<array<string, string|null>>|null $rows */
        $rows = $wpdb->get_results($sql, 'ARRAY_A');
        return $wpdb->last_error === '' ? $rows ?? [] : null;
    } finally {
        leave_internal($previous);
    }
}

function own_var(string $sql): ?string
{
    $previous = enter_internal();
    try {
        return db()->get_var($sql);
    } finally {
        leave_internal($previous);
    }
}

/**
 * Prepare a statement whose shape is built from fixed fragments.
 *
 * @param list<int|string> $args
 */
function prepared(string $sql, array $args): string
{
    // @mago-expect analysis:possibly-invalid-argument -- The SQL is assembled only from fixed fragments.
    $prepared = db()->prepare($sql, ...$args);
    return is_string($prepared) ? $prepared : '';
}

/**
 * Bring an existing table up to date. Each step looks first whether it is needed, so one that failed halfway can
 * simply run again.
 */
function upgrade_table(int $version): bool
{
    if ($version < 1 || $version >= SCHEMA_VERSION) {
        return true;
    }
    if ($version < 3 && !add_group_columns()) {
        return false;
    }
    if (
        own_var(prepared('SHOW COLUMNS FROM %i LIKE %s', [table(), 'new_hash'])) !== null
        && !own_query('ALTER TABLE `' . table() . '` DROP COLUMN new_hash')
    ) {
        return false;
    }
    return (
        own_var(prepared('SHOW INDEX FROM %i WHERE Key_name = %s', [table(), 'waiting'])) !== null
        || own_query('ALTER TABLE `' . table() . '` ADD KEY waiting (kind, status)')
    );
}

function add_group_columns(): bool
{
    if (
        own_var(prepared('SHOW INDEX FROM %i WHERE Key_name = %s', [table(), 'row_ref'])) === null
        && !own_query('ALTER TABLE `' . table() . '` ADD KEY row_ref (tbl(64), pk(64))')
    ) {
        return false;
    }
    if (own_var(prepared('SHOW COLUMNS FROM %i LIKE %s', [table(), 'grp'])) === null) {
        $added = own_query(
            'ALTER TABLE `'
            . table()
            . "` ADD COLUMN grp CHAR(32) NOT NULL DEFAULT '' AFTER session, ADD COLUMN listed TINYINT(1) NOT NULL DEFAULT 1, ADD KEY grp (grp)",
        );
        if (!$added) {
            return false;
        }
    }
    return own_query('UPDATE `' . table() . "` SET grp = execution WHERE grp = ''");
}

/** The version is saved only once the table really matches it: otherwise the next request tries again. */
function ensure_table(): void
{
    $version = (int) get_option(SCHEMA_OPTION, default_value: 0);
    if ($version === SCHEMA_VERSION || !upgrade_table($version)) {
        return;
    }
    $created = own_query('CREATE TABLE IF NOT EXISTS `' . table() . "` (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            execution CHAR(32) NOT NULL,
            session VARCHAR(191) NOT NULL DEFAULT '',
            grp CHAR(32) NOT NULL DEFAULT '',
            listed TINYINT(1) NOT NULL DEFAULT 1,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created INT UNSIGNED NOT NULL,
            kind VARCHAR(20) NOT NULL,
            tbl VARCHAR(191) NOT NULL DEFAULT '',
            pk_col VARCHAR(64) NOT NULL DEFAULT '',
            pk VARCHAR(191) NOT NULL DEFAULT '',
            old_value LONGTEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'recorded',
            PRIMARY KEY (id),
            KEY execution (execution),
            KEY session (session),
            KEY grp (grp),
            KEY created (created),
            KEY row_ref (tbl(64), pk(64)),
            KEY waiting (kind, status)
        ) " . db()->get_charset_collate());
    if ($created) {
        update_option(SCHEMA_OPTION, SCHEMA_VERSION, autoload: false);
    }
}

/** Whether the journal's table has been created, whichever version of it. */
function table_ready(): bool
{
    return (int) get_option(SCHEMA_OPTION, default_value: 0) > 0;
}

/**
 * Text the history can store, for what it only shows or sends later: an email in an old charset is converted, not lost.
 */
function readable_text(mixed $value): mixed
{
    if (is_array($value)) {
        return array_map(__NAMESPACE__ . '\\readable_text', $value);
    }
    return is_string($value) ? readable_string($value) : $value;
}

function readable_string(string $value): string
{
    if (preg_match('//u', $value) === 1) {
        return $value;
    }
    return function_exists('mb_convert_encoding')
        ? (string) mb_convert_encoding($value, to_encoding: 'UTF-8', from_encoding: 'Windows-1252')
        : (string) preg_replace('/[^\x00-\x7F]/', replacement: '?', subject: $value);
}

/** At most $length bytes of a text, without cutting a character in half. */
function cut_text(string $text, int $length): string
{
    $cut = substr($text, offset: 0, length: $length);
    for ($drop = 0; $drop < 4 && preg_match('//u', $cut) !== 1; $drop++) {
        $cut = substr($text, offset: 0, length: max(0, $length - $drop - 1));
    }
    return preg_match('//u', $cut) === 1 ? $cut : readable_string(substr($text, offset: 0, length: $length));
}

/**
 * A row as the history stores it, or false when it cannot be stored as it is. Binary data and broken text fail here:
 * wp_json_encode() would quietly convert them, and the AI would read a different value.
 */
function stored_value(mixed $value): string|false
{
    return json_encode($value) === false ? false : wp_json_encode($value);
}

/**
 * @param array{execution: string, kind: string, tbl?: string, pk_col?: string, pk?: string, old_value?: mixed, status?: string, session?: string, grp?: string, user_id?: int} $entry
 */
function add_entry(array $entry): int
{
    $state = state();
    $encoded = array_key_exists('old_value', $entry) ? wp_json_encode($entry['old_value']) : false;
    if (array_key_exists('old_value', $entry) && !is_string($encoded)) {
        return 0;
    }
    $previous = enter_internal();
    try {
        $wpdb = db();
        $keep = $wpdb->insert_id;
        $wpdb->insert(table(), [
            'execution' => $entry['execution'],
            'session' => $entry['session'] ?? $state['session'],
            'grp' => $entry['grp'] ?? $state['group'],
            'user_id' => $entry['user_id'] ?? $state['user_id'],
            'created' => time(),
            'kind' => $entry['kind'],
            'tbl' => $entry['tbl'] ?? '',
            'pk_col' => $entry['pk_col'] ?? '',
            'pk' => $entry['pk'] ?? '',
            'old_value' => is_string($encoded) ? $encoded : null,
            'status' => $entry['status'] ?? 'recorded',
        ]);
        $id = $wpdb->insert_id;
        restore_insert_id($keep);
        return $id;
    } finally {
        leave_internal($previous);
    }
}

/** @param array<string, string|null> $fields */
function update_entry(int $id, array $fields): bool
{
    $previous = enter_internal();
    try {
        return db()->update(table(), $fields, ['id' => $id]) !== false;
    } finally {
        leave_internal($previous);
    }
}

/**
 * Insert many journal rows with as few statements as possible.
 *
 * @param list<array{execution: string, kind: string, tbl?: string, pk_col?: string, pk?: string, old_value?: mixed, status?: string}> $entries
 * @return bool Whether every row was saved.
 */
function add_entries(array $entries): bool
{
    $state = state();
    foreach (array_chunk($entries, CHUNK) as $chunk) {
        $tuples = [];
        $args = [table()];
        foreach ($chunk as $entry) {
            $encoded = array_key_exists('old_value', $entry) ? stored_value($entry['old_value']) : false;
            if (array_key_exists('old_value', $entry) && !is_string($encoded)) {
                // A value that cannot be stored would read back as something else.
                return false;
            }
            $tuples[] = '(%s, %s, %s, %d, %d, %s, %s, %s, %s, ' . (is_string($encoded) ? '%s' : 'NULL') . ', %s)';
            array_push(
                $args,
                $entry['execution'],
                $state['session'],
                $state['group'],
                $state['user_id'],
                time(),
                $entry['kind'],
            );
            array_push($args, $entry['tbl'] ?? '', $entry['pk_col'] ?? '', $entry['pk'] ?? '');
            if (is_string($encoded)) {
                $args[] = $encoded;
            }
            $args[] = $entry['status'] ?? 'recorded';
        }
        $keep = db()->insert_id;
        $saved = own_query(prepared(
            'INSERT INTO %i (execution, session, grp, user_id, created, kind, tbl, pk_col, pk, old_value, status) VALUES '
                . implode(', ', $tuples),
            $args,
        ));
        if ($saved && saved_from() === 0) {
            saved_from((int) db()->insert_id);
        }
        restore_insert_id($keep);
        if (!$saved) {
            return false;
        }
    }
    return true;
}

/**
 * The id of the first row add_entries() stored since this was set to 0: where the rows saved for one write start, so
 * they can be forgotten if that write does not happen.
 */
function saved_from(?int $set = null): int
{
    /** @var int $id */
    static $id = 0;
    if ($set !== null) {
        $id = $set;
    }
    return $id;
}

/**
 * After a journal insert, give the AI's code back the insert id it had: $wpdb->insert_id, and MySQL's
 * LAST_INSERT_ID() on the same connection, which the journal's own INSERT replaced.
 */
function restore_insert_id(int $keep): void
{
    db()->insert_id = $keep;
    if (state()['active']) {
        own_var(prepared('SELECT LAST_INSERT_ID(%d)', [$keep]));
    }
}

/**
 * Move one journal row from one status to another, only if nobody else moved it first. Returns whether this
 * call made the change, so two people clicking at once cannot both act on it.
 */
function claim_entry(int $id, string $from, string $to): bool
{
    return own_exec(prepared('UPDATE %i SET status = %s WHERE id = %d AND status = %s', [table(), $to, $id, $from]))
    === 1;
}

/**
 * Fill a table copy, leaving out the secrets the journal never keeps: login sessions, application passwords and
 * the site's secret keys stored as options never reach the copy, and reset keys are blanked. Password hashes of a
 * users table stay: a copy restored without them would lock every user out, administrators included.
 *
 * @return bool Whether every row reached the copy.
 */
function copy_rows(string $table, string $backup): bool
{
    $wpdb = db();
    $rules = [
        $wpdb->usermeta => ['meta_key', SECRET_USER_META],
        $wpdb->options => ['option_name', SECRET_OPTIONS],
    ];
    $rule = $rules[$table] ?? null;
    $where = '';
    $args = [];
    if ($rule !== null) {
        $where = ' WHERE %i NOT IN (' . implode(', ', array_fill(0, count($rule[1]), value: '%s')) . ')';
        $args = [$rule[0], ...$rule[1]];
    }
    $copied = own_exec(prepared('INSERT INTO %i SELECT * FROM %i' . $where, [$backup, $table, ...$args]));
    if ($copied < 0 || (int) own_var(prepared('SELECT COUNT(*) FROM %i', [$backup])) !== $copied) {
        return false;
    }
    return $table !== $wpdb->users || own_query(prepared("UPDATE %i SET user_activation_key = ''", [$backup]));
}

/**
 * Current rows of a table by key, indexed by that key; keys with no row are absent. $column is a key as
 * primary_key() gives it, so it may name several columns.
 *
 * @param list<string> $keys
 * @return array<string, array<string, string|null>>
 */
function rows_by_keys(string $table, string $column, array $keys): array
{
    $found = [];
    foreach (array_chunk(array_values(array_unique($keys)), CHUNK) as $chunk) {
        $sql = rows_by_keys_sql($table, $column, $chunk);
        foreach (($sql === '' ? null : own_rows($sql)) ?? [] as $row) {
            $found[row_key($column, $row)] = $row;
        }
    }
    return $found;
}

/**
 * @param list<string> $keys
 */
function rows_by_keys_sql(string $table, string $column, array $keys): string
{
    if (count(key_columns($column)) === 1) {
        $in = implode(', ', array_fill(0, count($keys), value: '%s'));
        return prepared('SELECT * FROM %i WHERE %i IN (' . $in . ')', [$table, $column, ...$keys]);
    }
    $conditions = [];
    $args = [$table];
    foreach ($keys as $key) {
        $values = key_values($column, $key);
        if ($values === []) {
            continue;
        }
        [$where, $where_args] = key_condition($values);
        $conditions[] = '(' . $where . ')';
        array_push($args, ...$where_args);
    }
    return $conditions === [] ? '' : prepared('SELECT * FROM %i WHERE ' . implode(' OR ', $conditions), $args);
}

/**
 * The columns of a key as primary_key() gives it.
 *
 * @return list<string>
 */
function key_columns(string $key): array
{
    return $key === '' ? [] : explode(',', $key);
}

/**
 * The value identifying a row: the column itself for a single-column key, a JSON list for a composite one. '' when
 * the row lacks one of the columns, or the value does not fit the journal.
 *
 * @param array<string, string|null> $row
 */
function row_key(string $key, array $row): string
{
    $columns = key_columns($key);
    if (count($columns) === 1) {
        return $row[$columns[0]] ?? '';
    }
    $values = [];
    foreach ($columns as $column) {
        $value = $row[$column] ?? null;
        if ($value === null) {
            return '';
        }
        $values[] = $value;
    }
    $encoded = (string) wp_json_encode($values);
    return strlen($encoded) <= KEY_LIMIT ? $encoded : '';
}

/**
 * The columns and values a stored key stands for, or an empty array when it cannot be read.
 *
 * @return array<string, string>
 */
function key_values(string $key, string $value): array
{
    $columns = key_columns($key);
    if (count($columns) <= 1) {
        return $columns === [] ? [] : [$columns[0] => $value];
    }
    // @mago-expect analysis:mixed-assignment -- Checked to be a list of the right length just below.
    $values = json_decode($value, associative: true);
    if (!is_array($values) || count($values) !== count($columns)) {
        return [];
    }
    return array_combine($columns, array_map('strval', $values));
}

/**
 * A WHERE fragment and its arguments for columns and values.
 *
 * @param array<string, string> $values
 * @return array{0: string, 1: list<string>}
 */
function key_condition(array $values): array
{
    $parts = [];
    $args = [];
    foreach ($values as $column => $value) {
        $parts[] = '%i = %s';
        array_push($args, $column, $value);
    }
    return [implode(' AND ', $parts), $args];
}

/**
 * Columns of a row the journal never keeps: password hashes and reset keys of users, login sessions and
 * application passwords, and the site's secret keys stored as options.
 *
 * @param array<string, string|null> $row
 * @return list<string>
 */
function secret_columns(string $table, array $row): array
{
    $wpdb = db();
    if ($table === $wpdb->users) {
        return array_values(array_intersect(['user_pass', 'user_activation_key'], array_keys($row)));
    }
    // Table => [column naming the row, names that hold a secret, column holding it].
    $rules = [
        $wpdb->usermeta => ['meta_key', SECRET_USER_META, 'meta_value'],
        $wpdb->options => ['option_name', SECRET_OPTIONS, 'option_value'],
    ];
    $rule = $rules[$table] ?? null;
    return $rule !== null && in_array($row[$rule[0]] ?? '', $rule[1], strict: true) ? [$rule[2]] : [];
}

/**
 * The row as the journal stores it: secret columns replaced, and listed so they are left out of what the AI reads.
 * Values that cannot be stored as they are (binary data, broken text) are left out and listed, so one of them costs
 * that value and not the rest of the row: converted, the AI would read a different value.
 *
 * @param array<string, string|null> $row
 * @return array<string, mixed>
 */
function kept_row(string $table, array $row): array
{
    $hidden = secret_columns($table, $row);
    $kept = [];
    $unsaved = [];
    foreach ($row as $column => $value) {
        if (in_array($column, $hidden, strict: true)) {
            $kept[$column] = NOT_KEPT;
            continue;
        }
        if ($value !== null && preg_match('//u', $value) !== 1) {
            $unsaved[] = $column;
            continue;
        }
        $kept[$column] = $value;
    }
    if ($hidden !== []) {
        $kept['__not_kept'] = $hidden;
    }
    if ($unsaved !== []) {
        $kept['__not_saved'] = $unsaved;
    }
    return $kept;
}

/**
 * Columns of a saved row whose value could not be stored.
 *
 * @return list<string>
 */
function not_saved_columns(mixed $old): array
{
    if (!is_array($old) || !is_array($old['__not_saved'] ?? null)) {
        return [];
    }
    return array_values(array_filter($old['__not_saved'], callback: 'is_string'));
}

/** Delete every journal row and every table copy, except the emails still waiting. What they held can no longer be read. */
function clear(): void
{
    if (state()['active']) {
        return;
    }
    $wpdb = db();
    foreach (own_rows(prepared('SHOW TABLES LIKE %s', [$wpdb->esc_like($wpdb->prefix . BACKUP_INFIX) . '%']))
        ?? [] as $row) {
        $backup = array_values($row)[0] ?? '';
        if (str_contains($backup, BACKUP_INFIX)) {
            own_query(prepared('DROP TABLE IF EXISTS %i', [$backup]));
        }
    }
    if (!table_ready()) {
        return;
    }
    own_query(
        held_mail_count() === 0
            ? prepared('TRUNCATE TABLE %i', [table()])
            : prepared('DELETE FROM %i WHERE NOT (' . WAITING_MAIL . ')', [table()]),
    );
}

/**
 * @param list<int|string> $args
 * @return list<array{id: int, execution: string, session: string, grp: string, user_id: int, created: int, kind: string, tbl: string, pk_col: string, pk: string, old_value: mixed, status: string}>
 */
function entries(string $where, array $args): array
{
    $sql = prepared('SELECT * FROM %i WHERE ' . $where . ' ORDER BY id ASC', [table(), ...$args]);
    $rows = $sql === '' ? null : own_rows($sql);
    return array_map(__NAMESPACE__ . '\\normalize_entry', $rows ?? []);
}

/**
 * @param array<string, string|null> $row
 * @return array{id: int, execution: string, session: string, grp: string, user_id: int, created: int, kind: string, tbl: string, pk_col: string, pk: string, old_value: mixed, status: string}
 */
function normalize_entry(array $row): array
{
    $old = $row['old_value'] ?? null;
    return [
        'id' => (int) ($row['id'] ?? 0),
        'execution' => $row['execution'] ?? '',
        'session' => $row['session'] ?? '',
        'grp' => $row['grp'] ?? '',
        'user_id' => (int) ($row['user_id'] ?? 0),
        'created' => (int) ($row['created'] ?? 0),
        'kind' => $row['kind'] ?? '',
        'tbl' => $row['tbl'] ?? '',
        'pk_col' => $row['pk_col'] ?? '',
        'pk' => $row['pk'] ?? '',
        'old_value' => is_string($old) ? json_decode($old, associative: true) : null,
        'status' => $row['status'] ?? '',
    ];
}

/**
 * The current row with the given key, or null when it does not exist.
 *
 * @return array<string, string|null>|null
 */
function current_row(string $table, string $column, string $value): ?array
{
    $values = key_values($column, $value);
    if ($values === []) {
        return null;
    }
    [$where, $args] = key_condition($values);
    $sql = prepared('SELECT * FROM %i WHERE ' . $where . ' LIMIT 1', [$table, ...$args]);
    $rows = $sql === '' ? null : own_rows($sql);
    return $rows[0] ?? null;
}

/**
 * Keys of a table, as rows of SHOW KEYS.
 *
 * @return list<array<string, string|null>>
 */
// @mago-expect lint:no-boolean-flag-parameter -- Unique keys or the primary key.
function table_keys(string $table, bool $unique_only): array
{
    $sql = prepared(
        'SHOW KEYS FROM %i WHERE ' . ($unique_only ? 'Non_unique = 0' : 'Key_name = %s'),
        $unique_only ? [$table] : [$table, 'PRIMARY'],
    );
    return ($sql === '' ? null : own_rows($sql)) ?? [];
}

/**
 * The primary key of a table: its column, or its columns joined by commas. '' when it has none, or when the journal
 * could not store the list.
 */
function primary_key(string $table): string
{
    $cache = &table_cache();
    if (!array_key_exists($table, $cache['keys'])) {
        $columns = array_map(
            static fn(array $key): string => $key['Column_name'] ?? '',
            table_keys($table, unique_only: false),
        );
        $joined = implode(',', $columns);
        $usable = count(explode(',', $joined)) === count($columns) && strlen($joined) <= KEY_COLUMNS_LIMIT;
        $cache['keys'][$table] = $usable ? $joined : '';
    }
    return $cache['keys'][$table];
}

/**
 * The columns of a table, or an empty list when they cannot be read.
 *
 * @return list<string>
 */
function table_columns(string $table): array
{
    $cache = &table_cache();
    if (!array_key_exists($table, $cache['columns'])) {
        $rows = own_rows(prepared('SHOW COLUMNS FROM %i', [$table])) ?? [];
        $cache['columns'][$table] = array_map(static fn(array $row): string => $row['Field'] ?? '', $rows);
    }
    return $cache['columns'][$table];
}

/** A table is about to change shape: read its keys and columns again next time. */
function forget_table(string $table): void
{
    $cache = &table_cache();
    unset($cache['keys'][$table], $cache['columns'][$table]);
}

/**
 * @return array{keys: array<string, string>, columns: array<string, list<string>>}
 */
function &table_cache(): array
{
    /** @var array{keys: array<string, string>, columns: array<string, list<string>>} $cache */
    static $cache = ['keys' => [], 'columns' => []];
    return $cache;
}

/**
 * Delete journal rows older than the retention period, then the oldest rows beyond the size cap, together
 * with the table copies they point to.
 */
function prune(): void
{
    if (!table_ready()) {
        return;
    }
    /** @var mixed $days */
    $days = apply_filters('novamira_journal_retention_days', RETENTION_DAYS);
    $cutoff = time() - ((is_int($days) && $days > 0 ? $days : RETENTION_DAYS) * 86_400);
    /** @var mixed $max */
    $max = apply_filters('novamira_journal_max_rows', MAX_ROWS);
    $max = is_int($max) && $max > 0 ? $max : MAX_ROWS;
    $boundary = own_var(prepared('SELECT id FROM %i ORDER BY id DESC LIMIT 1 OFFSET %d', [table(), $max]));
    $below = $boundary === null ? 0 : (int) $boundary + 1;
    prune_cron_marks($cutoff);
    foreach (entries('(created < %d OR id < %d) AND kind = %s', [$cutoff, $below, 'table']) as $entry) {
        drop_backup($entry['old_value']);
    }
    own_query(prepared('DELETE FROM %i WHERE (created < %d OR id < %d) AND NOT (' . WAITING_MAIL . ')', [
        table(),
        $cutoff,
        $below,
    ]));
}

/**
 * Columns of a saved row that the journal did not keep.
 *
 * @return list<string>
 */
function not_kept(mixed $old): array
{
    if (!is_array($old) || !is_array($old['__not_kept'] ?? null)) {
        return [];
    }
    return array_values(array_filter($old['__not_kept'], callback: 'is_string'));
}

/** Whether a table honours transactions, so that a failed write changed nothing. */
function is_transactional(string $table): bool
{
    /** @var array<string, bool> $cache */
    static $cache = [];
    if (!array_key_exists($table, $cache)) {
        $engine = own_var(prepared('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', [
            $table,
        ]));
        $cache[$table] = strtolower((string) $engine) === 'innodb';
    }
    return $cache[$table];
}

function backup_name(mixed $value): string
{
    $backup = is_array($value) && is_string($value['backup'] ?? null) ? $value['backup'] : '';
    return str_contains($backup, BACKUP_INFIX) ? $backup : '';
}

function drop_backup(mixed $value): void
{
    $backup = backup_name($value);
    if ($backup !== '') {
        own_query(prepared('DROP TABLE IF EXISTS %i', [$backup]));
    }
}
