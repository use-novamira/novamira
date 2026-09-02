<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Collect every registered ability, grouped by ability prefix.
 *
 * Disabled abilities are usually absent from the registry after the policy hook,
 * so persisted disabled rules are merged back in as placeholder rows.
 *
 * @return array<string, array<int, array{name: string, label: string, description: string, category: string, mcp: string, mcp_type: string, status: string, disabled: bool, individually_manageable: bool, managed_by_feature: bool, infrastructure: bool, manager_kind: string, manager_label: string, manage_url: string}>>
 */
function novamira_collect_ability_hub_rows(): array
{
    if (!function_exists('wp_get_abilities')) {
        return [];
    }

    $rules = novamira_get_ability_rules();
    $groups = [];
    $seen = [];

    foreach (wp_get_abilities() as $ability) {
        $row = novamira_build_registered_ability_row($ability, $rules);
        if ($row === null) {
            continue;
        }
        $seen[$row['name']] = true;
        $groups[novamira_ability_prefix($row['name'])][] = $row;
    }

    $groups = novamira_append_feature_ability_rows($groups, $seen);
    $groups = novamira_append_disabled_ability_rows($groups, $rules, $seen);
    $groups = novamira_append_unavailable_wp_cli_rows($groups);

    foreach ($groups as $source => $rows) {
        usort($rows, static fn(array $a, array $b): int => [$a['name']] <=> [$b['name']]);
        $groups[$source] = $rows;
    }
    uksort($groups, static function (string $a, string $b): int {
        $rank = novamira_ability_hub_group_rank($a) <=> novamira_ability_hub_group_rank($b);
        return $rank !== 0 ? $rank : strcasecmp($a, $b);
    });

    return $groups;
}

/**
 * Build a hub row for a registered ability, or null when it is hidden or not exposed.
 *
 * @param array<string, array{disabled: bool}> $rules
 * @return array{name: string, label: string, description: string, category: string, mcp: string, mcp_type: string, status: string, disabled: bool, individually_manageable: bool, managed_by_feature: bool, infrastructure: bool, manager_kind: string, manager_label: string, manage_url: string}|null
 */
// The row combines registry metadata, feature ownership, direct rules, category lookup, and display state.
function novamira_build_registered_ability_row(WP_Ability $ability, array $rules): ?array
{
    $name = $ability->get_name();
    if (novamira_ability_is_hub_hidden($name)) {
        return null;
    }
    $meta = $ability->get_meta();
    if (!novamira_ability_is_exposed($meta)) {
        return null;
    }

    $features = \Novamira\Features\features();
    $feature_definition = $features->feature_for_ability($name, $ability->get_category());
    $feature_id = $feature_definition->id ?? null;
    $manager_kind = $feature_definition->kind ?? '';
    $manager_label = $feature_definition?->label() ?? '';
    $individually_manageable = $feature_definition === null;
    $managed_by_feature = $feature_definition !== null && $feature_definition->toggleable;
    $infrastructure = $feature_definition !== null && !$feature_definition->toggleable;
    $feature_inactive = $feature_id !== null && !$features->is_active($feature_id);
    $disabled = $feature_inactive || $individually_manageable && ($rules[$name]['disabled'] ?? false);
    $category_slug = $ability->get_category();
    $category = $category_slug !== '' ? wp_get_ability_category($category_slug) : null;

    return [
        'name' => $name,
        'label' => $ability->get_label(),
        'description' => $ability->get_description(),
        'category' => $category !== null ? $category->get_label() : $category_slug,
        'mcp' => novamira_format_ability_mcp_meta($meta),
        'mcp_type' => novamira_ability_mcp_type($meta),
        'status' => $disabled ? __('Disabled', domain: 'novamira') : __('Enabled', domain: 'novamira'),
        'disabled' => $disabled,
        'individually_manageable' => $individually_manageable,
        'managed_by_feature' => $managed_by_feature,
        'infrastructure' => $infrastructure,
        'manager_kind' => $manager_kind,
        'manager_label' => $manager_label,
        'manage_url' => $managed_by_feature ? \Novamira\Features\Admin\url((string) $feature_id) : '',
    ];
}

/**
 * Keep explicitly owned abilities visible when their feature is off and its
 * registration module therefore did not boot. Category-owned abilities are
 * already present because this screen keeps the complete registry intact.
 *
 * @param array<string, list<array{name: string, label: string, description: string, category: string, mcp: string, mcp_type: string, status: string, disabled: bool, individually_manageable: bool, managed_by_feature: bool, infrastructure: bool, manager_kind: string, manager_label: string, manage_url: string}>> $groups
 * @param array<string, bool> $seen
 * @return array<string, list<array{name: string, label: string, description: string, category: string, mcp: string, mcp_type: string, status: string, disabled: bool, individually_manageable: bool, managed_by_feature: bool, infrastructure: bool, manager_kind: string, manager_label: string, manage_url: string}>>
 */
function novamira_append_feature_ability_rows(array $groups, array $seen): array
{
    $manager = \Novamira\Features\features();
    foreach ($manager->definitions() as $feature) {
        foreach ($feature->abilities as $name) {
            if (array_key_exists($name, $seen) || novamira_ability_is_hub_hidden($name)) {
                continue;
            }
            $active = $manager->is_active($feature->id);
            $managed = $feature->toggleable;
            $groups[novamira_ability_prefix($name)][] = [
                'name' => $name,
                'label' => novamira_ability_placeholder_label($name),
                'description' => '',
                'category' => '',
                'mcp' => __('Unknown', domain: 'novamira'),
                'mcp_type' => '',
                'status' => $active ? __('Enabled', domain: 'novamira') : __('Disabled', domain: 'novamira'),
                'disabled' => !$active,
                'individually_manageable' => false,
                'managed_by_feature' => $managed,
                'infrastructure' => !$managed,
                'manager_kind' => $feature->kind,
                'manager_label' => $feature->label(),
                'manage_url' => $managed ? \Novamira\Features\Admin\url($feature->id) : '',
            ];
        }
    }

    return $groups;
}

