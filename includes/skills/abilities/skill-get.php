<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Skills\Abilities;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Register the shared `skill` ability category. Other plugins may have
 * registered it first; we tolerate that (idempotent via the try/catch).
 */
function register_categories(): void
{
    if (!function_exists('wp_register_ability_category') || wp_has_ability_category('skill')) {
        return;
    }

    wp_register_ability_category('skill', [
        'label' => __('Skills', domain: 'novamira'),
        'description' => __('Manage and load Novamira skills.', domain: 'novamira'),
    ]);
}

namespace Novamira\Skills\Abilities\SkillGet;

use Novamira\Features;
use Novamira\Skills\Parser;
use Novamira\Skills\Sources;
use WP_Error;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Take canonical ownership of `novamira/skill-get`. Registered at
 * `wp_abilities_api_init` priority 999 so any other plugin (e.g., a
 * pre-update Pro plugin) has already had a chance to register first.
 * The previous owner — whatever plugin it was — is captured here and
 * used as a fallback for slugs our sources do not know about.
 *
 * After enough downstream plugins migrate to the source-filter
 * (`novamira_skill_lookup_sources`), the fallback path stops firing
 * naturally. Keeping the wrapper costs nothing.
 *
 * This intentionally keeps the full input/output JSON schema next to the
 * `wp_register_ability()` call so the registration remains easy to audit.
 */
function register(): void
{
    if (!function_exists('wp_register_ability')) {
        return;
    }

    $previous = wp_has_ability('novamira/skill-get') ? wp_get_ability('novamira/skill-get') : null;
    if ($previous instanceof \WP_Ability) {
        wp_unregister_ability('novamira/skill-get');
    }

    wp_register_ability('novamira/skill-get', [
        'label' => __('Get Skill', domain: 'novamira'),
        'description' => __(
            'Load a Novamira skill by slug. Returns the full SKILL.md content plus metadata.',
            domain: 'novamira',
        ),
        'category' => 'skill',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'slug' => [
                    'type' => 'string',
                    'description' => 'The slug of the skill to load.',
                ],
            ],
            'required' => ['slug'],
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'found' => ['type' => 'boolean'],
                'slug' => ['type' => 'string'],
                'name' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'content' => ['type' => 'string'],
                'enable_prompt' => ['type' => 'boolean'],
                'enable_agentic' => ['type' => 'boolean'],
                'source' => ['type' => 'string'],
            ],
            'required' => ['found'],
        ],
        'execute_callback' => static fn(array $input): array|WP_Error => execute_skill_get($input, $previous),
        'permission_callback' => 'novamira_permission_callback',
        'meta' => [
            'show_in_rest' => true,
            'annotations' => [
                'readonly' => true,
                'destructive' => false,
                'idempotent' => true,
            ],
            'mcp' => ['public' => true, 'type' => 'tool'],
        ],
    ]);
}

function execute_skill_get(array $input, ?\WP_Ability $previous): array|WP_Error
{
    $agent_slug = normalize_requested_slug((string) ($input['slug'] ?? ''));
    if ($agent_slug === '') {
        return new WP_Error('missing_slug', __('A slug is required.', domain: 'novamira'));
    }

    $skill = Sources\find($agent_slug);
    if ($skill !== null) {
        $features = Features\features();
        $managers = $features->features_for_skill($agent_slug);
        if ($managers !== [] && !$features->is_skill_active($agent_slug)) {
            return feature_disabled_error($agent_slug, array_map(
                static fn(Features\Definition $feature): string => $feature->label(),
                $managers,
            ));
        }
        return format_skill_response($skill);
    }

    return forward_to_previous_skill_get($agent_slug, $previous);
}

/** @param list<string> $featureLabels */
function feature_disabled_error(string $slug, array $featureLabels): WP_Error
{
    return new WP_Error('skill_feature_disabled', sprintf(
        /* translators: 1: skill slug, 2: comma-separated feature labels */
        __(
            'Skill "%1$s" is managed by inactive features (%2$s). Ask the site admin to enable one under Novamira → Features.',
            domain: 'novamira',
        ),
        $slug,
        implode(', ', $featureLabels),
    ));
}

/**
 * @param array<string, mixed> $skill
 * @return array<string, mixed>
 */
function format_skill_response(array $skill): array
{
    $enable_prompt = boolval($skill['enable_prompt'] ?? false);
    $enable_agentic = boolval($skill['enable_agentic'] ?? true);

    return [
        'found' => true,
        'slug' => (string) $skill['slug'],
        'name' => (string) ($skill['name'] ?? $skill['slug']),
        'description' => (string) ($skill['description'] ?? ''),
        'content' => Parser\render_skill_md([
            'slug' => (string) $skill['slug'],
            'description' => (string) ($skill['description'] ?? ''),
            'content' => (string) ($skill['content'] ?? ''),
            'enable_prompt' => $enable_prompt,
            'enable_agentic' => $enable_agentic,
        ]),
        'enable_prompt' => $enable_prompt,
        'enable_agentic' => $enable_agentic,
        'source' => (string) ($skill['source'] ?? 'user-cpt'),
    ];
}

/**
 * @return array<array-key, mixed>
 */
function forward_to_previous_skill_get(string $agent_slug, ?\WP_Ability $previous): array
{
    if (!$previous instanceof \WP_Ability) {
        return ['found' => false];
    }

    /** @var mixed $forwarded */
    $forwarded = $previous->execute(['slug' => $agent_slug]);
    if (is_wp_error($forwarded)) {
        return ['found' => false];
    }

    return is_array($forwarded) ? $forwarded : ['found' => false];
}

function normalize_requested_slug(string $slug): string
{
    $normalized = trim($slug);
    if (str_starts_with($normalized, 'novamira/')) {
        return substr($normalized, strlen('novamira/'));
    }

    return $normalized;
}
