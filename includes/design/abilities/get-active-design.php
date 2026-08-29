<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Design\Abilities\GetActive;

use Novamira\Design\Abilities;
use Novamira\Design\Contract;
use Novamira\Design\Library;
use Novamira\Design\Store;

if (!defined('ABSPATH')) {
    exit();
}

function register(): void
{
    if (!function_exists('wp_register_ability')) {
        return;
    }

    wp_register_ability('novamira/get-active-design', [
        'label' => __('Get Active Design', domain: 'novamira'),
        'description' => __(
            'Return the active Novamira design as raw DESIGN.md plus structured tokens, dials, guidance, provenance, and readiness. `authority` says who owns the design for visual work: `design` (this design leads), `ask` (the page builder named in `builder` has its own design system and this design is active: ask the user once which one is authoritative and follow that choice for the session; until then use builder values and add no Novamira tokens), `hybrid` (the builder stays the source of truth for everything it defines; use this design only to fill gaps it has no value for or when the user explicitly asks, creating or reusing each token as a native builder entry first, builder values winning undecided conflicts), or `builder` (the builder alone leads). `authoritative` is true only for `design`; `builder` is the page builder\'s label or null.',
            domain: 'novamira',
        ),
        'category' => Abilities\CATEGORY,
        'input_schema' => [
            'type' => 'object',
            'default' => [],
            'properties' => new \stdClass(),
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => array_merge([
                'active' => ['type' => 'boolean'],
                'authority' => ['type' => 'string', 'enum' => ['design', 'ask', 'hybrid', 'builder']],
                'authoritative' => ['type' => 'boolean'],
                'builder' => ['type' => ['string', 'null']],
                'slug' => ['type' => 'string'],
                'name' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'content' => ['type' => 'string'],
            ], Contract\ability_output_properties()),
            'required' => ['active', 'authority', 'authoritative', 'builder'],
        ],
        'execute_callback' => static function (array $input): array {
            $authority = Abilities\authority_fields();
            $slug = Store\get_active_slug();
            if ($slug === '') {
                return array_merge(['active' => false], $authority);
            }
            $record = Library\find($slug);
            if ($record === null) {
                return array_merge(['active' => false], $authority);
            }
            return array_merge(
                [
                    'active' => true,
                ],
                $authority,
                [
                    'slug' => $record['slug'],
                    'name' => $record['name'],
                    'description' => $record['description'],
                    'content' => $record['content'],
                ],
                Contract\inspect($record['content']),
            );
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
