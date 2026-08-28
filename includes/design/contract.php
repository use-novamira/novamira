<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Design\Contract;

use Novamira\Design\Markdown;
use Novamira\Design\Preflight;
use Novamira\Design\Tokens;

if (!defined('ABSPATH')) {
    exit();
}

/** JSON Schema properties shared by design ability responses. */
function ability_output_properties(): array
{
    return [
        'tokens' => ['type' => 'object'],
        'dials' => [
            'type' => 'object',
            'properties' => [
                'variance' => ['type' => 'number'],
                'density' => ['type' => 'number'],
                'motion' => ['type' => 'number'],
            ],
        ],
        'guidance' => [
            'type' => 'object',
            'properties' => [
                'dos' => ['type' => 'array', 'items' => ['type' => 'string']],
                'donts' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ],
        'token_sources' => ['type' => 'object'],
        'readiness' => [
            'type' => 'object',
            'properties' => [
                'ready' => ['type' => 'boolean'],
                'sync_ready' => ['type' => 'boolean'],
                'errors' => ['type' => 'array', 'items' => ['type' => 'string']],
                'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['ready', 'sync_ready', 'errors', 'warnings'],
        ],
        'waivers' => ['type' => 'array', 'items' => ['type' => 'string']],
    ];
}

/**
 * Inspect a DESIGN.md without changing it. The raw document remains the source
 * of truth; this is the stable machine-readable view abilities and sync
 * consumers can use without re-parsing Markdown themselves.
 *
 * `ready` means the design has the minimum direction required for activation.
 * `sync_ready` is deliberately stricter: colors and typography must be explicit
 * front-matter tokens, not values inferred heuristically from narrative prose.
 *
 * @return array{
 *   tokens: array{
 *     colors: array<string, string>,
 *     typography: array<string, array<string, string>>,
 *     spacing: array<string, string>,
 *     rounded: array<string, string>,
 *     components: array<string, string>
 *   },
 *   dials: array{variance: float, density: float, motion: float},
 *   guidance: array{dos: list<string>, donts: list<string>},
 *   token_sources: array{colors: string, typography: string, spacing: string, rounded: string, components: string, dials: string},
 *   readiness: array{ready: bool, sync_ready: bool, errors: list<string>, warnings: list<string>},
 *   waivers: list<string>
 * }
 */
function inspect(string $content): array
{
    $all_tokens = Tokens\extract($content);
    $sources = token_sources($content, $all_tokens);
    $parsed = Markdown\guidance($content, guidance_titles());
    $readiness = readiness($all_tokens, $sources, guidance_source($parsed));

    return [
        'tokens' => [
            'colors' => $all_tokens['colors'],
            'typography' => $all_tokens['typography'],
            'spacing' => $all_tokens['spacing'],
            'rounded' => $all_tokens['rounded'],
            'components' => $all_tokens['components'],
        ],
        'dials' => Tokens\dials($all_tokens),
        'guidance' => plain_guidance($parsed),
        'token_sources' => $sources,
        'readiness' => $readiness,
        // Anti-slop rules this design waives, so the agent knows what is
        // approved house style and will not be flagged by the pre-flight.
        'waivers' => Preflight\waivers($content),
    ];
}

/**
 * Semantic activation gate. Missing optional craft tokens and missing Do/Don't
 * guidance stay warnings so imported real-world DESIGN.md files remain usable.
 *
 * @param array{
 *   colors: array<string, string>,
 *   typography: array<string, array<string, string>>,
 *   spacing: array<string, string>,
 *   rounded: array<string, string>,
 *   components: array<string, string>,
 *   dials: array<string, string>
 * } $tokens
 * @param array{colors: string, typography: string, spacing: string, rounded: string, components: string, dials: string} $sources
 * @param string $guidance_source One of guidance_source()'s values.
 * @return array{ready: bool, sync_ready: bool, errors: list<string>, warnings: list<string>}
 */
function readiness(array $tokens, array $sources, string $guidance_source): array
{
    $errors = required_token_errors($tokens);
    $warnings = readiness_warnings($sources, $guidance_source);
    $ready = $errors === [];
    return [
        'ready' => $ready,
        'sync_ready' => $ready && $sources['colors'] === 'explicit' && $sources['typography'] === 'explicit',
        'errors' => $errors,
        'warnings' => $warnings,
    ];
}

/**
 * @param array{
 *   colors: array<string, string>,
 *   typography: array<string, array<string, string>>,
 *   spacing: array<string, string>,
 *   rounded: array<string, string>,
 *   components: array<string, string>,
 *   dials: array<string, string>
 * } $tokens
 * @return list<string>
 */
function required_token_errors(array $tokens): array
{
    $errors = [];
    $vars = Tokens\css_vars($tokens);

    foreach ([
        '--nd-bg' => __('A background color is required.', domain: 'novamira'),
        '--nd-ink' => __('An ink/text color is required.', domain: 'novamira'),
        '--nd-accent' => __('An accent color is required.', domain: 'novamira'),
    ] as $property => $missing_message) {
        $value = $vars[$property] ?? '';
        if ($value === '') {
            $errors[] = $missing_message;
            continue;
        }
        if (Preflight\normalize_hex($value) === '') {
            $errors[] = sprintf(
                /* translators: %s: CSS custom property identifying the color role */
                __('The color for %s must be an exact hex value.', domain: 'novamira'),
                $property,
            );
        }
    }

    if (($vars['--nd-font-heading'] ?? '') === '') {
        $errors[] = __('A heading/display font is required.', domain: 'novamira');
    }
    if (($vars['--nd-font-body'] ?? '') === '') {
        $errors[] = __('A body font is required.', domain: 'novamira');
    }
    return $errors;
}

/**
 * @param array{colors: string, typography: string, spacing: string, rounded: string, components: string, dials: string} $sources
 * @param string $guidance_source One of guidance_source()'s values.
 * @return list<string>
 */
function readiness_warnings(array $sources, string $guidance_source): array
{
    $warnings = [];

    foreach ([
        'colors' => __(
            'Colors were inferred from prose; make the roles explicit before syncing site globals.',
            domain: 'novamira',
        ),
        'typography' => __(
            'Typography was inferred from prose; make the roles explicit before syncing site globals.',
            domain: 'novamira',
        ),
    ] as $key => $message) {
        if ($sources[$key] !== 'inferred') {
            continue;
        }
        $warnings[] = $message;
    }
    if ($sources['spacing'] === 'missing') {
        $warnings[] = __('No spacing tokens are declared.', domain: 'novamira');
    }
    if ($sources['rounded'] === 'missing') {
        $warnings[] = __('No corner-radius tokens are declared.', domain: 'novamira');
    }
    if ($sources['components'] === 'missing') {
        $warnings[] = __('No component treatments are declared.', domain: 'novamira');
    }
    if ($sources['dials'] === 'missing') {
        $warnings[] = __('No compositional dials are declared; defaults will be used.', domain: 'novamira');
    }
    $guidance_warning = guidance_warning($guidance_source);
    if ($guidance_warning !== '') {
        $warnings[] = $guidance_warning;
    }

    return $warnings;
}

/** The readiness warning for a guidance_source() value, or '' when items were recognised. */
function guidance_warning(string $guidance_source): string
{
    return match ($guidance_source) {
        'missing' => __(
            "No Do's and Don'ts section was found; design checks will only enforce tokens and the universal rules.",
            domain: 'novamira',
        ),
        'empty' => __(
            "A guidance section was found but no Do/Don't items were recognised, so design checks will only enforce tokens and the universal rules. Group bullets under Do's / Don'ts labels or start each one with a prefix such as Do, Always, Ensure, Prefer, Don't, Do not, Never or Avoid.",
            domain: 'novamira',
        ),
        default => '',
    };
}

/**
 * Whether the document's Do/Don't guidance is usable: 'explicit' when at least
 * one item was recognised, 'empty' when a guidance section exists but none of
 * its lines read as a Do or a Don't, 'missing' when there is no such section.
 *
 * @param array{dos: list<string>, donts: list<string>, rest: string, found: bool} $parsed
 */
function guidance_source(array $parsed): string
{
    if (!$parsed['found']) {
        return 'missing';
    }
    return $parsed['dos'] === [] && $parsed['donts'] === [] ? 'empty' : 'explicit';
}

/**
 * Say whether each token family was declared, inferred from prose, or absent.
 *
 * @param array{
 *   colors: array<string, string>,
 *   typography: array<string, array<string, string>>,
 *   spacing: array<string, string>,
 *   rounded: array<string, string>,
 *   components: array<string, string>,
 *   dials: array<string, string>
 * } $tokens
 * @return array{colors: string, typography: string, spacing: string, rounded: string, components: string, dials: string}
 */
function token_sources(string $content, array $tokens): array
{
    return [
        'colors' => token_source($content, key: 'colors', values: $tokens['colors']),
        'typography' => token_source($content, key: 'typography', values: $tokens['typography']),
        'spacing' => token_source($content, key: 'spacing', values: $tokens['spacing']),
        'rounded' => token_source($content, key: 'rounded', values: $tokens['rounded']),
        'components' => token_source($content, key: 'components', values: $tokens['components']),
        'dials' => token_source($content, key: 'dials', values: $tokens['dials']),
    ];
}

/** @param array<array-key, mixed> $values */
function token_source(string $content, string $key, array $values): string
{
    if ($values === []) {
        return 'missing';
    }
    return has_top_level_key($content, $key) ? 'explicit' : 'inferred';
}

function has_top_level_key(string $content, string $wanted): bool
{
    foreach (Tokens\front_matter_lines($content) as $line) {
        if ($line === '' || $line[0] === ' ' || $line[0] === "\t") {
            continue;
        }
        $colon = strpos($line, needle: ':');
        if ($colon === false) {
            continue;
        }
        $key = strtolower(Tokens\unquote(trim(substr($line, offset: 0, length: $colon))));
        if ($key === strtolower($wanted)) {
            return true;
        }
    }
    return false;
}

/**
 * Section titles that hold Do/Don't guidance. Compared by label key, so
 * "Do’s & Don'ts", "Dos and Donts" and "do's and don'ts" all match; a
 * standalone "Do's" or "Don'ts" section is read as that group.
 *
 * @return list<string>
 */
function guidance_titles(): array
{
    return [
        "do's and don'ts",
        "don'ts and do's",
        "do and don't",
        "do's",
        "don'ts",
        'do',
        "don't",
        'guidelines',
        'principles',
        'rules',
    ];
}

/**
 * Plain-text Do/Don't guidance for machine consumers.
 *
 * @return array{dos: list<string>, donts: list<string>}
 */
function guidance(string $content): array
{
    return plain_guidance(Markdown\guidance($content, guidance_titles()));
}

/**
 * @param array{dos: list<string>, donts: list<string>, rest: string, found: bool} $parsed
 * @return array{dos: list<string>, donts: list<string>}
 */
function plain_guidance(array $parsed): array
{
    return [
        'dos' => plain_items($parsed['dos']),
        'donts' => plain_items($parsed['donts']),
    ];
}

/** @param list<string> $items
 * @return list<string>
 */
function plain_items(array $items): array
{
    return array_values(array_map(static fn(string $item): string => trim(html_entity_decode(
        wp_strip_all_tags($item),
        ENT_QUOTES | ENT_HTML5,
        encoding: 'UTF-8',
    )), $items));
}

/** Human-readable reason suitable for an activation error. */
function activation_error(array $inspection): string
{
    /** @var list<string> $errors */
    $errors = $inspection['readiness']['errors'] ?? [];
    return $errors !== []
        ? implode(' ', $errors)
        : __('The design is incomplete and cannot be activated.', domain: 'novamira');
}
