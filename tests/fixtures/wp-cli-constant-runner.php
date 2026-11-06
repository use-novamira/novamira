<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Child-process runner for WpCliConstantValidationTest: php wp-cli-constant-runner.php <JSON input>.
 *
 * NOVAMIRA_WP_CLI_COMMAND can only be defined once per process, so each configuration gets its own.
 * Prints what resolution produced as JSON; any warning is printed ahead of it, as in a response.
 *
 * `minimal` stops after resolution, for children with functions disabled that the rest would need.
 * `checks` and `windows` validate extra commands under POSIX and Windows rules. `execute` runs the
 * resolved invocation through novamira_run_wp_cli().
 *
 * The discovery cache lives in memory for the child's lifetime, and every read or write of it is
 * reported as `transient_calls`, so a test can show that a constant never touches the cache.
 *
 * @var array{abspath: string, constant?: mixed, filter?: mixed, minimal?: bool, checks?: list<list<string>>, windows?: list<list<string>>, execute?: list<string>} $input
 */

$input = json_decode($argv[1], associative: true, flags: JSON_THROW_ON_ERROR);

define('ABSPATH', $input['abspath']);
define('MINUTE_IN_SECONDS', 60);
define('DAY_IN_SECONDS', 86_400);
if (array_key_exists('constant', $input)) {
    define('NOVAMIRA_WP_CLI_COMMAND', $input['constant']);
}

function __(string $text, string $domain = 'default'): string
{
    return $text;
}

function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    global $input, $filter_received;
    if (!array_key_exists('filter', $input)) {
        return $value;
    }
    $filter_received = $value;

    return $input['filter'];
}

$transients = [];
$transient_calls = [];

function get_transient(string $transient): mixed
{
    global $transients, $transient_calls;
    $transient_calls[] = 'get';

    return $transients[$transient] ?? false;
}

function set_transient(string $transient, mixed $value, int $expiration = 0): bool
{
    global $transients, $transient_calls;
    unset($expiration);
    $transient_calls[] = 'set';
    $transients[$transient] = $value;

    return true;
}

final class WP_Error
{
    public function __construct(
        public string $code = '',
        public string $message = '',
    ) {}
}

$filter_received = null;
$root = dirname(__DIR__, levels: 2);
require $root . '/includes/abilities/bootstrap.php';
require $root . '/includes/abilities/run-wp-cli.php';

$command = novamira_find_wp_cli_command();
$output = [
    'command' => $command,
    'problem' => novamira_wp_cli_constant_problem(),
    'filter_received' => $filter_received,
];

if ($input['minimal'] ?? false) {
    $output['transient_calls'] = $transient_calls;
    echo json_encode($output);
    exit();
}

$output['checks'] = array_map(
    static fn(array $candidate): ?string => novamira_wp_cli_command_problem($candidate, windows: false),
    $input['checks'] ?? [],
);
$output['windows'] = array_map(
    static fn(array $candidate): ?string => novamira_wp_cli_command_problem($candidate, windows: true),
    $input['windows'] ?? [],
);
if (isset($input['execute'])) {
    $output['execution'] = novamira_run_wp_cli(['args' => $input['execute']]);
}

if (function_exists('proc_open') && function_exists('exec')) {
    $output['status'] = novamira_wp_cli_status();
    $output['unavailable_message'] = novamira_wp_cli_unavailable_message('novamira/run-wp-cli');
    if ($command === null) {
        $error = novamira_run_wp_cli(['args' => ['--version']]);
        $output['run_error'] = $error instanceof WP_Error ? $error->message : null;
    }
}

$output['transient_calls'] = $transient_calls;
echo json_encode($output);
