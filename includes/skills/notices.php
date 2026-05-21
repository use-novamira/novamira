<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Skills\Notices;

use Novamira\Skills\Admin;

if (!defined('ABSPATH')) {
    exit();
}

const RELOAD_NOTICE_TRANSIENT = 'novamira_skill_pending_reload_notice';

const RELOAD_NOTICE_TTL = 60;

/**
 * Mark that the skill index has changed and a reload-MCP-client notice
 * should be shown to the admin the next time they land on a Novamira
 * admin page. Transient auto-expires after RELOAD_NOTICE_TTL seconds.
 */
function set_pending_reload_notice(): void
{
    set_transient(RELOAD_NOTICE_TRANSIENT, value: '1', expiration: RELOAD_NOTICE_TTL);
}

/**
 * Hooked on `admin_notices`. Renders:
 *  - any inline notice queued by `redirect_with_notice()` (one-shot transient),
 *  - the reload-MCP-client notice while a change is "recent" (< 60s) and the
 *    current admin screen belongs to Novamira.
 */
function render(): void
{
    if (!current_user_can(Admin\CAPABILITY)) {
        return;
    }

    // Inline notice from a failed/successful action redirect.
    $inline_key = 'novamira_skill_admin_notice_' . get_current_user_id();
    /** @var array{type?: string, message?: string}|false $inline */
    $inline = get_transient($inline_key);
    if (is_array($inline)) {
        delete_transient($inline_key);
        $type = $inline['type'] ?? 'success';
        $message = $inline['message'] ?? '';
        $class = $type === 'error' ? 'notice-error' : 'notice-success';
        if ($message !== '') {
            printf('<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr($class), esc_html($message));
        }
    }

    // Reload notice while the change is "recent" (transient still alive)
    // and we're on the Skills admin page specifically — other Novamira
    // screens don't need this prompt.
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen instanceof \WP_Screen) {
        return;
    }
    if ($screen->id !== 'novamira_page_' . Admin\PAGE_SLUG) {
        return;
    }

    if (get_transient(RELOAD_NOTICE_TRANSIENT) === '1') {
        echo
            '<div class="notice notice-info is-dismissible"><p>'
                . esc_html__(
                    'Skill list updated. If your AI client is already connected to this site, restart the conversation (or reconnect the client) so it sees the new state.',
                    domain: 'novamira',
                )
                . '</p></div>'
        ;
    }
}
