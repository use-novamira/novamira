<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Design\Markdown;

use Novamira\Design\Preflight;
use Novamira\Design\Tokens;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Render a small, safe subset of Markdown to HTML for the detail view:
 * `#`–`######` headings, `-`/`*`/`N.` lists, `**bold**`, `` `code` `` and
 * paragraphs. All text is escaped first and only the structural tags we emit
 * are HTML, so an imported DESIGN.md cannot inject markup. Front matter and
 * horizontal rules are dropped.
 */
// @mago-expect lint:halstead
function to_html(string $md): string
{
    $m = [];
    $normalized = Tokens\normalize_md($md);
    if (str_starts_with($normalized, "---\n")) {
        $end = strpos($normalized, needle: "\n---\n", offset: 4);
        if ($end !== false) {
            $normalized = substr($normalized, offset: $end + 5);
        }
    }

    $html = '';
    $para = '';
    $list = '';
    $list_tag = '';

    foreach (explode(separator: "\n", string: $normalized) as $line) {
        $trimmed = trim($line);

        if ($trimmed === '' || $trimmed === '---' || $trimmed === '***') {
            $html .= flush_para($para) . flush_list($list, $list_tag);
            $para = '';
            $list = '';
            $list_tag = '';
            continue;
        }

        if (preg_match('/^(#{1,6})\s+(.*)$/', $trimmed, $m) === 1) {
            $html .= flush_para($para) . flush_list($list, $list_tag);
            $para = '';
            $list = '';
            $list_tag = '';
            $level = (int) min(strlen($m[1]) + 1, 6);
            $html .= '<h' . $level . '>' . inline($m[2]) . '</h' . $level . '>';
            continue;
        }

        $is_bullet = preg_match('/^[-*]\s+(.*)$/', $trimmed, $m) === 1;
        $is_numbered = !$is_bullet && preg_match('/^\d+[.)]\s+(.*)$/', $trimmed, $m) === 1;
        if ($is_bullet || $is_numbered) {
            $html .= flush_para($para);
            $para = '';
            $tag = $is_bullet ? 'ul' : 'ol';
            if ($list_tag !== '' && $list_tag !== $tag) {
                $html .= flush_list($list, $list_tag);
                $list = '';
            }
            $list_tag = $tag;
            $list .= '<li' . li_class($m[1]) . '>' . inline($m[1]) . '</li>';
            continue;
        }

        $html .= flush_list($list, $list_tag);
        $list = '';
        $list_tag = '';
        $para = $para === '' ? $trimmed : $para . ' ' . $trimmed;
    }

    return $html . flush_para($para) . flush_list($list, $list_tag);
}

function flush_para(string $para): string
{
    return $para === '' ? '' : '<p>' . inline($para) . '</p>';
}

function flush_list(string $list, string $list_tag): string
{
    return $list === '' || $list_tag === '' ? '' : '<' . $list_tag . '>' . $list . '</' . $list_tag . '>';
}

/**
 * Class attribute for a guidance list item: positive ("Do …", "Always …") or
 * negative ("Don't …", "Never …", "Avoid …"). Returns '' for ordinary items so
 * only Do's & Don'ts get the colour-coded treatment.
 */
function li_class(string $text): string
{
    if (Preflight\is_dont($text)) {
        return ' class="nd-doc-dont"';
    }
    if (Preflight\is_do($text)) {
        return ' class="nd-doc-do"';
    }
    return '';
}

/**
 * Render just the body of the first top-level or second-level section (one or
 * two leading hashes) whose title matches one of $titles, case-insensitively,
 * excluding the heading line itself. Deeper sub-headings stay part of the body.
 * Returns '' when no such section exists. Lets the detail view surface specific
 * narrative blocks (philosophy, Do's and Don'ts) instead of the whole document.
 *
 * @param list<string> $titles
 */
function section(string $md, array $titles): string
{
    $body = raw_section($md, $titles);
    return $body === '' ? '' : to_html($body);
}

/**
 * The raw Markdown body of the first matching section, or '' when absent.
 *
 * @param list<string> $titles
 */
function raw_section(string $md, array $titles): string
{
    $sections = raw_sections($md, $titles);
    return $sections === [] ? '' : $sections[0]['body'];
}

/**
 * Every top-level or second-level section whose title matches one of $titles,
 * in document order, each as its label key, heading level and raw body.
 * Titles compare by label key (see label_key()), so apostrophe style, `&`
 * versus "and" and emphasis marks around the title do not matter.
 *
 * @param list<string> $titles
 * @return list<array{title: string, level: int, body: string}>
 */
function raw_sections(string $md, array $titles): array
{
    $normalized = Tokens\normalize_md($md);
    $wanted = array_map(static fn(string $title): string => label_key($title), $titles);
    $sections = [];
    $title = null;
    $level = 0;
    $body = '';
    $m = [];
    foreach (explode(separator: "\n", string: $normalized) as $line) {
        if (preg_match('/^(#{1,2})\s+(.*)$/', $line, $m) === 1) {
            if ($title !== null) {
                $sections[] = ['title' => $title, 'level' => $level, 'body' => $body];
            }
            $key = label_key($m[2]);
            $title = in_array($key, $wanted, strict: true) ? $key : null;
            $level = strlen($m[1]);
            $body = '';
            continue;
        }
        if ($title !== null) {
            $body .= $line . "\n";
        }
    }
    if ($title !== null) {
        $sections[] = ['title' => $title, 'level' => $level, 'body' => $body];
    }
    return $sections;
}

