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
 * Third-party plugins and themes that Novamira Pro ships dedicated
 * specializations and skills for. Drives the personalized upsell copy: any of
 * these that is active on the site gets named explicitly.
 *
 * The `category` keys group entries for the generic fallback copy; see
 * novamira_pro_integration_groups().
 *
 * Each entry declares one of `constant` / `class` / `function`; presence of that symbol means the
 * plugin or theme is active (see novamira_pro_integration_active()).
 *
 * @return list<array{label: string, category: string, constant?: string, class?: string, function?: string}>
 */
// @mago-expect lint:halstead
function novamira_pro_integration_catalog(): array
{
    return [
        // Page builders, themes, and block libraries.
        ['label' => 'Elementor', 'category' => 'builder', 'constant' => 'ELEMENTOR_VERSION'],
        ['label' => 'Bricks Builder', 'category' => 'builder', 'constant' => 'BRICKS_VERSION'],
        ['label' => 'Bricksforge', 'category' => 'builder', 'constant' => 'BRICKSFORGE_VERSION'],
        ['label' => 'Divi 5', 'category' => 'builder', 'constant' => 'ET_BUILDER_VERSION'],
        ['label' => 'WPBakery Page Builder', 'category' => 'builder', 'constant' => 'WPB_VC_VERSION'],
        ['label' => 'Breakdance', 'category' => 'builder', 'function' => 'Breakdance\\Data\\get_global_option'],
        ['label' => 'Mosaic', 'category' => 'builder', 'class' => 'Mosaic\\Database\\MosaicDB'],
        ['label' => 'Etch', 'category' => 'builder', 'class' => 'Etch\\Plugin'],
        ['label' => 'Beaver Builder', 'category' => 'builder', 'class' => 'FLBuilderModel'],
        ['label' => 'GeneratePress', 'category' => 'builder', 'function' => 'generate_get_option'],
        ['label' => 'GenerateBlocks', 'category' => 'builder', 'constant' => 'GENERATEBLOCKS_VERSION'],
        ['label' => 'Kadence', 'category' => 'builder', 'class' => 'Kadence\\Theme'],
        ['label' => 'Kadence Blocks', 'category' => 'builder', 'constant' => 'KADENCE_BLOCKS_VERSION'],
        // Custom fields and content modeling.
        ['label' => 'Advanced Custom Fields', 'category' => 'content', 'class' => 'ACF'],
        ['label' => 'JetEngine', 'category' => 'content', 'function' => 'jet_engine'],
        ['label' => 'Meta Box', 'category' => 'content', 'constant' => 'RWMB_VER'],
        ['label' => 'Pods', 'category' => 'content', 'constant' => 'PODS_VERSION'],
        ['label' => 'ACPT', 'category' => 'content', 'constant' => 'ACPT_PLUGIN_VERSION'],
        ['label' => 'ASE', 'category' => 'content', 'constant' => 'ASENHA_VERSION'],
        // SEO.
        ['label' => 'Yoast SEO', 'category' => 'seo', 'constant' => 'WPSEO_VERSION'],
        ['label' => 'Rank Math SEO', 'category' => 'seo', 'constant' => 'RANK_MATH_VERSION'],
        ['label' => 'All in One SEO', 'category' => 'seo', 'constant' => 'AIOSEO_VERSION'],
        ['label' => 'SeoPress', 'category' => 'seo', 'constant' => 'SEOPRESS_VERSION'],
        // Forms.
        ['label' => 'Contact Form 7', 'category' => 'forms', 'constant' => 'WPCF7_VERSION'],
        ['label' => 'WPForms', 'category' => 'forms', 'constant' => 'WPFORMS_VERSION'],
        ['label' => 'Gravity Forms', 'category' => 'forms', 'class' => 'GFForms'],
        ['label' => 'Fluent Forms', 'category' => 'forms', 'constant' => 'FLUENTFORM_VERSION'],
        ['label' => 'Formidable Forms', 'category' => 'forms', 'class' => 'FrmAppHelper'],
        ['label' => 'Ninja Forms', 'category' => 'forms', 'class' => 'Ninja_Forms'],
        // Commerce, dev tools, dynamic content.
        ['label' => 'WooCommerce', 'category' => 'commerce', 'class' => 'WooCommerce'],
        ['label' => 'Code Snippets', 'category' => 'dev', 'constant' => 'CODE_SNIPPETS_VERSION'],
        ['label' => 'Dynamic Shortcodes', 'category' => 'dynamic', 'constant' => 'DYNAMIC_SHORTCODES_VERSION'],
    ];
}

/**
 * Whether the integration's plugin or theme is active, detected by the presence of the constant,
 * class, or function its catalog entry declares.
 *
 * @param array{label: string, category: string, constant?: string, class?: string, function?: string} $integration
 */
function novamira_pro_integration_active(array $integration): bool
{
    $constant = $integration['constant'] ?? '';
    $class = $integration['class'] ?? '';
    $function = $integration['function'] ?? '';

    return (
        $constant !== ''
        && defined($constant)
        || $class !== ''
        && class_exists($class)
        || $function !== ''
        && function_exists($function)
    );
}

/**
 * Labels of catalog integrations whose plugin or theme is active on this site,
 * in catalog order.
 *
 * @return list<string>
 */
function novamira_pro_active_integrations(): array
{
    $active = [];
    foreach (novamira_pro_integration_catalog() as $integration) {
        if (!novamira_pro_integration_active($integration)) {
            continue;
        }
        $active[] = $integration['label'];
    }
    return $active;
}

/**
 * Short, category-level summary of what Pro specializes in, for the generic (no-match) copy:
 * e.g. "page builders, custom fields plugins, SEO plugins, form plugins, and more". Kept brief so
 * the fallback blurb never enumerates all the integrations; the single-entry categories
 * (WooCommerce, Code Snippets, Dynamic Shortcodes) and future specializations fall under "more".
 */
function novamira_pro_integration_groups(): string
{
    $group_labels = [
        'builder' => __('page builders', domain: 'novamira'),
        'content' => __('custom fields plugins', domain: 'novamira'),
        'seo' => __('SEO plugins', domain: 'novamira'),
        'forms' => __('form plugins', domain: 'novamira'),
    ];

    $present = [];
    foreach (novamira_pro_integration_catalog() as $integration) {
        $present[$integration['category']] = true;
    }

    $names = [];
    foreach ($group_labels as $category => $label) {
        if (!($present[$category] ?? false)) {
            continue;
        }
        $names[] = $label;
    }
    $names[] = __('more', domain: 'novamira');

    return wp_sprintf('%l', $names);
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
