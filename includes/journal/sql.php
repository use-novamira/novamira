<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * The "query" filter of the journal: reads each write with phpMyAdmin's SQL parser, saves what it will change and
 * copies tables before TRUNCATE, DROP TABLE and ALTER TABLE.
 */

namespace Novamira\Journal;

use Novamira\Confirmations;
use PhpMyAdmin\SqlParser\Components\ArrayObj;
use PhpMyAdmin\SqlParser\Components\Condition;
use PhpMyAdmin\SqlParser\Components\Expression;
use PhpMyAdmin\SqlParser\Components\Limit;
use PhpMyAdmin\SqlParser\Components\OrderKeyword;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Statement;
use PhpMyAdmin\SqlParser\Statements\AlterStatement;
use PhpMyAdmin\SqlParser\Statements\DeleteStatement;
use PhpMyAdmin\SqlParser\Statements\DropStatement;
use PhpMyAdmin\SqlParser\Statements\InsertStatement;
use PhpMyAdmin\SqlParser\Statements\ReplaceStatement;
use PhpMyAdmin\SqlParser\Statements\TruncateStatement;
use PhpMyAdmin\SqlParser\Statements\UpdateStatement;

if (!defined('ABSPATH')) {
    exit();
}

/** Statements that never change data and pass untouched. */
/** What the AI reads in place of a secret (Novamira\Secrets\REDACTED). */
const SECRET_PLACEHOLDER = '[redacted]';

/** How many rows a write holding the placeholder has read one by one, at most. */
const PLACEHOLDER_ROWS = 200;

const READ_KEYWORDS = [
    'select',
    'show',
    'describe',
    'desc',
    'explain',
    'set',
    'start',
    'begin',
    'commit',
    'rollback',
    'savepoint',
    'release',
    'use',
    'analyze',
    'check',
    'checksum',
    'help',
    'do',
    'handler',
];

/** Work on a table that changes none of its rows: on the AI change history it is no reason to stop. */
const MAINTENANCE_KEYWORDS = ['optimize', 'repair', 'analyze', 'check', 'lock', 'unlock', 'flush'];

/**
 * @param mixed $query
 * @return mixed
 */
function on_query($query)
{
    $state = state();
    if (!is_string($query) || $state['internal'] || !$state['active']) {
        return $query;
    }
    if ($state['halted'] !== '') {
        // After a stop no query of this execution runs, reads included: their results would describe a site the
        // code believes it already changed. An empty query makes $wpdb return false without running anything.
        return '';
    }
    flush_pending();
    facts_before_query(state()['facts']);
    // The history is what the AI restores from, so its code may read it, for a backup too, but never change it.
    // Another plugin's ability is only recorded, and the journal does not record itself.
    if (Confirmations\sql_names_table($query, table())) {
        if (
            in_array(first_keyword($query), [...READ_KEYWORDS, ...MAINTENANCE_KEYWORDS], strict: true)
            || !Confirmations\ability_is_ours($state['ability'])
        ) {
            return $query;
        }
        halt(
            "Novamira stopped this execution: it tried to change Novamira's AI change history. Nothing from this query ran.",
        );
    }
    if (in_array(first_keyword($query), READ_KEYWORDS, strict: true)) {
        return $query;
    }
    $setting = changes_novamira_setting($query);
    if ($setting !== null) {
        halt(
            "Novamira stopped this execution: the AI's code tried to change one of Novamira's own settings, "
            . $setting
            . '. A person changes those from the Novamira pages. Nothing from this query ran.',
        );
    }
    // The AI restores a table from its copy with SQL of its own: that runs, listed as having no previous values.
    if (str_contains($query, db()->prefix . BACKUP_INFIX)) {
        return unverified($query);
    }
    if (is_untracked_option($query)) {
        return $query;
    }
    // Reading SQL costs about 50-100 ms and 5-13 MB per MB of query: past the limit the write runs unrecorded, so the
    // history never stops an execution halfway for lack of time or memory.
    if (strlen($query) > parse_limit()) {
        stop_unread_placeholder_write($query);
        return unverified($query);
    }
    try {
        $parser = new Parser($query);
    } catch (\Throwable) {
        return unverified($query);
    }
    if ($parser->errors !== [] || count($parser->statements) !== 1) {
        return unverified($query);
    }
    return handle_statement($parser->statements[0], $query);
}

/**
 * The Novamira option the AI's own code is writing, if any: its approval settings, waiting requests, features, read
 * mode and keys all live there, so changing them would turn the checks off from inside. Only the row's name counts:
 * a value that mentions Novamira, such as the plugin list WordPress rewrites after delete_plugins(), is not a Novamira
 * setting. Novamira's own code writing them during the same execution goes on. This stops mistakes, not code written
 * to deceive.
 */
function changes_novamira_setting(string $query): ?string
{
    if (
        stripos($query, needle: 'novamira') === false
        || !str_contains($query, db()->options)
        || in_array(first_keyword($query), MAINTENANCE_KEYWORDS, strict: true)
    ) {
        return null;
    }
    $name = novamira_option_named($query);
    if ($name === null || !written_by_ai_code()) {
        return null;
    }
    return $name;
}

/**
 * The first Novamira option this write names, as the AI should read it ("the option novamira_x"), or null. Names are
 * read where SQL puts them: an option_name compared with =, IN or LIKE, a SET of option_name, or the option_name
 * column of an INSERT or REPLACE. Words inside the values, and in ordinary comments, do not count. Transients are a
 * cache: clearing or rewriting them, Novamira's included, decides nothing. Changing only the autoload flag changes no
 * value.
 */
function novamira_option_named(string $query): ?string
{
    $literals = [];
    $bare = masked_query($query, $literals);
    if ($bare === null) {
        return null;
    }
    // Outside every quoted value the word names a table or column of Novamira's, never a row of somebody else's.
    if (stripos($bare, needle: 'novamira') !== false) {
        return 'in this query';
    }
    if (preg_match('/^\s*UPDATE\b[^\x00]*?\bSET\s+`?autoload`?\s*=\s*\x00\d+\x00\s*(?:WHERE\b|$)/is', $bare) === 1) {
        return null;
    }
    $names = [];
    $compared = [];
    preg_match_all(
        '/`?\boption_name`?\s*(?:=|<=>|\bLIKE\b|\bIN\s*\()\s*((?:\x00\d+\x00\s*,?\s*)+)/i',
        $bare,
        $compared,
    );
    foreach ($compared[1] as $list) {
        $names = [...$names, ...literals_in($list, $literals)];
    }
    $names = [...$names, ...inserted_option_names($bare, $literals)];
    foreach ($names as $name) {
        if (stripos(ltrim($name, characters: '%'), needle: 'novamira') === 0) {
            return 'the option ' . $name;
        }
    }
    return null;
}

/**
 * The query with each quoted value replaced by a numbered marker (its text kept in $literals) and ordinary comments
 * removed. A MySQL comment that runs (/*! … *\/) keeps its content. Null if the query cannot be read this way.
 *
 * @param list<string> $literals
 */
function masked_query(string $query, array &$literals): ?string
{
    // Possessive: values of hundreds of kilobytes must not run the pattern out of stack.
    $bare = preg_replace_callback(
        '/\'(?:[^\'\\\\]++|\\\\.|\'\')*+\'|"(?:[^"\\\\]++|\\\\.|"")*+"/s',
        static function (array $match) use (&$literals): string {
            $literals[] = stripcslashes(substr($match[0], offset: 1, length: -1));
            return "\0" . (count($literals) - 1) . "\0";
        },
        $query,
    );
    if ($bare === null) {
        return null;
    }
    $bare = (string) preg_replace('~/\*!\d*~', replacement: ' ', subject: $bare);
    $bare = (string) preg_replace('~/\*.*?\*/~s', replacement: ' ', subject: $bare);
    return (string) preg_replace('~(?:--\s|#)[^\n]*~', replacement: ' ', subject: $bare);
}

