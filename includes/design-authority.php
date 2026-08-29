<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Design authority policy: who owns the design for visual work on this site
 * — the Novamira design (DESIGN.md) or a page builder's own design system —
 * and what agents are told about it.
 *
 * Loaded unconditionally so the policy can be consulted, and filtered, even
 * when the Novamira Design feature itself is switched off. The free plugin
 * knows nothing about specific page builders: it defaults to the Novamira
 * design on every site and lets specializations declare a builder-owned
 * design system through the `novamira_design_authority` filter.
 */

if (!defined('ABSPATH')) {
    exit();
}

/** Whether the Novamira Design feature is switched on. */
function novamira_design_feature_is_active(): bool
{
    if (!function_exists('Novamira\\Features\\features')) {
        return false;
    }
    try {
        return \Novamira\Features\features()->is_active('novamira/design');
    } catch (\LogicException) {
        return false;
    }
}

/** Slug of the active, existing design, or '' when no design is active. */
function novamira_design_active_slug(): string
{
    if (!function_exists('Novamira\\Design\\Store\\get_active_slug')) {
        return '';
    }
    $slug = \Novamira\Design\Store\get_active_slug();
    if ($slug === '' || !function_exists('Novamira\\Design\\Library\\find')) {
        return $slug;
    }

    return \Novamira\Design\Library\find($slug) === null ? '' : $slug;
}

/**
 * Resolve who owns the design for visual work, as a level plus the label of
 * the page builder that owns the design system when one does:
 *
 * - `design`: the Novamira design leads (the `novamira-design` skill
 *   establishes or applies a direction). The default on every site.
 * - `ask`: a page builder owns a design system and a Novamira design is
 *   active; the agent asks the user once which one leads.
 * - `hybrid`: the builder's stores stay the source of truth for everything
 *   they define, the design only fills gaps or applies on request.
 * - `builder`: the builder alone is the source of truth.
 *
 * Resolution, first to last:
 *
 * 1. Feature-off guarantee: when the Novamira Design feature is disabled the
 *    level is `none`, no filter runs, and nothing about the design is emitted.
 * 2. `novamira_design_authority` ($default = 'design', $context with
 *    `feature_enabled`, `design_active`, `active_design`) — the single
 *    policy filter. It may return a level string, or an array
 *    `['level' => <level>, 'builder' => <label>]` naming the page builder
 *    that owns the design system. Invalid values keep `design`. The label
 *    is expected for `ask`, `hybrid`, and `builder`; when it is missing the
 *    agent-facing wording falls back to "the page builder".
 * 3. `novamira_design_authoritative` (bool, context) — legacy boolean view,
 *    consulted only when something hooks it: `true` forces `design`; `false`
 *    keeps the level the previous step produced when it is not `design`,
 *    and is a no-op otherwise. Abstention rule: a hooked callback must
 *    return a non-boolean to abstain; a non-boolean leaves the level as is.
 *
 * @return array{level: 'none'|'builder'|'hybrid'|'ask'|'design', builder: ?string}
 */
function novamira_design_resolve_authority(): array
{
    if (!novamira_design_feature_is_active()) {
        return ['level' => 'none', 'builder' => null];
    }
    $active_slug = novamira_design_active_slug();
    $context = [
        'feature_enabled' => true,
        'design_active' => $active_slug !== '',
        'active_design' => $active_slug,
    ];

    $default = 'design';
    /** @var mixed $filtered */
    $filtered = apply_filters('novamira_design_authority', $default, $context);
    $resolved = novamira_design_parse_authority($filtered);

    if (has_filter('novamira_design_authoritative')) {
        /** @var mixed $filtered_bool */
        $filtered_bool = apply_filters('novamira_design_authoritative', $resolved['level'] === 'design', $context);
        if ($filtered_bool === true) {
            $resolved['level'] = 'design';
        }
    }

    return $resolved;
}

/**
 * Narrow a `novamira_design_authority` filter result to a public level and
 * an optional builder label; anything else resolves to `design`.
 *
 * @return array{level: 'builder'|'hybrid'|'ask'|'design', builder: ?string}
 */
function novamira_design_parse_authority(mixed $value): array
{
    /** @var mixed $level */
    $level = is_array($value) ? $value['level'] ?? null : $value;
    /** @var mixed $label */
    $label = is_array($value) ? $value['builder'] ?? null : null;
    $label = is_string($label) ? trim($label) : '';

    return match ($level) {
        'builder', 'hybrid', 'ask', 'design' => ['level' => $level, 'builder' => $label !== '' ? $label : null],
        default => ['level' => 'design', 'builder' => null],
    };
}

/**
 * Who owns the design for visual work (see novamira_design_resolve_authority()).
 *
 * @return 'none'|'builder'|'hybrid'|'ask'|'design'
 */
function novamira_design_authority(): string
{
    return novamira_design_resolve_authority()['level'];
}

/**
 * Whether the Novamira design is the source of truth for visual work: true
 * only at the `design` level.
 */
function novamira_design_is_authoritative(): bool
{
    return novamira_design_authority() === 'design';
}

/**
 * The agent-facing lines about the Novamira design, emitted from this one
 * place only, per authority level: the load-the-skill directive at `design`,
 * the ask-once rule at `ask`, the gap-filling rule at `hybrid`, the
 * builder-owns-the-design note at `builder`, and nothing when the feature is
 * off.
 *
 * @return list<string>
 */
function novamira_design_building_context_lines(): array
{
    $resolved = novamira_design_resolve_authority();
    if ($resolved['level'] === 'none') {
        return [];
    }
    if ($resolved['level'] === 'design') {
        return [
            '',
            'Before any visual work (building or restyling a page, template, section, or component), load the `novamira-design` skill and follow it.',
        ];
    }

    return [
        '',
        'This site runs '
            . ($resolved['builder'] ?? 'the page builder')
            . novamira_design_builder_line_tail($resolved['level']),
    ];
}

/**
 * The builder-site line for a non-`design` level, without its subject.
 *
 * @param 'builder'|'hybrid'|'ask' $level
 */
function novamira_design_builder_line_tail(string $level): string
{
    return match ($level) {
        'ask'
            => ', which has its own design system, and a Novamira design is active. Before visual work, ask the user once whether the builder\'s design system or the Novamira design is authoritative, then follow that choice for the session. Until Novamira is chosen, use existing builder values and do not add Novamira tokens. If it is chosen, create or reuse each token as a native builder variable, palette colour, global/theme style, or class before referencing it — never paste literals or DESIGN.md names into content, never create a parallel token layer.',
        'hybrid'
            => ', whose own design system (theme styles, variables, palettes, classes, components) stays the source of truth for everything it already defines. Consult the active Novamira design (`novamira/get-active-design`) only to fill gaps the builder has no value for, or when the user explicitly asks to apply it; load the `novamira-design` skill in those cases. Create or reuse each design token as a native builder variable, palette colour, global/theme style, or class before referencing it — never paste literals or DESIGN.md names into content, never create a parallel token layer. Existing builder values win any conflict the user has not decided.',
        'builder'
            => ', whose own design system (theme styles, variables, palettes, classes, components) is the source of truth for visual work. Do not create or activate a Novamira design unless the user asks for one; load the `novamira-design` skill only if the user explicitly asks to apply a Novamira design.',
    };
}
