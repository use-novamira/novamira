<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Skills\Admin;

use Novamira\Skills\Abilities\SkillWrite;
use Novamira\Skills\Cpt;
use Novamira\Skills\Notices;
use Novamira\Skills\Parser;
use Novamira\Skills\Sources;

if (!defined('ABSPATH')) {
    exit();
}

const PAGE_SLUG = 'novamira-skills';

const CAPABILITY = 'manage_options';

/**
 * Skills lives as a submenu under the Novamira top-level menu registered
 * in `novamira.php`. The parent slug `novamira-connect` is the canonical
 * Configuration page. Skills sits directly after AI Abilities; ordering
 * is fixed in `reorder_submenu()` below because `add_submenu_page` only
 * appends, and we want a specific position rather than the tail of the
 * Novamira submenu.
 */
function register_menu(): void
{
    add_submenu_page(
        parent_slug: 'novamira-connect',
        page_title: __('Skills', domain: 'novamira'),
        menu_title: __('Skills', domain: 'novamira'),
        capability: CAPABILITY,
        menu_slug: PAGE_SLUG,
        callback: __NAMESPACE__ . '\\render_page',
    );
}

/**
 * Reposition the Skills submenu entry to sit immediately after AI
 * Abilities (slug `novamira`) inside the Novamira menu group. Runs at a
 * priority higher than any caller of `add_submenu_page` for that parent.
 */
// @mago-expect lint:no-global
function reorder_submenu(): void
{
    global $submenu;
    if (!is_array($submenu ?? null) || !is_array($submenu['novamira-connect'] ?? null)) {
        return;
    }

    /** @var array<int, array<int, string>> $entries */
    $entries = $submenu['novamira-connect'];
    $skills_entry = null;
    foreach ($entries as $key => $entry) {
        if (($entry[2] ?? null) !== PAGE_SLUG) {
            continue;
        }
        $skills_entry = $entry;
        unset($entries[$key]);
        break;
    }
    if ($skills_entry === null) {
        return;
    }

    $reordered = [];
    $inserted = false;
    foreach ($entries as $entry) {
        $reordered[] = $entry;
        if (!$inserted && ($entry[2] ?? null) === 'novamira') {
            $reordered[] = $skills_entry;
            $inserted = true;
        }
    }
    if (!$inserted) {
        $reordered[] = $skills_entry;
    }
    $submenu['novamira-connect'] = $reordered;
}

function render_page(): void
{
    if (!current_user_can(CAPABILITY)) {
        wp_die(__('You do not have permission to manage skills.', domain: 'novamira'));
    }
    $view = ($_GET['skill'] ?? null) !== null ? 'edit' : 'list';
    if ($view === 'edit') {
        require __DIR__ . '/templates/edit.php';
        return;
    }
    require __DIR__ . '/templates/list.php';
}

function register_post_handlers(): void
{
    $actions = [
        'novamira_skill_create',
        'novamira_skill_update',
        'novamira_skill_delete',
        'novamira_skill_restore',
        'novamira_skill_permanent_delete',
        'novamira_skill_toggle_status',
        'novamira_skill_toggle_activation',
        'novamira_skill_upload',
        'novamira_skill_download',
        'novamira_skill_download_all',
    ];
    foreach ($actions as $action) {
        $handler = 'handle_' . substr($action, strlen('novamira_skill_'));
        // @mago-expect analysis:less-specific-nested-argument-type
        add_action("admin_post_{$action}", __NAMESPACE__ . '\\' . $handler);
    }
}

function enqueue_assets(string $hook): void
{
    // Submenu page hook id is `<parent>_page_<slug>` where `<parent>` is
    // the sanitised menu_title of the parent top-level menu ("Novamira").
    if ($hook !== 'novamira_page_' . PAGE_SLUG) {
        return;
    }
    wp_enqueue_style(
        'novamira-skills-admin',
        (string) NOVAMIRA_PLUGIN_URL . 'includes/skills/assets/admin.css',
        [],
        NOVAMIRA_VERSION,
    );

    // CodeMirror with markdown syntax highlighting on the body textarea.
    // Only on the edit view — list page has no editor.
    if (($_GET['skill'] ?? null) === null) {
        return;
    }
    $settings = wp_enqueue_code_editor(['type' => 'text/markdown']);
    if ($settings === false) {
        return;
    }
    wp_add_inline_script('code-editor', sprintf(
        'jQuery(function($){ var el = document.getElementById("novamira-skills-content"); if (el && window.wp && wp.codeEditor) { var inst = wp.codeEditor.initialize(el, %s); window.novamiraSkillsEditor = inst && inst.codemirror ? inst.codemirror : null; window.dispatchEvent(new CustomEvent("novamira-skills-editor-ready")); } });',
        (string) wp_json_encode($settings),
    ));
}

