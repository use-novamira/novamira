<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Build the nonce action for a persistent admin-notice dismissal.
 */
function novamira_admin_notice_nonce_action(string $meta_key, string $dismiss_value): string
{
    return 'novamira_dismiss_admin_notice_' . $meta_key . '_' . $dismiss_value;
}

/**
 * Persist dismissal of a Novamira admin notice for the current user.
 */
function novamira_handle_persistent_admin_notice_dismiss(): void
{
    $raw_meta_key = $_GET['novamira_notice_dismiss'] ?? null;
    $raw_value = $_GET['novamira_notice_value'] ?? null;
    if (!is_string($raw_meta_key) || !is_string($raw_value)) {
        return;
    }

    $meta_key = sanitize_key($raw_meta_key);
    $dismiss_value = sanitize_key($raw_value);
    if (!str_starts_with($meta_key, 'novamira_') || $dismiss_value === '') {
        return;
    }
    if (!novamira_current_user_can_manage()) {
        return;
    }

    check_admin_referer(novamira_admin_notice_nonce_action($meta_key, $dismiss_value));
    update_user_meta(get_current_user_id(), $meta_key, $dismiss_value);
    wp_die('Dismissed', title: 'Dismissed', args: ['response' => 200]);
}

/**
 * Render a standard WordPress notice whose X dismissal persists for the current user.
 *
 * The message may contain caller-escaped HTML, matching wp_admin_notice().
 *
 * @param array{
 *     type?: string,
 *     dismissible?: bool,
 *     id?: string,
 *     additional_classes?: array<array-key, string>,
 *     attributes?: array<array-key, string>,
 *     paragraph_wrap?: bool
 * } $args Additional wp_admin_notice() arguments.
 */
function novamira_render_persistent_admin_notice(
    string $message,
    string $meta_key,
    string $dismiss_value = '1',
    array $args = [],
): void {
    if ((string) get_user_meta(get_current_user_id(), $meta_key, single: true) === $dismiss_value) {
        return;
    }

    $dismiss_url = wp_nonce_url(
        add_query_arg([
            'novamira_notice_dismiss' => $meta_key,
            'novamira_notice_value' => $dismiss_value,
        ], admin_url()),
        action: novamira_admin_notice_nonce_action($meta_key, $dismiss_value),
    );

    $additional_classes = $args['additional_classes'] ?? [];
    $additional_classes[] = 'novamira-persistent-notice';

    $attributes = $args['attributes'] ?? [];
    $attributes['data-novamira-dismiss-url'] = $dismiss_url;

    $args['type'] ??= 'info';
    $args['dismissible'] = true;
    $args['additional_classes'] = $additional_classes;
    $args['attributes'] = $attributes;

    wp_admin_notice($message, $args);
    wp_enqueue_script(
        'novamira-admin-notices',
        (string) NOVAMIRA_PLUGIN_URL . 'includes/assets/admin-notices.js',
        [],
        NOVAMIRA_VERSION,
        args: true,
    );
}

add_action('admin_init', callback: 'novamira_handle_persistent_admin_notice_dismiss');
