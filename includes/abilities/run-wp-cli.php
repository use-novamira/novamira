<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Abilities: Execute WP-CLI commands and query job statuses.
 *
 * WP-CLI discovery resolves an invocation as an argv list, because WP-CLI is not always a directly
 * executable file: Windows hosts such as Local (Local by Flywheel) ship a `wp-cli.phar` that has to
 * be run as `<php binary> -c <php.ini> <phar>`. Resolution order:
 *
 * 1. The `NOVAMIRA_WP_CLI_COMMAND` constant. Either a full argv array, for example
 *    `['C:/.../php.exe', '-c', 'C:/.../php.ini', 'C:/.../wp-cli.phar']`, or a string holding the
 *    path of a single executable. It is never parsed as a shell command line. A value that can be
 *    shown not to work leaves WP-CLI unavailable, with no discovery; see
 *    novamira_wp_cli_command_problem().
 * 2. Automatic discovery for the current platform (`where` on Windows, `which`/`command -v`
 *    elsewhere) plus the well-known install locations. Discovery launches processes, so its result
 *    is cached across requests; see novamira_cached_wp_cli_discovery(). A well-known location PHP's
 *    open_basedir refuses counts as absent, without the warning that probing it would print into
 *    the response. A path `which` or `command -v` reported stays found even when open_basedir
 *    refuses to inspect it, because open_basedir does not restrict what a process can start.
 * 3. The `novamira_wp_cli_command` filter, which receives the resolved argv list (or null when
 *    nothing was found) and may replace it outright. This runs last, so it wins over everything.
 *
 * `NOVAMIRA_WP_CLI_PHP_INI` overrides the php.ini passed with `-c` when a phar is run through a
 * discovered PHP binary; by default the php.ini already loaded by this process is reused, which is
 * what makes Local's per-site configuration (and therefore mysqli) available to WP-CLI.
 */

if (!defined('ABSPATH')) {
    exit();
}

const NOVAMIRA_WP_CLI_DISCOVERY_TRANSIENT_PREFIX = 'novamira_wp_cli_discovery_';

function novamira_register_wp_cli_abilities(): void
{
    if (!wp_has_ability('novamira/run-wp-cli')) {
        novamira_register_run_wp_cli_ability();
    }
    if (!wp_has_ability('novamira/get-wp-cli-job')) {
        novamira_register_get_wp_cli_job_ability();
    }
}

function novamira_register_run_wp_cli_ability(): void
{
    wp_register_ability('novamira/run-wp-cli', [
        'label' => __('Run WP-CLI Command', domain: 'novamira'),
        'description' => __(
            'Runs a WP-CLI command on the server. Can be run synchronously (default) or asynchronously in the background.',
            domain: 'novamira',
        ),
        'category' => 'code-execution',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'args' => [
                    'type' => 'array',
                    'description' => 'Arguments to pass to wp (e.g. ["plugin", "list", "--format=json"]).',
                    'items' => [
                        'type' => 'string',
                    ],
                    'minItems' => 1,
                ],
                'async' => [
                    'type' => 'boolean',
                    'description' => 'Whether to run the command asynchronously in the background.',
                    'default' => false,
                ],
            ],
            'required' => ['args'],
            'additionalProperties' => false,
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'success' => [
                    'type' => 'boolean',
                    'description' => 'Whether the command started successfully (or completed successfully for synchronous run).',
                ],
                'exit_code' => [
                    'type' => 'integer',
                    'description' => 'Exit status code of the process (null if async).',
                ],
                'stdout' => [
                    'type' => 'string',
                    'description' => 'Captured standard output (empty if async).',
                ],
                'stderr' => [
                    'type' => 'string',
                    'description' => 'Captured standard error (empty if async).',
                ],
                'job_id' => [
                    'type' => 'string',
                    'description' => 'The generated background job ID. Absent for a synchronous run.',
                ],
                'pid' => [
                    'type' => 'integer',
                    'description' =>
                        'PID of the background process. Absent for a synchronous run, and on Windows, '
                            . 'where a detached job reports no PID; track the job through job_id instead.',
                ],
            ],
            'required' => ['success'],
        ],
        'execute_callback' => 'novamira_run_wp_cli',
        'permission_callback' => 'novamira_permission_callback',
        'meta' => [
            'show_in_rest' => true,
            'mcp' => ['public' => true],
            'annotations' => [
                'readonly' => false,
                'destructive' => true,
                'idempotent' => false,
            ],
        ],
    ]);
}

function novamira_register_get_wp_cli_job_ability(): void
{
    wp_register_ability('novamira/get-wp-cli-job', [
        'label' => __('Get WP-CLI Job Status', domain: 'novamira'),
        'description' => __('Checks the status of an asynchronous background WP-CLI job.', domain: 'novamira'),
        'category' => 'code-execution',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'job_id' => [
                    'type' => 'string',
                    'description' => 'The ID of the background job to check.',
                    'minLength' => 1,
                ],
                'offset' => [
                    'type' => 'integer',
                    'description' => 'Byte offset to start reading from the output log.',
                    'default' => 0,
                    'minimum' => 0,
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum bytes of the log output to return. Use -1 for the entire file.',
                    'default' => 1_048_576,
                ],
            ],
            'required' => ['job_id'],
            'additionalProperties' => false,
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'success' => [
                    'type' => 'boolean',
                    'description' => 'Whether the job was found.',
                ],
                'job_id' => [
                    'type' => 'string',
                ],
                'status' => [
                    'type' => 'string',
                    'description' => 'Job status: "running", "completed", or "not_found".',
                ],
                'exit_code' => [
                    'type' => 'integer',
                    'description' => 'Exit code of the process (null if running or not found).',
                ],
                'stdout' => [
                    'type' => 'string',
                    'description' => 'Captured stdout and stderr output log.',
                ],
                'bytes_read' => [
                    'type' => 'integer',
                    'description' => 'Number of bytes read from log file.',
                ],
                'truncated' => [
                    'type' => 'boolean',
                    'description' => 'Whether the log output was truncated due to limit.',
                ],
            ],
            'required' => ['success', 'job_id', 'status'],
        ],
        'execute_callback' => 'novamira_get_wp_cli_job',
        'permission_callback' => 'novamira_permission_callback',
        'meta' => [
            'show_in_rest' => true,
            'mcp' => ['public' => true],
            'annotations' => [
                'readonly' => true,
                'destructive' => false,
                'idempotent' => true,
            ],
        ],
    ]);
}

/**
 * Execute WP-CLI command.
 *
 * @param array $input Input parameters.
 * @return array|WP_Error
 */
function novamira_run_wp_cli(array $input)
{
    // An ability that runs PHP or WP-CLI commands also refuses on its own in read mode, even when it is called
    // without the registry or a permission check. Every other ability relies on the checks they all share.
    $read_mode_refusal = function_exists('novamira_read_mode_execution_refusal')
        ? novamira_read_mode_execution_refusal('novamira/run-wp-cli')
        : null;
    if ($read_mode_refusal !== null) {
        return $read_mode_refusal;
    }

    $raw_args = is_array($input['args'] ?? null) ? $input['args'] : [];
    $args = [];
    /** @var mixed $arg */
    foreach ($raw_args as $arg) {
        if (!is_string($arg)) {
            return new WP_Error('invalid_wp_cli_arg', __('WP-CLI arguments must be strings.', domain: 'novamira'));
        }
        $args[] = $arg;
    }

    $async = ($input['async'] ?? null) === true;

    if (!function_exists('proc_open') || !function_exists('exec')) {
        return new WP_Error('process_execution_disabled', __(
            'Process execution (proc_open or exec) is disabled in PHP configuration.',
            domain: 'novamira',
        ));
    }

    // Discover again rather than trusting the cross-request cache: WP-CLI may have been removed or
    // moved since the cached result was stored, and a run is rare enough to pay for the lookup.
    $wp_command = novamira_find_fresh_wp_cli_command();
    if ($wp_command === null) {
        return new WP_Error(
            'wp_cli_not_found',
            novamira_wp_cli_constant_problem() ?? novamira_wp_cli_not_found_message(PHP_OS_FAMILY),
        );
    }

    // Automatically append --allow-root if executing as root.
    if (novamira_is_current_user_root() && !in_array(needle: '--allow-root', haystack: $args, strict: true)) {
        array_unshift($args, '--allow-root');
    }

    if ($async) {
        return novamira_run_wp_cli_async($wp_command, $args);
    }

    return novamira_run_wp_cli_sync($wp_command, $args);
}

/**
 * Query the status of an asynchronous background WP-CLI job.
 *
 * @param array $input Input parameters.
 * @return array|WP_Error
 */
