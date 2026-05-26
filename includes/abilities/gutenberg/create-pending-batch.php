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

wp_register_ability('novamira/gutenberg-create-pending-batch', [
    'label' => __('Create Gutenberg Pending Batch', domain: 'novamira'),
    'description' => __(
        'Creates an empty draft Gutenberg pending batch and reports the Block Editor Queue runtime with curl SSE/poll URLs. Draft batches are recoverable but not finalizable; after adding target changes, call gutenberg-enable-batch-finalization. If the runtime is offline, ask the user to open the generic Block Editor Queue page and keep it open while you work.',
        domain: 'novamira',
    ),
    'category' => 'gutenberg',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'label' => ['type' => 'string', 'description' => 'Human-readable batch label.'],
            'agent_label' => ['type' => 'string', 'description' => 'Display name for the originating agent/client.'],
            'agent_session_id' => ['type' => 'string', 'description' => 'Opaque originating session/conversation id.'],
            'agent_note' => ['type' => 'string', 'description' => 'Short note explaining what this batch changes.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'batch_id' => ['type' => 'integer'],
            'batch_status' => ['type' => 'string'],
            'finalization_required' => ['type' => 'boolean'],
            'finalizer_runtime' => ['type' => 'object'],
            'user_instruction' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => __NAMESPACE__ . '\\gutenberg_create_pending_batch',
    'permission_callback' => 'novamira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Optional convenience call. The common path is to call gutenberg-add-pending-change without batch_id and let it auto-create the draft batch. Check finalizer_runtime immediately; if online is false, ask the user to open dashboard_url and keep that Block Editor Queue page open while you work. Watch finalizer_runtime.sse_url with curl -N or poll finalizer_runtime.poll_url with curl if you need to check whether the page is still open. Draft batches are not live and cannot be finalized until gutenberg-enable-batch-finalization is called.',
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function gutenberg_create_pending_batch(array $input): array|WP_Error
{
    mark_stale_drafts();

    $batch_id = create_batch(
        is_scalar($input['label'] ?? null) ? (string) $input['label'] : '',
        is_scalar($input['agent_label'] ?? null) ? (string) $input['agent_label'] : '',
        is_scalar($input['agent_session_id'] ?? null) ? (string) $input['agent_session_id'] : '',
        is_scalar($input['agent_note'] ?? null) ? (string) $input['agent_note'] : '',
    );

    if (is_wp_error($batch_id)) {
        return $batch_id;
    }

    $batch = find_batch($batch_id);
    if (!$batch instanceof WP_Post) {
        return new WP_Error('gutenberg_batch_not_found', sprintf(
            'Gutenberg batch %d was not found after creation.',
            $batch_id,
        ));
    }

    return [
        'batch_id' => $batch->ID,
        'batch_status' => status($batch->ID),
        'finalization_required' => true,
        'finalizer_runtime' => finalizer_runtime_status(),
        'user_instruction' => sprintf(
            'Gutenberg batch #%d: %s is a draft. Add pending changes, then call novamira/gutenberg-enable-batch-finalization. %s Queued changes are not live until the Block Editor Queue page reports completion.',
            $batch->ID,
            batch_label($batch),
            finalizer_runtime_startup_instruction(),
        ),
    ];
}
