<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * What an execution changed, in words a person can read: "Page "Home": Elementor layout changed", not
 * "wp_postmeta: 3". The facts come from the rows the journal saw, so they do not depend on what the AI says. Rows
 * of tables Novamira does not know stay plain ("3 row(s) in wp_xyz"): nothing is guessed. Bookkeeping that only
 * WordPress reads (revisions, caches, edit locks) is counted as technical, never hidden.
 */

namespace Novamira\Journal;

if (!defined('ABSPATH')) {
    exit();
}

/** Things named one by one in one execution; the rest are counted. */
const FACT_ITEMS = 30;

/** Post types that are WordPress's own bookkeeping. */
const TECHNICAL_POST_TYPES = ['revision', 'customize_changeset', 'oembed_cache', 'wp_font_face'];

/** Meta keys only WordPress and page builders read back: caches, locks, bookkeeping. */
const TECHNICAL_META = [
    '_edit_lock',
    '_edit_last',
    '_encloseme',
    '_pingme',
    '_wp_trash_meta_status',
    '_wp_trash_meta_time',
    '_wp_desired_post_slug',
    '_wp_old_slug',
    '_wp_old_date',
    '_elementor_css',
    '_elementor_page_assets',
    '_elementor_element_cache',
];

/** Options WordPress keeps for itself. */
const TECHNICAL_OPTIONS = ['cron', 'rewrite_rules', 'recently_edited', 'recently_activated'];

/** @return array{items: array<string, array{type: string, id: string, title: string, post_type: string, actions: array<string, int>, details: array<string, int>}>, more: int, technical: int, other: array<string, int>, tables: array<string, string>} */
function fresh_facts(): array
{
    return ['items' => [], 'more' => 0, 'technical' => 0, 'other' => [], 'tables' => []];
}

/**
 * Note one row an execution changes, from the row itself: this costs no query.
 *
 * @param array<string, string|null> $row
 */
// @mago-expect lint:halstead -- One small step per thing a row can add to what is known about an item.
function note_fact(string $kind, string $table, array $row): void
{
    $state = &state();
    $fact = classify_row($table, $row);
    if ($fact === 'technical') {
        $state['facts']['technical']++;
        return;
    }
    if ($fact === null) {
        $state['facts']['other'][$table] = ($state['facts']['other'][$table] ?? 0) + 1;
        return;
    }
    $ref = $fact['type'] . ':' . $fact['id'];
    if (!array_key_exists($ref, $state['facts']['items'])) {
        if (count($state['facts']['items']) >= FACT_ITEMS) {
            $state['facts']['more']++;
            return;
        }
        $state['facts']['items'][$ref] = [
            'type' => $fact['type'],
            'id' => $fact['id'],
            'title' => '',
            'post_type' => '',
            'actions' => [],
            'details' => [],
        ];
    }
    $item = &$state['facts']['items'][$ref];
    $title = $fact['title'] ?? '';
    if ($title !== '') {
        $item['title'] = shorten_title($title);
    }
    $type = $fact['post_type'] ?? '';
    if ($type !== '') {
        $item['post_type'] = $type;
    }
    $detail = $fact['detail'] ?? '';
    if ($detail !== '') {
        $item['details'][$detail] = ($item['details'][$detail] ?? 0) + 1;
        return;
    }
    $item['actions'][$kind] = ($item['actions'][$kind] ?? 0) + 1;
}

/**
 * What a row belongs to: 'technical', null for a table Novamira does not know, or the thing it changes.
 *
 * @param array<string, string|null> $row
 * @return 'technical'|array{type: string, id: string, title?: string, post_type?: string, detail?: string}|null
 */
