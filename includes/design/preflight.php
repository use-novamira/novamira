<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Anti-slop pre-flight rules. Pure logic over a candidate output string
 * (the HTML/CSS or visible text the agent is about to ship). Two rule
 * families: universal AI-tells that fire regardless of the active design,
 * and design-conditional checks that compare the output against the active
 * design's tokens and "Don't" rules. No WordPress state is read here — the
 * ability layer gathers the active design and passes it in as context.
 */

namespace Novamira\Design\Preflight;

use Novamira\Design\Contract;
use Novamira\Design\Tokens;

if (!defined('ABSPATH')) {
    exit();
}

/** Rule ids that are mechanically checked here. */
const MECHANIZED = [
    'em-dash',
    'ai-purple',
    'inter-font',
    'warm-craft-palette',
    'filler-copy',
    'generic-names',
    'font-off-palette',
    'color-off-palette',
    'design-dont',
];

/**
 * Rules from the design-system philosophy that need a structured page model
 * (DOM/layout), which a raw output string cannot give. Reported so the agent
 * knows these were NOT covered rather than assuming a clean pass.
 */
const STRUCTURAL_NOT_CHECKED = [
    'hero-in-viewport',
    'centered-mega-hero',
    'three-equal-cards',
    'eyebrow-overuse',
    'zigzag-cap',
    'section-layout-repetition',
];

/** Marketing filler verbs/phrases that signal vague AI copy. */
const FILLER_WORDS = [
    'elevate',
    'seamless',
    'unleash',
    'supercharge',
    'revolutionize',
    'revolutionise',
    'empower',
    'leverage',
    'next-gen',
    'cutting-edge',
    'game-changer',
    'game changer',
    'best-in-class',
    'turnkey',
    'synergy',
    'frictionless',
];

/** Placeholder names/brands that signal un-personalized output. */
const GENERIC_NAME_PATTERNS = [
    '/\bacme\b/i',
    '/\bjohn doe\b/i',
    '/\bjane doe\b/i',
    '/lorem ipsum/i',
    '/\bexample\.com\b/i',
    '/\bsarah chan\b/i',
    '/\byour company\b/i',
    '/\bcompany name\b/i',
];

/** CSS-generic font families that are always acceptable. */
const GENERIC_FAMILIES = [
    'sans-serif',
    'serif',
    'monospace',
    'system-ui',
    'ui-sans-serif',
    'ui-serif',
    'ui-monospace',
    '-apple-system',
    'blinkmacsystemfont',
    'cursive',
    'fantasy',
    'inherit',
    'initial',
    'unset',
    'revert',
];

/** Design-noun keywords used to map a natural-language "Don't" to an output probe. */
const DONT_LEXICON = [
    'gradient',
    'shadow',
    'rounded',
    'radius',
    'neon',
    'glow',
    'italic',
    'uppercase',
    'emoji',
    'centered',
    'inter',
    'roboto',
    'purple',
    'violet',
    'blur',
    'glass',
    'glassmorphism',
    'serif',
];

/**
 * Build the design context the rules compare against, from raw DESIGN.md.
 * Pass null when there is no active design — only universal rules will fire.
 *
 * @return array{has_active: bool, allowed_fonts: list<string>, allowed_colors: list<string>, accent: string, donts: list<string>, allows: list<string>}
 */
function context(?string $design_md): array
{
    if ($design_md === null) {
        return [
            'has_active' => false,
            'allowed_fonts' => [],
            'allowed_colors' => [],
            'accent' => '',
            'donts' => [],
            'allows' => [],
        ];
    }

    $tokens = Tokens\extract($design_md);

    $colors = [];
    foreach ($tokens['colors'] as $value) {
        $hex = normalize_hex($value);
        if ($hex === '') {
            continue;
        }
        $colors[$hex] = true;
    }

    return [
        'has_active' => true,
        'allowed_fonts' => collect_allowed_fonts($tokens['typography']),
        'allowed_colors' => array_keys($colors),
        'accent' => normalize_hex(Tokens\pick_value($tokens['colors'], ['accent', 'primary', 'brand', 'action'])),
        'donts' => extract_donts($design_md),
        'allows' => extract_allows($design_md),
    ];
}

