<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

// Serves a discovery document the way a request on `init` does, with output buffers already open
// as a page-cache plugin would leave them. Run in a child process: serve() calls exit().

if ($argc !== 2) {
    exit(2);
}

define('ABSPATH', __DIR__ . '/');

function wp_json_encode(mixed $value): string|false
{
    return json_encode($value);
}

require dirname(__DIR__, 2) . '/includes/oauth/endpoints/discovery.php';

$append_comment = static fn(string $buffer): string => $buffer . '<!-- page cache: DONOTCACHEPAGE forbade it -->';

if ($argv[1] === 'non-removable') {
    // A buffer that cannot be removed must not stop the document from being sent, nor raise a notice.
    ob_start(chunk_size: 0, flags: PHP_OUTPUT_HANDLER_STDFLAGS ^ PHP_OUTPUT_HANDLER_REMOVABLE);
}

ob_start($append_comment);
echo 'stray output before the document';
ob_start($append_comment);

Novamira\OAuth\Endpoints\Discovery\serve(['issuer' => 'https://example.test', 'scopes_supported' => ['full']]);
