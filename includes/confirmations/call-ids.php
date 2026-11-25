<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Retries after a timeout must not run the same code twice.
 *
 * An MCP client that times out, or loses the connection, cannot tell whether its call ran, and the AI often
 * sends it again. While approvals are on, a call below the cutoff may carry review.call_id, a fresh id for each
 * intended action. The first call with that id, from the same user, runs; sending the identical call again
 * with the same id returns the first call's stored result (or tells the AI it is still running) instead of
 * running it again. The id is claimed with an atomic insert, so two copies arriving together cannot both run.
 */

namespace Novamira\Confirmations;

use Novamira\Vendor\WP\MCP\Core\McpServer;
use Novamira\Vendor\WP\McpSchema\Server\Tools\DTO\CallToolResult;

if (!defined('ABSPATH')) {
    exit();
}

const CALL_PREFIX = 'novamira_confirmation_call_';

/** A call id is remembered this long. */
const CALL_TTL = 86_400;

/** Results larger than this are not stored; a retry is told to inspect the site instead. */
const CALL_RESULT_LIMIT = 262_144;

add_filter('novamira_mcp_adapter_tool_call_result', __NAMESPACE__ . '\\finish_call', PHP_INT_MAX, accepted_args: 3);

/**
 * The call id of a review object: '' when there is none, null when it is malformed.
 */
function call_id_of(mixed $review): ?string
{
    if (!is_array($review) || !array_key_exists('call_id', $review)) {
        return '';
    }
    return is_string($review['call_id']) && preg_match('/^[A-Za-z0-9_-]{8,64}$/', $review['call_id']) === 1
        ? $review['call_id']
        : null;
}

/**
 * The option of the call being executed in this request, if it carries a call id.
 */
function &current_call(): string
{
    /** @var string $current */
    static $current = '';
    return $current;
}

/**
 * Who a call id belongs to: the user on this server. Neither the MCP session nor the credential is part of it, because
 * a retry is what follows a dropped connection, and the client may come back on a new session.
 */
function call_owner(McpServer $server): string
{
    return hash_hmac('sha256', $server->get_server_id() . ':' . get_current_user_id(), wp_salt('auth'));
}

function call_option(string $owner, string $call_id): string
{
    return CALL_PREFIX . hash('sha256', $owner . "\0" . $call_id);
}

/** @param array<array-key, mixed> $args */
function call_fingerprint(array $args): string
{
    return hash('sha256', serialize(strip_review($args)));
}

/**
 * Claim a call id before executing. Null lets the call run; a result answers a retry without running it.
 *
 * @param array<array-key, mixed> $args
 */
function begin_call(string $owner, string $call_id, array $args): ?CallToolResult
{
    $option = call_option($owner, $call_id);
    $record = ['state' => 'running', 'started' => time(), 'args' => call_fingerprint($args)];
    $wpdb = database();
    $insert = $wpdb->prepare(
        "INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
        $wpdb->options,
        $option,
        (string) wp_json_encode($record),
    );
    if (is_string($insert) && $wpdb->query($insert) === 1) {
        $current = &current_call();
        $current = $option;
        /** @var bool $registered */
        static $registered = false;
        if (!$registered) {
            $registered = true;
            register_shutdown_function(__NAMESPACE__ . '\\abandon_call');
        }
        return null;
    }
    return replay_call($option, $call_id, $record['args']);
}

/**
 * What a retry receives: the stored result, or why it cannot have one.
 */
