<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Skills\BuiltIn;

use Novamira\Skills\Parser;

if (!defined('ABSPATH')) {
    exit();
}

const SOURCE_ID = 'built-in';

const SOURCE_LABEL = 'Built-in';

/**
 * Catalog priority. Lower than user-cpt (50) so built-ins render before
 * user skills, but high enough that downstream plugins can still slot in
 * earlier (e.g. priority 5) if they want to outrank built-ins.
 */
const SOURCE_PRIORITY = 10;

/**
 * Register the bundled skill source with the lookup registry. Hooked on
 * the `novamira_skill_lookup_sources` filter; idempotent.
 *
 * @param array<string, array{id: string, priority: int, label: string, loader: callable}> $sources
 * @return array<string, array{id: string, priority: int, label: string, loader: callable}>
 */
function register(array $sources): array
{
    $sources[SOURCE_ID] = [
        'id' => SOURCE_ID,
        'priority' => SOURCE_PRIORITY,
        'label' => SOURCE_LABEL,
        'loader' => __NAMESPACE__ . '\\load',
    ];
    return $sources;
}

/**
 * Load every bundled SKILL.md file. The slug is the filename (without
 * `.md`); the frontmatter `name:` is informational only.
 *
 * Memoized for the duration of a request — the lookup registry calls this
 * from several spots (catalog inject, prompts register, admin list) and
 * re-parsing on every call would be wasteful.
 *
 * @return list<array{slug: string, name: string, description: string, content: string, enable_prompt: bool, enable_agentic: bool}>
 */
function load(): array
{
    /** @var list<array{slug: string, name: string, description: string, content: string, enable_prompt: bool, enable_agentic: bool}>|null $cached */
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    $result = [];
    $dir = __DIR__ . '/built-in';
    $files = is_dir($dir) ? glob($dir . '/*.md') : false;

    if (is_array($files)) {
        sort($files);
        foreach ($files as $path) {
            $slug = Parser\normalize_slug(basename($path, suffix: '.md'));
            if ($slug === '') {
                continue;
            }
            $raw = file_get_contents($path);
            if ($raw === false) {
                continue;
            }
            $parsed = Parser\parse($raw);
            if ($parsed['parse_error'] !== null) {
                continue;
            }
            if (trim($parsed['body']) === '') {
                continue;
            }
            $result[] = [
                'slug' => $slug,
                'name' => $parsed['name'] !== '' ? $parsed['name'] : $slug,
                'description' => $parsed['description'],
                'content' => $parsed['body'],
                'enable_prompt' => $parsed['enable_prompt'],
                'enable_agentic' => $parsed['enable_agentic'],
            ];
        }
    }

    $cached = $result;
    return $result;
}