/**
 * Human-readable waivers a design's declarations grant to the pre-flight.
 *
 * Derived from context(), never recomputed, so the admin badge and the rules
 * can not disagree. Only the escape hatches an actual rule consults are
 * listed; unknown `allow:` entries grant nothing and are not shown.
 *
 * @return list<string>
 */
function waivers(string $design_md): array
{
    $ctx = context($design_md);
    $out = [];
    if (in_array('em-dash', $ctx['allows'], strict: true)) {
        $out[] = __('em-dash', domain: 'novamira');
    }
    if (in_array('warm-craft-palette', $ctx['allows'], strict: true) || declares_warm_craft($ctx['allowed_colors'])) {
        $out[] = __('warm-craft palette', domain: 'novamira');
    }
    if (in_array('inter', $ctx['allowed_fonts'], strict: true)) {
        $out[] = __('Inter', domain: 'novamira');
    }
    if ($ctx['accent'] !== '') {
        $hsl = hex_to_hsl($ctx['accent']);
        if ($hsl !== null && is_purple($hsl)) {
            $out[] = __('purple accent', domain: 'novamira');
        }
    }
    return $out;
}

/**
 * Run every rule against the output and return the flat list of violations.
 *
 * @param array{has_active: bool, allowed_fonts: list<string>, allowed_colors: list<string>, accent: string, donts: list<string>, allows: list<string>} $ctx
 * @return list<array{rule: string, severity: string, message: string, evidence: string}>
 */
function violations(string $output, array $ctx): array
{
    // Copy rules judge human-visible text, so run them against the output with
    // comments and <script>/<style> blocks stripped. On a real served page those
    // carry platform chrome (a builder's inline JSON config, the theme's enqueued
    // CSS, code comments) that would otherwise false-positive: an em-dash in a
    // section-marker comment, or "solutions" inside a script config. The
    // style-inspecting rules below still see the full output, because that is
    // where colors and font-family declarations legitimately live.
    $text = visible_text($output);

    // Scan the output for hex colors once and share it: three color rules
    // (ai-purple, warm-craft, off-palette) all need the distinct hex list, and on
    // a large builder page that scan is the dominant cost.
    $hexes = find_hexes($output);

    $out = [];
    array_push($out, ...em_dash($text, $ctx['allows']));
    array_push($out, ...ai_purple($output, $ctx['accent'], $hexes));
    array_push($out, ...inter_font($output, $ctx['allowed_fonts']));
    array_push($out, ...warm_craft_palette($ctx['allows'], $ctx['allowed_colors'], $hexes));
    array_push($out, ...filler_copy($text));
    array_push($out, ...generic_names($text));

    if ($ctx['has_active']) {
        array_push($out, ...font_off_palette($output, $ctx['allowed_fonts']));
        array_push($out, ...color_off_palette($ctx['allowed_colors'], $hexes));
        array_push($out, ...dont_matches($text, $ctx['donts']));
    }

    return $out;
}

/**
 * Reduce output to its human-visible text: drop HTML comments and the contents
 * of <script> and <style> blocks. Tags themselves are kept (attribute text such
 * as alt or aria-label is visible), only the non-rendered regions are removed.
 */
function visible_text(string $output): string
{
    $stripped = preg_replace(
        pattern: ['#<!--.*?-->#s', '#<script\b[^>]*>.*?</script>#is', '#<style\b[^>]*>.*?</style>#is'],
        replacement: '',
        subject: $output,
    );

    return is_string($stripped) ? $stripped : $output;
}

/**
 * @param list<string> $allows
 * @return list<array{rule: string, severity: string, message: string, evidence: string}>
 */
