<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * One decision at a time per conversation. Calls a conversation sends while one of its requests waits join that
 * request, up to BLOCK_LIMIT, and a person decides them together. They run in the order they were sent, each only once
 * the one before it completed. Other conversations keep their own decisions.
 *
 * Each call keeps its own request and request_id. The first one leads the block: its id names the block. A conversation
 * points to the block it has open with a row of its own (SLOT_PREFIX), and the block has a header saying whether it is
 * still open to new calls, and one row per position. All three are written with atomic inserts and compare-and-swap
 * updates, so they hold without an object cache.
 */

namespace Novamira\Confirmations;

use Novamira\Vendor\WP\MCP\Core\McpServer;
use WP_Error;

if (!defined('ABSPATH')) {
    exit();
}

/** The block a conversation has open, by a hash of where the conversation comes from. */
const SLOT_PREFIX = 'novamira_confirmation_slot_';

/** The header of a block ("open" or "decided") and, with "_<position>", the request at each position. */
const BLOCK_PREFIX = 'novamira_confirmation_block_';

/** How many calls one decision covers: a person still reads every one of them. */
const BLOCK_LIMIT = 10;

/** Before blocks, one row held the one request of the whole site. */
const LEGACY_SLOT_OPTION = 'novamira_confirmation_slot';

/** The error code of a small call whose block was decided while it was being queued: it runs as usual instead. */
const BLOCK_GONE = 'novamira_block_gone';

/** The MCP sessions that opened a user's blocks on a server: a reconnected conversation finds its block again. */
const LAST_SESSION_PREFIX = 'novamira_confirmation_last_';

/**
 * Where an MCP call comes from: its MCP session, or the user on that server when the connection has no sessions
 * (STDIO). A client that reconnects gets a new session, and the conversation would lose the decision it has waiting:
 * when a session of this user that opened a block is gone and that block still waits or runs, the new session carries
 * it on, so its next call joins it or waits behind it instead of running ahead. A session still alive is another
 * conversation and keeps its own.
 */
function mcp_scope(McpServer $server): string
{
    $owner = call_owner($server);
    $session = session_id();
    if ($session === '') {
        return $owner;
    }
    $own = $owner . ':' . $session;
    if (key_value(slot_name($own)) !== null) {
        return $own;
    }
    foreach (option_names(block_sessions_prefix($owner)) as $name) {
        $earlier = key_value($name);
        if ($earlier === null || $earlier === $session || session_alive(get_current_user_id(), $earlier)) {
            continue;
        }
        if (live_block($owner . ':' . $earlier) !== null) {
            return $owner . ':' . $earlier;
        }
        release_key($name, $earlier);
    }
    return $own;
}

function block_sessions_prefix(string $owner): string
{
    return LAST_SESSION_PREFIX . substr(hash('sha256', $owner), offset: 0, length: 32) . '_';
}

/** Remember a session that opened a block over MCP, for its conversation to find the block after reconnecting. */
function remember_block_session(string $owner, string $session): void
{
    if ($session !== '') {
        insert_row(block_sessions_prefix($owner) . substr(hash('sha256', $session), offset: 0, length: 32), $session);
    }
}

/** A direct REST run has no session: the user and the credential it signs in with stand for the conversation. */
function rest_scope(): string
{
    return rest_owner() . ':' . rest_connection();
}

function rest_connection(): string
{
    $uuid = function_exists('rest_get_authenticated_app_password') ? rest_get_authenticated_app_password() : null;
    if (is_string($uuid) && $uuid !== '') {
        return 'app:' . $uuid;
    }
    if (!answers_as_result()) {
        return 'cookie';
    }
    $client = oauth_client();
    return $client === '' ? 'oauth' : 'oauth:' . $client;
}

/** The OAuth client of the token this request signed in with, or nothing when it cannot be told. */
function oauth_client(): string
{
    if (
        !function_exists('Novamira\\OAuth\\Middleware\\request_oauth_token_id')
        || !class_exists('Novamira\\OAuth\\Repositories\\AccessTokenRepository')
    ) {
        return '';
    }
    $token = \Novamira\OAuth\Middleware\request_oauth_token_id();
    if ($token === '') {
        return '';
    }
    return (new \Novamira\OAuth\Repositories\AccessTokenRepository())->get_client_id_by_token_identifier($token) ?? '';
}

