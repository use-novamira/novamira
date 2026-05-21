<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Skills\Abilities\SkillWrite;

use Novamira\Skills\Cpt;
use Novamira\Skills\Parser;
use Novamira\Skills\Sources;
use WP_Error;

if (!defined('ABSPATH')) {
    exit();
}

function register(): void
{
    if (!function_exists('wp_register_ability')) {
        return;
    }

    wp_register_ability('novamira/skill-write', [
        'label' => __('Write Skill', domain: 'novamira'),
        'description' => __(
            'Create or update a Novamira user skill. The `title` is the only identifier — it is sanitised server-side (lowercase, dash-separated) and becomes both the human label and the slug used for lookups.',
            domain: 'novamira',
        ),
        'category' => 'skill',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'content' => ['type' => 'string'],
                'enable_prompt' => ['type' => 'boolean'],
                'enable_agentic' => ['type' => 'boolean'],
                'on_conflict' => ['type' => 'string', 'enum' => ['fail', 'replace', 'rename']],
            ],
            'required' => ['title', 'description', 'content'],
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'success' => ['type' => 'boolean'],
                'slug' => ['type' => 'string'],
                'action' => ['type' => 'string', 'enum' => ['created', 'updated', 'renamed']],
            ],
            'required' => ['success'],
        ],
        'execute_callback' => __NAMESPACE__ . '\\execute',
        'permission_callback' => 'novamira_permission_callback',
        'meta' => [
            'annotations' => [
                'readonly' => false,
                'destructive' => true,
                'idempotent' => false,
            ],
            'mcp' => ['public' => true, 'type' => 'tool'],
        ],
    ]);
}

/**
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function execute(array $input): array|WP_Error
{
    $title = sanitize_title((string) ($input['title'] ?? ''));
    if ($title === '') {
        return new WP_Error('invalid_title', __(
            'Title is required and must contain at least one letter or digit (lowercase, dash-separated).',
            domain: 'novamira',
        ));
    }

    // Description is optional at write time. Skills without a description
    // exist (e.g. drafts, fresh uploads) but won't show up in the MCP
    // catalog until the user fills one in (see `Sources\discoverable()`).
    $description = trim((string) ($input['description'] ?? ''));

    $content = Parser\unescape_content((string) ($input['content'] ?? ''));
    if (strlen($content) > Parser\MAX_BODY_BYTES) {
        return new WP_Error('body_too_large', __('Body exceeds 1 MB.', domain: 'novamira'));
    }

    // Cross-source collision: external sources are read-only; we cannot
    // overwrite or rename into another plugin's namespace.
    $external_label = Sources\exists_in_external_source($title);
    if ($external_label !== null) {
        return new WP_Error(
            'slug_in_external_source',
            sprintf(
                /* translators: 1: slug, 2: source label e.g. "Pro" */
                __('Slug "%1$s" is already used by source "%2$s". Choose a different title.', domain: 'novamira'),
                $title,
                $external_label,
            ),
            ['slug' => $title, 'source' => $external_label],
        );
    }

    $on_conflict = (string) ($input['on_conflict'] ?? 'fail');
    if (!in_array($on_conflict, ['fail', 'replace', 'rename'], strict: true)) {
        $on_conflict = 'fail';
    }

    $existing = find_user_post_by_slug($title);
    $action = 'created';
    $slug = $title;

    if ($existing !== null) {
        if ($on_conflict === 'fail') {
            return new WP_Error('slug_exists', __('A skill with this title already exists.', domain: 'novamira'), [
                'slug' => $slug,
                'suggested_slug' => find_free_suffix($slug),
            ]);
        }
        if ($on_conflict === 'rename') {
            $slug = find_free_suffix($slug);
            $action = 'renamed';
            $existing = null;
        } else {
            $action = 'updated';
        }
    }

    $enable_prompt = filter_var($input['enable_prompt'] ?? true, FILTER_VALIDATE_BOOLEAN);
    $enable_agentic = filter_var($input['enable_agentic'] ?? true, FILTER_VALIDATE_BOOLEAN);

    $postarr = [
        'post_type' => Cpt\POST_TYPE,
        'post_status' => 'publish',
        'post_title' => $slug,
        'post_name' => $slug,
        'post_excerpt' => $description,
        'post_content' => $content,
    ];

    if ($existing !== null) {
        $postarr['ID'] = $existing->ID;
        // @mago-expect analysis:possibly-invalid-argument
        $post_id = wp_update_post(wp_slash($postarr), wp_error: true);
    } else {
        // @mago-expect analysis:possibly-invalid-argument
        $post_id = wp_insert_post(wp_slash($postarr), wp_error: true);
    }

    if (is_wp_error($post_id)) {
        return $post_id;
    }

    update_post_meta($post_id, Cpt\META_ENABLE_PROMPT, $enable_prompt);
    update_post_meta($post_id, Cpt\META_ENABLE_AGENTIC, $enable_agentic);

    return [
        'success' => true,
        'slug' => $slug,
        'action' => $action,
    ];
}

function find_user_post_by_slug(string $slug): ?\WP_Post
{
    /** @var list<\WP_Post> $posts */
    $posts = get_posts([
        'post_type' => Cpt\POST_TYPE,
        'post_status' => ['publish', 'draft'],
        'name' => $slug,
        'posts_per_page' => 1,
        'no_found_rows' => true,
    ]);
    return $posts[0] ?? null;
}

function find_free_suffix(string $slug): string
{
    $i = 2;
    while (
        find_user_post_by_slug($slug . '-' . $i) !== null
        || Sources\exists_in_external_source($slug . '-' . $i) !== null
    ) {
        $i++;
        if ($i > 9999) {
            return $slug . '-' . time();
        }
    }
    return $slug . '-' . $i;
}