/**
 * An UPDATE or DELETE on the options table that names no option, such as one by option_id after a lookup: the rows it
 * will change are read first, and if one is Novamira's the AI's code stops before the query runs.
 *
 * @param UpdateStatement|DeleteStatement $statement
 */
function stop_novamira_rows(Statement $statement, Expression $target): void
{
    if ((string) $target->table !== db()->options || !countable_without_side_effects($statement)) {
        return;
    }
    if (!written_by_ai_code()) {
        return;
    }
    $name = own_var(
        'SELECT option_name FROM ('
        . matching_sql($statement, $target, MEASURE_LIMIT, columns: '`option_name`')
        . ") AS novamira_rows WHERE option_name LIKE 'novamira%' LIMIT 1",
    );
    if ($name === null || $name === '') {
        return;
    }
    halt(
        "Novamira stopped this execution: the AI's code tried to change one of Novamira's own settings, the option "
        . $name
        . '. A person changes those from the Novamira pages. Nothing from this query ran.',
    );
}

/**
 * @param list<string> $literals
 * @return list<string>
 */
function literals_in(string $bare, array $literals): array
{
    $found = [];
    preg_match_all('/\x00(\d+)\x00/', $bare, $found);
    return array_values(array_map(static fn(string $i): string => $literals[(int) $i] ?? '', $found[1]));
}

/**
 * The option_name values of an INSERT or REPLACE into the options table, whatever the order of its columns.
 *
 * @param list<string> $literals
 * @return list<string>
 */
function inserted_option_names(string $bare, array $literals): array
{
    $head = [];
    if (
        preg_match(
            '/^\s*(?:INSERT|REPLACE)\b[^(]*?\bINTO\s+`?'
            . preg_quote(db()->options, delimiter: '/')
            . '`?\s*\(([^)]*)\)\s*VALUES?\s*(.*)$/is',
            $bare,
            $head,
        ) !== 1
    ) {
        return [];
    }
    $columns = array_map(
        static fn(string $c): string => strtolower(trim($c, characters: " \t\n\r`")),
        explode(',', $head[1]),
    );
    $index = array_search('option_name', $columns, strict: true);
    if ($index === false) {
        return [];
    }
    $names = [];
    foreach (value_rows($head[2]) as $row) {
        $names = [...$names, ...literals_in($row[$index] ?? '', $literals)];
    }
    return $names;
}

/**
 * The rows of a VALUES list, each split into its fields, in a query whose quoted values are already placeholders.
 *
 * @return list<list<string>>
 */
function value_rows(string $values): array
{
    // One row after the other from the start: whatever follows them, such as ON DUPLICATE KEY UPDATE, is not a row.
    $tuples = [];
    preg_match_all('/\G\s*,?\s*\((?<in>(?:[^()]++|(?<p>\((?:[^()]++|(?&p))*\)))*)\)/', $values, $tuples);
    $rows = [];
    foreach ($tuples[1] as $inner) {
        $fields = [];
        // A field ends at a comma outside parentheses: NOW() or CONCAT(a, b) stay whole.
        preg_match_all('/(?:[^,()]++|(?<p>\((?:[^()]++|(?&p))*\)))+/', $inner, $fields);
        $rows[] = array_values($fields[0]);
    }
    return $rows;
}

/**
 * Whether the query comes from the code execute-php evaluates. Past WordPress's own files, the first file in the call
 * stack says who asked: the evaluated code, or a plugin, Novamira included.
 */
function written_by_ai_code(): bool
{
    $core = [wp_normalize_path(ABSPATH . WPINC) . '/', wp_normalize_path(ABSPATH . 'wp-admin') . '/'];
    $past_wpdb = false;
    /** @var list<array<string, mixed>> $frames */
    $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
    foreach ($frames as $frame) {
        if (!$past_wpdb && !is_wpdb_frame($frame)) {
            continue;
        }
        $past_wpdb = true;
        $file = is_string($frame['file'] ?? null) ? wp_normalize_path($frame['file']) : '';
        if ($file === '' || str_starts_with($file, $core[0]) || str_starts_with($file, $core[1])) {
            continue;
        }
        return str_contains($file, "eval()'d code");
    }
    return false;
}

/** @param array<string, mixed> $frame */
function is_wpdb_frame(array $frame): bool
{
    $class = is_string($frame['class'] ?? null) ? $frame['class'] : '';
    return $class === 'wpdb' || $class !== '' && is_subclass_of($class, class: 'wpdb');
}

/** The first SQL keyword of a query, lowercased, skipping leading comments and parentheses. */
function first_keyword(string $query): string
{
    $match = [];
    return preg_match('/^\s*(?:\/\*.*?\*\/\s*)*\(?\s*([a-z]+)/is', $query, $match) === 1 ? strtolower($match[1]) : '';
}

/**
 * Option rows the journal leaves alone: cache entries (transients), and its own and the confirmation queue's records.
 * Only a write that names one such row, or a LIKE on one such prefix, qualifies: a word somewhere in a query proves
 * nothing, and a real setting deleted next to a transient must be recorded.
 */
function is_untracked_option(string $query): bool
{
    $options = db()->options;
    if (!str_contains($query, $options)) {
        return false;
    }
    $names = '(?:_site)?_transient_|novamira_confirmation_|novamira_journal_';
    $escaped = str_replace('_', replace: '\\\\{0,2}_', subject: $names);
    $table = '`?' . preg_quote($options, delimiter: '/') . '`?';
    if (
        preg_match(
            '/^\s*INSERT\s+(?:IGNORE\s+)?INTO\s+'
            . $table
            . '\s*\(\s*`?option_name`?\s*[,)][^)]*\)?\s*VALUES\s*\(\s*\'(?:'
            . $names
            . ')/i',
            $query,
        ) === 1
    ) {
        // The first value is the option's name only because option_name is the first column. And only one row: the
        // next ones of a longer list could be real settings.
        $bare = (string) preg_replace('/\'(?:[^\'\\\\]++|\\\\.)*+\'/s', replacement: "''", subject: $query);
        return preg_match('/\)\s*,\s*\(/', $bare) !== 1;
    }
    $found = [];
    if (
        preg_match(
            '/^\s*(?:UPDATE\s+'
            . $table
            . '\s+SET\b.*|DELETE\s+FROM\s+'
            . $table
            . '\s+)\bWHERE\s+`?option_name`?\s*(=|LIKE)\s*\'(?:'
            . $escaped
            . ')(?:[^\'\\\\]++|\\\\.)*+\'(.*)$/is',
            $query,
            $found,
        ) !== 1
    ) {
        return false;
    }
    // Further conditions can only narrow what the first one selects; an OR could reach any row.
    $rest = (string) preg_replace('/\'(?:[^\'\\\\]++|\\\\.)*+\'/s', replacement: "''", subject: $found[2]);
    return preg_match('/\bOR\b|\|\|/i', $rest) !== 1;
}

