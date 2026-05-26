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

wp_register_ability('novamira/gutenberg-enable-batch-finalization', [
    'label' => __('Enable Gutenberg Batch Finalization', domain: 'novamira'),
    'description' => __(
        'Marks a draft Gutenberg pending batch ready after all target changes are queued. If the Block Editor Queue page is open, it can pick up the batch automatically; otherwise the response tells the agent to ask the user to open the generic Block Editor Queue page. The response also includes token-gated SSE and poll URLs agents can watch with curl. Browser-serialized items are staged first; queued changes are still not live until the whole batch commits and reports finalized.',
        domain: 'novamira',
    ),
    'category' => 'gutenberg',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'batch_id' => ['type' => 'integer', 'description' => 'Draft Gutenberg batch id.'],
        ],
        'required' => ['batch_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'batch_id' => ['type' => 'integer'],
            'batch_status' => ['type' => 'string'],
            'finalization_required' => ['type' => 'boolean'],
            'finalization_url' => ['type' => 'string'],
            'finalizer_runtime' => ['type' => 'object'],
            'user_instruction' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => __NAMESPACE__ . '\\gutenberg_enable_batch_finalization',
    'permission_callback' => 'novamira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Call this only after every target change has been added to the batch. Check finalizer_runtime: if online and can_finalize_batch are true, the open Block Editor Queue page should process the batch automatically, so stream finalizer_runtime.sse_url with curl -N or poll finalizer_runtime.poll_url with curl instead of repeatedly calling MCP abilities or asking the user to do anything. If the runtime is offline or becomes offline while watching, point the user to finalizer_runtime.dashboard_url or finalization_url; both are the generic Block Editor Queue page. Items may be staged as prepared while the browser works; do not treat queued Gutenberg changes as live until the batch reports finalized.',
            'readonly' => false,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function gutenberg_enable_batch_finalization(array $input): array|WP_Error
{
    mark_stale_drafts();

    $batch_id = is_scalar($input['batch_id'] ?? null) ? (int) $input['batch_id'] : 0;
    $batch = find_batch($batch_id);
    if (!$batch instanceof WP_Post) {
        return new WP_Error('gutenberg_batch_not_found', sprintf('Gutenberg batch %d was not found.', $batch_id));
    }

    $batch_status = status($batch->ID);
    if ($batch_status === STATUS_READY) {
        return gutenberg_enable_batch_finalization_response($batch);
    }

    if ($batch_status !== STATUS_DRAFT) {
        return new WP_Error(
            'gutenberg_batch_not_draft',
            sprintf('Gutenberg batch %d is %s and cannot be enabled for finalization.', $batch->ID, $batch_status),
            ['status' => 409, 'batch' => shape_batch($batch)],
        );
    }

    $items = get_items($batch->ID, [STATUS_DRAFT]);
    if ($items === []) {
        return new WP_Error('gutenberg_batch_empty', sprintf(
            'Gutenberg batch %d has no draft items to finalize.',
            $batch->ID,
        ));
    }

    foreach ($items as $item) {
        set_status($item->ID, STATUS_READY);
    }
    set_status($batch->ID, STATUS_READY);
    update_post_meta($batch->ID, META_READY_AT, now_mysql());

    $fresh_batch = find_batch($batch->ID) ?? $batch;

    return gutenberg_enable_batch_finalization_response($fresh_batch);
}

/**
 * @return array<string, mixed>
 */
function gutenberg_enable_batch_finalization_response(WP_Post $batch): array
{
    return [
        'batch_id' => $batch->ID,
        'batch_status' => status($batch->ID),
        'finalization_required' => true,
        'finalization_url' => finalization_url($batch->ID),
        'finalizer_runtime' => finalizer_runtime_status($batch),
        'user_instruction' => user_instruction($batch),
    ];
}
