<?php
// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

define('ABSPATH', '/');

$stored_home = $argv[1] ?? '';
$filtered_home = $argv[2] ?? '';

function get_option(string $option, mixed $default_value = false): mixed
{
    global $stored_home;
    return $option === 'home' ? $stored_home : $default_value;
}

function home_url(string $path = ''): string
{
    global $filtered_home;
    return $path === '' ? $filtered_home : rtrim($filtered_home, '/') . '/' . ltrim($path, '/');
}

function wp_parse_url(string $url, int $component = -1): mixed
{
    return parse_url($url, $component);
}

require_once __DIR__ . '/../../includes/connect-page.php';

echo novamira_get_mcp_server_name_default();
