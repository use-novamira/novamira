<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Context;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Admin page for site-level context shown to connected agents. The saved
 * content is prepended to the Novamira discover-abilities instructions, while
 * the read-only preview shows the system instructions without that user layer.
 */

const INSTRUCTIONS_ENABLED_OPTION = 'novamira_instructions_enabled';

const INSTRUCTIONS_CONTENT_OPTION = 'novamira_instructions_content';

const LEGACY_PRO_INSTRUCTIONS_ENABLED_OPTION = 'nvp_instructions_enabled';

const LEGACY_PRO_INSTRUCTIONS_CONTENT_OPTION = 'nvp_instructions_content';

const OPTION_MISSING = '__novamira_option_missing__';

const USER_CONTEXT_FEATURE_ID = 'novamira/user-context';

function context_page_slug(): string
{
    return 'novamira-context';
}

/** @param array<string, scalar> $query */
function context_page_url(array $query = []): string
{
    return add_query_arg(array_merge(['page' => context_page_slug()], $query), admin_url('admin.php'));
}

function instructions_enabled_option_name(): string
{
    return INSTRUCTIONS_ENABLED_OPTION;
}

function instructions_content_option_name(): string
{
    return INSTRUCTIONS_CONTENT_OPTION;
}

function instructions_legacy_enabled_option_name(): string
{
    return LEGACY_PRO_INSTRUCTIONS_ENABLED_OPTION;
}

function instructions_legacy_content_option_name(): string
{
    return LEGACY_PRO_INSTRUCTIONS_CONTENT_OPTION;
}

function instructions_read_option_with_legacy(string $option_name, string $legacy_option_name, mixed $default): mixed
{
    /** @var mixed $value */
    $value = get_option($option_name, default_value: OPTION_MISSING);
    if ($value !== OPTION_MISSING) {
        return $value;
    }

    /** @var mixed $legacy_value */
    $legacy_value = get_option($legacy_option_name, default_value: OPTION_MISSING);
    if ($legacy_value !== OPTION_MISSING) {
        return $legacy_value;
    }

    return $default;
}

function instructions_update_content(string $content): void
{
    update_option(instructions_content_option_name(), $content);
    update_option(instructions_legacy_content_option_name(), $content);
}

function legacy_pro_context_loaded(): bool
{
    return (
        function_exists('\\Novamira\\Pro\\instructions_get_content')
        && function_exists('\\Novamira\\Pro\\instructions_is_enabled')
    );
}

function instructions_is_enabled(): bool
{
    try {
        return \Novamira\Features\features()->is_active(USER_CONTEXT_FEATURE_ID);
    } catch (\LogicException) {
        // Before the Features runtime initializes, retain the historical
        // value. Normal requests use only the central feature preference.
        return filter_var(
            instructions_read_option_with_legacy(
                option_name: instructions_enabled_option_name(),
                legacy_option_name: instructions_legacy_enabled_option_name(),
                default: true,
            ),
            FILTER_VALIDATE_BOOLEAN,
        );
    }
}

function instructions_get_content(): string
{
    /** @var mixed $raw */
    $raw = instructions_read_option_with_legacy(
        option_name: instructions_content_option_name(),
        legacy_option_name: instructions_legacy_content_option_name(),
        default: '',
    );
    return is_string($raw) ? $raw : '';
}

function instructions_custom_injection_suppression_state(string $action = 'read'): bool
{
    static $suppressed = false;

    $previous = $suppressed;
    if ($action === 'suppress') {
        $suppressed = true;
    }
    if ($action === 'restore_enabled') {
        $suppressed = true;
    }
    if ($action === 'restore_disabled') {
        $suppressed = false;
    }

    return $previous;
}

function instructions_custom_injection_suppressed(): bool
{
    return instructions_custom_injection_suppression_state();
}

function instructions_post_string(string $key): string
{
    $raw = $_POST[$key] ?? '';
    if (!is_string($raw)) {
        return '';
    }

    return wp_unslash($raw);
}

function register_context_menu(): void
{
    if (!defined('NOVAMIRA_VERSION')) {
        return;
    }

    // Stay out while a Novamira Pro that still manages custom instructions is
    // active: that Pro owns the instructions UI (its "Memory & Instructions"
    // page) until it is removed or updated to a version that hands context to
    // the base. The base then takes over automatically — see
    // legacy_pro_context_loaded().
    if (legacy_pro_context_loaded()) {
        return;
    }

    add_submenu_page(
        parent_slug: 'novamira-connect',
        page_title: __('Novamira Context', domain: 'novamira'),
        menu_title: __('Context', domain: 'novamira'),
        capability: \novamira_manage_capability(),
        menu_slug: context_page_slug(),
        callback: __NAMESPACE__ . '\\render_context_page',
    );
}