function em_dash(string $output, array $allows): array
{
    if (in_array('em-dash', $allows, strict: true)) {
        return []; // Intentionally permitted by the active design (allow: [em-dash]).
    }
    $hits = substr_count($output, needle: "\u{2014}");
    if ($hits === 0) {
        return [];
    }
    return [
        [
            'rule' => 'em-dash',
            'severity' => 'fail',
            'message' => 'Em-dash present. Banned by default: use a hyphen, comma, or period. Keep it only when the user explicitly wants em-dashes as house style.',
            'evidence' => $hits . ' occurrence(s)',
        ],
    ];
}

/**
 * @param list<string> $hexes Distinct hex colors in the output, pre-scanned by the caller.
 * @return list<array{rule: string, severity: string, message: string, evidence: string}>
 */
function ai_purple(string $output, string $accent, array $hexes): array
{
    if ($accent !== '') {
        $accent_hsl = hex_to_hsl($accent);
        if ($accent_hsl !== null && is_purple($accent_hsl)) {
            return []; // The design's own accent is purple — intentional.
        }
    }

    $purples = [];
    foreach ($hexes as $hex) {
        $hsl = hex_to_hsl($hex);
        if ($hsl === null || !is_purple($hsl)) {
            continue;
        }
        $purples[$hex] = true;
    }
    if ($purples === []) {
        return [];
    }

    $in_gradient = purple_in_gradient($output, array_keys($purples));
    $message = $in_gradient
        ? 'AI-purple inside a gradient (the classic AI glow). Use a neutral base with one high-contrast non-purple accent unless the brand is purple.'
        : 'Purple/violet detected. Discouraged as a default accent. Use a non-purple high-contrast accent unless the brand is purple.';

    return [
        [
            'rule' => 'ai-purple',
            'severity' => $in_gradient ? 'fail' : 'warn',
            'message' => $message,
            'evidence' => implode(', ', array_keys($purples)),
        ],
    ];
}

/**
 * Whether any of the given purple hexes appears inside the argument list of a
 * `gradient(...)` in the output. The escalation to `fail` targets the classic
 * AI purple-gradient glow specifically: a flat purple on a page that also
 * happens to contain a non-purple gradient elsewhere must stay a `warn`.
 *
 * @param list<string> $purples Normalized purple hexes found in the output.
 */
function purple_in_gradient(string $output, array $purples): bool
{
    $m = [];
    if ($purples === [] || preg_match_all('/gradient\(([^)]*)/i', $output, $m) === false) {
        return false;
    }
    foreach ($m[1] as $args) {
        foreach (find_hexes($args) as $hex) {
            if (in_array($hex, $purples, strict: true)) {
                return true;
            }
        }
    }
    return false;
}

/**
 * @param list<string> $allowed_fonts
 * @return list<array{rule: string, severity: string, message: string, evidence: string}>
 */
function inter_font(string $output, array $allowed_fonts): array
{
    $uses_inter =
        preg_match('/font-family[^;{}]*\binter\b/i', $output) === 1 || preg_match('/[\'"]inter[\'"]/i', $output) === 1;
    if (!$uses_inter) {
        return [];
    }
    if (in_array('inter', $allowed_fonts, strict: true)) {
        return []; // Explicitly listed in the active design.
    }
    return [
        [
            'rule' => 'inter-font',
            'severity' => 'fail',
            'message' => 'Inter used as a font. Banned by default (the #1 AI tell). Pick a brand-appropriate face, or list Inter in the design if intentional.',
            'evidence' => 'font-family: Inter',
        ],
    ];
}

