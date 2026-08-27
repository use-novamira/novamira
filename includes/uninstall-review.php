<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

const NOVAMIRA_UNINSTALL_PLAN_OPTION = 'novamira_uninstall_plan';

const NOVAMIRA_UNINSTALL_PAGE_SLUG = 'novamira-uninstall';

add_action('admin_menu', callback: 'novamira_register_uninstall_review_page');
add_action('network_admin_menu', callback: 'novamira_register_uninstall_review_page');
add_action('admin_post_novamira_prepare_uninstall', callback: 'novamira_handle_prepare_uninstall');

$novamira_plugin_basename = plugin_basename(dirname(__DIR__) . '/novamira.php');
add_filter('plugin_action_links_' . $novamira_plugin_basename, callback: 'novamira_uninstall_action_links');
add_filter(
    'network_admin_plugin_action_links_' . $novamira_plugin_basename,
    callback: 'novamira_uninstall_action_links',
);

function novamira_clear_uninstall_plan(): void
{
    delete_site_option(NOVAMIRA_UNINSTALL_PLAN_OPTION);
}

function novamira_register_uninstall_review_page(): void
{
    add_submenu_page(
        parent_slug: '',
        page_title: __('Before deactivating Novamira', domain: 'novamira'),
        menu_title: '',
        capability: novamira_manage_capability(),
        menu_slug: NOVAMIRA_UNINSTALL_PAGE_SLUG,
        callback: 'novamira_render_uninstall_review_page',
    );
}

/**
 * @param array<string, string> $actions Plugin row actions.
 * @return array<string, string>
 */
function novamira_uninstall_action_links(array $actions): array
{
    if (!array_key_exists('deactivate', $actions) || !novamira_current_user_can_manage()) {
        return $actions;
    }

    $url = is_network_admin()
        ? network_admin_url('admin.php?page=' . NOVAMIRA_UNINSTALL_PAGE_SLUG)
        : admin_url('admin.php?page=' . NOVAMIRA_UNINSTALL_PAGE_SLUG);
    $actions['deactivate'] = sprintf(
        '<a href="%1$s" aria-label="%2$s">%3$s</a>',
        esc_url($url),
        esc_attr__('Review options before deactivating Novamira', domain: 'novamira'),
        esc_html__('Deactivate', domain: 'novamira'),
    );

    return $actions;
}

