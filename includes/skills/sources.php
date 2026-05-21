<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Skills\Sources;

use Novamira\Skills\Cpt;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Catalog priority for the built-in user-cpt source. Higher numbers render
 * later, so user skills appear below contributor-registered sources that
 * choose a lower priority (e.g. 5 or 10).
 */
const USER_CPT_PRIORITY = 50;

/**
 * Aggregated, priority-sorted list of skill sources.
 *
 * Each entry's loader must return a list of skill records of the shape:
 *   array{slug: string, name: string, description: string, content: string,
 *         enable_prompt?: bool, enable_agentic?: bool}
 *
 * @return list<array{id: string, priority: int, label: string, loader: callable(): list<array<string,mixed>>}>
 */
function registry(): array
{
    $default = [
        'user-cpt' => [
            'id' => 'user-cpt',
            'priority' => USER_CPT_PRIORITY,
            'label' => 'User',
            'loader' => __NAMESPACE__ . '\\load_user_cpt',
        ],
    ];

    /** @var array<string, array{id: string, priority: int, label: string, loader: callable(): list<array<string,mixed>>}> $sources */
    $sources = apply_filters('novamira_skill_lookup_sources', $default);

    $list = array_values($sources);
    usort($list, static fn(array $a, array $b): int => $a['priority'] <=> $b['priority']);
    return $list;
}

/**
 * Loader for the built-in user-cpt source. Returns only `publish` skills
 * (drafts are admin-only and never reach the agent). Skills whose
 * `post_name` is empty (malformed title) are filtered out — the agent
 * never sees them; the admin UI still surfaces a warning.
 *
 * @return list<array{slug: string, name: string, description: string, content: string, enable_prompt: bool, enable_agentic: bool}>
 */
function load_user_cpt(): array
{
    /** @var list<\WP_Post> $posts */
    $posts = get_posts([
        'post_type' => Cpt\POST_TYPE,
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'orderby' => 'title',
        'order' => 'ASC',
        'no_found_rows' => true,
    ]);

    $result = [];
    foreach ($posts as $post) {
        $slug = $post->post_name;
        if ($slug === '') {
            continue;
        }
        $result[] = [
            'slug' => $slug,
            'name' => $post->post_title,
            'description' => $post->post_excerpt,
            'content' => $post->post_content,
            // @mago-expect analysis:mixed-operand
            'enable_prompt' => (bool) get_post_meta($post->ID, Cpt\META_ENABLE_PROMPT, single: true),
            // @mago-expect analysis:mixed-operand
            'enable_agentic' => (bool) get_post_meta($post->ID, Cpt\META_ENABLE_AGENTIC, single: true),
        ];
    }
    return $result;
}

/**
 * Find a skill by its bare slug across every registered source, in
 * priority order. Returns the record annotated with `source` (filter id)
 * and `source_label` (display badge), or null if no source has it.
 *
 * @return array<string,mixed>|null
 */
function find(string $slug): ?array
{
    foreach (registry() as $entry) {
        foreach ($entry['loader']() as $skill) {
            if (($skill['slug'] ?? '') !== $slug) {
                continue;
            }
            $skill['source'] = $entry['id'];
            $skill['source_label'] = $entry['label'];
            return $skill;
        }
    }
    return null;
}

/**
 * Return every skill from every source, priority-ordered. Each entry is
 * annotated with `source` (filter id) and `source_label` (display badge).
 *
 * @return list<array<string,mixed>>
 */
function all(): array
{
    $result = [];
    foreach (registry() as $entry) {
        foreach ($entry['loader']() as $skill) {
            $skill['source'] = $entry['id'];
            $skill['source_label'] = $entry['label'];
            $result[] = $skill;
        }
    }
    return $result;
}

/**
 * Return every skill where `description` is non-empty and the requested
 * activation flag is on. Used by the catalog renderer and the prompt
 * registrar — both skip skills that lack discovery context.
 *
 * Default semantics differ per mode:
 *   - `enable_agentic` defaults to TRUE when a source does not set it
 *     (catalog discovery is the primary mode).
 *   - `enable_prompt` defaults to FALSE when a source does not set it
 *     (prompt mode is opt-in).
 *
 * @param 'agentic'|'prompt' $mode
 * @return list<array<string,mixed>>
 */
function discoverable(string $mode): array
{
    $key = $mode === 'agentic' ? 'enable_agentic' : 'enable_prompt';
    $default = $mode === 'agentic';
    $result = [];
    foreach (all() as $skill) {
        if (trim((string) ($skill['description'] ?? '')) === '') {
            continue;
        }
        if (trim((string) ($skill['content'] ?? '')) === '') {
            continue;
        }
        if (!($skill[$key] ?? $default)) {
            continue;
        }
        $result[] = $skill;
    }
    return $result;
}

/**
 * Whether a given slug already exists on any source other than user-cpt.
 * Used by skill-write to prevent users from shadowing skills provided by
 * another source. Returns the source label on collision, null otherwise.
 */
function exists_in_external_source(string $slug): ?string
{
    foreach (registry() as $entry) {
        if ($entry['id'] === 'user-cpt') {
            continue;
        }
        foreach ($entry['loader']() as $skill) {
            if (($skill['slug'] ?? '') === $slug) {
                return $entry['label'];
            }
        }
    }
    return null;
}
