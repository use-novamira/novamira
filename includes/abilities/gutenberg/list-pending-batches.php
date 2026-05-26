<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Abilities\Gutenberg;

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('novamira/gutenberg-list-pending-batches', [
    'label' => __('List Gutenberg Pending Batches', domain: 'novamira'),
    'description' => __(
        'Lists compact queue state grouped by Gutenberg batch for agent recovery, plus the current Block Editor Queue runtime status and curl SSE/poll URLs. Full block specs are not returned.',
        domain: 'novamira',
    ),
    'category' => 'gutenberg',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'status' => [
                'type' => 'string',
                'enum' => ['draft', 'ready', 'running', 'finalized', 'failed', 'conflicted', 'canceled', 'stale'],
                'description' => 'Optional batch status filter.',
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Maximum batches to return.',
                'default' => 20,
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'batches' => ['type' => 'array'],
            'finalizer_runtime' => ['type' => 'object'],
            'user_instruction' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => __NAMESPACE__ . '\\gutenberg_list_pending_batches',
    'permission_callback' => 'novamira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Use this for compact recovery/discovery. The top-level finalizer_runtime tells you whether the Block Editor Queue page is currently open and includes sse_url/poll_url for curl loops; if it is offline during Gutenberg work, ask the user to reopen dashboard_url and keep it open. For one batch, call gutenberg-get-pending-batch once, then watch the returned sse_url with curl -N or poll_url with curl.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

/**
 * @param array<string, mixed> $input
 * @return array{batches: list<array<string, mixed>>, finalizer_runtime: array<string, mixed>, user_instruction: string}
 */
function gutenberg_list_pending_batches(array $input): array
{
    mark_stale_drafts();

    $status = is_scalar($input['status'] ?? null) ? (string) $input['status'] : '';
    $limit = max(1, min(100, is_scalar($input['limit'] ?? null) ? (int) $input['limit'] : 20));
    $statuses = $status !== '' ? [$status] : null;

    $batches = [];
    foreach (get_batches($statuses, posts_per_page: $limit) as $batch) {
        $batch = refresh_batch_runtime_state($batch);
        $batches[] = shape_batch_summary($batch);
    }

    return [
        'batches' => $batches,
        'finalizer_runtime' => finalizer_runtime_status(),
        'user_instruction' => finalizer_runtime_startup_instruction(),
    ];
}
