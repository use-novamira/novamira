<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Skills\Catalog;

use Novamira\Skills\Sources;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Prepend the unified skill catalog block to the Novamira discover-abilities
 * instructions string. Skills are listed only if their `description` is
 * non-empty and `enable_agentic` is true (default for sources that do not
 * specify it).
 *
 * Other plugins that contribute additional sources via
 * `novamira_skill_lookup_sources` automatically appear here under their
 * source label.
 */
function inject(mixed $instructions): mixed
{
    if (!is_string($instructions)) {
        return $instructions;
    }

    $skills = Sources\discoverable('agentic');
    if ($skills === []) {
        return $instructions;
    }

    return render($skills) . "\n" . $instructions;
}

/**
 * Render the markdown catalog block. Public so admin previews can reuse it.
 *
 * @param list<array<string,mixed>> $skills
 */
function render(array $skills): string
{
    $lines = [
        '',
        '## Available Skills',
        '',
        'Each entry shows its source badge: `(User)` for skills the site admin created, plugin-specific labels for skills contributed by other plugins.',
        '',
        'When a skill description matches the user\'s request, call `novamira/skill-get` with the slug to load its full instructions before starting work.',
        '',
    ];
    foreach ($skills as $skill) {
        $slug = (string) ($skill['slug'] ?? '');
        $description = trim((string) ($skill['description'] ?? ''));
        $label = (string) ($skill['source_label'] ?? '');
        $lines[] = sprintf('- **`%s`** *(%s)* — %s', $slug, $label, $description);
    }
    $lines[] = '';
    return implode("\n", $lines);
}
