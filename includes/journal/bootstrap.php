<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * The journal records what an AI execution changes in the database, with the values from before, so the AI can read
 * what it did and restore it when someone asks, without restoring a backup over everyone else's work.
 *
 * It runs while an ability that can change the site executes through MCP or Novamira Chat. During that execution
 * every $wpdb query passes through the "query" filter: UPDATE and DELETE rows are saved before they change, INSERTs
 * are recorded after, and TRUNCATE, DROP TABLE and ALTER TABLE copy the table first. What is decided is decided before
 * the code starts: only writing a secret's placeholder over a real value, or changing the history, ends an execution,
 * and then every further query of it is refused and its output is discarded. Emails are held
 * instead of sent; with them off they go out and are counted. Requests to other services go out and are counted.
 *
 * It sees what goes through $wpdb, wp_mail() and the WordPress HTTP API in the same request. It guards against
 * good-faith mistakes, not code that sets out to get around it.
 */

namespace Novamira\Journal;

use Novamira\Confirmations;
use WP_Error;

if (!defined('ABSPATH')) {
    exit();
}

/** Prefix of the copies made before TRUNCATE, DROP TABLE and ALTER TABLE (after the site's table prefix). */
const BACKUP_INFIX = 'novamira_bak_';

/** Without confirmations, a write changing more rows than this, or a larger table, runs with no previous values saved. */
const RECORD_ROW_LIMIT = 2_000;

/** Without confirmations, a write whose saved rows or table copy would take more bytes than this runs the same way. */
const RECORD_BYTE_LIMIT = 8 * 1024 * 1024;

/** The limits for Novamira's own abilities: paying more for what can be restored. */
const CONFIRMED_ROW_LIMIT = 50_000;

const CONFIRMED_BYTE_LIMIT = 256 * 1024 * 1024;

/** Rows are read and saved in pages of about this many bytes, so memory stays bounded whatever their size. */
const RECORD_PAGE_BYTES = 2 * 1024 * 1024;

/** Abilities that work on files, which are not in the history: their calls are always listed. */
const FILE_ABILITIES = [
    'novamira/write-file',
    'novamira/edit-file',
    'novamira/delete-file',
    'novamira/enable-file',
    'novamira/create-upload-link',
];

/** The arguments of a call are kept up to this size, with secrets hidden. */
const CALL_BYTE_LIMIT = 64 * 1024;

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/sql.php';
require_once __DIR__ . '/effects.php';
require_once __DIR__ . '/cron.php';
require_once __DIR__ . '/facts.php';
require_once __DIR__ . '/groups.php';
require_once __DIR__ . '/ai-view.php';
require_once __DIR__ . '/abilities.php';
require_once __DIR__ . '/admin.php';

add_filter(
    'novamira_mcp_adapter_call_tool_arguments',
    __NAMESPACE__ . '\\take_request_id',
    priority: 5,
    accepted_args: 2,
);
add_filter('novamira_mcp_adapter_tools_list', __NAMESPACE__ . '\\decorate_tools');
add_filter('novamira_mcp_adapter_pre_tool_call', __NAMESPACE__ . '\\begin_tool_call', accepted_args: 2);
add_filter('novamira_mcp_adapter_tool_call_result', __NAMESPACE__ . '\\end_tool_call', priority: 100, accepted_args: 3);
add_filter('novamira_mcp_adapter_tool_call_exception', __NAMESPACE__ . '\\on_tool_exception', accepted_args: 2);
add_action('novamira_confirmation_cleanup', __NAMESPACE__ . '\\prune');

/**
 * Per-request journal state.
 *
 * @return array{active: bool, internal: bool, confirming: bool, halted: string, execution: string, session: string, user_id: int, ability: string, entry: int, pending: list<array{kind: string, table: string, pk_col: string, pks?: list<string>, rows?: int, from?: int, max_affected?: int, backup?: string}>, counts: array<string, int>, unverified: int, unchecked: int, mail: int, sent_mail: int, sent_http: int, files: bool, cron: int, cron_execution: string, call: array<string, string>, group: string, facts: array{items: array<string, array{type: string, id: string, title: string, post_type: string, actions: array<string, int>, details: array<string, int>}>, more: int, technical: int, other: array<string, int>, tables: array<string, string>}}
 */
