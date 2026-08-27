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

    wp_enqueue_style(
        'novamira-admin-list',
        (string) NOVAMIRA_PLUGIN_URL . 'includes/assets/admin-list.css',
        [],
        NOVAMIRA_VERSION,
    );
    wp_enqueue_style(
        'novamira-features-admin',
        (string) NOVAMIRA_PLUGIN_URL . 'includes/features/assets/admin.css',
        ['novamira-admin-list'],
        NOVAMIRA_VERSION,
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
    <div class="wrap novamira-features">
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
    $skills = $feature->skills;
    $abilities = feature_ability_names($id, $manager);
    $changed_features = array_values(array_filter(
        $impact->features,
        static fn(string $featureId): bool => $manager->is_active($featureId) !== $impact->active,
    ));
    $affected_abilities = feature_ability_names_for($changed_features, $manager);
    $affected_features = count(array_filter(
        $changed_features,
        static fn(string $featureId): bool => $featureId !== $id,
    ));
    $confirm = feature_confirmation(
        $active ? 'disable' : 'enable',
        $feature->label,
        $affected_features,
        count($impact->skills),
        count($affected_abilities),
    );
    ?>
    <article id="<?php echo esc_attr(sanitize_html_class($id)); ?>" class="novamira-admin-list-row<?php echo
        $active ? ' is-on' : ''
    ; ?>">
        <form
            method="post"
            class="novamira-admin-list-toggle"
            title="<?php echo
                $active ? esc_attr__('Disable', domain: 'novamira') : esc_attr__('Enable', domain: 'novamira')
            ; ?>"
            <?php if ($impact->applied): ?>onsubmit="return confirm('<?php echo esc_js($confirm); ?>');"<?php endif; ?>
        >
            <?php wp_nonce_field('novamira_set_feature'); ?>
            <input type="hidden" name="novamira_feature_action" value="set" />
            <input type="hidden" name="feature_id" value="<?php echo esc_attr($id); ?>" />
            <input type="hidden" name="active" value="<?php echo $active ? '0' : '1'; ?>" />
            <button type="submit" class="novamira-admin-list-check" <?php disabled(!$impact->applied); ?> aria-label="<?php echo
                $active
                    ? esc_attr__('Click to disable', domain: 'novamira')
                    : esc_attr__('Click to enable', domain: 'novamira')
            ; ?>"></button>
        </form>
        <div class="novamira-admin-list-main">
            <span class="slug"><?php echo esc_html($feature->label); ?></span>
            <span class="desc"><?php echo esc_html($feature->description); ?></span>
        </div>
        <div class="novamira-admin-list-pills">
            <?php if ($skills !== []): ?>
                <span class="pill"><?php printf(
                    esc_html(_n(single: '%d skill', plural: '%d skills', number: count($skills), domain: 'novamira')),
                    count($skills),
                ); ?></span>
            <?php endif; ?>
            <?php if ($abilities !== []): ?>
                <span class="pill"><?php printf(
                    esc_html(_n(
                        single: '%d ability',
                        plural: '%d abilities',
                        number: count($abilities),
                        domain: 'novamira',
                    )),
                    count($abilities),
                ); ?></span>
            <?php endif; ?>
            <?php if ($inactive_dependencies !== [] && $feature->kind !== 'specialization'): ?>
                <span class="pill has-blocker" title="<?php echo
                    esc_attr(implode(', ', array_map(
                        static fn(string $dependency): string => (
                            $manager->definition($dependency)->label ?? $dependency
                        ),
                        $inactive_dependencies,
                    )))
                ; ?>"><?php esc_html_e('Inactive dependency', domain: 'novamira'); ?></span>
            <?php endif; ?>
            <?php if ($off_reason !== null): ?>
                <span class="pill has-blocker" title="<?php echo esc_attr($off_reason['title']); ?>"><?php echo
                    esc_html($off_reason['label'])
                ; ?></span>
            <?php endif; ?>
        </div>
    </article>
    <?php
}

