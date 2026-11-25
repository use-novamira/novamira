<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * A one-time notice for sites that already had AI Abilities on before approvals came: they never saw the activation
 * dialog, so they are told once, in plain words, what changed. New installations see the dialog instead.
 */

namespace Novamira\Confirmations;

if (!defined('ABSPATH')) {
    exit();
}

/** "show" on a site that had AI Abilities on when approvals arrived, "done" once dismissed or never needed. */
const V2_NOTICE_OPTION = 'novamira_v2_notice';

/** Decided once, on the first admin request with approvals: only a site already using Novamira is told. */
function v2_notice_state(): string
{
    /** @var mixed $state */
    $state = get_option(V2_NOTICE_OPTION, default_value: '');
    if ($state === 'show' || $state === 'done') {
        return $state;
    }
    $state = novamira_is_enabled() ? 'show' : 'done';
    update_option(V2_NOTICE_OPTION, $state, autoload: true);
    return $state;
}

add_action('admin_init', static function (): void {
    if (($_GET['novamira_v2_notice'] ?? '') !== 'dismiss' || !novamira_current_user_can_manage()) {
        return;
    }
    check_admin_referer('novamira_v2_notice');
    update_option(V2_NOTICE_OPTION, value: 'done', autoload: true);
    wp_safe_redirect(remove_query_arg(['novamira_v2_notice', '_wpnonce']));
    exit();
});

add_action('admin_notices', static function (): void {
    // Only inside Novamira's own pages, except AI Activity, where the levels are. Choosing a level means the person
    // has seen what approvals are.
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    $on_novamira =
        $screen !== null
        && (str_starts_with($screen->id, 'toplevel_page_novamira') || str_starts_with($screen->id, 'novamira_page_'));
    if (
        !$on_novamira
        || !novamira_current_user_can_manage()
        || ($_GET['page'] ?? '') === PAGE_SLUG
        || settings()['level_chosen']
        || v2_notice_state() !== 'show'
    ) {
        return;
    }
    $dismiss = wp_nonce_url(add_query_arg(['novamira_v2_notice' => 'dismiss']), action: 'novamira_v2_notice');
    ?>
    <div class="notice notice-info">
        <p><strong><?php esc_html_e('Novamira v2: ready for production', domain: 'novamira'); ?></strong></p>
        <p><?php esc_html_e(
            'Your AI now asks you before the actions it rates as important, and the AI change history records what it changes in the database. Your site starts on Balanced, the default level, and your AI connections keep working as before.',
            domain: 'novamira',
        ); ?></p>
        <p>
            <a class="button button-primary" href="<?php echo esc_url(dashboard_url()); ?>"><?php esc_html_e(
                'Choose how often it asks',
                domain: 'novamira',
            ); ?></a>
            <a class="button" href="<?php echo esc_url($dismiss); ?>"><?php esc_html_e(
                'Got it',
                domain: 'novamira',
            ); ?></a>
        </p>
    </div>
    <?php
});
