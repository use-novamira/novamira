<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Ability: Write/create files.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('novamira/write-file', [
    'label' => __('Write File', domain: 'novamira'),
    'description' => __(
        'Writes content to a file on the server filesystem. PHP files (*.php) and PHP execution control files can ONLY be written to the sandbox directory (wp-content/novamira-sandbox/). Other non-PHP files can go anywhere under ABSPATH. Supports both UTF-8 text and base64-encoded binary content. Automatically creates parent directories when needed.',
        domain: 'novamira',
    ),
    'category' => 'filesystem',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'path' => [
                'type' => 'string',
                'description' => 'File path. Relative paths are resolved from the WordPress root (ABSPATH).',
                'minLength' => 1,
            ],
            'content' => [
                'type' => 'string',
                'description' => 'Content to write to the file.',
            ],
            'encoding' => [
                'type' => 'string',
                'description' => 'Content encoding.',
                'enum' => ['utf-8', 'base64'],
                'default' => 'utf-8',
            ],
            'mode' => [
                'type' => 'string',
                'description' => 'Write mode.',
                'enum' => ['overwrite', 'append'],
                'default' => 'overwrite',
            ],
            'create_directories' => [
                'type' => 'boolean',
                'description' => 'Whether to create parent directories if they do not exist.',
                'default' => true,
            ],
        ],
        'required' => ['path', 'content'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'path' => ['type' => 'string', 'description' => 'Absolute path to the written file.'],
            'bytes_written' => ['type' => 'integer', 'description' => 'Number of bytes written.'],
            'created' => [
                'type' => 'boolean',
                'description' => 'Whether a new file was created (vs overwriting existing).',
            ],
            'directories_created' => [
                'type' => 'array',
                'description' => 'List of directories that were created.',
                'items' => ['type' => 'string'],
            ],
            'size' => ['type' => 'integer', 'description' => 'Final file size in bytes.'],
        ],
    ],
    'execute_callback' => 'novamira_write_file',
    'permission_callback' => 'novamira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'PHP FILE SANDBOX:',
                '- PHP files (*.php) and PHP execution control files can ONLY be written to: wp-content/novamira-sandbox/',
                '- Use a path like "wp-content/novamira-sandbox/my-feature.php"',
                '- Other non-PHP files can be written anywhere under ABSPATH.',
                '- Sandbox plugins are loaded by a mu-plugin loader on every request.',
                '',
                'CRASH RECOVERY:',
                '- If a sandbox plugin causes a fatal error, the loader auto-detects the crash',
                '  and enters safe mode on the next request. All sandbox plugins are skipped.',
                '- In safe mode, MCP still works. You can read, fix, or delete the broken file.',
                '- After fixing, delete the file "wp-content/novamira-sandbox/.crashed"',
                '  to exit safe mode and resume loading sandbox plugins.',
                '- If MCP suddenly stops responding after you wrote a PHP file, wait — the next',
                '  request will auto-recover into safe mode and MCP will be available again.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

/**
 * Decode write content based on encoding.
 *
 * @param string $content  Raw content string.
 * @param string $encoding Encoding type ('utf-8' or 'base64').
 * @return string|WP_Error Decoded content or WP_Error on failure.
 */
function novamira_decode_write_content(string $content, string $encoding): string|WP_Error
{
    if ($encoding === 'base64') {
        $decoded = base64_decode(string: $content, strict: true);
        if ($decoded === false) {
            return new WP_Error('invalid_base64', 'The provided content is not valid base64.');
        }

        return $decoded;
    }

    return $content;
}

/**
 * Write content to a file.
 *
 * @param array $input Input with 'path', 'content', optional 'encoding', 'mode', 'create_directories'.
 * @return array|WP_Error
 */
function novamira_write_file($input)
{
    $resolved = novamira_resolve_path(path: (string) $input['path'], must_exist: false);
    if (is_wp_error($resolved)) {
        return $resolved;
    }

    $encoding = (string) ($input['encoding'] ?? 'utf-8');
    $mode = (string) ($input['mode'] ?? 'overwrite');
    $create_directories = ($input['create_directories'] ?? true) !== false;

    $sandbox_error = novamira_check_php_execution_sandbox($resolved);
    if (is_wp_error($sandbox_error)) {
        return $sandbox_error;
    }

    $content = novamira_decode_write_content((string) $input['content'], $encoding);
    if (is_wp_error($content)) {
        return $content;
    }

    $created = !file_exists($resolved);
    $parent_dir = dirname($resolved);

    if (!is_dir($parent_dir) && !$create_directories) {
        return new WP_Error('directory_not_found', sprintf('Parent directory does not exist: %s', $parent_dir));
    }

    $directories_created = novamira_ensure_parent_dir($parent_dir);
    if (is_wp_error($directories_created)) {
        return $directories_created;
    }

    $flags = LOCK_EX;
    if ($mode === 'append') {
        $flags |= FILE_APPEND;
    }

    $bytes_written = file_put_contents($resolved, $content, $flags);
    if ($bytes_written === false) {
        return new WP_Error('write_failed', sprintf('Failed to write file: %s', $resolved));
    }

    if ($created) {
        chmod(filename: $resolved, permissions: 0644);
    }

    return [
        'path' => $resolved,
        'bytes_written' => $bytes_written,
        'created' => $created,
        'directories_created' => $directories_created,
        'size' => filesize($resolved),
    ];
}
