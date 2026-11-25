<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * A request of the user usually takes several calls of the AI. Calls that serve one request form a group: one item
 * a person can read at once. The AI names the group with request_id, a code that says nothing about the
 * request. Calls without one join the latest group of their session, unless the session paused for a while.
 */

namespace Novamira\Journal;

use Novamira\Confirmations;
use Novamira\Vendor\WP\McpSchema\Server\Tools\DTO\Tool;

if (!defined('ABSPATH')) {
    exit();
}

/** Without a request_id, a pause longer than this starts a new group. */
const GROUP_PAUSE = 600;

/** A description of what was done is kept up to this many characters. */
const DESCRIPTION_LIMIT = 200;

/** Groups looked through when searching the history. */
const SEARCH_GROUPS = 200;

/** The request_id of the running call, set when it is removed from the call's arguments. */
function request_id(?string $set = null): string
{
    /** @var string $id */
    static $id = '';
    if ($set !== null) {
        $id = $set;
    }
    return $id;
}

/**
 * @param mixed $args
 * @return mixed
 */
function take_request_id($args, string $tool_name)
{
    if ($tool_name !== 'mcp-adapter-execute-ability' || !is_array($args)) {
        return $args;
    }
    request_id(is_string($args['request_id'] ?? null) ? $args['request_id'] : '');
    unset($args['request_id']);
    // The approvals take the review out of the arguments before the call begins: keep what the AI said it does.
    sent_review(call_says($args));
    return $args;
}

/**
 * The AI's words for the call about to run, taken before the review leaves its arguments. Read once.
 */
function sent_review(?string $says = null): string
{
    static $kept = '';
    if ($says !== null) {
        $kept = $says;
        return $says;
    }
    $taken = $kept;
    $kept = '';
    return $taken;
}

/** The group a request id stands for: scoped to its session, so two sessions using "1" never share a group. */
function group_of_request(string $session, int $user_id, string $request_id): string
{
    $scope = $session !== '' ? $session : 'user:' . $user_id;
    return substr(hash('sha256', $scope . "\0" . $request_id), offset: 0, length: 32);
}

function valid_request_id(string $request_id): bool
{
    return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $request_id) === 1;
}

function resolve_group(string $session, int $user_id, string $request_id): string
{
    if (valid_request_id($request_id)) {
        return group_of_request($session, $user_id, $request_id);
    }
    return latest_group($session, $user_id, time() - GROUP_PAUSE) ?? bin2hex(random_bytes(16));
}

/** The group an execution belongs to, for effects that happen later: a scheduled event's emails. */
function group_of_execution(string $execution): string
{
    return (string) own_var(prepared('SELECT grp FROM %i WHERE execution = %s AND kind = %s LIMIT 1', [
        table(),
        $execution,
        'execution',
    ]));
}

/**
 * The latest group of a session, if it changed something after $since: reads do not keep a group open. A connection
 * without sessions (STDIO) counts as one session per user.
 */
function latest_group(string $session, int $user_id, int $since): ?string
{
    $group = own_var(prepared(
        'SELECT grp FROM %i WHERE session = %s AND user_id = %d AND kind = %s AND listed = 1 AND created >= %d ORDER BY id DESC LIMIT 1',
        [
            table(),
            $session,
            $user_id,
            'execution',
            $since,
        ],
    ));
    return $group !== null && $group !== '' ? $group : null;
}

/**
 * @param list<Tool> $tools
 * @return list<Tool>
 */
function decorate_tools(array $tools): array
{
    if (novamira_is_read_mode()) {
        return $tools;
    }
    $result = [];
    foreach ($tools as $tool) {
        $data = $tool->toArray();
        if (($data['name'] ?? '') !== 'mcp-adapter-execute-ability') {
            $result[] = $tool;
            continue;
        }
        /** @var array{properties?: array<string, mixed>, ...} $schema */
        $schema = $data['inputSchema'];
        $schema['properties'] ??= [];
        $schema['properties']['request_id'] = [
            'type' => 'string',
            'pattern' => '^[A-Za-z0-9_-]{1,64}$',
            'description' => 'The same short code on every call that serves one request of the user, and a new code for each new request. It is only a code: do not put the request in it.',
        ];
        $data['inputSchema'] = $schema;
        $description = is_string($data['description'] ?? null) ? $data['description'] : '';
        $data['description'] = $description . "\n\n" . history_instructions() . request_grouping_instructions();
        $result[] = Tool::fromArray($data);
    }
    return $result;
}

