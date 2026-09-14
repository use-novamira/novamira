<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Ability: Delete files and directories.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('novamira/delete-file', [
    'label' => __('Delete File', domain: 'novamira'),
    'description' => __(
        'Deletes a file or directory from the server filesystem. Non-empty directories require the recursive flag. Critical WordPress directories (ABSPATH root, wp-admin, wp-includes) are protected from deletion. Idempotent: deleting a non-existent path succeeds with deleted=false.',
        domain: 'novamira',
    ),
    'category' => 'filesystem',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'path' => [
                'type' => 'string',
                'description' => 'Path to the file or directory to delete. Relative paths are resolved from ABSPATH.',
                'minLength' => 1,
            ],
            'recursive' => [
                'type' => 'boolean',
                'description' => 'Whether to recursively delete directory contents. Required for non-empty directories.',
                'default' => false,
            ],
        ],
        'required' => ['path'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'path' => ['type' => 'string', 'description' => 'Absolute path that was targeted.'],
            'type' => [
                'type' => 'string',
                'description' => 'Type of the target: "file", "directory", or "not_found".',
            ],
            'deleted' => ['type' => 'boolean', 'description' => 'Whether anything was actually deleted.'],
            'items_deleted' => ['type' => 'integer', 'description' => 'Number of files/directories deleted.'],
        ],
    ],
    'execute_callback' => 'novamira_delete_file',
    'permission_callback' => 'novamira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'SANDBOX NOTES:',
                '- Files in wp-content/novamira-sandbox/ (the PHP sandbox) can be deleted.',
                '- To exit safe mode after a crash, delete: wp-content/novamira-sandbox/.crashed',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => true,
        ],
    ],
]);

/**
 * Delete a file or directory.
 *
 * @param array $input Input with 'path', optional 'recursive'.
 * @return array|WP_Error
 */
function novamira_delete_file($input)
{
    $resolved = novamira_resolve_path((string) $input['path'], must_exist: false);
    if (is_wp_error($resolved)) {
        return $resolved;
    }

    $recursive = ($input['recursive'] ?? false) === true;

    // Idempotent: non-existent path is a no-op success.
    if (!file_exists($resolved) && !is_link($resolved)) {
        return [
            'path' => $resolved,
            'type' => 'not_found',
            'deleted' => false,
            'items_deleted' => 0,
        ];
    }

    // Protect critical WordPress directories.
    $real_resolved = realpath($resolved);
    $protected = array_filter([
        realpath(ABSPATH),
        realpath(ABSPATH . 'wp-admin'),
        realpath(ABSPATH . 'wp-includes'),
        realpath(WP_CONTENT_DIR . '/mu-plugins'),
    ]);

    if (in_array($real_resolved, $protected, strict: true)) {
        return new WP_Error('protected_path', sprintf('Cannot delete protected WordPress directory: %s', $resolved), [
            'status' => 403,
        ]);
    }

    // Delete a file or symlink.
    if (is_file($resolved) || is_link($resolved)) {
        if (!unlink($resolved)) {
            return new WP_Error('delete_failed', sprintf('Failed to delete file: %s', $resolved));
        }
        return [
            'path' => $resolved,
            'type' => 'file',
            'deleted' => true,
            'items_deleted' => 1,
        ];
    }

    // Delete a directory.
    if (is_dir($resolved)) {
        return novamira_delete_directory($resolved, $recursive);
    }

    return new WP_Error('unknown_type', sprintf('Path is not a file or directory: %s', $resolved), ['status' => 400]);
}

/**
 * Delete a directory, optionally recursively.
 *
 * @param string $resolved  Absolute path to the directory.
 * @param bool   $recursive Whether to delete contents recursively.
 * @return array|WP_Error
 */
function novamira_delete_directory($resolved, $recursive)
{
    $contents = scandir($resolved);
    if ($contents === false) {
        return new WP_Error('scan_failed', sprintf('Could not read directory: %s', $resolved));
    }
    $is_empty = count($contents) <= 2;

    if (!$is_empty && !$recursive) {
        return new WP_Error(
            'directory_not_empty',
            sprintf('Directory is not empty. Set recursive=true to delete: %s', $resolved),
            ['status' => 409],
        );
    }

    if ($is_empty) {
        if (!rmdir($resolved)) {
            return new WP_Error('delete_failed', sprintf('Failed to delete directory: %s', $resolved));
        }
        return [
            'path' => $resolved,
            'type' => 'directory',
            'deleted' => true,
            'items_deleted' => 1,
        ];
    }

    // Recursive delete: remove contents depth-first, then the directory itself.
    $items_deleted = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($resolved, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        if (!$item instanceof SplFileInfo) {
            continue;
        }
        $item_path = $item->getPathname();
        $deleted = $item->isDir() ? rmdir($item_path) : unlink($item_path);
        if (!$deleted) {
            return new WP_Error('delete_failed', sprintf('Failed to delete: %s', $item_path));
        }
        $items_deleted++;
    }

    if (!rmdir($resolved)) {
        return new WP_Error('delete_failed', sprintf('Failed to delete directory: %s', $resolved));
    }
    $items_deleted++;

    return [
        'path' => $resolved,
        'type' => 'directory',
        'deleted' => true,
        'items_deleted' => $items_deleted,
    ];
}
