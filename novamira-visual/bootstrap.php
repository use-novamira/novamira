<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Visual;

if (!defined('ABSPATH')) {
    exit();
}

define(constant_name: 'NOVAMIRA_VISUAL_VERSION', value: NOVAMIRA_VERSION);
define(constant_name: 'NOVAMIRA_VISUAL_PLUGIN_DIR', value: __DIR__ . '/');
define(constant_name: 'NOVAMIRA_VISUAL_PLUGIN_URL', value: (string) NOVAMIRA_PLUGIN_URL . 'novamira-visual/');

require_once __DIR__ . '/includes/Workspace.php';
require_once __DIR__ . '/includes/BackendTools.php';
require_once __DIR__ . '/includes/BackendApprovals.php';

new \NovamiraVisual\Workspace();

function asset_version(string $asset_file): string
{
    $modified_time = file_exists($asset_file) ? filemtime($asset_file) : false;

    return is_int($modified_time) ? (string) $modified_time : (string) NOVAMIRA_VISUAL_VERSION;
}

/**
 * Enqueue the builder tool bundle inside the Gutenberg block editor.
 */
add_action('enqueue_block_editor_assets', static function (): void {
    $asset_file = NOVAMIRA_VISUAL_PLUGIN_DIR . 'dist/gutenberg.js';
    $version = asset_version($asset_file);

    wp_enqueue_script(
        'novamira-visual-gutenberg-tools',
        NOVAMIRA_VISUAL_PLUGIN_URL . 'dist/gutenberg.js',
        ['wp-blocks', 'wp-data', 'wp-dom-ready', 'wp-editor'],
        $version,
        args: true,
    );

    wp_localize_script('novamira-visual-gutenberg-tools', object_name: 'novamiraVisualData', l10n: [
        'restUrl' => esc_url_raw(rest_url('novamira-visual/v1')),
        'nonce' => wp_create_nonce('wp_rest'),
        // In read mode the editor exposes only the tools that read editor state.
        'readMode' => function_exists('novamira_is_read_mode') && \novamira_is_read_mode(),
    ]);
});
