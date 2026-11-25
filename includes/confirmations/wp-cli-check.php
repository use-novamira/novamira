<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Confirmations;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * run-wp-cli: the AI says in words what a command does and scores it, and its score decides. What PHP already does goes
 * through execute-php and its check, so commands that run PHP or install packages are forbidden, and so is what would
 * destroy the whole database or touch the AI change history. The few commands that do the most damage when the AI
 * underrates them have a minimum. Nothing else is judged here: reading every way to write through SQL is a losing game.
 *
 * @param array<array-key, mixed> $parameters
 * @return array{score: int, rules: list<string>, reject: bool}
 */
function wp_cli_check(array $parameters): array
{
    $args = wp_cli_string_args($parameters);
    return analysis_result($args === null ? ['high:wp_cli_arguments'] : wp_cli_rules($args));
}

/**
 * @param array<array-key, mixed> $parameters
 * @return list<string>|null Null when an argument is not a string.
 */
function wp_cli_string_args(array $parameters): ?array
{
    $args = [];
    // @mago-expect analysis:mixed-assignment -- Arguments are checked one by one.
    foreach (is_array($parameters['args'] ?? null) ? $parameters['args'] : [] as $arg) {
        if (!is_string($arg)) {
            return null;
        }
        $args[] = $arg;
    }
    return $args;
}

/**
 * Why a command must not run, or an empty string. The ability asks this too, so it holds in every mode.
 *
 * @param list<string> $args
 */
function wp_cli_forbidden(array $args): string
{
    $forbidden = array_filter(wp_cli_rules($args), static fn(string $rule): bool => str_starts_with($rule, 'forbid:'));
    return $forbidden === [] ? '' : refusal_text(array_values($forbidden));
}

/**
 * @param list<string> $args
 * @return list<string>
 */
function wp_cli_rules(array $args): array
{
    $positional = [];
    $flags = [];
    foreach ($args as $arg) {
        if (str_starts_with($arg, '--')) {
            $flags[] = strtolower(explode('=', substr($arg, offset: 2), limit: 2)[0]);
            continue;
        }
        $positional[] = $arg;
    }
    $positional = wp_cli_command_words($positional);
    // --exec and --require load PHP before any command.
    if (array_intersect($flags, ['exec', 'require']) !== []) {
        return ['forbid:wp_cli_runs_php'];
    }
    $command = strtolower($positional[0] ?? '');
    $subcommand = strtolower($positional[1] ?? '');
    if (wp_cli_changes_novamira_setting($command, $subcommand, $positional)) {
        return ['forbid:wp_cli_novamira_settings'];
    }
    return [
        ...wp_cli_command_rules($positional, $flags, $args),
        ...wp_cli_access_rules($command, $subcommand, $positional, $flags),
    ];
}

/**
 * @param list<string> $positional
 * @param list<string> $flags
 * @param list<string> $args
 * @return list<string>
 */
function wp_cli_command_rules(array $positional, array $flags, array $args): array
{
    $command = strtolower($positional[0] ?? '');
    $subcommand = strtolower($positional[1] ?? '');
    $third = strtolower($positional[2] ?? '');
    return match ($command) {
        'eval', 'eval-file', 'shell' => ['forbid:wp_cli_runs_php'],
        'package' => ['forbid:wp_cli_package'],
        'db' => wp_cli_db_rules($subcommand, $positional[2] ?? '', $args),
        'search-replace' => wp_cli_search_replace_rules($positional, $flags),
        'plugin', 'theme' => wp_cli_plugin_theme_rules($subcommand, $third),
        'core', 'language' => wp_cli_change($subcommand, $third, rule: 'high:plugins_and_themes'),
        'user' => $subcommand === 'delete'
            ? ['high:deletes_user']
            : wp_cli_change($subcommand, $third, rule: 'high:users_and_roles'),
        'role', 'cap', 'super-admin' => wp_cli_change($subcommand, $third, rule: 'high:users_and_roles'),
        'option' => wp_cli_option_rules($subcommand, $positional[2] ?? ''),
        'post' => wp_cli_post_rules($subcommand, array_slice($positional, offset: 2), $flags),
        // Changing the link structure changes every link on the site; flushing alone only rebuilds them.
        'rewrite' => $subcommand === 'structure' ? ['max:permalinks'] : [],
        // Deleting a site of the network removes its tables and its uploaded files.
        'site' => $subcommand === 'delete' ? ['high:deletes_site'] : [],
        // A refund created through WooCommerce's commands sends money back, as wc_create_refund() does.
        'wc' => str_contains($subcommand, needle: 'refund') && $third === 'create' ? ['money:refund'] : [],
        default => [],
    };
}

/**
 * Turning a plugin or theme on or off counts less than installing, updating or deleting one. It is not harmless:
 * turning a plugin on runs its activation code, which can create tables, change settings or send email.
 *
 * @return list<string>
 */