function &state(): array
{
    /** @var array{active: bool, internal: bool, confirming: bool, halted: string, execution: string, session: string, user_id: int, ability: string, entry: int, pending: list<array{kind: string, table: string, pk_col: string, pks?: list<string>, rows?: int, from?: int, max_affected?: int, backup?: string}>, counts: array<string, int>, unverified: int, unchecked: int, mail: int, sent_mail: int, sent_http: int, files: bool, cron: int, cron_execution: string, call: array<string, string>, group: string, facts: array{items: array<string, array{type: string, id: string, title: string, post_type: string, actions: array<string, int>, details: array<string, int>}>, more: int, technical: int, other: array<string, int>, tables: array<string, string>}}|null $state */
    static $state = null;
    if ($state === null) {
        $state = fresh_state();
    }
    return $state;
}

/**
 * @return array{active: bool, internal: bool, confirming: bool, halted: string, execution: string, session: string, user_id: int, ability: string, entry: int, pending: list<array{kind: string, table: string, pk_col: string, pks?: list<string>, rows?: int, from?: int, max_affected?: int, backup?: string}>, counts: array<string, int>, unverified: int, unchecked: int, mail: int, sent_mail: int, sent_http: int, files: bool, cron: int, cron_execution: string, call: array<string, string>, group: string, facts: array{items: array<string, array{type: string, id: string, title: string, post_type: string, actions: array<string, int>, details: array<string, int>}>, more: int, technical: int, other: array<string, int>, tables: array<string, string>}}
 */
function fresh_state(): array
{
    return [
        'active' => false,
        'internal' => false,
        'confirming' => false,
        'halted' => '',
        'execution' => '',
        'session' => '',
        'user_id' => 0,
        'ability' => '',
        'entry' => 0,
        'pending' => [],
        'counts' => [],
        'unverified' => 0,
        'unchecked' => 0,
        'mail' => 0,
        'sent_mail' => 0,
        'sent_http' => 0,
        'files' => false,
        'cron' => 0,
        'cron_execution' => '',
        'call' => [],
        'group' => '',
        'facts' => fresh_facts(),
    ];
}

/**
 * The journal records every execution of an ability that can change the site. Recording does not change
 * what the execution does; emails are held only outside read mode.
 *
 * What an administrator chose in the Abilities Hub is left out on purpose: "Never ask" removes the questions, not the
 * record of what changed, nor the emails held while the AI works.
 */
function applies_to(string $ability): bool
{
    return Confirmations\listed_ability_policy($ability)['confirm'] !== 'never';
}

/**
 * @param mixed $args
 * @return mixed
 */
function begin_tool_call($args, string $tool_name)
{
    // Taken before any early return: a call stopped by an earlier filter must not leave its words to the next one.
    $said = sent_review();
    if ($tool_name !== 'mcp-adapter-execute-ability' || !is_array($args)) {
        return $args;
    }
    $ability = is_string($args['ability_name'] ?? null) ? $args['ability_name'] : '';
    if (applies_to($ability)) {
        $recorded = array_key_exists('review', $args) || $said === ''
            ? $args
            : array_merge($args, ['review' => ['summary' => $said]]);
        begin($ability, recorded_call($recorded), may_touch_files($ability, $args));
    }
    return $args;
}

/**
 * What the AI ran, as it is kept in the history: the arguments, with secrets hidden. The user's request is
 * deliberately not kept: nobody should have a record of what people ask an AI.
 *
 * @param array<array-key, mixed> $args
 * @return array<string, string>
 */
