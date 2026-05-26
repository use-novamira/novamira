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

wp_register_ability('novamira/gutenberg-write-content', [
    'label' => __('Write Gutenberg Content', domain: 'novamira'),
    'description' => __(
        'Directly writes Gutenberg post_content only when every supplied block is a registered Novamira-owned dynamic-only block. Native/static Gutenberg blocks require browser JS finalization; queue them with gutenberg-add-pending-change, then call gutenberg-enable-batch-finalization and send the finalization link to the user.',
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
            'block_spec' => [
                'type' => 'array',
                'description' => 'Top-level Gutenberg block specs: [{name, attributes, innerBlocks}]. Only registered novamira/* dynamic-only blocks are accepted here.',
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
            'target_id' => ['type' => 'integer'],
            'target_type' => ['type' => 'string'],
            'written' => ['type' => 'boolean'],
            'finalization_required' => ['type' => 'boolean'],
            'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
    ],
    'execute_callback' => __NAMESPACE__ . '\\gutenberg_write_content',
    'permission_callback' => 'novamira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Use this only for Novamira-owned dynamic-only Gutenberg blocks where save:null means no static saved HTML is needed. For core/static/native blocks, this ability refuses the write and tells you to use the pending queue and Block Editor Queue browser runtime.',
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
function gutenberg_write_content(array $input): array|WP_Error
{
    $target_id = input_target_id($input);
    $target = get_target($target_id);
    if (!$target instanceof WP_Post) {
        return new WP_Error('gutenberg_target_not_found', sprintf('Target post %d was not found.', $target_id));
    }

    $target_type = input_target_type($input, $target);
    $conflict = active_item_for_target($target->ID, $target_type);
    if ($conflict instanceof WP_Post) {
        return new WP_Error(
            'gutenberg_target_has_pending_change',
            sprintf(
                '%s %d already has a non-terminal Gutenberg pending change in batch #%d: %s.',
                ucfirst($target_type),
                $target->ID,
                $conflict->post_parent,
                (string) (conflict_payload($conflict)['batch_label'] ?? ''),
            ),
            ['status' => 409, 'conflict' => conflict_payload($conflict)],
        );
    }

    $blocks = normalize_blocks($input['block_spec'] ?? null);
    if (is_wp_error($blocks)) {
        return $blocks;
    }

    $dynamic_error = validate_dynamic_only_blocks($blocks);
    if ($dynamic_error instanceof WP_Error) {
        return $dynamic_error;
    }

    $content = serialize_dynamic_blocks($blocks);
    $updated = wp_update_post([
        'ID' => $target->ID,
        'post_content' => $content,
    ], wp_error: true);

    if (is_wp_error($updated)) {
        return $updated;
    }

    return [
        'target_id' => $target->ID,
        'target_type' => $target_type,
        'written' => true,
        'finalization_required' => false,
        'warnings' => [
            'Direct write path used because every block is a registered Novamira-owned dynamic-only block.',
        ],
    ];
}
