<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Skills\Prompts;

use Novamira\Skills\Parser;
use Novamira\Skills\Sources;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Register one ability per discoverable prompt-mode skill. The MCP Adapter
 * auto-discovers abilities whose `meta.mcp.type` is `prompt` and exposes
 * them through the protocol's prompts/list and prompts/get endpoints.
 */
function register_dynamic_abilities(): void
{
    if (!function_exists('wp_register_ability')) {
        return;
    }

    $skills = Sources\discoverable('prompt');

    foreach ($skills as $skill) {
        // The slug here is already decorated (e.g. `custom_landing-page`)
        // because `Sources\discoverable()` returns records with the
        // agent-facing slug.
        $slug = (string) ($skill['slug'] ?? '');
        if ($slug === '') {
            continue;
        }
        $name = (string) ($skill['name'] ?? $slug);
        $description = (string) ($skill['description'] ?? '');
        $body = Parser\render_skill_md([
            'slug' => $slug,
            'description' => $description,
            'content' => (string) ($skill['content'] ?? ''),
            // @mago-expect analysis:mixed-operand
            'enable_prompt' => (bool) ($skill['enable_prompt'] ?? true),
            // @mago-expect analysis:mixed-operand
            'enable_agentic' => (bool) ($skill['enable_agentic'] ?? true),
        ]);

        wp_register_ability("novamira/skill-prompt-{$slug}", [
            'label' => $name,
            'description' => $description,
            'category' => 'skill',
            'input_schema' => [
                'type' => 'object',
                'properties' => new \stdClass(),
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'messages' => ['type' => 'array'],
                ],
            ],
            'execute_callback' => static fn(): array => [
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => ['type' => 'text', 'text' => $body],
                    ],
                ],
            ],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => [
                'mcp' => ['public' => true, 'type' => 'prompt'],
            ],
        ]);
    }
}