function slot_name(string $scope): string
{
    return SLOT_PREFIX . hash('sha256', $scope);
}

function position_name(string $lead, int $position): string
{
    return BLOCK_PREFIX . $lead . '_' . $position;
}

/** Add a row only if it does not exist yet: the database decides between two calls arriving together. */
function insert_row(string $name, string $value): bool
{
    $wpdb = database();
    $insert = $wpdb->prepare(
        "INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
        $wpdb->options,
        $name,
        $value,
    );
    return is_string($insert) && $wpdb->query($insert) === 1;
}

/** Change a row only while it still holds the value this request read. */
function swap_row(string $name, string $from, string $to): bool
{
    $wpdb = database();
    $update = $wpdb->prepare(
        'UPDATE %i SET option_value = %s WHERE option_name = %s AND BINARY option_value = %s',
        $wpdb->options,
        $to,
        $name,
        $from,
    );
    return is_string($update) && $wpdb->query($update) === 1;
}

/**
 * The ids of a block, in the order its calls were sent.
 *
 * @return list<string>
 */
function block_ids(string $lead): array
{
    $ids = [];
    for ($position = 0; $position < BLOCK_LIMIT; $position++) {
        $id = key_value(position_name($lead, $position));
        if ($id !== null && is_request_id($id)) {
            $ids[] = $id;
        }
    }
    return $ids;
}

/**
 * The requests of a block, refreshed, in the order their calls were sent.
 *
 * @return list<array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int}>
 */
function block_records(string $lead): array
{
    $records = [];
    foreach (block_ids($lead) as $id) {
        $record = get_request($id);
        if ($record !== null) {
            $records[] = refresh_record($record);
        }
    }
    return $records;
}

/**
 * The block a request belongs to. A request saved before blocks existed is a block of its own.
 *
 * @param array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int} $record
 * @return list<array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int}>
 */
function block_of(array $record): array
{
    if (!array_key_exists('block', $record)) {
        return [$record];
    }
    $members = block_records($record['block']);
    return $members === [] ? [$record] : $members;
}

/** Whether a request was approved together with others. */
function in_block_of_many(string $lead): bool
{
    return count(block_ids($lead)) > 1;
}

/**
 * The block a conversation still has waiting or running, or null. Requests that can no longer run because of an
 * earlier one are marked not_run on the way.
 *
 * @return array{lead: array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int}, members: list<array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int}>}|null
 */
function live_block(string $scope): ?array
{
    $id = key_value(slot_name($scope));
    $lead = $id !== null && is_request_id($id) ? get_request($id) : null;
    if ($lead === null) {
        return null;
    }
    $lead_id = $lead['block'] ?? $lead['id'];
    next_in_block($lead_id);
    $members = block_of(refresh_record($lead));
    foreach ($members as $member) {
        if (in_array($member['state'], ACTIVE_STATES, strict: true)) {
            return ['lead' => $members[0], 'members' => $members];
        }
    }
    // Over: the next call of this conversation finds no slot and reads nothing more.
    release_key(slot_name($scope), $lead['id']);
    return null;
}

/**
 * Atomically take a conversation's slot for a new block. Returns true when it is now this block's, the block still
 * holding it, or false when concurrent requests kept taking it over.
 *
 * @return bool|array{lead: array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int}, members: list<array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int}>}
 */
function claim_slot(string $scope, string $id): bool|array
{
    $name = slot_name($scope);
    for ($attempt = 0; $attempt < 3; $attempt++) {
        if (insert_row($name, $id)) {
            return true;
        }
        $current = key_value($name);
        if ($current === null) {
            continue; // Released between the insert and the read: try to insert again.
        }
        $holder = live_block($scope);
        if ($holder !== null) {
            return $holder;
        }
        // The block holding it is over: take it over only if nobody else did meanwhile.
        if (swap_row($name, $current, $id)) {
            return true;
        }
    }
    return false;
}

/** A new block starts open, with its first request at position 0, before any other call can see it. */
function open_block(string $id): bool
{
    return insert_row(BLOCK_PREFIX . $id, value: 'open') && insert_row(position_name($id, position: 0), $id);
}

function drop_block(string $id): void
{
    release_key(position_name($id, position: 0), $id);
    release_key(BLOCK_PREFIX . $id, id: 'open');
}