function novamira_get_wp_cli_job(array $input)
{
    $job_id = (string) ($input['job_id'] ?? '');
    if (!preg_match('/^[a-f0-9]{16}$/i', $job_id)) {
        return new WP_Error('invalid_job_id', __('Invalid job ID format.', domain: 'novamira'));
    }

    $job_dir = novamira_get_sandbox_dir(ensure_exists: false) . 'wp-cli-jobs/';
    $log_file = $job_dir . 'job_' . $job_id . '.log';
    $status_file = $job_dir . 'job_' . $job_id . '.status';

    if (!is_file($log_file)) {
        return novamira_get_missing_wp_cli_job_result($job_id, $status_file);
    }

    $status = 'running';
    $exit_code = null;
    if (is_file($status_file)) {
        $status = 'completed';
        $exit_content = trim((string) file_get_contents($status_file));
        if ($exit_content !== '') {
            $exit_code = (int) $exit_content;
        }
    }

    $offset = (int) ($input['offset'] ?? 0);
    $limit = (int) ($input['limit'] ?? 1_048_576);

    $slice = novamira_read_log_slice($log_file, $offset, $limit);
    if (is_wp_error($slice)) {
        return $slice;
    }

    $res = [
        'success' => true,
        'job_id' => $job_id,
        'status' => $status,
        'stdout' => $slice['content'],
        'bytes_read' => $slice['bytes_read'],
        'truncated' => $slice['truncated'],
    ];
    if ($exit_code !== null) {
        $res['exit_code'] = $exit_code;
    }
    return $res;
}

/**
 * Build a job response when the log file is not present.
 *
 * @param string $job_id      Job ID.
 * @param string $status_file Status file path.
 * @return array
 */
function novamira_get_missing_wp_cli_job_result(string $job_id, string $status_file): array
{
    if (!is_file($status_file)) {
        return [
            'success' => false,
            'job_id' => $job_id,
            'status' => 'not_found',
            'stdout' => '',
            'bytes_read' => 0,
            'truncated' => false,
        ];
    }

    $exit_content = trim((string) file_get_contents($status_file));
    $res = [
        'success' => true,
        'job_id' => $job_id,
        'status' => 'completed',
        'stdout' => '',
        'bytes_read' => 0,
        'truncated' => false,
    ];
    if ($exit_content !== '') {
        $res['exit_code'] = (int) $exit_content;
    }
    return $res;
}

/**
 * Helper to read a slice of log file.
 *
 * @param string $log_file File path.
 * @param int    $offset   Byte offset.
 * @param int    $limit    Max bytes to read.
 * @return array{content: string, bytes_read: int, truncated: bool}|WP_Error
 */
function novamira_read_log_slice(string $log_file, int $offset, int $limit): array|WP_Error
{
    $size = (int) filesize($log_file);
    if ($offset >= $size) {
        return [
            'content' => '',
            'bytes_read' => 0,
            'truncated' => false,
        ];
    }

    $handle = fopen(filename: $log_file, mode: 'rb');
    if ($handle === false) {
        return new WP_Error('read_failed', sprintf(__('Could not open log file: %s', domain: 'novamira'), $log_file));
    }

    if ($offset > 0) {
        fseek($handle, $offset);
    }

    $read_length = $limit === -1 ? $size - $offset : $limit;
    $content = fread($handle, max(1, $read_length));
    fclose($handle);

    if ($content === false) {
        return new WP_Error('read_failed', sprintf(__('Could not read log file: %s', domain: 'novamira'), $log_file));
    }

    $bytes_read = strlen($content);
    $truncated = $limit !== -1 && ($offset + $bytes_read) < $size;

    return [
        'content' => $content,
        'bytes_read' => $bytes_read,
        'truncated' => $truncated,
    ];
}

/**
 * Resolve the WP-CLI invocation for this server, reusing a discovery result cached by an earlier request.
 *
 * See the file docblock for the constant and filter that override this.
 *
 * @return list<string>|null Argv list (executable plus any leading arguments), or null when unknown.
 */
function novamira_find_wp_cli_command(): ?array
{
    return novamira_resolve_wp_cli_command('novamira_cached_wp_cli_discovery');
}

/**
 * Resolve the WP-CLI invocation for this server, searching the host again instead of trusting the cache.
 *
 * @return list<string>|null Argv list (executable plus any leading arguments), or null when unknown.
 */
function novamira_find_fresh_wp_cli_command(): ?array
{
    return novamira_resolve_wp_cli_command('novamira_discover_and_cache_wp_cli_command');
}

/**
 * Apply the constant, host discovery and the filter, in that order.
 *
 * @param callable(): (list<string>|null) $discover Host discovery, reached only when the constant is unset or
 *                                                 malformed. A constant that fails validation is reported
 *                                                 instead (see novamira_wp_cli_constant_problem()).
 * @return list<string>|null
 */
function novamira_resolve_wp_cli_command(callable $discover): ?array
{
    $command = null;
    $constant_problem = novamira_wp_cli_constant_problem();

    if ($constant_problem === null && defined('NOVAMIRA_WP_CLI_COMMAND')) {
        $command = novamira_normalize_wp_cli_command(constant('NOVAMIRA_WP_CLI_COMMAND'));
    }

    // Explicit configuration wins: a value that cannot work is reported, not replaced by discovery,
    // and a constant never reads or writes the discovery cache.
    if ($command === null && $constant_problem === null) {
        $command = $discover();
    }

    /**
     * Filter the resolved WP-CLI invocation.
     *
     * Return a list of argv strings, for example
     * ['C:/php/php.exe', '-c', 'C:/site/php.ini', 'C:/local/wp-cli.phar'], or a single executable
     * path. Return null (or an invalid value) to report WP-CLI as unavailable. Ability arguments
     * are appended to this list and are never part of it.
     *
     * @param list<string>|null $command Resolved argv list, or null when discovery found nothing or
     *                                   the constant failed validation.
     */
    /** @var mixed $filtered */
    $filtered = apply_filters('novamira_wp_cli_command', $command);

    return novamira_normalize_wp_cli_command($filtered);
}

/**
 * Discover WP-CLI on this host, reusing the result of an earlier request when it still applies.
 *
 * Ability registration asks for WP-CLI on every request that initializes the Abilities API, which
 * is every REST and MCP request. Discovery launches processes (`which` and `command -v` on Unix,
 * `where` through cmd.exe three times on Windows) and globs the Local install directories, which
 * costs hundreds of milliseconds where process creation is slow. The result is therefore stored in
 * a transient, and a request with a matching entry launches nothing.
 *
 * The entry is named after the inputs it was computed for (see
 * novamira_wp_cli_discovery_transient()), so it is only reused while they are unchanged. A found
 * invocation is also dropped when it no longer names an absolute program that can be started and
 * an existing entry point (see novamira_wp_cli_command_is_reusable()), which is a stat, not a
 * process launch. A path open_basedir refuses to inspect cannot be checked and is accepted as stored.
 *
 * @return list<string>|null
 */
function novamira_cached_wp_cli_discovery(): ?array
{
    /** @var mixed $cached */
    $cached = get_transient(novamira_wp_cli_discovery_transient());
    if (!is_array($cached) || !array_key_exists('command', $cached)) {
        return novamira_discover_and_cache_wp_cli_command();
    }

    /** @var mixed $cached_command */
    $cached_command = $cached['command'] ?? null;
    if ($cached_command === null) {
        return null;
    }

    $command = novamira_normalize_wp_cli_command($cached_command);
    if ($command === null || !novamira_wp_cli_command_is_reusable($command)) {
        return novamira_discover_and_cache_wp_cli_command();
    }

    return $command;
}

/**
 * Search the host for WP-CLI and store the result for later requests.
 *
 * Lifetimes: a found invocation is kept for a day, because it is re-checked on disk every time it
 * is read and a run always searches again (see novamira_run_wp_cli()). A "not found" result is
 * kept for fifteen minutes, so WP-CLI installed afterwards is picked up soon without searching on
 * every request of a host that does not have it.
 *
 * A relative program or entry point (`which` reports one for a relative PATH entry) is joined to
 * the working directory discovery ran in, so the stored invocation names the file that was found
 * and still names it when a later run starts from ABSPATH. A result that cannot be made absolute,
 * or that does not pass novamira_wp_cli_command_is_reusable(), is handled as "not found": nothing
 * could safely run it, and caching that outcome keeps the next requests from searching again.
 *
 * @return list<string>|null
 */
