<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Novamira Pro upsell: submenu entry, Connect-page card, dismissible welcome notice.
 */

if (!defined('ABSPATH')) {
    exit();
}

const NOVAMIRA_PRO_URL = 'https://novamira.ai/pro/';

const NOVAMIRA_PRO_DISMISS_PREFIX = 'novamira_pro_dismissed_';

const NOVAMIRA_PRO_WELCOME_KEY = 'welcome';

/**
 * True when the Novamira Pro plugin is active.
 * License state is irrelevant — if Pro is running, the upsell is.
 */
function novamira_pro_is_active(): bool
{
    return defined('NOVAMIRA_PRO_VERSION');
}

/**
 * Labels of the specializations whose plugin or theme is active on this site,
 * in published order.
 *
 * The list itself is not kept here: it is generated from the Pro manifest and
 * published with every Pro release (see includes/specializations.php), so this
 * plugin cannot fall behind the specializations Pro actually ships.
 *
 * @return list<string>
 */
function novamira_pro_active_integrations(): array
{
    $folders = novamira_active_folders();

    $active = [];
    foreach (novamira_specializations() as $specialization) {
        if (!novamira_specialization_is_active($specialization, $folders)) {
            continue;
        }
        $active[] = $specialization['label'];
    }
    return $active;
}

/**
 * The generic (no-match) copy: what Pro specializes in, said in groups rather
 * than by naming forty-odd products. Deliberately a plain sentence — the
 * published list always contains every group, so deriving this from it would
 * compute the same string every time.
 */
function novamira_pro_integration_groups(): string
{
    return __(
        'page builders, themes and block libraries, custom fields plugins, SEO plugins, form plugins, and more',
        domain: 'novamira',
    );
}

/**
 * One-line Pro upsell blurb, shared by the welcome notice and the Connect card.
 * Names the integrations the user already runs; falls back to the full grouped
 * catalog when none are detected.
 */
function novamira_pro_upsell_blurb(): string
{
    $active = novamira_pro_active_integrations();
    if ($active === []) {
        return sprintf(
            /* translators: %s: grouped list of integrations, e.g. "page builders (Elementor, Bricks), WooCommerce". */
            __(
                'Novamira Pro adds specializations that combine abilities and skills for %s, plus memory that persists between sessions.',
                domain: 'novamira',
            ),
            novamira_pro_integration_groups(),
        );
    }

    return sprintf(
        /* translators: %s: comma-separated list of detected plugin names, e.g. "Elementor, ACF, and WooCommerce". */
        __(
            'Novamira Pro adds specializations that combine abilities and skills for the tools you\'re already running (%s), plus memory that persists between sessions.',
            domain: 'novamira',
        ),
        wp_sprintf('%l', $active),
    );
}

/**
 * Append a "Get Pro" submenu entry that links out to novamira.ai/pro/.
 * Uses the $submenu global because add_submenu_page() doesn't accept external URLs.
 */
add_action(
    'admin_menu',
    static function (): void {
        if (novamira_pro_is_active()) {
            return;
        }
        // @mago-expect lint:no-global
        global $submenu;
        if (!is_array($submenu) || !is_array($submenu['novamira-connect'] ?? null)) {
            return;
        }
        $entries = $submenu['novamira-connect'];
        $entries[] = [
            '<span style="color:#f8ca50;font-weight:600;">' . esc_html__('Get Pro', domain: 'novamira') . '</span>',
            novamira_manage_capability(),
            esc_url(NOVAMIRA_PRO_URL . '?utm_source=plugin&utm_medium=submenu'),
        ];
        $submenu['novamira-connect'] = $entries;
    },
    priority: 99,
);

/**
 * Add a "Get Pro" action link on the Plugins page row for Novamira Free.
 */
add_filter(
    'plugin_action_links_' . plugin_basename(dirname(__DIR__) . '/novamira.php'),
    static function (array $links): array {
        if (novamira_pro_is_active()) {
            return $links;
        }
        $url = esc_url(NOVAMIRA_PRO_URL . '?utm_source=plugin&utm_medium=plugins_row');
        $links[] =
            '<a href="'
            . $url
            . '" target="_blank" rel="noopener">'
            . esc_html__('Get Pro', domain: 'novamira')
            . '</a>';
        return $links;
    },
);

