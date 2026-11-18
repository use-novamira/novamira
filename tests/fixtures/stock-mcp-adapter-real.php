<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Loads the real, unprefixed MCP Adapter (wordpress/mcp-adapter, WP\MCP) and its schema (WP\McpSchema) from
 * the development vendor directory, as another plugin bundling them would.
 */

spl_autoload_register(static function (string $class): void {
    $vendor = dirname(__DIR__, levels: 2) . '/vendor/wordpress';
    $prefixes = [
        'WP\\MCP\\' => $vendor . '/mcp-adapter/includes/',
        'WP\\McpSchema\\' => $vendor . '/php-mcp-schema/src/',
    ];
    foreach ($prefixes as $prefix => $directory) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $file = $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
        return;
    }
});