/** A request that could not be placed leaves nothing behind: no record, and no key pointing to it. */
function abandon_request(string $id, string $key): void
{
    release_key($key, $id);
    delete_option(REQUEST_PREFIX . $id);
}

/**
 * Put a saved request where it belongs: at the head of a new block, or in the block its conversation has waiting.
 *
 * @param array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int} $record
 * @return array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int}|WP_Error
 */
// @mago-expect lint:no-boolean-flag-parameter -- A small call only joins a block that waits; it never starts one.
function place_request(array $record, string $key, string $scope, bool $join_only): array|WP_Error
{
    $id = $record['id'];
    if ($join_only) {
        $holder = live_block($scope);
        if ($holder === null) {
            abandon_request($id, $key);
            return new WP_Error(BLOCK_GONE, 'No decision waits for this call.');
        }
        return join_block($record, $key, $holder);
    }
    if (!open_block($id)) {
        drop_block($id);
        abandon_request($id, $key);
        return new WP_Error('not_saved', 'Could not save confirmation request. Nothing has executed.');
    }
    $slot = claim_slot($scope, $id);
    if ($slot === true) {
        return $record;
    }
    drop_block($id);
    if ($slot === false) {
        abandon_request($id, $key);
        return new WP_Error(
            'approval_waiting',
            'Another action is being queued at the same time. Nothing has executed.',
        );
    }
    return join_block($record, $key, $slot);
}

/**
 * Join a block that waits, at the first free position. A person who decides at the same moment either sees this call
 * on the card, or this call withdraws: nothing is approved that the card did not show.
 *
 * @param array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int} $record
 * @param array{lead: array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int}, members: list<array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int}>} $holder
 * @return array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int}|WP_Error
 */
function join_block(array $record, string $key, array $holder): array|WP_Error
{
    $lead = $holder['lead'];
    if ($lead['state'] !== 'pending_confirmation') {
        abandon_request($record['id'], $key);
        return new WP_Error('approval_waiting', block_running_text());
    }
    for ($position = 1; $position < BLOCK_LIMIT; $position++) {
        // Nothing else knows this request before its row exists: setting its place cannot race.
        $member = array_merge($record, [
            'block' => $lead['id'],
            'position' => $position,
            'block_started' => $lead['block_started'] ?? $lead['created'],
        ]);
        if (!transition($record, $member)) {
            abandon_request($record['id'], $key);
            return new WP_Error('not_saved', 'Could not save confirmation request. Nothing has executed.');
        }
        $record = $member;
        if (insert_row(position_name($lead['id'], $position), $record['id'])) {
            return joined_or_withdrawn($record, $key);
        }
    }
    abandon_request($record['id'], $key);
    return new WP_Error('approval_waiting', waiting_text($lead));
}

/**
 * A call that took its place after the block was sealed for a decision withdraws, unless the decision already
 * included it.
 *
 * @param array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int} $record
 * @return array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int}|WP_Error
 */
function joined_or_withdrawn(array $record, string $key): array|WP_Error
{
    $lead = $record['block'] ?? $record['id'];
    if (key_value(BLOCK_PREFIX . $lead) === 'open') {
        return $record;
    }
    wp_cache_delete(REQUEST_PREFIX . $record['id'], group: 'options');
    $fresh = get_request($record['id']) ?? $record;
    if (
        $fresh['state'] !== 'pending_confirmation'
        || !transition($fresh, array_merge($fresh, ['state' => 'canceled']))
    ) {
        return $fresh;
    }
    release_key(position_name($lead, $record['position'] ?? 0), $record['id']);
    abandon_request($record['id'], $key);
    return new WP_Error(
        'approval_waiting',
        'A person decided the earlier actions of this conversation while this call was being sent, so it did not join '
        . 'them. Nothing has executed: send this call again.',
    );
}

/**
 * What a call meets when its conversation has a block: the request it already is when it is sent again, a refusal, or
 * null to go on and join it.
 *
 * @param array{lead: array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int}, members: list<array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int}>}|null $block
 * @param array<array-key, mixed> $clean_args
 * @return array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int}|string|null
 */