function handle_statement(Statement $statement, string $query): string
{
    if ($statement instanceof UpdateStatement) {
        return handle_update($statement, $query);
    }
    if ($statement instanceof DeleteStatement) {
        $target =
            $statement->columns === null || $statement->columns === []
                ? single_target($statement->from ?? [], $statement->join ?? $statement->using)
                : null;
        if ($target === null) {
            return unverified($query);
        }
        stop_novamira_rows($statement, $target);
        return record_rows('delete', $statement, $target, $query);
    }
    if ($statement instanceof InsertStatement || $statement instanceof ReplaceStatement) {
        return record_insert($statement, $query);
    }
    if ($statement instanceof TruncateStatement) {
        copy_table(table_name($statement->table), operation: 'TRUNCATE');
        return $query;
    }
    if ($statement instanceof DropStatement || $statement instanceof AlterStatement) {
        return copy_dropped_or_altered($statement, $query);
    }
    if (first_keyword($query) === 'create') {
        return $query;
    }
    return unverified($query);
}

function handle_update(UpdateStatement $statement, string $query): string
{
    $target = single_target($statement->tables, $statement->join);
    if ($target === null) {
        return unverified($query);
    }
    stop_novamira_rows($statement, $target);
    stop_placeholder_overwrite($statement, $target, $query);
    return record_rows('update', $statement, $target, $query);
}

/**
 * The AI reads secrets as "[redacted]", and the history keeps some columns only as a note that it did not keep them.
 * Writing either back loses the real value: a key shown in a page, a setting a plugin needs, a password. So an UPDATE
 * that would do that stops before it runs, in every mode. A text that merely holds the word, on a value with
 * no secret in it, loses nothing and goes on, and so does a new row.
 */
function stop_placeholder_overwrite(UpdateStatement $statement, Expression $target, string $query): void
{
    if (!selection_is_repeatable($statement)) {
        // Reading its rows first could change what the write does: record_rows() decides what happens to it.
        return;
    }
    if (!countable_without_side_effects($statement)) {
        return;
    }
    foreach ([SECRET_PLACEHOLDER, NOT_KEPT] as $placeholder) {
        if (!str_contains($query, $placeholder)) {
            continue;
        }
        stop_overwrite_with($placeholder, $statement, $target);
    }
}

/**
 * A write too large to read can still put the placeholder over a real value: a whole option or page rewritten from
 * what the AI read, the loss stop_placeholder_overwrite() prevents. With no reading there is no telling, so an UPDATE
 * of Novamira's abilities that carries the placeholder stops before it runs. Another plugin's ability is only
 * recorded.
 */
function stop_unread_placeholder_write(string $query): void
{
    $keyword = first_keyword($query);
    $replaces =
        $keyword === 'update'
        || $keyword === 'replace'
        || $keyword === 'insert' && stripos($query, needle: 'ON DUPLICATE KEY UPDATE') !== false;
    if (!$replaces || !Confirmations\ability_is_ours(state()['ability'])) {
        return;
    }
    foreach ([SECRET_PLACEHOLDER, NOT_KEPT] as $placeholder) {
        if (!str_contains($query, $placeholder)) {
            continue;
        }
        halt(sprintf(
            'Novamira stopped this execution before a write larger than it can read: it contains "%s", the placeholder Novamira shows in place of secrets, and Novamira cannot check whether it would write it over a real value. Nothing from this query ran. Write only what you need to change, keeping the placeholder out of it.',
            $placeholder,
        ));
    }
}

function stop_overwrite_with(string $placeholder, UpdateStatement $statement, Expression $target): void
{
    // Another plugin's ability is only recorded.
    if (!Confirmations\ability_is_ours(state()['ability'])) {
        return;
    }
    foreach ($statement->set ?? [] as $set) {
        $column = set_column_name($set->column);
        if ($column === '' || !str_contains($set->value, $placeholder)) {
            continue;
        }
        $quoted = '`' . str_replace('`', replace: '``', subject: $column) . '`';
        $loses = $placeholder === NOT_KEPT
            ? holds_any_value($statement, $target, $quoted, $placeholder)
            : holds_a_secret($statement, $target, $quoted);
        if ($loses) {
            halt(sprintf(
                'Novamira stopped this execution before an UPDATE on %s: the new value of %s contains "%s", the placeholder Novamira shows in place of secrets, and the row holds a real value there. Writing it would lose that value. Nothing from this query ran. Write only what you need to change, keeping the placeholder out of it.',
                (string) $target->table,
                $column,
                $placeholder,
            ));
        }
    }
}

/** The name of the column a SET clause writes, without its table or quotes. */
function set_column_name(string $column): string
{
    return trim(substr((string) strrchr('.' . $column, needle: '.'), offset: 1), characters: ' `');
}

/**
 * Whether a value this write replaces holds something the AI read as "[redacted]". The damage this stops comes from
 * a few rows read and written back: the same text put on more rows than can be checked is a deliberate write, such
 * as anonymizing comments, so it runs and the summary says it was not checked. If the rows cannot be read, or the
 * site has no secrets module, it falls back on the stricter check: any value without the placeholder.
 */
function holds_a_secret(UpdateStatement $statement, Expression $target, string $quoted): bool
{
    $size = measure_rows($statement, $target, PLACEHOLDER_ROWS + 1);
    if ($size !== null && ($size['rows'] > PLACEHOLDER_ROWS || $size['bytes'] > RECORD_BYTE_LIMIT)) {
        $state = &state();
        $state['unchecked']++;
        return false;
    }
    $rows = function_exists('Novamira\\Secrets\\redact_string') && $size !== null
        ? own_rows(matching_sql($statement, $target, PLACEHOLDER_ROWS + 1, columns: $quoted . ' AS checked_value'))
        : null;
    if ($rows === null) {
        return holds_any_value($statement, $target, $quoted, SECRET_PLACEHOLDER);
    }
    foreach ($rows as $row) {
        $value = $row['checked_value'] ?? '';
        if (\Novamira\Secrets\redact_string($value) !== $value) {
            return true;
        }
    }
    return false;
}

/** Whether a value this write replaces is neither empty nor already holding the placeholder. */
function holds_any_value(UpdateStatement $statement, Expression $target, string $quoted, string $placeholder): bool
{
    $real = '(COALESCE(' . $quoted . ", '') <> '' AND COALESCE(" . $quoted . ", '') NOT LIKE '%" . $placeholder . "%')";
    $found = own_var(
        'SELECT COALESCE(MAX(real_value), 0) FROM ('
        . matching_sql($statement, $target, RECORD_ROW_LIMIT, columns: $real . ' AS real_value')
        . ') AS novamira_placeholder',
    );
    return (int) $found === 1;
}

/**
 * The one table an UPDATE or DELETE changes, or null for joins, several tables or another database.
 *
 * @param mixed $tables
 * @param mixed $join
 */
function single_target($tables, $join): ?Expression
{
    if (!is_array($tables) || count($tables) !== 1 || $join !== null && $join !== []) {
        return null;
    }
    // @mago-expect analysis:mixed-assignment -- Checked to be an Expression just below.
    $target = $tables[0];
    if (!$target instanceof Expression || !is_string($target->table) || $target->table === '') {
        return null;
    }
    if (
        $target->database !== null
        && $target->database !== ''
        && (!defined('DB_NAME') || $target->database !== constant('DB_NAME'))
    ) {
        return null;
    }
    return $target;
}

function table_name(mixed $expression): string
{
    return $expression instanceof Expression && is_string($expression->table) ? $expression->table : '';
}

/**
 * Save the rows an UPDATE or DELETE will change. The query itself always runs exactly as written: rewriting it
 * could change what it does (ORDER BY, IGNORE, LIMIT, the order rows are updated in). How much it would change
 * is measured in the database first, so no row is loaded until it is known to fit.
 *
 * What is decided is decided before the code starts: while it runs, a change that cannot be saved still runs, and is
 * listed as having no previous values saved.
 *
 * @param UpdateStatement|DeleteStatement $statement
 */
