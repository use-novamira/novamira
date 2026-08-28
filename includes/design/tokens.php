<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Design\Tokens;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Extract structured tokens from a DESIGN.md front matter. This is targeted
 * at the known DESIGN.md shape — NOT a general YAML parser: single-level maps
 * for `colors`/`spacing`/`rounded`, two-level maps for `typography`. Indentation
 * is two spaces per level. Unknown top-level keys are ignored. Quotes around
 * scalar values are stripped.
 *
 * Used by the admin summary (and, later, previews and materialization) — never
 * by the agent path, which reads raw DESIGN.md.
 *
 * @return array{
 *   colors: array<string, string>,
 *   typography: array<string, array<string, string>>,
 *   spacing: array<string, string>,
 *   rounded: array<string, string>,
 *   components: array<string, string>,
 *   dials: array<string, string>
 * }
 */
// @mago-expect lint:cyclomatic-complexity
// @mago-expect lint:halstead
function extract(string $raw): array
{
    $colors = [];
    $typography = [];
    $spacing = [];
    $rounded = [];
    $components = [];
    $dials = [];

    $section = null;
    $token = null;

    foreach (front_matter_lines($raw) as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }
        $colon = strpos($trimmed, needle: ':');
        if ($colon === false) {
            continue;
        }
        $indent = strlen($line) - strlen(ltrim($line, characters: " \t"));
        $key = unquote(trim(substr($trimmed, offset: 0, length: $colon)));
        $value = unquote(trim(substr($trimmed, offset: $colon + 1)));

        if ($indent === 0) {
            // Flat scalar form: `rounded: "2px"` declares the section's default value.
            if ($value !== '' && $key === 'rounded') {
                $rounded['md'] = $value;
                $section = null;
                $token = null;
                continue;
            }
            if ($value !== '' && $key === 'spacing') {
                $spacing['md'] = $value;
                $section = null;
                $token = null;
                continue;
            }
            $section = $value === ''
            && in_array($key, ['colors', 'typography', 'spacing', 'rounded', 'components', 'dials'], strict: true)
                ? $key
                : null;
            $token = null;
            continue;
        }

        if ($section === 'colors' && $value !== '') {
            $colors[$key] = $value;
            continue;
        }
        if ($section === 'spacing' && $value !== '') {
            $spacing[$key] = $value;
            continue;
        }
        if ($section === 'rounded' && $value !== '') {
            $rounded[$key] = $value;
            continue;
        }
        if ($section === 'components' && $value !== '') {
            $components[$key] = $value;
            continue;
        }
        if ($section === 'dials' && $value !== '') {
            $dials[$key] = $value;
            continue;
        }
        if ($section !== 'typography') {
            continue;
        }
        if ($indent <= 2 && $value === '') {
            $token = $key;
            $typography[$key] = [];
            continue;
        }
        // Flat form: `display: Fraunces` (optionally `Fraunces 700`) declares
        // the role's family, and a trailing 100-900 is its weight.
        if ($indent <= 2) {
            $token = null;
            $fw = [];
            if (preg_match('/^(.*\S)\s+([1-9]00)$/', $value, $fw) === 1) {
                $typography[$key] = ['fontFamily' => $fw[1], 'fontWeight' => $fw[2]];
                continue;
            }
            $typography[$key] = ['fontFamily' => $value];
            continue;
        }
        if ($indent >= 4 && $token !== null && $value !== '') {
            $typography[$token][$key] = $value;
        }
    }

    if ($colors === []) {
        $colors = prose_colors($raw);
    }
    if ($typography === []) {
        $typography = prose_typography($raw);
    }

    return [
        'colors' => $colors,
        'typography' => $typography,
        'spacing' => $spacing,
        'rounded' => $rounded,
        'components' => $components,
        'dials' => $dials,
    ];
}

/**
 * Compositional dials (0-1): how the design wants layouts to feel.
 * `variance` = symmetry → asymmetry, `density` = airy → packed,
 * `motion` = static → kinetic. Missing or non-numeric values fall back to
 * sensible defaults; everything is clamped to [0, 1].
 *
 * @param array{colors: array<string,string>, typography: array<string,array<string,string>>, spacing: array<string,string>, rounded: array<string,string>, components: array<string,string>, dials: array<string,string>} $tokens
 * @return array{variance: float, density: float, motion: float}
 */
