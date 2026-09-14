<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Ability: Read file contents.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('novamira/read-file', [
    'label' => __('Read File', domain: 'novamira'),
    'description' => __(
        'Reads the contents of a file from the server filesystem. Supports text and binary files. Binary files and files with invalid UTF-8 are returned as base64-encoded. Supports partial reads via offset and limit parameters.',
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
            'offset' => [
                'type' => 'integer',
                'description' => 'Byte offset to start reading from.',
                'default' => 0,
                'minimum' => 0,
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Maximum bytes to read. Use -1 for the entire file.',
                'default' => 1_048_576,
            ],
        ],
        'required' => ['path'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'path' => ['type' => 'string', 'description' => 'Absolute path to the file.'],
            'content' => ['type' => 'string', 'description' => 'File content (text or base64-encoded).'],
            'encoding' => ['type' => 'string', 'description' => 'Content encoding: "utf-8" or "base64".'],
            'size' => ['type' => 'integer', 'description' => 'Total file size in bytes.'],
            'bytes_read' => ['type' => 'integer', 'description' => 'Number of bytes actually read.'],
            'truncated' => [
                'type' => 'boolean',
                'description' => 'Whether the content was truncated due to limit.',
            ],
            'mime_type' => ['type' => 'string', 'description' => 'Detected MIME type.'],
        ],
    ],
    'execute_callback' => 'novamira_read_file',
    'permission_callback' => 'novamira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'TIP: AI-written PHP plugins live in wp-content/novamira-sandbox/. Check wp-content/novamira-sandbox/.crashed to see if safe mode is active.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

/**
 * Read file contents.
 *
 * @param array $input Input with 'path', optional 'offset' and 'limit'.
 * @return array|WP_Error
 */
function novamira_read_file($input)
{
    $resolved = novamira_resolve_path(path: (string) $input['path'], must_exist: true);
    if (is_wp_error($resolved)) {
        return $resolved;
    }

    if (!is_file($resolved)) {
        return new WP_Error('not_a_file', sprintf('Path is not a file: %s', $resolved), ['status' => 400]);
    }

    if (!is_readable($resolved)) {
        return new WP_Error('not_readable', sprintf('File is not readable: %s', $resolved));
    }

    $size = (int) filesize($resolved);
    $offset = (int) ($input['offset'] ?? 0);
    $limit = (int) ($input['limit'] ?? 1_048_576);

    $default_mime = 'application/octet-stream';
    $detected_mime = function_exists('mime_content_type') ? mime_content_type($resolved) : false;
    $mime_type = $detected_mime !== false ? $detected_mime : $default_mime;

    $handle = fopen(filename: $resolved, mode: 'rb');
    if ($handle === false) {
        return new WP_Error('read_failed', sprintf('Could not open file: %s', $resolved));
    }

    if ($offset > 0) {
        fseek($handle, $offset);
    }

    $read_length = $limit === -1 ? $size - $offset : $limit;
    $content = fread($handle, max(1, $read_length));
    fclose($handle);

    if ($content === false) {
        return new WP_Error('read_failed', sprintf('Could not read file: %s', $resolved));
    }

    $bytes_read = strlen($content);
    $truncated = $limit !== -1 && ($offset + $bytes_read) < $size;

    // Determine encoding: use base64 for binary content or invalid UTF-8.
    $is_text = novamira_is_text_mime_type($mime_type) && mb_check_encoding(value: $content, encoding: 'UTF-8');

    if (!$is_text) {
        $content = base64_encode($content);
    }

    return [
        'path' => $resolved,
        'content' => $content,
        'encoding' => $is_text ? 'utf-8' : 'base64',
        'size' => $size,
        'bytes_read' => $bytes_read,
        'truncated' => $truncated,
        'mime_type' => $mime_type,
    ];
}

/**
 * Check whether a MIME type represents text content.
 *
 * @param string $mime_type The MIME type to check.
 * @return bool
 */
function novamira_is_text_mime_type($mime_type)
{
    // MIME types and subtypes that are treated as text.
    $text_prefixes = ['text/', 'application/json', 'application/xml', 'application/javascript'];
    $text_suffixes = ['+xml', '+json'];

    foreach ($text_prefixes as $prefix) {
        if (str_starts_with($mime_type, $prefix)) {
            return true;
        }
    }

    foreach ($text_suffixes as $suffix) {
        if (str_ends_with($mime_type, $suffix)) {
            return true;
        }
    }

    return false;
}