function novamira_discover_and_cache_wp_cli_command(): ?array
{
    $command = PHP_OS_FAMILY === 'Windows'
        ? novamira_find_wp_cli_command_windows()
        : novamira_find_wp_cli_command_unix();

    if ($command !== null) {
        $command = novamira_absolutize_wp_cli_command($command, novamira_wp_cli_working_directory());
    }
    if ($command !== null && !novamira_wp_cli_command_is_reusable($command)) {
        $command = null;
    }

    set_transient(
        novamira_wp_cli_discovery_transient(),
        ['command' => $command],
        $command === null ? 15 * MINUTE_IN_SECONDS : DAY_IN_SECONDS,
    );

    return $command;
}

/**
 * Name of the transient holding the discovery result for the current environment.
 *
 * The name carries a hash of every input that decides what discovery finds (see
 * novamira_wp_cli_discovery_inputs()), so a different host, PATH or PATHEXT, PHP build or SAPI,
 * php.ini, open_basedir, WordPress directory, Local install location, override constant or plugin version - and
 * the working directory, where it can matter - searches again instead of reporting what an
 * earlier environment had. The host is part of it because several web nodes can share one
 * database or object cache while each has its own filesystem. WP-CLI and the web server share
 * the database but usually not the environment, and keying the name rather than the stored value
 * lets each keep its own entry instead of overwriting the other's. Entries left behind by an
 * environment that changed expire on their own.
 *
 * @return string
 */
function novamira_wp_cli_discovery_transient(): string
{
    return NOVAMIRA_WP_CLI_DISCOVERY_TRANSIENT_PREFIX . novamira_wp_cli_discovery_fingerprint();
}

/**
 * Hash the inputs that decide what discovery finds.
 *
 * @return string
 */
function novamira_wp_cli_discovery_fingerprint(): string
{
    return md5(serialize(novamira_wp_cli_discovery_inputs()));
}

/**
 * The inputs that decide what discovery finds.
 *
 * PATHEXT decides which names `where` matches. The working directory is included only where it
 * can change the result (see novamira_wp_cli_discovery_depends_on_cwd()), so requests entering
 * WordPress through different scripts share one entry otherwise.
 *
 * Every lookup that PHP lets an administrator remove with disable_functions is guarded: a removed
 * function contributes null, which makes the key less specific but never stops the request.
 *
 * @return array<string, mixed>
 */
function novamira_wp_cli_discovery_inputs(): array
{
    $environment = [];
    foreach ([
        'PATH',
        'PATHEXT',
        'HOSTNAME',
        'COMPUTERNAME',
        'WP_CLI_PHP',
        'LOCALAPPDATA',
        'ProgramFiles',
        'ProgramFiles(x86)',
        'ProgramW6432',
        'APPDATA',
    ] as $name) {
        $environment[$name] = novamira_wp_cli_getenv($name);
    }

    return [
        'host' => function_exists('gethostname') ? gethostname() : null,
        'node' => function_exists('php_uname') ? php_uname('n') : null,
        'cwd' => novamira_wp_cli_discovery_depends_on_cwd() ? novamira_wp_cli_working_directory() : null,
        'os' => PHP_OS_FAMILY,
        'sapi' => PHP_SAPI,
        'env' => $environment,
        'php_binary' => PHP_BINARY,
        'php_bindir' => PHP_BINDIR,
        'php_ini' => function_exists('php_ini_loaded_file') ? php_ini_loaded_file() : null,
        'abspath' => ABSPATH,
        'exec' => function_exists('exec'),
        'command' => defined('NOVAMIRA_WP_CLI_COMMAND') ? constant('NOVAMIRA_WP_CLI_COMMAND') : null,
        'php_ini_override' => defined('NOVAMIRA_WP_CLI_PHP_INI') ? constant('NOVAMIRA_WP_CLI_PHP_INI') : null,
        'open_basedir' => function_exists('ini_get') ? ini_get('open_basedir') : null,
        'version' => defined('NOVAMIRA_VERSION') ? constant('NOVAMIRA_VERSION') : null,
    ];
}

/**
 * Read an environment variable, or null when it is unset or getenv() is disabled.
 *
 * @param string $name Variable name.
 * @return string|null
 */
function novamira_wp_cli_getenv(string $name): ?string
{
    if (!function_exists('getenv')) {
        return null;
    }
    $value = getenv($name);

    return is_string($value) ? $value : null;
}

/**
 * The working directory discovery runs in, or null when PHP cannot report it.
 *
 * @return string|null
 */
function novamira_wp_cli_working_directory(): ?string
{
    if (!function_exists('getcwd')) {
        return null;
    }
    $cwd = getcwd();

    return is_string($cwd) ? $cwd : null;
}

/**
 * Whether the working directory can change what discovery finds.
 *
 * On Windows `where` searches the working directory before the PATH. Elsewhere it only matters
 * when the PATH has a relative entry, including an empty one, which POSIX shells read as the
 * working directory.
 *
 * @param bool|null   $windows Target platform, or null for the running one.
 * @param string|null $path    PATH value, or null to read the running one.
 * @return bool
 */
function novamira_wp_cli_discovery_depends_on_cwd(?bool $windows = null, ?string $path = null): bool
{
    $windows ??= PHP_OS_FAMILY === 'Windows';
    if ($windows) {
        return true;
    }

    $path ??= novamira_wp_cli_getenv('PATH');
    if ($path === null) {
        return false;
    }
    foreach (explode(PATH_SEPARATOR, $path) as $entry) {
        if (!str_starts_with($entry, '/')) {
            return true;
        }
    }

    return false;
}

/**
 * Make the program and entry point of a discovered invocation absolute.
 *
 * A relative path is joined to the directory discovery resolved it from. The join is textual, not
 * realpath(), so a symlinked launcher keeps running as the launcher. On Windows a drive-relative
 * ("C:wp.exe") or root-relative ("\wp.exe") path depends on state this cannot see and is refused.
 *
 * @param list<string> $command Discovered invocation.
 * @param string|null  $base    Directory discovery ran in, or null when unknown.
 * @param bool|null    $windows Target platform, or null for the running one.
 * @return list<string>|null Null when a path cannot be made absolute.
 */
function novamira_absolutize_wp_cli_command(array $command, ?string $base, ?bool $windows = null): ?array
{
    $windows ??= PHP_OS_FAMILY === 'Windows';
    $absolute = [];
    $last = count($command) - 1;

    foreach ($command as $index => $part) {
        if ($index !== 0 && $index !== $last) {
            $absolute[] = $part;
            continue;
        }
        if ($windows ? novamira_is_absolute_windows_path($part) : str_starts_with($part, '/')) {
            $absolute[] = $part;
            continue;
        }
        if ($base === null || $windows && preg_match('#^(?:[A-Za-z]:|[\\\\/])#', $part) === 1) {
            return null;
        }
        $separator = $windows ? '\\' : '/';
        $absolute[] = rtrim($base, characters: '/\\') . $separator . $part;
    }

    return $absolute;
}

/**
 * Whether a discovered invocation may be stored for, or reused by, a later request.
 *
 * The first element is the program and the last is the entry point: the same path for a directly
 * started `wp`, the phar for `php.exe -c php.ini wp-cli.phar`. Both must be absolute, so the check
 * does not depend on the working directory of the request that reads it, and both must still be
 * files. Outside Windows the program must also still be executable; Windows has no execute bit,
 * so there a file is enough.
 *
 * A path open_basedir refuses to inspect passes these file checks, silently: it was reported by a
 * process, and open_basedir does not restrict what a process can start. Only a run can tell whether
 * it still works, and a run always searches again, so a stale entry only affects whether the
 * WP-CLI abilities are listed.
 *
 * @param list<string> $command Resolved invocation.
 * @param bool|null    $windows Target platform, or null for the running one.
 * @return bool
 */
function novamira_wp_cli_command_is_reusable(array $command, ?bool $windows = null): bool
{
    $windows ??= PHP_OS_FAMILY === 'Windows';
    $program = $command[0] ?? '';
    $entry_point = $command[count($command) - 1] ?? '';

    foreach ([$program, $entry_point] as $path) {
        $absolute = $windows ? novamira_is_absolute_windows_path($path) : str_starts_with($path, '/');
        if (!$absolute || novamira_wp_cli_inspect_path($path, check: 'is_file') === false) {
            return false;
        }
    }

    return $windows || novamira_wp_cli_inspect_path($program, check: 'is_executable') !== false;
}

/**
 * Whether a path is absolute under Windows rules.
 *
 * @param string $path Candidate path.
 * @return bool
 */