function wp_cli_plugin_theme_rules(string $subcommand, string $third): array
{
    if (in_array($subcommand, ['activate', 'toggle', 'enable'], strict: true)) {
        return ['big:plugins_on'];
    }
    if (in_array($subcommand, ['deactivate', 'disable'], strict: true)) {
        return ['medium:plugins_on_off'];
    }
    return wp_cli_change($subcommand, $third, rule: 'high:plugins_and_themes');
}

/**
 * Commands after which the AI may lose this site: a new address (Novamira is tied to it), or Novamira turned off.
 *
 * @param list<string> $positional
 * @param list<string> $flags
 * @return list<string>
 */
function wp_cli_access_rules(string $command, string $subcommand, array $positional, array $flags): array
{
    if ($command === 'search-replace') {
        $old = strtolower($positional[1] ?? '');
        foreach (site_hosts() as $host) {
            if ($old !== '' && str_contains($old, $host)) {
                return ['access:site_address'];
            }
        }
        return [];
    }
    if ($command === 'option' && in_array(strtolower($positional[2] ?? ''), ['siteurl', 'home'], strict: true)) {
        return in_array($subcommand, ['update', 'add', 'delete', 'patch'], strict: true) ? ['access:site_address'] : [];
    }
    // toggle turns an active plugin off, so on Novamira it is the same as deactivate.
    if ($command === 'plugin' && in_array($subcommand, ['deactivate', 'toggle', 'delete', 'uninstall'], strict: true)) {
        $names = strtolower(implode(' ', array_slice($positional, offset: 2)));
        return in_array('all', $flags, strict: true) || str_contains($names, 'novamira') ? ['access:novamira_off'] : [];
    }
    return [];
}

/**
 * The hosts this site answers on, lowercased.
 *
 * @return list<string>
 */
function site_hosts(): array
{
    $hosts = [];
    foreach ([home_url(), site_url()] as $url) {
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host !== '') {
            $hosts[] = strtolower($host);
        }
    }
    return array_values(array_unique($hosts));
}

/**
 * Novamira's options hold its approval settings and requests: a command does not change them, an option command naming
 * one or SQL writing to one alike. Reading them stays allowed.
 *
 * @param list<string> $positional
 */
function wp_cli_changes_novamira_setting(string $command, string $subcommand, array $positional): bool
{
    if ($command === 'option' || $command === 'site' && $subcommand === 'option') {
        $verb = strtolower($command === 'option' ? $subcommand : $positional[2] ?? '');
        $name = strtolower($command === 'option' ? $positional[2] ?? '' : $positional[3] ?? '');
        return (
            in_array($verb, ['add', 'update', 'delete', 'patch', 'set-autoload'], strict: true)
            && str_starts_with($name, 'novamira_')
        );
    }
    if ($command === 'db' && $subcommand === 'query') {
        // A write to the options table that names a Novamira option; text about Novamira elsewhere is fine.
        $sql = $positional[2] ?? '';
        return (
            stripos($sql, needle: 'novamira_') !== false
            && preg_match('/options\b/i', $sql) === 1
            && preg_match('/^\s*(select|show|describe|explain)\b/i', $sql) !== 1
        );
    }
    return false;
}

/**
 * The words that say which command runs, as WP-CLI itself reads them: "wp" and an alias say nothing about it, a single
 * argument can hold the whole command, and "sql" is "db".
 *
 * @param list<string> $positional
 * @return list<string>
 */
function wp_cli_command_words(array $positional): array
{
    $first = array_shift($positional) ?? '';
    $words = preg_split('/\s+/', trim($first), flags: PREG_SPLIT_NO_EMPTY);
    $positional = [...(is_array($words) ? $words : []), ...$positional];
    if (($positional[0] ?? '') === 'wp') {
        array_shift($positional);
    }
    if (str_starts_with($positional[0] ?? '', '@')) {
        array_shift($positional);
    }
    if (strtolower($positional[0] ?? '') === 'sql') {
        $positional[0] = 'db';
    }
    return $positional;
}

/**
 * With the "all tables" flags, a wildcard or the table named it also reaches the AI change history. A dry run changes
 * nothing, unless --no-dry-run undoes it or --export writes a file.
 *
 * @param list<string> $positional
 * @param list<string> $flags
 * @return list<string>
 */
function wp_cli_search_replace_rules(array $positional, array $flags): array
{
    if (array_intersect($flags, ['all-tables', 'all-tables-with-prefix']) !== []) {
        return ['forbid:wp_cli_ai_change_history'];
    }
    foreach (array_slice($positional, offset: 3) as $table) {
        if (str_contains(strtolower($table), HISTORY_TABLE_SUFFIX) || strpbrk($table, characters: '*?%') !== false) {
            return ['forbid:wp_cli_ai_change_history'];
        }
    }
    $writes = !in_array('dry-run', $flags, strict: true) || array_intersect($flags, ['no-dry-run', 'export']) !== [];
    return $writes ? ['high:wp_cli_search_replace'] : [];
}

/**
 * Deleting one post to the trash is the AI's to score. Without --force, WP-CLI moves posts and pages to the trash, where
 * a person brings them back from the dashboard: several of them stay recoverable. Past the trash, or other types it
 * deletes for good, several at once have a minimum: the same work written in PHP would ask too.
 *
 * @param list<string> $targets
 * @param list<string> $flags
 * @return list<string>
 */
