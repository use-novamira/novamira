<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Features\Admin;

use Novamira\Features;

if (!defined('ABSPATH')) {
    exit();
}

const PAGE_SLUG = 'novamira-features';

function register_menu(): void
{
    add_submenu_page(
        parent_slug: 'novamira-connect',
        page_title: __('Features', domain: 'novamira'),
        menu_title: __('Features', domain: 'novamira'),
        capability: \novamira_manage_capability(),
        menu_slug: PAGE_SLUG,
        callback: __NAMESPACE__ . '\\render_page',
    );
}

function enqueue_assets(string $hook): void
{
    if ($hook !== 'novamira_page_' . PAGE_SLUG) {
        return;
    }

    $admin_list_path = dirname(__DIR__) . '/assets/admin-list.css';
    $features_admin_path = __DIR__ . '/assets/admin.css';
    $debug_assets = defined('WP_DEBUG') && constant('WP_DEBUG') === true;
    $admin_list_version = $debug_assets && is_file($admin_list_path)
        ? (string) filemtime($admin_list_path)
        : NOVAMIRA_VERSION;
    $features_admin_version = $debug_assets && is_file($features_admin_path)
        ? (string) filemtime($features_admin_path)
        : NOVAMIRA_VERSION;

    wp_enqueue_style(
        'novamira-admin-list',
        (string) NOVAMIRA_PLUGIN_URL . 'includes/assets/admin-list.css',
        [],
        $admin_list_version,
    );
    wp_enqueue_style(
        'novamira-features-admin',
        (string) NOVAMIRA_PLUGIN_URL . 'includes/features/assets/admin.css',
        ['novamira-admin-list'],
        $features_admin_version,
    );
}

function handle_update(): void
{
    $action = is_string($_POST['novamira_feature_action'] ?? null)
        ? sanitize_key(wp_unslash($_POST['novamira_feature_action']))
        : '';
    if ($action !== 'set') {
        return;
    }
    if (!\novamira_current_user_can_manage()) {
        wp_die(__('You do not have permission to manage Novamira features.', domain: 'novamira'), args: [
            'response' => 403,
        ]);
    }
    check_admin_referer('novamira_set_feature');

    $id = is_string($_POST['feature_id'] ?? null) ? sanitize_text_field(wp_unslash($_POST['feature_id'])) : '';
    $manager = Features\features();
    $definition = $manager->definition($id);
    if ($definition === null || !$definition->toggleable) {
        wp_safe_redirect(admin_url('admin.php?page=' . PAGE_SLUG . '&novamira_result=invalid'));
        exit();
    }

    $activate = ($_POST['active'] ?? null) === '1';
    $transition = $activate ? $manager->activate($id) : $manager->deactivate($id);
    if (!$transition->applied) {
        wp_safe_redirect(admin_url('admin.php?page=' . PAGE_SLUG . '&novamira_result=blocked'));
        exit();
    }
    redirect_after_update($id);
}

function redirect_after_update(string $id): void
{
    wp_safe_redirect(
        admin_url('admin.php?page=' . PAGE_SLUG . '&novamira_result=updated') . '#' . sanitize_html_class($id),
    );
    exit();
}

function url(?string $id = null): string
{
    $url = add_query_arg(['page' => PAGE_SLUG], admin_url('admin.php'));

    return $id !== null ? $url . '#' . sanitize_html_class($id) : $url;
}

function render_page(): void
{
    if (!\novamira_current_user_can_manage()) {
        return;
    }
    if (function_exists('wp_get_abilities')) {
        wp_get_abilities();
    }
    $manager = Features\features();
    $groups = ['feature' => [], 'specialization' => []];
    foreach ($manager->definitions() as $id => $definition) {
        if (!$definition->visible || !specialization_is_visible($definition)) {
            continue;
        }
        $kind = $definition->kind;
        $groups[$kind][$id] = $definition;
    }
    uasort($groups['specialization'], static function (Features\Definition $a, Features\Definition $b): int {
        $label_order = strcasecmp($a->label, $b->label);

        return $label_order !== 0 ? $label_order : strcmp($a->id, $b->id);
    });
    $result = is_string($_GET['novamira_result'] ?? null) ? sanitize_key(wp_unslash($_GET['novamira_result'])) : '';
    $notice = match ($result) {
        'updated' => ['success is-dismissible', __('Feature settings updated.', domain: 'novamira')],
        'invalid' => ['error', __('Unknown feature.', domain: 'novamira')],
        'blocked' => [
            'error',
            __('This feature cannot be enabled until its requirements are available.', domain: 'novamira'),
        ],
        default => null,
    };

    \novamira_render_admin_header();
    ?>
    <div class="wrap novamira-features novamira-list-layout">
        <h1><?php esc_html_e('Features', domain: 'novamira'); ?></h1>
        <p class="description"><?php esc_html_e(
            'Enable or disable complete Novamira components. Skills and abilities owned by a feature follow its state automatically; stored content and preferences are preserved.',
            domain: 'novamira',
        ); ?></p>
        <?php if ($notice !== null): ?>
            <div class="notice notice-<?php echo esc_attr($notice[0]); ?>"><p><?php echo
                esc_html($notice[1])
            ; ?></p></div>
        <?php endif; ?>
        <?php foreach ($manager->errors() as $error): ?>
            <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
        <?php endforeach; ?>
        <?php render_group(__('Core', domain: 'novamira'), $groups['feature'], $manager); ?>
        <?php if ($groups['specialization'] !== []): ?>
            <?php render_group(
                __('Novamira Pro Specializations', domain: 'novamira'),
                $groups['specialization'],
                $manager,
            ); ?>
        <?php endif; ?>
    </div>
    <?php
}

