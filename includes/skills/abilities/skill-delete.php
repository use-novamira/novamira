<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Skills\Abilities\SkillDelete;

use Novamira\Skills\Abilities\SkillWrite;
use WP_Error;

if (!defined('ABSPATH')) {
    exit();
}

function register(): void
{
    if (!function_exists('wp_register_ability')) {
        return;
    }

    wp_register_ability('novamira/skill-delete', [
        'label' => __('Delete Skill', domain: 'novamira'),
        'description' => __(
            'Move a Novamira user skill to trash. Pass `permanent=true` to skip trash and delete immediately.',
            domain: 'novamira',
        ),
        'category' => 'skill',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'slug' => ['type' => 'string'],
                'permanent' => ['type' => 'boolean'],
            ],
            'required' => ['slug'],
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'success' => ['type' => 'boolean'],
                'deleted' => ['type' => 'boolean'],
                'trashed' => ['type' => 'boolean'],
                'reason' => ['type' => 'string'],
            ],
            'required' => ['success'],
        ],
        'execute_callback' => static function (array $input): array|WP_Error {
            $slug = (string) ($input['slug'] ?? '');
            if ($slug === '') {
                return new WP_Error('missing_slug', __('A slug is required.', domain: 'novamira'));
            }
            // Only user-cpt skills can be deleted. If the slug exists in
            // an external source, refuse cleanly without raising an error.
            $post = SkillWrite\find_user_post_by_slug($slug);
            if ($post === null) {
                return [
                    'success' => true,
                    'deleted' => false,
                    'trashed' => false,
                    'reason' => 'not_user_managed_or_not_found',
                ];
            }
            $permanent = filter_var($input['permanent'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if ($permanent) {
                $result = wp_delete_post($post->ID, force_delete: true);
                return [
                    'success' => (bool) $result,
                    'deleted' => (bool) $result,
                    'trashed' => false,
                ];
            }
            $result = wp_trash_post($post->ID);
            return [
                'success' => $result !== false && $result !== null,
                'deleted' => false,
                'trashed' => $result !== false && $result !== null,
            ];
        },
        'permission_callback' => 'novamira_permission_callback',
        'meta' => [
            'annotations' => [
                'readonly' => false,
                'destructive' => true,
                'idempotent' => true,
            ],
            'mcp' => ['public' => true, 'type' => 'tool'],
        ],
    ]);
}
