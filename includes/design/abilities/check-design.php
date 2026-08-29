<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Design\Abilities\Check;

use Novamira\Design\Abilities;
use Novamira\Design\Library;
use Novamira\Design\Parser;
use Novamira\Design\Preflight;
use Novamira\Design\Store;
use WP_Error;

if (!defined('ABSPATH')) {
    exit();
}

// @mago-expect lint:halstead
function register(): void
{
    if (!function_exists('wp_register_ability')) {
        return;
    }

    wp_register_ability('novamira/check-design', [
        'label' => __('Check Design (Pre-flight)', domain: 'novamira'),
        'description' => __(
            'Pre-flight a candidate page (its HTML/CSS or visible text) against the active design\'s tokens and Don\'t rules plus universal anti-slop checks (em-dash, AI-purple, Inter, filler copy, off-palette fonts/colors). Call before finalizing any visual output and fix every "fail" before shipping. `authority` says who owns the design: at `design` fix every violation; at `ask` the page builder named in `builder` has its own design system, so ask the user once which one is authoritative and, until they answer, treat token and Don\'t violations as advisory while builder values lead; at `hybrid` the builder stays the source of truth for everything it defines, so the universal checks still apply but token and Don\'t violations matter only for the gaps this design fills or when the user asked to apply it; at `builder` only the universal checks apply. `authoritative` is true only for `design`; `builder` is the page builder\'s label or null.',
            domain: 'novamira',
        ),
        'category' => Abilities\CATEGORY,
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'output' => [
                    'type' => 'string',
                    'description' => 'The candidate output to check: the HTML/CSS you built, or its visible text.',
                ],
                'slug' => [
                    'type' => 'string',
                    'description' => 'Optional design slug to check against; defaults to the active design.',
                ],
            ],
            'required' => ['output'],
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'ok' => ['type' => 'boolean'],
                'active' => ['type' => 'boolean'],
                'authority' => ['type' => 'string', 'enum' => ['design', 'ask', 'hybrid', 'builder']],
                'authoritative' => ['type' => 'boolean'],
                'builder' => ['type' => ['string', 'null']],
                'slug' => ['type' => 'string'],
                'violations' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'rule' => ['type' => 'string'],
                            'severity' => ['type' => 'string'],
                            'message' => ['type' => 'string'],
                            'evidence' => ['type' => 'string'],
                        ],
                    ],
                ],
                'checked' => ['type' => 'array', 'items' => ['type' => 'string']],
                'not_checked' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['ok', 'authority', 'authoritative', 'builder', 'violations'],
        ],
        'execute_callback' => static function (array $input): array|WP_Error {
            $output = stripcslashes((string) ($input['output'] ?? ''));
            if (strlen($output) > Parser\MAX_BYTES) {
                return new WP_Error('too_large', __('Output exceeds the size limit.', domain: 'novamira'));
            }

            $explicit_slug = ($input['slug'] ?? null) !== null && $input['slug'] !== '';
            $slug = $explicit_slug ? Parser\normalize_slug((string) $input['slug']) : Store\get_active_slug();
            $record = $slug !== '' ? Library\find($slug) : null;
            if ($explicit_slug && $record === null) {
                return new WP_Error('unknown_design', __('No design with that slug exists.', domain: 'novamira'));
            }

            $ctx = Preflight\context($record !== null ? $record['content'] : null);
            $violations = Preflight\violations($output, $ctx);

            $ok = true;
            foreach ($violations as $violation) {
                if ($violation['severity'] !== 'fail') {
                    continue;
                }
                $ok = false;
                break;
            }

            return array_merge(
                [
                    'ok' => $ok,
                    'active' => $ctx['has_active'],
                ],
                Abilities\authority_fields(),
                [
                    'slug' => $ctx['has_active'] ? $slug : '',
                    'violations' => $violations,
                    'checked' => Preflight\MECHANIZED,
                    'not_checked' => Preflight\STRUCTURAL_NOT_CHECKED,
                ],
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
