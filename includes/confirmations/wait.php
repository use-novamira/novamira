<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * How the AI notices a decision without the user telling it. Every status call is a model turn the user pays for, so a
 * client with a shell waits on a signed URL instead, as the Gutenberg queue does: curl keeps a stream open, the site
 * answers as soon as a person decides, and the command opens the next stream when one reaches its time limit. The URL
 * says only what state a request is in, and running the approved call still takes the authenticated status call.
 */

namespace Novamira\Confirmations;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit();
}

/** How long the AI keeps waiting: past this it tells the user what is waiting and stops, instead of spending tokens. */
const WAIT_LIMIT_SECONDS = 1800;

/** Seconds between two checks inside an open stream: each is one read of the request, not a new HTTP request. */
const STREAM_CHECK_SECONDS = 2;

/** The longest one stream stays open, under the 30 seconds many hosts and proxies allow; the command opens the next. */
const STREAM_DURATION_SECONDS = 25;

/** Seconds the wait command pauses after a failed connection, before trying again. */
const WAIT_RETRY_SECONDS = 5;

/** The longest a status call holds its PHP worker waiting for a decision, for clients with no shell. */
const WAIT_LONGEST_POLL_SECONDS = 20;

/** Failed requests in a row after which the wait command gives up and shows why. */
const WAIT_FAILURES_LIMIT = 6;

add_action('rest_api_init', static function (): void {
    register_rest_route('novamira/v1', route: '/confirmations/(?P<id>[a-f0-9]{32})/wait', args: [
        'methods' => 'GET',
        'permission_callback' => __NAMESPACE__ . '\\wait_permission',
        'callback' => __NAMESPACE__ . '\\wait_status',
    ]);
});

function wait_token(string $id, int $expires): string
{
    return substr(
        hash_hmac('sha256', 'confirmation-wait|' . $id . '|' . $expires, wp_salt('auth')),
        offset: 0,
        length: 32,
    );
}

/** The status URL of a request. It lasts as long as the request can wait, and a bit more to report that it expired. */
function wait_url(string $id, int $created): string
{
    $expires = $created + REQUEST_TTL + 300;
    return add_query_arg([
        'expires' => $expires,
        'token' => wait_token($id, $expires),
    ], rest_url('novamira/v1/confirmations/' . $id . '/wait'));
}

/** The stream of a request: the same signed URL, held open and answered as soon as a person decides. */
function stream_url(string $id, int $created): string
{
    return add_query_arg(['stream' => '1'], wait_url($id, $created));
}

/**
 * A shell command that returns once a person has decided, and prints what the AI does next. It holds one connection
 * at a time; a host that buffers or cuts the stream only makes the answer arrive at the end of that connection.
 */
function wait_command(string $id, int $created): string
{
    $url = escapeshellarg(stream_url($id, $created));
    return (
        'f=0; while :; do r=$(curl -sS -N --max-time '
        . (STREAM_DURATION_SECONDS + 20)
        . " {$url} 2>&1); case \"\$r\" in *'event: done'*) printf '%s\\n' \"\$r\" | sed -n 's/^data: //p' | tail -n 1; break ;; "
        . "*'event: reconnect'*) f=0 ;; *) f=$((f+1)); [ \"\$f\" -ge "
        . WAIT_FAILURES_LIMIT
        . " ] && { printf '%s\\n' \"\$r\"; break; }; sleep "
        . WAIT_RETRY_SECONDS
        . ' ;; esac; done'
    );
}

function wait_permission(WP_REST_Request $request): bool|WP_Error
{
    /** @var mixed $id */
    $id = $request->get_param('id');
    /** @var mixed $expires */
    $expires = $request->get_param('expires');
    /** @var mixed $token */
    $token = $request->get_param('token');
    if (
        is_string($id)
        && is_numeric($expires)
        && is_string($token)
        && (int) $expires >= time()
        && hash_equals(wait_token($id, (int) $expires), $token)
    ) {
        return true;
    }
    return new WP_Error(
        'novamira_confirmation_wait_url',
        'This status URL is not valid anymore. Ask for the state of the request through its status tool instead.',
        ['status' => 403],
    );
}