/** What the AI is told about the AI change history, through every door. */
function history_instructions(): string
{
    $emails = Confirmations\holds_emails()
        ? 'Emails sent through WordPress while you work wait in AI Activity until a person sends or discards them: '
        . 'tell the user, and do not send them again another way. '
        : '';
    return (
        'AI change history: Novamira records your database changes, with their previous values when it can, for 30 '
        . 'days. Read the history only when the user asks to restore something done earlier, even days ago: '
        . 'novamira/list-changes shows what was done and how to restore it. To restore one operation, pass its '
        . 'operation_id: if operations_since is above 0 it is not the latest, so tell the user and ask whether to undo '
        . 'only that or also what came after it (newest first). It counts only AI work: people may have edited the '
        . 'site by hand since, so compare what the site holds now with what the operation did, and say so if it '
        . 'differs. '
        . $emails
    );
}

/** Novamira Chat groups the calls of one message by itself: MCP and REST are asked for a request_id. */
function request_grouping_instructions(): string
{
    return (
        'Send request_id so that the calls of one request of the user become one item a person can read. When the '
        . 'work is done, call novamira/describe-changes with that request_id and one sentence saying what you did, not '
        . 'what the user asked. '
    );
}

/**
 * Keep the AI's sentence about what a group of calls did. It is the AI's own description, shown as such: it
 * proves nothing about what happened.
 *
 * @return array{group_id: string, description: string}|null Null when there is no group to describe.
 */
function save_description(string $request_id, string $text): ?array
{
    ensure_table();
    $session = Confirmations\session_id();
    $user_id = get_current_user_id();
    $group = valid_request_id($request_id)
        ? group_of_request($session, $user_id, $request_id)
        : latest_group($session, $user_id, since: 0);
    if ($group === null || entries('grp = %s AND kind = %s', [$group, 'execution']) === []) {
        $group = approved_group($request_id, $user_id);
    }
    if ($group === null) {
        return null;
    }
    $description = hide_secrets(mb_substr(trim($text), start: 0, length: DESCRIPTION_LIMIT));
    own_query(prepared('DELETE FROM %i WHERE grp = %s AND kind = %s', [table(), $group, 'description']));
    add_entry([
        'execution' => $group,
        'kind' => 'description',
        'grp' => $group,
        'session' => $session,
        'user_id' => $user_id,
        'old_value' => ['text' => $description],
    ]);
    return ['group_id' => $group, 'description' => $description];
}

/**
 * The group of a call a person approved, by the approval's ID: an AI that waited for the approval may give that ID
 * instead of its own code. Only the AI that sent the call finds it.
 */
function approved_group(string $id, int $user_id): ?string
{
    if (preg_match('/^[a-f0-9]{32}$/', $id) !== 1 || !function_exists('Novamira\\Confirmations\\get_request')) {
        return null;
    }
    $record = \Novamira\Confirmations\get_request($id);
    if ($record === null || $record['user_id'] !== $user_id) {
        return null;
    }
    $result = $record['result'] ?? [];
    $content = is_array($result['structuredContent'] ?? null) ? $result['structuredContent'] : [];
    $journal = is_array($content['novamira_journal'] ?? null) ? $content['novamira_journal'] : [];
    $group = is_string($journal['group_id'] ?? null) ? $journal['group_id'] : '';
    if ($group === '' || entries('grp = %s AND kind = %s', [$group, 'execution']) === []) {
        return null;
    }
    return $group;
}

/**
 * Recent groups, newest first. A search looks through the words of each group, in Novamira's facts and the AI's
 * description, in the latest SEARCH_GROUPS groups.
 *
 * @return array{groups: list<array<string, mixed>>, has_more: bool}
 */
function list_groups(int $page, string $search = ''): array
{
    if (!table_ready()) {
        return ['groups' => [], 'has_more' => false];
    }
    $per_page = 25;
    if ($search === '') {
        $ids = group_ids($per_page + 1, ($page - 1) * $per_page);
        $groups = array_map(__NAMESPACE__ . '\\describe_group', array_slice($ids, offset: 0, length: $per_page));
        return ['groups' => $groups, 'has_more' => count($ids) > $per_page];
    }
    $found = [];
    foreach (group_ids(SEARCH_GROUPS, offset: 0) as $id) {
        $group = describe_group($id);
        if (group_matches($group, $search)) {
            $found[] = $group;
        }
    }
    return [
        'groups' => array_slice($found, ($page - 1) * $per_page, length: $per_page),
        'has_more' => count($found) > ($page * $per_page),
    ];
}