function novamira_ability_placeholder_label(string $name): string
{
    $parts = explode('/', $name, limit: 2);
    $slug = $parts[1] ?? $name;

    return ucwords(str_replace(search: '-', replace: ' ', subject: $slug));
}

/**
 * Merge persisted disabled rules back in as placeholder rows for abilities that
 * are no longer registered (disabled abilities are absent after the policy hook).
 *
 * @param array<string, list<array{name: string, label: string, description: string, category: string, mcp: string, mcp_type: string, status: string, disabled: bool, individually_manageable: bool, managed_by_feature: bool, infrastructure: bool, manager_kind: string, manager_label: string, manage_url: string}>> $groups
 * @param array<string, array{disabled: bool}> $rules
 * @param array<string, bool> $seen
 * @return array<string, list<array{name: string, label: string, description: string, category: string, mcp: string, mcp_type: string, status: string, disabled: bool, individually_manageable: bool, managed_by_feature: bool, infrastructure: bool, manager_kind: string, manager_label: string, manage_url: string}>>
 */
function novamira_append_disabled_ability_rows(array $groups, array $rules, array $seen): array
{
    foreach ($rules as $name => $rule) {
        if (
            \Novamira\Features\features()->feature_for_ability($name) !== null
            || novamira_ability_is_hub_hidden($name)
            || array_key_exists($name, $seen)
            || !$rule['disabled']
        ) {
            continue;
        }
        $groups[novamira_ability_prefix($name)][] = [
            'name' => $name,
            'label' => __('Previously registered ability', domain: 'novamira'),
            'description' => '',
            'category' => '',
            'mcp' => __('Unknown', domain: 'novamira'),
            'mcp_type' => '',
            'status' => __('Disabled', domain: 'novamira'),
            'disabled' => true,
            'individually_manageable' => true,
            'managed_by_feature' => false,
            'infrastructure' => false,
            'manager_kind' => '',
            'manager_label' => '',
            'manage_url' => '',
        ];
    }

    return $groups;
}

/**
 * Keep WP-CLI visible to administrators when this server cannot expose its abilities to agents.
 *
 * @param array<string, list<array{name: string, label: string, description: string, category: string, mcp: string, mcp_type: string, status: string, disabled: bool, individually_manageable: bool, managed_by_feature: bool, infrastructure: bool, manager_kind: string, manager_label: string, manage_url: string}>> $groups
 * @return array<string, list<array{name: string, label: string, description: string, category: string, mcp: string, mcp_type: string, status: string, disabled: bool, individually_manageable: bool, managed_by_feature: bool, infrastructure: bool, manager_kind: string, manager_label: string, manage_url: string}>>
 */
function novamira_append_unavailable_wp_cli_rows(array $groups): array
{
    if (!function_exists('novamira_wp_cli_status')) {
        return $groups;
    }
    $status = novamira_wp_cli_status();
    if ($status['available']) {
        return $groups;
    }

    $labels = [
        'novamira/run-wp-cli' => __('Run WP-CLI Command', domain: 'novamira'),
        'novamira/get-wp-cli-job' => __('Get WP-CLI Job Status', domain: 'novamira'),
    ];
    $source = 'novamira';
    $rows = array_values(array_filter(
        $groups[$source] ?? [],
        static fn(array $row): bool => !array_key_exists($row['name'], $labels),
    ));
    foreach ($labels as $name => $label) {
        $rows[] = [
            'name' => $name,
            'label' => $label,
            'description' => $status['reason'],
            'category' => __('Code Execution', domain: 'novamira'),
            'mcp' => __('Not exposed', domain: 'novamira'),
            'mcp_type' => '',
            'status' => __('Unavailable', domain: 'novamira'),
            'disabled' => true,
            'individually_manageable' => false,
            'managed_by_feature' => false,
            'infrastructure' => false,
            'manager_kind' => '',
            'manager_label' => '',
            'manage_url' => '',
        ];
    }
    $groups[$source] = $rows;

    return $groups;
}

function novamira_ability_prefix(string $ability_name): string
{
    $parts = explode('/', $ability_name, limit: 2);
    return $parts[0] !== '' ? $parts[0] : __('Other', domain: 'novamira');
}

/**
 * The ability name without its provider prefix. The provider is already the
 * group header, so repeating it on every row is noise.
 */
function novamira_ability_display_slug(string $ability_name): string
{
    $parts = explode('/', $ability_name, limit: 2);
    return ($parts[1] ?? '') !== '' ? $parts[1] : $ability_name;
}

/**
 * Sort rank for an ability group header: the "novamira" provider first, then
 * every other provider (the caller breaks ties alphabetically).
 */
function novamira_ability_hub_group_rank(string $source): int
{
    return $source === 'novamira' ? 0 : 1;
}

function novamira_ability_is_hub_hidden(string $ability_name): bool
{
    return str_starts_with($ability_name, 'novamira-mcp-adapter/');
}

/**
 * An ability is exposed when its MCP metadata marks it public.
 *
 * @param array<string, mixed> $meta
 */
function novamira_ability_is_exposed(array $meta): bool
{
    /** @var mixed $mcp */
    $mcp = $meta['mcp'] ?? null;
    return is_array($mcp) && ($mcp['public'] ?? false) === true;
}

/**
 * @param array<string, mixed> $meta
 */
function novamira_format_ability_mcp_meta(array $meta): string
{
    /** @var mixed $mcp */
    $mcp = $meta['mcp'] ?? null;
    if (!is_array($mcp)) {
        return __('Unknown', domain: 'novamira');
    }

    return (string) ($mcp['type'] ?? 'tool');
}

/**
 * Raw MCP exposure type ('tool', 'resource' or 'prompt') for pill logic, kept
 * separate from the translated display label.
 *
 * @param array<string, mixed> $meta
 */
function novamira_ability_mcp_type(array $meta): string
{
    /** @var mixed $mcp */
    $mcp = $meta['mcp'] ?? null;
    if (!is_array($mcp)) {
        return 'tool';
    }
    /** @var mixed $type */
    $type = $mcp['type'] ?? '';
    return $type === 'resource' || $type === 'prompt' ? $type : 'tool';
}

