<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Sandbox Loader
 * Loads AI-written PHP plugins from the sandbox directory. Includes automatic crash recovery in dev mode.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Writes the .crashed marker, which keeps sandbox files in safe mode from the next request onward.
 *
 * @param string               $crashed_file Path to the .crashed marker file.
 * @param string               $sandbox_file The sandbox file that failed.
 * @param array<string, mixed> $error        Error details (type, message, file, line).
 */
function novamira_sandbox_write_crash_marker(string $crashed_file, string $sandbox_file, array $error): void
{
    $error['sandbox_file'] = $sandbox_file;
    file_put_contents($crashed_file, (string) wp_json_encode($error), LOCK_EX);
}

/**
 * Loads one sandbox file, keeping what it prints and how it fails to itself.
 *
 * Locals here are prefixed because the required file shares this scope and its own top-level
 * variables would otherwise be free to overwrite the buffer bookkeeping below.
 *
 * @param string $crashed_file Path to the .crashed marker file.
 * @param string $file         The sandbox file to load.
 */
function novamira_sandbox_load_file(string $crashed_file, string $file): void
{
    // Sandbox files exist to register hooks and functions, not to print while being required.
    // Nothing used to contain what they printed anyway, so one leftover `echo` landed in whatever
    // response happened to be building — and these files load on every request, so that meant
    // front-end pages, admin screens, and the REST/MCP JSON that AI clients parse from byte zero
    // alike. Give each file its own buffer and throw away what it prints.
    $novamira_ob_level = ob_get_level();
    ob_start();

    try {
        require_once $file;
    } catch (\Throwable $novamira_error) {
        // Keep one broken file from ending the request: arm safe mode for the next request, then
        // carry on with the remaining sandbox files and the rest of WordPress's bootstrap. A failure
        // `require_once` cannot survive is not catchable here and still reaches the shutdown handler.
        novamira_sandbox_write_crash_marker($crashed_file, $file, [
            'type' => E_ERROR,
            'message' => $novamira_error->getMessage(),
            'file' => $novamira_error->getFile(),
            'line' => $novamira_error->getLine(),
        ]);
    }

    // Unwind only the levels this call opened. A sandbox file may leave a buffer of its own open, or
    // close one it never opened, and a bare ob_end_clean() would then delete a buffer belonging to
    // WordPress or the host — discarding the very response this is meant to keep clean. A file that
    // serves its own response and calls exit() never arrives here at all: PHP flushes its buffered
    // output at shutdown, so that pattern still works.
    $novamira_printed = '';
    while (ob_get_level() > $novamira_ob_level) {
        $novamira_printed = (string) ob_get_clean() . $novamira_printed;
    }

    if ($novamira_printed !== '' && defined('WP_DEBUG') && constant('WP_DEBUG') === true) {
        error_log(sprintf('Novamira Sandbox: discarded output printed while loading %s: %s', $file, $novamira_printed));
    }
}

/**
 * Shutdown handler that creates a .crashed marker when PHP terminates while a sandbox file is loading.
 *
 * Only a failure that `require_once` cannot survive reaches this — out of memory, a parse error, a
 * timeout. A thrown Throwable does not: the load loop below catches it and the request continues.
 *
 * @param string      $crashed_file        Path to the .crashed marker file.
 * @param string|null $current_sandbox_file The sandbox file currently being loaded, or null if loading is complete.
 */
function novamira_sandbox_crash_handler(string $crashed_file, ?string $current_sandbox_file): void
{
    if ($current_sandbox_file === null) {
        return;
    }

    $error = error_get_last();
    if ($error === null || !($error['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR))) {
        $error = [
            'type' => 0,
            'message' => 'Sandbox file terminated PHP during loading.',
            'file' => $current_sandbox_file,
            'line' => 0,
        ];
    }

    novamira_sandbox_write_crash_marker($crashed_file, $current_sandbox_file, $error);
}

(static function () {
    $sandbox_dir = NOVAMIRA_SANDBOX_DIR;

    // Ensure sandbox directory exists.
    if (!is_dir($sandbox_dir)) {
        return;
    }

    // WordPress includes a plugin once in a sandboxed probe before activation. Loading generated
    // extensions there lets one stale exit(), redirect, or request-method guard block Novamira itself.
    if (defined('WP_SANDBOX_SCRAPING') && constant('WP_SANDBOX_SCRAPING') === true) {
        return;
    }

    $loading_file = $sandbox_dir . '.loading';
    $crashed_file = $sandbox_dir . '.crashed';

    // Clean up legacy .loading marker if present.
    if (file_exists($loading_file)) {
        unlink($loading_file);
    }

    // Crash recovery: .crashed exists → stay in safe mode.
    $is_safe_mode = file_exists($crashed_file);

    // Manual safe mode via URL parameter.
    if (!$is_safe_mode && ($_GET['novamira_safe_mode'] ?? null) === '1') {
        $is_safe_mode = true;
    }

    // Dashboard warnings.
    add_action('admin_notices', static function () use ($crashed_file) {
        if (!novamira_current_user_can_manage()) {
            return;
        }
        if (file_exists($crashed_file)) {
            wp_admin_notice(
                sprintf(
                    '<strong>%s</strong> %s',
                    esc_html__('Novamira Sandbox: Safe mode is active.', domain: 'novamira'),
                    esc_html__(
                        'A sandbox plugin caused a fatal error. All sandbox plugins are disabled. Fix or delete the broken plugin, then delete wp-content/novamira-sandbox/.crashed to resume.',
                        domain: 'novamira',
                    ),
                ),
                [
                    'type' => 'error',
                    'dismissible' => false,
                ],
            );
        }
    });

    // Safe mode: skip loading all sandbox files.
    if ($is_safe_mode) {
        return;
    }

    // Normal load with shutdown-based crash detection.
    $files = glob($sandbox_dir . '*.php');
    if (!$files) {
        return;
    }
    $files = array_values(array_filter(
        $files,
        static fn(string $file): bool => !novamira_sandbox_file_is_disabled($file),
    ));
    if ($files === []) {
        return;
    }

    // Tracks which sandbox file is currently being loaded. The shutdown handler uses this to
    // detect crashes even when the fatal error is thrown from a core or third-party file in the
    // call chain (e.g. sandbox file → get_header() → wp_head() → fatal in wp-includes/).
    // Set to null after the loop completes, which makes the handler a no-op.
    $current_sandbox_file = null;

    register_shutdown_function(static function () use ($crashed_file, &$current_sandbox_file) {
        novamira_sandbox_crash_handler($crashed_file, $current_sandbox_file);
    });

    foreach ($files as $file) {
        $current_sandbox_file = $file;
        novamira_sandbox_load_file($crashed_file, $file);
    }
    $current_sandbox_file = null;
})();