function handle_create(): void
{
    require_capability_and_nonce('novamira_skill_create');
    $input = collect_input_from_post();
    $result = SkillWrite\execute($input + ['on_conflict' => 'fail']);
    if (is_wp_error($result)) {
        redirect_with_notice('error', $result->get_error_message());
        return;
    }
    Notices\set_pending_reload_notice();

    $slug = (string) $result['slug'];
    $post = SkillWrite\find_user_post_by_slug($slug);
    $args = ['page' => PAGE_SLUG, 'updated' => '1'];
    if ($post !== null) {
        $args['skill'] = $post->ID;
    }
    wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
    exit();
}

function handle_update(): void
{
    $post_id = read_post_id();
    require_capability_and_nonce('novamira_skill_update_' . $post_id);
    $post = load_skill_post($post_id);
    $input = collect_input_from_post();

    $new_title = sanitize_title($input['title']);
    if ($new_title === '') {
        redirect_with_notice(
            'error',
            __('Title must contain at least one letter or digit (lowercase, dash-separated).', domain: 'novamira'),
            $post_id,
        );
        return;
    }

    if (trim($input['description']) === '') {
        redirect_with_notice('error', __('Description is required.', domain: 'novamira'), $post_id);
        return;
    }

    $content = Parser\unescape_content($input['content']);
    if (strlen($content) > Parser\MAX_BODY_BYTES) {
        redirect_with_notice('error', __('Body exceeds 1 MB.', domain: 'novamira'), $post_id);
        return;
    }

    // Renaming check: if title changed, ensure no collision with external
    // sources or with another user-cpt post.
    if ($new_title !== $post->post_name) {
        $external = Sources\exists_in_external_source($new_title);
        if ($external !== null) {
            redirect_with_notice(
                'error',
                sprintf(
                    /* translators: 1: slug, 2: source label */
                    __('Title "%1$s" is already used by source "%2$s". Choose a different title.', domain: 'novamira'),
                    $new_title,
                    $external,
                ),
                $post_id,
            );
            return;
        }
        $clash = SkillWrite\find_user_post_by_slug($new_title);
        if ($clash !== null && (int) $clash->ID !== $post_id) {
            redirect_with_notice('error', __('A skill with this title already exists.', domain: 'novamira'), $post_id);
            return;
        }
    }

    $status_raw = $_POST['status'] ?? 'publish';
    $status = is_string($status_raw) && $status_raw === 'draft' ? 'draft' : 'publish';

    wp_update_post([
        'ID' => $post_id,
        'post_title' => $new_title,
        'post_name' => $new_title,
        'post_excerpt' => $input['description'],
        'post_content' => $content,
        'post_status' => $status,
    ]);
    update_post_meta($post_id, Cpt\META_ENABLE_PROMPT, $input['enable_prompt']);
    update_post_meta($post_id, Cpt\META_ENABLE_AGENTIC, $input['enable_agentic']);

    Notices\set_pending_reload_notice();
    wp_safe_redirect(add_query_arg([
        'page' => PAGE_SLUG,
        'skill' => $post_id,
        'updated' => '1',
    ], admin_url('admin.php')));
    exit();
}

function handle_delete(): void
{
    $post_id = read_post_id();
    require_capability_and_nonce('novamira_skill_delete_' . $post_id);
    wp_trash_post($post_id);
    Notices\set_pending_reload_notice();
    wp_safe_redirect(add_query_arg(['page' => PAGE_SLUG, 'trashed' => '1'], admin_url('admin.php')));
    exit();
}

function handle_restore(): void
{
    $post_id = read_post_id();
    require_capability_and_nonce('novamira_skill_restore_' . $post_id);
    wp_untrash_post($post_id);
    Notices\set_pending_reload_notice();
    wp_safe_redirect(add_query_arg(['page' => PAGE_SLUG, 'restored' => '1'], admin_url('admin.php')));
    exit();
}

function handle_permanent_delete(): void
{
    $post_id = read_post_id();
    require_capability_and_nonce('novamira_skill_permanent_delete_' . $post_id);
    wp_delete_post($post_id, force_delete: true);
    Notices\set_pending_reload_notice();
    wp_safe_redirect(add_query_arg(['page' => PAGE_SLUG, 'deleted' => '1'], admin_url('admin.php')));
    exit();
}