function novamira_handle_ability_hub_actions(): void
{
    if (($_POST['novamira_ability_hub_action'] ?? null) === null) {
        return;
    }

    if (!novamira_current_user_can_manage()) {
        return;
    }

    check_admin_referer('novamira_ability_hub_action');

    $action = is_string($_POST['novamira_ability_hub_action'] ?? null)
        ? sanitize_key(wp_unslash($_POST['novamira_ability_hub_action']))
        : '';

    if ($action === 'set_group') {
        novamira_handle_ability_group_action();
        return;
    }

    $ability_name = is_string($_POST['ability_name'] ?? null)
        ? novamira_sanitize_requested_ability_name($_POST['ability_name'])
        : '';

    if (!novamira_is_valid_ability_name($ability_name)) {
        wp_safe_redirect(admin_url('admin.php?page=novamira-abilities&novamira_result=invalid'));
        exit();
    }

    if (!novamira_ability_can_be_managed_individually($ability_name)) {
        wp_safe_redirect(admin_url('admin.php?page=novamira-abilities&novamira_result=managed'));
        exit();
    }

    $rules = novamira_get_ability_rules();
    $rules[$ability_name] ??= ['disabled' => false];

    $rules = novamira_apply_ability_hub_action_to_rules($rules, $ability_name, $action);

    novamira_update_ability_rules($rules);
    wp_safe_redirect(admin_url('admin.php?page=novamira-abilities&novamira_result=updated'));
    exit();
}

function novamira_handle_ability_group_action(): void
{
    $group_action = is_string($_POST['group_action'] ?? null) ? sanitize_key(wp_unslash($_POST['group_action'])) : '';
    if (!in_array($group_action, ['enable', 'disable'], strict: true)) {
        wp_safe_redirect(admin_url('admin.php?page=novamira-abilities&novamira_result=invalid'));
        exit();
    }
    $ability_names = novamira_requested_ability_names();
    if ($ability_names === []) {
        wp_safe_redirect(admin_url('admin.php?page=novamira-abilities&novamira_result=managed'));
        exit();
    }

    $rules = novamira_get_ability_rules();
    $has_manageable_ability = false;
    foreach ($ability_names as $ability_name) {
        if (!novamira_ability_can_be_managed_individually($ability_name)) {
            continue;
        }
        $has_manageable_ability = true;
        $rules[$ability_name] ??= ['disabled' => false];
        $rules[$ability_name]['disabled'] = $group_action === 'disable';
    }
    if (!$has_manageable_ability) {
        wp_safe_redirect(admin_url('admin.php?page=novamira-abilities&novamira_result=managed'));
        exit();
    }

    novamira_update_ability_rules($rules);
    wp_safe_redirect(admin_url('admin.php?page=novamira-abilities&novamira_result=group_updated'));
    exit();
}

/** @return list<string> */
function novamira_requested_ability_names(): array
{
    $raw_names = is_array($_POST['ability_names'] ?? null) ? $_POST['ability_names'] : [];
    $ability_names = [];
    foreach ($raw_names as $raw_name) {
        if (!is_string($raw_name)) {
            continue;
        }
        $ability_name = novamira_sanitize_requested_ability_name($raw_name);
        if (!novamira_is_valid_ability_name($ability_name) || novamira_ability_is_hub_hidden($ability_name)) {
            continue;
        }
        $ability_names[$ability_name] = true;
    }

    return array_keys($ability_names);
}

/**
 * AJAX endpoint for the single-row enable/disable toggle. Mirrors the POST path
 * but responds with JSON so the page does not reload (preserving open sections).
 * The browser falls back to the plain form submit if this request fails.
 */
function novamira_handle_ability_toggle_ajax(): void
{
    if (!novamira_current_user_can_manage()) {
        wp_send_json_error(['message' => __('Permission denied.', domain: 'novamira')], status_code: 403);
    }

    if (!check_ajax_referer('novamira_ability_hub_action', query_arg: false, stop: false)) {
        wp_send_json_error(['message' => __(
            'Your session expired. Reload the page.',
            domain: 'novamira',
        )], status_code: 403);
    }

    $ability_name = is_string($_POST['ability_name'] ?? null)
        ? novamira_sanitize_requested_ability_name($_POST['ability_name'])
        : '';

    if (!novamira_is_valid_ability_name($ability_name) || novamira_ability_is_hub_hidden($ability_name)) {
        wp_send_json_error(['message' => __('Invalid ability name.', domain: 'novamira')], status_code: 400);
    }

    if (!novamira_ability_can_be_managed_individually($ability_name)) {
        wp_send_json_error(['message' => __('This ability cannot be changed.', domain: 'novamira')], status_code: 403);
    }

    $rules = novamira_get_ability_rules();
    $rules[$ability_name] ??= ['disabled' => false];
    $rules = novamira_toggle_ability_disabled_rule($rules, $ability_name);
    novamira_update_ability_rules($rules);

    $disabled = $rules[$ability_name]['disabled'] === true;
    wp_send_json_success([
        'disabled' => $disabled,
        'status' => $disabled ? __('Disabled', domain: 'novamira') : __('Enabled', domain: 'novamira'),
        'button' => $disabled ? __('Enable', domain: 'novamira') : __('Disable', domain: 'novamira'),
    ]);
}

/**
 * @param array<string, array{disabled: bool}> $rules
 * @return array<string, array{disabled: bool}>
 */
function novamira_apply_ability_hub_action_to_rules(array $rules, string $ability_name, string $action): array
{
    if ($action === 'toggle_disabled') {
        return novamira_toggle_ability_disabled_rule($rules, $ability_name);
    }

    return $rules;
}

/**
 * @param array<string, array{disabled: bool}> $rules
 * @return array<string, array{disabled: bool}>
 */
function novamira_toggle_ability_disabled_rule(array $rules, string $ability_name): array
{
    if (!novamira_ability_can_be_managed_individually($ability_name)) {
        return $rules;
    }

    $rules[$ability_name]['disabled'] = !$rules[$ability_name]['disabled'];
    return $rules;
}