function wp_cli_post_rules(string $subcommand, array $targets, array $flags): array
{
    if ($subcommand !== 'delete') {
        return [];
    }
    $ids = preg_split('/\s+/', trim(implode(' ', $targets)), flags: PREG_SPLIT_NO_EMPTY);
    $ids = is_array($ids) ? $ids : [];
    if (in_array('force', $flags, strict: true)) {
        return ['high:wp_cli_post_delete'];
    }
    // A post already in the trash is deleted for good, even without --force.
    if (wp_cli_ids_in_trash($ids)) {
        return count($ids) === 1 ? ['medium:deletes_content'] : ['high:wp_cli_post_delete'];
    }
    if (count($ids) === 1) {
        return [];
    }
    return wp_cli_ids_go_to_trash($ids) ? ['medium:trash'] : ['high:wp_cli_post_delete'];
}

/**
 * Whether WP-CLI sends every one of these posts to the trash: only posts and pages are sure to go there without
 * --force, and only while the trash is on.
 *
 * @param list<string> $ids
 */
function wp_cli_ids_go_to_trash(array $ids): bool
{
    if ($ids === [] || !trash_is_on() || !function_exists('get_post_type')) {
        return false;
    }
    foreach ($ids as $id) {
        $type = ctype_digit($id) ? get_post_type((int) $id) : false;
        if ($type !== 'post' && $type !== 'page') {
            return false;
        }
    }
    return true;
}

/**
 * Whether any of these posts is already in the trash. Outside WordPress the status cannot be read: nothing is assumed.
 *
 * @param list<string> $ids
 */
function wp_cli_ids_in_trash(array $ids): bool
{
    if (!function_exists('get_post_status')) {
        return false;
    }
    foreach ($ids as $id) {
        if (!ctype_digit($id) || get_post_status((int) $id) === 'trash') {
            return true;
        }
    }
    return false;
}

/**
 * Nothing for a read, the given rule for anything else. Some commands are groups: "user meta get", "plugin auto-updates status".
 *
 * @return list<string>
 */
function wp_cli_change(string $subcommand, string $third, string $rule): array
{
    $reads = [
        'list',
        'get',
        'status',
        'path',
        'version',
        'info',
        'check-update',
        'is-installed',
        'is-active',
        'verify-checksums',
        'count',
        'exists',
        'search',
        'pluck',
        'prefix',
        'size',
        'tables',
        'columns',
        'check',
        'help',
        'has',
        'type',
    ];
    $groups = ['meta', 'auto-updates', 'mod'];
    if ($subcommand === '' || in_array($subcommand, $reads, strict: true)) {
        return [];
    }
    return in_array($subcommand, $groups, strict: true) && in_array($third, $reads, strict: true) ? [] : [$rule];
}

/**
 * @param list<string> $args
 * @return list<string>
 */
function wp_cli_db_rules(string $subcommand, string $query, array $args): array
{
    if (in_array($subcommand, ['reset', 'drop', 'clean'], strict: true)) {
        return ['forbid:wp_cli_destroys_database'];
    }
    foreach ($args as $arg) {
        if ($arg !== $query && str_contains(strtolower($arg), HISTORY_TABLE_SUFFIX)) {
            return ['forbid:wp_cli_ai_change_history'];
        }
    }
    if ($subcommand === 'query') {
        return wp_cli_query_rules($query);
    }
    return $subcommand === 'import' ? ['high:wp_cli_database'] : [];
}

/**
 * Only what would destroy the database or touch the history is judged here. Anything else a query does, the AI scores.
 *
 * @return list<string>
 */
function wp_cli_query_rules(string $query): array
{
    if (sql_names_table($query, HISTORY_TABLE_SUFFIX)) {
        return ['forbid:wp_cli_ai_change_history'];
    }
    return preg_match('/\bDROP\s+(?:DATABASE|SCHEMA)\b/i', $query) === 1 ? ['forbid:wp_cli_destroys_database'] : [];
}

/**
 * Whether SQL names a table outside its texts and comments: an article quoting the name does not touch the table. A
 * comment MySQL or MariaDB runs ("/*! ... *\/") is code, and "--" starts a comment only before a space.
 */
function sql_names_table(string $query, string $table): bool
{
    $code = preg_replace(
        '/\'(?:[^\'\\\\]|\\\\.|\'\')*\'|"(?:[^"\\\\]|\\\\.|"")*"|\/\*(?!!|M!).*?\*\/|--\s[^\n]*|#[^\n]*/s',
        replacement: ' ',
        subject: $query,
    );
    return str_contains(strtolower($code ?? $query), strtolower($table));
}

/** @return list<string> */
function wp_cli_option_rules(string $subcommand, string $option): array
{
    if (wp_cli_change($subcommand, third: '', rule: 'change') === []) {
        return [];
    }
    if (in_array(strtolower($option), PERMALINK_OPTIONS, strict: true)) {
        return ['max:permalinks'];
    }
    return $option !== '' && critical_option($option) ? ['high:critical_option'] : [];
}