function dials(array $tokens): array
{
    return [
        'variance' => dial_value($tokens['dials'], key: 'variance', default: 0.8),
        'density' => dial_value($tokens['dials'], key: 'density', default: 0.4),
        'motion' => dial_value($tokens['dials'], key: 'motion', default: 0.5),
    ];
}

/**
 * Read one dial as a clamped float in [0, 1], or the default when absent/invalid.
 *
 * @param array<string, string> $dials
 */
function dial_value(array $dials, string $key, float $default): float
{
    $raw = $dials[$key] ?? '';
    if ($raw === '' || !is_numeric($raw)) {
        return $default;
    }
    $n = (float) $raw;
    if ($n > 1.0 && $n <= 100.0) {
        $n /= 100.0; // 0-100 scale in the wild
    }
    return max(0.0, min(1.0, $n));
}

/**
 * Heuristic fallback palette for prose-format DESIGN.md files that carry no
 * machine-readable token block: scan the document for hex colours and pick a
 * background (lightest), ink (darkest) and accent (most saturated). Returns
 * only the ones it is confident about; the rest fall back to CSS defaults so
 * the preview still renders sensibly.
 *
 * @return array<string, string>
 */
// @mago-expect lint:halstead
function prose_colors(string $raw): array
{
    $matches = [];
    if (!preg_match_all('/#(?:[0-9A-Fa-f]{6}|[0-9A-Fa-f]{3})\b/', $raw, $matches)) {
        return [];
    }

    $accent = '';
    $accent_sat = -1.0;
    $bg = '';
    $bg_lum = -1.0;
    $ink = '';
    $ink_lum = 2.0;

    foreach (array_unique($matches[0]) as $hex) {
        $full = strlen($hex) === 4 ? '#' . $hex[1] . $hex[1] . $hex[2] . $hex[2] . $hex[3] . $hex[3] : $hex;
        $r = (int) hexdec(substr($full, offset: 1, length: 2)) / 255;
        $g = (int) hexdec(substr($full, offset: 3, length: 2)) / 255;
        $b = (int) hexdec(substr($full, offset: 5, length: 2)) / 255;
        $maxc = max($r, $g, $b);
        $minc = min($r, $g, $b);
        $lum = (0.2126 * $r) + (0.7152 * $g) + (0.0722 * $b);
        $sat = $maxc <= 0.0 ? 0.0 : ($maxc - $minc) / $maxc;
        if ($sat > $accent_sat) {
            $accent_sat = $sat;
            $accent = strtoupper($full);
        }
        if ($lum > $bg_lum) {
            $bg_lum = $lum;
            $bg = strtoupper($full);
        }
        if ($lum < $ink_lum) {
            $ink_lum = $lum;
            $ink = strtoupper($full);
        }
    }

    $out = [];
    if ($bg !== '' && $bg_lum > 0.72) {
        $out['bg'] = $bg;
    }
    if ($ink !== '' && $ink_lum < 0.32) {
        $out['ink'] = $ink;
    }
    if ($accent !== '' && $accent_sat > 0.28) {
        $out['accent'] = $accent;
    }
    return $out;
}

/**
 * The full named palette for the detail view. Prefers explicitly named prose
 * colours (`**Forest Green** (#1B4332)`), which carry human labels; otherwise
 * falls back to the front-matter colour map (or the heuristic for unnamed
 * prose). Richer than css_vars(), which only needs bg/ink/accent.
 *
 * @return array<string, string>
 */
function palette(string $raw): array
{
    $named = prose_named_colors($raw);
    if (count($named) >= 2) {
        return $named;
    }
    return extract($raw)['colors'];
}

/**
 * Every `**Label** (#hex)` pair in the document, in order — the convention
 * real prose-format DESIGN.md files use to enumerate a palette with names.
 *
 * @return array<string, string>
 */
