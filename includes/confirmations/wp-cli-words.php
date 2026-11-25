<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Confirmations;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * What a WP-CLI command means, in one plain sentence, for the person approving it: they do not read commands. It is
 * Novamira's own reading, next to the AI's words, which may be wrong. A command it does not know is said so, rather
 * than guessed.
 */

/** Subcommands that only read. */
const WP_CLI_READS = [
    'list',
    'get',
    'status',
    'is-active',
    'is-installed',
    'search',
    'path',
    'version',
    'check-update',
    'exists',
    'size',
    'tables',
    'check',
    'count',
    'pluck',
];

/** @param array<array-key, mixed> $args mcp-adapter-execute-ability arguments. */
function wp_cli_meaning(array $args): string
{
    $parameters = is_array($args['parameters'] ?? null) ? $args['parameters'] : [];
    $words = wp_cli_string_args($parameters);
    if ($words === null) {
        return 'A command that cannot be checked: rely on the AI\'s words.';
    }
    [$positional, $flags] = wp_cli_split($words);
    $meaning = wp_cli_sentence($positional, $flags);
    $meaning = $meaning !== '' ? $meaning : 'A command with no plain description here: rely on the AI\'s words.';
    return function_exists('Novamira\\Secrets\\redact_string') ? \Novamira\Secrets\redact_string($meaning) : $meaning;
}

/**
 * @param list<string> $words
 * @return array{0: list<string>, 1: array<string, string>}
 */
function wp_cli_split(array $words): array
{
    $positional = [];
    $flags = [];
    foreach ($words as $word) {
        if (!str_starts_with($word, '--')) {
            $positional[] = $word;
            continue;
        }
        $pair = explode('=', substr($word, offset: 2), limit: 2);
        $flags[strtolower($pair[0])] = $pair[1] ?? '';
    }
    return [wp_cli_command_words($positional), $flags];
}

/**
 * @param list<string> $positional
 * @param array<string, string> $flags
 */
function wp_cli_sentence(array $positional, array $flags): string
{
    $command = strtolower($positional[0] ?? '');
    $subcommand = strtolower($positional[1] ?? '');
    $rest = array_slice($positional, offset: 2);
    if (
        in_array($subcommand, WP_CLI_READS, strict: true) && !in_array($command, ['db', 'search-replace'], strict: true)
    ) {
        return 'Reads ' . wp_cli_things($command) . ': changes nothing.';
    }
    return match ($command) {
        'plugin', 'theme' => wp_cli_extension_sentence($command, $subcommand, $rest, $flags),
        'core' => wp_cli_core_sentence($subcommand),
        'user' => wp_cli_user_sentence($subcommand, $rest, $flags),
        'role', 'cap' => 'Changes what a user role is allowed to do.',
        'option' => wp_cli_option_sentence($subcommand, $rest),
        'post' => wp_cli_post_sentence($subcommand, $rest, $flags),
        'search-replace' => wp_cli_replace_sentence(array_slice($positional, offset: 1), $flags),
        'db' => wp_cli_db_sentence($subcommand, $rest),
        default => wp_cli_other_sentence($command, $subcommand),
    };
}

function wp_cli_things(string $command): string
{
    return match ($command) {
        'plugin' => 'the plugins',
        'theme' => 'the themes',
        'user' => 'the users',
        'option' => 'the site settings',
        'post' => 'the content',
        'core' => 'the WordPress version',
        default => 'information from the site',
    };
}

/** "the plugin "seo", "forms"", or "all plugins". */
function wp_cli_named(string $kind, array $names, array $flags): string
{
    if (array_key_exists('all', $flags)) {
        return 'all ' . $kind . 's';
    }
    $names = array_map(static fn(mixed $name): string => '"' . (string) $name . '"', $names);
    return $names === [] ? 'a ' . $kind : 'the ' . $kind . (count($names) > 1 ? 's ' : ' ') . implode(', ', $names);
}

/**
 * @param list<string> $rest
 * @param array<string, string> $flags
 */
function wp_cli_extension_sentence(string $kind, string $subcommand, array $rest, array $flags): string
{
    $what = wp_cli_named($kind, $rest, $flags);
    return match ($subcommand) {
        'install' => 'Installs ' . $what . (array_key_exists('activate', $flags) ? ' and turns it on.' : '.'),
        'activate', 'enable' => 'Turns on ' . $what . '.',
        'deactivate', 'disable' => 'Turns off ' . $what . '.',
        'delete', 'uninstall' => 'Deletes ' . $what . '.',
        'update' => 'Updates ' . $what . '.',
        'auto-updates' => 'Changes automatic updates for ' . $what . '.',
        default => '',
    };
}

function wp_cli_core_sentence(string $subcommand): string
{
    return match ($subcommand) {
        'update',
        'download',
        'install',
        'multisite-convert',
        'multisite-install',
            => 'Changes the WordPress installation itself.',
        'update-db' => 'Updates the WordPress database after an upgrade.',
        'verify-checksums' => 'Checks the WordPress files: changes nothing.',
        default => '',
    };
}

