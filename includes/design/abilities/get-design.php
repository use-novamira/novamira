<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Design\Abilities\Get;

use Novamira\Design\Abilities;
use Novamira\Design\Contract;
use Novamira\Design\Library;
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

    wp_register_ability('novamira/get-design', [
        'label' => __('Get Design', domain: 'novamira'),
        'description' => __(
            'Return a specific design system by slug as raw DESIGN.md plus its structured contract and readiness. Use to read, repair, or evolve a saved direction.',
            domain: 'novamira',
        ),
        'category' => Abilities\CATEGORY,
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'slug' => [
                    'type' => 'string',
                    'description' => 'Slug of the design to read.',
                ],
            ],
            'required' => ['slug'],
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => array_merge([
                'found' => ['type' => 'boolean'],
                'slug' => ['type' => 'string'],
                'name' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'content' => ['type' => 'string'],
                'is_active' => ['type' => 'boolean'],
            ], Contract\ability_output_properties()),
            'required' => ['found'],
        ],
        'execute_callback' => static function (array $input): array|WP_Error {
            $slug = (string) ($input['slug'] ?? '');
            if ($slug === '') {
                return new WP_Error('missing_slug', __('A slug is required.', domain: 'novamira'));
            }

            $record = Library\find($slug);
            if ($record === null) {
                return ['found' => false];
            }

            return array_merge([
                'found' => true,
                'slug' => $record['slug'],
                'name' => $record['name'],
                'description' => $record['description'],
                'content' => $record['content'],
                'is_active' => Store\get_active_slug() === $record['slug'],
            ], Contract\inspect($record['content']));
        },
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
