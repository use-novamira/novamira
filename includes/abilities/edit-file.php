<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Ability: Edit files via string replacement.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('novamira/edit-file', [
    'label' => __('Edit File', domain: 'novamira'),
    'description' => __(
        'Edits an existing file by replacing an exact string match with new content. Works like the edit tool in AI code agents: specify the old text to find and the new text to replace it with. The old string must be unique in the file unless replace_all is set.',
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
            'old_string' => [
                'type' => 'string',
                'description' => 'The exact text to find in the file. Must match exactly including whitespace and indentation.',
            ],
            'new_string' => [
                'type' => 'string',
                'description' => 'The text to replace old_string with. Use an empty string to delete the matched text.',
            ],
            'replace_all' => [
                'type' => 'boolean',
                'description' => 'Replace all occurrences of old_string. When false (default), old_string must appear exactly once in the file.',
                'default' => false,
            ],
        ],
        'required' => ['path', 'old_string', 'new_string'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'path' => ['type' => 'string', 'description' => 'Absolute path to the edited file.'],
            'replacements' => ['type' => 'integer', 'description' => 'Number of replacements made.'],
            'size' => ['type' => 'integer', 'description' => 'Final file size in bytes.'],
        ],
    ],
    'execute_callback' => 'novamira_edit_file',
    'permission_callback' => 'novamira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Use this to make targeted edits to existing files. Provide the exact text to find (old_string)',
                'and the replacement text (new_string). The old_string must match the file content exactly,',
                'including whitespace and indentation.',
                '',
                'TIPS:',
                '- Read the file first to get the exact text to match.',
                '- old_string must be unique in the file (unless using replace_all).',
                '- Include enough surrounding context in old_string to make it unique.',
                '- old_string and new_string must be different.',
                '- To delete text, set new_string to an empty string.',
                '',
                'PHP SANDBOX: Same rules as write-file — PHP files and PHP execution control files can only be written to the sandbox directory. Other non-PHP edits outside the sandbox are intentional; the sandbox is not security isolation for all filesystem writes.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

/**
 * Replace the first occurrence of a substring.
 *
 * Assumes $old_string exists in $content (caller must verify).
 *
 * @param string $content    Original file content.
 * @param string $old_string Text to find.
 * @param string $new_string Text to replace with.
 * @return string The new content.
 */
function novamira_edit_replace_first(string $content, string $old_string, string $new_string): string
{
    /** @var int $pos — caller guarantees $old_string exists in $content */
    $pos = strpos($content, $old_string);

    return (
        substr($content, offset: 0, length: $pos) . $new_string . substr($content, offset: $pos + strlen($old_string))
    );
}

/**
 * Edit a file by replacing an exact string match.
 *
 * @param array $input Input with 'path', 'old_string', 'new_string', optional 'replace_all'.
 * @return array|WP_Error
 */
function novamira_edit_file($input)
{
    $resolved = novamira_resolve_path(path: (string) $input['path'], must_exist: true);
    if (is_wp_error($resolved)) {
        return $resolved;
    }

    if (!is_file($resolved)) {
        return new WP_Error('not_a_file', sprintf('Path is not a file: %s', $resolved));
    }

    $sandbox_error = novamira_check_php_execution_sandbox($resolved);
    if (is_wp_error($sandbox_error)) {
        return $sandbox_error;
    }

    if (!is_readable($resolved) || !is_writable($resolved)) {
        return new WP_Error('not_writable', sprintf('File is not readable/writable: %s', $resolved));
    }

    $old_string = (string) $input['old_string'];
    $new_string = (string) $input['new_string'];
    $replace_all = ($input['replace_all'] ?? false) === true;

    if ($old_string === $new_string) {
        return new WP_Error('no_change', 'old_string and new_string are identical. No edit needed.');
    }

    $content = file_get_contents($resolved);
    if ($content === false) {
        return new WP_Error('read_failed', sprintf('Could not read file: %s', $resolved));
    }

    $count = substr_count($content, $old_string);

    if ($count === 0) {
        return new WP_Error(
            'no_match',
            'old_string was not found in the file. Make sure it matches the file content exactly, including whitespace and indentation.',
        );
    }

    if ($count > 1 && !$replace_all) {
        return new WP_Error('multiple_matches', sprintf(
            'old_string was found %d times in the file. Include more surrounding context to make it unique, or set replace_all to true.',
            $count,
        ));
    }

    $new_content = $replace_all
        ? str_replace($old_string, $new_string, $content)
        : novamira_edit_replace_first($content, $old_string, $new_string);

    $bytes_written = file_put_contents($resolved, $new_content, LOCK_EX);
    if ($bytes_written === false) {
        return new WP_Error('write_failed', sprintf('Failed to write file: %s', $resolved));
    }

    return [
        'path' => $resolved,
        'replacements' => $count,
        'size' => $bytes_written,
    ];
}
