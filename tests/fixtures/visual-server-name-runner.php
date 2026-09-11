<?php
// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

define('ABSPATH', value: '/');
define('NOVAMIRA_VISUAL_VERSION', value: '0.0.0-test');

$stored_home = $argv[1] ?? '';
$filtered_home = $argv[2] ?? '';
$site_name = $argv[3] ?? '';

function get_option(string $option, mixed $default_value = false): mixed
{
    global $stored_home;
    return $option === 'home' ? $stored_home : $default_value;
}

function home_url(string $path = ''): string
{
    global $filtered_home;
    return $path === '' ? $filtered_home : rtrim($filtered_home, characters: '/') . '/' . ltrim($path, characters: '/');
}

function wp_parse_url(string $url, int $component = -1): mixed
{
    return parse_url($url, $component);
}

function get_bloginfo(string $show = ''): string
{
    global $site_name;
    return $show === 'name' ? $site_name : '';
}

function add_action(string $hook_name, mixed $callback, int $priority = 10, int $accepted_args = 1): bool
{
    return true;
}

function __(string $text, string $domain = 'default'): string
{
    return $text;
}

require_once __DIR__ . '/../../includes/connect-page.php';
require_once __DIR__ . '/../../novamira-visual/includes/Workspace.php';

$workspace = (new ReflectionClass(\NovamiraVisual\Workspace::class))->newInstanceWithoutConstructor();
$server_name = (new ReflectionMethod($workspace, 'server_name'))->invoke($workspace);
/** @var array{name: string, display_name: string} $manifest */
$manifest = (new ReflectionMethod($workspace, 'build_mcpb_manifest'))->invoke(
    $workspace,
    'https://example.com/wp-admin/admin-post.php?action=novamira-visual',
    $server_name,
);

echo json_encode([
    'serverName' => $server_name,
    'manifestName' => $manifest['name'],
    'displayName' => $manifest['display_name'],
]);