function prose_named_colors(string $raw): array
{
    $out = [];
    $matches = [];
    if (!preg_match_all(
        '/\*\*([^*\n]{1,40})\*\*\s*\((#(?:[0-9A-Fa-f]{6}|[0-9A-Fa-f]{3}))\)/',
        $raw,
        $matches,
        PREG_SET_ORDER,
    )) {
        return $out;
    }
    foreach ($matches as $match) {
        $out[trim($match[1])] = strtoupper($match[2]);
    }
    return $out;
}

/**
 * Heuristic typography for prose-format DESIGN.md files: parse the `## Typography`
 * section's `- **Role**: 72rpx extra-bold` bullets into the same shape as the
 * front-matter typography map (role => {fontFamily, fontSize, fontWeight}), so
 * the detail view renders specimens for prose imports too. Lines that carry a
 * font stack but no size seed the body/heading families for the sized roles.
 *
 * @return array<string, array<string, string>>
 */
function prose_typography(string $raw): array
{
    $body_family = '';
    $heading_family = '';
    $sized = [];
    $matches = [];
    foreach (section_body_lines($raw, ['typography', 'type', 'fonts', 'typefaces']) as $line) {
        if (preg_match('/^[-*]\s*\*\*([^*]+)\*\*\s*:?\s*(.*)$/', trim($line), $matches) !== 1) {
            continue;
        }
        $role = strtolower(trim($matches[1]));
        $rest = trim($matches[2]);
        $size = size_from_text($rest);
        if ($size === '') {
            $family = family_from_text($rest);
            if ($family !== '' && (str_contains($role, 'body') || str_contains($role, 'text'))) {
                $body_family = $family;
            }
            if ($family !== '' && !str_contains($role, 'body') && !str_contains($role, 'text')) {
                $heading_family = $family;
            }
            continue;
        }
        $sized[$role] = ['fontSize' => $size, 'fontWeight' => weight_from_text($rest)];
    }

    [$heading_family, $body_family] = resolve_prose_families($raw, $heading_family, $body_family);
    $out = [];
    foreach ($sized as $role => $props) {
        $is_heading = preg_match('/^(display|h[1-6]|headline|title|heading|hero|kicker)/', $role) === 1;
        $family = $is_heading && $heading_family !== '' ? $heading_family : $body_family;
        $row = [];
        if ($family !== '') {
            $row['fontFamily'] = $family;
        }
        if ($props['fontWeight'] !== '') {
            $row['fontWeight'] = $props['fontWeight'];
        }
        $row['fontSize'] = $props['fontSize'];
        $out[$role] = $row;
    }
    return with_bare_font_roles($out, $heading_family, $body_family);
}

/**
 * Resolve the heading/body families for a prose import: keep what the bullets
 * found, otherwise fall back to explicit declarations, then let each role stand
 * in for the other so a single declared family still yields an activatable pair.
 *
 * @return array{0: string, 1: string}
 */
function resolve_prose_families(string $raw, string $heading_family, string $body_family): array
{
    $explicit = explicit_font_families($raw);
    if ($heading_family === '') {
        $heading_family = $explicit['heading'];
    }
    if ($body_family === '') {
        $body_family = $explicit['body'];
    }
    if ($body_family === '') {
        $body_family = $heading_family;
    }
    if ($heading_family === '') {
        $heading_family = $body_family;
    }
    return [$heading_family, $body_family];
}

/**
 * Add bare heading/body roles carrying the resolved families when no sized
 * bullet already declared them, so a typography section that only names the
 * fonts still surfaces the pair (and the design stays activatable).
 *
 * @param array<string, array<string, string>> $out
 * @return array<string, array<string, string>>
 */
function with_bare_font_roles(array $out, string $heading_family, string $body_family): array
{
    if ($heading_family !== '' && !array_key_exists('heading', $out)) {
        $out['heading'] = ['fontFamily' => $heading_family];
    }
    if ($body_family !== '' && !array_key_exists('body', $out)) {
        $out['body'] = ['fontFamily' => $body_family];
    }
    return $out;
}