/** @param array<string, mixed> $group */
function group_matches(array $group, string $search): bool
{
    $changes = is_array($group['changes'] ?? null) ? $group['changes'] : [];
    $description = is_string($group['description'] ?? null) ? $group['description'] : '';
    return (
        mb_stripos($description . "\n" . implode("\n", array_filter($changes, callback: 'is_string')), $search)
        !== false
    );
}

/** @return list<string> */
function group_ids(int $limit, int $offset): array
{
    $rows = own_rows(prepared(
        'SELECT grp, MAX(id) AS newest FROM %i WHERE kind = %s AND listed = 1 GROUP BY grp ORDER BY newest DESC LIMIT %d OFFSET %d',
        [
            table(),
            'execution',
            $limit,
            $offset,
        ],
    ));
    return array_map(static fn(array $row): string => $row['grp'] ?? '', $rows ?? []);
}

/**
 * One group as a person or the AI reads it: facts from Novamira, and the AI's description marked as its own.
 *
 * @return array<string, mixed>
 */
function describe_group(string $group): array
{
    // Reads that left no mark say nothing about what happened to this work.
    $entries = entries("grp = %s AND ((kind = 'execution' AND listed = 1) OR kind IN ('mail', 'description'))", [
        $group,
    ]);
    return array_merge(['group_id' => $group], summarize_entries($entries));
}

/**
 * The AI's work one operation at a time, newest first: each call that left a mark, with what it changed. A request
 * the AI made of several calls shows as several items, so each can be looked at and restored on its own.
 *
 * @return array{operations: list<array<string, mixed>>, has_more: bool}
 */
function list_operations(int $page): array
{
    if (!table_ready()) {
        return ['operations' => [], 'has_more' => false];
    }
    $per_page = 25;
    $rows = own_rows(prepared('SELECT execution, grp FROM %i WHERE kind = %s AND listed = 1 ORDER BY id DESC LIMIT %d OFFSET %d', [
        table(),
        'execution',
        $per_page + 1,
        ($page - 1) * $per_page,
    ]));
    $operations = [];
    foreach (array_slice($rows ?? [], offset: 0, length: $per_page) as $row) {
        $operations[] = describe_operation($row['execution'] ?? '', $row['grp'] ?? '');
    }
    return ['operations' => $operations, 'has_more' => count($rows ?? []) > $per_page];
}

/**
 * One operation: what that call changed, the emails it held or sent, and the description the AI gave its request.
 *
 * @return array<string, mixed>
 */
function describe_operation(string $execution, string $group): array
{
    $entries = entries("execution = %s AND ((kind = 'execution' AND listed = 1) OR kind = 'mail')", [$execution]);
    $descriptions = $group === '' ? [] : entries('grp = %s AND kind = %s', [$group, 'description']);
    return array_merge([
        'group_id' => $group,
        'execution_id' => $execution,
    ], summarize_entries([...$entries, ...$descriptions]));
}

/**
 * What a set of history entries says, as a person or the AI reads it.
 *
 * @param list<array{id: int, execution: string, session: string, grp: string, user_id: int, created: int, kind: string, tbl: string, pk_col: string, pk: string, old_value: mixed, status: string}> $entries
 * @return array<string, mixed>
 */