function novamira_delete_sandbox_file(string $path): bool
{
    if (!unlink($path)) {
        return false;
    }

    $marker = novamira_sandbox_disabled_marker_path($path);
    if (is_file($marker)) {
        unlink($marker);
    }

    return true;
}

/**
 * @return array<array-key, mixed>|WP_Error|bool
 */
function novamira_run_sandbox_action(string $action, string $file, string $path): array|WP_Error|bool
{
    return match ($action) {
        'delete' => novamira_delete_sandbox_file($path),
        'disable' => str_ends_with($file, '.php') ? novamira_create_sandbox_disabled_marker($path) : false,
        'enable' => str_ends_with($file, '.php') ? novamira_remove_sandbox_disabled_marker($path) : false,
        'exit_safe_mode' => $file === '.crashed' && unlink($path),
        default => false,
    };
}

function novamira_handle_sandbox_actions()
{
    if (!novamira_current_user_can_manage()) {
        return;
    }

    $action = $_GET['action'] ?? null;
    $file_param = $_GET['file'] ?? null;

    if (!is_string($action) || !is_string($file_param)) {
        return;
    }

    $file = basename($file_param);
    if (!check_admin_referer('novamira_manage_file_' . $file)) {
        return;
    }

    $path = novamira_get_sandbox_dir(true) . $file;
    if (!file_exists($path)) {
        return;
    }

    $result = novamira_run_sandbox_action($action, $file, $path);

    $succeeded = $result === true || is_array($result) && ($result['disabled'] ?? $result['enabled'] ?? false) === true;
    if ($succeeded) {
        wp_safe_redirect(admin_url('admin.php?page=novamira-sandbox&novamira_result=' . $action));
        exit();
    }
}

function novamira_render_sandbox_page(): void
{
    if (!novamira_current_user_can_manage()) {
        return;
    }

    $result_message = match ($_GET['novamira_result'] ?? null) {
        'delete' => __('File deleted.', domain: 'novamira'),
        'disable' => __('File disabled.', domain: 'novamira'),
        'enable' => __('File enabled.', domain: 'novamira'),
        'exit_safe_mode' => __(
            'Safe mode deactivated. Sandbox files will load on the next request.',
            domain: 'novamira',
        ),
        default => null,
    };
    $sandbox_dir = novamira_get_sandbox_dir(true);
    $is_crashed = file_exists($sandbox_dir . '.crashed');

    novamira_render_admin_header();
    ?>
    <div class="wrap novamira-list-layout">
        <h1 class="wp-heading-inline"><?php esc_html_e('Sandbox files', domain: 'novamira'); ?></h1>
        <hr class="wp-header-end" />

        <?php if ($result_message !== null): ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html($result_message); ?></p></div>
        <?php endif; ?>

        <?php if ($is_crashed): ?>
            <div class="notice notice-error">
                <p>
                    <strong><?php esc_html_e('Safe mode is active.', domain: 'novamira'); ?></strong>
                    <?php esc_html_e(
                        'A sandbox file caused a fatal error on a previous request. All sandbox files are suspended until you fix or delete the broken file and exit safe mode.',
                        domain: 'novamira',
                    ); ?>
                </p>
                <p>
                    <?php

                    $exit_url = wp_nonce_url(
                        admin_url('admin.php?page=novamira-sandbox&action=exit_safe_mode&file=.crashed'),
                        action: 'novamira_manage_file_.crashed',
                    );
                    ?>
                    <a href="<?php echo esc_url($exit_url); ?>" class="button button-primary"><?php esc_html_e(
                        'Exit Safe Mode',
                        domain: 'novamira',
                    ); ?></a>
                </p>
            </div>
        <?php endif; ?>

        <?php novamira_render_sandbox_list($sandbox_dir); ?>
    </div>
    <?php
}

/**
 * Render the file list as a card section.
 * Layout mirrors the Skills admin page so the two pages feel consistent.
 */
function novamira_render_sandbox_list(string $sandbox_dir): void
{
    $files = novamira_get_sandbox_files($sandbox_dir);
    $sandbox_status = file_exists($sandbox_dir . '.crashed') ? 'suspended' : 'active';
    ?>
    <section class="novamira-sandbox-section">
        <div class="novamira-sandbox-header">
            <h2><?php esc_html_e('Files', domain: 'novamira'); ?>
                <span class="count"><?php echo (int) count($files); ?></span>
            </h2>
        </div>
        <?php if ($files === []): ?>
            <div class="novamira-sandbox-empty"><?php esc_html_e(
                'No sandbox files yet. AI agents will place generated files here.',
                domain: 'novamira',
            ); ?></div>
        <?php endif; ?>
        <?php if ($files !== []): ?>
            <?php novamira_render_sandbox_rows($sandbox_dir, $files, $sandbox_status); ?>
        <?php endif; ?>
    </section>
    <?php
}

/**
 * @return list<string>
 */
function novamira_get_sandbox_files(string $sandbox_dir): array
{
    $scanned_files = is_dir($sandbox_dir) ? scandir($sandbox_dir) : false;
    $files = $scanned_files !== false ? array_diff($scanned_files, ['.', '..']) : [];

    return array_values(array_filter(
        $files,
        static fn(string $file): bool => (
            !is_dir($sandbox_dir . $file) && !novamira_is_sandbox_internal_file_name($file)
        ),
    ));
}

/**
 * @param list<string> $files
 */
function novamira_render_sandbox_rows(string $sandbox_dir, array $files, string $sandbox_status): void
{
    $base_url = admin_url('admin.php?page=novamira-sandbox');
    ?>
    <div class="novamira-sandbox-rows">
        <?php foreach ($files as $file): ?>
            <?php novamira_render_sandbox_row($sandbox_dir, $file, $sandbox_status, $base_url); ?>
        <?php endforeach; ?>
    </div>
    <?php
}