/**
 * Heading/body font families declared outside the `- **Role**: 72rpx …` bullet
 * form, scanned across the whole document: CSS custom properties such as
 * `--font-family-heading: "Manrope", …` (and `--heading-font`, `--font-body`,
 * …) and labelled lines such as `*Heading font:* Manrope`. Other design tools
 * export these forms; without them the families are missed and the design saves
 * without an activatable typography pair. CSS properties win over labels.
 *
 * @return array{heading: string, body: string}
 */
function explicit_font_families(string $raw): array
{
    $vars = css_var_font_families($raw);
    $labels = label_font_families($raw);
    return [
        'heading' => css_value($vars['heading'] !== '' ? $vars['heading'] : $labels['heading']),
        'body' => css_value($vars['body'] !== '' ? $vars['body'] : $labels['body']),
    ];
}

/**
 * Heading/body families read from CSS custom properties anywhere in the
 * document: `--font-family-heading`, `--heading-font`, `--font-body`, … . First
 * match per role wins.
 *
 * @return array{heading: string, body: string}
 */
function css_var_font_families(string $raw): array
{
    $heading = '';
    $body = '';
    $vars = [];
    if (preg_match_all('/--([a-z0-9-]+)\s*:\s*([^;\n{}]+)/i', $raw, $vars, PREG_SET_ORDER) === false) {
        return ['heading' => '', 'body' => ''];
    }
    foreach ($vars as $match) {
        $name = strtolower($match[1]);
        $value = trim($match[2]);
        if ($value === '') {
            continue;
        }
        if (
            $heading === ''
            && preg_match(
                '/(heading|display|headline|title)-?font|font-?(family-)?(heading|display|headline|title)/',
                $name,
            ) === 1
        ) {
            $heading = $value;
            continue;
        }
        if ($body === '' && preg_match('/(body|text)-?font|font-?(family-)?(body|text)/', $name) === 1) {
            $body = $value;
        }
    }
    return ['heading' => $heading, 'body' => $body];
}

/**
 * Heading/body families read from labelled lines such as `*Heading font:*
 * Manrope` or `Body font: Inter`, with markdown emphasis stripped. First match
 * per role wins.
 *
 * @return array{heading: string, body: string}
 */
function label_font_families(string $raw): array
{
    $heading = '';
    $body = '';
    $labels = [];
    foreach (explode(separator: "\n", string: $raw) as $line) {
        $clean = trim(str_replace(search: ['*', '_', '`'], replace: '', subject: $line));
        if (preg_match('/^(heading|display|body|text)\s+font\s*:\s*(.+)$/i', $clean, $labels) !== 1) {
            continue;
        }
        $role = strtolower($labels[1]);
        $value = trim($labels[2]);
        if ($value === '') {
            continue;
        }
        if ($heading === '' && ($role === 'heading' || $role === 'display')) {
            $heading = $value;
            continue;
        }
        if ($body === '' && ($role === 'body' || $role === 'text')) {
            $body = $value;
        }
    }

    return ['heading' => $heading, 'body' => $body];
}

/**
 * Lines of the first section whose `##`/`###` heading matches one of $titles
 * (case-insensitive), excluding the heading, up to the next heading.
 *
 * @param list<string> $titles
 * @return list<string>
 */
function section_body_lines(string $raw, array $titles): array
{
    $normalized = normalize_md($raw);
    $wanted = array_map(static fn(string $title): string => strtolower($title), $titles);
    $lines = [];
    $capturing = false;
    $matches = [];
    foreach (explode(separator: "\n", string: $normalized) as $line) {
        if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $matches) !== 1) {
            if ($capturing) {
                $lines[] = $line;
            }
            continue;
        }
        // Deeper headings (### and below) are content, not section boundaries.
        if (strlen($matches[1]) >= 3) {
            if ($capturing) {
                $lines[] = $line;
            }
            continue;
        }
        if ($capturing) {
            break;
        }
        $capturing = title_matches(strtolower(trim($matches[2])), $wanted);
    }
    return $lines;
}

/**
 * Whether a heading text matches a wanted title — exactly or by prefix, so
 * "Elevation -- Colored Glow System" is found by the keyword "elevation".
 *
 * @param list<string> $wanted
 */
