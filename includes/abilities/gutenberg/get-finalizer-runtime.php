<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Abilities\Gutenberg;

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('novamira/gutenberg-get-finalizer-runtime', [
    'label' => __('Get Block Editor Queue Runtime', domain: 'novamira'),
    'description' => __(
        'Reports whether the Novamira Block Editor Queue admin page is open and heartbeating, including token-gated SSE and poll URLs that agents can watch with curl. Call this at the start of Gutenberg work: if the runtime is offline, ask the user to open the returned generic Block Editor Queue page URL and keep it open while static/native Gutenberg changes are queued and finalized.',
        domain: 'novamira',
    ),
    'category' => 'gutenberg',
    'input_schema' => [
        'type' => 'object',
        'properties' => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'finalizer_runtime' => ['type' => 'object'],
            'user_instruction' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => __NAMESPACE__ . '\\gutenberg_get_finalizer_runtime',
    'permission_callback' => 'novamira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Run this once before Gutenberg content work that may queue static/native blocks, then stream finalizer_runtime.sse_url with curl -N or poll finalizer_runtime.poll_url with curl instead of repeatedly calling MCP abilities. If finalizer_runtime.online is false, ask the user to open finalizer_runtime.dashboard_url and keep the Block Editor Queue page open while you work. During batch finalization, watch the sse_url or poll_url from the enable response; if finalizer_runtime.online becomes false, tell the user the Block Editor Queue page is offline and ask them to reopen it.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function gutenberg_get_finalizer_runtime(array $input): array
{
    unset($input);

    return [
        'finalizer_runtime' => finalizer_runtime_status(),
        'user_instruction' => finalizer_runtime_startup_instruction(),
    ];
}
