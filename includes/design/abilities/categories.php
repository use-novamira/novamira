<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Design\Abilities;

if (!defined('ABSPATH')) {
    exit();
}

const CATEGORY = 'design-system';

/** Register the `design-system` ability category. Idempotent. */
function register_category(): void
{
    if (!function_exists('wp_register_ability_category')) {
        return;
    }
    try {
        wp_register_ability_category(CATEGORY, [
            'label' => __('Design', domain: 'novamira'),
            'description' => __('Read and manage the active site design system.', domain: 'novamira'),
        ]);

        // @mago-expect lint:no-empty-catch-clause
    } catch (\Throwable) {
        // Already registered — fine.
    }
}

/**
 * Design-authority fields shared by the design abilities: the authority level
 * (`design`, `ask`, `hybrid`, or `builder`; see
 * novamira_design_resolve_authority()), the compatibility boolean that is
 * true only at the `design` level, and the label of the page builder that
 * owns the design system, or null when none was declared.
 *
 * @return array{authority: string, authoritative: bool, builder: ?string}
 */
function authority_fields(): array
{
    $resolved = novamira_design_resolve_authority();
    $authority = $resolved['level'];
    if ($authority === 'none') {
        // The design abilities exist only while the feature is on, so the
        // resolver's feature-off level cannot reach an agent; report the
        // default rather than an internal value.
        $authority = 'design';
    }

    return [
        'authority' => $authority,
        'authoritative' => $authority === 'design',
        'builder' => $resolved['builder'],
    ];
}