/** @return array{type:string,message:string}|null */
function context_handle_post(): ?array
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return null;
    }

    $action = instructions_post_string('novamira_context_action');
    if ($action !== 'save_context') {
        return null;
    }

    check_admin_referer('novamira_context');

    if (!\novamira_current_user_can_manage()) {
        wp_die(esc_html__('You are not allowed to manage context.', domain: 'novamira'));
    }

    return instructions_handle_save();
}

/** @return array{type:string,message:string} */
function instructions_handle_save(): array
{
    $content = instructions_post_string('instructions_content');
    instructions_update_content($content);

    return [
        'type' => 'success',
        'message' => __('User context saved.', domain: 'novamira'),
    ];
}

function context_system_instructions_preview(): string
{
    if (!function_exists('novamira_build_server_instructions')) {
        return __('System instructions are unavailable until the base Novamira plugin has loaded.', domain: 'novamira');
    }

    $previous = instructions_custom_injection_suppression_state(action: 'suppress');

    try {
        return (string) apply_filters(
            'novamira_discover_abilities_instructions',
            \novamira_build_server_instructions(),
        );
    } finally {
        instructions_custom_injection_suppression_state(action: $previous ? 'restore_enabled' : 'restore_disabled');
    }
}

function context_system_instructions_excerpt(string $instructions, int $line_count = 6): string
{
    if ($line_count < 1) {
        return '...';
    }

    $normalized = preg_replace(pattern: '/\r\n?/', replacement: "\n", subject: $instructions) ?? $instructions;
    $lines = explode(separator: "\n", string: ltrim($normalized, characters: "\n"));

    if (count($lines) <= $line_count) {
        return implode(separator: "\n", array: $lines);
    }

    $excerpt = array_slice($lines, offset: 0, length: $line_count);
    $excerpt[] = '...';

    return implode(separator: "\n", array: $excerpt);
}

function render_context_page(): void
{
    if (!\novamira_current_user_can_manage()) {
        return;
    }

    $notice = context_handle_post();

    if (function_exists('novamira_render_admin_header')) {
        novamira_render_admin_header();
    }

    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline"><?php esc_html_e('Context', domain: 'novamira'); ?></h1>
        <hr class="wp-header-end">

        <p class="description novamira-context-intro"><?php esc_html_e(
            'Review the system instructions connected agents receive, then add site-specific context that should apply in every conversation.',
            domain: 'novamira',
        ); ?></p>

        <?php render_context_notice($notice); ?>
        <?php render_context_styles(); ?>
        <?php render_context_system_section(); ?>
        <?php render_user_context_state(); ?>
        <?php do_action('novamira_context_page_sections'); ?>
    </div>
    <?php
}

