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
 * Shutdown handler that creates a .crashed marker when a fatal error occurs while a sandbox file is loading.
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
    if ($error === null) {
        return;
    }

    // Only react to fatal error types that kill execution.
    if (!($error['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR))) {
        return;
    }

    $error['sandbox_file'] = $current_sandbox_file;
    file_put_contents($crashed_file, (string) wp_json_encode($error), LOCK_EX);
}

(static function () {
    $sandbox_dir = NOVAMIRA_SANDBOX_DIR;

    // Ensure sandbox directory exists.
    if (!is_dir($sandbox_dir)) {
        return;
    }

    $loading_file = $sandbox_dir . '.loading';
    $crashed_file = $sandbox_dir . '.crashed';
    $abilities_enabled = novamira_is_enabled();

    // When abilities are disabled, load sandbox files without crash-recovery overhead.
    if (!$abilities_enabled) {
        $files = glob($sandbox_dir . '*.php');
        if ($files) {
            foreach ($files as $file) {
                require_once $file;
            }
        }
        return;
    }

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
        require_once $file;
    }
    $current_sandbox_file = null;
})();
