<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

// One request's worth of WP-CLI resolution under the open_basedir the test passes with -d:
// php wp-cli-discovery-open-basedir-request.php <cached|run|windows> [<path to store as the cached invocation>]
// [<echo|throw|consume>]. The cache key depends on open_basedir, so a cached entry has to be stored from
// inside this process. The last argument installs an error handler first, as another plugin may have: one
// that prints every warning, one that throws it, or one that swallows it.

require __DIR__ . '/wp-cli-discovery-stubs.php';
require __DIR__ . '/../../../includes/abilities/bootstrap.php';
require __DIR__ . '/../../../includes/abilities/run-wp-cli.php';

$handler = (string) ($argv[3] ?? '');
if ($handler !== '') {
    set_error_handler(static function (int $errno, string $message) use ($handler): bool {
        if ($handler === 'throw') {
            throw new ErrorException($message, 0, $errno);
        }
        if ($handler === 'echo') {
            echo 'Handled: ' . $message . "\n";
        }

        return true;
    });
}

$seed = (string) ($argv[2] ?? '');
if ($seed !== '') {
    set_transient(novamira_wp_cli_discovery_transient(), ['command' => [$seed]], DAY_IN_SECONDS);
}

if (($argv[1] ?? '') === 'run') {
    echo json_encode(novamira_run_wp_cli(['args' => ['cli', 'info']]));
    exit();
}

if (($argv[1] ?? '') === 'windows') {
    echo json_encode(['command' => novamira_find_wp_cli_command_windows()]);
    exit();
}

$command = novamira_find_wp_cli_command();

echo json_encode(['available' => novamira_wp_cli_status()['available'], 'command' => $command]);
