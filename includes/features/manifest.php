<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Features;

if (!defined('ABSPATH')) {
    exit();
}

/** @return array<string, array<string, mixed>> */
function core_manifest(): array
{
    return [
        'novamira/infrastructure' => [
            'kind' => 'feature',
            'provider' => 'Novamira',
            'label' => __('Novamira Infrastructure', domain: 'novamira'),
            'description' => __(
                'Infrastructure required for Novamira to expose and load skills safely.',
                domain: 'novamira',
            ),
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
            'label' => __('Novamira Design', domain: 'novamira'),
            'description' => __(
                'Saved design directions, visual-work guidance, and the abilities agents use to manage and validate designs.',
                domain: 'novamira',
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
            'label' => __('Novamira Chat', domain: 'novamira'),
            'description' => __(
                'The AI agent workbench inside WordPress, including its menu, assets, sessions, and REST API.',
                domain: 'novamira',
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
            'label' => __('Novamira Visual', domain: 'novamira'),
            'description' => __(
                'The live browser workspace and its editor integrations for watching and guiding an AI agent.',
                domain: 'novamira',
            ),
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
            'label' => __('Block Editor Queue', domain: 'novamira'),
            'description' => __(
                'The browser-backed Gutenberg authoring workflow, including queue storage, finalization, REST routes, and abilities.',
                domain: 'novamira',
            ),
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
