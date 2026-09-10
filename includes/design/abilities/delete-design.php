<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Design\Abilities\Delete;

use Novamira\Design\Abilities;
use Novamira\Design\Parser;
use Novamira\Design\Store;
use WP_Error;

if (!defined('ABSPATH')) {
    exit();
}

function register(): void
{
    if (!function_exists('wp_register_ability')) {
        return;
    }

    wp_register_ability('novamira/delete-design', [
        'label' => __('Delete Design', domain: 'novamira'),
        'description' => __(
            'Delete a saved design system by slug. If the deleted design was active, the site is left with no active design.',
            domain: 'novamira',
        ),
        'category' => Abilities\CATEGORY,
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'slug' => [
                    'type' => 'string',
                    'description' => 'Slug of the design to delete.',
                ],
            ],
            'required' => ['slug'],
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'deleted' => ['type' => 'boolean'],
                'slug' => ['type' => 'string'],
                'was_active' => ['type' => 'boolean'],
            ],
            'required' => ['deleted'],
        ],
        'execute_callback' => static function (array $input): array|WP_Error {
            $slug = Parser\normalize_slug((string) ($input['slug'] ?? ''));
            if ($slug === '') {
                return new WP_Error('missing_slug', __('A slug is required.', domain: 'novamira'));
            }

            $result = Store\delete($slug);
            if (!$result['deleted']) {
                return new WP_Error('unknown_design', __('No saved design with that slug.', domain: 'novamira'));
            }

            return [
                'deleted' => true,
                'slug' => $slug,
                'was_active' => $result['was_active'],
            ];
        },
        'permission_callback' => 'novamira_permission_callback',
        'meta' => [
            'show_in_rest' => true,
            'annotations' => [
                'readonly' => false,
                'destructive' => true,
                'idempotent' => false,
            ],
            'mcp' => ['public' => true, 'type' => 'tool'],
        ],
    ]);
}