function title_matches(string $head, array $wanted): bool
{
    foreach ($wanted as $title) {
        if ($head === $title || str_starts_with($head, $title)) {
            return true;
        }
    }
    return false;
}

/**
 * First CSS length (digits + unit) in $text, normalized like "72rpx", else ''.
 * The lookbehind skips the tail of a range such as the "6rpx" in "4-6rpx" so a
 * letter-spacing note is not mistaken for a font size.
 */
function size_from_text(string $text): string
{
    $matches = [];
    if (preg_match('/(?<![\d.-])(\d+(?:\.\d+)?)\s*(rpx|px|rem|em|pt)\b/i', $text, $matches) !== 1) {
        return '';
    }
    return $matches[1] . strtolower($matches[2]);
}

/** Map a weight word or numeric weight in $text to a CSS font-weight, else ''. */
function weight_from_text(string $text): string
{
    $matches = [];
    if (preg_match('/\b([1-9]00)\b/', $text, $matches) === 1) {
        return $matches[1];
    }
    $lower = strtolower($text);
    $map = [
        'extra-bold' => '800',
        'extrabold' => '800',
        'ultra-bold' => '800',
        'black' => '900',
        'heavy' => '900',
        'semi-bold' => '600',
        'semibold' => '600',
        'bold' => '700',
        'medium' => '500',
        'regular' => '400',
        'normal' => '400',
        'light' => '300',
        'thin' => '100',
    ];
    foreach ($map as $word => $weight) {
        if (str_contains($lower, $word)) {
            return $weight;
        }
    }
    return '';
}

/**
 * A real CSS font stack in $text, else ''. A stack is comma-separated and free
 * of parentheses and digits, which rules out prose descriptors that merely
 * happen to contain commas ("System font stack, extra-bold (800), …").
 */
function family_from_text(string $text): string
{
    if (!str_contains($text, ',') || str_contains($text, '(') || preg_match('/\d/', $text) === 1) {
        return '';
    }
    return trim($text);
}

/**
 * Convert a token size label to a clamped pixel size for on-screen specimens.
 * rpx (WeChat responsive px) maps at ~0.5, rem/em at 16, pt at 1.333; the
 * result is clamped to a legible preview range so a 72rpx display still reads
 * large and a 20rpx caption stays small but visible.
 */
// @mago-expect analysis:invalid-type-cast
function display_px(string $size): string
{
    $matches = [];
    if (preg_match('/(\d+(?:\.\d+)?)\s*(rpx|px|rem|em|pt)?/i', $size, $matches) !== 1) {
        return '';
    }
    $n = (float) $matches[1];
    $px = match (strtolower($matches[2] ?? 'px')) {
        'rpx' => $n * 0.5,
        'rem', 'em' => $n * 16.0,
        'pt' => $n * 1.3333,
        default => $n,
    };
    return (string) (int) round(max(11.0, min(48.0, $px))) . 'px';
}

/**
 * Shadow specimens for prose-format DESIGN.md: parse an `## Elevation`/`## Shadows`
 * section's `- **shadow-x**: 0 4rpx 20rpx teal at 30%` bullets into real CSS
 * box-shadow values, resolving the colour word against $palette. The original
 * spec text is kept for the caption. name => {css, spec}.
 *
 * @param array<string, string> $palette
 * @return array<string, array{css: string, spec: string}>
 */
function prose_shadows(string $raw, array $palette): array
{
    $out = [];
    $matches = [];
    foreach (section_body_lines($raw, ['shadows', 'shadow', 'elevation', 'depth']) as $line) {
        if (preg_match('/^[-*]\s*\*\*([^*]+)\*\*\s*:?\s*(.*)$/', trim($line), $matches) !== 1) {
            continue;
        }
        $spec = trim($matches[2]);
        $css = shadow_css($spec, $palette);
        if ($css !== '') {
            $out[trim($matches[1])] = ['css' => $css, 'spec' => $spec];
        }
    }
    return $out;
}

