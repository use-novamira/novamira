<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Skills\Parser;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Maximum body length (bytes) for an uploaded or submitted skill. Sanity cap.
 */
const MAX_BODY_BYTES = 1_048_576; // 1 MB

/**
 * Unescape common C-style escape sequences in a skill body.
 *
 * AI clients sometimes JSON-encode their tool-call arguments twice, with
 * the result that real newlines arrive as the two-character sequence `\n`
 * instead of a real newline. The same can happen with `\t`, `\r`, etc.
 * Nobody actually wants those escape sequences as literal text in a
 * skill body, so we run `stripcslashes` on the way in — it converts
 * `\n` → LF, `\t` → tab, `\\` → `\`, `\"` → `"`, and so on.
 *
 * `stripcslashes` also drops the backslash from every other sequence, so
 * it would turn `\Elementor\Plugin` into `ElementorPlugin` and `\d` into
 * `d`. Only a body that was encoded twice arrives with a literal `\n` and
 * no real line break; any other body (an uploaded SKILL.md, the admin
 * editor, a correctly encoded tool call) is returned unchanged.
 */
function unescape_content(string $raw): string
{
    if (str_contains($raw, "\n") || !str_contains($raw, '\n')) {
        return $raw;
    }

    return stripcslashes($raw);
}

/**
 * Strip a single layer of matching surrounding quotes (single or double) from a
 * trimmed frontmatter value. Leaves the value untouched if it isn't quoted.
 */
function unquote_value(string $value): string
{
    if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
        return substr($value, offset: 1, length: -1);
    }
    if (str_starts_with($value, "'") && str_ends_with($value, "'")) {
        return substr($value, offset: 1, length: -1);
    }
    return $value;
}

/**
 * Parse the raw frontmatter block (the text between the `---` fences) into the
 * recognized fields. Blank lines, comment lines (`#`), lines without a colon,
 * and unknown keys are ignored (lenient). Unspecified fields keep their default.
 *
 * @return array{name: string, description: string, enable_prompt: bool, enable_agentic: bool, read_mode: bool}
 */
function parse_frontmatter(string $frontmatter_raw): array
{
    $name = '';
    $description = '';
    $enable_prompt = true;
    $enable_agentic = true;
    $read_mode = false;

    foreach (explode(separator: "\n", string: $frontmatter_raw) as $line) {
        if (trim($line) === '' || str_starts_with(trim($line), '#')) {
            continue;
        }
        $colon = strpos($line, needle: ':');
        if ($colon === false) {
            continue;
        }
        $key = strtolower(trim(substr($line, offset: 0, length: $colon)));
        $value = unquote_value(trim(substr($line, offset: $colon + 1)));
        switch ($key) {
            case 'name':
                $name = $value;
                break;
            case 'description':
                $description = $value;
                break;
            case 'enable_prompt':
                $enable_prompt = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
                break;
            case 'enable_agentic':
                $enable_agentic = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
                break;
            case 'read_mode':
                $read_mode = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
                break;

            // Unknown keys silently ignored (lenient).
        }
    }

    return [
        'name' => $name,
        'description' => $description,
        'enable_prompt' => $enable_prompt,
        'enable_agentic' => $enable_agentic,
        'read_mode' => $read_mode,
    ];
}

/**
 * Parse a SKILL.md string. Lenient: a malformed frontmatter block is reported
 * via `parse_error`, missing fields fall back to sensible defaults, and unknown
 * keys are silently ignored.
 *
 * @return array{name: string, description: string, enable_prompt: bool, enable_agentic: bool, read_mode: bool, body: string, parse_error: ?string}
 */
function parse(string $raw): array
{
    $body = $raw;
    $parse_error = null;
    $fields = [
        'name' => '',
        'description' => '',
        'enable_prompt' => true,
        'enable_agentic' => true,
        'read_mode' => false,
    ];

    $normalized = preg_replace(pattern: '/\r\n?/', replacement: "\n", subject: $raw);
    if (!is_string($normalized)) {
        $normalized = $raw;
    }

    if (str_starts_with($normalized, "---\n")) {
        $closing = strpos($normalized, needle: "\n---\n", offset: 4);
        if ($closing === false && str_ends_with($normalized, "\n---")) {
            // Allow trailing `---` with no newline after.
            $closing = strlen($normalized) - 4;
        }
        if ($closing !== false) {
            $frontmatter_raw = substr($normalized, offset: 4, length: $closing - 4);
            $body = ltrim(substr($normalized, offset: $closing + 5), characters: "\n");
            $fields = parse_frontmatter($frontmatter_raw);
        }
        if ($closing === false) {
            $parse_error = __('Frontmatter started with --- but had no closing ---', domain: 'novamira');
        }
    }

    return [
        'name' => $fields['name'],
        'description' => $fields['description'],
        'enable_prompt' => $fields['enable_prompt'],
        'enable_agentic' => $fields['enable_agentic'],
        'read_mode' => $fields['read_mode'],
        'body' => $body,
        'parse_error' => $parse_error,
    ];
}

/**
 * Normalize a slug candidate to the canonical internal form (WordPress-friendly,
 * no `custom_` prefix). Returns empty string if no usable slug can be derived —
 * the caller decides what to do with that.
 */
function normalize_slug(string $raw): string
{
    $candidate = sanitize_title($raw);
    if ($candidate === '') {
        return '';
    }
    if (strlen($candidate) > 60) {
        $candidate = rtrim(substr($candidate, offset: 0, length: 60), characters: '-');
    }
    return $candidate;
}

/**
 * Reconstruct a SKILL.md string from a skill record. The frontmatter
 * `name:` field carries the slug as-is — the slug is already the
 * sanitized identifier (`sanitize_title($post_title)`), no prefix or
 * decoration applied.
 *
 * @param array{slug?: string, description?: string, enable_prompt?: bool, enable_agentic?: bool, read_mode?: bool, content?: string} $skill
 */
function render_skill_md(array $skill): string
{
    return sprintf(
        "---\nname: %s\ndescription: %s\nenable_prompt: %s\nenable_agentic: %s\n%s---\n\n%s",
        $skill['slug'] ?? '',
        str_replace(search: "\n", replace: ' ', subject: $skill['description'] ?? ''),
        $skill['enable_prompt'] ?? false ? 'true' : 'false',
        $skill['enable_agentic'] ?? true ? 'true' : 'false',
        $skill['read_mode'] ?? false ? "read_mode: true\n" : '',
        $skill['content'] ?? '',
    );
}