function novamira_render_sandbox_row(string $sandbox_dir, string $file, string $sandbox_status, string $base_url): void
{
    $path = $sandbox_dir . $file;
    $file_status = novamira_get_sandbox_file_status($path, $sandbox_status);
    $display_name = $file;
    $ext = strtolower(pathinfo($display_name, PATHINFO_EXTENSION));
    $row_classes = ['novamira-sandbox-row', 'novamira-list-row', 'is-' . $file_status];
    if ($file_status === 'disabled') {
        $row_classes[] = 'is-off';
    }

    $delete_url = wp_nonce_url(
        $base_url . '&action=delete&file=' . urlencode($file),
        action: 'novamira_manage_file_' . $file,
    );
    ?>
    <div
        class="<?php echo esc_attr(implode(' ', $row_classes)); ?>"
        tabindex="0"
        aria-label="<?php echo
            esc_attr(sprintf(
                /* translators: %s: sandbox file name */
                __('Sandbox file %s', domain: 'novamira'),
                $display_name,
            ))
        ; ?>"
    >
        <div class="novamira-sandbox-main">
            <span class="slug"><?php echo esc_html($display_name); ?></span>
            <?php if ($file_status === 'disabled'): ?>
                <span class="novamira-list-inline-state"><?php esc_html_e('— Disabled', domain: 'novamira'); ?></span>
            <?php endif; ?>
            <?php if ($file_status === 'suspended'): ?>
                <span class="novamira-list-inline-state is-critical"><?php esc_html_e(
                    '— Suspended',
                    domain: 'novamira',
                ); ?></span>
            <?php endif; ?>
        </div>

        <div class="novamira-sandbox-actions novamira-list-actions novamira-list-progressive-actions">
            <?php novamira_render_sandbox_state_action($file, $file_status, $ext, $base_url); ?>
            <a
                href="<?php echo esc_url($delete_url); ?>"
                class="action-btn action-btn--danger"
                onclick="return confirm('<?php echo
                    esc_js(__('Are you sure you want to delete this file?', domain: 'novamira'))
                ; ?>');"
            ><?php esc_html_e('Delete', domain: 'novamira'); ?></a>
        </div>
    </div>
    <?php
}

function novamira_get_sandbox_file_status(string $path, string $sandbox_status): string
{
    if ($sandbox_status === 'suspended') {
        return 'suspended';
    }

    if (novamira_sandbox_file_is_disabled($path)) {
        return 'disabled';
    }

    return 'on';
}

function novamira_render_sandbox_state_action(string $file, string $file_status, string $ext, string $base_url): void
{
    if ($file_status === 'suspended' || $file_status !== 'disabled' && $ext !== 'php') {
        return;
    }

    $is_disabled = $file_status === 'disabled';
    $toggle_action = $is_disabled ? 'enable' : 'disable';
    $toggle_url = wp_nonce_url(
        $base_url . '&action=' . $toggle_action . '&file=' . urlencode($file),
        action: 'novamira_manage_file_' . $file,
    );
    ?>
    <a
        href="<?php echo esc_url($toggle_url); ?>"
        class="action-btn"
        <?php if (!$is_disabled): ?>onclick="return confirm('<?php echo
            esc_js(__(
                'Disable this sandbox file? If the site depends on it, functionality may stop working or the site may encounter a fatal error.',
                domain: 'novamira',
            ))
        ; ?>');"<?php endif; ?>
    ><?php echo
        $is_disabled ? esc_html__('Enable', domain: 'novamira') : esc_html__('Disable', domain: 'novamira')
    ; ?></a>
    <?php
}

function novamira_render_settings_page()
{
    if (!novamira_current_user_can_manage()) {
        return;
    }

    $ability_groups = novamira_collect_ability_hub_rows();
    $result = is_string($_GET['novamira_result'] ?? null) ? sanitize_key(wp_unslash($_GET['novamira_result'])) : null;
    ?>
    <?php novamira_render_admin_header(); ?>
    <div
        class="wrap novamira-hub novamira-list-layout"
        data-alloff-label="<?php esc_attr_e('All disabled', domain: 'novamira'); ?>"
    >
        <div class="wrap-title">
            <div>
                <h1><?php esc_html_e('Abilities Hub', domain: 'novamira'); ?></h1>
                <p class="description"><?php printf(
                    /* translators: %s: link to the Configuration page */
                    esc_html__(
                        'Manage every ability exposed to AI agents. This lists abilities registered by Novamira and any other plugin that uses the WordPress Abilities API, grouped by provider. Disabled abilities are removed from registry discovery and MCP execution while AI Abilities are enabled on the %s page.',
                        domain: 'novamira',
                    ),
                    '<a href="'
                    . esc_url(admin_url('admin.php?page=novamira-connect'))
                    . '">'
                    . esc_html__('Configuration', domain: 'novamira')
                    . '</a>',
                ); ?></p>
            </div>
        </div>
        <?php novamira_render_ability_hub_result_notice($result); ?>
        <?php if ($ability_groups === []): ?>
            <div class="notice notice-info"><p><?php esc_html_e(
                'No abilities are currently registered.',
                domain: 'novamira',
            ); ?></p></div>
        <?php endif; ?>
        <?php $expanded_source = array_key_first($ability_groups); ?>
        <?php $seen_core = false; ?>
        <?php $divider_done = false; ?>
        <?php foreach ($ability_groups as $source => $abilities): ?>
            <?php $is_core = novamira_ability_hub_group_rank($source) === 0; ?>
            <?php if (!$is_core && $seen_core && !$divider_done): ?>
                <?php novamira_render_ability_other_plugins_divider(); ?>
                <?php $divider_done = true; ?>
            <?php endif; ?>
            <?php $seen_core = $seen_core || $is_core; ?>
            <?php novamira_render_ability_group_section($source, $abilities, $expanded_source); ?>
        <?php endforeach; ?>
    </div>
    <?php
}

function novamira_render_ability_hub_result_notice(?string $result): void
{
    $notice = match ($result) {
        'updated' => ['success', __('Ability rule updated.', domain: 'novamira')],
        'group_updated' => ['success', __('Ability rules updated.', domain: 'novamira')],
        'invalid' => ['error', __('Invalid ability name.', domain: 'novamira')],
        'managed' => [
            'info',
            __(
                'This ability is managed by Novamira or by a feature and cannot be changed independently.',
                domain: 'novamira',
            ),
        ],
        default => null,
    };

    if ($notice === null) {
        return;
    }
    ?>
    <div class="<?php echo esc_attr('notice notice-' . $notice[0] . ' is-dismissible'); ?>">
        <p><?php echo esc_html($notice[1]); ?></p>
    </div>
    <?php
}