function classify_row(string $table, array $row): string|array|null
{
    $wpdb = db();
    if ($table === $wpdb->posts) {
        return classify_post($row);
    }
    if ($table === $wpdb->postmeta) {
        return classify_post_meta($row);
    }
    if ($table === $wpdb->options) {
        return classify_option($row);
    }
    if ($table === $wpdb->usermeta) {
        return classify_user_meta($row);
    }
    $simple = [
        $wpdb->users => ['user', 'ID'],
        $wpdb->comments => ['comment', 'comment_ID'],
        $wpdb->terms => ['term', 'term_id'],
        $wpdb->term_taxonomy => ['term', 'term_id'],
        $wpdb->termmeta => ['term', 'term_id'],
        $wpdb->prefix . 'wc_orders' => ['order', 'id'],
        $wpdb->prefix . 'wc_orders_meta' => ['order', 'order_id'],
        $wpdb->prefix . 'wc_order_addresses' => ['order', 'order_id'],
        $wpdb->prefix . 'wc_order_operational_data' => ['order', 'order_id'],
        $wpdb->prefix . 'woocommerce_order_items' => ['order', 'order_id'],
    ];
    if (array_key_exists($table, $simple)) {
        return ['type' => $simple[$table][0], 'id' => $row[$simple[$table][1]] ?? ''];
    }
    if ($table === $wpdb->term_relationships) {
        return ['type' => 'post', 'id' => $row['object_id'] ?? '', 'detail' => 'categories and tags'];
    }
    return is_technical_table($table) ? 'technical' : null;
}

/** Tables that only summarize others, or that WordPress and WooCommerce rebuild themselves. */
function is_technical_table(string $table): bool
{
    $wpdb = db();
    if ($table === $wpdb->commentmeta) {
        return true;
    }
    $name = substr($table, offset: strlen($wpdb->prefix));
    return (
        str_starts_with($name, 'wc_admin_')
        || str_ends_with($name, '_lookup')
        || $name === 'wc_order_stats'
        || $name === 'wc_customer_lookup'
        || $name === 'actionscheduler_logs'
    );
}

/**
 * @param array<string, string|null> $row
 * @return 'technical'|array{type: string, id: string, title: string, post_type: string}
 */
function classify_post(array $row): string|array
{
    $type = $row['post_type'] ?? '';
    if (in_array($type, TECHNICAL_POST_TYPES, strict: true)) {
        return 'technical';
    }
    return ['type' => 'post', 'id' => $row['ID'] ?? '', 'title' => $row['post_title'] ?? '', 'post_type' => $type];
}

/**
 * @param array<string, string|null> $row
 * @return 'technical'|array{type: string, id: string, detail: string}
 */
function classify_post_meta(array $row): string|array
{
    $key = $row['meta_key'] ?? '';
    if (in_array($key, TECHNICAL_META, strict: true)) {
        return 'technical';
    }
    $labels = [
        '_elementor_data' => 'Elementor layout',
        '_elementor_page_settings' => 'Elementor page settings',
        '_thumbnail_id' => 'featured image',
        '_wp_page_template' => 'page template',
    ];
    return ['type' => 'post', 'id' => $row['post_id'] ?? '', 'detail' => $labels[$key] ?? 'custom field ' . $key];
}

/**
 * @param array<string, string|null> $row
 * @return 'technical'|array{type: string, id: string}
 */
function classify_option(array $row): string|array
{
    $name = $row['option_name'] ?? '';
    if (
        str_starts_with($name, '_transient_')
        || str_starts_with($name, '_site_transient_')
        || in_array($name, TECHNICAL_OPTIONS, strict: true)
    ) {
        return 'technical';
    }
    return ['type' => 'option', 'id' => $name];
}

/**
 * @param array<string, string|null> $row
 * @return 'technical'|array{type: string, id: string, detail: string}
 */
function classify_user_meta(array $row): string|array
{
    $key = $row['meta_key'] ?? '';
    if (
        $key === 'session_tokens'
        || str_contains($key, 'user-settings')
        || str_contains($key, 'dashboard_quick_press')
    ) {
        return 'technical';
    }
    return ['type' => 'user', 'id' => $row['user_id'] ?? '', 'detail' => 'profile data'];
}

/** Note a whole table the execution emptied, dropped or altered. */
function note_table_fact(string $table, string $operation): void
{
    $state = &state();
    $state['facts']['tables'][$table] = $operation;
}

