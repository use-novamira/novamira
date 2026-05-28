<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Novamira\Skills\Admin;
use Novamira\Skills\Cpt;
use Novamira\Skills\Sources;

if (!defined('ABSPATH')) {
    exit();
}

if (!Admin\current_user_can_manage()) {
    wp_die(__('You do not have permission to view this page.', domain: 'novamira'));
}

$per_page = 50;
$paged = max(1, (int) ($_GET['paged'] ?? 1));
$user_query = new \WP_Query([
    'post_type' => Cpt\POST_TYPE,
    'post_status' => ['publish', 'draft'],
    'posts_per_page' => $per_page,
    'paged' => $paged,
    'orderby' => 'title',
    'order' => 'ASC',
]);
/** @var list<\WP_Post> $user_posts */
$user_posts = $user_query->posts;
$user_total = (int) $user_query->found_posts;
$user_total_pages = (int) $user_query->max_num_pages;

// One-shot list of post IDs to flash-highlight (set by handle_upload).
$just_imported_key = 'novamira_skill_just_imported_' . get_current_user_id();
/** @var list<int> $just_imported */
$just_imported = [];
// @mago-expect analysis:mixed-assignment
$just_imported_raw = get_transient($just_imported_key);
if (is_array($just_imported_raw)) {
    // @mago-expect analysis:mixed-assignment
    foreach ($just_imported_raw as $post_id) {
        if (!is_scalar($post_id)) {
            continue;
        }
        $just_imported[] = (int) $post_id;
    }
}
if ($just_imported !== []) {
    delete_transient($just_imported_key);
}

/** @var list<\WP_Post> $trashed_posts */
$trashed_posts = get_posts([
    'post_type' => Cpt\POST_TYPE,
    'post_status' => 'trash',
    'posts_per_page' => -1,
    'orderby' => 'modified',
    'order' => 'DESC',
]);

// Group skills from non-user-cpt sources by source_id so each contributor
// gets its own table with its own dynamic heading (e.g. "Novamira Pro").
$external_groups = [];
foreach (Sources\registry() as $entry) {
    if ($entry['id'] === 'user-cpt') {
        continue;
    }
    $skills = $entry['loader']();
    if ($skills === []) {
        continue;
    }
    $external_groups[$entry['id']] = [
        'label' => $entry['label'],
        'skills' => $skills,
    ];
}