function record_rows(string $kind, Statement $statement, Expression $target, string $query): string
{
    $table = (string) $target->table;
    $state = &state();
    if (!selection_is_repeatable($statement)) {
        return unverified($query);
    }
    $budget = record_budget();
    $size = measure_rows($statement, $target, $budget + 1);
    if ($size === null) {
        return unverified($query);
    }
    if ($size['rows'] === 0) {
        return $query;
    }
    if ($size['rows'] > $budget || $size['bytes'] > byte_budget()) {
        return unverified($query);
    }
    $changed = $statement instanceof UpdateStatement ? changed_columns($statement) : null;
    $saved = save_matching_rows($kind, $statement, $target, $size, $changed);
    if ($saved === null) {
        forget_unfinished_rows($table, saved_from());
        return unverified($query);
    }
    if (lost_a_value()) {
        unverified($query);
    }
    $state['counts'][$table] = ($state['counts'][$table] ?? 0) + $saved;
    return $query;
}

/** How many rows of one write the history saves. */
function record_budget(): int
{
    return state()['confirming'] ? CONFIRMED_ROW_LIMIT : RECORD_ROW_LIMIT;
}

/** How many bytes of one write, or of one table's copy, the history saves. */
function byte_budget(): int
{
    return state()['confirming'] ? CONFIRMED_BYTE_LIMIT : RECORD_BYTE_LIMIT;
}

/**
 * Whether a SELECT finds the same rows the write will change, without side effects of its own: no function,
 * variable or subquery in the condition, and no LIMIT that lets the server pick any rows it likes.
 *
 * @param UpdateStatement|DeleteStatement $statement
 */
function selection_is_repeatable(Statement $statement): bool
{
    if (!countable_without_side_effects($statement)) {
        return false;
    }
    return !$statement->limit instanceof Limit || is_array($statement->order) && $statement->order !== [];
}

/**
 * Rows, total bytes and the largest row a write would touch, computed in the database without loading any row.
 *
 * @param UpdateStatement|DeleteStatement $statement
 * @return array{rows: int, bytes: int, largest: int}|null
 */
function measure_rows(Statement $statement, Expression $target, int $limit): ?array
{
    $columns = table_columns((string) $target->table);
    if ($columns === []) {
        return null;
    }
    $prefix = $target->alias !== null && $target->alias !== '' ? '`' . $target->alias . '`.' : '';
    $lengths = array_map(
        static fn(string $column): string => (
            'COALESCE(LENGTH(' . $prefix . '`' . str_replace('`', replace: '``', subject: $column) . '`), 0)'
        ),
        $columns,
    );
    $rows = own_rows(
        'SELECT COUNT(*) AS n, COALESCE(SUM(b), 0) AS total, COALESCE(MAX(b), 0) AS largest FROM ('
        . matching_sql($statement, $target, $limit, columns: implode(' + ', $lengths) . ' AS b')
        . ') AS novamira_size',
    );
    if ($rows === null || $rows === []) {
        return null;
    }
    return [
        'rows' => (int) ($rows[0]['n'] ?? 0),
        'bytes' => (int) ($rows[0]['total'] ?? 0),
        'largest' => (int) ($rows[0]['largest'] ?? 0),
    ];
}

/**
 * Save the rows a write will change, one page at a time so memory stays bounded whatever their size.
 *
 * @param UpdateStatement|DeleteStatement $statement
 * @param array{rows: int, bytes: int, largest: int} $size
 * @param list<string>|null $changed The columns the write changes; null when the whole row can go.
 * @return int|null How many rows were saved, or null when they could not be saved.
 */
function save_matching_rows(string $kind, Statement $statement, Expression $target, array $size, ?array $changed): ?int
{
    lost_a_value(false);
    // Set before anything can fail: a stale value would make the caller forget the rows of an earlier write.
    saved_from(0);
    $table = (string) $target->table;
    $pk_col = primary_key($table);
    $page = max(1, min(CHUNK, intdiv(RECORD_PAGE_BYTES, max(1, $size['largest']))));
    $paged = $size['rows'] > $page;
    if ($paged && $pk_col === '') {
        // Without a key the pages could overlap or miss rows.
        return null;
    }
    $columns = $target->alias !== null && $target->alias !== '' ? '`' . $target->alias . '`.*' : '*';
    $ids = [];
    $saved = 0;
    while ($saved < $size['rows']) {
        $rows = own_rows(matching_sql($statement, $target, min($page, $size['rows'] - $saved), $columns, [
            'offset' => $saved,
            'tie' => $paged ? $pk_col : '',
        ]));
        if ($rows === null) {
            return null;
        }
        if ($rows === []) {
            break;
        }
        $page_ids = save_rows($kind, $table, $pk_col, $rows, $changed);
        if ($page_ids === null) {
            return null;
        }
        array_push($ids, ...$page_ids);
        $saved += count($rows);
    }
    if ($ids !== []) {
        $state = &state();
        $state['pending'][] = [
            'kind' => 'change',
            'table' => $table,
            'pk_col' => $pk_col,
            'pks' => $ids,
            'from' => saved_from(),
            'max_affected' => $saved,
        ];
    }
    return $saved;
}

/** Rows saved for a write that will not be recorded after all: they describe a change that never completed. */
function forget_unfinished_rows(string $table, int $from): void
{
    if ($from === 0) {
        return;
    }
    own_query(prepared("DELETE FROM %i WHERE execution = %s AND tbl = %s AND kind IN ('update', 'delete') AND id >= %d", [
        table(),
        state()['execution'],
        $table,
        $from,
    ]));
}

/**
 * Save rows about to change.
 *
 * @param list<array<string, string|null>> $rows
 * @param list<string>|null $changed The columns the write changes; null when the whole row can go.
 * @return list<string>|null Their primary keys, or null when they could not be saved.
 */
function save_rows(string $kind, string $table, string $pk_col, array $rows, ?array $changed): ?array
{
    $execution = state()['execution'];
    $entries = [];
    $ids = [];
    foreach ($rows as $row) {
        $kept = kept_row($table, $row);
        if (write_loses_value($kept, $changed)) {
            lost_a_value(true);
        }
        note_fact($kind, $table, $row);
        $pk = $pk_col !== '' ? row_key($pk_col, $row) : '';
        $entries[] = [
            'execution' => $execution,
            'kind' => $kind,
            'tbl' => $table,
            'pk_col' => $pk_col,
            'pk' => $pk,
            'old_value' => $kept,
        ];
        if ($pk !== '') {
            $ids[] = $pk;
        }
    }
    return add_entries($entries) ? $ids : null;
}

/**
 * The columns an UPDATE writes, lowercase.
 *
 * @return list<string>
 */
function changed_columns(UpdateStatement $statement): array
{
    $columns = [];
    foreach ($statement->set ?? [] as $set) {
        $columns[] = strtolower(set_column_name($set->column));
    }
    return $columns;
}

/**
 * Whether this write changes or deletes a value the history could not store. A column the write leaves alone stays
 * in the database, so nothing is lost.
 *
 * @param array<string, mixed> $kept
 * @param list<string>|null $changed The columns written; null when the whole row can go.
 */
function write_loses_value(array $kept, ?array $changed): bool
{
    $unsaved = not_saved_columns($kept);
    if ($unsaved === [] || $changed === null) {
        return $unsaved !== [];
    }
    return array_intersect(array_map('strtolower', $unsaved), $changed) !== [];
}

/** Whether the rows saved for the current write include a value that write destroys. */
function lost_a_value(?bool $set = null): bool
{
    /** @var bool $lost */
    static $lost = false;
    if ($set !== null) {
        $lost = $set;
    }
    return $lost;
}

