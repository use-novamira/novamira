<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Abilities: Execute WP-CLI commands and query job statuses.
 */

if (!defined('ABSPATH')) {
    exit();
}

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
                'description' => 'The generated background job ID (null if synchronous).',
            ],
            'pid' => [
                'type' => 'integer',
                'description' => 'PID of the background process (null if synchronous).',
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
    // @mago-expect analysis:mixed-assignment
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

    $wp_path = novamira_find_wp_cli_path();
    if ($wp_path === null) {
        return new WP_Error('wp_cli_not_found', __(
            'WP-CLI is not installed or not executable on this server.',
            domain: 'novamira',
        ));
    }

    // Automatically append --allow-root if executing as root.
    if (novamira_is_current_user_root() && !in_array(needle: '--allow-root', haystack: $args, strict: true)) {
        array_unshift($args, '--allow-root');
    }

    if ($async) {
        return novamira_run_wp_cli_async($wp_path, $args);
    }

    return novamira_run_wp_cli_sync($wp_path, $args);
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
 * Find the WP-CLI executable on the server.
 *
 * @return string|null
 */
function novamira_find_wp_cli_path(): ?string
{
    if (function_exists('exec')) {
        $output = [];
        $return_var = 0;
        exec('which wp 2>/dev/null', $output, $return_var);
        $first_out = $output[0] ?? null;
        if ($return_var === 0 && is_string($first_out) && trim($first_out) !== '') {
            return trim($first_out);
        }

        $output = [];
        $return_var = 0;
        exec('command -v wp 2>/dev/null', $output, $return_var);
        $first_out = $output[0] ?? null;
        if ($return_var === 0 && is_string($first_out) && trim($first_out) !== '') {
            return trim($first_out);
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
            return $path;
        }
    }

    return null;
}

/**
 * Check if the PHP process is executing as root user.
 *
 * @return bool
 */
function novamira_is_current_user_root(): bool
{
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
 * @param string       $wp_path Path to the wp command.
 * @param list<string> $args    Arguments array.
 * @return array
 */
function novamira_run_wp_cli_sync(string $wp_path, array $args): array
{
    $cmd = array_merge([$wp_path], $args);

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
            'job_id' => null,
            'pid' => null,
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
 * @param string       $wp_path Path to the wp command.
 * @param list<string> $args    Arguments array.
 * @return array
 */
function novamira_run_wp_cli_async(string $wp_path, array $args): array
{
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

    $cmd_args = array_map('escapeshellarg', $args);
    $wp_cmd = escapeshellarg($wp_path) . ' ' . implode(' ', $cmd_args);

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
