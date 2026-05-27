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

wp_register_ability('novamira/gutenberg-add-pending-change', [
    'label' => __('Add Gutenberg Pending Change', domain: 'novamira'),
    'description' => __(
        'Adds one replace-content target change to a draft Gutenberg pending batch, or auto-creates a draft batch when batch_id is omitted. Static/native blocks are finalized in a hidden editor iframe so registered third-party blocks can be serialized by their editor JavaScript. Queued changes are not live until gutenberg-enable-batch-finalization marks the batch ready and an open Block Editor Queue page completes it.',
        domain: 'novamira',
    ),
    'category' => 'gutenberg',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'batch_id' => [
                'type' => 'integer',
                'description' => 'Existing draft batch id. Omit to auto-create a draft batch.',
            ],
            'label' => ['type' => 'string', 'description' => 'Batch label when auto-creating a batch.'],
            'agent_label' => [
                'type' => 'string',
                'description' => 'Originating agent/client display name when auto-creating a batch.',
            ],
            'agent_session_id' => [
                'type' => 'string',
                'description' => 'Originating opaque session id when auto-creating a batch.',
            ],
            'agent_note' => [
                'type' => 'string',
                'description' => 'Short note explaining the batch when auto-creating a batch.',
            ],
            'target_id' => ['type' => 'integer', 'description' => 'Target post/template ID. Alias: post_id.'],
            'post_id' => ['type' => 'integer', 'description' => 'Alias for target_id.'],
            'target_type' => [
                'type' => 'string',
                'description' => 'Optional target post type/context. Defaults to the actual post_type.',
            ],
            'post_type' => ['type' => 'string', 'description' => 'Alias for target_type.'],
            'operation' => [
                'type' => 'string',
                'enum' => ['replace-content'],
                'description' => 'MVP operation. Replaces the target post_content after JS finalization succeeds.',
                'default' => 'replace-content',
            ],
            'block_spec' => [
                'type' => 'array',
                'description' => 'Top-level Gutenberg block specs: [{name, attributes, innerBlocks}]. Static/native blocks are serialized by the Block Editor Queue inside the target editor context.',
                'items' => ['type' => 'object', 'additionalProperties' => true],
            ],
        ],
        'required' => ['block_spec'],
        'anyOf' => [
            ['required' => ['target_id']],
            ['required' => ['post_id']],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'batch_id' => ['type' => 'integer'],
            'item_id' => ['type' => 'integer'],
            'batch_status' => ['type' => 'string'],
            'target' => ['type' => 'object'],
            'finalization_required' => ['type' => 'boolean'],
            'finalizer_runtime' => ['type' => 'object'],
            'user_instruction' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => __NAMESPACE__ . '\\gutenberg_add_pending_change',
    'permission_callback' => 'novamira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Use this for native/static Gutenberg content. If no batch_id is supplied, this ability creates a draft batch and adds the first item. Check finalizer_runtime in the response: if online is false, ask the user to open dashboard_url and keep the Block Editor Queue page open while you finish queueing. You may stream finalizer_runtime.sse_url with curl -N or poll finalizer_runtime.poll_url with curl to check whether the page is still open. Continue adding items to the same batch_id, then call gutenberg-enable-batch-finalization. If a Block Editor Queue page is online, enabling the batch should let that page process it automatically. Do not tell the user the changes are live until finalization completes.',
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
function gutenberg_add_pending_change(array $input): array|WP_Error
{
    mark_stale_drafts();

    $target_data = gutenberg_add_pending_target_data($input);
    if (is_wp_error($target_data)) {
        return $target_data;
    }

    $target = $target_data['target'];
    $target_type = $target_data['target_type'];
    $operation = $target_data['operation'];

    $blocks = gutenberg_add_pending_blocks($input);
    if (is_wp_error($blocks)) {
        return $blocks;
    }

    $existing = active_item_for_target($target->ID, $target_type);
    if ($existing instanceof WP_Post) {
        return gutenberg_pending_conflict_error($existing, $target, $target_type);
    }

    $batch = gutenberg_resolve_pending_batch($input);
    if (is_wp_error($batch)) {
        return $batch;
    }

    $item_id = create_item($batch->ID, $target->ID, $target_type, $operation, $blocks);
    if (is_wp_error($item_id)) {
        return $item_id;
    }

    return gutenberg_pending_change_response($batch, $item_id, $target, $target_type);
}

/**
 * @param array<string, mixed> $input
 * @return array{target: WP_Post, target_type: string, operation: string}|WP_Error
 */
function gutenberg_add_pending_target_data(array $input): array|WP_Error
{
    $target_id = input_target_id($input);
    $target = get_target($target_id);
    if (!$target instanceof WP_Post) {
        return new WP_Error('gutenberg_target_not_found', sprintf('Target post %d was not found.', $target_id));
    }

    $target_type = input_target_type($input, $target);
    $operation = is_scalar($input['operation'] ?? null) ? (string) $input['operation'] : 'replace-content';
    if ($operation !== 'replace-content') {
        return new WP_Error('gutenberg_unsupported_operation', 'V1 supports only operation="replace-content".');
    }

    return [
        'target' => $target,
        'target_type' => $target_type,
        'operation' => $operation,
    ];
}

/**
 * @param array<string, mixed> $input
 * @return list<array<string, mixed>>|WP_Error
 */
function gutenberg_add_pending_blocks(array $input): array|WP_Error
{
    $blocks = normalize_blocks($input['block_spec'] ?? null);
    if (is_wp_error($blocks)) {
        return $blocks;
    }

    return $blocks;
}

function gutenberg_pending_conflict_error(WP_Post $existing, WP_Post $target, string $target_type): WP_Error
{
    $conflict = conflict_payload($existing);

    return new WP_Error(
        'gutenberg_target_has_pending_change',
        sprintf(
            '%s %d already has a non-terminal Gutenberg pending change in batch #%d: %s.',
            ucfirst($target_type),
            $target->ID,
            (int) $conflict['batch_id'],
            (string) $conflict['batch_label'],
        ),
        ['status' => 409, 'conflict' => $conflict],
    );
}

/**
 * @param array<string, mixed> $input
 * @return WP_Post|WP_Error
 */
function gutenberg_resolve_pending_batch(array $input): WP_Post|WP_Error
{
    $batch_id = is_scalar($input['batch_id'] ?? null) ? (int) $input['batch_id'] : 0;
    if ($batch_id <= 0) {
        $created_batch_id = create_batch(
            is_scalar($input['label'] ?? null) ? (string) $input['label'] : '',
            is_scalar($input['agent_label'] ?? null) ? (string) $input['agent_label'] : '',
            is_scalar($input['agent_session_id'] ?? null) ? (string) $input['agent_session_id'] : '',
            is_scalar($input['agent_note'] ?? null) ? (string) $input['agent_note'] : '',
        );
        if (is_wp_error($created_batch_id)) {
            return $created_batch_id;
        }
        $batch_id = $created_batch_id;
    }

    $batch = find_batch($batch_id);
    if (!$batch instanceof WP_Post) {
        return new WP_Error('gutenberg_batch_not_found', sprintf('Gutenberg batch %d was not found.', $batch_id));
    }

    if (status($batch->ID) !== STATUS_DRAFT) {
        return new WP_Error(
            'gutenberg_batch_not_draft',
            sprintf(
                'Gutenberg batch %d is %s. Only draft batches can receive new pending changes.',
                $batch->ID,
                status($batch->ID),
            ),
            ['status' => 409, 'batch' => shape_batch($batch)],
        );
    }

    return $batch;
}

/**
 * @return array<string, mixed>
 */
function gutenberg_pending_change_response(WP_Post $batch, int $item_id, WP_Post $target, string $target_type): array
{
    $runtime = finalizer_runtime_status($batch);

    return [
        'batch_id' => $batch->ID,
        'item_id' => $item_id,
        'batch_status' => status($batch->ID),
        'target' => [
            'target_id' => $target->ID,
            'target_type' => $target_type,
            'target_title' => target_title($target),
        ],
        'finalization_required' => true,
        'finalizer_runtime' => $runtime,
        'user_instruction' => gutenberg_pending_change_instruction($batch, $target, $target_type, $runtime),
    ];
}

/**
 * @param array<string, mixed> $runtime
 */
function gutenberg_pending_change_instruction(
    WP_Post $batch,
    WP_Post $target,
    string $target_type,
    array $runtime,
): string {
    $dashboard_state =
        ($runtime['online'] ?? false) === true && ($runtime['can_finalize_batch'] ?? false) === true
            ? 'The Block Editor Queue page is online; after you call novamira/gutenberg-enable-batch-finalization, it should pick up this batch automatically.'
            : sprintf(
                'The Block Editor Queue page is not ready for this batch yet. Ask the user to open %s and keep it open while you finish queueing; after you call novamira/gutenberg-enable-batch-finalization, use the returned instructions.',
                finalizer_dashboard_url(),
            );

    return sprintf(
        'Gutenberg batch #%d: %s now has a queued change for %s #%d. Continue adding changes, then call novamira/gutenberg-enable-batch-finalization. %s These queued changes are not live until finalization completes.',
        $batch->ID,
        batch_label($batch),
        $target_type,
        $target->ID,
        $dashboard_state,
    );
}