function recorded_call(array $args): array
{
    // @mago-expect analysis:mixed-assignment -- Arguments are arbitrary JSON-like data, stored as text.
    $parameters = $args['parameters'] ?? null;
    $text = is_string($parameters) ? $parameters : (string) wp_json_encode($parameters);
    // A call a person approved carries the words of its card and who approved it.
    $approval = class_exists('Novamira\\Confirmations\\RunningApproval')
        ? Confirmations\RunningApproval::$current
        : null;
    $says = $approval !== null && $approval['says'] !== '' ? $approval['says'] : call_says($args);
    // The file abilities name the file they work on: the history says which, though it does not keep its content.
    $path = is_array($parameters) && is_string($parameters['path'] ?? null) ? $parameters['path'] : '';
    $ability = is_string($args['ability_name'] ?? null) ? $args['ability_name'] : '';
    return (
        [
            'arguments' => hide_secrets(cut_text(readable_string($text), CALL_BYTE_LIMIT)),
            'says' => hide_secrets($says),
            'approved_by' => (string) ($approval['by'] ?? 0),
            'approval_request' => $approval['request'] ?? '',
            'file' => in_array($ability, FILE_ABILITIES, strict: true)
                ? cut_text(readable_string($path), length: 300)
                : '',
        ] + call_judgement($args, $approval)
    );
}

/**
 * What the AI said the call changes, from its review, or what Novamira says for the abilities a fixed rule decides.
 *
 * @param array<array-key, mixed> $args
 */
function call_says(array $args): string
{
    $parameters = is_array($args['parameters'] ?? null) ? $args['parameters'] : [];
    $inside = is_array($parameters['novamira_review'] ?? null) ? $parameters['novamira_review'] : [];
    $review = is_array($args['review'] ?? null) ? $args['review'] : $inside;
    $summary = is_string($review['summary'] ?? null) ? trim($review['summary']) : '';
    if ($summary !== '') {
        return mb_strimwidth($summary, start: 0, width: 240, trim_marker: '...', encoding: 'UTF-8');
    }
    return function_exists('Novamira\\Confirmations\\fixed_rule_summary')
        ? Confirmations\fixed_rule_summary($args) ?? ''
        : '';
}

/**
 * Kept for the AI, never shown on the page: the rating the AI sent, and whether it had read the site's rules, so it
 * can say later why a call ran without asking.
 *
 * @param array<array-key, mixed> $args
 * @param array{by: int, says: string, attention?: int, request?: string, group_request?: string}|null $approval
 * @return array{attention: string, site_rules: string}
 */
function call_judgement(array $args, ?array $approval): array
{
    $rules = $approval === null && function_exists('Novamira\\Confirmations\\verified_rules_code')
        ? Confirmations\verified_rules_code()
        : '';
    return ['attention' => (string) ($approval['attention'] ?? call_attention($args)), 'site_rules' => $rules];
}

/**
 * The attention the AI gave the call, or -1 when it gave none.
 *
 * @param array<array-key, mixed> $args
 */
function call_attention(array $args): int
{
    $parameters = is_array($args['parameters'] ?? null) ? $args['parameters'] : [];
    $inside = is_array($parameters['novamira_review'] ?? null) ? $parameters['novamira_review'] : [];
    $review = is_array($args['review'] ?? null) ? $args['review'] : $inside;
    return is_int($review['attention'] ?? null) ? $review['attention'] : -1;
}

/**
 * What the execution row keeps of the call, while it runs and once it finished.
 *
 * @param array<array-key, mixed> $call
 * @return array{says: mixed, approved_by: int, approval_request: mixed, file: mixed, attention: int, site_rules: mixed}
 */
function stored_call_fields(array $call): array
{
    return [
        'says' => $call['says'] ?? '',
        'approved_by' => (int) ($call['approved_by'] ?? 0),
        'approval_request' => $call['approval_request'] ?? '',
        'file' => $call['file'] ?? '',
        'attention' => (int) ($call['attention'] ?? -1),
        'site_rules' => $call['site_rules'] ?? '',
    ];
}

function hide_secrets(string $text): string
{
    return function_exists('Novamira\\Secrets\\hide_string') ? \Novamira\Secrets\hide_string($text) : $text;
}

/**
 * Files are not in the history. Abilities that work on files say so by their name; for code, the check that reads it
 * says whether it names a function that changes files.
 *
 * @param array<array-key, mixed> $args
 */