function novamira_is_absolute_windows_path(string $path): bool
{
    // A drive letter followed by a separator, or a UNC path. "C:foo" is relative to the drive's
    // current directory and "\foo" to the current drive, so neither qualifies.
    return preg_match('#^(?:[A-Za-z]:[\\\\/]|[\\\\/]{2}[^\\\\/])#', $path) === 1;
}

/**
 * Validate an argv list coming from a constant, a filter or discovery.
 *
 * A string is accepted as a single executable path; it is never split into words, so nothing here
 * can turn configuration into an unescaped shell command line.
 *
 * @param mixed $value Candidate invocation.
 * @return list<string>|null
 */
function novamira_normalize_wp_cli_command(mixed $value): ?array
{
    if (is_string($value)) {
        $value = [$value];
    }

    if (!is_array($value) || $value === []) {
        return null;
    }

    $command = [];
    /** @var mixed $part */
    foreach ($value as $part) {
        if (!is_string($part)) {
            return null;
        }
        $part = trim($part);
        if ($part === '') {
            return null;
        }
        $command[] = $part;
    }

    return $command;
}

/**
 * Explain why the NOVAMIRA_WP_CLI_COMMAND constant cannot work.
 *
 * @return string|null The reason, or null when the constant is unset, ignored as malformed, or may work.
 */
function novamira_wp_cli_constant_problem(): ?string
{
    if (!defined('NOVAMIRA_WP_CLI_COMMAND')) {
        return null;
    }

    $command = novamira_normalize_wp_cli_command(constant('NOVAMIRA_WP_CLI_COMMAND'));

    return $command === null ? null : novamira_wp_cli_command_problem($command);
}

/**
 * Check a configured invocation against the filesystem.
 *
 * Only stats files: it never starts a process and does not try to prove the program is WP-CLI. It
 * reports what can be shown wrong and lets everything else through, so a working value is not hidden:
 *
 * - A program with no directory separator is looked up on the PATH when started, so it is unchecked.
 * - A relative program resolves against ABSPATH, the working directory of both run paths. On Windows
 *   it is unchecked: CreateProcess resolves it against the parent's directory instead.
 * - open_basedir does not apply to started processes, so a path it refuses is unchecked.
 * - Windows has no reliable executable bit, and starts an extension-less program by its extension.
 * - Any function PHP's disable_functions removed leaves the whole value unchecked: a check that cannot
 *   run shows nothing, and calling the function would be fatal.
 *
 * @param list<string> $command Normalized invocation.
 * @param bool|null    $windows Whether to apply Windows path rules. Defaults to the current host.
 * @return string|null The reason, or null when nothing can be shown wrong.
 */
function novamira_wp_cli_command_problem(array $command, ?bool $windows = null): ?string
{
    if (!novamira_wp_cli_validation_functions_exist()) {
        return null;
    }

    $windows ??= PHP_OS_FAMILY === 'Windows';

    $problem = novamira_wp_cli_program_problem($command[0] ?? '', $windows);
    if ($problem !== null) {
        return $problem;
    }

    $phar = novamira_wp_cli_phar_entry_point($command);
    $path = $phar === null ? null : novamira_wp_cli_resolve_configured_path($phar, $windows);
    if ($path === null || novamira_wp_cli_open_basedir_refuses($path) || is_file($path)) {
        return null;
    }

    return sprintf(
        /* translators: %s: path of the configured phar */
        __(
            'The NOVAMIRA_WP_CLI_COMMAND constant names a phar that does not exist or is not a file: %s. Correct the constant, or remove it to search this server for WP-CLI.',
            domain: 'novamira',
        ),
        $path,
    );
}

/**
 * Whether every internal function reached by novamira_wp_cli_command_problem() is available.
 *
 * @return bool
 */
function novamira_wp_cli_validation_functions_exist(): bool
{
    $required = [
        'array_filter',
        'array_slice',
        'basename',
        'file_exists',
        'in_array',
        'ini_get',
        'is_executable',
        'is_file',
        'pathinfo',
        'preg_match',
        'restore_error_handler',
        'rtrim',
        'set_error_handler',
        'sprintf',
        'str_contains',
        'str_ends_with',
        'str_starts_with',
        'strpbrk',
        'strpos',
        'strtolower',
        'substr',
    ];
    foreach ($required as $function) {
        if (!function_exists($function)) {
            return false;
        }
    }

    return true;
}

/**
 * Check the program of a configured invocation. See novamira_wp_cli_command_problem() for the rules.
 *
 * @param string    $program Program path or name.
 * @param bool|null $windows Whether to apply Windows path rules. Defaults to the current host.
 * @return string|null
 */
function novamira_wp_cli_program_problem(string $program, ?bool $windows = null): ?string
{
    $windows ??= PHP_OS_FAMILY === 'Windows';

    if (($windows ? strpbrk($program, characters: '/\\') : strpos($program, needle: '/')) === false) {
        return null;
    }

    $path = novamira_wp_cli_resolve_configured_path($program, $windows);
    if ($path === null || $windows && $path !== $program || novamira_wp_cli_open_basedir_refuses($path)) {
        return null;
    }

    $extensions = $windows ? ['', '.exe', '.com', '.bat', '.cmd'] : [''];
    if (array_filter($extensions, static fn(string $extension): bool => is_file($path . $extension)) === []) {
        return sprintf(
            /* translators: %s: path of the configured program */
            __(
                'The NOVAMIRA_WP_CLI_COMMAND constant names a program that does not exist or is not a file: %s. Correct the constant, or remove it to search this server for WP-CLI.',
                domain: 'novamira',
            ),
            $path,
        );
    }

    if ($windows || is_executable($path)) {
        return null;
    }

    return sprintf(
        /* translators: %s: path of the configured program */
        __(
            'The NOVAMIRA_WP_CLI_COMMAND constant names a program that is not executable: %s. Correct the constant or the file permissions, or remove the constant to search this server for WP-CLI.',
            domain: 'novamira',
        ),
        $path,
    );
}

/**
 * Find the phar a configured invocation hands to PHP.
 *
 * Follows PHP's `[options] [-f] <file> [--] [args]`, and only for a PHP binary: a wrapper's arguments,
 * such as `docker exec`, may not name a local file at all. The script is the value of `-f` or else the
 * first item that is not an option. It counts only when it ends in `.phar`, the test
 * novamira_wp_cli_command_for_path() applies.
 *
 * Only the options listed here are understood, in each form PHP accepts. Any other option may take a
 * value, run inline code or not run a script at all, so it leaves the entry point unchecked.
 *
 * @param list<string> $command Normalized invocation.
 * @return string|null
 */
function novamira_wp_cli_phar_entry_point(array $command): ?string
{
    if (!novamira_is_php_cli_binary($command[0] ?? '')) {
        return null;
    }

    $script = null;
    $next_is = null;
    $attached = [];
    foreach (array_slice($command, offset: 1) as $item) {
        if ($next_is !== null) {
            $script = $next_is === 'script' ? $item : $script;
            $next_is = null;
            continue;
        }
        if (!str_starts_with($item, '-')) {
            $script ??= $item;
            break;
        }
        if ($item === '--') {
            break;
        }
        if (in_array($item, ['-f', '--file'], strict: true)) {
            $next_is = 'script';
            continue;
        }
        if (in_array($item, ['-c', '--php-ini', '-d', '--define', '-z', '--zend-extension'], strict: true)) {
            $next_is = 'value';
            continue;
        }
        if (preg_match('/^(?:-f=?|--file=)(.+)$/', $item, $attached) === 1) {
            $script = $attached[1];
            continue;
        }
        $understood = in_array($item, ['-n', '--no-php-ini', '-q', '-e', '-H'], strict: true);
        if (!$understood && preg_match('/^(?:-[cdz].|--(?:php-ini|define|zend-extension)=)/', $item) !== 1) {
            return null;
        }
    }

    return $script !== null && strtolower(pathinfo($script, PATHINFO_EXTENSION)) === 'phar' ? $script : null;
}

/**
 * Resolve a configured path the way it resolves at run time, when both run paths start in ABSPATH.
 *
 * @param string    $path    Configured path.
 * @param bool|null $windows Whether to apply Windows path rules. Defaults to the current host.
 * @return string|null The path to stat, or null for a Windows path missing a drive or a root. `C:wp.exe`
 *                     and `/tools/wp.exe` depend on the child's drive, which need not be this process's.
 */
