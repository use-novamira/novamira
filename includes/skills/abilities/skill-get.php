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
    if (!function_exists('wp_register_ability_category')) {
        return;
    }
    try {
        wp_register_ability_category('skill', [
            'label' => __('Skills', domain: 'novamira'),
            'description' => __('Manage and load Novamira skills.', domain: 'novamira'),
        ]);

        // @mago-expect lint:no-empty-catch-clause
    } catch (\Throwable) {
        // Already registered by another plugin — fine.
    }
}

namespace Novamira\Skills\Abilities\SkillGet;

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
 */
// @mago-expect lint:halstead
function register(): void
{
    if (!function_exists('wp_register_ability')) {
        return;
    }

    $previous = wp_get_ability('novamira/skill-get');
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
        'execute_callback' => static function (array $input) use ($previous): array|WP_Error {
            $agent_slug = (string) ($input['slug'] ?? '');
            if ($agent_slug === '') {
                return new WP_Error('missing_slug', __('A slug is required.', domain: 'novamira'));
            }

            $skill = Sources\find($agent_slug);
            if ($skill !== null) {
                // @mago-expect analysis:mixed-operand
                $enable_prompt = (bool) ($skill['enable_prompt'] ?? false);
                // @mago-expect analysis:mixed-operand
                $enable_agentic = (bool) ($skill['enable_agentic'] ?? true);
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

            // Fallback: an ability owner from before us may have it.
            // Generic — works for any plugin that previously registered
            // `novamira/skill-get`.
            if ($previous instanceof \WP_Ability) {
                // @mago-expect analysis:mixed-assignment
                $forwarded = $previous->execute(['slug' => $agent_slug]);
                if (!is_wp_error($forwarded)) {
                    return is_array($forwarded) ? $forwarded : ['found' => false];
                }
            }

            return ['found' => false];
        },
        'permission_callback' => 'novamira_permission_callback',
        'meta' => [
            'annotations' => [
                'readonly' => true,
                'destructive' => false,
                'idempotent' => true,
            ],
            'mcp' => ['public' => true, 'type' => 'tool'],
        ],
    ]);
}