function summarize_entries(array $entries): array
{
    $facts = fresh_facts();
    $info = [
        'description' => '',
        'steps' => 0,
        'messages' => [],
        'emails' => [],
        'sent_emails' => 0,
        'sent_requests' => 0,
        'files' => 0,
        'changed_rows' => 0,
        'not_restorable' => 0,
        'states' => [],
        'first' => 0,
        'last' => 0,
        'session' => '',
        'user_id' => 0,
    ];
    foreach ($entries as $entry) {
        $info = add_to_group($info, $entry, $facts);
    }
    $session = Confirmations\session_id();
    ['says' => $says, 'approved_by' => $approved_by, 'file' => $file, 'requests' => $requests] = execution_words(
        $entries,
    );
    return [
        'state' => group_state($info['states']),
        'says' => $says,
        'file' => $file,
        'approved_by' => $approved_by > 0 ? user_name($approved_by) : '',
        'approval_requests' => $requests,
        'started_at' => gmdate('c', $info['first']),
        'finished_at' => gmdate('c', $info['last']),
        'this_session' => $session !== '' && $session === $info['session'],
        'user_id' => $info['user_id'],
        'user_name' => user_name($info['user_id']),
        'steps' => $info['steps'],
        'description' => $info['description'],
        'changes' => fact_lines($facts),
        'technical_changes' => $facts['technical'],
        'changed_rows' => $info['changed_rows'],
        'not_restorable' => $info['not_restorable'],
        'messages' => $info['messages'],
        'emails' => $info['emails'],
        'sent_emails' => $info['sent_emails'],
        'sent_requests' => $info['sent_requests'],
        'files' => $info['files'],
    ];
}

/**
 * What the AI said its calls change, the latest it said, the file a file ability worked on, and who approved one of
 * them, if a person did.
 *
 * @param list<array{id: int, execution: string, session: string, grp: string, user_id: int, created: int, kind: string, tbl: string, pk_col: string, pk: string, old_value: mixed, status: string}> $entries
 * @return array{says: string, approved_by: int, file: string, requests: list<string>}
 */
function execution_words(array $entries): array
{
    $says = '';
    $file = '';
    $approved_by = 0;
    $requests = [];
    foreach ($entries as $entry) {
        if ($entry['kind'] !== 'execution' || !is_array($entry['old_value'])) {
            continue;
        }
        if (is_string($entry['old_value']['says'] ?? null) && $entry['old_value']['says'] !== '') {
            $says = $entry['old_value']['says'];
        }
        if (is_int($entry['old_value']['approved_by'] ?? null) && $entry['old_value']['approved_by'] > 0) {
            $approved_by = $entry['old_value']['approved_by'];
        }
        if (is_string($entry['old_value']['file'] ?? null) && $entry['old_value']['file'] !== '') {
            $file = $entry['old_value']['file'];
        }
        // The approval request a person decided on, when this execution is the call they approved.
        if (
            is_string($entry['old_value']['approval_request'] ?? null)
            && $entry['old_value']['approval_request'] !== ''
        ) {
            $requests[] = $entry['old_value']['approval_request'];
        }
    }
    return [
        'says' => $says,
        'approved_by' => $approved_by,
        'file' => $file,
        'requests' => array_values(array_unique($requests)),
    ];
}

function user_name(int $user_id): string
{
    $user = get_userdata($user_id);
    return is_object($user) ? $user->display_name : '#' . $user_id;
}

/** A request that died never said so: past the time one can run, it no longer is running. */
function shown_state(string $status, int $created): string
{
    return $status === 'running' && $created < (time() - Confirmations\execution_timeout()) ? 'interrupted' : $status;
}

/**
 * @param array{description: string, steps: int, messages: list<string>, emails: list<array<string, mixed>>, sent_emails: int, sent_requests: int, files: int, changed_rows: int, not_restorable: int, states: list<string>, first: int, last: int, session: string, user_id: int} $info
 * @param array{id: int, kind: string, status: string, created: int, session: string, user_id: int, old_value: mixed, ...} $entry
 * @param array{items: array<string, array{type: string, id: string, title: string, post_type: string, actions: array<string, int>, details: array<string, int>}>, more: int, technical: int, other: array<string, int>, tables: array<string, string>} $facts
 * @return array{description: string, steps: int, messages: list<string>, emails: list<array<string, mixed>>, sent_emails: int, sent_requests: int, files: int, changed_rows: int, not_restorable: int, states: list<string>, first: int, last: int, session: string, user_id: int}
 */
function add_to_group(array $info, array $entry, array &$facts): array
{
    $value = is_array($entry['old_value']) ? $entry['old_value'] : [];
    if ($entry['kind'] !== 'execution') {
        return add_effect_to_group($info, $entry, $value);
    }
    $info['steps']++;
    $info['states'][] = shown_state($entry['status'], $entry['created']);
    $info['first'] = $info['first'] === 0 ? $entry['created'] : min($info['first'], $entry['created']);
    $info['last'] = max($info['last'], $entry['created']);
    $info['session'] = $entry['session'];
    $info['user_id'] = $entry['user_id'];
    if (is_string($value['message'] ?? null) && $value['message'] !== '') {
        $info['messages'][] = $value['message'];
    }
    $info = add_summary_to_group(
        $info,
        is_array($value['summary'] ?? null) ? $value['summary'] : [],
        $value['ability'] ?? null,
    );
    $facts = merge_facts($facts, $value['facts'] ?? null);
    return $info;
}

