<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Ability: Create a temporary header-authenticated upload endpoint.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('novamira/create-upload-link', [
    'label' => __('Create Upload Link', domain: 'novamira'),
    'description' => __(
        'Creates a temporary upload endpoint and header-only bearer token that external tools can use to upload one file into the WordPress filesystem. Useful when the agent has a local ZIP, plugin, theme, or media file and wants to upload it with curl or another external tool. The endpoint accepts raw PUT/POST bodies and multipart/form-data with a field named "file".',
        domain: 'novamira',
    ),
    'category' => 'filesystem',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'path' => [
                'type' => 'string',
                'description' => 'Destination file path. Relative paths are resolved from the WordPress root (ABSPATH).',
                'minLength' => 1,
            ],
            'expires_in' => [
                'type' => 'integer',
                'description' => 'Seconds before the upload token expires. Minimum 30, maximum 3600.',
                'default' => 900,
                'minimum' => 30,
                'maximum' => 3600,
            ],
            'max_bytes' => [
                'type' => 'integer',
                'description' => 'Maximum number of bytes accepted by this upload endpoint. Default is 536870912 (512 MiB).',
                'default' => 536_870_912,
                'minimum' => 1,
            ],
            'overwrite' => [
                'type' => 'boolean',
                'description' => 'Whether the upload may replace an existing destination file.',
                'default' => false,
            ],
            'create_directories' => [
                'type' => 'boolean',
                'description' => 'Whether to create parent directories if they do not exist.',
                'default' => true,
            ],
        ],
        'required' => ['path'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'upload_url' => [
                'type' => 'string',
                'description' => 'Temporary upload endpoint URL. Send upload_token in token_header; the token is not included in the URL.',
            ],
            'upload_token' => [
                'type' => 'string',
                'description' => 'Temporary bearer token. Send as the token_header value.',
            ],
            'token_header' => ['type' => 'string', 'description' => 'HTTP header that must carry upload_token.'],
            'method' => ['type' => 'string', 'description' => 'Recommended HTTP method.'],
            'path' => ['type' => 'string', 'description' => 'Absolute destination path.'],
            'expires_at' => ['type' => 'integer', 'description' => 'Unix timestamp when the upload token expires.'],
            'max_bytes' => ['type' => 'integer', 'description' => 'Maximum upload size accepted by the endpoint.'],
            'overwrite' => ['type' => 'boolean', 'description' => 'Whether existing files may be replaced.'],
            'curl_examples' => [
                'type' => 'array',
                'description' => 'Example curl commands. Replace /path/to/local-file with the local file to upload.',
                'items' => ['type' => 'string'],
            ],
        ],
    ],
    'execute_callback' => 'novamira_create_upload_link',
    'permission_callback' => 'novamira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Use this when a file is too large or inconvenient to send through the MCP JSON transport.',
                'Recommended curl form: curl -X PUT -H "$token_header: $upload_token" --data-binary @/path/to/local-file "$upload_url"',
                'Multipart form is also accepted: curl -H "$token_header: $upload_token" -F file=@/path/to/local-file "$upload_url"',
                'PHP files (*.php) and PHP execution control files can ONLY be uploaded to wp-content/novamira-sandbox/.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

/**
 * Create a temporary upload endpoint.
 *
 * @param array $input Input with destination path and optional limits.
 * @return array|WP_Error
 */
function novamira_create_upload_link($input)
{
    $resolved = novamira_resolve_path(path: (string) $input['path'], must_exist: false);
    if (is_wp_error($resolved)) {
        return $resolved;
    }

    $sandbox_error = novamira_check_php_execution_sandbox($resolved);
    if (is_wp_error($sandbox_error)) {
        return $sandbox_error;
    }

    $expires_in = max(30, min(3_600, (int) ($input['expires_in'] ?? 900)));
    $max_bytes = max(1, (int) ($input['max_bytes'] ?? 536_870_912));
    $expires_at = time() + $expires_in;
    $overwrite = ($input['overwrite'] ?? false) === true;
    $create_directories = ($input['create_directories'] ?? true) !== false;

    $payload = [
        'path' => $resolved,
        'expires_at' => $expires_at,
        'max_bytes' => $max_bytes,
        'overwrite' => $overwrite,
        'create_directories' => $create_directories,
    ];
    $token = novamira_sign_upload_payload($payload);
    if (is_wp_error($token)) {
        return $token;
    }

    $upload_url = rest_url('novamira/v1/upload');
    $token_header = 'X-Novamira-Upload-Token';

    return [
        'upload_url' => $upload_url,
        'upload_token' => $token,
        'token_header' => $token_header,
        'method' => 'PUT',
        'path' => $resolved,
        'expires_at' => $expires_at,
        'max_bytes' => $max_bytes,
        'overwrite' => $overwrite,
        'curl_examples' => [
            sprintf(
                'curl -X PUT -H "%s: $upload_token" --data-binary @/path/to/local-file %s',
                $token_header,
                escapeshellarg($upload_url),
            ),
            sprintf(
                'curl -H "%s: $upload_token" -F file=@/path/to/local-file %s',
                $token_header,
                escapeshellarg($upload_url),
            ),
        ],
    ];
}
