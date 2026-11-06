<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

// One request's worth of WP-CLI resolution in a fresh PHP process: no static, global or loaded
// function survives from an earlier request, only the file-backed transient store.

require __DIR__ . '/wp-cli-discovery-stubs.php';
require __DIR__ . '/../../../includes/abilities/bootstrap.php';
require __DIR__ . '/../../../includes/abilities/run-wp-cli.php';

echo json_encode([
    'available' => novamira_wp_cli_status()['available'],
    'command' => novamira_find_wp_cli_command(),
]);
