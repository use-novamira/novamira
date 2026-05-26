<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Abilities\Gutenberg;

use WP_Error;

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('novamira/gutenberg-delete-pending-change', [
    'label' => __('Delete Gutenberg Pending Change', domain: 'novamira'),
    'description' => __(
        'Cancels one Gutenberg pending item without touching target content. The MVP batch page does not expose per-item cancellation, but agents can use this ability for recovery.',
        domain: 'novamira',
    ),
    'category' => 'gutenberg',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'item_id' => ['type' => 'integer', 'description' => 'Gutenberg pending item id to cancel.'],
        ],
        'required' => ['item_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\gutenberg_delete_pending_change',
    'permission_callback' => 'novamira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Cancels one queued item only. It does not alter target post_content.',
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
function gutenberg_delete_pending_change(array $input): array|WP_Error
{
    $item_id = is_scalar($input['item_id'] ?? null) ? (int) $input['item_id'] : 0;

    return cancel_item($item_id);
}