/**
 * @param list<string> $rest
 * @param array<string, string> $flags
 */
function wp_cli_user_sentence(string $subcommand, array $rest, array $flags): string
{
    $who = $rest === [] ? 'a user' : 'the user "' . $rest[0] . '"';
    $role = ($flags['role'] ?? '') !== '' ? ' with the role "' . $flags['role'] . '"' : '';
    return match ($subcommand) {
        'create' => 'Creates ' . $who . $role . '.',
        'delete' => 'Deletes ' . ($rest === [] ? 'users' : 'the users "' . implode('", "', $rest) . '"') . '.',
        'update' => 'Changes ' . $who . ($role !== '' ? ', giving them' . $role : '') . '.',
        'set-role' => 'Gives ' . $who . ' the role "' . ($rest[1] ?? '') . '".',
        'add-role', 'add-cap' => 'Gives ' . $who . ' more permissions.',
        'remove-role', 'remove-cap' => 'Takes permissions away from ' . $who . '.',
        'reset-password' => 'Resets the password of ' . $who . '.',
        'import-csv' => 'Creates or changes users from a file.',
        default => '',
    };
}

/** @param list<string> $rest */
function wp_cli_option_sentence(string $subcommand, array $rest): string
{
    $name = '"' . ($rest[0] ?? '') . '"';
    $value = mb_substr($rest[1] ?? '', start: 0, length: 60);
    return match ($subcommand) {
        'update', 'add', 'patch' => 'Changes the site setting '
            . $name
            . ($value !== '' ? ' to "' . $value . '"' : '')
            . '.',
        'delete' => 'Deletes the site setting ' . $name . '.',
        default => '',
    };
}

/**
 * @param list<string> $rest
 * @param array<string, string> $flags
 */
function wp_cli_post_sentence(string $subcommand, array $rest, array $flags): string
{
    $titles = array_map(
        static function (string $id): string {
            $title = ctype_digit($id) && function_exists('get_the_title') ? get_the_title((int) $id) : '';
            return $title !== '' ? '"' . $title . '"' : '#' . $id;
        },
        array_slice($rest, offset: 0, length: 5),
    );
    $more = count($rest) > 5 ? ' and ' . (count($rest) - 5) . ' more' : '';
    $which = $titles === [] ? 'content' : implode(', ', $titles) . $more;
    return match ($subcommand) {
        'create' => 'Creates the content "' . ($flags['post_title'] ?? '') . '".',
        'update' => 'Changes ' . $which . '.',
        'delete' => array_key_exists('force', $flags)
            ? 'Deletes for good, with no trash: ' . $which . '.'
            : 'Moves to the trash: ' . $which . '.',
        'generate' => 'Creates test content in bulk.',
        default => '',
    };
}

/**
 * @param list<string> $rest
 * @param array<string, string> $flags
 */
function wp_cli_replace_sentence(array $rest, array $flags): string
{
    $sentence =
        'Replaces "'
        . mb_substr($rest[0] ?? '', start: 0, length: 60)
        . '" with "'
        . mb_substr($rest[1] ?? '', start: 0, length: 60)
        . '" everywhere in the database';
    if (
        array_key_exists('dry-run', $flags)
        && !array_key_exists('no-dry-run', $flags)
        && !array_key_exists('export', $flags)
    ) {
        return $sentence . ', as a test: changes nothing.';
    }
    return $sentence . '.';
}

/** @param list<string> $rest */
function wp_cli_db_sentence(string $subcommand, array $rest): string
{
    if ($subcommand === 'query') {
        return preg_match('/^\s*(select|show|describe|explain)\b/i', $rest[0] ?? '') === 1
            ? 'Reads data straight from the database: changes nothing.'
            : 'Changes data straight in the database, without going through WordPress.';
    }
    return match ($subcommand) {
        'export' => 'Saves a copy of the whole database to a file.',
        'import' => 'Replaces database content with what is in a file.',
        'optimize', 'repair' => 'Tidies up the database tables.',
        'size', 'tables', 'check', 'columns' => 'Reads information about the database: changes nothing.',
        default => '',
    };
}

function wp_cli_other_sentence(string $command, string $subcommand): string
{
    return match ($command . ' ' . $subcommand) {
        'cache flush' => 'Empties the cache, so pages are built again.',
        'transient delete' => 'Deletes temporary data the site keeps to go faster.',
        'rewrite flush' => 'Rebuilds the site\'s links.',
        'cron run', 'cron event' => 'Runs or changes scheduled tasks.',
        'media regenerate' => 'Creates the image sizes again.',
        'comment delete' => 'Deletes comments.',
        'comment approve' => 'Approves comments.',
        'comment spam' => 'Marks comments as spam.',
        'term create' => 'Creates a category or tag.',
        'term delete' => 'Deletes categories or tags.',
        'menu create', 'menu item' => 'Changes the site menus.',
        'language core', 'language plugin', 'language theme' => 'Changes the site languages.',
        default => '',
    };
}