/**
 * Canonical comparison key for a section title or a Do/Don't label: ASCII
 * apostrophes, lowercase, emphasis marks and closing hashes dropped, `&` read
 * as "and", apostrophes removed and whitespace collapsed, so "Do’s & Don'ts",
 * "**Dos and Donts:**" and "do's and don'ts" all compare equal.
 */
function label_key(string $text): string
{
    $plain = strtolower(Tokens\normalize_apostrophes($text));
    $plain = str_replace(search: ['*', '_', '`', "'", '&'], replace: ['', '', '', '', ' and '], subject: $plain);
    $plain = trim($plain, characters: " \t:#");
    $collapsed = preg_replace(pattern: '/\s+/', replacement: ' ', subject: $plain);
    return is_string($collapsed) ? $collapsed : $plain;
}

/**
 * Which guidance group a label names: 'dont' for "Don't(s)" / "Do not" /
 * "Never" / "Avoid", 'do' for "Do(s)" / "Always" / "Ensure" / "Prefer", and
 * '' when the text is not a bare label. The vocabulary mirrors
 * Preflight\is_dont() / is_do(); a prohibition wins over an affirmation, so
 * "Do not" is a Don't. Only a bare label matches: "Do keep it simple" is an
 * item, not a label.
 */
function label_group(string $text): string
{
    $key = label_key($text);
    if (preg_match('/^(?:donts?|do nots?|never|avoid)$/', $key) === 1) {
        return 'dont';
    }
    if (preg_match('/^(?:dos?|always|ensure|prefer)$/', $key) === 1) {
        return 'do';
    }
    return '';
}

/**
 * Classify one list item: a prohibition or an affirmation by its own opening
 * words, otherwise the group it sits under ('' when there is none).
 */
function item_group(string $text, string $group): string
{
    if (Preflight\is_dont($text)) {
        return 'dont';
    }
    if (Preflight\is_do($text)) {
        return 'do';
    }
    return $group;
}

/**
 * Split the guidance sections into grouped Do items, Don't items and the
 * remaining prose, regardless of the order they appear in the source. Items
 * are safe inline HTML ready for an <li>; `rest` is rendered HTML; `found`
 * says whether any guidance section exists at all.
 *
 * A section whose title is a bare group label ("Do's", "Don'ts") counts only
 * as a second-level heading: a document titled `# Don't` is a name, not a
 * guidance section. Combined titles ("Do's and Don'ts", "Guidelines") match at
 * either level.
 *
 * Grouping: a section titled "Don'ts" or "Do's", a level-3+ subheading
 * (`### Don'ts`), a bare label line (`**Do**`, `Don't:`) or a bare label
 * bullet (`- **Do**` above nested items) sets the current group for the
 * bullets that follow; a subheading that is not a label ends it. A bullet
 * that itself opens with Do/Always/… or Don't/Never/Avoid keeps that reading
 * regardless of the group. Neutral bullets outside any group, and prose, go
 * to `rest`; consumed labels and subheadings do not.
 *
 * @param list<string> $titles
 * @return array{dos: list<string>, donts: list<string>, rest: string, found: bool}
 */
function guidance(string $md, array $titles): array
{
    $sections = array_values(array_filter(
        raw_sections($md, $titles),
        static fn(array $section): bool => $section['level'] === 2 || label_group($section['title']) === '',
    ));
    $dos = [];
    $donts = [];
    $rest = '';
    $m = [];
    foreach ($sections as $section) {
        $group = label_group($section['title']);
        foreach (explode(separator: "\n", string: $section['body']) as $line) {
            $trimmed = trim($line);
            $is_heading = preg_match('/^#{3,6}\s+(.*)$/', $trimmed, $m) === 1;
            $is_item = !$is_heading && preg_match('/^(?:[-*]|\d+[.)])\s+(.*)$/', $trimmed, $m) === 1;
            $text = $is_heading || $is_item ? trim($m[1]) : $trimmed;

            $label = $text === '' ? '' : label_group($text);
            if ($label !== '') {
                $group = $label;
                continue;
            }
            if ($is_heading) {
                $group = '';
                $rest .= $line . "\n";
                continue;
            }
            $kind = $is_item ? item_group($text, $group) : '';
            if ($kind === 'dont') {
                $donts[] = inline($text);
                continue;
            }
            if ($kind === 'do') {
                $dos[] = inline($text);
                continue;
            }
            $rest .= $line . "\n";
        }
    }
    return [
        'dos' => $dos,
        'donts' => $donts,
        'rest' => trim($rest) === '' ? '' : to_html($rest),
        'found' => $sections !== [],
    ];
}

/** Escape text, then re-apply inline bold + code. Escape-first keeps it safe. */
function inline(string $text): string
{
    $escaped = esc_html($text);
    $bold = preg_replace('/\*\*([^*]+)\*\*/', replacement: '<strong>$1</strong>', subject: $escaped);
    if (!is_string($bold)) {
        return $escaped;
    }
    $coded = preg_replace('/`([^`]+)`/', replacement: '<code>$1</code>', subject: $bold);
    return is_string($coded) ? $coded : $bold;
}
