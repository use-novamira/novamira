<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Temporary signed upload endpoint support.
 */

if (!defined('ABSPATH')) {
    exit();
}

add_action('rest_api_init', callback: 'novamira_register_upload_route');

/**
 * Register the REST endpoint used by signed uploads.
 */
function novamira_register_upload_route(): void
{
    $route_namespace = 'novamira/v1';
    $route = '/upload';

    register_rest_route($route_namespace, $route, [
        'methods' => ['POST', 'PUT'],
        'callback' => 'novamira_handle_signed_upload',
        'permission_callback' => '__return_true',
    ]);
}

/**
 * Sign an upload-link payload.
 *
 * @param array<string, mixed> $payload Upload token payload.
 * @return string|WP_Error
 */
function novamira_sign_upload_payload(array $payload): string|WP_Error
{
    $json = wp_json_encode($payload, JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return new WP_Error('upload_token_encode_failed', 'Could not encode upload token payload.');
    }

    $body = novamira_base64url_encode($json);
    $signature = hash_hmac('sha256', $body, novamira_upload_token_secret(), binary: true);

    return $body . '.' . novamira_base64url_encode($signature);
}

/**
 * Verify an upload-link token and return its payload.
 *
 * @return array<string, mixed>|WP_Error
 */
function novamira_verify_upload_token(string $token): array|WP_Error
{
    $parts = explode('.', $token, limit: 2);
    if (count($parts) !== 2) {
        return new WP_Error('invalid_upload_token', 'Invalid upload token.', ['status' => 401]);
    }

    [$body, $signature] = $parts;
    $expected = novamira_base64url_encode(hash_hmac('sha256', $body, novamira_upload_token_secret(), binary: true));
    if (!hash_equals($expected, $signature)) {
        return new WP_Error('invalid_upload_token', 'Invalid upload token signature.', ['status' => 401]);
    }

    $json = novamira_base64url_decode($body);
    if ($json === false) {
        return new WP_Error('invalid_upload_token', 'Invalid upload token payload.', ['status' => 401]);
    }

    /** @var array<string, mixed>|null $decoded */
    $decoded = json_decode($json, associative: true);
    if (!is_array($decoded)) {
        return new WP_Error('invalid_upload_token', 'Invalid upload token payload.', ['status' => 401]);
    }

    $payload = [
        'path' => $decoded['path'] ?? null,
        'expires_at' => $decoded['expires_at'] ?? null,
        'max_bytes' => $decoded['max_bytes'] ?? null,
        'overwrite' => $decoded['overwrite'] ?? null,
        'create_directories' => $decoded['create_directories'] ?? null,
        'nonce' => $decoded['nonce'] ?? null,
    ];

    $expires_at = (int) $payload['expires_at'];
    if ($expires_at < time()) {
        return new WP_Error('upload_token_expired', 'Upload token has expired.', ['status' => 401]);
    }

    return $payload;
}

/** Prefix of the options that mark an upload link as used, until it would have expired. */
const NOVAMIRA_UPLOAD_SPENT_PREFIX = 'novamira_upload_spent_';

/**
 * An upload link works once: its nonce is claimed with add_option(), which only one request can win.
 *
 * @param array<string, mixed> $payload
 */
function novamira_spend_upload_token(array $payload): ?WP_Error
{
    $nonce = is_string($payload['nonce'] ?? null) ? $payload['nonce'] : '';
    if (preg_match('/^[0-9a-f]{32}$/', $nonce) !== 1) {
        return new WP_Error('invalid_upload_token', 'Invalid upload token: create a new upload link.', [
            'status' => 401,
        ]);
    }
    // @mago-expect lint:no-global -- $wpdb is WordPress' database handle.
    global $wpdb;
    /** @var \wpdb $wpdb */
    $expired = $wpdb->prepare(
        'DELETE FROM %i WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d',
        $wpdb->options,
        $wpdb->esc_like(NOVAMIRA_UPLOAD_SPENT_PREFIX) . '%',
        time(),
    );
    if (is_string($expired)) {
        $wpdb->query($expired);
    }
    if (!add_option(NOVAMIRA_UPLOAD_SPENT_PREFIX . $nonce, (string) (int) $payload['expires_at'], autoload: false)) {
        return new WP_Error('upload_token_used', 'This upload link was already used: create a new one.', [
            'status' => 409,
        ]);
    }
    return null;
}