function novamira_render_uninstall_review_page(): void
{
    if (!novamira_current_user_can_manage()) {
        wp_die(esc_html__('You are not allowed to deactivate Novamira.', domain: 'novamira'), args: [
            'response' => 403,
        ]);
    }

    $sandbox_file_count = novamira_uninstall_sandbox_file_count();
    $password_count = novamira_uninstall_application_password_count();
    $content_counts = novamira_uninstall_content_counts();
    novamira_render_admin_header();
    ?>
    <div class="wrap novamira-uninstall-wrap">
        <h1><?php esc_html_e('Before deactivating Novamira', domain: 'novamira'); ?></h1>
        <p><?php esc_html_e(
            'Choose what Novamira should remove if you delete the plugin later.',
            domain: 'novamira',
        ); ?></p>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="novamira_prepare_uninstall">
            <?php wp_nonce_field('novamira_prepare_uninstall'); ?>

        <?php if ($sandbox_file_count > 0): ?>
            <div class="card" style="max-width:760px; margin-top:20px;">
                <h2><?php esc_html_e('Sandbox files', domain: 'novamira'); ?></h2>
                <p><strong><?php echo
                    esc_html(sprintf(
                        _n(
                            single: '%d file is present in the Novamira sandbox.',
                            plural: '%d files are present in the Novamira sandbox.',
                            number: $sandbox_file_count,
                            domain: 'novamira',
                        ),
                        $sandbox_file_count,
                    ))
                ; ?></strong></p>
                <p><?php esc_html_e(
                    'These files can contain PHP code that provides active site functionality. Deactivating Novamira stops loading them, which may break the site or cause fatal errors. Move any functionality you need to a persistent, independently loaded location before continuing.',
                    domain: 'novamira',
                ); ?></p>
                <p><code style="overflow-wrap:anywhere;"><?php echo esc_html(novamira_get_sandbox_dir()); ?></code></p>
                <details>
                    <summary style="cursor:pointer;">
                        <strong><?php esc_html_e('Ask your AI client how to move them', domain: 'novamira'); ?></strong>
                    </summary>
                    <p><?php esc_html_e(
                        'Use Novamira itself to prepare the move. Copy this prompt into your connected AI client:',
                        domain: 'novamira',
                    ); ?></p>
                    <textarea id="novamira-sandbox-prompt" class="large-text code" rows="6" readonly><?php esc_html_e(
                        'I am going to delete the Novamira plugin and need to move the files currently in its sandbox to persistent storage first. Use Novamira itself to inspect the sandbox files and determine which persistent locations are available on this WordPress site. Do not deactivate, delete, or uninstall Novamira, because that would end this connection. Explain the available options and their trade-offs. Do not move any files until I choose an option.',
                        domain: 'novamira',
                    ); ?></textarea>
                    <p>
                        <button type="button" class="button" id="novamira-copy-sandbox-prompt">
                            <?php esc_html_e('Copy prompt', domain: 'novamira'); ?>
                        </button>
                        <span id="novamira-copy-sandbox-status" aria-live="polite"></span>
                    </p>
                </details>
                <p>
                    <label>
                        <input type="checkbox" name="confirm_sandbox_files" value="1" required>
                        <strong><?php esc_html_e(
                            'I understand that these sandbox files may power site functionality and that deactivating Novamira could break the site or cause fatal errors.',
                            domain: 'novamira',
                        ); ?></strong>
                    </label>
                </p>
            </div>
        <?php endif; ?>

            <div class="card" style="max-width:760px; margin-top:20px;">
                <h2><?php esc_html_e('Stored content', domain: 'novamira'); ?></h2>
                <p><?php esc_html_e(
                    'Deactivating Novamira keeps this content in the database, but Memory, user-added Skills, and Chat will be unavailable. They become available again when Novamira and any required add-on are active.',
                    domain: 'novamira',
                ); ?></p>

                <?php if ($content_counts['memories'] > 0): ?>
                    <p><strong><?php echo
                        esc_html(sprintf(
                            _n(
                                single: '%d Novamira Pro memory was found.',
                                plural: '%d Novamira Pro memories were found.',
                                number: $content_counts['memories'],
                                domain: 'novamira',
                            ),
                            $content_counts['memories'],
                        ))
                    ; ?></strong></p>
                    <p>
                        <label>
                            <input type="checkbox" name="delete_memories" value="1">
                            <?php esc_html_e(
                                'When Novamira is deleted, permanently delete these memories',
                                domain: 'novamira',
                            ); ?>
                        </label>
                    </p>
                <?php endif; ?>
                <?php if ($content_counts['memories'] === 0): ?>
                    <p><?php esc_html_e('No Novamira Pro memories were found.', domain: 'novamira'); ?></p>
                <?php endif; ?>

                <?php if ($content_counts['user_skills'] > 0): ?>
                    <p><strong><?php echo
                        esc_html(sprintf(
                            _n(
                                single: '%d user-added skill was found.',
                                plural: '%d user-added skills were found.',
                                number: $content_counts['user_skills'],
                                domain: 'novamira',
                            ),
                            $content_counts['user_skills'],
                        ))
                    ; ?></strong></p>
                    <p>
                        <label>
                            <input type="checkbox" name="delete_user_skills" value="1">
                            <?php esc_html_e(
                                'When Novamira is deleted, permanently delete these user-added skills',
                                domain: 'novamira',
                            ); ?>
                        </label>
                    </p>
                <?php endif; ?>
                <?php if ($content_counts['user_skills'] === 0): ?>
                    <p><?php esc_html_e('No user-added skills were found.', domain: 'novamira'); ?></p>
                <?php endif; ?>

                <?php if ($content_counts['chat_sessions'] > 0): ?>
                    <p><strong><?php echo
                        esc_html(sprintf(
                            _n(
                                single: '%d Novamira Chat session was found.',
                                plural: '%d Novamira Chat sessions were found.',
                                number: $content_counts['chat_sessions'],
                                domain: 'novamira',
                            ),
                            $content_counts['chat_sessions'],
                        ))
                    ; ?></strong></p>
                    <p>
                        <label>
                            <input type="checkbox" name="delete_chat_sessions" value="1" checked>
                            <?php esc_html_e(
                                'When Novamira is deleted, permanently delete these chat sessions',
                                domain: 'novamira',
                            ); ?>
                        </label>
                    </p>
                <?php endif; ?>
                <?php if ($content_counts['chat_sessions'] === 0): ?>
                    <p><?php esc_html_e('No Novamira Chat sessions were found.', domain: 'novamira'); ?></p>
                <?php endif; ?>
            </div>

            <div class="card" style="max-width:760px; margin-top:20px;">
                <h2><?php esc_html_e('Connections', domain: 'novamira'); ?></h2>
                <p>
                    <label>
                        <input type="checkbox" name="delete_oauth" value="1" checked>
                        <?php esc_html_e(
                            'When Novamira is deleted, delete OAuth connections and authorization data',
                            domain: 'novamira',
                        ); ?>
                    </label>
                </p>
                <?php if ($password_count > 0): ?>
                    <p>
                        <label>
                            <input type="checkbox" name="delete_application_passwords" value="1" checked>
                            <?php echo
                                esc_html(sprintf(
                                    _n(
                                        single: 'When Novamira is deleted, revoke %d Novamira Application Password across all users',
                                        plural: 'When Novamira is deleted, revoke %d Novamira Application Passwords across all users',
                                        number: $password_count,
                                        domain: 'novamira',
                                    ),
                                    $password_count,
                                ))
                            ; ?>
                        </label>
                    </p>
                <?php endif; ?>
                <?php if ($password_count === 0): ?>
                    <p><?php esc_html_e('No Novamira Application Passwords were found.', domain: 'novamira'); ?></p>
                <?php endif; ?>
            </div>

            <?php submit_button(__('Save choices and deactivate Novamira', domain: 'novamira')); ?>
        </form>
        <script>
            (() => {
                const button = document.getElementById('novamira-copy-sandbox-prompt');
                const prompt = document.getElementById('novamira-sandbox-prompt');
                const status = document.getElementById('novamira-copy-sandbox-status');
                if (!button || !prompt || !status) {
                    return;
                }

                button.addEventListener('click', async () => {
                    try {
                        await navigator.clipboard.writeText(prompt.value);
                        status.textContent = ' <?php echo esc_js(__('Copied.', domain: 'novamira')); ?>';
                    } catch {
                        prompt.select();
                        status.textContent = ' <?php echo
                            esc_js(__('Select the prompt and copy it manually.', domain: 'novamira'))
                        ; ?>';
                    }
                });
            })();
        </script>
    </div>
    <?php
}