function shorten_title(string $title): string
{
    $short = mb_strlen($title) > 80 ? mb_substr($title, start: 0, length: 80) . '…' : $title;
    return hide_secrets($short);
}

/**
 * Posts known only by a meta row have no title yet: read titles once, when the execution ends. Revisions reached
 * through their meta are bookkeeping too.
 *
 * @param array{items: array<string, array{type: string, id: string, title: string, post_type: string, actions: array<string, int>, details: array<string, int>}>, more: int, technical: int, other: array<string, int>, tables: array<string, string>} $facts
 * @return array{items: array<string, array{type: string, id: string, title: string, post_type: string, actions: array<string, int>, details: array<string, int>}>, more: int, technical: int, other: array<string, int>, tables: array<string, string>}
 */
function complete_facts(array $facts): array
{
    $missing = [];
    foreach ($facts['items'] as $item) {
        if ($item['type'] !== 'post' || $item['post_type'] !== '' || !is_numeric($item['id'])) {
            continue;
        }
        $missing[] = (int) $item['id'];
    }
    if ($missing === []) {
        return $facts;
    }
    $in = implode(', ', array_fill(0, count($missing), value: '%d'));
    $rows = own_rows(prepared('SELECT ID, post_title, post_type FROM %i WHERE ID IN (' . $in . ')', [
        db()->posts,
        ...$missing,
    ]));
    foreach ($rows ?? [] as $row) {
        $ref = 'post:' . ($row['ID'] ?? '');
        if (!array_key_exists($ref, $facts['items'])) {
            continue;
        }
        if (in_array($row['post_type'] ?? '', TECHNICAL_POST_TYPES, strict: true)) {
            $facts['technical'] +=
                array_sum($facts['items'][$ref]['details']) + array_sum($facts['items'][$ref]['actions']);
            unset($facts['items'][$ref]);
            continue;
        }
        $facts['items'][$ref]['title'] = shorten_title($row['post_title'] ?? '');
        $facts['items'][$ref]['post_type'] = $row['post_type'] ?? '';
    }
    return $facts;
}

/**
 * Add one execution's facts to those of the others in its group.
 *
 * @param array{items: array<string, array{type: string, id: string, title: string, post_type: string, actions: array<string, int>, details: array<string, int>}>, more: int, technical: int, other: array<string, int>, tables: array<string, string>} $into
 * @return array{items: array<string, array{type: string, id: string, title: string, post_type: string, actions: array<string, int>, details: array<string, int>}>, more: int, technical: int, other: array<string, int>, tables: array<string, string>}
 */
// @mago-expect lint:cyclomatic-complexity -- Facts come back from JSON: each kind is checked before it is added.
function merge_facts(array $into, mixed $facts): array
{
    if (!is_array($facts)) {
        return $into;
    }
    $into['more'] += is_int($facts['more'] ?? null) ? $facts['more'] : 0;
    $into['technical'] += is_int($facts['technical'] ?? null) ? $facts['technical'] : 0;
    $into['other'] = merged_counts($into['other'], $facts['other'] ?? null);
    // @mago-expect analysis:mixed-assignment -- Facts come back from JSON; each value is checked.
    foreach (is_array($facts['tables'] ?? null) ? $facts['tables'] : [] as $table => $operation) {
        if (!is_string($table) || !is_string($operation)) {
            continue;
        }
        $into['tables'][$table] = $operation;
    }
    // @mago-expect analysis:mixed-assignment -- Facts come back from JSON; each value is checked.
    foreach (is_array($facts['items'] ?? null) ? $facts['items'] : [] as $ref => $item) {
        if (!is_string($ref) || !is_array($item)) {
            continue;
        }
        $into['items'][$ref] = merged_item($into['items'][$ref] ?? null, $item);
    }
    return $into;
}

/**
 * @param array{type: string, id: string, title: string, post_type: string, actions: array<string, int>, details: array<string, int>}|null $known
 * @param array<array-key, mixed> $item
 * @return array{type: string, id: string, title: string, post_type: string, actions: array<string, int>, details: array<string, int>}
 */