/**
 * How many rows the statement will change, counting at most $limit, or null when they cannot be counted.
 *
 * @param UpdateStatement|DeleteStatement $statement
 */
function count_rows(Statement $statement, Expression $target, int $limit): ?int
{
    $count = own_var(
        'SELECT COUNT(*) FROM (' . matching_sql($statement, $target, $limit, columns: '1') . ') AS novamira_count',
    );
    return $count === null ? null : (int) $count;
}

/**
 * A SELECT of the rows an UPDATE or DELETE matches, with the same WHERE, ORDER BY and LIMIT. When rows are read in
 * pages, "tie" orders them fully and "offset" skips the pages already read.
 *
 * @param UpdateStatement|DeleteStatement $statement
 * @param array{offset: int, tie: string} $page
 */
function matching_sql(
    Statement $statement,
    Expression $target,
    int $limit,
    string $columns,
    array $page = ['offset' => 0, 'tie' => ''],
): string {
    ['offset' => $offset, 'tie' => $tie] = $page;
    $sql = 'SELECT ' . $columns . ' FROM ' . Expression::build($target);
    if (is_array($statement->where) && $statement->where !== []) {
        $sql .= ' WHERE ' . Condition::build($statement->where);
    }
    $order = is_array($statement->order) && $statement->order !== [] ? OrderKeyword::build($statement->order) : '';
    if ($tie !== '') {
        $quoted = array_map(
            static fn(string $column): string => '`' . str_replace('`', replace: '``', subject: $column) . '`',
            key_columns($tie),
        );
        $order .= ($order !== '' ? ', ' : '') . implode(', ', $quoted);
    }
    if ($order !== '') {
        $sql .= ' ORDER BY ' . $order;
    }
    $cap = $statement->limit instanceof Limit && is_numeric($statement->limit->rowCount)
        ? min((int) $statement->limit->rowCount, $limit)
        : $limit;
    return $sql . ' LIMIT ' . ($offset > 0 ? $offset . ', ' : '') . max(0, $cap);
}

/**
 * INSERT and REPLACE: rows replaced or updated through a unique key are saved first; new rows are recorded
 * once the query has run, from the insert id.
 *
 * @param InsertStatement|ReplaceStatement $statement
 */
// @mago-expect lint:cyclomatic-complexity
// @mago-expect lint:halstead
function record_insert(Statement $statement, string $query): string
{
    $table = table_name($statement->into?->dest);
    $values = is_array($statement->values) ? $statement->values : [];
    if ($table === '' || $statement->select !== null || $values === []) {
        return unverified($query);
    }
    $state = &state();
    $count = count($values);
    $pk_col = primary_key($table);
    $into = $statement->into;
    $columns = $into !== null && is_array($into->columns) ? array_values($into->columns) : [];
    $upsert = $statement instanceof ReplaceStatement || $statement->onDuplicateSet !== null;
    $match = $upsert ? unique_column($table, $columns) : '';
    if ($upsert && ($match === '' || $pk_col === '' || count(applicable_unique_keys($table, $columns)) !== 1)) {
        // A second unique key can replace or update a row the journal did not save.
        return unverified($query);
    }
    if ($match !== '') {
        $keys = upsert_keys($values, array_search($match, $columns, strict: true));
        if ($keys === null) {
            return unverified($query);
        }
        $existing = rows_by_keys($table, $match, $keys);
        lost_a_value(false);
        $saved_ids = save_rows('update', $table, $pk_col, array_values($existing), changed: null);
        if ($saved_ids === null) {
            return unverified($query);
        }
        if (lost_a_value()) {
            unverified($query);
        }
        if ($saved_ids !== []) {
            $state['pending'][] = ['kind' => 'change', 'table' => $table, 'pk_col' => $pk_col, 'pks' => $saved_ids];
        }
        $new = array_values(array_diff($keys, array_map('strval', array_keys($existing))));
        if ($new !== []) {
            $state['pending'][] = ['kind' => 'insert_match', 'table' => $table, 'pk_col' => $match, 'pks' => $new];
        }
    }
    $composite = !$upsert && count(key_columns($pk_col)) > 1;
    if ($composite && !pend_composite_insert($table, $pk_col, $values, $columns)) {
        return unverified($query);
    }
    if ($match === '' && !$composite) {
        $state['pending'][] = ['kind' => 'insert', 'table' => $table, 'pk_col' => $pk_col, 'rows' => $count];
    }
    $state['counts'][$table] = ($state['counts'][$table] ?? 0) + $count;
    return $query;
}

/**
 * The value of the unique column in every tuple of an upsert, or null when one cannot be read.
 *
 * @param array<array-key, mixed> $values
 * @return list<string>|null
 */
function upsert_keys(array $values, int|string|false $position): ?array
{
    if (!is_int($position)) {
        return null;
    }
    $keys = [];
    // @mago-expect analysis:mixed-assignment -- Parser tuples; checked to be ArrayObj below.
    foreach ($values as $tuple) {
        $tuple_values = $tuple instanceof ArrayObj ? $tuple->values : [];
        if (!array_key_exists($position, $tuple_values)) {
            return null;
        }
        $keys[] = $tuple_values[$position];
    }
    return $keys;
}

/**
 * No insert id tells the rows of a composite key apart, but the query itself names their keys. Rows already there
 * are not recorded as inserted: an INSERT IGNORE leaves them alone. Returns false when the keys
 * cannot be read from the query.
 *
 * @param array<array-key, mixed> $values
 * @param list<mixed> $columns
 */
function pend_composite_insert(string $table, string $pk_col, array $values, array $columns): bool
{
    $keys = composite_insert_keys($values, $columns, key_columns($pk_col));
    if ($keys === null) {
        return false;
    }
    $new = array_values(array_diff($keys, array_map('strval', array_keys(rows_by_keys($table, $pk_col, $keys)))));
    if ($new !== []) {
        $state = &state();
        $state['pending'][] = ['kind' => 'insert_keys', 'table' => $table, 'pk_col' => $pk_col, 'pks' => $new];
    }
    return true;
}

/**
 * The composite key of every tuple of an INSERT, or null when the insert does not set all its columns.
 *
 * @param array<array-key, mixed> $values
 * @param list<mixed> $columns
 * @param list<string> $key_columns
 * @return list<string>|null
 */
function composite_insert_keys(array $values, array $columns, array $key_columns): ?array
{
    $positions = [];
    foreach ($key_columns as $column) {
        $position = array_search($column, $columns, strict: true);
        if (!is_int($position)) {
            return null;
        }
        $positions[] = $position;
    }
    $keys = [];
    // @mago-expect analysis:mixed-assignment -- Parser tuples; checked to be ArrayObj below.
    foreach ($values as $tuple) {
        $tuple_values = $tuple instanceof ArrayObj ? $tuple->values : [];
        $key = [];
        foreach ($positions as $position) {
            if (!array_key_exists($position, $tuple_values)) {
                return null;
            }
            $key[] = $tuple_values[$position];
        }
        $encoded = (string) wp_json_encode($key);
        if (strlen($encoded) > KEY_LIMIT) {
            return null;
        }
        $keys[] = $encoded;
    }
    return $keys;
}

/**
 * The unique keys (primary key included) whose every column an insert sets, so a row can collide on them.
 *
 * @param list<mixed> $columns
 * @return list<list<string>>
 */
function applicable_unique_keys(string $table, array $columns): array
{
    $by_key = [];
    foreach (table_keys($table, unique_only: true) as $key) {
        $by_key[$key['Key_name'] ?? ''][] = $key['Column_name'] ?? '';
    }
    $applicable = [];
    foreach ($by_key as $key_columns) {
        if (array_diff($key_columns, $columns) !== []) {
            continue;
        }
        $applicable[] = $key_columns;
    }
    return $applicable;
}