/**
 * Handle a signed upload request.
 *
 * @return array|WP_Error
 */
function novamira_handle_signed_upload(WP_REST_Request $request)
{
    // Checked first and before the token, so links issued before read mode was turned on are refused
    // with the read-mode wording even while AI Abilities are also off.
    if (novamira_is_read_mode()) {
        return novamira_read_mode_blocked_error('uploading files through an upload link');
    }

    if (!novamira_is_enabled()) {
        return new WP_Error('novamira_disabled', 'Novamira abilities are disabled.', ['status' => 403]);
    }

    $token = novamira_get_upload_token_from_request($request);
    if ($token === '') {
        return new WP_Error('missing_upload_token', 'Missing upload token.', ['status' => 401]);
    }

    $payload = novamira_verify_upload_token($token);
    if (is_wp_error($payload)) {
        return $payload;
    }
    $spent = novamira_spend_upload_token($payload);
    if (is_wp_error($spent)) {
        return $spent;
    }

    $destination = novamira_prepare_upload_destination($payload);
    if (is_wp_error($destination)) {
        return $destination;
    }

    $source = novamira_open_upload_source($request);
    if (is_wp_error($source)) {
        return $source;
    }

    $was_disabled = novamira_sandbox_file_is_disabled($destination['path']);
    $disabled = novamira_upload_disable_sandbox_php($destination['path']);
    if (is_wp_error($disabled)) {
        fclose($source['stream']);
        return $disabled;
    }

    $stream = $source['stream'];
    $result = $destination['overwrite']
        ? novamira_overwrite_upload_stream(
            source: $stream,
            resolved: $destination['path'],
            max_bytes: $destination['max_bytes'],
        )
        : novamira_create_upload_stream(
            source: $stream,
            resolved: $destination['path'],
            max_bytes: $destination['max_bytes'],
        );
    fclose($stream);

    if (is_wp_error($result)) {
        // A failed upload leaves the previous file as it was, enabled or not.
        if ($disabled === true && !$was_disabled) {
            novamira_remove_sandbox_disabled_marker($destination['path']);
        }
        return $result;
    }

    clearstatcache(clear_realpath_cache: true, filename: $destination['path']);

    return [
        'path' => $destination['path'],
        'bytes_written' => $result['bytes_written'],
        'created' => $result['created'],
        'directories_created' => $destination['directories_created'],
        'size' => filesize($destination['path']),
        'source' => $source['source'],
        'filename' => $source['filename'],
        'disabled' => $disabled,
    ];
}

/**
 * While approvals are on, a PHP file uploaded into the sandbox is saved disabled, before its content
 * is written, so it never runs on the next request. It starts only through enable-file, which reads it with
 * Novamira's code check and always asks a person.
 */
function novamira_upload_disable_sandbox_php(string $path): bool|WP_Error
{
    if (
        !function_exists('Novamira\\Confirmations\\active')
        || !\Novamira\Confirmations\active()
        || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'php'
        || !novamira_path_is_within_directory($path, novamira_get_sandbox_dir())
    ) {
        return false;
    }
    if (!novamira_create_sandbox_disabled_marker($path)) {
        return new WP_Error(
            'upload_disable_failed',
            'Could not save the uploaded PHP file disabled. Nothing was uploaded.',
            [
                'status' => 500,
            ],
        );
    }
    return true;
}

/**
 * Resolve and validate the upload destination from a verified token payload.
 *
 * @param array<string, mixed> $payload Verified upload token payload.
 * @return array{path: string, max_bytes: int, overwrite: bool, directories_created: array}|WP_Error
 */