/**
 * Convert a prose shadow spec ("0 4rpx 20rpx coral at 35%") to a CSS box-shadow
 * value, or '' when it cannot be resolved. rpx lengths halve to px, the trailing
 * colour word resolves against $palette, and "at N%" becomes the alpha channel.
 *
 * @param array<string, string> $palette
 */
// @mago-expect analysis:invalid-type-cast
function shadow_css(string $spec, array $palette): string
{
    $parts = preg_split('/\s+at\s+/i', trim($spec), limit: 2);
    if ($parts === false || $parts[0] === '') {
        return '';
    }
    $alpha = 1.0;
    $pm = [];
    if (count($parts) === 2 && preg_match('/(\d+(?:\.\d+)?)\s*%/', $parts[1], $pm) === 1) {
        $alpha = (float) $pm[1] / 100.0;
    }
    $pieces = preg_split('/\s+/', trim($parts[0]));
    if ($pieces === false) {
        return '';
    }
    $lengths = [];
    $color_word = '';
    foreach ($pieces as $piece) {
        if ($piece === '0' || preg_match('/^-?\d/', $piece) === 1) {
            $lengths[] = length_px($piece);
            continue;
        }
        $color_word = $piece;
    }
    if ($lengths === [] || $color_word === '') {
        return '';
    }
    $rgb = shadow_rgb($color_word, $palette);
    if ($rgb === '') {
        return '';
    }
    return implode(' ', $lengths) . ' rgba(' . $rgb . ', ' . format_alpha($alpha) . ')';
}

/** Format an alpha in [0,1] for CSS, trimming trailing zeros (0.30 -> "0.3"). */
function format_alpha(float $alpha): string
{
    $fixed = number_format(round($alpha, precision: 2), decimals: 2, decimal_separator: '.', thousands_separator: '');
    $text = rtrim(rtrim($fixed, characters: '0'), characters: '.');
    return $text === '' ? '0' : $text;
}

/** A single shadow length token to px ("4rpx" -> "2px", "0" -> "0"). */
// @mago-expect analysis:invalid-type-cast
function length_px(string $length): string
{
    if ($length === '0') {
        return '0';
    }
    $matches = [];
    if (preg_match('/^(-?\d+(?:\.\d+)?)\s*(rpx|px|rem|em)?$/i', $length, $matches) !== 1) {
        return '0';
    }
    $n = (float) $matches[1];
    $px = match (strtolower($matches[2] ?? 'px')) {
        'rpx' => $n * 0.5,
        'rem', 'em' => $n * 16.0,
        default => $n,
    };
    return (string) (int) round($px) . 'px';
}

/**
 * Resolve a shadow colour word ("teal", "coral", "primary", "black") to an
 * "r, g, b" triple — basic names first, then a substring match against the
 * named palette. Returns '' when nothing matches.
 *
 * @param array<string, string> $palette
 */
function shadow_rgb(string $word, array $palette): string
{
    $needle = str_replace(search: '-', replace: ' ', subject: strtolower(trim($word)));
    if ($needle === 'black') {
        return '0, 0, 0';
    }
    if ($needle === 'white') {
        return '255, 255, 255';
    }
    foreach ($palette as $name => $hex) {
        if (str_contains(str_replace(search: '-', replace: ' ', subject: strtolower($name)), $needle)) {
            return hex_to_rgb($hex);
        }
    }
    return '';
}

/** "#2BA8A2" -> "43, 168, 162", or '' if not a hex colour. */
function hex_to_rgb(string $hex): string
{
    $h = ltrim($hex, characters: '#');
    if (strlen($h) === 3) {
        $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
    }
    if (preg_match('/^[0-9A-Fa-f]{6}$/', $h) !== 1) {
        return '';
    }
    return (
        (string) hexdec(substr($h, offset: 0, length: 2))
        . ', '
        . (string) hexdec(substr($h, offset: 2, length: 2))
        . ', '
        . (string) hexdec(substr($h, offset: 4, length: 2))
    );
}

/**
 * Animation specimens for prose-format DESIGN.md: parse an `## Animations`
 * section's `### Name` headings plus their bullet descriptions into name =>
 * description.
 *
 * @return array<string, string>
 */
