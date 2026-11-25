<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * What a person reads before turning AI Abilities on, from the Configuration page or the admin bar: what the AI will be
 * able to do, that approvals rely on its judgement, and what they do not cover. Turning on waits for "I understand".
 */

if (!defined('ABSPATH')) {
    exit();
}

/** @return list<string> What approvals do not cover, in the words of the dialog. */
function novamira_activation_dialog_limits(): array
{
    return [
        __('An action the check does not recognise is judged by your AI alone.', domain: 'novamira'),
        __(
            'Abilities of other plugins are rated by your AI from their description alone: Novamira does not check their code.',
            domain: 'novamira',
        ),
    ];
}

/**
 * The dialog, once per page where AI Abilities can be turned on. The page's scripts open it with
 * window.novamiraConfirmActivation(onConfirm).
 */
function novamira_render_activation_dialog(): void
{
    if (!novamira_current_user_can_manage() || !novamira_admin_ui_visible() || novamira_is_enabled()) {
        return;
    }
    ?>
    <dialog id="novamira-activation-dialog" class="novamira-activation-dialog" aria-labelledby="novamira-activation-title">
        <h2 id="novamira-activation-title"><?php esc_html_e(
            'Before you turn on AI Abilities',
            domain: 'novamira',
        ); ?></h2>
        <p><?php esc_html_e(
            'AI agents will be able to execute PHP code and access the filesystem of this site.',
            domain: 'novamira',
        ); ?></p>
        <p><?php esc_html_e(
            'Before an action, your AI rates how much it deserves a look, and asks you from the level you choose. On top of that, Novamira checks your AI\'s code to catch some of the riskiest actions, such as deleting content, installing plugins or sending data outside the site.',
            domain: 'novamira',
        ); ?></p>
        <p><?php esc_html_e(
            'Approvals rely on your AI\'s judgement: use a capable AI model and keep a backup. They do not cover everything:',
            domain: 'novamira',
        ); ?></p>
        <ul>
            <?php foreach (novamira_activation_dialog_limits() as $limit) { ?>
                <li><?php echo esc_html($limit); ?></li>
            <?php } ?>
        </ul>
        <p><?php esc_html_e(
            'Approvals are designed to catch the mistakes of an AI working for you. They are not designed to stop an AI, or a person, that is trying to get around them.',
            domain: 'novamira',
        ); ?></p>
        <label class="novamira-activation-understand">
            <input type="checkbox" id="novamira-activation-understand">
            <?php esc_html_e('I understand', domain: 'novamira'); ?>
        </label>
        <p class="novamira-activation-actions">
            <button type="button" class="button button-primary" id="novamira-activation-confirm" disabled><?php esc_html_e(
                'Turn on AI Abilities',
                domain: 'novamira',
            ); ?></button>
            <button type="button" class="button" id="novamira-activation-cancel"><?php esc_html_e(
                'Cancel',
                domain: 'novamira',
            ); ?></button>
        </p>
    </dialog>
    <style>
    .novamira-activation-dialog {
        max-width: 560px;
        padding: 24px 28px;
        border: 0;
        border-radius: 8px;
        box-shadow: 0 12px 48px rgba(0, 0, 0, 0.3);
        color: #1d2327;
        font: 14px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    }
    .novamira-activation-dialog::backdrop { background: rgba(0, 0, 0, 0.5); }
    .novamira-activation-dialog h2 { margin: 0 0 12px; font-size: 18px; }
    .novamira-activation-dialog p { margin: 0 0 10px; }
    .novamira-activation-dialog ul { margin: 0 0 16px; padding-left: 20px; list-style: disc; }
    .novamira-activation-dialog li { margin: 0 0 6px; }
    .novamira-activation-understand { display: flex; gap: 8px; align-items: center; margin: 0 0 16px; font-weight: 600; }
    .novamira-activation-actions { display: flex; gap: 8px; margin: 0; }
    </style>
    <script>
    window.novamiraConfirmActivation = function (onConfirm) {
        var dialog = document.getElementById('novamira-activation-dialog');
        if (!dialog || typeof dialog.showModal !== 'function') {
            return;
        }
        var understand = document.getElementById('novamira-activation-understand');
        var confirmButton = document.getElementById('novamira-activation-confirm');
        var cancelButton = document.getElementById('novamira-activation-cancel');
        understand.checked = false;
        confirmButton.disabled = true;
        understand.onchange = function () { confirmButton.disabled = !understand.checked; };
        confirmButton.onclick = function () { dialog.close(); onConfirm(); };
        cancelButton.onclick = function () { dialog.close(); };
        dialog.showModal();
    };
    </script>
    <?php
}

add_action('admin_footer', callback: 'novamira_render_activation_dialog');
add_action('wp_footer', callback: 'novamira_render_activation_dialog');
