<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

if ($argc !== 3) {
    exit(2);
}

define('ABSPATH', __DIR__ . '/');
define('NOVAMIRA_SANDBOX_DIR', rtrim($argv[1], '/') . '/');

if ($argv[2] === 'activation') {
    define('WP_SANDBOX_SCRAPING', true);
}

function add_action(): bool
{
    return true;
}

function wp_json_encode(mixed $value): string|false
{
    return json_encode($value);
}

function wp_mkdir_p(string $path): bool
{
    return is_dir($path) || mkdir($path, permissions: 0755, recursive: true);
}

require dirname(__DIR__, 2) . '/includes/helpers.php';

if ($argv[2] === 'prepare') {
    novamira_prepare_sandbox_directory();
    echo 'runner-complete';
    exit();
}

// Stands in for a response that is already being buffered while sandbox files load: WordPress and
// several hosts keep an output buffer open across the request. The loader must leave that buffer,
// and only that buffer, exactly as it found it.
$buffered = $argv[2] === 'buffered-request';
if ($buffered) {
    ob_start();
    echo 'response-body';
}

require dirname(__DIR__, 2) . '/includes/sandbox-loader.php';

if ($buffered) {
    $level = ob_get_level();
    $body = $level > 0 ? (string) ob_get_clean() : '<<outer-buffer-destroyed>>';
    echo $body . '|ob-level:' . $level . '|';
}

echo 'runner-complete';