function replay_call(string $option, string $call_id, string $fingerprint): CallToolResult
{
    $stored = stored_call($option);
    if ($stored === null) {
        return error_response(
            "Call id {$call_id} could not be checked. Nothing has executed; send the call again with a new call_id.",
        );
    }
    if (!hash_equals($stored['args'], $fingerprint)) {
        return error_response(
            "Call id {$call_id} was already used for a different call. Use a new call_id for each action. Nothing has executed.",
        );
    }
    if ($stored['state'] === 'running' && ($stored['started'] + execution_timeout()) >= time()) {
        return response([
            'state' => 'running',
            'call_id' => $call_id,
            'next_step' => 'The first call with this call_id is still running and nothing new has executed. Wait, then send the identical call again to get its result.',
        ]);
    }
    if ($stored['state'] === 'running' || $stored['state'] === 'execution_unknown') {
        return error_response(
            "The first call with call_id {$call_id} was interrupted and its outcome is unknown. Nothing new has executed. Inspect the site before trying again, with a new call_id.",
        );
    }
    return response([
        'state' => $stored['state'],
        'call_id' => $call_id,
        'replayed' => true,
        'result' => $stored['result'] ?? null,
    ], error: $stored['state'] === 'failed');
}

/**
 * @return array{state: string, started: int, args: string, result?: mixed}|null
 */
function stored_call(string $option): ?array
{
    $value = key_value($option);
    // @mago-expect analysis:mixed-assignment -- Stored JSON, checked field by field below.
    $decoded = is_string($value) ? json_decode($value, associative: true) : null;
    if (!is_array($decoded) || !is_string($decoded['state'] ?? null) || !is_string($decoded['args'] ?? null)) {
        return null;
    }
    return [
        'state' => $decoded['state'],
        'started' => is_int($decoded['started'] ?? null) ? $decoded['started'] : 0,
        'args' => $decoded['args'],
        'result' => $decoded['result'] ?? null,
    ];
}

function save_call(string $option, string $state, mixed $result): void
{
    $stored = stored_call($option);
    if ($stored === null) {
        return;
    }
    $encoded = wp_json_encode($result);
    // @mago-expect analysis:mixed-assignment -- Whatever the ability returned, as the client received it.
    $keep = is_string($encoded) && strlen($encoded) <= CALL_RESULT_LIMIT
        ? $result
        : 'The result was too large to keep. Inspect the site to see what this call did.';
    $wpdb = database();
    $update = $wpdb->prepare(
        'UPDATE %i SET option_value = %s WHERE option_name = %s',
        $wpdb->options,
        (string) wp_json_encode(array_merge($stored, ['state' => $state, 'result' => $keep])),
        $option,
    );
    if (is_string($update)) {
        $wpdb->query($update);
    }
}

/**
 * Store the result of the call that claimed its id. Runs after secrets are redacted from the result.
 *
 * @param mixed $result
 * @param mixed $args
 * @return mixed
 */
function finish_call($result, $args, string $tool_name)
{
    $current = &current_call();
    $option = $current;
    if ($option === '' || $tool_name !== 'mcp-adapter-execute-ability') {
        return $result;
    }
    $current = '';
    if (is_wp_error($result)) {
        save_call($option, state: 'failed', result: $result->get_error_message());
        return $result;
    }
    save_call($option, state: 'completed', result: $result);
    return $result;
}

/** A call that threw or ended the request never reached finish_call. */
function abandon_call(): void
{
    $current = &current_call();
    $option = $current;
    if ($option === '') {
        return;
    }
    $current = '';
    save_call(
        $option,
        state: error_get_last() !== null ? 'execution_unknown' : 'failed',
        result: 'The call ended without a result.',
    );
}

/** Forget call ids older than a day. */
function prune_calls(): void
{
    $wpdb = database();
    $query = $wpdb->prepare(
        'SELECT option_name FROM %i WHERE option_name LIKE %s',
        $wpdb->options,
        $wpdb->esc_like(CALL_PREFIX) . '%',
    );
    if (!is_string($query)) {
        return;
    }
    /** @var list<string> $names */
    $names = $wpdb->get_col($query);
    foreach ($names as $name) {
        $stored = stored_call($name);
        if ($stored === null || ($stored['started'] + CALL_TTL) < time()) {
            delete_option($name);
        }
    }
}