/** @param 'enable'|'disable' $action */
function feature_confirmation(
    string $action,
    string $label,
    int $affectedFeatures,
    int $affectedSkills,
    int $affectedAbilities,
): string {
    $skills = sprintf(
        _n(single: '%d skill', plural: '%d skills', number: $affectedSkills, domain: 'novamira'),
        $affectedSkills,
    );
    $abilities = sprintf(
        _n(single: '%d ability', plural: '%d abilities', number: $affectedAbilities, domain: 'novamira'),
        $affectedAbilities,
    );
    if ($action === 'disable' && $affectedFeatures === 0) {
        return sprintf(
            /* translators: 1: feature label, 2: skill count label, 3: ability count label */
            __(
                'Disable %1$s? This will remove %2$s and %3$s from AI agents. Settings and stored data will be preserved.',
                domain: 'novamira',
            ),
            $label,
            $skills,
            $abilities,
        );
    }
    if ($action === 'enable' && $affectedFeatures === 0) {
        return sprintf(
            /* translators: 1: feature label, 2: skill count label, 3: ability count label */
            __(
                'Enable %1$s? This will expose %2$s and %3$s to AI agents when AI Abilities are on.',
                domain: 'novamira',
            ),
            $label,
            $skills,
            $abilities,
        );
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
            /* translators: 1: feature label, 2: dependent feature count label, 3: skill count label, 4: ability count label */
            __(
                'Disable %1$s? This will also deactivate %2$s and remove %3$s and %4$s from AI agents. Settings and stored data will be preserved.',
                domain: 'novamira',
            ),
            $label,
            $relatedFeatures,
            $skills,
            $abilities,
        )
        : sprintf(
            /* translators: 1: feature label, 2: required feature count label, 3: skill count label, 4: ability count label */
            __(
                'Enable %1$s? This will also activate %2$s and expose %3$s and %4$s to AI agents when AI Abilities are on.',
                domain: 'novamira',
            ),
            $label,
            $relatedFeatures,
            $skills,
            $abilities,
        );
}

/** @return list<string> */
function feature_ability_names(string $featureId, Features\Manager $manager): array
{
    return feature_ability_catalog($manager)[$featureId] ?? [];
}

/**
 * @param list<string> $featureIds
 * @return list<string>
 */
function feature_ability_names_for(array $featureIds, Features\Manager $manager): array
{
    $catalog = feature_ability_catalog($manager);
    $result = [];
    foreach ($featureIds as $featureId) {
        foreach ($catalog[$featureId] ?? [] as $ability) {
            $result[$ability] = true;
        }
    }

    return array_keys($result);
}

/**
 * Build the page's ability inventory once. Explicit ability ownership remains
 * available even when a feature does not boot; category ownership adds dynamic
 * Pro and third-party abilities without duplicating their names in a manifest.
 *
 * @return array<string, list<string>>
 */
function feature_ability_catalog(Features\Manager $manager): array
{
    /** @var array<int, array<string, list<string>>> $cache */
    static $cache = [];
    $managerId = spl_object_id($manager);
    if (array_key_exists($managerId, $cache)) {
        return $cache[$managerId];
    }

    $indexed = [];
    foreach ($manager->definitions() as $featureId => $feature) {
        $indexed[$featureId] = array_fill_keys(keys: $feature->abilities, value: true);
    }
    if (function_exists('wp_get_abilities')) {
        foreach (wp_get_abilities() as $ability) {
            $feature = $manager->feature_for_ability($ability->get_name(), $ability->get_category());
            if ($feature === null) {
                continue;
            }
            $indexed[$feature->id][$ability->get_name()] = true;
        }
    }

    $catalog = [];
    foreach ($indexed as $featureId => $abilities) {
        $catalog[$featureId] = array_keys($abilities);
    }
    $cache[$managerId] = $catalog;

    return $catalog;
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