function novamira_wp_cli_resolve_configured_path(string $path, ?bool $windows = null): ?string
{
    $windows ??= PHP_OS_FAMILY === 'Windows';

    if (!$windows && str_starts_with($path, '/')) {
        return $path;
    }
    if ($windows) {
        if (preg_match('#^(?:[A-Za-z]:[\\\\/]|\\\\\\\\|//)#', $path) === 1) {
            return $path;
        }
        if (preg_match('#^(?:[A-Za-z]:|[\\\\/])#', $path) === 1) {
            return null;
        }
    }

    return rtrim(ABSPATH, characters: '/\\') . '/' . $path;
}

/**
 * Whether open_basedir stops PHP from seeing a path at all, which says nothing about the path itself.
 *
 * Shares the warning-swallowing probe of novamira_wp_cli_inspect_path(), so a refused path never prints
 * an open_basedir warning into the response and any other warning still reaches the previous handler.
 *
 * @param string $path Path to probe.
 * @return bool
 */
function novamira_wp_cli_open_basedir_refuses(string $path): bool
{
    return novamira_wp_cli_inspect_path($path, check: 'file_exists') === null;
}

/**
 * Discover WP-CLI on Unix-like hosts.
 *
 * @return list<string>|null
 */
function novamira_find_wp_cli_command_unix(): ?array
{
    if (function_exists('exec')) {
        $path = novamira_first_command_output_line('which wp 2>/dev/null');
        if ($path !== null) {
            return [$path];
        }

        $path = novamira_first_command_output_line('command -v wp 2>/dev/null');
        if ($path !== null) {
            return [$path];
        }
    }

    $common_paths = [
        '/usr/local/bin/wp',
        '/usr/bin/wp',
        '/bin/wp',
        '/usr/local/sbin/wp',
        '/usr/sbin/wp',
    ];
    foreach ($common_paths as $path) {
        if (novamira_wp_cli_probe_path(
            $path,
            static fn(string $file): bool => is_file($file) && is_executable($file),
        )) {
            return [$path];
        }
    }

    return null;
}

/**
 * Discover WP-CLI on Windows hosts, including Local (Local by Flywheel) installs.
 *
 * @return list<string>|null
 */
function novamira_find_wp_cli_command_windows(): ?array
{
    $php_binary = novamira_find_php_binary();
    $php_ini = novamira_find_wp_cli_php_ini();

    $candidates = [];
    if (function_exists('exec')) {
        foreach (['wp', 'wp.phar', 'wp-cli.phar'] as $name) {
            foreach (novamira_command_output_lines('where ' . escapeshellarg($name) . ' 2>NUL') as $line) {
                $candidates[] = $line;
            }
        }
    }
    foreach (novamira_windows_wp_cli_candidate_paths() as $path) {
        $candidates[] = $path;
    }

    foreach ($candidates as $candidate) {
        if (!novamira_wp_cli_probe_path($candidate, check: 'is_file')) {
            continue;
        }

        $command = novamira_wp_cli_command_for_path($candidate, $php_binary, $php_ini);
        if ($command !== null) {
            return $command;
        }
    }

    return null;
}

/**
 * Build the invocation for a discovered WP-CLI entry point.
 *
 * A phar is not executable by itself and has to be handed to a PHP binary.
 *
 * A `.bat` or `.cmd` launcher is deliberately never used as the program. Starting one means
 * `cmd.exe /c <launcher> <args>`, and cmd re-parses everything after `/c` with its own rules: it
 * expands `%VAR%`, and it counts raw double quotes, so the `\"` that proc_open writes for an
 * embedded quote closes cmd's quoted state instead of escaping anything and leaves the rest of
 * that argument as cmd syntax, where `&` starts a second command. The arguments are ability input,
 * so an ordinary value such as a post title containing a quote would change the command that runs
 * and a search for `100% off` would silently become something else. There is no escape that fixes
 * this from the outside - `%%` is a batch-file form and does not apply to a command line - which
 * is the same defect catalogued as BatBadBut across several language runtimes. The Windows WP-CLI
 * package ships `wp.bat` beside the `wp-cli.phar` it runs, so the phar is used directly and the
 * launcher is skipped; if no phar is there, resolution continues to the next candidate and, if
 * nothing else is found, reports WP-CLI as not found with the constant and filter as the fix.
 *
 * @param string      $path       Path to a WP-CLI launcher or phar.
 * @param string|null $php_binary PHP binary used to run a phar, or null when none was found.
 * @param string|null $php_ini    php.ini passed to that PHP binary with -c, or null to omit it.
 * @return list<string>|null
 */
function novamira_wp_cli_command_for_path(string $path, ?string $php_binary, ?string $php_ini): ?array
{
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    if ($extension === 'phar') {
        if ($php_binary === null || $php_binary === '') {
            return null;
        }

        $command = [$php_binary];
        if ($php_ini !== null && $php_ini !== '') {
            $command[] = '-c';
            $command[] = $php_ini;
        }
        $command[] = $path;

        return $command;
    }

    if ($extension === 'bat' || $extension === 'cmd') {
        $phar = novamira_wp_cli_phar_beside_launcher($path);

        return $phar === null ? null : novamira_wp_cli_command_for_path($phar, $php_binary, $php_ini);
    }

    if ($extension === 'exe') {
        return [$path];
    }

    // Extension-less launchers on Windows are shell scripts the platform cannot start.
    return PHP_OS_FAMILY === 'Windows' ? null : [$path];
}

/**
 * Find the phar a Windows batch launcher runs, next to the launcher itself.
 *
 * The WP-CLI Windows package installs `wp.bat` and `wp-cli.phar` in the same directory, and the
 * batch file does nothing but hand the phar to PHP. Running the phar directly reaches the same
 * program without putting ability input through cmd.exe.
 *
 * @param string $launcher Path to a .bat or .cmd launcher.
 * @return string|null
 */
function novamira_wp_cli_phar_beside_launcher(string $launcher): ?string
{
    $directory = dirname($launcher);
    $base = pathinfo($launcher, PATHINFO_FILENAME);

    foreach ([$base . '-cli.phar', $base . '.phar', 'wp-cli.phar'] as $name) {
        $candidate = $directory . DIRECTORY_SEPARATOR . $name;
        if (novamira_wp_cli_probe_path($candidate, check: 'is_file')) {
            return $candidate;
        }
    }

    return null;
}

/**
 * Well-known Windows locations that may hold a wp-cli phar.
 *
 * @return list<string>
 */
function novamira_windows_wp_cli_candidate_paths(): array
{
    $patterns = [];
    foreach (['LOCALAPPDATA', 'ProgramFiles', 'ProgramFiles(x86)', 'ProgramW6432', 'APPDATA'] as $variable) {
        $value = getenv($variable);
        if (!is_string($value) || trim($value) === '') {
            continue;
        }

        $root = rtrim(str_replace(search: '\\', replace: '/', subject: trim($value)), characters: '/');
        foreach (['Programs/Local', 'Local', 'Local by Flywheel'] as $app_dir) {
            $patterns[] = $root . '/' . $app_dir . '/resources/extraResources/*/wp-cli*.phar';
            $patterns[] = $root . '/' . $app_dir . '/resources/extraResources/*/*/wp-cli*.phar';
        }
    }

    $abspath = rtrim(str_replace(search: '\\', replace: '/', subject: ABSPATH), characters: '/');
    $patterns[] = $abspath . '/wp-cli.phar';
    $patterns[] = dirname($abspath) . '/wp-cli.phar';

    $paths = [];
    foreach ($patterns as $pattern) {
        $matches = glob($pattern);
        if ($matches === false) {
            continue;
        }
        foreach ($matches as $match) {
            $paths[] = $match;
        }
    }

    return $paths;
}

/**
 * Whether a path names a PHP command line interpreter rather than a server SAPI binary.
 *
 * `php`, `php.exe`, `php8` and `php8.2` are interpreters that read a script path from argv and
 * write nothing but its output. `php-cgi`, `php-fpm` and `php-win` are not: php-cgi in particular
 * emits CGI headers (`X-Powered-By`, `Content-type`, a blank line) before any script output unless
 * it is given -q, so handing it a wp-cli phar produces output no caller can parse. A prefix test
 * on "php" accepts all of them, so the name is matched exactly.
 *
 * @param string $path Candidate binary path.
 * @return bool
 */
function novamira_is_php_cli_binary(string $path): bool
{
    if (trim($path) === '') {
        return false;
    }

    $name = strtolower(basename(trim($path)));
    if (str_ends_with($name, '.exe')) {
        $name = substr($name, offset: 0, length: -4);
    }

    return preg_match('/^php\d*(?:\.\d+)*$/', $name) === 1;
}

/**
 * Locate a PHP binary able to run a phar.
 *
 * @return string|null
 */