/**
 * What an execution's summary adds to its group.
 *
 * @param array{description: string, steps: int, messages: list<string>, emails: list<array<string, mixed>>, sent_emails: int, sent_requests: int, files: int, changed_rows: int, not_restorable: int, states: list<string>, first: int, last: int, session: string, user_id: int} $info
 * @param array<array-key, mixed> $summary
 * @return array{description: string, steps: int, messages: list<string>, emails: list<array<string, mixed>>, sent_emails: int, sent_requests: int, files: int, changed_rows: int, not_restorable: int, states: list<string>, first: int, last: int, session: string, user_id: int}
 */
function add_summary_to_group(array $info, array $summary, mixed $ability): array
{
    $info['changed_rows'] += is_array($summary['changed_rows'] ?? null)
        ? (int) array_sum(array_filter($summary['changed_rows'], callback: 'is_int'))
        : 0;
    $info['not_restorable'] +=
        (is_int($summary['unverified_writes'] ?? null) ? $summary['unverified_writes'] : 0)
        + ($ability === 'novamira/run-wp-cli' ? 1 : 0);
    $info['sent_emails'] += is_int($summary['sent_emails'] ?? null) ? $summary['sent_emails'] : 0;
    $info['sent_requests'] += is_int($summary['sent_requests'] ?? null) ? $summary['sent_requests'] : 0;
    $info['files'] += is_string($summary['files'] ?? null) ? 1 : 0;
    return $info;
}

/**
 * @param array{description: string, steps: int, messages: list<string>, emails: list<array<string, mixed>>, sent_emails: int, sent_requests: int, files: int, changed_rows: int, not_restorable: int, states: list<string>, first: int, last: int, session: string, user_id: int} $info
 * @param array{id: int, kind: string, status: string, ...} $entry
 * @param array<array-key, mixed> $value
 * @return array{description: string, steps: int, messages: list<string>, emails: list<array<string, mixed>>, sent_emails: int, sent_requests: int, files: int, changed_rows: int, not_restorable: int, states: list<string>, first: int, last: int, session: string, user_id: int}
 */
function add_effect_to_group(array $info, array $entry, array $value): array
{
    if ($entry['kind'] === 'description') {
        $info['description'] = is_string($value['text'] ?? null) ? $value['text'] : '';
    }
    if ($entry['kind'] === 'mail') {
        $info['emails'][] = mail_view($entry, $value);
    }
    return $info;
}

/**
 * The state of a group, from those of its executions.
 *
 * @param list<string> $states
 */
function group_state(array $states): string
{
    $distinct = array_values(array_unique($states));
    foreach (['running', 'halted', 'interrupted', 'failed'] as $state) {
        if (in_array($state, $distinct, strict: true)) {
            return $state;
        }
    }
    return 'completed';
}

/**
 * The calls of a group, for a person who wants to see what ran.
 *
 * @return list<array<string, mixed>>
 */
function group_steps(string $group): array
{
    $steps = [];
    $executions = entries('grp = %s AND kind = %s AND listed = 1', [$group, 'execution']);
    foreach ($executions as $entry) {
        $value = is_array($entry['old_value']) ? $entry['old_value'] : [];
        /** @var mixed $call */
        $call = entries('execution = %s AND kind = %s', [$entry['execution'], 'call'])[0]['old_value'] ?? null;
        $steps[] = [
            'execution_id' => $entry['execution'],
            'ability' => $value['ability'] ?? '',
            'state' => $entry['status'],
            'created_at' => gmdate('c', $entry['created']),
            'arguments' => is_array($call) ? $call['arguments'] ?? '' : '',
            'changed_rows' => is_array($value['summary'] ?? null) ? $value['summary']['changed_rows'] ?? [] : [],
            'attention' => is_int($value['attention'] ?? null) ? $value['attention'] : -1,
            'site_rules' => is_string($value['site_rules'] ?? null) ? $value['site_rules'] : '',
        ];
    }
    return $steps;
}