function block_answer(?array $block, string $tool, array $clean_args, string $caller): array|string|null
{
    if ($block === null) {
        return null;
    }
    foreach ($block['members'] as $member) {
        if (
            in_array($member['state'], ACTIVE_STATES, strict: true)
            && holds_same_call($member, $tool, $clean_args, $caller)
        ) {
            return $member;
        }
    }
    if ($block['lead']['state'] !== 'pending_confirmation') {
        return block_running_text();
    }
    return count($block['members']) >= BLOCK_LIMIT ? waiting_text($block['lead']) : null;
}

function block_running_text(): string
{
    return (
        'Earlier actions of this conversation were approved and are running. Nothing has executed: send this call '
        . 'again when they finish, and read their results with their request_id.'
    );
}

/**
 * The next approved request of a block that may run now, or null. Each runs only after the one before it completed,
 * within APPROVAL_TTL of it. What can no longer run is marked not_run, and says why.
 *
 * @return array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int}|null
 */
function next_in_block(string $lead): ?array
{
    $broken = '';
    $ready = 0;
    foreach (block_records($lead) as $member) {
        $state = $member['state'];
        if ($state === 'completed') {
            $ready = $member['finished'] ?? time();
            if ($broken === '' && started_in_background($member['result'] ?? [])) {
                $broken =
                    'An earlier action approved with this one started a command that runs in the background, so this '
                    . 'one did not run while it may still be changing the site.';
            }
            continue;
        }
        if ($state === 'executing' || $state === 'pending_confirmation') {
            return null;
        }
        if ($state !== 'approved') {
            $broken = $broken === '' ? broken_reason($state) : $broken;
            continue;
        }
        $broken = $broken === '' ? late_reason($member, $ready) : $broken;
        if ($broken !== '') {
            mark_not_run($member, $broken);
            continue;
        }
        return $member;
    }
    return null;
}

/** Why the requests after one that did not complete do not run. */
function broken_reason(string $state): string
{
    return match ($state) {
        'failed' => 'An earlier action approved with this one failed, so this one did not run.',
        'execution_unknown' => 'An earlier action approved with this one was interrupted, so this one did not run.',
        default => 'An earlier action approved with this one did not run, so this one did not run either.',
    };
}

/**
 * Each request runs within APPROVAL_TTL of the one before it, or of the approval for the first: the person approved
 * what the site looked like then.
 *
 * @param array{approved_at?: int, ...} $member
 */
function late_reason(array $member, int $ready): string
{
    $start = $ready > 0 ? $ready : $member['approved_at'] ?? time();
    if (($start + APPROVAL_TTL) >= time()) {
        return '';
    }
    return (
        'It did not start within '
        . (string) (APPROVAL_TTL / 60)
        . ' minutes of the action before it, so it did not run, and neither did the actions after it.'
    );
}

/** @param array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int} $member */
function mark_not_run(array $member, string $reason): void
{
    transition($member, array_merge($member, [
        'state' => 'not_run',
        'finished' => time(),
        'result' => error_response($reason . ' Nothing of this action has executed.')->toArray(),
    ]));
}

/**
 * Run the approved requests of a block, in order, until one is still running elsewhere, cannot run here, or $until
 * has run. A chain cut halfway goes on from the next status call or page refresh.
 */
function drive_block(string $lead, ?McpServer $server = null, string $until = ''): void
{
    $limit = (int) ini_get('max_execution_time');
    $started = time();
    for ($step = 0; $step < BLOCK_LIMIT; $step++) {
        $next = next_in_block($lead);
        // A long chain does not start a new call near PHP's limit: what is left runs on the next status call.
        if ($next === null || $step > 0 && $limit > 0 && ((time() - $started) * 2) > $limit) {
            return;
        }
        if ($limit > 0) {
            set_time_limit($limit);
        }
        $ran = run_approved($next, $server);
        if (
            !in_array($ran['state'], ['completed', 'failed', 'execution_unknown'], strict: true)
            || $ran['id'] === $until
        ) {
            return;
        }
    }
}

/**
 * Keep approved blocks moving from the page: a chain cut halfway runs on. Whether there was one.
 *
 * @param list<array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int}> $active
 */
function drive_blocks(array $active): bool
{
    $leads = [];
    foreach ($active as $record) {
        if ($record['state'] !== 'approved' || !array_key_exists('block', $record)) {
            continue;
        }
        $leads[$record['block']] = true;
    }
    $driven = false;
    foreach (array_keys($leads) as $lead) {
        if (!in_block_of_many($lead)) {
            continue;
        }
        drive_block($lead);
        $driven = true;
    }
    return $driven;
}