function may_touch_files(string $ability, array $args): bool
{
    if (in_array($ability, FILE_ABILITIES, strict: true)) {
        return true;
    }
    if ($ability !== 'novamira/execute-php') {
        return false;
    }
    $analysis = Confirmations\analyze_call($args);
    return $analysis !== null && array_intersect(['medium:files', 'high:files_in_loop'], $analysis['rules']) !== [];
}

/** The group code shared by calls a person approved together, or null for a call that runs on its own. */
function approval_group_request(): ?string
{
    if (!class_exists('Novamira\\Confirmations\\RunningApproval')) {
        return null;
    }
    $group = Confirmations\RunningApproval::$current['group_request'] ?? '';
    return $group !== '' ? $group : null;
}

/**
 * @param array<string, string> $call
 */
function begin(string $ability, array $call = [], bool $files = false): void
{
    // A previous execution in the same request that threw never reached end_tool_call.
    if (state()['active']) {
        finish('failed');
    }
    ensure_table();
    $state = &state();
    $state = fresh_state();
    $state['active'] = true;
    $state['execution'] = bin2hex(random_bytes(16));
    $state['session'] = Confirmations\session_id();
    $state['user_id'] = get_current_user_id();
    $state['ability'] = $ability;
    $state['files'] = $files;
    $state['call'] = $call;
    $state['group'] = resolve_group($state['session'], $state['user_id'], approval_group_request() ?? request_id());
    // Every ability follows the approvals, another plugin's included: its emails wait for a person like Novamira's.
    $state['confirming'] = Confirmations\covers_work();
    $state['entry'] = add_entry([
        'execution' => $state['execution'],
        'kind' => 'execution',
        'old_value' =>
            [
                'ability' => $ability,
                'state' => 'running',
            ] + stored_call_fields($call),
        'status' => 'running',
    ]);
    // Kept apart from the execution row: lists of executions never load what each one ran.
    if ($call !== []) {
        add_entry(['execution' => $state['execution'], 'kind' => 'call', 'old_value' => $call]);
    }
    add_filter('query', __NAMESPACE__ . '\\on_query', PHP_INT_MAX);
    add_effect_filters($state['confirming']);
    /** @var bool $registered */
    static $registered = false;
    if (!$registered) {
        $registered = true;
        register_shutdown_function(__NAMESPACE__ . '\\on_shutdown');
    }
}

/**
 * While confirmations and that setting are on, emails are held; otherwise they go out, and are counted. Requests always go out,
 * and are counted.
 */
// @mago-expect lint:no-boolean-flag-parameter -- Whether this ability's emails can be held.
function add_effect_filters(bool $confirming): void
{
    add_filter('pre_http_request', __NAMESPACE__ . '\\count_http', PHP_INT_MAX, accepted_args: 2);
    if ($confirming && Confirmations\holds_emails()) {
        add_filter('pre_wp_mail', __NAMESPACE__ . '\\hold_mail', PHP_INT_MAX, accepted_args: 2);
        add_filter('schedule_event', __NAMESPACE__ . '\\mark_event', PHP_INT_MAX);
        return;
    }
    add_filter('pre_wp_mail', __NAMESPACE__ . '\\count_mail', PHP_INT_MAX);
}

/**
 * @param mixed $result
 * @param mixed $args
 * @return mixed
 */
function end_tool_call($result, $args, string $tool_name)
{
    $state = state();
    if ($tool_name !== 'mcp-adapter-execute-ability' || !$state['active']) {
        return $result;
    }
    if ($state['halted'] !== '') {
        $summary = finish('halted');
        // The output of an execution that was stopped is discarded: it may describe changes that never happened.
        return new WP_Error(
            'novamira_journal_halted',
            $state['halted'] . ' ' . summary_text($summary),
            array_merge($summary, ['status' => 409]),
        );
    }
    $summary = finish(is_wp_error($result) ? 'failed' : 'completed');
    if (is_array($result)) {
        $result['novamira_journal'] = $summary;
        $warning = access_warning(is_array($args) ? $args : []);
        if ($warning !== '') {
            $result['novamira_access_warning'] = $warning;
        }
    }
    return $result;
}