function specialization_is_visible(Features\Definition $feature): bool
{
    if ($feature->kind !== 'specialization' || $feature->abilityCategories === []) {
        return true;
    }
    foreach ($feature->abilityCategories as $category) {
        if (function_exists('wp_has_ability_category') && wp_has_ability_category($category)) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<string, Features\Definition> $featureRows
 */
function render_group(string $heading, array $featureRows, Features\Manager $manager): void
{
    if ($featureRows === []) {
        return;
    }
    ?>
    <section class="novamira-admin-list-section">
        <div class="novamira-admin-list-header">
            <h2><?php echo esc_html($heading); ?> <span class="count"><?php echo
                (int) count($featureRows)
            ; ?></span></h2>
        </div>
        <div class="novamira-admin-list-list">
            <?php foreach ($featureRows as $id => $feature): ?>
                <?php render_row($id, $feature, $manager); ?>
            <?php endforeach; ?>
        </div>
    </section>
    <?php
}

// The row state and its compact HTML template stay together so labels, confirmation impact, and controls cannot drift.
function render_row(string $id, Features\Definition $feature, Features\Manager $manager): void
{
    $active = $manager->is_active($id);
    $inactive_dependencies = $manager->inactive_dependencies($id);
    $impact = $active ? $manager->preview_deactivation($id) : $manager->preview_activation($id);
    $off_reason = $active ? null : specialization_off_reason($feature, $manager, $inactive_dependencies);
    $disabled_label = $active ? null : __('— Disabled', domain: 'novamira');
    $dependency_warning = feature_dependency_warning($feature, $manager, $inactive_dependencies, $off_reason);
    $changed_features = array_values(array_filter(
        $impact->features,
        static fn(string $featureId): bool => $manager->is_active($featureId) !== $impact->active,
    ));
    $affected_features = count(array_filter(
        $changed_features,
        static fn(string $featureId): bool => $featureId !== $id,
    ));
    $confirm = feature_confirmation($active ? 'disable' : 'enable', $feature->label, $affected_features);
    ?>
    <article id="<?php echo
        esc_attr(sanitize_html_class($id))
    ; ?>" class="novamira-admin-list-row novamira-list-row<?php echo $active ? ' is-on' : ' is-off'; ?>">
        <?php render_feature_main($feature, $disabled_label, $dependency_warning); ?>
        <div class="novamira-admin-list-actions novamira-list-actions novamira-list-progressive-actions">
            <form
                method="post"
                <?php if ($impact->applied): ?>onsubmit="return confirm('<?php echo
                    esc_js($confirm)
                ; ?>');"<?php endif; ?>
            >
                <?php wp_nonce_field('novamira_set_feature'); ?>
                <input type="hidden" name="novamira_feature_action" value="set" />
                <input type="hidden" name="feature_id" value="<?php echo esc_attr($id); ?>" />
                <input type="hidden" name="active" value="<?php echo $active ? '0' : '1'; ?>" />
                <button type="submit" class="action-btn" <?php disabled(!$impact->applied); ?>><?php echo
                    $active ? esc_html__('Disable', domain: 'novamira') : esc_html__('Enable', domain: 'novamira')
                ; ?></button>
            </form>
        </div>
    </article>
    <?php
}

/**
 * @param array{label: string, details: string}|null $dependencyWarning
 */
function render_feature_main(Features\Definition $feature, ?string $disabledLabel, ?array $dependencyWarning): void
{ ?>
    <details class="novamira-feature-main">
        <summary>
            <span class="novamira-feature-summary-copy">
                <span class="slug"><?php echo esc_html($feature->label); ?></span>
                <?php render_feature_maturity($feature); ?>
                <?php render_feature_state($disabledLabel, $dependencyWarning); ?>
            </span>
            <span class="novamira-feature-details-action novamira-list-progressive-actions">
                <?php esc_html_e('Details', domain: 'novamira'); ?>
            </span>
        </summary>
        <div class="novamira-feature-details">
            <p><?php echo esc_html($feature->description); ?></p>
            <?php render_feature_warning($dependencyWarning); ?>
        </div>
    </details>
    <?php }

/** @param array{label: string, details: string}|null $dependencyWarning */
function render_feature_state(?string $disabledLabel, ?array $dependencyWarning): void
{
    if ($disabledLabel !== null) { ?>
        <span class="novamira-list-inline-state"><?php echo esc_html($disabledLabel); ?></span>
        <?php }
    if ($dependencyWarning !== null) { ?>
        <span class="novamira-list-inline-state is-warning">— <?php echo
            esc_html($dependencyWarning['label'])
        ; ?></span>
        <?php }
}

function render_feature_maturity(Features\Definition $feature): void
{
    if ($feature->experimental) { ?>
        <span class="novamira-list-inline-state is-experimental">(<?php esc_html_e(
            'Experimental',
            domain: 'novamira',
        ); ?>)</span>
        <?php }
}

/** @param array{label: string, details: string}|null $dependencyWarning */
function render_feature_warning(?array $dependencyWarning): void
{
    if ($dependencyWarning === null) {
        return;
    }
    ?>
    <p class="novamira-feature-warning"><?php echo esc_html($dependencyWarning['details']); ?></p>
    <?php
}

/**
 * @param list<string> $inactiveDependencies
 * @param array{label: string, title: string}|null $offReason
 * @return array{label: string, details: string}|null
 */
function feature_dependency_warning(
    Features\Definition $feature,
    Features\Manager $manager,
    array $inactiveDependencies,
    ?array $offReason,
): ?array {
    if ($inactiveDependencies === []) {
        return null;
    }
    if ($feature->kind === 'specialization') {
        return $offReason === null ? null : ['label' => $offReason['label'], 'details' => $offReason['title']];
    }
    $labels = array_map(
        static fn(string $dependency): string => $manager->definition($dependency)->label ?? $dependency,
        $inactiveDependencies,
    );

    return [
        'label' => __('Inactive dependency', domain: 'novamira'),
        'details' => sprintf(
            /* translators: %s: comma-separated feature labels */
            __('Requires: %s.', domain: 'novamira'),
            implode(', ', $labels),
        ),
    ];
}

/** @param 'enable'|'disable' $action */
function feature_confirmation(string $action, string $label, int $affectedFeatures): string
{
    if ($action === 'disable' && $affectedFeatures === 0) {
        return sprintf(
            /* translators: %s: feature label */
            __('Disable %s? Settings and stored data will be preserved.', domain: 'novamira'),
            $label,
        );
    }
    if ($action === 'enable' && $affectedFeatures === 0) {
        return sprintf(/* translators: %s: feature label */ __('Enable %s?', domain: 'novamira'), $label);
    }

    $relatedFeatures = $action === 'disable'
        ? sprintf(
            _n(
                single: '%d dependent feature',
                plural: '%d dependent features',
                number: $affectedFeatures,
                domain: 'novamira',
            ),
            $affectedFeatures,
        )
        : sprintf(
            _n(
                single: '%d required feature',
                plural: '%d required features',
                number: $affectedFeatures,
                domain: 'novamira',
            ),
            $affectedFeatures,
        );

    return $action === 'disable'
        ? sprintf(
            /* translators: 1: feature label, 2: dependent feature count label */
            __(
                'Disable %1$s? This will also deactivate %2$s. Settings and stored data will be preserved.',
                domain: 'novamira',
            ),
            $label,
            $relatedFeatures,
        )
        : sprintf(
            /* translators: 1: feature label, 2: required feature count label */
            __('Enable %1$s? This will also activate %2$s.', domain: 'novamira'),
            $label,
            $relatedFeatures,
        );
}

/**
 * @param list<string> $inactiveDependencies
 * @return array{label: string, title: string}|null
 */
function specialization_off_reason(
    Features\Definition $feature,
    Features\Manager $manager,
    array $inactiveDependencies,
): ?array {
    if ($feature->kind !== 'specialization') {
        return null;
    }
    if ($inactiveDependencies === []) {
        return [
            'label' => __('Turned off', domain: 'novamira'),
            'title' => __('This specialization was turned off in Novamira.', domain: 'novamira'),
        ];
    }

    $labels = array_map(
        static fn(string $dependency): string => $manager->definition($dependency)->label ?? $dependency,
        $inactiveDependencies,
    );
    $label = count($labels) === 1
        ? sprintf(
            /* translators: %s: specialization label */
            __('Requires %s specialization', domain: 'novamira'),
            $labels[0],
        )
        : sprintf(
            /* translators: %s: comma-separated specialization labels */
            __('Requires %s specializations', domain: 'novamira'),
            implode(', ', $labels),
        );
    $unavailable = array_values(array_filter(
        $inactiveDependencies,
        static fn(string $dependency): bool => !$manager->meets_requirements($dependency),
    ));
    $title = $unavailable !== []
        ? sprintf(
            /* translators: %s: comma-separated specialization labels */
            __('Required specializations are unavailable: %s.', domain: 'novamira'),
            implode(', ', array_map(
                static fn(string $dependency): string => $manager->definition($dependency)->label ?? $dependency,
                $unavailable,
            )),
        )
        : sprintf(
            /* translators: %s: comma-separated specialization labels */
            __(
                'Required specializations are turned off: %s. Enabling this specialization will enable them too.',
                domain: 'novamira',
            ),
            implode(', ', $labels),
        );

    return ['label' => $label, 'title' => $title];
}
