<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Ability: Re-enable a sandbox file by removing its disabled-state marker.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('novamira/enable-file', [
    'label' => __('Enable File', domain: 'novamira'),
    'description' => __(
        'Re-enables a previously disabled sandbox PHP file by removing its empty disabled-state sidecar marker. Also accepts legacy .php.disabled paths created by older Novamira versions. Only operates on files inside the sandbox directory. Idempotent: enabling a file that is not disabled succeeds with enabled=false.',
        domain: 'novamira',
    ),
    'category' => 'filesystem',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'path' => [
                'type' => 'string',
                'description' => 'Path to the original PHP file to re-enable. Legacy .php.disabled paths are also accepted. Relative paths are resolved from ABSPATH. Must be inside wp-content/novamira-sandbox/.',
                'minLength' => 1,
            ],
        ],
        'required' => ['path'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'disabled_path' => ['type' => 'string', 'description' => 'Absolute path of the disabled-state marker.'],
            'enabled_path' => ['type' => 'string', 'description' => 'Absolute path of the enabled PHP file.'],
            'enabled' => ['type' => 'boolean', 'description' => 'Whether disabled state was removed.'],
        ],
    ],
    'execute_callback' => 'novamira_enable_file',
    'permission_callback' => 'novamira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'SANDBOX NOTES:',
                '- Only files inside wp-content/novamira-sandbox/ (the PHP sandbox) can be enabled.',
                '- Pass the original PHP path; legacy .php.disabled paths are also accepted.',
                '- Counterpart to disable-file: removes the sidecar marker so the loader picks the PHP file up again.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

/**
 * Enable a legacy file whose PHP source was renamed with a .disabled suffix.
 *
 * @return array|WP_Error|null Null when the legacy path does not exist.
 */
function novamira_enable_legacy_sandbox_file(string $path): array|WP_Error|null
{
    $legacy_path = novamira_resolve_path($path, must_exist: true);
    if (is_wp_error($legacy_path)) {
        return null;
    }

    $sandbox_check = novamira_validate_sandbox_path($legacy_path);
    if (is_wp_error($sandbox_check)) {
        return $sandbox_check;
    }

    $enabled_path = substr($legacy_path, offset: 0, length: -9);
    if (file_exists($enabled_path)) {
        return new WP_Error('enabled_file_exists', sprintf('An enabled version already exists: %s', $enabled_path));
    }
    if (!rename($legacy_path, $enabled_path)) {
        return new WP_Error('rename_failed', sprintf('Failed to enable legacy file: %s', $legacy_path));
    }

    return [
        'disabled_path' => $legacy_path,
        'enabled_path' => $enabled_path,
        'enabled' => true,
    ];
}

/**
 * Re-enable a disabled sandbox file by removing its sidecar marker.
 *
 * @param array $input Input with 'path'.
 * @return array|WP_Error
 */
function novamira_enable_file($input)
{
    $path = (string) $input['path'];
    $basename = basename($path);

    // Accept the sidecar path returned by disable-file as well as the original PHP path.
    if (novamira_is_disabled_file($path) && str_starts_with($basename, '.')) {
        $path = dirname($path) . DIRECTORY_SEPARATOR . substr($basename, offset: 1, length: -9);
    }

    if (novamira_is_disabled_file($path) && !str_starts_with($basename, '.')) {
        // Older versions renamed source to .php.disabled. Continue to enable those files safely.
        $legacy_result = novamira_enable_legacy_sandbox_file($path);
        if ($legacy_result !== null) {
            return $legacy_result;
        }
        $path = substr($path, offset: 0, length: -9);
    }

    $resolved = novamira_resolve_path($path, must_exist: true);
    if (is_wp_error($resolved)) {
        return $resolved;
    }

    $sandbox_check = novamira_validate_sandbox_path($resolved);
    if (is_wp_error($sandbox_check)) {
        return $sandbox_check;
    }

    if (!is_file($resolved)) {
        return new WP_Error('not_a_file', sprintf('Path is not a file: %s', $resolved));
    }

    $disabled_path = novamira_sandbox_disabled_marker_path($resolved);
    if (!is_file($disabled_path)) {
        return [
            'disabled_path' => $disabled_path,
            'enabled_path' => $resolved,
            'enabled' => false,
        ];
    }

    if (!unlink($disabled_path)) {
        return new WP_Error('marker_delete_failed', sprintf('Failed to enable file: %s', $resolved));
    }

    return [
        'disabled_path' => $disabled_path,
        'enabled_path' => $resolved,
        'enabled' => true,
    ];
}