/**
 * A call after which the AI may lose this site, with approvals skipped too: the AI tells the user, who is the only
 * one able to undo it.
 *
 * @param array<array-key, mixed> $args
 */
function access_warning(array $args): string
{
    $ability = is_string($args['ability_name'] ?? null) ? $args['ability_name'] : '';
    if ($ability === '' || !Confirmations\ability_is_ours($ability)) {
        return '';
    }
    $lines = Confirmations\access_lines(Confirmations\ability_check(Confirmations\ability_policy($ability), $args));
    return $lines === []
        ? ''
        : implode(' ', $lines) . ' Tell the user now: if Novamira stops answering, only a person can undo this.';
}

/**
 * An ability that let an exception escape (a stop of the AI change history included) never reaches
 * end_tool_call: close the execution here, so the rest of the request (the confirmation record, later calls)
 * runs normally, and report a stop with what happened before it.
 *
 * @param mixed $handled
 * @return mixed
 */
function on_tool_exception($handled, \Throwable $exception)
{
    $state = state();
    if (!$state['active']) {
        return $handled;
    }
    if ($state['halted'] === '') {
        finish('failed');
        return $handled;
    }
    $summary = finish('halted');
    return Confirmations\response([
        'error' => $state['halted'] . ' ' . summary_text($summary),
        'executed' => null,
        'novamira_journal' => $summary,
    ], error: true);
}

/**
 * Close the current execution and describe what it did.
 *
 * @return array{execution_id: string, group_id: string, state: string, changed_rows: array<string, int>, unverified_writes: int, held_emails: int, sent_emails: int, sent_requests: int, watched_scheduled_events: int, restore: string, not_restorable?: string, not_checked?: string, files?: string}
 */
function finish(string $outcome): array
{
    flush_pending();
    remove_filter('query', __NAMESPACE__ . '\\on_query', PHP_INT_MAX);
    remove_filter('pre_wp_mail', __NAMESPACE__ . '\\hold_mail', PHP_INT_MAX);
    remove_filter('schedule_event', __NAMESPACE__ . '\\mark_event', PHP_INT_MAX);
    remove_filter('pre_wp_mail', __NAMESPACE__ . '\\count_mail', PHP_INT_MAX);
    remove_filter('pre_http_request', __NAMESPACE__ . '\\count_http', PHP_INT_MAX);
    $state = &state();
    $state['facts'] = complete_facts($state['facts']);
    $summary = [
        'execution_id' => $state['execution'],
        'group_id' => $state['group'],
        'state' => $outcome,
        'changed_rows' => $state['counts'],
        'unverified_writes' => $state['unverified'],
        'held_emails' => $state['mail'],
        'sent_emails' => $state['sent_mail'],
        'sent_requests' => $state['sent_http'],
        'watched_scheduled_events' => $state['cron'],
        'restore' => $state['counts'] === []
            ? 'No database change was recorded. Files, emails already sent and requests already made are not in this history, so this does not mean nothing changed.'
            : 'Recorded. If the user asks to restore this, use this group_id with novamira/list-changes. Files, emails already sent and requests already made are not in this history.',
    ];
    if ($state['files']) {
        $summary['files'] = 'This call may have changed files. The call is recorded, but the previous content of a file is not.';
    }
    if ($state['unverified'] > 0) {
        $summary['not_restorable'] = sprintf(
            '%d write(s) of this execution ran without their previous values being saved: the call is recorded, but those values are not available.',
            $state['unverified'],
        );
    }
    if ($state['unchecked'] > 0) {
        $summary['not_checked'] = sprintf(
            '%d write(s) of this execution put "[redacted]" on more rows than can be checked: if any of them held a real secret, it was replaced.',
            $state['unchecked'],
        );
    }
    if ($state['ability'] === 'novamira/run-wp-cli') {
        $summary['not_restorable'] = 'WP-CLI runs in a separate process: the call is recorded, but the previous values of what it changed are not.';
    }
    update_entry($state['entry'], [
        'status' => $outcome,
        'listed' => execution_left_a_mark($state, $outcome) ? '1' : '0',
        'old_value' => (string) wp_json_encode(
            stored_call_fields($state['call'])
            + [
                'ability' => $state['ability'],
                'state' => $outcome,
                'message' => $state['halted'],
                'summary' => $summary,
                'facts' => $state['facts'],
            ],
        ),
    ]);
    $state['active'] = false;
    return $summary;
}

