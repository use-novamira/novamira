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
 * Writes the .crashed marker, activating safe mode for sandbox files on the next request.
 *
 * @param string               $crashed_file  Path to the .crashed marker file.
 * @param string               $sandbox_file  The sandbox file responsible for the crash.
 * @param array<string, mixed> $error         Error details (type, message, file, line).
 */
function novamira_sandbox_write_crash_marker(string $crashed_file, string $sandbox_file, array $error): void
{
    $error['sandbox_file'] = $sandbox_file;
    file_put_contents($crashed_file, (string) wp_json_encode($error), LOCK_EX);
}

/**
 * Shutdown handler that creates a .crashed marker when PHP terminates while a sandbox file is loading.
 *
 * Only reachable for a fatal that require_once itself cannot survive (out of memory, a parse error,
 * a timeout). An uncaught Throwable never reaches here, since the surrounding try/catch in the load
 * loop below handles it without terminating the request.
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
        // Sandbox files are meant to register hooks/functions, not print output at require-time: any
        // top-level echo, stray warning, or leftover debug statement would otherwise land in whatever
        // response happens to be building on every other request (REST/MCP JSON included) rather than
        // staying local to this file. Buffer and discard it. A file that deliberately serves its own
        // response and calls exit() is unaffected, since exit() skips the ob_end_clean() below and
        // PHP flushes the buffered output normally at shutdown.
        ob_start();
        try {
            require_once $file;
            ob_end_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            // Isolate this file's failure instead of letting it take down the whole request: log it,
            // arm safe mode for the next request, and keep loading the remaining sandbox files and the
            // rest of the WordPress bootstrap.
            novamira_sandbox_write_crash_marker($crashed_file, $file, [
                'type' => E_ERROR,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }
    $current_sandbox_file = null;
})();