function novamira_handle_prepare_uninstall(): void
{
    if (!novamira_current_user_can_manage()) {
        wp_die(esc_html__('You are not allowed to deactivate Novamira.', domain: 'novamira'), args: [
            'response' => 403,
        ]);
    }

    $plugin = plugin_basename(dirname(__DIR__) . '/novamira.php');
    if (!current_user_can('deactivate_plugin', $plugin)) {
        wp_die(esc_html__('You are not allowed to deactivate Novamira.', domain: 'novamira'), args: [
            'response' => 403,
        ]);
    }

    check_admin_referer('novamira_prepare_uninstall');
    if (novamira_uninstall_sandbox_file_count() > 0 && !array_key_exists('confirm_sandbox_files', $_POST)) {
        wp_die(
            esc_html__('Confirm that you understand what will happen to the sandbox files.', domain: 'novamira'),
            args: ['response' => 400],
        );
    }
    update_site_option(NOVAMIRA_UNINSTALL_PLAN_OPTION, [
        'delete_oauth' => array_key_exists('delete_oauth', $_POST),
        'delete_application_passwords' => array_key_exists('delete_application_passwords', $_POST),
        'delete_memories' => array_key_exists('delete_memories', $_POST),
        'delete_user_skills' => array_key_exists('delete_user_skills', $_POST),
        'delete_chat_sessions' => array_key_exists('delete_chat_sessions', $_POST),
    ]);

    if (!function_exists('deactivate_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $network_wide = is_multisite() && is_plugin_active_for_network($plugin);
    deactivate_plugins($plugin, silent: false, network_wide: $network_wide);

    $plugins_url = $network_wide
        ? network_admin_url('plugins.php?deactivate=true')
        : admin_url('plugins.php?deactivate=true');
    wp_safe_redirect($plugins_url);
    exit();
}

function novamira_uninstall_sandbox_file_count(): int
{
    $sandbox_dir = novamira_get_sandbox_dir();
    if (!is_dir($sandbox_dir)) {
        return 0;
    }

    try {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $sandbox_dir,
            FilesystemIterator::SKIP_DOTS,
        ));
    } catch (UnexpectedValueException) {
        return 0;
    }

    $count = 0;
    foreach ($files as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }
        if (novamira_is_sandbox_internal_file_name($file->getFilename())) {
            continue;
        }
        $count++;
    }

    return $count;
}