function novamira_find_php_binary(): ?string
{
    $name = PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php';
    $candidates = [];

    $configured = getenv('WP_CLI_PHP');
    if (is_string($configured) && trim($configured) !== '') {
        $candidates[] = trim($configured);
    }

    // The CLI binary next to the running SAPI: on Local this is the site's own PHP build, whose
    // php.ini and extension directory WP-CLI has to match. PHP_BINARY is the interpreter this
    // request is executing in, so its directory is a real path on this machine; PHP_BINDIR is
    // not, on Windows: it is the prefix baked in when the binary was built, "C:\php" for the
    // official builds, which either does not exist or holds an unrelated PHP installation.
    $candidates[] = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . $name;

    // Unix builds do record a usable bindir, and it is where a distribution keeps the CLI binary
    // when the running SAPI is a server module.
    $candidates[] = PHP_BINDIR . DIRECTORY_SEPARATOR . $name;

    // PHP_BINARY itself only when it really is the CLI interpreter: under FastCGI it is
    // php-cgi.exe, and under a server module it is the server.
    if (novamira_is_php_cli_binary(PHP_BINARY)) {
        $candidates[] = PHP_BINARY;
    }

    foreach ($candidates as $candidate) {
        if (novamira_wp_cli_probe_path($candidate, check: 'is_file')) {
            return $candidate;
        }
    }

    return null;
}

/**
 * Resolve the php.ini handed to a PHP binary that runs the wp-cli phar.
 *
 * Reusing the configuration this process already loaded is what keeps a Local site's extensions
 * (notably mysqli) available to WP-CLI.
 *
 * @return string|null
 */
function novamira_find_wp_cli_php_ini(): ?string
{
    if (defined('NOVAMIRA_WP_CLI_PHP_INI')) {
        /** @var mixed $configured */
        $configured = constant('NOVAMIRA_WP_CLI_PHP_INI');
        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }
    }

    $loaded = function_exists('php_ini_loaded_file') ? php_ini_loaded_file() : false;
    if ($loaded !== false && $loaded !== '' && novamira_wp_cli_probe_path($loaded, check: 'is_file')) {
        return $loaded;
    }

    return null;
}

/**
 * Run a filesystem check on a path that may lie outside open_basedir, treating a refused path as absent.
 *
 * For locations nothing has reported to exist. A path reported by a process goes through
 * novamira_wp_cli_inspect_path() instead, because open_basedir says nothing about whether it can be started.
 *
 * @param string                $path  Path to probe.
 * @param callable(string):bool $check Filesystem check to run on it, such as is_file().
 * @return bool
 */
function novamira_wp_cli_probe_path(string $path, callable $check): bool
{
    return novamira_wp_cli_inspect_path($path, $check) === true;
}

/**
 * Run a filesystem check on a path that may lie outside open_basedir, reporting a refusal separately.
 *
 * Probing a path outside open_basedir emits an "open_basedir restriction in effect" warning, which
 * lands in the HTTP response when display_errors is on and corrupts any JSON body after it. Only
 * PHP knows whether it will refuse a path: it resolves symlinks in both the path and the entries,
 * resolves ".." through them, and expands relative paths and the "." entry against the working
 * directory, none of which a string comparison can reproduce without touching the path. So the
 * check runs as usual, and that one warning is swallowed for its duration and marks the path as
 * refused. Any other error still reaches the previous handler or PHP's own. Without ini_get() the
 * restriction cannot be ruled out, so the check runs the same way.
 *
 * When disable_functions removed set_error_handler(), restore_error_handler() or str_contains(), the
 * handler cannot be installed (calling a removed function is a fatal error), and the path is not touched
 * at all: the answer is unknown (null), exactly as for a refusal. Probing without the handler would emit
 * the warning into whatever handler is already installed, which could print it, throw it, or consume it
 * and turn a refusal into a false absence. Unknown never hides a working value and never fails the
 * request: a path a process reported, or a cached one, is kept (a run always searches again), and a
 * well-known location counts as absent, as one open_basedir refuses always has.
 *
 * @param string                $path  Path to inspect.
 * @param callable(string):bool $check Filesystem check to run on it, such as is_file().
 * @return bool|null The result of the check, or null when open_basedir refused to let it look or the
 *                   check could not run safely.
 */
function novamira_wp_cli_inspect_path(string $path, callable $check): ?bool
{
    if (function_exists('ini_get') && (string) ini_get('open_basedir') === '') {
        return $check($path);
    }

    if (
        !function_exists('set_error_handler')
        || !function_exists('restore_error_handler')
        || !function_exists('str_contains')
    ) {
        return null;
    }

    // Set by the handler through its reference.
    /** @var bool $refused */
    $refused = false;
    // Assigned below; the handler reads it by reference so it can defer to whatever was there.
    /** @var null|callable(int, string, string, int): mixed $previous */
    $previous = null;
    $previous = set_error_handler(
        static function (int $errno, string $message, string $file = '', int $line = 0) use (
            &$previous,
            &$refused,
        ): bool {
            if (str_contains($message, 'open_basedir restriction in effect')) {
                $refused = true;
                return true;
            }

            // As PHP itself does, only an explicit false hands the error on to the standard handler.
            return $previous !== null && $previous($errno, $message, $file, $line) !== false;
        },
        E_WARNING,
    );

    try {
        $result = $check($path);
    } finally {
        restore_error_handler();
    }

    return $refused ? null : $result;
}

/**
 * Path of the Windows command interpreter.
 *
 * @return string
 */
function novamira_windows_command_interpreter(): string
{
    $comspec = getenv('ComSpec');
    if (is_string($comspec) && trim($comspec) !== '') {
        return trim($comspec);
    }

    return 'cmd.exe';
}

/**
 * Run a discovery command and return its first non-empty output line.
 *
 * @param string $command Fully escaped command line.
 * @return string|null
 */
function novamira_first_command_output_line(string $command): ?string
{
    $output = [];
    $return_var = 0;
    exec($command, $output, $return_var);
    $first_out = $output[0] ?? null;
    if ($return_var === 0 && is_string($first_out) && trim($first_out) !== '') {
        return trim($first_out);
    }

    return null;
}

/**
 * Run a discovery command and return all of its non-empty output lines.
 *
 * @param string $command Fully escaped command line.
 * @return list<string>
 */
function novamira_command_output_lines(string $command): array
{
    $output = [];
    $return_var = 0;
    exec($command, $output, $return_var);
    if ($return_var !== 0) {
        return [];
    }

    $lines = [];
    /** @var mixed $line */
    foreach ($output as $line) {
        if (!is_string($line)) {
            continue;
        }
        $line = trim($line);
        if ($line !== '') {
            $lines[] = $line;
        }
    }

    return $lines;
}

/**
 * Operator-facing message describing where WP-CLI was looked for.
 *
 * Only generic, non-sensitive locations are named; the CLI surfaces this text verbatim.
 *
 * @param string $os_family Value of PHP_OS_FAMILY.
 * @return string
 */
function novamira_wp_cli_not_found_message(string $os_family): string
{
    if ($os_family === 'Windows') {
        return __(
            'WP-CLI was not found. Searched the PATH with "where" for wp, wp.phar and wp-cli.phar, the Local (Local by Flywheel) resource directories, the WordPress directory and its parent for wp-cli.phar. A wp.bat or wp.cmd launcher is only used when the wp-cli.phar it runs sits beside it, because starting a batch file would put command arguments through cmd.exe. Set the NOVAMIRA_WP_CLI_COMMAND constant to the full invocation, for example array( "<php.exe>", "-c", "<site php.ini>", "<wp-cli.phar>" ), or use the "novamira_wp_cli_command" filter. NOVAMIRA_WP_CLI_PHP_INI overrides only the php.ini used to run a phar.',
            domain: 'novamira',
        );
    }

    return __(
        'WP-CLI was not found. Searched the PATH with "which wp" and "command -v wp", then /usr/local/bin, /usr/bin, /bin, /usr/local/sbin and /usr/sbin. Set the NOVAMIRA_WP_CLI_COMMAND constant to the wp executable path or the full invocation, or use the "novamira_wp_cli_command" filter.',
        domain: 'novamira',
    );
}

/**
 * Check if the PHP process is executing as root user.
 *
 * @return bool
 */
function novamira_is_current_user_root(): bool
{
    if (PHP_OS_FAMILY === 'Windows') {
        return false;
    }

    if (function_exists('posix_getuid')) {
        return posix_getuid() === 0;
    }

    if (getenv('USER') === 'root' || getenv('USERNAME') === 'root') {
        return true;
    }

    if (function_exists('exec')) {
        $whoami = [];
        $rc = 0;
        exec('whoami 2>/dev/null', $whoami, $rc);
        $first_out = $whoami[0] ?? null;
        if ($rc === 0 && is_string($first_out) && trim($first_out) === 'root') {
            return true;
        }
    }

    return false;
}

