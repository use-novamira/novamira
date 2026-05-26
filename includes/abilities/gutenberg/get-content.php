<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Abilities\Gutenberg;

use WP_Error;
use WP_Post;

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('novamira/gutenberg-get-content', [
    'label' => __('Get Gutenberg Content', domain: 'novamira'),
    'description' => __(
        'Reads the live saved Gutenberg post_content for one target and returns a compact parsed block tree. This also reports the Block Editor Queue runtime plus curl SSE/poll URLs so agents can ask the user to open the queue page before queueing static/native block changes. This does not read queued pending block_spec data; if a non-terminal Gutenberg queue item exists for the target, pending_gutenberg_change summarizes it separately.',
        domain: 'novamira',
    ),
    'category' => 'gutenberg',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'target_id' => ['type' => 'integer', 'description' => 'Target post/template ID. Alias: post_id.'],
            'post_id' => ['type' => 'integer', 'description' => 'Alias for target_id.'],
            'target_type' => [
                'type' => 'string',
                'description' => 'Optional target post type/context. Defaults to the actual post_type.',
            ],
            'post_type' => ['type' => 'string', 'description' => 'Alias for target_type.'],
            'max_depth' => [
                'type' => 'integer',
                'description' => 'Maximum innerBlocks depth to include in the compact tree.',
                'default' => 4,
            ],
            'include_attributes' => [
                'type' => 'boolean',
                'description' => 'Whether to include block attributes in the compact tree.',
                'default' => true,
            ],
            'include_raw_content' => [
                'type' => 'boolean',
                'description' => 'Whether to include raw live post_content. Can be large; default false.',
                'default' => false,
            ],
        ],
        'anyOf' => [
            ['required' => ['target_id']],
            ['required' => ['post_id']],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'target_id' => ['type' => 'integer'],
            'target_type' => ['type' => 'string'],
            'target_title' => ['type' => 'string'],
            'live_content_only' => ['type' => 'boolean'],
            'blocks' => ['type' => 'array'],
            'pending_gutenberg_change' => ['type' => 'object'],
            'finalizer_runtime' => ['type' => 'object'],
            'user_instruction' => ['type' => 'string'],
            'raw_content' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => __NAMESPACE__ . '\\gutenberg_get_content',
    'permission_callback' => 'novamira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Reads only the saved live post_content. At the start of Gutenberg work, check finalizer_runtime: if online is false, ask the user to open dashboard_url and keep the Block Editor Queue page open while you work. Use finalizer_runtime.sse_url with curl -N, or finalizer_runtime.poll_url with curl, to check whether the page is open instead of repeatedly calling MCP abilities. If pending_gutenberg_change is present, do not assume the queued intended content is live; inspect/finalize that batch first.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function gutenberg_get_content(array $input): array|WP_Error
{
    $target_id = input_target_id($input);
    $target = get_target($target_id);
    if (!$target instanceof WP_Post) {
        return new WP_Error('gutenberg_target_not_found', sprintf('Target post %d was not found.', $target_id));
    }

    $target_type = input_target_type($input, $target);
    $max_depth = max(0, min(12, is_scalar($input['max_depth'] ?? null) ? (int) $input['max_depth'] : 4));
    $include_attributes = !array_key_exists('include_attributes', $input) || $input['include_attributes'] === true;
    $options = ['include_attributes' => $include_attributes];

    $blocks = gutenberg_parsed_blocks_list(parse_blocks($target->post_content));
    $response = [
        'target_id' => $target->ID,
        'target_type' => $target_type,
        'target_title' => target_title($target),
        'live_content_only' => true,
        'blocks' => gutenberg_shape_parsed_blocks($blocks, $max_depth, $options),
        'pending_gutenberg_change' => pending_summary_for_target($target->ID, $target_type),
        'finalizer_runtime' => finalizer_runtime_status(),
        'user_instruction' => finalizer_runtime_startup_instruction(),
    ];

    if (array_key_exists('include_raw_content', $input) && $input['include_raw_content'] === true) {
        $response['raw_content'] = $target->post_content;
    }

    return $response;
}

/**
 * @param array<array-key, mixed> $blocks
 * @return list<array<string, mixed>>
 */
function gutenberg_parsed_blocks_list(array $blocks): array
{
    $parsed_blocks = array_values(array_filter($blocks, static fn(mixed $block): bool => is_array($block)));

    /** @var list<array<string, mixed>> $parsed_blocks */
    return typed_array_list($parsed_blocks);
}

/**
 * @param array<string, mixed> $block
 * @return list<array<string, mixed>>
 */
function gutenberg_parsed_inner_blocks(array $block): array
{
    return gutenberg_parsed_blocks_list(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : []);
}

/**
 * @param list<array<string, mixed>> $blocks
 * @param array{include_attributes: bool} $options
 * @return list<array<string, mixed>>
 */
function gutenberg_shape_parsed_blocks(array $blocks, int $max_depth, array $options, int $depth = 0): array
{
    $shaped = [];
    foreach ($blocks as $block) {
        $name = is_string($block['blockName'] ?? null) ? $block['blockName'] : 'core/freeform';
        $inner_blocks = gutenberg_parsed_inner_blocks($block);
        $entry = [
            'name' => $name,
            'inner_block_count' => count($inner_blocks),
            'inner_html_length' => is_string($block['innerHTML'] ?? null) ? strlen($block['innerHTML']) : 0,
        ];

        if ($options['include_attributes']) {
            $entry['attributes'] = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
        }

        if ($inner_blocks !== [] && $depth < $max_depth) {
            $entry['innerBlocks'] = gutenberg_shape_parsed_blocks($inner_blocks, $max_depth, $options, $depth + 1);
        }

        $shaped[] = $entry;
    }

    return $shaped;
}