/** @return array{memories: int, user_skills: int, chat_sessions: int} */
function novamira_uninstall_content_counts(): array
{
    $plugin = plugin_basename(dirname(__DIR__) . '/novamira.php');
    if (!is_multisite() || !is_plugin_active_for_network($plugin)) {
        return novamira_uninstall_current_site_content_counts();
    }

    $counts = ['memories' => 0, 'user_skills' => 0, 'chat_sessions' => 0];
    // @mago-expect analysis:mixed-assignment -- WordPress returns site ids when fields=ids.
    $site_ids = get_sites(['fields' => 'ids', 'number' => 0]);
    if (!is_array($site_ids)) {
        return $counts;
    }

    // @mago-expect analysis:mixed-assignment
    foreach ($site_ids as $site_id) {
        switch_to_blog((int) $site_id);
        $site_counts = novamira_uninstall_current_site_content_counts();
        restore_current_blog();

        $counts['memories'] += $site_counts['memories'];
        $counts['user_skills'] += $site_counts['user_skills'];
        $counts['chat_sessions'] += $site_counts['chat_sessions'];
    }

    return $counts;
}

/** @return array{memories: int, user_skills: int, chat_sessions: int} */
function novamira_uninstall_current_site_content_counts(): array
{
    return [
        'memories' => novamira_uninstall_post_type_count('novamira_memory'),
        'user_skills' => novamira_uninstall_post_type_count('novamira_skill'),
        'chat_sessions' => novamira_uninstall_chat_session_count(),
    ];
}

function novamira_uninstall_post_type_count(string $post_type): int
{
    // @mago-expect lint:no-global -- $wpdb is WordPress' database handle.
    global $wpdb;

    /** @var wpdb $wpdb */
    $query = $wpdb->prepare('SELECT COUNT(ID) FROM %i WHERE post_type = %s', $wpdb->posts, $post_type);
    if (!is_string($query)) {
        return 0;
    }

    return (int) $wpdb->get_var($query);
}

function novamira_uninstall_chat_session_count(): int
{
    // @mago-expect lint:no-global -- $wpdb is WordPress' database handle.
    global $wpdb;

    /** @var wpdb $wpdb */
    $table = $wpdb->prefix . 'novamira_chat_sessions';
    $table_query = $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table));
    if (!is_string($table_query) || $wpdb->get_var($table_query) !== $table) {
        return 0;
    }

    $count_query = $wpdb->prepare('SELECT COUNT(*) FROM %i', $table);
    return is_string($count_query) ? (int) $wpdb->get_var($count_query) : 0;
}

function novamira_uninstall_application_password_count(): int
{
    $count = 0;
    foreach (novamira_application_password_user_ids() as $user_id) {
        foreach (WP_Application_Passwords::get_user_application_passwords($user_id) as $password) {
            if (!novamira_is_application_password($password)) {
                continue;
            }
            $count++;
        }
    }

    return $count;
}

/** @return list<int> */
function novamira_application_password_user_ids(): array
{
    // @mago-expect lint:no-global -- $wpdb is WordPress' database handle.
    global $wpdb;

    /** @var wpdb $wpdb */
    // @mago-expect analysis:possibly-invalid-argument -- $wpdb->usermeta is WordPress' users table.
    $query = $wpdb->prepare(
        "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s",
        WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS,
    );
    if (!is_string($query)) {
        return [];
    }
    $user_ids = $wpdb->get_col($query);

    return array_values(array_unique(array_map('intval', $user_ids)));
}
