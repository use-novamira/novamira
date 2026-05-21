<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Skills\Abilities\SkillEdit;

use Novamira\Skills\Abilities\SkillWrite;
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

    wp_register_ability('novamira/skill-edit', [
        'label' => __('Edit Skill', domain: 'novamira'),
        'description' => __(
            'Update one or more fields on an existing user skill. Only the fields you pass are touched; everything else is preserved.',
            domain: 'novamira',
        ),
        'category' => 'skill',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'slug' => ['type' => 'string'],
                'title' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'content' => ['type' => 'string'],
                'enable_prompt' => ['type' => 'boolean'],
                'enable_agentic' => ['type' => 'boolean'],
                'enabled' => ['type' => 'boolean'],
            ],
            'required' => ['slug'],
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'success' => ['type' => 'boolean'],
                'slug' => ['type' => 'string'],
                'changed_fields' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['success'],
        ],
        'execute_callback' => __NAMESPACE__ . '\\execute',
        'permission_callback' => 'novamira_permission_callback',
        'meta' => [
            'annotations' => [
                'readonly' => false,
                'destructive' => false,
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
// @mago-expect lint:cyclomatic-complexity
// @mago-expect lint:halstead
function execute(array $input): array|WP_Error
{
    $slug = (string) ($input['slug'] ?? '');
    if ($slug === '') {
        return new WP_Error('missing_slug', __('A slug is required.', domain: 'novamira'));
    }

    $post = SkillWrite\find_user_post_by_slug($slug);
    if ($post === null) {
        return new WP_Error('not_found', __(
            'Skill not found. Only user-authored skills can be edited.',
            domain: 'novamira',
        ));
    }

    $changed = [];
    $current_slug = $slug;

    if (array_key_exists('title', $input)) {
        $new_title = sanitize_title((string) $input['title']);
        if ($new_title === '') {
            return new WP_Error('invalid_title', __(
                'Title must contain at least one letter or digit (lowercase, dash-separated).',
                domain: 'novamira',
            ));
        }
        if ($new_title !== $post->post_name) {
            // Collision check before renaming.
            $external = Sources\exists_in_external_source($new_title);
            if ($external !== null) {
                return new WP_Error('slug_in_external_source', sprintf(
                    /* translators: 1: slug, 2: source label */
                    __('Title "%1$s" is already used by source "%2$s". Choose a different title.', domain: 'novamira'),
                    $new_title,
                    $external,
                ));
            }
            $clash = SkillWrite\find_user_post_by_slug($new_title);
            if ($clash !== null && (int) $clash->ID !== (int) $post->ID) {
                return new WP_Error('slug_exists', __('A skill with this title already exists.', domain: 'novamira'));
            }
            wp_update_post([
                'ID' => $post->ID,
                'post_title' => $new_title,
                'post_name' => $new_title,
            ]);
            $changed[] = 'title';
            $current_slug = $new_title;
        }
    }

    if (array_key_exists('description', $input)) {
        $new_description = trim((string) $input['description']);
        if ($new_description === '') {
            return new WP_Error('missing_description', __('Description cannot be empty.', domain: 'novamira'));
        }
        if ($new_description !== $post->post_excerpt) {
            wp_update_post(['ID' => $post->ID, 'post_excerpt' => $new_description]);
            $changed[] = 'description';
        }
    }

    if (array_key_exists('content', $input)) {
        $new_content = Parser\unescape_content((string) $input['content']);
        if (strlen($new_content) > Parser\MAX_BODY_BYTES) {
            return new WP_Error('body_too_large', __('Body exceeds 1 MB.', domain: 'novamira'));
        }
        if ($new_content !== $post->post_content) {
            wp_update_post(['ID' => $post->ID, 'post_content' => $new_content]);
            $changed[] = 'content';
        }
    }

    if (array_key_exists('enable_prompt', $input)) {
        // @mago-expect analysis:mixed-operand
        $new = (bool) $input['enable_prompt'];
        // @mago-expect analysis:mixed-operand
        $current = (bool) get_post_meta($post->ID, Cpt\META_ENABLE_PROMPT, single: true);
        if ($new !== $current) {
            update_post_meta($post->ID, Cpt\META_ENABLE_PROMPT, $new);
            $changed[] = 'enable_prompt';
        }
    }

    if (array_key_exists('enable_agentic', $input)) {
        // @mago-expect analysis:mixed-operand
        $new = (bool) $input['enable_agentic'];
        // @mago-expect analysis:mixed-operand
        $current = (bool) get_post_meta($post->ID, Cpt\META_ENABLE_AGENTIC, single: true);
        if ($new !== $current) {
            update_post_meta($post->ID, Cpt\META_ENABLE_AGENTIC, $new);
            $changed[] = 'enable_agentic';
        }
    }

    if (array_key_exists('enabled', $input)) {
        // @mago-expect analysis:mixed-operand
        $new_status = (bool) $input['enabled'] ? 'publish' : 'draft';
        if ($new_status !== $post->post_status) {
            wp_update_post(['ID' => $post->ID, 'post_status' => $new_status]);
            $changed[] = 'enabled';
        }
    }

    return [
        'success' => true,
        'slug' => $current_slug,
        'changed_fields' => $changed,
    ];
}
