<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Design\Abilities\Activate;

use Novamira\Design\Abilities;
use Novamira\Design\Contract;
use Novamira\Design\Library;
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

    wp_register_ability('novamira/activate-design', [
        'label' => __('Activate Design', domain: 'novamira'),
        'description' => __(
            'Make a ready design system (by slug) active for this site. Incomplete saved drafts are rejected until their required colors and typography are repaired. The response carries the readiness of the activated design, including any warnings.',
            domain: 'novamira',
        ),
        'category' => Abilities\CATEGORY,
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'slug' => [
                    'type' => 'string',
                    'description' => 'The slug of the design to activate.',
                ],
            ],
            'required' => ['slug'],
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'activated' => ['type' => 'boolean'],
                'slug' => ['type' => 'string'],
                'readiness' => Contract\ability_output_properties()['readiness'],
            ],
            'required' => ['activated'],
        ],
        'execute_callback' => static function (array $input): array|WP_Error {
            $slug = Parser\normalize_slug((string) ($input['slug'] ?? ''));
            if ($slug === '') {
                return new WP_Error('missing_slug', __('A slug is required.', domain: 'novamira'));
            }
            $record = Library\find($slug);
            if ($record === null) {
                return new WP_Error('unknown_design', __('No design with that slug exists.', domain: 'novamira'));
            }
            $inspection = Contract\inspect($record['content']);
            if (!$inspection['readiness']['ready']) {
                return new WP_Error('design_not_ready', Contract\activation_error($inspection));
            }
            Store\set_active($slug);
            return ['activated' => true, 'slug' => $slug, 'readiness' => $inspection['readiness']];
        },
        'permission_callback' => 'novamira_permission_callback',
        'meta' => [
            'annotations' => [
                'readonly' => false,
                'destructive' => false,
                'idempotent' => true,
            ],
            'mcp' => ['public' => true, 'type' => 'tool'],
        ],
    ]);
}