function handle_toggle_status(): void
{
    $post_id = read_post_id();
    require_capability_and_nonce('novamira_skill_toggle_status_' . $post_id);
    $post = load_skill_post($post_id);
    $new_status = $post->post_status === 'publish' ? 'draft' : 'publish';
    wp_update_post(['ID' => $post_id, 'post_status' => $new_status]);
    Notices\set_pending_reload_notice();
    wp_safe_redirect(add_query_arg(['page' => PAGE_SLUG, 'updated' => '1'], admin_url('admin.php')));
    exit();
}

function handle_toggle_activation(): void
{
    $post_id = read_post_id();
    $field_raw = $_POST['field'] ?? '';
    $field = is_string($field_raw) ? $field_raw : '';
    require_capability_and_nonce('novamira_skill_toggle_activation_' . $post_id);
    if (!in_array($field, ['enable_prompt', 'enable_agentic'], strict: true)) {
        wp_die(__('Invalid field.', domain: 'novamira'));
    }
    $meta_key = $field === 'enable_prompt' ? Cpt\META_ENABLE_PROMPT : Cpt\META_ENABLE_AGENTIC;
    // @mago-expect analysis:mixed-operand
    $current = (bool) get_post_meta($post_id, $meta_key, single: true);
    update_post_meta($post_id, $meta_key, !$current);
    Notices\set_pending_reload_notice();
    wp_safe_redirect(add_query_arg(['page' => PAGE_SLUG, 'updated' => '1'], admin_url('admin.php')));
    exit();
}

const MAX_UPLOAD_FILES = 20;

function handle_upload(): void
{
    require_capability_and_nonce('novamira_skill_upload');

    $files = normalize_uploaded_files($_FILES['skill_file'] ?? null);
    if ($files === []) {
        redirect_with_notice('error', __('No files uploaded.', domain: 'novamira'));
        return;
    }
    if (count($files) > MAX_UPLOAD_FILES) {
        redirect_with_notice('error', sprintf(
            /* translators: %d: maximum number of files allowed per upload */
            __('Too many files. Upload up to %d skills at a time.', domain: 'novamira'),
            MAX_UPLOAD_FILES,
        ));
        return;
    }

    $on_conflict_raw = $_POST['on_conflict'] ?? null;
    $on_conflict = is_string($on_conflict_raw) ? $on_conflict_raw : 'fail';

    $imported = [];
    $failed = [];
    foreach ($files as $file) {
        $result = process_one_upload($file, $on_conflict);
        if (is_wp_error($result)) {
            $failed[] = sprintf('“%s” (%s)', $file['name'], $result->get_error_message());
            continue;
        }

        $imported[] = $result;
    }

    if ($imported !== []) {
        Notices\set_pending_reload_notice();

        // Track freshly imported post IDs so the list view can briefly
        // highlight the new rows (transient, 30s).
        $imported_ids = [];
        foreach ($imported as $slug) {
            $p = SkillWrite\find_user_post_by_slug($slug);
            if ($p !== null) {
                $imported_ids[] = (int) $p->ID;
            }
        }
        if ($imported_ids !== []) {
            set_transient('novamira_skill_just_imported_' . get_current_user_id(), $imported_ids, expiration: 30);
        }
    }

    $type = match (true) {
        $imported === [] => 'error',
        $failed === [] => 'success',
        default => 'info',
    };
    redirect_with_notice($type, build_upload_notice($imported, $failed), skill_id: 0, extra_args: [
        'imported' => (string) count($imported),
    ]);
}

/**
 * @param array{name: string, tmp_name: string, error: int, size: int} $file
 * @return string|\WP_Error slug on success, error otherwise
 */