function novamira_prepare_upload_destination(array $payload): array|WP_Error
{
    if (!is_string($payload['path']) || $payload['path'] === '') {
        return new WP_Error('invalid_upload_token', 'Upload token does not contain a valid path.', ['status' => 401]);
    }

    $resolved = novamira_resolve_path(path: $payload['path'], must_exist: false);
    if (is_wp_error($resolved)) {
        return $resolved;
    }

    $symlink_error = novamira_reject_final_path_symlink($resolved);
    if (is_wp_error($symlink_error)) {
        return $symlink_error;
    }

    // Sandbox enforcement is intentionally limited to PHP execution paths.
    // Non-PHP uploads outside the sandbox are part of the filesystem ability model.
    $sandbox_error = novamira_check_php_execution_sandbox($resolved);
    if (is_wp_error($sandbox_error)) {
        return $sandbox_error;
    }

    $parent_dir = dirname($resolved);
    $directories_created = [];
    if (!is_dir($parent_dir)) {
        if ($payload['create_directories'] !== true) {
            return new WP_Error('directory_not_found', sprintf('Parent directory does not exist: %s', $parent_dir));
        }
        $directories_created = novamira_ensure_parent_dir($parent_dir);
        if (is_wp_error($directories_created)) {
            return $directories_created;
        }
    }

    if (!is_writable($parent_dir)) {
        return new WP_Error('directory_not_writable', sprintf('Parent directory is not writable: %s', $parent_dir));
    }

    return [
        'path' => $resolved,
        'max_bytes' => max(1, (int) $payload['max_bytes']),
        'overwrite' => $payload['overwrite'] === true,
        'directories_created' => $directories_created,
    ];
}

/**
 * Return the upload token from request headers.
 */
function novamira_get_upload_token_from_request(WP_REST_Request $request): string
{
    return novamira_rest_header_token($request, header_name: 'x-novamira-upload-token');
}

/**
 * Open the uploaded file stream, either from multipart/form-data or the raw request body.
 *
 * @return array{stream: resource, source: string, filename: string}|WP_Error
 */
function novamira_open_upload_source(WP_REST_Request $request): array|WP_Error
{
    $file = novamira_get_multipart_upload_file($request);
    if ($file !== null) {
        return novamira_open_multipart_upload_source($file);
    }

    $stream = fopen('php://input', mode: 'rb');
    if ($stream === false) {
        return new WP_Error('upload_read_failed', 'Could not read upload request body.');
    }

    return [
        'stream' => $stream,
        'source' => 'raw',
        'filename' => '',
    ];
}

/**
 * Return a multipart file entry from the request, if present.
 *
 * @return array<array-key, mixed>|null
 */
function novamira_get_multipart_upload_file(WP_REST_Request $request): ?array
{
    /** @var array<string, array<array-key, mixed>> $files */
    $files = $request->get_file_params();
    foreach ($files as $field => $candidate) {
        if ($field === 'file' || count($files) === 1) {
            return $candidate;
        }
    }

    return null;
}

/**
 * Open a multipart upload source stream.
 *
 * @param array<array-key, mixed> $file File entry from WP_REST_Request::get_file_params().
 * @return array{stream: resource, source: string, filename: string}|WP_Error
 */
function novamira_open_multipart_upload_source(array $file): array|WP_Error
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        return new WP_Error('upload_failed', novamira_upload_error_message($error));
    }

    $tmp_name = '';
    if (array_key_exists('tmp_name', $file) && is_string($file['tmp_name'])) {
        $tmp_name = $file['tmp_name'];
    }
    if ($tmp_name === '' || !is_uploaded_file($tmp_name)) {
        return new WP_Error('invalid_upload', 'The multipart upload did not contain a valid uploaded file.');
    }

    $stream = fopen($tmp_name, mode: 'rb');
    if ($stream === false) {
        return new WP_Error('upload_read_failed', 'Could not read uploaded file.');
    }

    $name = '';
    if (array_key_exists('name', $file) && is_string($file['name'])) {
        $name = $file['name'];
    }

    return [
        'stream' => $stream,
        'source' => 'multipart',
        'filename' => sanitize_file_name($name),
    ];
}

/**
 * Write an upload stream to a new destination path.
 *
 * @param resource $source
 * @return array{bytes_written: int, created: bool}|WP_Error
 */
function novamira_create_upload_stream($source, string $resolved, int $max_bytes): array|WP_Error
{
    // The empty file only claims the name: the upload is written and checked aside, then moved over it.
    $target = fopen($resolved, mode: 'xb');
    if ($target === false) {
        if (file_exists($resolved)) {
            return new WP_Error('file_exists', sprintf('Destination already exists: %s', $resolved));
        }
        return new WP_Error('upload_write_failed', sprintf('Could not open destination for writing: %s', $resolved));
    }
    fclose($target);

    $result = novamira_overwrite_upload_stream(source: $source, resolved: $resolved, max_bytes: $max_bytes);
    if (is_wp_error($result)) {
        unlink($resolved);
        return $result;
    }

    return [
        'bytes_written' => $result['bytes_written'],
        'created' => true,
    ];
}