/**
 * Reads and inspections that changed nothing and held nothing stay out of the lists a person reads.
 *
 * @param array{halted: string, counts: array<string, int>, unverified: int, unchecked: int, mail: int, sent_mail: int, sent_http: int, files: bool, cron: int, ability: string, ...} $state
 */
function execution_left_a_mark(array $state, string $outcome): bool
{
    return (
        $outcome !== 'completed'
        || $state['counts'] !== []
        || $state['unverified'] > 0
        || $state['mail'] > 0
        || $state['sent_mail'] > 0
        || $state['sent_http'] > 0
        || $state['files']
        || $state['cron'] > 0
        || $state['ability'] === 'novamira/run-wp-cli'
    );
}

/** @param array{changed_rows: array<string, int>, unverified_writes: int, held_emails: int, ...} $summary */
function summary_text(array $summary): string
{
    $rows = array_sum($summary['changed_rows']);
    return sprintf(
        'Before stopping, it changed %d row(s), made %d write(s) with no previous values saved, and held %d email(s). Those changes stay: running the same code again would repeat them, so send only what is left. novamira/list-changes shows what was recorded.',
        $rows,
        $summary['unverified_writes'],
        $summary['held_emails'],
    );
}

/** A fatal error or exit ends the request before end_tool_call: the execution is still closed and marked. */
function on_shutdown(): void
{
    if (!state()['active']) {
        return;
    }
    $error = error_get_last();
    try {
        finish($error !== null ? 'interrupted' : 'failed');

        // @mago-expect lint:no-empty-catch-clause -- At shutdown there is nowhere left to report a failure.
    } catch (\Throwable) {
        // Nothing more can be done at shutdown; the execution row stays "running" and is shown as unfinished.
    }
}

/**
 * Run a Novamira Chat tool call under the same recording as an MCP call. Chat has no filters around its
 * execution, so it comes here. A stop returns an error with what happened before it; the group is the user's message
 * the call answers.
 *
 * @param array<string, mixed> $arguments
 * @param callable(): mixed $run
 * @return mixed
 */
function run_direct_call(string $ability, array $arguments, string $message_id, callable $run, string $says = '')
{
    if (!applies_to($ability)) {
        return $run();
    }
    request_id($message_id);
    $args = ['ability_name' => $ability, 'parameters' => $arguments, 'review' => ['summary' => $says]];
    begin($ability, recorded_call($args), may_touch_files($ability, $args));
    try {
        // @mago-expect analysis:mixed-assignment -- Whatever the ability returns.
        $result = $run();
    } catch (\Throwable $exception) {
        if (state()['halted'] === '') {
            finish('failed');
            throw $exception;
        }
        $result = null;
    }
    // @mago-expect analysis:mixed-assignment -- Whatever the ability returned, plus the summary.
    $closed = end_tool_call($result, [], tool_name: 'mcp-adapter-execute-ability');
    // A key added to a list would turn it into an object for the client: the history still has the summary.
    return is_array($result) && array_values($result) === $result && is_array($closed) ? $result : $closed;
}

/** Whether an execution is being recorded in this request, so a nested run does not close it. */
function is_recording(): bool
{
    return state()['active'];
}

/**
 * Stop the execution: no further query of it runs, and its output is discarded.
 */
function halt(string $message): void
{
    $state = &state();
    $state['halted'] = $message;
    throw new Halted($message);
}

// An Error, not an Exception: code that catches Exception to protect its own writes does not swallow the stop and go on
// with every query empty. Novamira's own handlers catch Throwable and read the stop from the state.
// @mago-expect lint:file-name -- A small error that belongs with the journal it stops.
final class Halted extends \Error {}