function process_one_upload(array $file, string $on_conflict): string|\WP_Error
{
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return new \WP_Error('upload_error', __('upload failed', domain: 'novamira'));
    }
    if (!str_ends_with(strtolower($file['name']), '.md')) {
        return new \WP_Error('not_md', __('not a .md file', domain: 'novamira'));
    }
    if ($file['size'] > Parser\MAX_BODY_BYTES) {
        return new \WP_Error('too_large', __('too large (max 1 MB)', domain: 'novamira'));
    }
    if ($file['tmp_name'] === '' || !is_readable($file['tmp_name'])) {
        return new \WP_Error('unreadable', __('could not be read', domain: 'novamira'));
    }
    $raw = file_get_contents($file['tmp_name']);
    if ($raw === false) {
        return new \WP_Error('unreadable', __('could not be read', domain: 'novamira'));
    }

    $parsed = Parser\parse($raw);
    if ($parsed['parse_error'] !== null) {
        return new \WP_Error('parse', $parsed['parse_error']);
    }

    $title_seed = $parsed['name'] !== '' ? $parsed['name'] : basename($file['name'], suffix: '.md');
    $result = SkillWrite\execute([
        'title' => $title_seed,
        'description' => $parsed['description'],
        'content' => $parsed['body'],
        'enable_prompt' => $parsed['enable_prompt'],
        'enable_agentic' => $parsed['enable_agentic'],
        'on_conflict' => $on_conflict,
    ]);
    if (is_wp_error($result)) {
        return $result;
    }
    return (string) $result['slug'];
}

/**
 * Normalize PHP's $_FILES shape (which differs for `name="x"` vs `name="x[]"`)
 * into a flat list of per-file rows.
 *
 * @return list<array{name: string, tmp_name: string, error: int, size: int}>
 */
// @mago-expect lint:cyclomatic-complexity
// @mago-expect analysis:mixed-assignment
function normalize_uploaded_files(mixed $raw): array
{
    if (!is_array($raw) || !array_key_exists('name', $raw)) {
        return [];
    }
    $names = is_array($raw['name']) ? $raw['name'] : [$raw['name']];
    $tmps = is_array($raw['tmp_name'] ?? null) ? $raw['tmp_name'] : [$raw['tmp_name'] ?? ''];
    $errors = is_array($raw['error'] ?? null) ? $raw['error'] : [$raw['error'] ?? UPLOAD_ERR_NO_FILE];
    $sizes = is_array($raw['size'] ?? null) ? $raw['size'] : [$raw['size'] ?? 0];

    $out = [];
    foreach ($names as $i => $name) {
        $err = (int) ($errors[$i] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $out[] = [
            'name' => is_string($name) ? $name : '',
            'tmp_name' => is_string($tmps[$i] ?? null) ? $tmps[$i] : '',
            'error' => $err,
            'size' => (int) ($sizes[$i] ?? 0),
        ];
    }
    return $out;
}

/**
 * @param list<string> $imported  Slugs of successfully imported skills.
 * @param list<string> $failed    Pre-formatted "<name> (<reason>)" strings.
 */
function build_upload_notice(array $imported, array $failed): string
{
    if ($imported === [] && $failed !== []) {
        return sprintf(
            /* translators: %s: list of failed files */
            __('Upload failed: %s', domain: 'novamira'),
            implode(', ', $failed),
        );
    }
    $head = sprintf(
        /* translators: %d: count */
        _n('%d skill imported.', plural: '%d skills imported.', number: count($imported), domain: 'novamira'),
        count($imported),
    );
    if ($failed === []) {
        return $head;
    }
    return sprintf(
        /* translators: 1: import count message, 2: list of failed files */
        __('%1$s Failed: %2$s', domain: 'novamira'),
        $head,
        implode(', ', $failed),
    );
}

function handle_download(): void
{
    $post_id = read_post_id();
    require_capability_and_nonce('novamira_skill_download_' . $post_id);
    $post = load_skill_post($post_id);

    // @mago-expect analysis:mixed-operand
    $enable_prompt = (bool) get_post_meta($post_id, Cpt\META_ENABLE_PROMPT, single: true);
    // @mago-expect analysis:mixed-operand
    $enable_agentic = (bool) get_post_meta($post_id, Cpt\META_ENABLE_AGENTIC, single: true);

    $slug = $post->post_name !== '' ? $post->post_name : 'skill';
    $body = Parser\render_skill_md([
        'slug' => $slug,
        'description' => $post->post_excerpt,
        'enable_prompt' => $enable_prompt,
        'enable_agentic' => $enable_agentic,
        'content' => $post->post_content,
    ]);

    nocache_headers();
    header('Content-Type: text/markdown; charset=utf-8');
    header(sprintf('Content-Disposition: attachment; filename="%s.md"', $slug));
    header('Content-Length: ' . strlen($body));
    echo $body; // phpcs:ignore WordPress.Security.EscapeOutput
    exit();
}

function handle_download_all(): void
{
    require_capability_and_nonce('novamira_skill_download_all');

    if (!class_exists(\ZipArchive::class)) {
        redirect_with_notice('error', __(
            'Cannot build a ZIP. The PHP "zip" extension is not installed on this server.',
            domain: 'novamira',
        ));
        return;
    }

    /** @var list<\WP_Post> $posts */
    $posts = get_posts([
        'post_type' => Cpt\POST_TYPE,
        'post_status' => ['publish', 'draft'],
        'posts_per_page' => -1,
        'orderby' => 'title',
        'order' => 'ASC',
    ]);
    if ($posts === []) {
        redirect_with_notice('error', __('No skills to download.', domain: 'novamira'));
        return;
    }

    $tmp = wp_tempnam('novamira-skills-' . time() . '.zip');
    if ($tmp === '') {
        redirect_with_notice('error', __('Could not create temporary ZIP file.', domain: 'novamira'));
        return;
    }

    $zip = new \ZipArchive();
    if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
        wp_delete_file($tmp);
        redirect_with_notice('error', __('Could not open the ZIP file for writing.', domain: 'novamira'));
        return;
    }

    foreach ($posts as $post) {
        // @mago-expect analysis:mixed-operand
        $enable_prompt = (bool) get_post_meta($post->ID, Cpt\META_ENABLE_PROMPT, single: true);
        // @mago-expect analysis:mixed-operand
        $enable_agentic = (bool) get_post_meta($post->ID, Cpt\META_ENABLE_AGENTIC, single: true);
        $slug = $post->post_name !== '' ? $post->post_name : 'skill-' . $post->ID;
        $md = Parser\render_skill_md([
            'slug' => $slug,
            'description' => $post->post_excerpt,
            'enable_prompt' => $enable_prompt,
            'enable_agentic' => $enable_agentic,
            'content' => $post->post_content,
        ]);
        $zip->addFromString($slug . '.md', $md);
    }
    $zip->close();

    $raw = file_get_contents($tmp);
    wp_delete_file($tmp);
    if ($raw === false) {
        redirect_with_notice('error', __('Could not read the generated ZIP.', domain: 'novamira'));
        return;
    }

    $filename = sprintf('novamira-skills-%s.zip', wp_date('Y-m-d'));
    nocache_headers();
    header('Content-Type: application/zip');
    header(sprintf('Content-Disposition: attachment; filename="%s"', $filename));
    header('Content-Length: ' . strlen($raw));
    echo $raw; // phpcs:ignore WordPress.Security.EscapeOutput
    exit();
}