function merged_item(?array $known, array $item): array
{
    $merged = $known ?? [
        'type' => is_string($item['type'] ?? null) ? $item['type'] : '',
        'id' => is_string($item['id'] ?? null) ? $item['id'] : '',
        'title' => '',
        'post_type' => '',
        'actions' => [],
        'details' => [],
    ];
    $title = is_string($item['title'] ?? null) ? $item['title'] : '';
    if ($title !== '') {
        $merged['title'] = $title;
    }
    $type = is_string($item['post_type'] ?? null) ? $item['post_type'] : '';
    if ($type !== '') {
        $merged['post_type'] = $type;
    }
    $merged['actions'] = merged_counts($merged['actions'], $item['actions'] ?? null);
    $merged['details'] = merged_counts($merged['details'], $item['details'] ?? null);
    return $merged;
}

/**
 * @param array<string, int> $into
 * @return array<string, int>
 */
function merged_counts(array $into, mixed $counts): array
{
    // @mago-expect analysis:mixed-assignment -- Facts come back from JSON; each value is checked.
    foreach (is_array($counts) ? $counts : [] as $name => $count) {
        if (!is_string($name) || !is_int($count)) {
            continue;
        }
        $into[$name] = ($into[$name] ?? 0) + $count;
    }
    return $into;
}

/**
 * The facts as lines a person can read.
 *
 * @param array{items: array<string, array{type: string, id: string, title: string, post_type: string, actions: array<string, int>, details: array<string, int>}>, more: int, technical: int, other: array<string, int>, tables: array<string, string>} $facts
 * @return list<string>
 */
function fact_lines(array $facts): array
{
    $lines = [];
    foreach ($facts['items'] as $item) {
        $lines[] = item_name($item) . ': ' . implode(', ', item_changes($item));
    }
    foreach ($facts['tables'] as $table => $operation) {
        $lines[] = sprintf('Whole table %s: %s', $table, $operation);
    }
    foreach ($facts['other'] as $table => $count) {
        $lines[] = sprintf('%d row(s) in %s', $count, $table);
    }
    if ($facts['more'] > 0) {
        $lines[] = sprintf('%d more row(s) on other items', $facts['more']);
    }
    return $lines;
}

/** @param array{type: string, id: string, title: string, post_type: string, ...} $item */
function item_name(array $item): string
{
    if ($item['type'] === 'post') {
        $kind = post_type_name($item['post_type']);
        return $item['title'] !== ''
            ? sprintf('%s "%s"', $kind, $item['title'])
            : sprintf('%s #%s', $kind, $item['id']);
    }
    $names = ['user' => 'User #%s', 'comment' => 'Comment #%s', 'term' => 'Term #%s', 'order' => 'Order #%s'];
    return sprintf($names[$item['type']] ?? 'Setting "%s"', $item['id']);
}

function post_type_name(string $type): string
{
    if ($type !== '' && function_exists('get_post_type_object')) {
        /** @var mixed $object */
        $object = get_post_type_object($type);
        if (
            is_object($object)
            && is_object($object->labels ?? null)
            && is_string($object->labels->singular_name ?? null)
        ) {
            return $object->labels->singular_name;
        }
    }
    return $type === '' ? 'Item' : ucfirst(str_replace(['_', '-'], replace: ' ', subject: $type));
}

/**
 * @param array{actions: array<string, int>, details: array<string, int>, ...} $item
 * @return list<string>
 */
function item_changes(array $item): array
{
    $words = ['insert' => 'created', 'update' => 'changed', 'delete' => 'deleted'];
    $changes = [];
    foreach (['insert', 'delete', 'update'] as $action) {
        if (($item['actions'][$action] ?? 0) <= 0) {
            continue;
        }
        $changes[] = $words[$action];
    }
    foreach (array_keys($item['details']) as $detail) {
        $changes[] = $detail . ' changed';
    }
    return $changes === [] ? ['changed'] : $changes;
}