/**
 * Divider that separates Novamira's own abilities from those registered by
 * other plugins, so a provider like "jet-engine" reads clearly as the plugin's.
 */
function novamira_render_ability_other_plugins_divider(): void
{ ?>
    <h2 class="novamira-hub-divider"><?php esc_html_e('Registered by other plugins', domain: 'novamira'); ?></h2>
    <?php }

/**
 * @param array<int, array{name: string, label: string, description: string, category: string, mcp: string, mcp_type: string, status: string, disabled: bool, individually_manageable: bool, managed_by_feature: bool, infrastructure: bool, manager_kind: string, manager_label: string, manage_url: string}> $abilities
 * @param string|null $expanded_source Group key that should render expanded.
 */
function novamira_render_ability_group_section(string $source, array $abilities, ?string $expanded_source): void
{ ?>
    <details class="novamira-hub-section"<?php echo $source === $expanded_source ? ' open' : ''; ?>>
        <summary class="novamira-hub-header novamira-list-row">
            <h2><?php echo esc_html($source); ?>
                <?php novamira_render_ability_header_status_meta($abilities); ?>
                <?php novamira_render_ability_managed_owner_meta($abilities); ?>
            </h2>
            <?php if ($source !== 'novamira'): ?>
                <?php novamira_render_ability_group_action($source, $abilities, scope: 'provider'); ?>
            <?php endif; ?>
        </summary>
        <?php novamira_render_ability_group_body($abilities); ?>
    </details>
    <?php }

/**
 * Render a section header's count and, when every ability in it is disabled, an
 * "All disabled" pill. The count shows `enabled / total` while some are off and
 * the bare total when all are enabled. hub.js keeps both in sync after an
 * AJAX toggle.
 *
 * @param array<int, array{name: string, label: string, description: string, category: string, mcp: string, mcp_type: string, status: string, disabled: bool, individually_manageable: bool, managed_by_feature: bool, infrastructure: bool, manager_kind: string, manager_label: string, manage_url: string}> $abilities
 */
function novamira_render_ability_header_status_meta(array $abilities): void
{
    $total = count($abilities);
    $enabled = 0;
    foreach ($abilities as $ability) {
        if ($ability['disabled']) {
            continue;
        }
        $enabled++;
    }
    ?>
    <span class="count"><?php echo
        esc_html($enabled === $total ? (string) $total : $enabled . ' / ' . $total)
    ; ?></span>
    <?php if ($enabled === 0 && $total > 0): ?>
        <span class="pill status is-disabled novamira-hub-alloff"><?php

        esc_html_e('All disabled', domain: 'novamira'); ?></span>
    <?php endif; ?>
    <?php
}

/**
 * @param array<int, array{name: string, label: string, description: string, category: string, mcp: string, mcp_type: string, status: string, disabled: bool, individually_manageable: bool, managed_by_feature: bool, infrastructure: bool, manager_kind: string, manager_label: string, manage_url: string}> $abilities
 * @return array{label: string, reason: string, url: string}|null
 */
function novamira_ability_managed_group_context(array $abilities): ?array
{
    if (!novamira_abilities_are_managed_as_group($abilities)) {
        return null;
    }
    $managed_by_pro =
        count(array_filter(
            $abilities,
            static fn(array $ability): bool => $ability['manager_kind'] !== 'specialization',
        )) === 0;

    return [
        'label' => $managed_by_pro ? __('Novamira Pro', domain: 'novamira') : __('Novamira', domain: 'novamira'),
        'reason' => $managed_by_pro
            ? __(
                'These abilities are managed by a Novamira Pro specialization and cannot be selected individually. Manage the specialization in Features.',
                domain: 'novamira',
            )
            : __(
                'These abilities are managed by Novamira and cannot be selected individually. Manage their feature in Features.',
                domain: 'novamira',
            ),
        'url' => novamira_ability_header_manage_url($abilities),
    ];
}

/**
 * @param array<int, array{name: string, label: string, description: string, category: string, mcp: string, mcp_type: string, status: string, disabled: bool, individually_manageable: bool, managed_by_feature: bool, infrastructure: bool, manager_kind: string, manager_label: string, manage_url: string}> $abilities
 */
function novamira_render_ability_managed_owner_meta(array $abilities): void
{
    $management = novamira_ability_managed_group_context($abilities);
    if ($management === null) {
        return;
    }
    ?>
    <span
        class="novamira-hub-managed-owner"
        data-tooltip="<?php echo esc_attr($management['reason']); ?>"
    ><?php

    printf(
        /* translators: %s: Novamira or Novamira Pro */
        esc_html__('Managed by %s', domain: 'novamira'),
        esc_html($management['label']),
    );
    ?></span>
    <?php
}

/**
 * @param array<int, array{name: string, label: string, description: string, category: string, mcp: string, mcp_type: string, status: string, disabled: bool, individually_manageable: bool, managed_by_feature: bool, infrastructure: bool, manager_kind: string, manager_label: string, manage_url: string}> $abilities
 */
function novamira_abilities_are_managed_as_group(array $abilities): bool
{
    if ($abilities === []) {
        return false;
    }
    foreach ($abilities as $ability) {
        if (!$ability['managed_by_feature']) {
            return false;
        }
    }

    return true;
}

/**
 * Use the feature anchor only when every row points to the same package.
 * Mixed managed groups open the Features page without implying one owner.
 *
 * @param array<int, array{name: string, label: string, description: string, category: string, mcp: string, mcp_type: string, status: string, disabled: bool, individually_manageable: bool, managed_by_feature: bool, infrastructure: bool, manager_kind: string, manager_label: string, manage_url: string}> $abilities
 */
