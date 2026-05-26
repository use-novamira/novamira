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

wp_register_ability('novamira/gutenberg-get-pending-batch', [
    'label' => __('Get Gutenberg Pending Batch', domain: 'novamira'),
    'description' => __(
        'Returns compact status, target summaries, validation errors, Block Editor Queue runtime status, and curl SSE/poll URLs for one pending batch.',
        domain: 'novamira',
    ),
    'category' => 'gutenberg',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'batch_id' => ['type' => 'integer', 'description' => 'Gutenberg batch id.'],
        ],
        'required' => ['batch_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\gutenberg_get_pending_batch',
    'permission_callback' => 'novamira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Use this to inspect one batch without loading full block_spec payloads. During finalization, prefer streaming finalizer_runtime.sse_url with curl -N, or polling finalizer_runtime.poll_url with curl, until the batch is finalized, failed, or conflicted. Item status prepared means canonical content is staged but not live. If finalizer_runtime.online becomes false, the user closed or lost the Block Editor Queue page; ask them to reopen finalizer_runtime.dashboard_url and keep it open before treating queued changes as live.',
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
function gutenberg_get_pending_batch(array $input): array|WP_Error
{
    mark_stale_drafts();

    $batch_id = is_scalar($input['batch_id'] ?? null) ? (int) $input['batch_id'] : 0;
    $batch = find_batch($batch_id);
    if (!$batch instanceof WP_Post) {
        return new WP_Error('gutenberg_batch_not_found', sprintf('Gutenberg batch %d was not found.', $batch_id));
    }

    $batch = refresh_batch_runtime_state($batch);

    return shape_batch($batch);
}