/**
 * The first column of the insert that alone forms a unique key (or the primary key) of the table.
 *
 * @param list<mixed> $columns
 */
function unique_column(string $table, array $columns): string
{
    foreach (applicable_unique_keys($table, $columns) as $key_columns) {
        if (count($key_columns) === 1) {
            return $key_columns[0];
        }
    }
    return '';
}

/**
 * Record what the previous query did: the new state of the rows saved before it, and the rows it inserted.
 * Runs at the start of the next query and when the execution ends, while $wpdb still holds that query's
 * insert id.
 */
function flush_pending(): void
{
    $state = &state();
    if ($state['pending'] === []) {
        return;
    }
    $pending = $state['pending'];
    $state['pending'] = [];
    // What $wpdb reports for the previous query, read before any query of the journal's own replaces it.
    $wpdb = db();
    $insert_id = (int) $wpdb->insert_id;
    $failed = $wpdb->last_error !== '';
    $affected = (int) $wpdb->rows_affected;
    foreach ($pending as $item) {
        if ($item['kind'] === 'whole_table') {
            // A stop means the query never ran; a failure means it changed nothing.
            if ($failed || state()['halted'] !== '') {
                forget_table_copy($item);
            }
            continue;
        }
        if ($item['kind'] === 'change') {
            record_change($item, $failed, $affected);
            continue;
        }
        if ($item['kind'] === 'insert_match') {
            record_inserted(
                $item['table'],
                primary_key($item['table']),
                rows_by_keys($item['table'], $item['pk_col'], $item['pks'] ?? []),
            );
            continue;
        }
        if (!$failed) {
            record_new_rows($item, $insert_id, $affected);
        }
    }
}

/**
 * A whole-table operation that did not happen: its copy goes, and its rows do not count.
 *
 * @param array{table: string, rows?: int, from?: int, backup?: string, ...} $item
 */
function forget_table_copy(array $item): void
{
    $state = &state();
    $rows = $item['rows'] ?? 0;
    $table = $item['table'];
    drop_backup(['backup' => $item['backup'] ?? '']);
    own_query(prepared('DELETE FROM %i WHERE id = %d', [table(), $item['from'] ?? 0]));
    unset($state['facts']['tables'][$table]);
    $left = ($state['counts'][$table] ?? 0) - $rows;
    if ($left > 0) {
        $state['counts'][$table] = $left;
        return;
    }
    unset($state['counts'][$table]);
}

/**
 * What became of a write that saved its rows first.
 *
 * @param array{table: string, pk_col: string, pks?: list<string>, from?: int, max_affected?: int, ...} $item
 */
// @mago-expect lint:no-boolean-flag-parameter -- Whether the write failed, as $wpdb reported it.
function record_change(array $item, bool $failed, int $affected): void
{
    if (($failed || $affected === 0) && is_transactional($item['table'])) {
        forget_write($item);
        return;
    }
    if (!$failed && $affected > ($item['max_affected'] ?? PHP_INT_MAX)) {
        note_extra_rows($item['table'], $affected - ($item['max_affected'] ?? 0));
    }
}

/**
 * What was known before the running query. A write that fails or changes nothing goes back to this: the history must
 * not say something changed when it did not.
 *
 * @param array{items: array<string, array{type: string, id: string, title: string, post_type: string, actions: array<string, int>, details: array<string, int>}>, more: int, technical: int, other: array<string, int>, tables: array<string, string>}|null $set
 * @return array{items: array<string, array{type: string, id: string, title: string, post_type: string, actions: array<string, int>, details: array<string, int>}>, more: int, technical: int, other: array<string, int>, tables: array<string, string>}
 */
function facts_before_query(?array $set = null): array
{
    /** @var array{items: array<string, array{type: string, id: string, title: string, post_type: string, actions: array<string, int>, details: array<string, int>}>, more: int, technical: int, other: array<string, int>, tables: array<string, string>} $facts */
    static $facts = ['items' => [], 'more' => 0, 'technical' => 0, 'other' => [], 'tables' => []];
    if ($set !== null) {
        $facts = $set;
    }
    return $facts;
}

/**
 * A write that saved its rows first and then did not happen. On a table without transactions a failed write may have
 * changed some rows, so there it stays recorded.
 *
 * @param array{table: string, pks?: list<string>, from?: int, ...} $item
 */
function forget_write(array $item): void
{
    $state = &state();
    $rows = count($item['pks'] ?? []);
    forget_unfinished_rows($item['table'], $item['from'] ?? 0);
    $state['facts'] = facts_before_query();
    $left = ($state['counts'][$item['table']] ?? 0) - $rows;
    if ($left > 0) {
        $state['counts'][$item['table']] = $left;
        return;
    }
    unset($state['counts'][$item['table']]);
}

/**
 * The rows an INSERT added, found from the keys it named or from the insert id.
 *
 * @param array{kind: string, table: string, pk_col: string, pks?: list<string>, rows?: int, ...} $item
 */
function record_new_rows(array $item, int $insert_id, int $affected): void
{
    $state = &state();
    if ($item['kind'] === 'insert_keys') {
        $pks = $item['pks'] ?? [];
        $rows = rows_by_keys($item['table'], $item['pk_col'], $pks);
        record_inserted($item['table'], $item['pk_col'], $rows);
        if (count($rows) !== count($pks)) {
            $state['unverified']++;
        }
        return;
    }
    $rows = inserted_rows($item, $insert_id, $affected);
    if ($rows === null) {
        $state['unverified']++;
        return;
    }
    record_inserted($item['table'], $item['pk_col'], $rows);
}

/**
 * A write changed more rows than were saved before it: rows written between the read and the write, which the
 * journal holds no previous value for. They are listed as not recorded.
 */
function note_extra_rows(string $table, int $extra): void
{
    $state = &state();
    $state['unverified']++;
    add_entry([
        'execution' => $state['execution'],
        'kind' => 'unverified',
        'tbl' => $table,
        'old_value' => ['statement' => 'EXTRA ROWS', 'rows' => $extra],
    ]);
}

/**
 * The rows a plain INSERT added, found from the insert id and the auto-increment step, or null when they cannot
 * be told apart from other rows: nothing added, no primary key, or a number of rows in the id range that differs
 * from the number the query added (a concurrent insert, INSERT IGNORE skipping rows). An insert that failed never
 * gets here. An id equal to the previous query's means nothing: two tables often hand out the same number.
 *
 * @param array{pk_col: string, table: string, rows?: int, ...} $item
 * @return array<array-key, array<string, string|null>>|null
 */
function inserted_rows(array $item, int $insert_id, int $affected): ?array
{
    if (count(key_columns($item['pk_col'])) !== 1 || $insert_id <= 0 || $affected <= 0) {
        return null;
    }
    $step = max(1, (int) own_var('SELECT @@auto_increment_increment'));
    $count = min($affected, max(1, $item['rows'] ?? 1));
    $keys = [];
    for ($i = 0; $i < $count; $i++) {
        $keys[] = (string) ($insert_id + ($i * $step));
    }
    $rows = rows_by_keys($item['table'], $item['pk_col'], $keys);
    return count($rows) === $affected ? $rows : null;
}

/** @param array<array-key, array<string, string|null>> $rows */
function record_inserted(string $table, string $pk_col, array $rows): void
{
    if ($pk_col === '' || $rows === []) {
        return;
    }
    $entries = [];
    foreach ($rows as $row) {
        note_fact('insert', $table, $row);
        $entries[] = [
            'execution' => state()['execution'],
            'kind' => 'insert',
            'tbl' => $table,
            'pk_col' => $pk_col,
            'pk' => row_key($pk_col, $row),
        ];
    }
    add_entries($entries);
}