function render_context_styles(): void
{ ?>
    <style>
        .novamira-context-intro { margin-top:8px; max-width:800px; }
        .novamira-context-section { margin-top:24px; max-width:1100px; }
        .novamira-context-section h2 { margin-bottom:4px; font-size:16px; }
        .novamira-context-panel { background:#fff; border:1px solid #dcdcde; border-radius:12px; padding:16px 20px; margin-top:12px; }
        .novamira-system-box summary { cursor:pointer; font-weight:600; }
        .novamira-system-preview-wrap { position:relative; margin-top:12px; }
        .novamira-system-preview { box-sizing:border-box; width:100%; max-height:none; overflow:auto; margin:0; padding:14px 16px; background:#f6f7f7; border:1px solid #dcdcde; border-radius:8px; white-space:pre-wrap; font-family:ui-monospace, SFMono-Regular, Consolas, "Liberation Mono", Menlo, monospace; font-size:12px; line-height:1.55; }
        .novamira-system-preview.is-excerpt { margin-top:12px; }
        .novamira-system-details[open] + .novamira-system-preview.is-excerpt { display:none; }
        .novamira-context-guidance { margin-top:12px; color:#1d2327; }
        .novamira-context-guidance ul { list-style:disc; margin-left:20px; max-width:780px; }
        .novamira-context-guidance li { margin:4px 0; }
        .novamira-context-disabled { display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap; border-style:dashed; }
        .novamira-context-disabled p { margin:4px 0 0; color:#50575e; }
        .novamira-context-form { margin-top:12px; }
        .novamira-context-form textarea { font-family:ui-monospace, SFMono-Regular, Consolas, "Liberation Mono", Menlo, monospace; font-size:13px; }
    </style>
    <?php }

function render_context_system_section(): void
{
    $preview = context_system_instructions_preview();
    $excerpt = context_system_instructions_excerpt($preview);
    ?>
    <section class="novamira-context-section" aria-labelledby="novamira-system-context-heading">
        <h2 id="novamira-system-context-heading"><?php esc_html_e('System context', domain: 'novamira'); ?></h2>
        <p class="description"><?php esc_html_e(
            'Read-only instructions Novamira generates for this site, adapting them to the plugins you have active. Shown here for visibility and not editable from this page.',
            domain: 'novamira',
        ); ?></p>

        <div class="novamira-context-panel novamira-system-box">
            <details class="novamira-system-details">
                <summary><?php esc_html_e('Show full system context', domain: 'novamira'); ?></summary>
                <div class="novamira-system-preview-wrap" aria-label="<?php echo
                    esc_attr__('Read-only system context preview', domain: 'novamira')
                ; ?>">
                    <pre class="novamira-system-preview is-full"><?php echo esc_html($preview); ?></pre>
                </div>
            </details>
            <pre class="novamira-system-preview is-excerpt"><?php echo esc_html($excerpt); ?></pre>
        </div>
    </section>
    <?php
}

function render_user_context_section(): void
{
    $content = instructions_get_content();
    $form_url = context_page_url();
    ?>
    <section class="novamira-context-section" aria-labelledby="novamira-user-context-heading">
        <h2 id="novamira-user-context-heading"><?php esc_html_e('User context', domain: 'novamira'); ?></h2>
        <p class="description"><?php esc_html_e(
            'Additional instructions provided by this site owner for all connected agents.',
            domain: 'novamira',
        ); ?></p>

        <div class="novamira-context-panel novamira-context-guidance">
            <p style="margin-top:0;"><?php esc_html_e(
                'Stable context agents should apply on this site without asking again.',
                domain: 'novamira',
            ); ?></p>
            <ul>
                <li><?php esc_html_e(
                    'Site goals, brand voice, audience, and naming conventions.',
                    domain: 'novamira',
                ); ?></li>
                <li><?php esc_html_e(
                    'Constraints: what to avoid, what needs approval, and preferred workflows.',
                    domain: 'novamira',
                ); ?></li>
            </ul>
            <p style="margin-bottom:0;"><?php esc_html_e(
                'No passwords, API keys, private data, or one-off notes. Keep it stable and site-wide.',
                domain: 'novamira',
            ); ?></p>
        </div>

        <form method="post" action="<?php echo esc_url($form_url); ?>" class="novamira-context-form">
            <?php wp_nonce_field('novamira_context'); ?>
            <input type="hidden" name="novamira_context_action" value="save_context">
            <label for="instructions_content" class="screen-reader-text"><?php esc_html_e(
                'User context',
                domain: 'novamira',
            ); ?></label>
            <textarea
                id="instructions_content"
                name="instructions_content"
                rows="14"
                class="large-text code"
                placeholder="<?php echo
                    esc_attr__(
                        'Write site-level context for connected agents. Markdown is supported.',
                        domain: 'novamira',
                    )
                ; ?>"
            ><?php echo esc_textarea($content); ?></textarea>
            <p style="margin-top:8px;">
                <button type="submit" class="button button-primary"><?php esc_html_e(
                    'Save context',
                    domain: 'novamira',
                ); ?></button>
            </p>
        </form>
    </section>
    <?php
}

function render_user_context_state(): void
{
    if (instructions_is_enabled()) {
        render_user_context_section();
        return;
    }

    $features_url = admin_url('admin.php?page=novamira-features#novamira-user-context');
    ?>
    <section class="novamira-context-section" aria-labelledby="novamira-user-context-disabled-heading">
        <h2 id="novamira-user-context-disabled-heading"><?php esc_html_e('User context', domain: 'novamira'); ?></h2>
        <div class="novamira-context-panel novamira-context-disabled">
            <div>
                <strong><?php esc_html_e('User context is disabled.', domain: 'novamira'); ?></strong>
                <p><?php esc_html_e(
                    'Connected agents receive only the system context shown above.',
                    domain: 'novamira',
                ); ?></p>
            </div>
            <a class="button button-secondary" href="<?php echo esc_url($features_url); ?>"><?php esc_html_e(
                'Manage features',
                domain: 'novamira',
            ); ?></a>
        </div>
    </section>
    <?php
}

/** @param array{type:string,message:string}|null $notice */
function render_context_notice(?array $notice): void
{
    if ($notice === null) {
        return;
    }

    $type = in_array($notice['type'], ['success', 'warning', 'error', 'info'], strict: true) ? $notice['type'] : 'info';
    ?>
    <div class="notice notice-<?php echo esc_attr($type); ?> is-dismissible"><p><?php echo
        esc_html($notice['message'])
    ; ?></p></div>
    <?php
}

function boot_context_admin(): void
{
    add_action('admin_menu', callback: __NAMESPACE__ . '\\register_context_menu', priority: 30);
}
