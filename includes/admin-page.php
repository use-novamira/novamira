<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Collects every public MCP tool ability registered on the site, grouped by source.
 *
 * The source label is resolved per-ability via the `novamira_ability_source_label`
 * filter (default: "Novamira"), so add-ons can contribute rows under their own
 * heading. Within a group, rows are sorted by category then name. Groups are
 * returned with the default source first, other sources sorted alphabetically.
 *
 * @return array<string, list<array{name: string, category: string, description: string}>>
 */
function novamira_collect_public_abilities(): array
{
    $default_source = __('Novamira', domain: 'novamira');
    $groups = [];
    foreach (wp_get_abilities() as $ability) {
        $name = $ability->get_name();
        if (!str_starts_with($name, 'novamira/')) {
            continue;
        }
        $meta = $ability->get_meta();
        if (!($meta['mcp']['public'] ?? false)) {
            continue;
        }
        if (($meta['mcp']['type'] ?? 'tool') !== 'tool') {
            continue;
        }
        $category_slug = $ability->get_category();
        $category = $category_slug !== '' ? wp_get_ability_category($category_slug) : null;
        /** @var string $source */
        $source = apply_filters('novamira_ability_source_label', $default_source, $ability);
        $groups[$source] ??= [];
        $groups[$source][] = [
            'name' => $name,
            'category' => $category !== null ? $category->get_label() : $category_slug,
            'description' => $ability->get_description(),
        ];
    }
    foreach ($groups as $source => $rows) {
        usort(
            $rows,
            static fn(array $a, array $b): int => [$a['category'], $a['name']] <=> [$b['category'], $b['name']],
        );
        $groups[$source] = $rows;
    }

    $sorted = [];
    if (array_key_exists($default_source, $groups)) {
        $sorted[$default_source] = $groups[$default_source];
        unset($groups[$default_source]);
    }
    ksort($groups);
    return $sorted + $groups;
}

