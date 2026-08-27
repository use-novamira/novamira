<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Features;

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/api.php';
require_once __DIR__ . '/admin.php';

add_action(
    'plugins_loaded',
    static function (): void {
        initialize_features()->boot_active();
    },
    priority: 1,
);
add_action('admin_menu', __NAMESPACE__ . '\Admin\register_menu', priority: 24);
add_action('admin_init', __NAMESPACE__ . '\Admin\handle_update');
add_action('admin_enqueue_scripts', __NAMESPACE__ . '\Admin\enqueue_assets');
