<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Features;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * @param \Closure(): string $translate
 * @return array{source: string, translate: \Closure(): string}
 */
function deferred_text(string $source, \Closure $translate): array
{
    return ['source' => $source, 'translate' => $translate];
}

/** @return array<string, array<string, mixed>> */
function core_manifest(): array
{
    return [
        'novamira/infrastructure' => [
            'kind' => 'feature',
            'provider' => 'Novamira',
            'label' => deferred_text('Novamira Infrastructure', static fn(): string => __(
                'Novamira Infrastructure',
                domain: 'novamira',
            )),
            'description' => deferred_text('Infrastructure required for Novamira to expose and load skills safely.', static fn(): string => __(
                'Infrastructure required for Novamira to expose and load skills safely.',
                domain: 'novamira',
            )),
            'toggleable' => false,
            'visible' => false,
            'default_active' => true,
            'depends_on' => [],
            'skills' => ['skill-creator'],
            'abilities' => [
                'novamira/skill-get',
                'novamira/agent-context',
                'novamira/skill-prompt-skill-creator',
                'novamira-mcp-adapter/discover-abilities',
                'novamira-mcp-adapter/get-ability-info',
                'novamira-mcp-adapter/execute-ability',
            ],
        ],
        'novamira/design' => [
            'kind' => 'feature',
            'provider' => 'Novamira',
            'label' => deferred_text('Novamira Design', static fn(): string => __(
                'Novamira Design',
                domain: 'novamira',
            )),
            'description' => deferred_text(
                'One saved design system your AI follows on every page it builds, so the site stays consistent and looks deliberate rather than generic.',
                static fn(): string => __(
                    'One saved design system your AI follows on every page it builds, so the site stays consistent and looks deliberate rather than generic.',
                    domain: 'novamira',
                ),
            ),
            'default_active' => true,
            'depends_on' => [],
            'skills' => ['novamira-design', 'custom-theme-build'],
            'abilities' => [
                'novamira/list-design-library',
                'novamira/get-active-design',
                'novamira/activate-design',
                'novamira/save-design',
                'novamira/check-design',
                'novamira/get-design',
                'novamira/delete-design',
                'novamira/skill-prompt-novamira-design',
                'novamira/skill-prompt-custom-theme-build',
            ],
            'boot' => 'novamira_boot_design_feature',
        ],
        'novamira/chat' => [
            'kind' => 'feature',
            'provider' => 'Novamira',
            'label' => deferred_text('Novamira Chat', static fn(): string => __('Novamira Chat', domain: 'novamira')),
            'description' => deferred_text(
                'An AI agent inside your WordPress dashboard: describe a change in plain language, review its plan, and approve it to apply the change.',
                static fn(): string => __(
                    'An AI agent inside your WordPress dashboard: describe a change in plain language, review its plan, and approve it to apply the change.',
                    domain: 'novamira',
                ),
            ),
            'default_active' => true,
            'depends_on' => [],
            'skills' => [],
            'abilities' => [],
            'boot' => 'novamira_boot_chat_feature',
        ],
        'novamira/visual' => [
            'kind' => 'feature',
            'provider' => 'Novamira',
            'label' => deferred_text('Novamira Visual', static fn(): string => __(
                'Novamira Visual',
                domain: 'novamira',
            )),
            'description' => deferred_text('The live browser workspace and its editor integrations for watching and guiding an AI agent.', static fn(): string => __(
                'The live browser workspace and its editor integrations for watching and guiding an AI agent.',
                domain: 'novamira',
            )),
            'experimental' => true,
            'default_active' => true,
            'depends_on' => [],
            'skills' => [],
            'abilities' => [],
            'boot' => 'novamira_boot_visual_feature',
        ],
        'novamira/block-editor-queue' => [
            'kind' => 'feature',
            'provider' => 'Novamira',
            'label' => deferred_text('Block Editor Queue', static fn(): string => __(
                'Block Editor Queue',
                domain: 'novamira',
            )),
            'description' => deferred_text('The browser-backed Gutenberg authoring workflow, including queue storage, finalization, REST routes, and abilities.', static fn(): string => __(
                'The browser-backed Gutenberg authoring workflow, including queue storage, finalization, REST routes, and abilities.',
                domain: 'novamira',
            )),
            'default_active' => true,
            'depends_on' => [],
            'skills' => ['gutenberg-edit-content'],
            'abilities' => [
                'novamira/gutenberg-get-finalizer-runtime',
                'novamira/gutenberg-list-block-types',
                'novamira/gutenberg-get-block-type',
                'novamira/gutenberg-get-content',
                'novamira/gutenberg-write-content',
                'novamira/gutenberg-create-pending-batch',
                'novamira/gutenberg-add-pending-change',
                'novamira/gutenberg-enable-batch-finalization',
                'novamira/gutenberg-get-pending-batch',
                'novamira/gutenberg-list-pending-batches',
                'novamira/gutenberg-delete-pending-batch',
                'novamira/gutenberg-delete-pending-change',
                'novamira/gutenberg-get-finalization-url',
                'novamira/skill-prompt-gutenberg-edit-content',
            ],
            'boot' => 'novamira_boot_block_editor_queue_feature',
            'deactivate' => 'novamira_deactivate_block_editor_queue_feature',
        ],
    ];
}
