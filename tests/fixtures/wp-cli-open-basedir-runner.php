<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Child-process runner for WpCliDiscoveryTest:
 * php wp-cli-open-basedir-runner.php <plugin root> <site dir> [<probe path>...].
 *
 * Runs WP-CLI discovery under the open_basedir the test passes with -d and prints what it found,
 * plus an is_file() verdict for each probe path, as JSON. Any warning discovery emits is printed
 * ahead of the JSON, as it would be in a response.
 */

if ($argc < 3) {
    exit(2);
}

$root = $argv[1];
$site = $argv[2];
$probes = array_slice($argv, offset: 3);

define('ABSPATH', $site . '/');

function __(string $text, string $domain = 'default'): string
{
    return $text;
}

require $root . '/includes/abilities/run-wp-cli.php';

$discovery = [
    'unix' => novamira_find_wp_cli_command_unix(),
    'php_binary' => novamira_find_php_binary(),
    'php_ini' => novamira_find_wp_cli_php_ini(),
    'phar_outside' => novamira_wp_cli_phar_beside_launcher('/usr/local/bin/wp.bat'),
    'phar_inside' => novamira_wp_cli_phar_beside_launcher($site . '/wp.bat'),
];

$found = [];
foreach ($probes as $path) {
    $found[$path] = novamira_wp_cli_probe_path($path, check: 'is_file');
}

echo json_encode(['discovery' => $discovery, 'found' => $found]);