/** Only the state and what follows from it: never the call, its code or its result. */
function wait_status(WP_REST_Request $request): WP_REST_Response|WP_Error
{
    /** @var mixed $id */
    $id = $request->get_param('id');
    if (!is_string($id)) {
        return new WP_Error('request_not_found', 'This request no longer exists.', ['status' => 404]);
    }
    if ($request->get_param('stream') !== null) {
        send_stream_headers();
        stream_wait(
            $id,
            emit: static function (string $text): void {
                echo $text;
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            },
            pause: static function (int $seconds): void {
                sleep(max(0, $seconds));
            },
            gone: static fn(): bool => (bool) connection_aborted(),
        );
        exit();
    }
    $record = get_request($id);
    if ($record === null) {
        return new WP_Error('request_not_found', 'This request no longer exists.', ['status' => 404]);
    }
    return new WP_REST_Response(wait_payload(refresh_record($record)));
}

/**
 * @param array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int} $record
 * @return array{request_id: string, state: string, waiting: bool, next_step: ?string}
 */
function wait_payload(array $record): array
{
    // The limit counts from the request, not the command: a client that cuts the command and runs it again still stops.
    // An approved call also waits while one approved with it, sent before it, is running.
    $waiting =
        $record['state'] === 'executing'
        || $record['state'] === 'pending_confirmation' && (time() - $record['created']) < WAIT_LIMIT_SECONDS
        || $record['state'] === 'approved' && earlier_running($record);
    return [
        'request_id' => $record['id'],
        'state' => $record['state'],
        'waiting' => $waiting,
        'next_step' => $waiting ? null : next_step_of($record),
    ];
}

function send_stream_headers(): void
{
    if (headers_sent()) {
        return;
    }
    status_header(200);
    header('Content-Type: text/event-stream; charset=UTF-8');
    header('Cache-Control: no-cache, no-transform');
    header('X-Accel-Buffering: no');
}

/**
 * Hold the connection until a person decides, or until it has been open STREAM_DURATION_SECONDS. It ends with a
 * `done` event carrying the state and the next step, or a `reconnect` event that tells the command to open the next.
 *
 * @param callable(string): void $emit
 * @param callable(int): void $pause
 * @param callable(): bool $gone
 */
function stream_wait(string $id, callable $emit, callable $pause, callable $gone): void
{
    $emit('retry: ' . (string) (WAIT_RETRY_SECONDS * 1000) . "\n\n");
    $elapsed = 0;
    while (!$gone()) {
        // The decision is written by another request: the copy cached in this one would never change.
        wp_cache_delete(REQUEST_PREFIX . $id, group: 'options');
        $record = get_request($id);
        if ($record === null) {
            $emit(stream_event('done', [
                'request_id' => $id,
                'state' => 'not_found',
                'waiting' => false,
                'next_step' => 'This request no longer exists. Tell the user, and send the call again only if they ask.',
            ]));
            return;
        }
        $payload = wait_payload(refresh_record($record));
        if (!$payload['waiting']) {
            $emit(stream_event('done', $payload));
            return;
        }
        if (($elapsed + STREAM_CHECK_SECONDS) >= STREAM_DURATION_SECONDS) {
            $emit(stream_event('reconnect', ['request_id' => $id, 'state' => $payload['state']]));
            return;
        }
        // A comment line keeps proxies from closing an idle connection, and lets PHP notice a closed one.
        $emit(": waiting\n\n");
        $pause(STREAM_CHECK_SECONDS);
        $elapsed += STREAM_CHECK_SECONDS;
    }
}

/** @param array<string, mixed> $data */
function stream_event(string $event, array $data): string
{
    $json = wp_json_encode($data, JSON_UNESCAPED_SLASHES);
    return 'event: ' . $event . "\n" . 'data: ' . ($json !== false ? $json : '{}') . "\n\n";
}

/**
 * The status tool of a request: its client got the request through MCP or through a direct REST run.
 *
 * @param array{tool: string, ...} $record
 */
function status_name(array $record): string
{
    return $record['tool'] === REST_TOOL ? REST_STATUS_ABILITY : STATUS_TOOL;
}

/**
 * What the AI does once a request has left the wait.
 *
 * @param array{id: string, state: string, tool: string, ...} $record
 */
