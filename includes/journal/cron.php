<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Emails and requests sent later by events the AI scheduled.
 *
 * Code run by the AI can schedule a cron event whose callback sends emails or calls an API minutes or days
 * later, in another request where the AI's execution no longer exists. Unless read mode is on,
 * every event scheduled during an execution is marked (hook and arguments). When a marked event runs, its
 * wp_mail() emails are held under the execution that scheduled it, exactly as if
 * the AI had sent them itself. Other events, and visitors' requests, are untouched.
 */

namespace Novamira\Journal;

use Novamira\Confirmations;

if (!defined('ABSPATH')) {
    exit();
}

const CRON_MARKS_OPTION = 'novamira_journal_cron_marks';

/** At most this many marks are kept; the oldest go first. */
const CRON_MARKS_LIMIT = 200;

/**
 * A recurring event the AI scheduled is watched for this long; a single event is watched until it runs once.
 * Afterwards the same hook (often scheduled by WordPress itself, like pings) runs unwatched again.
 */
const RECURRING_MARK_SECONDS = 86_400;

add_action('plugins_loaded', __NAMESPACE__ . '\\watch_marked_events');

/**
 * @return list<array{hook: string, args: string, execution: string, created: int, once: bool, timestamp: int}>
 */
function cron_marks(): array
{
    /** @var mixed $marks */
    $marks = get_option(CRON_MARKS_OPTION, default_value: []);
    if (!is_array($marks)) {
        return [];
    }
    $valid = [];
    // @mago-expect analysis:mixed-assignment -- Stored marks, checked field by field.
    foreach ($marks as $mark) {
        if (
            !is_array($mark)
            || !is_string($mark['hook'] ?? null)
            || !is_string($mark['args'] ?? null)
            || !is_string($mark['execution'] ?? null)
            || !is_int($mark['created'] ?? null)
        ) {
            continue;
        }
        $valid[] = [
            'hook' => $mark['hook'],
            'args' => $mark['args'],
            'execution' => $mark['execution'],
            'created' => $mark['created'],
            // Marks saved before single events were told apart are treated as single.
            'once' => ($mark['once'] ?? true) !== false,
            'timestamp' => is_int($mark['timestamp'] ?? null) ? $mark['timestamp'] : 0,
        ];
    }
    return $valid;
}

/** @param list<array{hook: string, args: string, execution: string, created: int, once: bool, timestamp: int}> $marks */
function save_cron_marks(array $marks): void
{
    $previous = enter_internal();
    try {
        update_option(CRON_MARKS_OPTION, array_slice($marks, -CRON_MARKS_LIMIT), autoload: true);
    } finally {
        leave_internal($previous);
    }
}

/** @param array<array-key, mixed> $args */
function cron_args_hash(array $args): string
{
    return hash('sha256', serialize(array_values($args)));
}

/**
 * The schedule_event filter while an execution runs: remember the event, change nothing.
 *
 * @param mixed $event
 * @return mixed
 */
function mark_event($event)
{
    $state = state();
    if (!$state['active'] || !$state['confirming'] || !Confirmations\holds_emails() || !is_object($event)) {
        return $event;
    }
    /** @var mixed $hook */
    $hook = $event->hook ?? null;
    /** @var mixed $args */
    $args = $event->args ?? [];
    if (!is_string($hook) || $hook === '' || !is_array($args)) {
        return $event;
    }
    $marks = cron_marks();
    $marks[] = [
        'hook' => $hook,
        'args' => cron_args_hash($args),
        'execution' => $state['execution'],
        'created' => time(),
        'once' => ($event->schedule ?? false) === false,
        'timestamp' => is_int($event->timestamp ?? null) ? $event->timestamp : 0,
    ];
    save_cron_marks($marks);
    $current = &state();
    $current['cron']++;
    return $event;
}

