<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Novamira\Skills\Admin;
use Novamira\Skills\Cpt;

if (!defined('ABSPATH')) {
    exit();
}

if (!Admin\current_user_can_manage()) {
    wp_die(__('You do not have permission to edit skills.', domain: 'novamira'));
}

$skill_param = $_GET['skill'] ?? 'new';
$id_or_new = is_scalar($skill_param) ? (string) $skill_param : 'new'; // @mago-expect analysis:redundant-cast
$is_new = $id_or_new === 'new';

$title = '';
$description = '';
$content = '';
$enable_prompt = true;
$enable_agentic = true;
$enabled = true;
$post_id = 0;

if (!$is_new) {
    // @mago-expect analysis:mixed-assignment
    $maybe_post = get_post((int) $id_or_new);
    if (!$maybe_post instanceof \WP_Post || $maybe_post->post_type !== Cpt\POST_TYPE) {
        wp_die(__('Skill not found.', domain: 'novamira'));
    }

    /** @var \WP_Post $post */
    $post = $maybe_post;
    $post_id = (int) $post->ID;
    $title = $post->post_name !== '' ? $post->post_name : $post->post_title;
    $description = $post->post_excerpt;
    $content = $post->post_content;
    // @mago-expect analysis:mixed-operand
    $enable_prompt = (bool) get_post_meta($post_id, Cpt\META_ENABLE_PROMPT, single: true);
    // @mago-expect analysis:mixed-operand
    $enable_agentic = (bool) get_post_meta($post_id, Cpt\META_ENABLE_AGENTIC, single: true);
    $enabled = $post->post_status === 'publish';
}

$list_url = admin_url('admin.php?page=' . Admin\PAGE_SLUG);
$action_url = admin_url('admin-post.php');
$nonce_action = $is_new ? 'novamira_skill_create' : 'novamira_skill_update_' . $post_id;
$form_action = $is_new ? 'novamira_skill_create' : 'novamira_skill_update';