function next_step_of(array $record): ?string
{
    $status = status_name($record) . ' with request_id ' . $record['id'];
    return match ($record['state']) {
        'pending_confirmation'
            => 'Nobody has decided yet, so stop waiting. Tell the user what is waiting and where to decide, and end your turn. It stays valid for '
            . (REQUEST_TTL / 3600)
            . ' hours. When they write to you, run '
            . $status
            . ': if they approved it has already run, and you read its result there.',
        'approved' => 'A person approved this call. Run '
            . $status
            . ' now: it executes the call once and returns the result. Do not ask the user to confirm again.',
        'executing' => 'This call is running. Run '
            . $status
            . ' again in a moment to get its result. Do not resend it.',
        'expired'
            => 'Nothing has executed: nobody decided in time. Tell the user, and send the call again only if they ask: it makes a new request, and a person decides again.',
        'canceled'
            => 'Nothing has executed: the approval settings changed. Tell the user, and send the call again only if they ask.',
        'rejected'
            => 'A person rejected this call. Nothing has executed. Tell the user, and send it again only if they ask.',
        'completed' => 'A person approved this call and it ran once. Run '
            . $status
            . ' to read its result, check the site, and go on with the work that is left. Do not send this call again.',
        'not_run' => 'Nothing of this call has executed: a call approved with it, sent before it, did not complete. '
            . 'Run '
            . $status
            . ' to read why, check the site, and tell the user. Send it again only if they ask.',
        'failed', 'execution_unknown' => 'Run '
            . $status
            . ' to read the outcome and check the site. Do not send this call again automatically.',
        default => null,
    };
}

/**
 * A person may decide while the AI does other work: what it said earlier about a request may no longer be true.
 */
function check_before_saying_waiting(string $status): string
{
    return (
        'Before telling the user that a call of yours is still waiting, check with '
        . $status
        . ' without request_id: it lists your requests still open, and a person may have decided in the meantime. '
        . 'While a request waits, your later calls that change the site join it and wait for the same decision, so if '
        . 'one of them runs, the earlier request has been decided. A person approves or rejects the calls that wait '
        . 'together, never one by one: do not ask the user to approve some and reject others.'
    );
}

/** What the AI does while a person has not decided. It does not ask the user to tell it: it notices by itself. */
function waiting_step(): string
{
    return 'Nothing has executed. Do not resend the original call while it waits. Give the user dashboard_url so they can '
    . 'decide.'
    . first_approval_hint()
    . ' If you can run shell commands, run wait_command with the longest timeout your tool allows, and run it '
    . 'again if it is cut off or prints nothing. It keeps a connection open, uses no tokens while it waits, and ends '
    . 'as soon as a person has decided, or after '
    . (WAIT_LIMIT_SECONDS / 60)
    . ' minutes: do not ask the user to tell you, and follow the next_step it prints. Without a shell, call '
    . STATUS_TOOL
    . ' with request_id and wait_seconds '
    . WAIT_LONGEST_POLL_SECONDS
    . ', at most '
    . POLL_LIMIT
    . ' times; if it is still pending after that, tell the user what is waiting and end your turn. Approving runs '
    . 'the call and keeps its result, so when they write to you, read it with request_id. If you reconnect, the '
    . 'request is still there. '
    . decision_rule()
    . ' '
    . check_before_saying_waiting(STATUS_TOOL);
}

/**
 * Hold a status call until a person decides, or for at most the given seconds. A held call keeps a PHP worker busy, so
 * only clients with no shell are told to ask for it.
 *
 * @param array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int} $record
 * @return array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int}
 */
function await_decision(array $record, int $seconds): array
{
    $until = time() + min(max(0, $seconds), WAIT_LONGEST_POLL_SECONDS);
    while ($record['state'] === 'pending_confirmation' && time() < $until) {
        sleep(2);
        // The decision is written by another request: the copy cached in this one would never change.
        wp_cache_delete(REQUEST_PREFIX . $record['id'], group: 'options');
        $fresh = get_request($record['id']);
        if ($fresh === null) {
            return $record;
        }
        $record = refresh_record($fresh);
    }
    return $record;
}

/** Set on a person once the AI has been told to explain approvals to them. */
const FIRST_APPROVAL_META = 'novamira_first_approval_told';

/**
 * The first time a person's AI meets a waiting request, it is told to explain approvals in plain words; after that,
 * nothing, so the explanation comes once.
 */
function first_approval_hint(): string
{
    $user = get_current_user_id();
    if ($user === 0 || get_user_meta($user, FIRST_APPROVAL_META, single: true) !== '') {
        return '';
    }
    update_user_meta($user, FIRST_APPROVAL_META, (string) time());
    return (
        ' This is the first time this person meets an approval: tell them in plain words, "Novamira v2 asks you to '
        . 'approve important actions before they run. This one is waiting for you at the link. You can change how often '
        . 'it asks in AI Activity."'
    );
}

/** Who decides on approvals, as the AI is told wherever a call can wait. */
function decision_rule(): string
{
    return (
        'Only a person decides on approvals. Never approve or reject a request on your own initiative, with browser '
        . 'tools or otherwise. If the person wants fewer questions, tell them they can choose a lower level in AI Activity, '
        . 'or set an ability to Never ask in the Abilities Hub.'
    );
}