function novamira_handle_sandbox_actions()
{
    if (!current_user_can('manage_options')) {
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

    $result = match ($action) {
        'delete' => unlink($path),
        'disable' => str_ends_with($file, '.php') && rename($path, $path . '.disabled'),
        'enable' => str_ends_with($file, '.disabled') && rename($path, substr($path, offset: 0, length: -9)),
        'exit_safe_mode' => $file === '.crashed' && unlink($path),
        default => false,
    };

    if ($result) {
        wp_safe_redirect(admin_url('admin.php?page=novamira-sandbox&novamira_result=' . $action));
        exit();
    }
}

function novamira_render_sandbox_page(): void
{
    if (!current_user_can('manage_options')) {
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
    <div class="wrap">
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
    $files = $scanned_files !== false ? array_diff($scanned_files, ['.', '..', '.loading', '.crashed']) : [];

    return array_values(array_filter($files, static fn(string $file): bool => !is_dir($sandbox_dir . $file)));
}

/**
 * @param list<string> $files
 */
function novamira_render_sandbox_rows(string $sandbox_dir, array $files, string $sandbox_status): void
{
    $format = novamira_get_datetime_format();
    $base_url = admin_url('admin.php?page=novamira-sandbox');
    ?>
    <div class="novamira-sandbox-rows">
        <?php foreach ($files as $file): ?>
            <?php novamira_render_sandbox_row($sandbox_dir, $file, $sandbox_status, $format, $base_url); ?>
        <?php endforeach; ?>
    </div>
    <?php
}

function novamira_render_sandbox_row(
    string $sandbox_dir,
    string $file,
    string $sandbox_status,
    string $format,
    string $base_url,
): void {
    $path = $sandbox_dir . $file;
    $file_status = novamira_get_sandbox_file_status($file, $sandbox_status);
    $display_name = $file_status === 'disabled' ? substr($file, offset: 0, length: -9) : $file;
    $ext = strtolower(pathinfo($display_name, PATHINFO_EXTENSION));
    $mtime = filemtime($path);
    $wp_date = $mtime !== false ? wp_date($format, $mtime) : false;
    $modified = $wp_date !== false ? $wp_date : __('Unknown', domain: 'novamira');

    $delete_url = wp_nonce_url(
        $base_url . '&action=delete&file=' . urlencode($file),
        action: 'novamira_manage_file_' . $file,
    );
    ?>
    <div class="<?php echo esc_attr('novamira-sandbox-row is-' . $file_status); ?>">
        <?php novamira_render_sandbox_toggle($file, $file_status, $ext, $base_url); ?>

        <div class="novamira-sandbox-main">
            <span class="slug"><?php echo esc_html($display_name); ?></span>
            <span class="meta"><?php echo esc_html($modified); ?></span>
        </div>

        <div class="novamira-sandbox-pills">
            <?php novamira_render_sandbox_pills($ext, $file_status); ?>
        </div>

        <div class="novamira-sandbox-actions">
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

function novamira_get_sandbox_file_status(string $file, string $sandbox_status): string
{
    if ($sandbox_status === 'suspended') {
        return 'suspended';
    }

    if (str_ends_with($file, '.disabled')) {
        return 'disabled';
    }

    return 'on';
}

function novamira_render_sandbox_toggle(string $file, string $file_status, string $ext, string $base_url): void
{
    if ($file_status === 'suspended' || $file_status !== 'disabled' && $ext !== 'php') {
        ?>
        <span class="novamira-sandbox-check" aria-hidden="true"></span>
        <?php

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
        class="novamira-sandbox-toggle"
        title="<?php echo
            $is_disabled ? esc_attr__('Enable', domain: 'novamira') : esc_attr__('Disable', domain: 'novamira')
        ; ?>"
        aria-label="<?php echo
            $is_disabled
                ? esc_attr__('Enable file', domain: 'novamira')
                : esc_attr__('Disable file', domain: 'novamira')
        ; ?>"
    ><span class="novamira-sandbox-check"></span></a>
    <?php
}

function novamira_render_sandbox_pills(string $ext, string $file_status): void
{
    if ($ext !== '') { ?>
        <span class="pill ext-<?php echo esc_attr($ext); ?>"><?php echo esc_html($ext); ?></span>
        <?php }

    if ($file_status === 'suspended') {
        ?>
        <span class="pill warn"><?php esc_html_e('Suspended', domain: 'novamira'); ?></span>
        <?php

        return;
    }

    if ($file_status === 'disabled') { ?>
        <span class="pill"><?php esc_html_e('Disabled', domain: 'novamira'); ?></span>
        <?php }
}

function novamira_render_settings_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $ability_groups = novamira_collect_public_abilities();
    ?>
    <?php novamira_render_admin_header(); ?>
    <div class="wrap">
        <h1><?php esc_html_e('AI Abilities', domain: 'novamira'); ?></h1>
        <p><?php printf(
            /* translators: %s: link to the Configuration page */
            esc_html__(
                'These MCP tools are exposed to AI agents when AI Abilities are enabled on the %s page.',
                domain: 'novamira',
            ),
            '<a href="'
            . esc_url(admin_url('admin.php?page=novamira-connect'))
            . '">'
            . esc_html__('Configuration', domain: 'novamira')
            . '</a>',
        ); ?></p>
        <?php foreach ($ability_groups as $source => $abilities): ?>
            <h3 style="margin-top:1.5em;"><?php echo esc_html($source); ?></h3>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:260px;"><?php esc_html_e('Ability', domain: 'novamira'); ?></th>
                        <th style="width:140px;"><?php esc_html_e('Category', domain: 'novamira'); ?></th>
                        <th><?php esc_html_e('Description', domain: 'novamira'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($abilities as $ability): ?>
                        <tr>
                            <td><code><?php echo esc_html($ability['name']); ?></code></td>
                            <td><?php echo esc_html($ability['category']); ?></td>
                            <td><?php echo esc_html($ability['description']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endforeach; ?>
    </div>
    <?php
}