$heading_title = match (true) {
    $is_new => __('New skill', domain: 'novamira'),
    $title !== '' => $title,
    default => __('Untitled', domain: 'novamira'),
};
?>
<?php novamira_render_admin_header(); ?>
<div class="wrap novamira-skills-edit">
    <h1>
        <a href="<?php echo esc_url($list_url); ?>">← <?php esc_html_e('Skills', domain: 'novamira'); ?></a>
        / <?php echo esc_html($heading_title); ?>
    </h1>

    <form method="post" action="<?php echo esc_url($action_url); ?>">
        <?php wp_nonce_field($nonce_action); ?>
        <input type="hidden" name="action" value="<?php echo esc_attr($form_action); ?>" />
        <?php if ($post_id > 0): ?>
            <input type="hidden" name="post_id" value="<?php echo (int) $post_id; ?>" />
        <?php endif; ?>

        <div class="novamira-skills-edit-grid">
            <div class="novamira-skills-edit-main">
                <div class="novamira-skills-title-field">
                    <input
                        type="text"
                        name="title"
                        value="<?php echo esc_attr($title); ?>"
                        required
                        placeholder="<?php esc_attr_e('untitled-skill', domain: 'novamira'); ?>"
                        class="novamira-skills-title-input"
                        aria-label="<?php esc_attr_e('Title', domain: 'novamira'); ?>"
                    />
                </div>
                <div class="novamira-skills-field">
                    <label
                        for="novamira-skills-description"
                        class="novamira-skills-field-label"
                    ><?php esc_html_e('Description', domain: 'novamira'); ?></label>
                    <textarea
                        name="description"
                        id="novamira-skills-description"
                        rows="2"
                        required
                        class="large-text"
                        placeholder="<?php esc_attr_e(
                            'e.g. Builds a landing page from a brief, following the site\'s design system.',
                            domain: 'novamira',
                        ); ?>"
                    ><?php echo esc_textarea($description); ?></textarea>
                    <div class="novamira-skills-field-help">
                        <p><?php

                        printf(
                            /* translators: %s: emphasised word "when" */
                            esc_html__('Describe %s to use this skill, not what it does or how.', domain: 'novamira'),
                            '<strong>' . esc_html__('when', domain: 'novamira') . '</strong>',
                        );
                        ?></p>
                        <ul class="novamira-skills-field-examples">
                            <li>
                                <span class="novamira-skills-example-label"><?php

                                esc_html_e('Too vague', domain: 'novamira');
                                ?></span>
                                <span class="novamira-skills-example-text"><?php esc_html_e(
                                    'Helps with content.',
                                    domain: 'novamira',
                                ); ?></span>
                            </li>
                            <li>
                                <span class="novamira-skills-example-label is-better"><?php

                                esc_html_e('Better', domain: 'novamira');
                                ?></span>
                                <span class="novamira-skills-example-text"><?php esc_html_e(
                                    'Builds a landing page from a brief, following the site\'s design system.',
                                    domain: 'novamira',
                                ); ?></span>
                            </li>
                        </ul>
                    </div>
                </div>
                <div class="novamira-skills-field">
                    <label
                        for="novamira-skills-content"
                        class="novamira-skills-field-label"
                    ><?php esc_html_e('Body', domain: 'novamira'); ?></label>
                    <div class="novamira-skills-md-toolbar" role="toolbar" aria-label="<?php

                    esc_attr_e('Markdown formatting', domain: 'novamira');
                    ?>">
                        <button type="button" data-md="bold" title="<?php

                        esc_attr_e('Bold', domain: 'novamira');
                        ?>"><strong>B</strong></button>
                        <button type="button" data-md="italic" title="<?php

                        esc_attr_e('Italic', domain: 'novamira');
                        ?>"><em>I</em></button>
                        <button type="button" data-md="heading" title="<?php

                        esc_attr_e('Heading', domain: 'novamira');
                        ?>">H</button>
                        <button type="button" data-md="list" title="<?php

                        esc_attr_e('Bulleted list', domain: 'novamira');
                        ?>">•</button>
                        <button type="button" data-md="code" title="<?php

                        esc_attr_e('Inline code', domain: 'novamira');
                        ?>"><code>&lt;/&gt;</code></button>
                        <button type="button" data-md="link" title="<?php

                        esc_attr_e('Link', domain: 'novamira');
                        ?>">🔗</button>
                    </div>
                    <div class="novamira-skills-body-wrap">
                        <textarea
                            name="content"
                            id="novamira-skills-content"
                            rows="20"
                            class="large-text code"
                        ><?php echo esc_textarea($content); ?></textarea>
                        <?php if ($is_new): ?>
                            <div
                                class="novamira-skills-body-hint"
                                data-novamira-skills-body-hint
                                aria-hidden="true"
                            >
                                <p>
                                    <span class="novamira-skills-body-hint-emoji">🤖</span>
                                    <strong><?php esc_html_e(
                                        'Pssst, you don\'t have to write this by hand.',
                                        domain: 'novamira',
                                    ); ?></strong>
                                </p>
                                <p><?php

                                printf(
                                    /* translators: %s: example natural-language prompt */
                                    esc_html__('Just say to your AI: %s', domain: 'novamira'),
                                    '<em>“'
                                    . esc_html__(
                                        'Create a Novamira skill that builds landing pages from a brief.',
                                        domain: 'novamira',
                                    )
                                    . '”</em>',
                                );
                                ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="novamira-skills-edit-sidebar">
                <h2><?php esc_html_e('Settings', domain: 'novamira'); ?></h2>
                <p class="novamira-skills-checkbox-row">
                    <label>
                        <input type="checkbox" name="enable_agentic" value="1" <?php checked($enable_agentic); ?> />
                        <strong><?php esc_html_e('AI uses it automatically', domain: 'novamira'); ?></strong>
                    </label>
                    <small class="description"><?php esc_html_e(
                        'The AI discovers this skill from its description and runs it when the task matches.',
                        domain: 'novamira',
                    ); ?></small>
                </p>
                <p class="novamira-skills-checkbox-row">
                    <label>
                        <input type="checkbox" name="enable_prompt" value="1" <?php checked($enable_prompt); ?> />
                        <strong><?php esc_html_e('You can invoke it manually', domain: 'novamira'); ?></strong>
                    </label>
                    <small class="description"><?php esc_html_e(
                        'Lets you call this skill directly from your AI client, instead of waiting for the AI to pick it.',
                        domain: 'novamira',
                    ); ?></small>
                </p>
                <div class="novamira-skills-save-row">
                    <label for="novamira-skills-status" class="screen-reader-text"><?php

                    esc_html_e('Status', domain: 'novamira');
                    ?></label>
                    <select id="novamira-skills-status" name="status">
                        <option value="publish" <?php selected($enabled, current: true); ?>><?php

                        esc_html_e('Enabled', domain: 'novamira');
                        ?></option>
                        <option value="draft" <?php selected($enabled, current: false); ?>><?php

                        esc_html_e('Disabled', domain: 'novamira');
                        ?></option>
                    </select>
                    <button type="submit" class="button button-primary"><?php esc_html_e(
                        'Save',
                        domain: 'novamira',
                    ); ?></button>
                </div>
            </div>
        </div>
    </form>