/**
 * The "warm-craft" premium-consumer default palette — a cream/beige ground with a
 * brass/clay/oxblood/ochre accent (and usually espresso text) — is the
 * second-most-recurring AI palette tell: every premium / artisan / wellness /
 * heritage / DTC site reaches for it and they all look identical. The trigger is
 * the GESTALT, not one color: a warm near-white ground AND a warm mid accent
 * together. A cream ground with a non-warm accent (cobalt, emerald) is a
 * recommended alternative and must NOT fire. Warn, not fail: the palette is right
 * for a brand that genuinely is warm-craft, which the design declares with
 * `allow: [warm-craft-palette]`.
 *
 * @param list<string> $allows
 * @param list<string> $design_colors The active design's own declared palette.
 * @param list<string> $hexes Distinct hex colors in the output, pre-scanned by the caller.
 * @return list<array{rule: string, severity: string, message: string, evidence: string}>
 */
function warm_craft_palette(array $allows, array $design_colors, array $hexes): array
{
    if (in_array('warm-craft-palette', $allows, strict: true)) {
        return []; // The brand genuinely is warm-craft — declared intentional.
    }
    if (declares_warm_craft($design_colors)) {
        return []; // The design's own palette is warm-craft — intentional, like a purple accent.
    }

    $creams = [];
    $accents = [];
    foreach ($hexes as $hex) {
        match (warm_role(hex_to_hsl($hex))) {
            'cream' => $creams[$hex] = true,
            'accent' => $accents[$hex] = true,
            default => null,
        };
    }
    if ($creams === [] || $accents === []) {
        return []; // Needs the full gestalt (warm ground + warm accent), not one warm color.
    }

    return [
        [
            'rule' => 'warm-craft-palette',
            'severity' => 'warn',
            'message' => 'Warm cream/paper ground with a brass/clay/oxblood accent: the "premium-consumer" default palette and a common AI tell. Rotate to another family (cold luxury, forest, cobalt + cream, black-and-tan, monochrome + one bright pop) unless the brand genuinely is warm-craft; if it is, declare those colors in the design\'s own palette and the rule stays quiet.',
            'evidence' =>
                'ground ' . implode('/', array_keys($creams)) . ' + accent ' . implode('/', array_keys($accents)),
        ],
    ];
}

/** @return list<array{rule: string, severity: string, message: string, evidence: string}> */
function filler_copy(string $output): array
{
    $low = strtolower($output);
    $found = [];
    foreach (FILLER_WORDS as $word) {
        if (preg_match('/\b' . preg_quote($word, delimiter: '/') . '\b/', $low) !== 1) {
            continue;
        }
        $found[] = $word;
    }
    if ($found === []) {
        return [];
    }
    return [
        [
            'rule' => 'filler-copy',
            'severity' => 'warn',
            'message' => 'Marketing filler words found. Prefer concrete, specific verbs.',
            'evidence' => implode(', ', $found),
        ],
    ];
}

/** @return list<array{rule: string, severity: string, message: string, evidence: string}> */
function generic_names(string $output): array
{
    $found = [];
    $m = [];
    foreach (GENERIC_NAME_PATTERNS as $pattern) {
        if (preg_match($pattern, $output, $m) !== 1) {
            continue;
        }
        $found[$m[0]] = true;
    }
    if ($found === []) {
        return [];
    }
    return [
        [
            'rule' => 'generic-names',
            'severity' => 'warn',
            'message' => 'Placeholder/generic names found. Use realistic, specific names.',
            'evidence' => implode(', ', array_keys($found)),
        ],
    ];
}

/**
 * @param list<string> $allowed_fonts
 * @return list<array{rule: string, severity: string, message: string, evidence: string}>
 */
function font_off_palette(string $output, array $allowed_fonts): array
{
    $allowed = array_merge($allowed_fonts, GENERIC_FAMILIES);
    $bad = [];
    foreach (font_families($output) as $family) {
        if ($family === '' || str_starts_with($family, 'var(')) {
            continue;
        }
        if (in_array($family, $allowed, strict: true)) {
            continue;
        }
        $bad[$family] = true;
    }
    if ($bad === []) {
        return [];
    }
    return [
        [
            'rule' => 'font-off-palette',
            'severity' => 'warn',
            'message' => 'Fonts not declared in the active design were used.',
            'evidence' => implode(', ', array_slice(array_keys($bad), offset: 0, length: 8)),
        ],
    ];
}