add_action('admin_footer', static function (): void {
    if (!novamira_current_user_can_manage()) {
        return;
    }
    ?>
    <script>
    (function() {
        var links = document.querySelectorAll('#toplevel_page_novamira-connect .wp-submenu a');
        for (var i = 0; i < links.length; i++) {
            if (links[i].href.indexOf('novamira.ai/pro') !== -1) {
                links[i].target = '_blank';
                links[i].rel = 'noopener';
            }
        }
    })();
    </script>
    <?php
});

/**
 * Flag the welcome notice on first activation.
 */
register_activation_hook(dirname(__DIR__) . '/novamira.php', callback: 'novamira_pro_upsell_on_activate');
function novamira_pro_upsell_on_activate(): void
{
    if (get_option('novamira_pro_upsell_installed_at') === false) {
        update_option('novamira_pro_upsell_installed_at', time(), autoload: false);
    }
}

/**
 * Render the one-time welcome notice until dismissed.
 */
add_action('admin_notices', callback: 'novamira_render_pro_welcome_notice');

function novamira_render_pro_welcome_notice(): void
{
    if (!novamira_current_user_can_manage()) {
        return;
    }
    if (novamira_pro_is_active()) {
        return;
    }
    // Don't show on the Pro page itself or irrelevant screens outside Novamira admin.
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    $on_novamira =
        $screen
        && (
            str_starts_with($screen->id, 'toplevel_page_novamira')
            || str_starts_with($screen->id, 'novamira_page_')
            || $screen->id === 'dashboard'
            || $screen->id === 'plugins'
        );
    if (!$on_novamira) {
        return;
    }

    $pro_url = esc_url(NOVAMIRA_PRO_URL . '?utm_source=plugin&utm_medium=welcome_notice');
    $message = sprintf(
        '<strong>%s</strong> %s &nbsp; <a href="%s" target="_blank" rel="noopener" class="button button-primary" style="background:#f8ca50;border-color:#f8ca50;color:#1a1a1a;">%s</a>',
        esc_html__('Novamira Pro is here.', domain: 'novamira'),
        esc_html(novamira_pro_upsell_blurb()),
        $pro_url,
        esc_html__('Discover more', domain: 'novamira'),
    );
    novamira_render_persistent_admin_notice(
        $message,
        meta_key: NOVAMIRA_PRO_DISMISS_PREFIX . NOVAMIRA_PRO_WELCOME_KEY,
        args: [
            'type' => 'info',
            'additional_classes' => ['novamira-pro-notice'],
            'attributes' => ['style' => 'border-left-color:#f8ca50;'],
        ],
    );
}

/**
 * Render a Pro upsell card — called from the Connect page.
 */
function novamira_render_pro_upsell_card(): void
{
    if (novamira_pro_is_active()) {
        return;
    }
    $pro_url = esc_url(NOVAMIRA_PRO_URL . '?utm_source=plugin&utm_medium=connect_card');
    ?>
    <div class="novamira-pro-card" style="margin:24px 0;padding:20px 24px;border:1px solid #e0e0e0;border-left:4px solid #f8ca50;border-radius:4px;background:#fffdf5;">
        <h2 style="margin:0 0 6px;font-size:16px;">
            <?php esc_html_e('Novamira Pro', domain: 'novamira'); ?>
            <span style="display:inline-block;margin-left:6px;padding:1px 8px;font-size:10px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;background:#f8ca50;color:#1a1a1a;border-radius:3px;vertical-align:middle;">
                <?php esc_html_e('Beta', domain: 'novamira'); ?>
            </span>
        </h2>
        <p style="margin:0 0 12px;color:#50575e;">
            <?php echo esc_html(novamira_pro_upsell_blurb()); ?>
        </p>
        <a href="<?php echo
            $pro_url
        ; ?>" target="_blank" rel="noopener" class="button button-primary" style="background:#f8ca50;border-color:#f8ca50;color:#1a1a1a;">
            <?php esc_html_e('Get Novamira Pro', domain: 'novamira'); ?>
        </a>
    </div>
    <?php
}