/**
 * Write an upload stream, replacing the destination path if it exists.
 *
 * @param resource $source
 * @return array{bytes_written: int, created: bool}|WP_Error
 */
function novamira_overwrite_upload_stream($source, string $resolved, int $max_bytes): array|WP_Error
{
    $created = !file_exists($resolved);
    $temporary_path = tempnam(dirname($resolved), prefix: '.novamira-upload-');
    if ($temporary_path === false) {
        return new WP_Error('upload_temp_failed', sprintf(
            'Could not create temporary upload file in: %s',
            dirname($resolved),
        ));
    }

    $target = fopen($temporary_path, mode: 'wb');
    if ($target === false) {
        unlink($temporary_path);
        return new WP_Error('upload_write_failed', sprintf('Could not open destination for writing: %s', $resolved));
    }

    $bytes_written = novamira_copy_limited_stream(source: $source, target: $target, max_bytes: $max_bytes);
    fclose($target);

    if (is_wp_error($bytes_written)) {
        unlink($temporary_path);
        return $bytes_written;
    }
    $guard_error = novamira_uploaded_php_guard_error($resolved, $temporary_path);
    if ($guard_error !== null) {
        unlink($temporary_path);
        return $guard_error;
    }

    if (!rename($temporary_path, $resolved)) {
        unlink($temporary_path);
        return new WP_Error('upload_move_failed', sprintf('Could not move uploaded file into place: %s', $resolved));
    }

    chmod(filename: $resolved, permissions: 0644);

    return [
        'bytes_written' => $bytes_written,
        'created' => $created,
    ];
}

/** The PHP guard check for an uploaded file, read from where it was written before it takes its place. */
function novamira_uploaded_php_guard_error(string $destination, string $written): ?WP_Error
{
    if (!in_array(strtolower(pathinfo($destination, PATHINFO_EXTENSION)), NOVAMIRA_PHP_EXTENSIONS, strict: true)) {
        return null;
    }
    $content = file_get_contents($written);
    return novamira_php_guard_error($destination, is_string($content) ? $content : '');
}

/**
 * Copy a stream while enforcing a byte limit.
 *
 * @param resource $source
 * @param resource $target
 * @return int|WP_Error
 */
function novamira_copy_limited_stream($source, $target, int $max_bytes): int|WP_Error
{
    $bytes_written = 0;
    while (!feof($source)) {
        $chunk = fread($source, length: 1_048_576);
        if ($chunk === false) {
            return new WP_Error('upload_read_failed', 'Could not read upload stream.');
        }
        if ($chunk === '') {
            continue;
        }

        $bytes_written += strlen($chunk);
        if ($bytes_written > $max_bytes) {
            return new WP_Error('upload_too_large', sprintf(
                'Upload exceeds the signed URL limit of %d bytes.',
                $max_bytes,
            ));
        }

        if (fwrite($target, $chunk) === false) {
            return new WP_Error('upload_write_failed', 'Could not write upload stream.');
        }
    }

    return $bytes_written;
}

/**
 * Return a human-readable upload error message.
 */
function novamira_upload_error_message(int $error): string
{
    return match ($error) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Uploaded file exceeds the configured PHP upload size limit.',
        UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded.',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'The server is missing a temporary upload directory.',
        UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded file to disk.',
        UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.',
        default => 'The upload failed.',
    };
}

/**
 * Return the signing secret for upload URLs.
 */
function novamira_upload_token_secret(): string
{
    return wp_salt('auth') . '|' . wp_salt('secure_auth') . '|novamira-upload-link';
}

/**
 * Encode bytes with base64url.
 */
function novamira_base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), from: '+/', to: '-_'), characters: '=');
}

/**
 * Decode base64url bytes.
 */
function novamira_base64url_decode(string $value): string|false
{
    $padding = strlen($value) % 4;
    if ($padding !== 0) {
        $value .= str_repeat('=', 4 - $padding);
    }

    return base64_decode(strtr($value, from: '-_', to: '+/'), strict: true);
}