/**
 * Execute WP-CLI synchronously.
 *
 * @param list<string> $wp_command Resolved WP-CLI invocation.
 * @param list<string> $args       Arguments array.
 * @return array
 */
function novamira_run_wp_cli_sync(array $wp_command, array $args): array
{
    // proc_open receives an argv array, so it escapes every element itself.
    $cmd = array_merge($wp_command, $args);

    $descriptorspec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    /** @var array<int, resource> $pipes */
    $pipes = [];
    $process = proc_open($cmd, $descriptorspec, $pipes, ABSPATH);

    if (!is_resource($process)) {
        return [
            'success' => false,
            'exit_code' => -1,
            'stdout' => '',
            'stderr' => __('Failed to start process.', domain: 'novamira'),
            // job_id and pid are typed string and integer and are not required, so they are
            // omitted rather than sent as null: a null fails output validation and the caller
            // loses the whole result, including the reason the run failed.
        ];
    }

    $stdin_pipe = $pipes[0] ?? null;
    if (is_resource($stdin_pipe)) {
        fclose($stdin_pipe);
    }

    [$stdout, $stderr] = novamira_collect_process_output(
        stdout_pipe: $pipes[1] ?? null,
        stderr_pipe: $pipes[2] ?? null,
    );

    $exit_code = proc_close($process);

    return [
        'success' => $exit_code === 0,
        'exit_code' => $exit_code,
        'stdout' => $stdout,
        'stderr' => $stderr,
    ];
}

/**
 * Read process stdout and stderr concurrently.
 *
 * @param mixed $stdout_pipe Stdout pipe resource.
 * @param mixed $stderr_pipe Stderr pipe resource.
 * @return array{string, string}
 */
function novamira_collect_process_output(mixed $stdout_pipe, mixed $stderr_pipe): array
{
    if (is_resource($stdout_pipe)) {
        stream_set_blocking(stream: $stdout_pipe, enable: false);
    }
    if (is_resource($stderr_pipe)) {
        stream_set_blocking(stream: $stderr_pipe, enable: false);
    }

    $stdout = '';
    $stderr = '';

    while (is_resource($stdout_pipe) || is_resource($stderr_pipe)) {
        /** @var list<resource> $read */
        $read = [];
        if (is_resource($stdout_pipe)) {
            if (feof($stdout_pipe)) {
                fclose($stdout_pipe);
                $stdout_pipe = null;
            }
            if (is_resource($stdout_pipe)) {
                $read[] = $stdout_pipe;
            }
        }
        if (is_resource($stderr_pipe)) {
            if (feof($stderr_pipe)) {
                fclose($stderr_pipe);
                $stderr_pipe = null;
            }
            if (is_resource($stderr_pipe)) {
                $read[] = $stderr_pipe;
            }
        }

        if ($read === []) {
            break;
        }

        $write = null;
        $except = null;
        $ready = stream_select($read, $write, $except, seconds: 0, microseconds: 200_000);
        if ($ready === false) {
            break;
        }
        if ($ready === 0) {
            continue;
        }

        foreach ($read as $pipe) {
            novamira_append_process_pipe_output($pipe, $stdout_pipe, $stderr_pipe, $stdout, $stderr);
        }
    }

    return [$stdout, $stderr];
}

/**
 * Append one readable process pipe chunk to the correct output buffer.
 *
 * @param mixed  $pipe        Readable pipe resource.
 * @param mixed  $stdout_pipe Stdout pipe resource.
 * @param mixed  $stderr_pipe Stderr pipe resource.
 * @param string $stdout      Stdout buffer.
 * @param string $stderr      Stderr buffer.
 */
function novamira_append_process_pipe_output(
    mixed $pipe,
    mixed $stdout_pipe,
    mixed $stderr_pipe,
    string &$stdout,
    string &$stderr,
): void {
    if (!is_resource($pipe)) {
        return;
    }

    $chunk = stream_get_contents($pipe);
    if ($chunk === false || $chunk === '') {
        return;
    }

    if (is_resource($stdout_pipe) && $pipe === $stdout_pipe) {
        $stdout .= $chunk;
        return;
    }

    if (is_resource($stderr_pipe) && $pipe === $stderr_pipe) {
        $stderr .= $chunk;
    }
}

/**
 * Execute WP-CLI asynchronously.
 *
 * @param list<string> $wp_command Resolved WP-CLI invocation.
 * @param list<string> $args       Arguments array.
 * @return array
 */
function novamira_run_wp_cli_async(array $wp_command, array $args): array
{
    // A Windows job is a batch file, and no escaping can keep a line break inside an argument
    // from ending the line and starting a command of its own. Refuse the input instead.
    if (PHP_OS_FAMILY === 'Windows') {
        foreach ($args as $argument) {
            if (preg_match('/[\r\n\x00]/', $argument) === 1) {
                return [
                    'success' => false,
                    'stdout' => '',
                    'stderr' => __(
                        'WP-CLI arguments cannot contain line breaks or NUL bytes in a background job on Windows.',
                        domain: 'novamira',
                    ),
                ];
            }
        }
    }

    $job_id = bin2hex(random_bytes(8));
    $job_dir = novamira_get_sandbox_dir(ensure_exists: true) . 'wp-cli-jobs/';
    if (!is_dir($job_dir)) {
        wp_mkdir_p($job_dir);
    }

    $log_file = $job_dir . 'job_' . $job_id . '.log';
    $status_file = $job_dir . 'job_' . $job_id . '.status';
    if (file_put_contents(filename: $log_file, data: '') === false) {
        return [
            'success' => false,
            'stderr' => sprintf(__('Failed to create log file: %s', domain: 'novamira'), $log_file),
        ];
    }

    $wp_cmd = novamira_build_wp_cli_shell_command($wp_command, $args);

    if (PHP_OS_FAMILY === 'Windows') {
        return novamira_start_wp_cli_windows_job($wp_cmd, [
            'job_id' => $job_id,
            'dir' => $job_dir,
            'log' => $log_file,
            'status' => $status_file,
        ]);
    }

    $background_cmd = sprintf(
        'cd %s && (%s > %s 2>&1; echo $? > %s)',
        escapeshellarg(ABSPATH),
        $wp_cmd,
        escapeshellarg($log_file),
        escapeshellarg($status_file),
    );

    $cmd = 'nohup sh -c ' . escapeshellarg($background_cmd) . ' > /dev/null 2>&1 & echo $!';

    $pid_output = [];
    $rc = 0;
    exec($cmd, $pid_output, $rc);

    $pid = null;
    $first_out = $pid_output[0] ?? null;
    if ($rc === 0 && is_string($first_out) && trim($first_out) !== '') {
        $pid = (int) trim($first_out);
    }

    if ($rc !== 0 || $pid === null) {
        file_put_contents(filename: $status_file, data: '127');

        return [
            'success' => false,
            'job_id' => $job_id,
            'exit_code' => $rc,
            'stdout' => '',
            'stderr' => __('Failed to start background WP-CLI process.', domain: 'novamira'),
        ];
    }

    return [
        'success' => true,
        'job_id' => $job_id,
        'pid' => $pid,
    ];
}

/**
 * Quote a path this plugin controls for a generated batch file.
 *
 * A Windows path can never contain a double quote, so real quotes are safe, and for the leading
 * executable and for redirection targets they are also required: cmd.exe locates the program and
 * opens the redirection with its own quoting rules, which the caret form used for arguments
 * deliberately switches off. Inside real quotes cmd treats no character as syntax except a
 * percent sign, and a batch file writes a literal percent as two.
 *
 * @param string $path Path to quote.
 * @return string
 */
function novamira_quote_windows_batch_path(string $path): string
{
    return (
        '"'
        . str_replace(search: '%', replace: '%%', subject: str_replace(search: '"', replace: '', subject: $path))
        . '"'
    );
}

/**
 * Escape one argument for a generated Windows batch file.
 *
 * escapeshellarg() must not be used here: on Windows it is documented to replace percent signs,
 * exclamation marks and double quotes with spaces rather than escaping them, so
 * `LIKE '%draft%'` or a password containing `!` would reach WP-CLI as different text than the
 * synchronous path (a proc_open argv array) delivers for the same input.
 *
 * Two parsers read this text in turn. cmd.exe reads the batch line, then the C runtime of the
 * started program splits the command line cmd hands it. The value is therefore quoted for the C
 * runtime first - surrounding quotes, with backslashes doubled only where they precede a quote -
 * and the result is then escaped for cmd.exe: every character cmd treats as syntax gets a caret,
 * and a percent sign is doubled. Because the surrounding quotes are themselves carets-escaped,
 * cmd never enters its own quoted state, so no metacharacter inside the value can start a
 * command, open a pipe, or redirect a file.
 *
 * @param string $value Argument value.
 * @return string
 */