/** Listen to the hooks of marked events, first and last, to know when one of them is running. */
function watch_marked_events(): void
{
    $hooks = array_unique(array_map(static fn(array $mark): string => $mark['hook'], cron_marks()));
    if ($hooks !== []) {
        add_filter('pre_unschedule_event', __NAMESPACE__ . '\\note_unscheduled', PHP_INT_MAX, accepted_args: 3);
    }
    foreach ($hooks as $hook) {
        add_action($hook, __NAMESPACE__ . '\\enter_marked_event', PHP_INT_MIN, accepted_args: 99);
        add_action($hook, __NAMESPACE__ . '\\leave_marked_event', PHP_INT_MAX, accepted_args: 0);
    }
}

/**
 * WP-Cron unschedules each event right before running it, and this is the only moment the event's time is known:
 * it tells two single events with the same hook and arguments apart. Changes nothing.
 *
 * @param mixed $pre
 * @param mixed $timestamp
 * @param mixed $hook
 * @return mixed
 */
function note_unscheduled($pre, $timestamp, $hook)
{
    if (is_int($timestamp) && is_string($hook)) {
        $last = &last_unscheduled();
        $last = ['hook' => $hook, 'timestamp' => $timestamp];
    }
    return $pre;
}

/**
 * @return array{hook: string, timestamp: int}
 */
function &last_unscheduled(): array
{
    /** @var array{hook: string, timestamp: int} $last */
    static $last = ['hook' => '', 'timestamp' => 0];
    return $last;
}

/**
 * Whether a marked single event is the one about to run: false when WP-Cron just unscheduled an event of this
 * hook at another time. Recurring events move at every run, so they match on hook and arguments only.
 *
 * @param array{once: bool, timestamp: int, ...} $mark
 */
function mark_is_this_run(array $mark, string $hook): bool
{
    $last = last_unscheduled();
    return (
        !$mark['once']
        || $mark['timestamp'] === 0
        || $last['hook'] !== $hook
        || $last['timestamp'] === $mark['timestamp']
    );
}

/**
 * When a marked event runs from WP-Cron, hold its emails and requests. A single event's mark is used up by its
 * first run; a recurring one's lasts RECURRING_MARK_SECONDS. The same hook run outside WP-Cron, or by an event
 * nobody marked, is left alone.
 */
function enter_marked_event(mixed ...$args): void
{
    // What decides is the setting now, not the one when the event was scheduled.
    if (!wp_doing_cron() || !Confirmations\covers_work() || !Confirmations\holds_emails()) {
        return;
    }
    $hook = current_filter();
    $hash = cron_args_hash($args);
    $marks = cron_marks();
    foreach ($marks as $index => $mark) {
        if (
            $mark['hook'] !== $hook
            || $mark['args'] !== $hash
            || !$mark['once'] && $mark['created'] < (time() - RECURRING_MARK_SECONDS)
            || !mark_is_this_run($mark, $hook)
        ) {
            continue;
        }
        if ($mark['once']) {
            unset($marks[$index]);
            save_cron_marks(array_values($marks));
        }
        ensure_table();
        $state = &state();
        $state['cron_execution'] = $mark['execution'];
        $state['group'] = group_of_execution($mark['execution']);
        add_filter('pre_wp_mail', __NAMESPACE__ . '\\hold_mail', PHP_INT_MAX, accepted_args: 2);
        return;
    }
}

function leave_marked_event(): void
{
    $state = &state();
    if ($state['cron_execution'] === '') {
        return;
    }
    $state['cron_execution'] = '';
    if (!$state['active']) {
        $state['group'] = '';
        remove_filter('pre_wp_mail', __NAMESPACE__ . '\\hold_mail', PHP_INT_MAX);
    }
}

/** Forget marks older than the retention period. */
function prune_cron_marks(int $cutoff): void
{
    $marks = cron_marks();
    $recurring_cutoff = time() - RECURRING_MARK_SECONDS;
    $kept = array_values(array_filter(
        $marks,
        static fn(array $mark): bool => (
            $mark['created'] >= $cutoff
            && ($mark['once'] || $mark['created'] >= $recurring_cutoff)
        ),
    ));
    if (count($kept) !== count($marks)) {
        save_cron_marks($kept);
    }
}