/** @param DropStatement|AlterStatement $statement */
function copy_dropped_or_altered(Statement $statement, string $query): string
{
    $options = $statement->options;
    $is_table = $options !== null && $options->has('TABLE') !== false;
    if (!$is_table) {
        if (
            $statement instanceof DropStatement
            && $options !== null
            && ($options->has('DATABASE') !== false || $options->has('SCHEMA') !== false)
        ) {
            return unverified($query);
        }
        return $statement instanceof DropStatement ? $query : unverified($query);
    }
    if ($statement instanceof AlterStatement) {
        if (alter_only_adds($query)) {
            forget_table(table_name($statement->table));
            return $query;
        }
        copy_table(table_name($statement->table), operation: 'ALTER TABLE');
        forget_table(table_name($statement->table));
        return $query;
    }
    foreach (is_array($statement->fields) ? $statement->fields : [] as $field) {
        copy_table(table_name($field), operation: 'DROP TABLE');
    }
    return $query;
}

/** An ALTER TABLE that only adds columns or indexes loses nothing: no copy, and it does not count as changed rows. */
function alter_only_adds(string $query): bool
{
    $bare = (string) preg_replace('/\'(?:[^\'\\\\]++|\\\\.)*+\'/s', replacement: "''", subject: $query);
    $rest = (string) preg_replace('/^\s*ALTER\b/i', replacement: '', subject: $bare);
    return (
        preg_match('/\bADD\b/i', $rest) === 1
        && preg_match(
            '/\b(?:ALTER|CHANGE|COALESCE|CONVERT|DISCARD|DROP|EXCHANGE|IGNORE|IMPORT|MODIFY|ORDER|REMOVE|RENAME|REORGANIZE|TRUNCATE)\b/i',
            $rest,
        ) !== 1
    );
}

/** Copy a whole table before an operation the row journal cannot save. If that is not possible it runs unrecorded. */
function copy_table(string $table, string $operation): void
{
    if ($table === '') {
        unverified($operation);
        return;
    }
    $state = &state();
    $result = copy_whole_table($table);
    if ($result['status'] === 'missing') {
        return;
    }
    if ($result['status'] !== 'copied') {
        unverified($operation . ' ' . $table);
        return;
    }
    $entry = add_entry([
        'execution' => $state['execution'],
        'kind' => 'table',
        'tbl' => $table,
        'old_value' => ['backup' => $result['backup'] ?? '', 'rows' => $result['rows'] ?? 0, 'operation' => $operation],
    ]);
    if ($entry > 0) {
        $state['counts'][$table] = ($state['counts'][$table] ?? 0) + ($result['rows'] ?? 0);
        note_table_fact($table, $operation);
        $state['pending'][] = [
            'kind' => 'whole_table',
            'table' => $table,
            'pk_col' => '',
            'rows' => $result['rows'] ?? 0,
            'from' => $entry,
            'backup' => $result['backup'] ?? '',
        ];
        return;
    }
    // A copy nothing points to could never be found or cleaned up.
    drop_backup(['backup' => $result['backup'] ?? '']);
    unverified($operation . ' ' . $table);
}

/**
 * @return array{status: string, rows?: int, backup?: string}
 */
function copy_whole_table(string $table): array
{
    if (own_var(prepared('SHOW TABLES LIKE %s', [db()->esc_like($table)])) === null) {
        return ['status' => 'missing'];
    }
    // The size the server reports is an estimate, but it needs no scan and keeps ordinary ALTERs cheap.
    $bytes =
        (int) own_var(prepared('SELECT DATA_LENGTH + INDEX_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', [
            $table,
        ]));
    if ($bytes > byte_budget()) {
        return ['status' => 'too_large'];
    }
    $rows = (int) own_var(prepared('SELECT COUNT(*) FROM %i', [$table]));
    if ($rows > record_budget()) {
        return ['status' => 'too_large', 'rows' => $rows];
    }
    $backup = db()->prefix . BACKUP_INFIX . bin2hex(random_bytes(6));
    if (!own_query(prepared('CREATE TABLE %i LIKE %i', [$backup, $table])) || !copy_rows($table, $backup)) {
        own_query(prepared('DROP TABLE IF EXISTS %i', [$backup]));
        return ['status' => 'failed'];
    }
    return ['status' => 'copied', 'rows' => $rows, 'backup' => $backup];
}

/** A write the journal could not read or record. It runs, and is counted and listed as not recorded. */
function unverified(string $query): string
{
    $state = &state();
    $state['unverified']++;
    add_entry([
        'execution' => $state['execution'],
        'kind' => 'unverified',
        'old_value' => [
            'statement' => strtoupper(first_keyword($query)),
            'sql_hash' => hash('sha256', $query),
        ],
    ]);
    return $query;
}

/** Words followed by a parenthesis that have no effect and give the same result when evaluated again. */
const PURE_SQL_WORDS = [
    'in',
    'and',
    'or',
    'not',
    'xor',
    'like',
    'rlike',
    'regexp',
    'between',
    'lower',
    'upper',
    'length',
    'char_length',
    'concat',
    'concat_ws',
    'trim',
    'ltrim',
    'rtrim',
    'left',
    'right',
    'substring',
    'substr',
    'replace',
    'locate',
    'instr',
    'abs',
    'round',
    'floor',
    'ceil',
    'ceiling',
    'mod',
    'greatest',
    'least',
    'ifnull',
    'coalesce',
    'nullif',
    'if',
    'cast',
    'convert',
    'binary',
    'now',
    'curdate',
    'curtime',
    'current_timestamp',
    'utc_timestamp',
    'unix_timestamp',
    'from_unixtime',
    'date',
    'date_add',
    'date_sub',
    'datediff',
    'year',
    'month',
    'day',
];

/** Counting before a confirmation stops here; above it the card says "more than". */
const MEASURE_LIMIT = 100_000;

/** Default size, in bytes, of the largest query the history reads. */
const PARSE_LIMIT_BYTES = 2_097_152;

/** The largest query the history reads; a larger one runs unrecorded. Filter: novamira_journal_max_parse_bytes. */
function parse_limit(): int
{
    /** @var mixed $limit */
    $limit = apply_filters('novamira_journal_max_parse_bytes', PARSE_LIMIT_BYTES);
    return is_int($limit) && $limit > 0 ? $limit : PARSE_LIMIT_BYTES;
}

// @mago-expect lint:cyclomatic-complexity -- One branch per kind of write.
// @mago-expect lint:halstead
/**
 * How much a write would change right now, counted without changing anything: rows an UPDATE or DELETE
 * matches, rows an INSERT adds, rows of a table a TRUNCATE, DROP TABLE or ALTER TABLE affects. Null when the
 * SQL cannot be read or counted.
 *
 * @return array{operation: string, table: string, rows: int, capped: bool}|null
 */