function prose_animations(string $raw): array
{
    $out = [];
    $current = '';
    $matches = [];
    foreach (section_body_lines($raw, ['animations', 'animation', 'motion']) as $line) {
        $trimmed = trim($line);
        if (preg_match('/^#{3,6}\s+(.*)$/', $trimmed, $matches) === 1) {
            $current = trim($matches[1]);
            continue;
        }
        if ($current === '' || preg_match('/^[-*]\s+(.*)$/', $trimmed, $matches) !== 1) {
            continue;
        }
        $piece = trim($matches[1]);
        $existing = $out[$current] ?? '';
        $out[$current] = $existing === '' ? $piece : $existing . ' ' . $piece;
    }
    return $out;
}

/**
 * Normalize raw DESIGN.md for parsing: CRLF to LF, strip a UTF-8 BOM, and
 * trim trailing whitespace off `---` fences. Files downloaded from the wild
 * routinely carry all three.
 */
function normalize_md(string $raw): string
{
    $n = preg_replace(pattern: '/\r\n?/', replacement: "\n", subject: $raw);
    if (!is_string($n)) {
        $n = $raw;
    }
    if (str_starts_with($n, "\xEF\xBB\xBF")) {
        $n = substr($n, offset: 3);
    }
    $fenced = preg_replace(pattern: '/^(---)[ \t]+$/m', replacement: '$1', subject: $n);
    return is_string($fenced) ? $fenced : $n;
}

/**
 * Fold typographic apostrophes (right/left single quotation marks and the
 * modifier letter apostrophe) into the ASCII `'` so "Don’t" and "Don't" read
 * the same to the section-title and Do/Don't matchers. Matching only: the
 * stored document is never rewritten.
 */
function normalize_apostrophes(string $text): string
{
    return str_replace(search: ["\u{2019}", "\u{2018}", "\u{02BC}"], replace: "'", subject: $text);
}

/**
 * Return the front-matter block lines (between the opening and closing `---`),
 * or [] if there is no valid front matter.
 *
 * @return list<string>
 */
function front_matter_lines(string $raw): array
{
    $normalized = normalize_md($raw);
    if (!str_starts_with($normalized, "---\n")) {
        return [];
    }
    $closing = strpos($normalized, needle: "\n---\n", offset: 4);
    if ($closing === false && str_ends_with($normalized, "\n---")) {
        $closing = strlen($normalized) - 4;
    }
    if ($closing === false) {
        return [];
    }
    $front = substr($normalized, offset: 4, length: $closing - 4);
    return explode(separator: "\n", string: $front);
}

/** A bare number is a pixel length; anything else passes through. */
function css_length(string $value): string
{
    return is_numeric($value) ? $value . 'px' : $value;
}

function unquote(string $value): string
{
    if (strlen($value) >= 2) {
        if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
            return substr($value, offset: 1, length: -1);
        }
        if (str_starts_with($value, "'") && str_ends_with($value, "'")) {
            return substr($value, offset: 1, length: -1);
        }
    }
    return $value;
}

/**
 * Sanitize a token value for safe use inside an inline `style="prop:VALUE"`
 * attribute. esc_attr() already blocks attribute breakout (quotes/markup);
 * this additionally strips characters that could inject extra CSS
 * declarations (`;`) or stray markup, so an imported DESIGN.md cannot smuggle
 * CSS into the admin summary preview.
 */
function css_value(string $value): string
{
    return trim(str_replace(search: [';', '{', '}', '<', '>'], replace: '', subject: $value));
}

/**
 * Map extracted tokens to the canonical preview/materialization CSS custom
 * properties. Name fallbacks tolerate DESIGN.md files that use different role
 * names. Every value is run through css_value() so it is safe in inline CSS.
 *
 * @param array{colors: array<string,string>, typography: array<string,array<string,string>>, spacing: array<string,string>, rounded: array<string,string>, components: array<string,string>, dials: array<string,string>} $tokens
 * @return array<string, string>
 */
