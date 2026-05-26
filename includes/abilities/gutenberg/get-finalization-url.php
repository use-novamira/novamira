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

wp_register_ability('novamira/gutenberg-get-finalization-url', [
    'label' => __('Get Gutenberg Finalization URL', domain: 'novamira'),
    'description' => __(
        'Returns the generic Block Editor Queue admin page URL for one ready or failed Gutenberg batch, plus the current finalizer runtime status and curl SSE/poll URLs. If the page is open and can finalize the batch, prefer watching the status URL instead of asking the user to do anything.',
        domain: 'novamira',
    ),
    'category' => 'gutenberg',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'batch_id' => ['type' => 'integer', 'description' => 'Ready or failed Gutenberg batch id.'],
        ],
        'required' => ['batch_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'batch_id' => ['type' => 'integer'],
            'batch_status' => ['type' => 'string'],
            'finalization_url' => ['type' => 'string'],
            'finalizer_runtime' => ['type' => 'object'],
            'user_instruction' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => __NAMESPACE__ . '\\gutenberg_get_finalization_url',
    'permission_callback' => 'novamira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Use this when a ready or failed batch already exists. Check finalizer_runtime first: if online and can_finalize_batch are true, the open Block Editor Queue page should process the batch automatically, so stream finalizer_runtime.sse_url with curl -N or poll finalizer_runtime.poll_url with curl. If finalizer_runtime.online is false, ask the user to open finalizer_runtime.dashboard_url or the returned finalization_url, which both point to the generic Block Editor Queue page.',
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
function gutenberg_get_finalization_url(array $input): array|WP_Error
{
    mark_stale_drafts();

    $batch_id = is_scalar($input['batch_id'] ?? null) ? (int) $input['batch_id'] : 0;
    $batch = find_batch($batch_id);
    if (!$batch instanceof WP_Post) {
        return new WP_Error('gutenberg_batch_not_found', sprintf('Gutenberg batch %d was not found.', $batch_id));
    }

    if (!in_array(status($batch->ID), [STATUS_READY, STATUS_FAILED], strict: true)) {
        return new WP_Error(
            'gutenberg_batch_not_finalizable',
            sprintf(
                'Gutenberg batch %d is %s. Only ready or failed batches need the Block Editor Queue page URL.',
                $batch->ID,
                status($batch->ID),
            ),
            ['status' => 409, 'batch' => shape_batch_summary($batch)],
        );
    }

    return [
        'batch_id' => $batch->ID,
        'batch_status' => status($batch->ID),
        'finalization_url' => finalization_url($batch->ID),
        'finalizer_runtime' => finalizer_runtime_status($batch),
        'user_instruction' => user_instruction($batch),
    ];
}