function novamira_ability_header_manage_url(array $abilities): string
{
    $urls = [];
    foreach ($abilities as $ability) {
        if ($ability['manage_url'] === '') {
            continue;
        }
        $urls[$ability['manage_url']] = true;
    }
    if (count($urls) === 1) {
        return array_key_first($urls);
    }

    return admin_url('admin.php?page=novamira-features');
}

/**
 * Render a provider group's body: category sub-sections when there is more than
 * one category, otherwise a flat row list.
 *
 * @param array<int, array{name: string, label: string, description: string, category: string, mcp: string, mcp_type: string, status: string, disabled: bool, individually_manageable: bool, managed_by_feature: bool, infrastructure: bool, manager_kind: string, manager_label: string, manage_url: string}> $abilities
 */
function novamira_render_ability_group_body(array $abilities): void
{
    $by_category = novamira_group_abilities_by_category($abilities);
    if (count($by_category) > 1) {
        foreach ($by_category as $category => $rows) {
            novamira_render_ability_category_subsection($category, $rows);
        }
        return;
    }
    ?>
    <div class="novamira-hub-rows">
        <?php novamira_render_ability_rows($abilities); ?>
    </div>
    <?php
}

/**
 * Group hub rows by their category label. Uncategorized rows sort last.
 *
 * @param array<int, array{name: string, label: string, description: string, category: string, mcp: string, mcp_type: string, status: string, disabled: bool, individually_manageable: bool, managed_by_feature: bool, infrastructure: bool, manager_kind: string, manager_label: string, manage_url: string}> $abilities
 * @return array<string, array<int, array{name: string, label: string, description: string, category: string, mcp: string, mcp_type: string, status: string, disabled: bool, individually_manageable: bool, managed_by_feature: bool, infrastructure: bool, manager_kind: string, manager_label: string, manage_url: string}>>
 */
function novamira_group_abilities_by_category(array $abilities): array
{
    $groups = [];
    foreach ($abilities as $ability) {
        $groups[$ability['category']][] = $ability;
    }

    uksort($groups, static function (string $a, string $b): int {
        if ($a === '' || $b === '') {
            return $a === '' ? 1 : -1;
        }
        return strcasecmp($a, $b);
    });

    return $groups;
}

/**
 * @param array<int, array{name: string, label: string, description: string, category: string, mcp: string, mcp_type: string, status: string, disabled: bool, individually_manageable: bool, managed_by_feature: bool, infrastructure: bool, manager_kind: string, manager_label: string, manage_url: string}> $rows
 */
function novamira_render_ability_category_subsection(string $category, array $rows): void
{
    $label = $category !== '' ? $category : __('Other', domain: 'novamira');
    ?>
    <details class="novamira-hub-subsection">
        <summary class="novamira-hub-subheader novamira-list-row">
            <h3><?php echo esc_html($label); ?>
                <?php novamira_render_ability_header_status_meta($rows); ?>
                <?php novamira_render_ability_managed_owner_meta($rows); ?>
            </h3>
            <?php novamira_render_ability_group_action($label, $rows, scope: 'category'); ?>
        </summary>
        <div class="novamira-hub-rows">
            <?php novamira_render_ability_rows($rows); ?>
        </div>
    </details>
    <?php
}

/**
 * @param array<int, array{name: string, label: string, description: string, category: string, mcp: string, mcp_type: string, status: string, disabled: bool, individually_manageable: bool, managed_by_feature: bool, infrastructure: bool, manager_kind: string, manager_label: string, manage_url: string}> $rows
 * @param 'category'|'provider' $scope
 */
function novamira_render_ability_group_action(string $label, array $rows, string $scope): void
{
    $management = novamira_ability_managed_group_context($rows);
    if ($management !== null) {
        ?>
        <div class="novamira-hub-group-actions novamira-list-actions novamira-list-progressive-actions">
            <a class="action-btn" href="<?php echo esc_url($management['url']); ?>"><?php esc_html_e(
                'Manage in Features',
                domain: 'novamira',
            ); ?></a>
        </div>
        <?php

        return;
    }
    $configurable = array_values(array_filter(
        $rows,
        static fn(array $ability): bool => $ability['individually_manageable'],
    ));
    if ($configurable === []) {
        return;
    }
    $all_configurable = count($configurable) === count($rows);
    $all_disabled = count(array_filter(
        $configurable,
        static fn(array $ability): bool => $ability['disabled'],
    )) === count($configurable);
    $group_action = $all_disabled ? 'enable' : 'disable';
    $action_label = novamira_ability_group_action_label($group_action, $all_configurable ? $scope : 'configurable');
    $confirmation = sprintf(
        /* translators: 1: number of abilities, 2: category or provider label */
        _n(
            single: 'Disable %1$d ability in %2$s?',
            plural: 'Disable %1$d abilities in %2$s?',
            number: count($configurable),
            domain: 'novamira',
        ),
        count($configurable),
        $label,
    );
    ?>
    <div class="novamira-hub-group-actions novamira-list-actions novamira-list-progressive-actions">
        <form
            method="post"
            <?php if ($group_action === 'disable'): ?>onsubmit="return confirm('<?php echo
                esc_js($confirmation)
            ; ?>');"<?php endif; ?>
        >
            <?php wp_nonce_field('novamira_ability_hub_action'); ?>
            <input type="hidden" name="novamira_ability_hub_action" value="set_group" />
            <input type="hidden" name="group_action" value="<?php echo esc_attr($group_action); ?>" />
            <?php foreach ($configurable as $ability): ?>
                <input type="hidden" name="ability_names[]" value="<?php echo esc_attr($ability['name']); ?>" />
            <?php endforeach; ?>
            <button type="submit" class="action-btn"><?php echo esc_html($action_label); ?></button>
        </form>
    </div>
    <?php
}

/**
 * @param 'enable'|'disable' $action
 * @param 'category'|'provider'|'configurable' $scope
 */
function novamira_ability_group_action_label(string $action, string $scope): string
{
    if ($action === 'enable') {
        return match ($scope) {
            'category' => __('Enable category', domain: 'novamira'),
            'provider' => __('Enable all abilities', domain: 'novamira'),
            default => __('Enable configurable abilities', domain: 'novamira'),
        };
    }

    return match ($scope) {
        'category' => __('Disable category', domain: 'novamira'),
        'provider' => __('Disable all abilities', domain: 'novamira'),
        default => __('Disable configurable abilities', domain: 'novamira'),
    };
}