/**
 * Whether an approved request waits for an earlier one of its block that is running now.
 *
 * @param array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int} $record
 */
function earlier_running(array $record): bool
{
    $position = $record['position'] ?? 0;
    if ($position === 0 || !array_key_exists('block', $record)) {
        return false;
    }
    foreach (block_records($record['block']) as $member) {
        if (($member['position'] ?? 0) < $position && $member['state'] === 'executing') {
            return true;
        }
    }
    return false;
}

/**
 * Where a request stands in its block, for the page and the AI. With other requests, also how far the block got.
 *
 * @param array{id: string, block?: string, position?: int, ...} $record
 * @param list<array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int}>|null $members
 * @return array<string, mixed>
 */
function block_fields(array $record, ?array $members = null): array
{
    $lead = $record['block'] ?? $record['id'];
    $members ??= array_key_exists('block', $record) ? block_records($lead) : [];
    $fields = [
        'block_id' => $lead,
        'block_position' => $record['position'] ?? 0,
        'block_size' => max(1, count($members)),
    ];
    if (count($members) > 1) {
        $fields['block'] = block_summary($members);
    }
    return $fields;
}

/**
 * @param list<array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int}> $members
 * @return array{size: int, ran: int, failed_at: ?int, not_run: int}
 */
function block_summary(array $members): array
{
    $ran = 0;
    $not_run = 0;
    $failed_at = null;
    foreach ($members as $member) {
        $ran += $member['state'] === 'completed' ? 1 : 0;
        $not_run += $member['state'] === 'not_run' ? 1 : 0;
        if ($failed_at === null && in_array($member['state'], ['failed', 'execution_unknown'], strict: true)) {
            $failed_at = $member['position'] ?? 0;
        }
    }
    return ['size' => count($members), 'ran' => $ran, 'failed_at' => $failed_at, 'not_run' => $not_run];
}

/**
 * The group the AI change history files the calls of a block under: the AI's own code for the first call, so the whole
 * block is one item, or the block's id when it sent none. Nothing for a request decided alone.
 *
 * @param array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int} $record
 */
function history_group_of(array $record): string
{
    $lead_id = $record['block'] ?? $record['id'];
    if (!in_block_of_many($lead_id)) {
        return '';
    }
    $lead = $lead_id === $record['id'] ? $record : get_request($lead_id);
    /** @var mixed $code */
    $code = $lead['arguments']['request_id'] ?? null;
    return is_string($code) && $code !== '' ? $code : 'approval-' . $lead_id;
}

/**
 * Option names starting with a prefix.
 *
 * @return list<string>
 */
function option_names(string $prefix): array
{
    $wpdb = database();
    $query = $wpdb->prepare(
        'SELECT option_name FROM %i WHERE option_name LIKE %s',
        $wpdb->options,
        $wpdb->esc_like($prefix) . '%',
    );
    if (!is_string($query)) {
        return [];
    }
    /** @var list<string> */
    return $wpdb->get_col($query);
}

/**
 * A conversation's slot goes once its block is over. A block's rows stay as long as its first request: the history
 * still shows which requests were decided together.
 */
function prune_blocks(): void
{
    foreach (option_names(SLOT_PREFIX) as $name) {
        $lead = key_value($name);
        if ($lead === null) {
            continue;
        }
        $record = is_request_id($lead) ? get_request($lead) : null;
        $live = false;
        foreach ($record === null ? [] : block_of($record) as $member) {
            $live = $live || in_array($member['state'], ACTIVE_STATES, strict: true);
        }
        if (!$live) {
            release_key($name, $lead);
        }
    }
    foreach (option_names(BLOCK_PREFIX) as $name) {
        $value = key_value($name);
        if ($value !== null && get_request(substr($name, strlen(BLOCK_PREFIX), length: 32)) === null) {
            release_key($name, $value);
        }
    }
    delete_option(LEGACY_SLOT_OPTION);
    // A remembered session is only useful while some block may still wait.
    if (option_names(SLOT_PREFIX) === []) {
        foreach (option_names(LAST_SESSION_PREFIX) as $name) {
            $session = key_value($name);
            if ($session !== null) {
                release_key($name, $session);
            }
        }
    }
}