$action_url = admin_url('admin-post.php');
$new_url = add_query_arg(['page' => Admin\PAGE_SLUG, 'skill' => 'new'], admin_url('admin.php'));
?>
<?php novamira_render_admin_header(); ?>
<div class="wrap novamira-skills">
    <h1 class="wp-heading-inline"><?php esc_html_e('Skills', domain: 'novamira'); ?></h1>
    <label for="novamira-skills-upload-file" class="page-title-action"><?php esc_html_e(
        'Upload .md',
        domain: 'novamira',
    ); ?></label>
    <a href="<?php echo esc_url($new_url); ?>" class="page-title-action"><?php esc_html_e(
        'Add new',
        domain: 'novamira',
    ); ?></a>
    <?php if ($user_total > 0): ?>
        <a
            href="<?php echo
                esc_url(wp_nonce_url(add_query_arg([
                    'action' => 'novamira_skill_download_all',
                ], admin_url('admin-post.php')), action: 'novamira_skill_download_all'))
            ; ?>"
            class="page-title-action"
        ><?php esc_html_e('Download all', domain: 'novamira'); ?></a>
    <?php endif; ?>
    <hr class="wp-header-end" />

    <?php require __DIR__ . '/upload.php'; ?>

    <details class="novamira-skills-trust-warning">
        <summary>
            <span class="dashicons dashicons-shield" aria-hidden="true"></span>
            <span class="summary-text"><?php esc_html_e(
                'Only upload skills from sources you trust.',
                domain: 'novamira',
            ); ?></span>
            <span class="summary-toggle"><?php esc_html_e('Why?', domain: 'novamira'); ?></span>
        </summary>
        <div class="novamira-skills-trust-body">
            <p><?php esc_html_e(
                'A skill\'s description and body become part of the AI\'s context on this site. A malicious skill can:',
                domain: 'novamira',
            ); ?></p>
            <ul>
                <li><?php esc_html_e(
                    'Override or hijack the AI\'s behaviour with hidden instructions (prompt injection).',
                    domain: 'novamira',
                ); ?></li>
                <li><?php esc_html_e(
                    'Trick the AI into reading sensitive files (config, credentials, customer data) and sending them outside.',
                    domain: 'novamira',
                ); ?></li>
                <li><?php esc_html_e(
                    'Get the AI to run arbitrary code on your site via Novamira\'s PHP-execution abilities.',
                    domain: 'novamira',
                ); ?></li>
            </ul>
            <p><?php esc_html_e(
                'Treat an uploaded .md the same way you\'d treat installing a plugin: trust the author first.',
                domain: 'novamira',
            ); ?></p>
        </div>
    </details>

    <?php if ($user_posts === [] && $external_groups === []): ?>
        <div class="novamira-skills-empty">
            <span class="dashicons dashicons-welcome-learn-more"></span>
            <p><?php esc_html_e(
                'No skills yet. Upload a .md file or create one from scratch.',
                domain: 'novamira',
            ); ?></p>
            <p>
                <a href="<?php echo esc_url($new_url); ?>" class="button button-primary"><?php esc_html_e(
                    'Create from scratch',
                    domain: 'novamira',
                ); ?></a>
            </p>
        </div>
    <?php endif; ?>

    <?php if ($user_posts !== []): ?>
    <section class="novamira-skills-d-section">
        <div class="novamira-skills-d-header">
            <h2><?php esc_html_e('Your Skills', domain: 'novamira'); ?> <span class="count"><?php

            echo (int) $user_total;
            ?></span></h2>
            <input
                type="search"
                id="novamira-skills-search"
                class="novamira-skills-search"
                placeholder="<?php esc_attr_e('Filter on this page…', domain: 'novamira'); ?>"
                autocomplete="off"
            />
        </div>
        <div class="novamira-skills-d-list" data-novamira-skills-list>
            <?php foreach ($user_posts as $post):
                $slug = $post->post_name;
                $malformed_title = $slug === '';
                // @mago-expect analysis:mixed-operand
                $prompt_on = (bool) get_post_meta($post->ID, Cpt\META_ENABLE_PROMPT, single: true);
                // @mago-expect analysis:mixed-operand
                $agentic_on = (bool) get_post_meta($post->ID, Cpt\META_ENABLE_AGENTIC, single: true);
                $enabled = $post->post_status === 'publish';
                $description = trim($post->post_excerpt);
                $missing_description = $description === '';
                $missing_body = trim($post->post_content) === '';
                $external_conflict = $slug !== '' ? Sources\exists_in_external_source($slug) : null;
                // Missing description/body are shown as inline badges in
                // the slug column; the right-side ⚠ pill is reserved for
                // issues that don't fit there (malformed slug, external
                // source collision).
                $has_warning = $malformed_title || $external_conflict !== null;
                $edit_url = add_query_arg([
                    'page' => Admin\PAGE_SLUG,
                    'skill' => $post->ID,
                ], admin_url('admin.php'));
                $row_classes = ['novamira-skills-d-row'];
                if ($enabled) {
                    $row_classes[] = 'is-on';
                }
                if ($has_warning) {
                    $row_classes[] = 'has-warn';
                }
                if (in_array((int) $post->ID, $just_imported, strict: true)) {
                    $row_classes[] = 'is-just-imported';
                }
                ?>
            <div class="<?php echo esc_attr(implode(' ', $row_classes)); ?>">
                <form
                    method="post"
                    action="<?php echo esc_url($action_url); ?>"
                    class="novamira-skills-d-toggle"
                    title="<?php echo
                        $enabled ? esc_attr__('Disable', domain: 'novamira') : esc_attr__('Enable', domain: 'novamira')
                    ; ?>"
                >
                    <?php wp_nonce_field('novamira_skill_toggle_status_' . $post->ID); ?>
                    <input type="hidden" name="action" value="novamira_skill_toggle_status" />
                    <input type="hidden" name="post_id" value="<?php echo (int) $post->ID; ?>" />
                    <button type="submit" class="novamira-skills-d-check" aria-label="<?php echo
                        $enabled
                            ? esc_attr__('Click to disable', domain: 'novamira')
                            : esc_attr__('Click to enable', domain: 'novamira')
                    ; ?>"></button>
                </form>
                <a class="novamira-skills-d-main" href="<?php echo esc_url($edit_url); ?>">
                    <span class="slug"><?php echo esc_html($slug !== '' ? $slug : $post->post_title); ?></span>
                    <?php if ($missing_description): ?>
                        <span class="desc-badge is-missing">⚠ <?php esc_html_e(
                            'Missing description',
                            domain: 'novamira',
                        ); ?></span>
                    <?php endif; ?>
                    <?php if (!$missing_description && $description !== ''): ?>
                        <span class="desc"><?php echo esc_html($description); ?></span>
                    <?php endif; ?>
                    <?php if ($missing_body): ?>
                        <span class="desc-badge is-missing">⚠ <?php esc_html_e(
                            'Missing body',
                            domain: 'novamira',
                        ); ?></span>
                    <?php endif; ?>
                </a>
                <div class="novamira-skills-d-pills">
                    <?php if ($agentic_on): ?>
                        <span class="pill auto"><?php esc_html_e('Auto', domain: 'novamira'); ?></span>
                    <?php endif; ?>
                    <?php if ($prompt_on): ?>
                        <span class="pill cmd"><?php esc_html_e('Command', domain: 'novamira'); ?></span>
                    <?php endif; ?>
                    <?php if ($has_warning): ?>
                        <?php

                        $critical = $external_conflict !== null;
                        $warnings = [];
                        if ($malformed_title) {
                            $warnings[] = __('Malformed title', domain: 'novamira');
                        }
                        if ($external_conflict !== null) {
                            $warnings[] = sprintf(
                                /* translators: %s = source label */
                                __('Conflicts with %s', domain: 'novamira'),
                                $external_conflict,
                            );
                        }
                        ?>
                        <span
                            class="pill warn<?php echo $critical ? ' is-critical' : ''; ?>"
                            title="<?php echo esc_attr(implode(' · ', $warnings)); ?>"
                        >⚠ <?php echo (int) count($warnings); ?></span>
                    <?php endif; ?>
                </div>
                <div class="novamira-skills-d-actions">
                    <a class="action-btn" href="<?php echo esc_url($edit_url); ?>"><?php

                    esc_html_e('Edit', domain: 'novamira');
                    ?></a>
                    <a
                        class="action-btn"
                        href="<?php echo
                            esc_url(wp_nonce_url(
                                add_query_arg([
                                    'action' => 'novamira_skill_download',
                                    'post_id' => (int) $post->ID,
                                ], admin_url('admin-post.php')),
                                'novamira_skill_download_' . (int) $post->ID,
                            ))
                        ; ?>"
                    ><?php esc_html_e('Download', domain: 'novamira'); ?></a>
                    <form
                        method="post"
                        action="<?php echo esc_url($action_url); ?>"
                        onsubmit="return confirm('<?php echo
                            esc_js(__('Delete this skill permanently?', domain: 'novamira'))
                        ; ?>');"
                    >
                        <?php wp_nonce_field('novamira_skill_delete_' . $post->ID); ?>
                        <input type="hidden" name="action" value="novamira_skill_delete" />
                        <input type="hidden" name="post_id" value="<?php echo (int) $post->ID; ?>" />
                        <button type="submit" class="action-btn action-btn--danger"><?php

                        esc_html_e('Delete', domain: 'novamira');
                        ?></button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if ($user_total_pages > 1): ?>
            <nav class="novamira-skills-pagination" aria-label="<?php esc_attr_e(
                'Skills pagination',
                domain: 'novamira',
            ); ?>">
                <?php

                // @mago-expect analysis:mixed-assignment
                $links = paginate_links([
                    'base' => add_query_arg(['paged' => '%#%'], admin_url('admin.php?page=' . Admin\PAGE_SLUG)),
                    'format' => '',
                    'current' => $paged,
                    'total' => $user_total_pages,
                    'prev_text' => '‹',
                    'next_text' => '›',
                    'type' => 'plain',
                ]);
                echo is_string($links) ? $links : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
            </nav>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($trashed_posts !== []): ?>
    <section class="novamira-skills-d-section novamira-skills-d-section--trash">
        <div class="novamira-skills-d-header">
            <h2><?php esc_html_e('Trash', domain: 'novamira'); ?> <span class="count"><?php

            echo (int) count($trashed_posts);
            ?></span></h2>
            <span class="novamira-skills-d-trash-hint"><?php esc_html_e(
                'Trashed skills are not loaded by the AI. Items are auto-removed after 30 days.',
                domain: 'novamira',
            ); ?></span>
        </div>
        <div class="novamira-skills-d-list">
            <?php foreach ($trashed_posts as $post):
                $slug = $post->post_name !== '' ? $post->post_name : $post->post_title;
                // WP appends `__trashed` to post_name when trashing; strip for display.
                $slug = (string) preg_replace('/__trashed$/', replacement: '', subject: $slug);
                $description = trim($post->post_excerpt);
                ?>
            <div class="novamira-skills-d-row is-trashed">
                <div class="novamira-skills-d-trash-icon" aria-hidden="true">⌫</div>
                <div class="novamira-skills-d-main novamira-skills-d-main--trash">
                    <span class="slug"><?php echo esc_html($slug); ?></span>
                    <?php if ($description !== ''): ?>
                        <span class="desc"><?php echo esc_html($description); ?></span>
                    <?php endif; ?>
                </div>
                <div class="novamira-skills-d-pills">
                    <span class="pill"><?php esc_html_e('Trash', domain: 'novamira'); ?></span>
                </div>
                <div class="novamira-skills-d-actions novamira-skills-d-actions--trash">
                    <form method="post" action="<?php echo esc_url($action_url); ?>">
                        <?php wp_nonce_field('novamira_skill_restore_' . $post->ID); ?>
                        <input type="hidden" name="action" value="novamira_skill_restore" />
                        <input type="hidden" name="post_id" value="<?php echo (int) $post->ID; ?>" />
                        <button type="submit" class="action-btn"><?php

                        esc_html_e('Restore', domain: 'novamira');
                        ?></button>
                    </form>
                    <form
                        method="post"
                        action="<?php echo esc_url($action_url); ?>"
                        onsubmit="return confirm('<?php echo
                            esc_js(__('Delete this skill permanently? This cannot be undone.', domain: 'novamira'))
                        ; ?>');"
                    >
                        <?php wp_nonce_field('novamira_skill_permanent_delete_' . $post->ID); ?>
                        <input type="hidden" name="action" value="novamira_skill_permanent_delete" />
                        <input type="hidden" name="post_id" value="<?php echo (int) $post->ID; ?>" />
                        <button type="submit" class="action-btn action-btn--danger"><?php

                        esc_html_e('Delete permanently', domain: 'novamira');
                        ?></button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php foreach ($external_groups as $source_id => $group): ?>
    <section class="novamira-skills-d-section novamira-skills-d-section--external">
        <div class="novamira-skills-d-header">
            <h2><?php

            printf(
                /* translators: %s: contributor label, e.g. "Novamira Pro" */
                esc_html__('Skills from %s', domain: 'novamira'),
                esc_html($group['label']),
            );
            ?> <span class="count"><?php

            echo (int) count($group['skills']);
            ?></span></h2>
            <span class="novamira-skills-d-readonly-note"><?php esc_html_e(
                'Not editable',
                domain: 'novamira',
            ); ?></span>
        </div>
        <?php if (str_starts_with($source_id, 'novamira-pro')): ?>
            <p class="novamira-skills-d-source-blurb">
                <?php

                printf(
                    /* translators: 1: source label, 2: link opening tag, 3: link closing tag */
                    esc_html__(
                        '%1$s combines skills, abilities, and more. You see only the skills relevant to the plugins you have installed. %2$sLearn more →%3$s',
                        domain: 'novamira',
                    ),
                    esc_html($group['label']),
                    '<a href="https://www.novamira.ai/pro/?utm_source=plugin&utm_medium=skills" target="_blank" rel="noopener">',
                    '</a>',
                );
                ?>
            </p>
        <?php endif; ?>
        <div class="novamira-skills-d-list">
            <?php foreach ($group['skills'] as $skill):
                $slug = (string) ($skill['slug'] ?? '');
                $description = trim((string) ($skill['description'] ?? ''));
                $missing_description = $description === '';
                // @mago-expect analysis:mixed-operand
                $prompt_on = (bool) ($skill['enable_prompt'] ?? false);
                // @mago-expect analysis:mixed-operand
                $agentic_on = (bool) ($skill['enable_agentic'] ?? false);
                ?>
            <div class="novamira-skills-d-row is-external is-on">
                <div class="novamira-skills-d-source-icon" aria-hidden="true">↗</div>
                <div class="novamira-skills-d-main novamira-skills-d-main--external">
                    <span class="slug"><?php echo esc_html($slug); ?></span>
                    <?php if ($missing_description): ?>
                        <span class="desc-badge is-missing">⚠ <?php esc_html_e(
                            'Missing description',
                            domain: 'novamira',
                        ); ?></span>
                    <?php endif; ?>
                    <?php if (!$missing_description): ?>
                        <span class="desc"><?php echo esc_html($description); ?></span>
                    <?php endif; ?>
                </div>
                <div class="novamira-skills-d-pills">
                    <?php if ($agentic_on): ?>
                        <span class="pill auto"><?php esc_html_e('Auto', domain: 'novamira'); ?></span>
                    <?php endif; ?>
                    <?php if ($prompt_on): ?>
                        <span class="pill cmd"><?php esc_html_e('Command', domain: 'novamira'); ?></span>
                    <?php endif; ?>
                </div>
                <div class="novamira-skills-d-actions"></div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endforeach; ?>

</div>
<script>
(function () {
    var file = document.getElementById('novamira-skills-upload-file');
    var form = document.getElementById('novamira-skills-upload-form');
    var MAX_UPLOAD = 20;
    if (file && form) {
        file.addEventListener('change', function () {
            if (!file.files || file.files.length === 0) {
                return;
            }
            if (file.files.length > MAX_UPLOAD) {
                alert('Too many files. Upload up to ' + MAX_UPLOAD + ' skills at a time.');
                file.value = '';
                return;
            }
            form.submit();
        });
    }

    // Client-side filter for the current page's rows. Searches slug and
    // description (visible text inside each row).
    var search = document.getElementById('novamira-skills-search');
    var list = document.querySelector('[data-novamira-skills-list]');
    if (search && list) {
        var rows = Array.prototype.slice.call(list.querySelectorAll('.novamira-skills-d-row'));
        search.addEventListener('input', function () {
            var q = search.value.toLowerCase().trim();
            rows.forEach(function (row) {
                if (q === '') {
                    row.style.display = '';
                    return;
                }
                row.style.display = (row.textContent || '').toLowerCase().indexOf(q) !== -1 ? '' : 'none';
            });
        });
    }
})();
</script>