/**
 * @param array<int, array{name: string, label: string, description: string, category: string, mcp: string, mcp_type: string, status: string, disabled: bool, individually_manageable: bool, managed_by_feature: bool, infrastructure: bool, manager_kind: string, manager_label: string, manage_url: string}> $abilities
 */
function novamira_render_ability_rows(array $abilities): void
{
    $management_explained_by_header = novamira_abilities_are_managed_as_group($abilities);
    foreach ($abilities as $ability) {
        $management_label = $management_explained_by_header ? null : novamira_ability_row_management_label($ability);
        novamira_render_ability_hub_row($ability, $management_label);
    }
}

/**
 * @param array{name: string, label: string, description: string, category: string, mcp: string, mcp_type: string, status: string, disabled: bool, individually_manageable: bool, managed_by_feature: bool, infrastructure: bool, manager_kind: string, manager_label: string, manage_url: string} $ability
 */
function novamira_ability_row_management_label(array $ability): ?string
{
    if ($ability['individually_manageable']) {
        return null;
    }
    if ($ability['infrastructure']) {
        return __('Required by Novamira', domain: 'novamira');
    }
    if ($ability['manager_kind'] === 'specialization') {
        return __('Managed by Novamira Pro', domain: 'novamira');
    }
    if ($ability['managed_by_feature']) {
        return __('Managed by Novamira', domain: 'novamira');
    }

    return $ability['status'];
}

/**
 * @param array{name: string, label: string, description: string, category: string, mcp: string, mcp_type: string, status: string, disabled: bool, individually_manageable: bool, managed_by_feature: bool, infrastructure: bool, manager_kind: string, manager_label: string, manage_url: string} $ability
 */
function novamira_render_ability_hub_row(array $ability, ?string $managementLabel = null): void
{
    $row_class = 'novamira-hub-row novamira-list-row ' . ($ability['disabled'] ? 'is-off' : 'is-on');
    $row_class .= $ability['individually_manageable'] ? '' : ' is-managed';
    $row_class .= $ability['status'] === __('Unavailable', domain: 'novamira') ? ' is-unavailable' : '';
    ?>
    <div class="<?php echo esc_attr($row_class); ?>">
        <?php novamira_render_ability_hub_main($ability, $managementLabel); ?>

        <?php novamira_render_ability_toggle_action($ability); ?>
    </div>
    <?php
}

/**
 * Render the ability's slug and description. When a description is available the
 * row becomes expandable (CSS-only <details>) to reveal the full text and its
 * safety annotations; placeholder rows without a description stay flat.
 *
 * @param array{name: string, label: string, description: string, category: string, mcp: string, mcp_type: string, status: string, disabled: bool, individually_manageable: bool, managed_by_feature: bool, infrastructure: bool, manager_kind: string, manager_label: string, manage_url: string} $ability
 */
function novamira_render_ability_hub_main(array $ability, ?string $managementLabel): void
{
    if ($ability['description'] === '') {
        ?>
        <div class="novamira-hub-main novamira-hub-main--plain">
            <span class="slug" title="<?php echo esc_attr($ability['name']); ?>"><?php echo
                esc_html(novamira_ability_display_slug($ability['name']))
            ; ?></span>
            <?php novamira_render_ability_inline_state($ability, $managementLabel); ?>
        </div>
        <?php

        return;
    }
    ?>
    <details class="novamira-hub-main">
        <summary class="novamira-hub-summary">
            <span class="slug" title="<?php echo esc_attr($ability['name']); ?>"><?php echo
                esc_html(novamira_ability_display_slug($ability['name']))
            ; ?></span>
            <?php if ($ability['status'] === __('Unavailable', domain: 'novamira')): ?>
                <span class="desc"><?php echo esc_html($ability['description']); ?></span>
            <?php endif; ?>
            <?php novamira_render_ability_inline_state($ability, $managementLabel); ?>
        </summary>
        <div class="novamira-hub-detail">
            <p class="desc-full"><?php echo esc_html($ability['description']); ?></p>
        </div>
    </details>
    <?php
}

/**
 * @param array{name: string, label: string, description: string, category: string, mcp: string, mcp_type: string, status: string, disabled: bool, individually_manageable: bool, managed_by_feature: bool, infrastructure: bool, manager_kind: string, manager_label: string, manage_url: string} $ability
 */
function novamira_render_ability_inline_state(array $ability, ?string $managementLabel): void
{
    if ($managementLabel !== null) { ?>
        <span class="novamira-list-inline-state">— <?php echo esc_html($managementLabel); ?></span>
        <?php }
    if (!$ability['disabled'] || $ability['status'] === __('Unavailable', domain: 'novamira')) {
        return;
    }
    ?>
    <span class="novamira-list-inline-state"><?php esc_html_e('— Disabled', domain: 'novamira'); ?></span>
    <?php
}

/**
 * @param array{name: string, label: string, description: string, category: string, mcp: string, mcp_type: string, status: string, disabled: bool, individually_manageable: bool, managed_by_feature: bool, infrastructure: bool, manager_kind: string, manager_label: string, manage_url: string} $ability
 */
function novamira_render_ability_toggle_action(array $ability): void
{
    if (!$ability['individually_manageable']) {
        return;
    }
    ?>
    <div class="novamira-hub-actions novamira-list-actions novamira-list-progressive-actions">
        <form method="post">
            <?php wp_nonce_field('novamira_ability_hub_action'); ?>
            <input type="hidden" name="novamira_ability_hub_action" value="toggle_disabled" />
            <input type="hidden" name="ability_name" value="<?php echo esc_attr($ability['name']); ?>" />
            <button type="submit" class="action-btn">
                <?php echo
                    esc_html(
                        $ability['disabled'] ? __('Enable', domain: 'novamira') : __('Disable', domain: 'novamira'),
                    )
                ; ?>
            </button>
        </form>
    </div>
    <?php
}