</div>
<script>
(function () {
    // Body placeholder — visible only when content is empty.
    function wireBodyHint(cm) {
        var hint = document.querySelector('[data-novamira-skills-body-hint]');
        if (!hint || !cm) {
            return;
        }
        var update = function () {
            if (cm.getValue().length > 0) {
                hint.classList.add('is-hidden');
            } else {
                hint.classList.remove('is-hidden');
            }
        };
        cm.on('change', update);
        update();
    }

    // Title normalisation (on blur + submit).
    var titleInput = document.querySelector('input[name="title"]');
    if (titleInput) {
        var normalize = function (raw) {
            return raw
                .toLowerCase()
                .replace(/\s+/g, '-')
                .replace(/[^a-z0-9_-]/g, '')
                .replace(/-+/g, '-')
                .replace(/^-|-$/g, '');
        };
        var applyTitle = function () {
            var raw = titleInput.value;
            var normalized = normalize(raw);
            if (normalized !== raw) {
                titleInput.value = normalized;
            }
        };
        titleInput.addEventListener('blur', applyTitle);
        var form = titleInput.form;
        if (form) {
            form.addEventListener('submit', applyTitle);
        }
    }

    // Markdown toolbar — wraps the current selection (or inserts a
    // template) into the CodeMirror instance exposed as
    // window.novamiraSkillsEditor.
    function wireToolbar(cm) {
        var toolbar = document.querySelector('.novamira-skills-md-toolbar');
        if (!toolbar || !cm) {
            return;
        }
        toolbar.addEventListener('click', function (event) {
            var btn = event.target.closest('button[data-md]');
            if (!btn) {
                return;
            }
            event.preventDefault();
            var action = btn.getAttribute('data-md');
            var selection = cm.getSelection();
            var hasSelection = selection.length > 0;
            var cursor = cm.getCursor();
            switch (action) {
                case 'bold':
                    cm.replaceSelection('**' + (hasSelection ? selection : 'bold text') + '**');
                    break;
                case 'italic':
                    cm.replaceSelection('*' + (hasSelection ? selection : 'italic text') + '*');
                    break;
                case 'code':
                    cm.replaceSelection('`' + (hasSelection ? selection : 'code') + '`');
                    break;
                case 'link':
                    cm.replaceSelection('[' + (hasSelection ? selection : 'text') + '](https://)');
                    break;
                case 'heading': {
                    var lineNo = cursor.line;
                    var line = cm.getLine(lineNo);
                    var match = line.match(/^(#{1,5})\s/);
                    var newLine;
                    if (match) {
                        newLine = '#'.repeat(match[1].length + 1) + ' ' + line.slice(match[0].length);
                        if (match[1].length >= 5) {
                            newLine = line.replace(/^#+\s/, '');
                        }
                    } else {
                        newLine = '# ' + line;
                    }
                    cm.replaceRange(newLine, { line: lineNo, ch: 0 }, { line: lineNo, ch: line.length });
                    break;
                }
                case 'list': {
                    var lineNo2 = cursor.line;
                    var line2 = cm.getLine(lineNo2);
                    var newLine2 = /^- /.test(line2) ? line2.slice(2) : '- ' + line2;
                    cm.replaceRange(newLine2, { line: lineNo2, ch: 0 }, { line: lineNo2, ch: line2.length });
                    break;
                }
            }
            cm.focus();
        });
    }

    function wireBodyExtras(cm) {
        wireToolbar(cm);
        wireBodyHint(cm);
    }

    if (window.novamiraSkillsEditor) {
        wireBodyExtras(window.novamiraSkillsEditor);
    } else {
        window.addEventListener('novamira-skills-editor-ready', function () {
            wireBodyExtras(window.novamiraSkillsEditor);
        });
    }
})();
</script>
