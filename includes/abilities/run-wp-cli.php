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
 *    path of a single executable. It is never parsed as a shell command line.
 * 2. Automatic discovery for the current platform (`where` on Windows, `which`/`command -v`
 *    elsewhere) plus the well-known install locations.
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

    $wp_command = novamira_find_wp_cli_command();
    if ($wp_command === null) {
        return new WP_Error('wp_cli_not_found', novamira_wp_cli_not_found_message(PHP_OS_FAMILY));
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
 * Resolve the WP-CLI invocation for this server.
 *
 * See the file docblock for the constant and filter that override this.
 *
 * @return list<string>|null Argv list (executable plus any leading arguments), or null when unknown.
 */
function novamira_find_wp_cli_command(): ?array
{
    $command = null;

    if (defined('NOVAMIRA_WP_CLI_COMMAND')) {
        $command = novamira_normalize_wp_cli_command(constant('NOVAMIRA_WP_CLI_COMMAND'));
    }

    if ($command === null) {
        $command = PHP_OS_FAMILY === 'Windows'
            ? novamira_find_wp_cli_command_windows()
            : novamira_find_wp_cli_command_unix();
    }

    /**
     * Filter the resolved WP-CLI invocation.
     *
     * Return a list of argv strings, for example
     * ['C:/php/php.exe', '-c', 'C:/site/php.ini', 'C:/local/wp-cli.phar'], or a single executable
     * path. Return null (or an invalid value) to report WP-CLI as unavailable. Ability arguments
     * are appended to this list and are never part of it.
     *
     * @param list<string>|null $command Resolved argv list, or null when discovery found nothing.
     */
    /** @var mixed $filtered */
    $filtered = apply_filters('novamira_wp_cli_command', $command);

    return novamira_normalize_wp_cli_command($filtered);
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
        if (is_file($path) && is_executable($path)) {
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
        if (!is_file($candidate)) {
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
        if (is_file($candidate)) {
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
        if (is_file($candidate)) {
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

    $loaded = php_ini_loaded_file();
    if ($loaded !== false && $loaded !== '' && is_file($loaded)) {
        return $loaded;
    }

    return null;
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