function novamira_escape_windows_batch_arg(string $value): string
{
    $quoted = '"';
    $length = strlen($value);
    $index = 0;
    while ($index < $length) {
        $backslashes = 0;
        while ($index < $length && $value[$index] === '\\') {
            $backslashes++;
            $index++;
        }
        if ($index === $length) {
            // Trailing backslashes would otherwise escape the closing quote.
            $quoted .= str_repeat(string: '\\', times: $backslashes * 2);
            break;
        }
        // Backslashes are only special to the C runtime immediately before a quote, where each
        // one has to be doubled and the quote itself escaped.
        $quoted .= $value[$index] === '"'
            ? str_repeat(string: '\\', times: ($backslashes * 2) + 1) . '"'
            : str_repeat(string: '\\', times: $backslashes) . $value[$index];
        $index++;
    }
    $quoted .= '"';

    $escaped = '';
    $quoted_length = strlen($quoted);
    for ($position = 0; $position < $quoted_length; $position++) {
        $character = $quoted[$position];
        if ($character === '%') {
            $escaped .= '%%';
            continue;
        }
        if (in_array(needle: $character, haystack: ['^', '&', '|', '<', '>', '(', ')', '"', '!'], strict: true)) {
            $escaped .= '^' . $character;
            continue;
        }
        $escaped .= $character;
    }

    return $escaped;
}

/**
 * Build a shell command line for the resolved invocation and the ability arguments.
 *
 * Every element is escaped, so ability input can never reach the shell as syntax. On Unix that is
 * escapeshellarg and the result is identical to the historical "escaped wp path, space, escaped
 * arguments" form. On Windows escapeshellarg is lossy rather than safe, so the batch escaping
 * above is used instead and the same input produces the same argv as the synchronous path.
 *
 * @param list<string> $wp_command Resolved WP-CLI invocation.
 * @param list<string> $args       Arguments array.
 * @param bool|null    $windows    Target platform, or null for the running one.
 * @return string
 */
function novamira_build_wp_cli_shell_command(array $wp_command, array $args, ?bool $windows = null): string
{
    $windows ??= PHP_OS_FAMILY === 'Windows';

    if (!$windows) {
        return (
            implode(' ', array_map('escapeshellarg', $wp_command))
            . ' '
            . implode(' ', array_map('escapeshellarg', $args))
        );
    }

    $parts = [];
    foreach ($wp_command as $index => $element) {
        $parts[] = $index === 0
            ? novamira_quote_windows_batch_path($element)
            : novamira_escape_windows_batch_arg($element);
    }

    return implode(' ', $parts) . ' ' . implode(' ', array_map('novamira_escape_windows_batch_arg', $args));
}

/**
 * Build the batch file that runs one background WP-CLI job and records its exit code.
 *
 * The exit code is written with the redirection in front of the command, `> "file" echo %VAR%`,
 * and never as `echo %ERRORLEVEL%> "file"`. cmd.exe consumes a digit that immediately precedes
 * `>` as the handle number to redirect, so the trailing form records no exit code for any value
 * of ERRORLEVEL: `1` redirects stdout and leaves `ECHO is off.` in the file, which reads back as
 * exit code 0 and reports a failed job as a success; `0` redirects stdin and leaves the file
 * empty; `255` redirects handle 5 and writes nothing.
 *
 * Delayed expansion is switched off explicitly. It is off by default, but the Command Processor
 * registry keys can turn it on machine- or user-wide, and with it on an exclamation mark inside
 * an argument would be expanded instead of passed through.
 *
 * The code page is switched to UTF-8 before any line that can carry a non-ASCII character. PHP
 * hands this function UTF-8 text, but cmd.exe decodes a batch file with the console code page,
 * which on a Danish or Spanish Windows is an OEM page such as 850 - a site path containing `é` or
 * `ø`, or a WP-CLI argument such as a post title, would otherwise be read as mojibake and the job
 * would run against the wrong path or the wrong text. `chcp` is the second line so that cmd has
 * already switched before it reads the `cd` line; cmd re-reads the remainder of the file with the
 * new page. The file is written without a byte order mark, which cmd would try to execute as a
 * command on the first line.
 *
 * @param string $wp_cmd      Escaped command line.
 * @param string $working_dir Directory the job runs in.
 * @param string $log         Log file the job writes to.
 * @param string $status      Status file that receives the exit code.
 * @return string
 */
function novamira_build_windows_job_script(string $wp_cmd, string $working_dir, string $log, string $status): string
{
    return (
        "@echo off\r\n"
        . "chcp 65001 > nul\r\n"
        . "setlocal DisableDelayedExpansion\r\n"
        . 'cd /d '
        . novamira_quote_windows_batch_path($working_dir)
        . "\r\n"
        . $wp_cmd
        . ' > '
        . novamira_quote_windows_batch_path($log)
        . " 2>&1\r\n"
        . '> '
        . novamira_quote_windows_batch_path($status)
        . " echo %ERRORLEVEL%\r\n"
    );
}

/**
 * Start a background WP-CLI job on Windows.
 *
 * The command interpreter cannot express the Unix "nohup ... & echo $!" idiom, so the job is
 * written to a batch file that redirects output to the log and records the exit code, and that
 * file is launched detached. Windows reports no PID for a detached start, so none is returned;
 * job status is still tracked through the status file.
 *
 * The launch deliberately does not put the script's path on a command line. `exec()` hands its
 * string to cmd.exe, and cmd expands `%NAME%` before it does anything else - inside double quotes
 * too, where a caret cannot reach it and no escape exists on the command line, since `%%` is a
 * batch-file form only. A site installed under a directory containing a percent sign (a path such
 * as `C:\sites\100%\htdocs`, or any directory whose name happens to read as a variable) would run
 * something other than the job, and an exclamation mark would be eaten as well wherever the
 * machine has delayed expansion enabled by registry. So the script is started through proc_open
 * with an argument list: PHP passes the arguments to CreateProcess itself instead of building a
 * shell line, the working directory travels as an API parameter that cmd never parses, and the
 * only value cmd does read is the bare file name `job_<hex>.cmd`, which by construction holds
 * nothing cmd treats as syntax. `start` is still needed - it is what returns immediately and
 * leaves the job running - but it now receives a name it cannot rewrite.
 *
 * @param string $wp_cmd Escaped command line.
 * @param array{job_id: string, dir: non-empty-string, log: string, status: string} $job Job paths.
 * @return array
 */
function novamira_start_wp_cli_windows_job(string $wp_cmd, array $job): array
{
    $script_name = 'job_' . $job['job_id'] . '.cmd';
    $script_file = $job['dir'] . $script_name;
    $script = novamira_build_windows_job_script($wp_cmd, ABSPATH, $job['log'], $job['status']);

    if (file_put_contents(filename: $script_file, data: $script) === false) {
        return [
            'success' => false,
            'job_id' => $job['job_id'],
            'stdout' => '',
            'stderr' => sprintf(__('Failed to create job script: %s', domain: 'novamira'), $script_file),
        ];
    }

    // The empty string after `start` is the window title. `start` treats its first quoted token as
    // a title, so omitting it would make it read the script name as one and start nothing.
    $pipes = [];
    $process = proc_open(
        [novamira_windows_command_interpreter(), '/c', 'start', '', '/B', $script_name],
        [['file', 'NUL', 'r'], ['file', 'NUL', 'w'], ['file', 'NUL', 'w']],
        $pipes,
        $job['dir'],
    );

    // `start` returns as soon as it has spawned the job, so cmd.exe exits immediately and this
    // waits only for the launch, never for the job itself.
    $rc = is_resource($process) ? proc_close($process) : 127;

    if ($rc !== 0) {
        file_put_contents(filename: $job['status'], data: '127');

        return [
            'success' => false,
            'job_id' => $job['job_id'],
            'exit_code' => $rc,
            'stdout' => '',
            'stderr' => __('Failed to start background WP-CLI process.', domain: 'novamira'),
        ];
    }

    // No pid key at all. Windows reports no PID for a detached start, and the output schema types
    // pid as an integer: returning null would fail validation and cost the caller the job_id it
    // needs to poll the job that did start.
    return [
        'success' => true,
        'job_id' => $job['job_id'],
    ];
}
