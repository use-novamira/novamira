<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * The file Novamira's OAuth installer loads from ABSPATH, standing in for WordPress' upgrade API.
 *
 * The fixture ABSPATH points here (see client-store-stubs.php), so `require_once ABSPATH .
 * 'wp-admin/includes/upgrade.php'` resolves without a WordPress install.
 */

if (!function_exists('dbDelta')) {
    /**
     * Applies one CREATE TABLE statement to the in-memory schema of the wpdb double.
     *
     * Modelled on the two behaviours of the real dbDelta that this suite has to be able to see:
     *
     * - a table that does not exist is created in full, and a table that does exist gains only the
     *   columns it is missing;
     * - an existing column whose declared type differs from the installed one is CHANGED to the
     *   declared type (wp-admin/includes/upgrade.php issues `ALTER TABLE ... CHANGE COLUMN` there),
     *   which is how re-running a CREATE TABLE statement over a table that drifted wider narrows it.
     *   Every such change is recorded in $GLOBALS['nm_column_changes'] so a test can assert that a
     *   complete table was never handed over in the first place. Deliberately stricter than the real
     *   dbDelta, which leaves a column alone when both types belong to the text or blob family and
     *   the declared one is the smaller: the risk is represented here, not under-represented.
     *
     * Both are refused when the database user may not ALTER the table
     * ($GLOBALS['nm_test_can_alter'] = false) and, like the real dbDelta, the refusal is reported
     * back rather than thrown: the caller sees a successful call and an unchanged table.
     *
     * @return list<string>
     */
    function dbDelta(string $queries): array
    {
        // The wpdb double from client-store-stubs.php, which owns the in-memory schema.
        global $wpdb;
        $GLOBALS['nm_dbdelta_calls'][] = $queries;

        [$table, $columns] = nm_test_parse_create_table($queries);
        if ($table === '') {
            return [];
        }
        if (!isset($wpdb->tables[$table])) {
            // A CREATE TABLE the database refuses ($GLOBALS['nm_dbdelta_cannot_create']) leaves the
            // table missing, reported rather than thrown, as dbDelta does.
            if (in_array($table, (array) ($GLOBALS['nm_dbdelta_cannot_create'] ?? []), true)) {
                return [];
            }
            $wpdb->tables[$table] = $columns;
            $wpdb->rows[$table] ??= [];
            return [];
        }
        if (!(bool) ($GLOBALS['nm_test_can_alter'] ?? true)) {
            return [];
        }
        foreach ($columns as $column => $declared) {
            $installed = $wpdb->tables[$table][$column] ?? null;
            if ($installed === null) {
                $wpdb->tables[$table][$column] = $declared;
                continue;
            }
            if (nm_test_column_type($installed) === nm_test_column_type($declared)) {
                continue;
            }
            $GLOBALS['nm_column_changes'][] = [
                'table' => $table,
                'column' => $column,
                'from' => $installed,
                'to' => $declared,
            ];
            $wpdb->tables[$table][$column] = $declared;
        }
        return [];
    }
}

if (!function_exists('nm_test_column_type')) {
    /** The type of a column definition, as dbDelta compares it: the first token, lower-cased. */
    function nm_test_column_type(string $definition): string
    {
        return strtolower((string) strtok(trim($definition), " \t\n\r"));
    }
}

if (!function_exists('nm_test_parse_create_table')) {
    /**
     * Table name and columns of a CREATE TABLE statement, as column name => its definition. Index
     * definitions (PRIMARY KEY, UNIQUE KEY, KEY) are not columns and are skipped.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    function nm_test_parse_create_table(string $sql): array
    {
        if (preg_match('/CREATE TABLE\s+([A-Za-z0-9_]+)/', $sql, $matches) !== 1) {
            return ['', []];
        }
        $open = strpos($sql, '(');
        $close = strrpos($sql, ')');
        if ($open === false || $close === false || $close <= $open) {
            return ['', []];
        }

        $columns = [];
        foreach (explode(',', substr($sql, $open + 1, $close - $open - 1)) as $definition) {
            $definition = trim($definition);
            $name = strtok($definition, " \t\n\r(");
            if ($name === false || $name === '') {
                continue;
            }
            if (in_array(strtoupper($name), ['PRIMARY', 'UNIQUE', 'KEY', 'INDEX', 'FULLTEXT', 'CONSTRAINT'], true)) {
                continue;
            }
            $columns[strtolower($name)] = trim(substr($definition, strlen($name)));
        }

        return [$matches[1], $columns];
    }
}