/**
 * @param list<string> $allowed_colors
 * @param list<string> $hexes Distinct hex colors in the output, pre-scanned by the caller.
 * @return list<array{rule: string, severity: string, message: string, evidence: string}>
 */
function color_off_palette(array $allowed_colors, array $hexes): array
{
    if ($allowed_colors === []) {
        return []; // Design carries no machine-readable hex palette to compare against.
    }
    $bad = [];
    foreach ($hexes as $hex) {
        if (in_array($hex, $allowed_colors, strict: true)) {
            continue;
        }
        $bad[$hex] = true;
    }
    if ($bad === []) {
        return [];
    }
    return [
        [
            'rule' => 'color-off-palette',
            'severity' => 'warn',
            'message' => 'Colors outside the active palette were used.',
            'evidence' => implode(', ', array_slice(array_keys($bad), offset: 0, length: 8)),
        ],
    ];
}

/**
 * @param list<string> $donts
 * @return list<array{rule: string, severity: string, message: string, evidence: string}>
 */
function dont_matches(string $output, array $donts): array
{
    $out = [];
    foreach ($donts as $dont) {
        foreach (dont_keywords($dont) as $keyword) {
            if ($keyword === '') {
                continue;
            }
            // Word-boundary match so "inter" hits the font Inter, not "pointer".
            if (preg_match('/\b' . preg_quote($keyword, delimiter: '/') . '/i', $output) !== 1) {
                continue;
            }
            $out[] = [
                'rule' => 'design-dont',
                'severity' => 'warn',
                'message' => 'Output may violate a "Don\'t" of the active design.',
                'evidence' => $dont . '  [matched: ' . $keyword . ']',
            ];
            break; // One hit per Don't is enough.
        }
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Collect the concrete font families a design allows, normalized.
 *
 * @param array<string, array<string, string>> $typography
 * @return list<string>
 */
function collect_allowed_fonts(array $typography): array
{
    $fonts = [];
    foreach ($typography as $role) {
        foreach (split_families($role['fontFamily'] ?? '') as $family) {
            $fonts[$family] = true;
        }
    }
    return array_keys($fonts);
}

/**
 * Split a CSS font-family value into normalized individual families.
 *
 * @return list<string>
 */
function split_families(string $value): array
{
    $out = [];
    foreach (explode(separator: ',', string: $value) as $part) {
        $family = strtolower(trim($part, characters: " \t\n\"'"));
        if ($family === '') {
            continue;
        }
        $out[] = $family;
    }
    return $out;
}

/**
 * Font families referenced in `font-family:` declarations within the output.
 *
 * @return list<string>
 */
function font_families(string $output): array
{
    $out = [];
    $m = [];
    if (preg_match_all('/font-family\s*:\s*([^;{}>]+)/i', $output, $m) !== false) {
        foreach ($m[1] as $declaration) {
            foreach (split_families($declaration) as $family) {
                $out[$family] = true;
            }
        }
    }
    return array_keys($out);
}

/**
 * Distinct normalized 6-digit hex colors found in the output.
 *
 * @return list<string>
 */
function find_hexes(string $output): array
{
    $out = [];
    $m = [];
    if (preg_match_all('/#([0-9a-fA-F]{6}|[0-9a-fA-F]{3})\b/', $output, $m) !== false) {
        foreach ($m[1] as $hex) {
            $normalized = normalize_hex('#' . $hex);
            if ($normalized === '') {
                continue;
            }
            $out[$normalized] = true;
        }
    }
    return array_keys($out);
}

/** Normalize a hex color to lowercase `#rrggbb`, or '' if not a hex. */
function normalize_hex(string $hex): string
{
    $value = strtolower(trim($hex));
    $value = ltrim($value, characters: '#');
    if (strlen($value) === 3) {
        $value = $value[0] . $value[0] . $value[1] . $value[1] . $value[2] . $value[2];
    }
    return preg_match('/^[0-9a-f]{6}$/', $value) === 1 ? '#' . $value : '';
}

/**
 * Convert a normalized `#rrggbb` to [hue 0-360, saturation 0-1, lightness 0-1],
 * or null when the input is not a valid hex.
 *
 * @return array{0: float, 1: float, 2: float}|null
 */
function hex_to_hsl(string $hex): ?array
{
    $value = ltrim($hex, characters: '#');
    if (preg_match('/^[0-9a-f]{6}$/', $value) !== 1) {
        return null;
    }
    $r = hexdec(substr($value, offset: 0, length: 2)) / 255;
    $g = hexdec(substr($value, offset: 2, length: 2)) / 255;
    $b = hexdec(substr($value, offset: 4, length: 2)) / 255;

    $max = max($r, $g, $b);
    $min = min($r, $g, $b);
    $delta = $max - $min;
    $lightness = ($max + $min) / 2;

    if ($delta <= 0.0) {
        return [0.0, 0.0, $lightness];
    }

    $saturation = $lightness > 0.5 ? $delta / (2 - $max - $min) : $delta / ($max + $min);
    return [hue_component($r, $g, $b, $delta) * 60, $saturation, $lightness];
}

/** Hue sector (0-6, pre-scaled by 60) for the dominant channel. */
function hue_component(float $r, float $g, float $b, float $delta): float
{
    if ($r >= $g && $r >= $b) {
        return (($g - $b) / $delta) + ($g < $b ? 6 : 0);
    }
    if ($g >= $b) {
        return (($b - $r) / $delta) + 2;
    }
    return (($r - $g) / $delta) + 4;
}

/**
 * Whether a design's own declared palette embodies the warm-craft gestalt
 * (a cream ground plus a warm mid accent). Such a palette is the design's
 * deliberate identity, so the warm-craft rule and the waivers badge treat it
 * as intentional without an explicit `allow:` — imported DESIGN.md files
 * rarely carry one.
 *
 * @param list<string> $colors
 */
function declares_warm_craft(array $colors): bool
{
    $has_cream = false;
    $has_accent = false;
    foreach ($colors as $hex) {
        match (warm_role(hex_to_hsl($hex))) {
            'cream' => $has_cream = true,
            'accent' => $has_accent = true,
            default => null,
        };
    }
    return $has_cream && $has_accent;
}

/**
 * Whether an HSL triple sits in the "AI-purple" indigo-violet band.
 *
 * @param array{0: float, 1: float, 2: float} $hsl
 */
function is_purple(array $hsl): bool
{
    return $hsl[0] >= 235.0 && $hsl[0] <= 300.0 && $hsl[1] >= 0.30 && $hsl[2] >= 0.30 && $hsl[2] <= 0.80;
}

/**
 * Classify a color into the warm-craft palette roles from its HSL, or '' for
 * neither. `cream` = a warm near-white ground; `accent` = a brass/clay/oxblood/
 * ochre mid warm. Bands are deliberately narrow so cool off-whites and non-warm
 * accents (a cream ground with a cobalt or emerald accent is fine) do not match.
 *
 * @param array{0: float, 1: float, 2: float}|null $hsl
 */
function warm_role(?array $hsl): string
{
    if ($hsl === null) {
        return '';
    }
    [$hue, $saturation, $lightness] = $hsl;
    if (
        $lightness >= 0.86
        && $lightness <= 0.98
        && $saturation >= 0.06
        && $saturation <= 0.55
        && $hue >= 20.0
        && $hue <= 70.0
    ) {
        return 'cream';
    }
    $warm_hue = $hue >= 8.0 && $hue <= 48.0 || $hue >= 344.0;
    if ($warm_hue && $saturation >= 0.28 && $lightness >= 0.25 && $lightness <= 0.62) {
        return 'accent';
    }
    return '';
}

/**
 * Whether a Do/Don't list line reads as a "Don't" (a prohibition) by its own
 * opening words. A per-item prefix classifier: Markdown\guidance() applies it
 * to each bullet (where it overrides the surrounding Do/Don't group) and the
 * admin renderer styles a line by it. Grouping by label or subheading, and
 * the collection of a design's Don'ts, happen in Markdown\guidance().
 */
function is_dont(string $text): bool
{
    return preg_match('/^[*_`\s]*(don\'?t|do not|never|avoid)\b/i', Tokens\normalize_apostrophes($text)) === 1;
}

/** Whether a Do/Don't list line reads as a "Do" (an instruction / affirmation). */
function is_do(string $text): bool
{
    return preg_match('/^[*_`\s]*(do|always|ensure|prefer)\b/i', Tokens\normalize_apostrophes($text)) === 1;
}

/**
 * The "Don't" guidance items of a DESIGN.md, as plain text. Delegates to the
 * contract's guidance parser so the anti-slop pass enforces exactly the Don'ts
 * the abilities report: one parser, one Do/Don't split.
 *
 * @return list<string>
 */
function extract_donts(string $md): array
{
    return Contract\guidance($md)['donts'];
}

/**
 * AI-tells the active design intentionally permits, declared in the front matter
 * as `allow: [em-dash]` (inline) or a YAML block list. A deliberate house-style
 * choice overrides the universal default ban, the same way listing Inter in the
 * typography overrides the Inter tell. Normalized to lowercase, hyphenated ids.
 *
 * @return list<string>
 */
function extract_allows(string $md): array
{
    $normalized = Tokens\normalize_md($md);

    $front = [];
    if (preg_match('/^---\n(.*?)\n---/s', $normalized, $front) !== 1) {
        return [];
    }

    $raw = [];
    $in_block = false;
    $m = [];
    foreach (explode(separator: "\n", string: $front[1]) as $line) {
        if (preg_match('/^allow:\s*(.*)$/i', $line, $m) === 1) {
            $inline = trim($m[1], characters: " \t[]");
            if ($inline === '') {
                $in_block = true;
                continue;
            }
            foreach (explode(separator: ',', string: $inline) as $part) {
                $raw[] = $part;
            }
            $in_block = false;
            continue;
        }
        if ($in_block && preg_match('/^\s*-\s*(.+)$/', $line, $m) === 1) {
            $raw[] = $m[1];
            continue;
        }
        $in_block = false;
    }

    $allows = [];
    foreach ($raw as $item) {
        $id = strtolower(trim($item, characters: " \t\"'"));
        $collapsed = preg_replace(pattern: '/\s+/', replacement: '-', subject: $id);
        $id = is_string($collapsed) ? $collapsed : $id;
        if ($id !== '') {
            $allows[$id] = true;
        }
    }
    return array_keys($allows);
}

/**
 * Derive output probe keywords from a natural-language "Don't" line: any quoted
 * token plus design-noun lexicon hits. Best-effort, hence warn-only.
 *
 * @return list<string>
 */
function dont_keywords(string $dont): array
{
    $low = strtolower($dont);
    $keywords = [];

    $m = [];
    if (preg_match_all('/[\'"]([^\'"]{2,40})[\'"]/', $dont, $m) !== false) {
        foreach ($m[1] as $quoted) {
            $token = trim(strtolower($quoted));
            if ($token === '') {
                continue;
            }
            $keywords[] = $token;
        }
    }

    foreach (DONT_LEXICON as $word) {
        if (!str_contains($low, $word)) {
            continue;
        }
        $keywords[] = $word === 'rounded' || $word === 'radius' ? 'border-radius' : $word;
    }

    return array_values(array_unique($keywords));
}