function css_vars(array $tokens): array
{
    $vars = [];
    $bg = pick_value($tokens['colors'], ['bg', 'background', 'ground', 'surface', 'base', 'paper']);
    $ink = pick_value($tokens['colors'], ['ink', 'text', 'foreground', 'fg', 'body', 'on-background', 'on-surface']);
    $accent = pick_value($tokens['colors'], ['accent', 'primary', 'brand', 'action']);
    if ($bg !== '') {
        $vars['--nd-bg'] = $bg;
    }
    if ($ink !== '') {
        $vars['--nd-ink'] = $ink;
    }
    if ($accent !== '') {
        $vars['--nd-accent'] = $accent;
    }
    $heading = pick_typography($tokens['typography'], ['heading', 'display', 'headline', 'title', 'hero']);
    $body = pick_typography($tokens['typography'], ['body', 'text', 'paragraph']);
    if ($heading !== '') {
        $vars['--nd-font-heading'] = $heading;
    }
    if ($body !== '') {
        $vars['--nd-font-body'] = $body;
    }
    $heading_weight = pick_typography_prop(
        $tokens['typography'],
        ['heading', 'display', 'headline', 'title', 'hero'],
        prop: 'fontWeight',
    );
    if ($heading_weight !== '') {
        $vars['--nd-weight-heading'] = $heading_weight;
    }
    $radius = $tokens['rounded']['md'] ?? first_value($tokens['rounded']);
    if ($radius !== '') {
        $vars['--nd-radius'] = css_length(css_value($radius));
    }
    $space = $tokens['spacing']['md'] ?? first_value($tokens['spacing']);
    if ($space !== '') {
        $vars['--nd-space'] = css_length(css_value($space));
    }
    return $vars;
}

/**
 * Serialize css_vars() to an inline-style string (values already sanitized).
 *
 * @param array{colors: array<string,string>, typography: array<string,array<string,string>>, spacing: array<string,string>, rounded: array<string,string>, components: array<string,string>, dials: array<string,string>} $tokens
 */
function css_vars_string(array $tokens): string
{
    $out = '';
    foreach (css_vars($tokens) as $prop => $value) {
        $out .= $prop . ':' . $value . ';';
    }
    return $out;
}

/**
 * @param array<string, string> $map
 * @param list<string> $keys
 */
function pick_value(array $map, array $keys): string
{
    $lower = [];
    foreach ($map as $map_key => $map_value) {
        $lower_key = strtolower($map_key);
        if (!array_key_exists($lower_key, $lower)) {
            $lower[$lower_key] = $map_value;
        }
    }
    foreach ($keys as $key) {
        if (($lower[$key] ?? '') !== '') {
            return css_value($lower[$key]);
        }
    }
    return '';
}

/**
 * @param array<string, array<string, string>> $typography
 * @param list<string> $roles
 */
function pick_typography(array $typography, array $roles): string
{
    return pick_typography_prop($typography, $roles, prop: 'fontFamily');
}

/**
 * First non-empty value of a given typography property among $roles, sanitized.
 *
 * @param array<string, array<string, string>> $typography
 * @param list<string> $roles
 */
function pick_typography_prop(array $typography, array $roles, string $prop): string
{
    $lower = [];
    foreach ($typography as $role_key => $props) {
        $lower_key = strtolower($role_key);
        if (!array_key_exists($lower_key, $lower)) {
            $lower[$lower_key] = $props;
        }
    }
    foreach ($roles as $role) {
        if (($lower[$role][$prop] ?? '') !== '') {
            return css_value($lower[$role][$prop]);
        }
    }
    // Scale naming (Material/Tailwind and similar): roles are named display-hero,
    // headline-xl, body-md, .... Match the leading segment so display-hero counts
    // as a display role and body-md as a body role.
    foreach ($roles as $role) {
        foreach ($lower as $role_key => $props) {
            if (str_starts_with($role_key, $role) && ($props[$prop] ?? '') !== '') {
                return css_value($props[$prop]);
            }
        }
    }
    return '';
}

/** @param array<string, string> $map */
function first_value(array $map): string
{
    foreach ($map as $value) {
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}