function measure_sql(string $query): ?array
{
    if (strlen($query) > parse_limit()) {
        return null;
    }
    try {
        $parser = new Parser($query);
    } catch (\Throwable) {
        return null;
    }
    if ($parser->errors !== [] || count($parser->statements) !== 1) {
        return null;
    }
    $statement = $parser->statements[0];
    if ($statement instanceof UpdateStatement || $statement instanceof DeleteStatement) {
        $kind = $statement instanceof UpdateStatement ? 'UPDATE' : 'DELETE';
        $target = $statement instanceof UpdateStatement
            ? single_target($statement->tables, $statement->join)
            : single_target($statement->from ?? [], $statement->join ?? $statement->using);
        $rows = $target === null || !countable_without_side_effects($statement)
            ? null
            : count_rows($statement, $target, MEASURE_LIMIT + 1);
        return (
            $rows === null || $target === null
                ? null
                : [
                    'operation' => $kind,
                    'table' => (string) $target->table,
                    'rows' => min($rows, MEASURE_LIMIT),
                    'capped' => $rows > MEASURE_LIMIT,
                ]
        );
    }
    if ($statement instanceof InsertStatement || $statement instanceof ReplaceStatement) {
        $values = is_array($statement->values) ? $statement->values : [];
        $table = table_name($statement->into?->dest);
        return (
            $table === '' || $values === []
                ? null
                : [
                    'operation' => $statement instanceof ReplaceStatement ? 'REPLACE' : 'INSERT',
                    'table' => $table,
                    'rows' => count($values),
                    'capped' => false,
                ]
        );
    }
    $whole = whole_table_operation($statement);
    if ($whole === null) {
        return null;
    }
    $rows = own_var(prepared('SELECT COUNT(*) FROM %i', [$whole['table']]));
    return $rows === null ? null : $whole + ['rows' => (int) $rows, 'capped' => false];
}

/**
 * What a counted write would change, in the words a person reads on an approval card. Tables of WordPress get what
 * they hold and a few examples; any other table stays plain, as nothing about it is known.
 *
 * @param array{operation: string, table: string, rows: int, capped: bool} $measured
 */
function measured_sentence(string $query, array $measured): string
{
    $table = $measured['table'];
    $rows = ($measured['capped'] ? 'more than ' : '') . number_format_i18n($measured['rows']);
    return match ($measured['operation']) {
        'INSERT' => sprintf('Adds %s row(s) to %s.', $rows, $table),
        'REPLACE' => sprintf('Adds or replaces %s row(s) in %s.', $rows, $table),
        'TRUNCATE' => sprintf('Empties %s: all %s row(s).', $table, $rows),
        'DROP TABLE' => sprintf('Deletes the table %s: all %s row(s).', $table, $rows),
        'ALTER TABLE' => sprintf('Changes the structure of %s, which has %s row(s).', $table, $rows),
        default => ($measured['operation'] === 'DELETE' ? 'Deletes ' : 'Changes ')
            . $rows
            . measured_contents($query, $table),
    };
}

/** " items in wp_posts: 3 post, 1 product. For example: ..." or " row(s) in wp_xyz." */
function measured_contents(string $query, string $table): string
{
    $wpdb = db();
    $known = [
        $wpdb->posts => ['items', 'post_title', 'post_type'],
        $wpdb->users => ['users', 'user_login', ''],
        $wpdb->comments => ['comments', 'comment_author', ''],
        $wpdb->options => ['settings', 'option_name', ''],
    ];
    $found = measured_statement($query);
    if ($found === null || !array_key_exists($table, $known)) {
        return sprintf(' row(s) in %s.', $table);
    }
    [$noun, $name, $type] = $known[$table];
    [$statement, $target] = $found;
    $kinds = $type === '' ? '' : measured_kinds($statement, $target, $type);
    $examples = measured_examples($statement, $target, $name, $table);
    return sprintf(' %s in %s%s.%s', $noun, $table, $kinds, $examples);
}

/**
 * The statement of a single-table UPDATE or DELETE, and its table.
 *
 * @return array{UpdateStatement|DeleteStatement, Expression}|null
 */
function measured_statement(string $query): ?array
{
    $statement = (new Parser($query))->statements[0] ?? null;
    if ($statement instanceof UpdateStatement) {
        $target = single_target($statement->tables, $statement->join);
        return $target === null ? null : [$statement, $target];
    }
    if ($statement instanceof DeleteStatement) {
        $target = single_target($statement->from ?? [], $statement->join ?? $statement->using);
        return $target === null ? null : [$statement, $target];
    }
    return null;
}

/**
 * ": 3 post, 1 product".
 *
 * @param UpdateStatement|DeleteStatement $statement
 */
function measured_kinds(Statement $statement, Expression $target, string $type): string
{
    $rows = own_rows(
        'SELECT t, COUNT(*) AS n FROM ('
        . matching_sql($statement, $target, MEASURE_LIMIT, columns: '`' . $type . '` AS t')
        . ') AS novamira_types GROUP BY t ORDER BY n DESC, t ASC LIMIT 6',
    );
    $parts = array_map(
        static fn(array $row): string => number_format_i18n((int) ($row['n'] ?? 0)) . ' ' . ($row['t'] ?? ''),
        $rows ?? [],
    );
    return $parts === [] ? '' : ': ' . implode(', ', $parts);
}

/**
 * ' For example: "One", "Two".' What the site holds, so secrets stay hidden and names stay short.
 *
 * @param UpdateStatement|DeleteStatement $statement
 */
function measured_examples(Statement $statement, Expression $target, string $name, string $table): string
{
    $rows = own_rows(matching_sql($statement, $target, limit: 5, columns: '`' . $name . '` AS t', page: [
        'offset' => 0,
        'tie' => primary_key($table),
    ]));
    $names = array_map(
        static fn(array $row): string => '"' . hide_secrets(mb_substr($row['t'] ?? '', start: 0, length: 40)) . '"',
        $rows ?? [],
    );
    return $names === [] ? '' : ' For example: ' . implode(', ', $names) . '.';
}

/**
 * Whether the WHERE and ORDER BY of a write can be evaluated ahead of it, once or twice, without any effect: only
 * columns, literals, operators and functions known to be pure. SLEEP, RAND, a stored function, a subquery or a
 * variable (@n := @n + 1) are not. Anything else is not counted, and the card says so.
 *
 * @param UpdateStatement|DeleteStatement $statement
 */
function countable_without_side_effects(Statement $statement): bool
{
    $parts = '';
    if (is_array($statement->where) && $statement->where !== []) {
        $parts .= Condition::build($statement->where);
    }
    if (is_array($statement->order) && $statement->order !== []) {
        $parts .= ' ' . OrderKeyword::build($statement->order);
    }
    $unquoted = (string) preg_replace(
        '/\'(?:[^\'\\\\]++|\\\\.)*+\'|"(?:[^"\\\\]++|\\\\.)*+"/s',
        replacement: "''",
        subject: $parts,
    );
    if (str_contains($unquoted, '@') || preg_match('/\bselect\b/i', $unquoted) === 1) {
        return false;
    }
    $calls = [];
    preg_match_all('/([a-z_][a-z0-9_]*)\s*\(/i', $unquoted, $calls);
    foreach ($calls[1] as $name) {
        if (!in_array(strtolower($name), PURE_SQL_WORDS, strict: true)) {
            return false;
        }
    }
    return true;
}

/**
 * TRUNCATE, DROP TABLE and ALTER TABLE on one table.
 *
 * @return array{operation: string, table: string}|null
 */
function whole_table_operation(Statement $statement): ?array
{
    if ($statement instanceof TruncateStatement) {
        $table = table_name($statement->table);
        return $table === '' ? null : ['operation' => 'TRUNCATE', 'table' => $table];
    }
    $options = $statement->options;
    if ($options === null || $options->has('TABLE') === false) {
        return null;
    }
    if ($statement instanceof AlterStatement) {
        $table = table_name($statement->table);
        return $table === '' ? null : ['operation' => 'ALTER TABLE', 'table' => $table];
    }
    if ($statement instanceof DropStatement && is_array($statement->fields) && count($statement->fields) === 1) {
        $table = table_name($statement->fields[0]);
        return $table === '' ? null : ['operation' => 'DROP TABLE', 'table' => $table];
    }
    return null;
}