function require_capability_and_nonce(string $nonce_action): void
{
    if (!current_user_can(CAPABILITY)) {
        wp_die(__('Not allowed.', domain: 'novamira'), title: '', args: ['response' => 403]);
    }
    check_admin_referer($nonce_action);
}

function load_skill_post(int $post_id): \WP_Post
{
    // @mago-expect analysis:mixed-assignment
    $maybe_post = get_post($post_id);
    if (!$maybe_post instanceof \WP_Post || $maybe_post->post_type !== Cpt\POST_TYPE) {
        wp_die(__('Skill not found.', domain: 'novamira'));
    }
    /** @var \WP_Post $maybe_post */
    return $maybe_post;
}

function read_post_id(): int
{
    $raw = $_POST['post_id'] ?? $_GET['post_id'] ?? null;
    return is_scalar($raw) ? (int) $raw : 0;
}

/**
 * @return array{title: string, description: string, content: string, enable_prompt: bool, enable_agentic: bool}
 */
function collect_input_from_post(): array
{
    $title_raw = $_POST['title'] ?? '';
    $description_raw = $_POST['description'] ?? '';
    $content_raw = $_POST['content'] ?? '';
    return [
        'title' => is_string($title_raw) ? wp_unslash($title_raw) : '',
        'description' => is_string($description_raw) ? wp_unslash($description_raw) : '',
        'content' => is_string($content_raw) ? wp_unslash($content_raw) : '',
        'enable_prompt' => ($_POST['enable_prompt'] ?? null) !== null,
        'enable_agentic' => ($_POST['enable_agentic'] ?? null) !== null,
    ];
}

/**
 * @param array<string, string> $extra_args Additional query args merged into the redirect URL.
 */
function redirect_with_notice(string $type, string $message, int $skill_id = 0, array $extra_args = []): void
{
    set_transient(
        'novamira_skill_admin_notice_' . get_current_user_id(),
        ['type' => $type, 'message' => $message],
        expiration: 30,
    );
    $args = array_merge(['page' => PAGE_SLUG], $extra_args);
    if ($skill_id > 0) {
        $args['skill'] = $skill_id;
    }
    wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
    exit();
}
