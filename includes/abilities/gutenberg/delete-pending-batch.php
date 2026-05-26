<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Abilities\Gutenberg;

use WP_Error;

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('novamira/gutenberg-delete-pending-batch', [
    'label' => __('Delete Gutenberg Pending Batch', domain: 'novamira'),
    'description' => __(
        'Cancels a draft, ready, running, prepared, failed, or conflicted Gutenberg pending batch and its non-finalized items without touching target content.',
        domain: 'novamira',
    ),
    'category' => 'gutenberg',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'batch_id' => ['type' => 'integer', 'description' => 'Gutenberg batch id to cancel.'],
        ],
        'required' => ['batch_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\gutenberg_delete_pending_batch',
    'permission_callback' => 'novamira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Use this to cancel an active queued batch when the user confirms it should be abandoned. It does not alter target post_content.',
            'readonly' => false,
            'destructive' => true,
            'idempotent' => true,
        ],
    ],
]);

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function gutenberg_delete_pending_batch(array $input): array|WP_Error
{
    $batch_id = is_scalar($input['batch_id'] ?? null) ? (int) $input['batch_id'] : 0;

    return cancel_batch($batch_id);
}
