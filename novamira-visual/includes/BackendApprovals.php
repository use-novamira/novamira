<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Visual\BackendTools;

use Novamira\Confirmations;
use Novamira\Journal;
use Novamira\Secrets;

if (!defined('ABSPATH')) {
    exit();
}

/*
 * Backend tool calls from Novamira Visual follow the same approvals as MCP and Novamira Chat: the model's attention
 * and Novamira's checks decide whether the person in front of Visual is asked, a skipped approval asks nothing, and
 * everything that runs is recorded in the AI change history. The decision is checked here, not in the browser: a call
 * that waits runs only through the decision endpoint, and only while the checks still see what the card showed.
 */

\add_action('wp_ajax_novamira_visual_safe_backend_tools_decide', __NAMESPACE__ . '\ajax_decide');

const APPROVAL_PREFIX = 'novamira_visual_approval_';

const APPROVAL_TTL = 3600;

/**
 * The nonce a decision carries: printed only in the workspace page, not in the pages it shows, where WordPress prints
 * the REST nonce.
 */
const DECISION_NONCE_ACTION = 'novamira_visual_decision';

/**
 * The model's attention for a call, 0-10, or null when it sent none or an invalid one.
 *
 * @param array<string, mixed> $params
 */
function attention_param(array $params): ?int
{
    // @mago-expect analysis:mixed-assignment -- The model's value is checked below.
    $attention = $params['attention'] ?? null;
    if (is_string($attention) && ctype_digit($attention)) {
        $attention = (int) $attention;
    }

    return is_int($attention) && $attention >= 0 && $attention <= 10 ? $attention : null;
}

/** Who decides whether a call waits: "novamira" (approvals on), "nobody" (skipped), "chat" (read mode). */
function approval_mode(): string
{
    return function_exists('Novamira\\Confirmations\\chat_approval_mode')
        ? Confirmations\chat_approval_mode()
        : 'nobody';
}

/**
 * What the approvals say about a call: null when it runs now, an error when it is refused, or a request waiting for
 * the person in front of Visual.
 *
 * @param array<string, mixed> $args
 * @return array<string, mixed>|\WP_Error|null
 */
function approval_gate(string $name, array $args, ?int $attention, string $says = ''): array|\WP_Error|null
{
    if (approval_mode() !== 'novamira') {
        return null;
    }

    $check = Confirmations\chat_call_check($name, $args, $attention);
    if ($check['refused'] !== '') {
        return refused_error($check['refused']);
    }
    if (!$check['must_ask']) {
        return null;
    }

    $id = bin2hex(random_bytes(16));
    \set_transient(
        APPROVAL_PREFIX . $id,
        [
            'user' => \get_current_user_id(),
            'name' => $name,
            'args' => $args,
            'attention' => $attention,
            'says' => $says,
            'fingerprint' => $check['fingerprint'],
        ],
        APPROVAL_TTL,
    );

    return [
        'name' => $name,
        'status' => 'pending_approval',
        'approval_id' => $id,
        'why' => $check['why'],
        'code' => readable_code($name, $args),
    ];
}

/**
 * The PHP of an execute-php call as the card shows it, laid out again when it was sent on one line; empty for other
 * calls. What runs is always the code as sent.
 *
 * @param array<string, mixed> $args
 */
function readable_code(string $name, array $args): string
{
    $code = is_string($args['code'] ?? null) ? $args['code'] : '';
    if ($name !== 'novamira/execute-php' || $code === '') {
        return '';
    }

    return function_exists('Novamira\\Confirmations\\readable_code') ? Confirmations\readable_code($code) : $code;
}

function refused_error(string $message): \WP_Error
{
    return new \WP_Error('novamira_visual_backend_tool_refused', $message, ['status' => 409]);
}

function ajax_decide(): void
{
    ajax_check_permission();
    $params = json_request_params();
    $nonce = is_string($params['decision_nonce'] ?? null) ? $params['decision_nonce'] : '';
    if (\wp_verify_nonce($nonce, DECISION_NONCE_ACTION) === false) {
        \wp_send_json_error([
            'message' => __('Reload Novamira Visual and decide again.', domain: 'novamira'),
        ], status_code: 403);
    }
    ajax_send_result(decide_data($params));
}

/**
 * The person's decision on a waiting call: a denial drops it, an approval runs it. Each request is decided once.
 *
 * @param array<string, mixed> $params
 * @return array<string, mixed>|\WP_Error
 */
function decide_data(array $params): array|\WP_Error
{
    $id = is_string($params['approval_id'] ?? null) ? $params['approval_id'] : '';
    // @mago-expect analysis:mixed-assignment -- The stored request is checked below.
    $request = preg_match('/^[0-9a-f]{32}$/', $id) === 1 ? \get_transient(APPROVAL_PREFIX . $id) : false;
    // Deleting is what claims the decision: of two clicks, only one finds the row.
    if (
        !is_array($request)
        || ($request['user'] ?? 0) !== \get_current_user_id()
        || !\delete_transient(APPROVAL_PREFIX . $id)
    ) {
        return new \WP_Error(
            'novamira_visual_approval_unknown',
            __('This request is no longer waiting for a decision.', domain: 'novamira'),
            ['status' => 404],
        );
    }
    if (($params['decision'] ?? '') !== 'approve') {
        return ['status' => 'denied'];
    }

    return run_approved($request);
}

/**
 * Run an approved call, unless what the checks see changed since the card was shown.
 *
 * @param array<array-key, mixed> $request
 * @return array<string, mixed>|\WP_Error
 */
function run_approved(array $request): array|\WP_Error
{
    $refusal = read_mode_refusal();
    if ($refusal !== null) {
        return $refusal;
    }

    $name = is_string($request['name'] ?? null) ? $request['name'] : '';
    /** @var array<string, mixed> $args */
    $args = is_array($request['args'] ?? null) ? $request['args'] : [];
    $attention = is_int($request['attention'] ?? null) ? $request['attention'] : null;
    $says = is_string($request['says'] ?? null) ? $request['says'] : '';
    $ability = get_backend_tool($name);
    if (is_wp_error($ability)) {
        return $ability;
    }

    if (approval_mode() === 'novamira') {
        $check = Confirmations\chat_call_check($name, $args, $attention);
        if ($check['refused'] !== '') {
            return refused_error($check['refused']);
        }
        if ($check['must_ask'] && $check['fingerprint'] !== ($request['fingerprint'] ?? '')) {
            return refused_error(__(
                'What this call would change is no longer what was approved. Nothing ran: send the call again.',
                domain: 'novamira',
            ));
        }
        // The addresses this call sends to are approved from now on, as on the AI Activity page.
        if (\function_exists('Novamira\\Confirmations\\remember_destinations_of')) {
            Confirmations\remember_destinations_of($name, $args);
        }
    }

    // The change history says who approved it and what the AI said it would do, as for calls approved in AI Activity.
    Confirmations\RunningApproval::$current = ['by' => \get_current_user_id(), 'says' => $says];
    try {
        return run_backend_tool($ability, $name, $args, $says);
    } finally {
        Confirmations\RunningApproval::$current = null;
    }
}

/**
 * Run a backend tool, recorded in the AI change history like an MCP call, its result without the site's secrets.
 *
 * @param array<string, mixed> $args
 * @return array<string, mixed>|\WP_Error
 */
function run_backend_tool(\WP_Ability $ability, string $name, array $args, string $says = ''): array|\WP_Error
{
    $run = static fn(): mixed => $ability->execute($args);
    // @mago-expect analysis:mixed-assignment -- Whatever the ability returns.
    $result = function_exists('Novamira\\Journal\\run_direct_call') ? recorded_run($name, $args, $run, $says) : $run();
    // What reaches the agent leaves the site's secrets out, as MCP and Chat results do.
    if (function_exists('Novamira\\Secrets\\redact_tool_result')) {
        // @mago-expect analysis:mixed-assignment -- The same result, its secrets replaced.
        $result = Secrets\redact_tool_result($result);
    }
    if (is_wp_error($result)) {
        return $result;
    }

    return [
        'name' => $name,
        'result' => $result,
    ];
}

/**
 * @param array<string, mixed> $args
 */
function recorded_run(string $name, array $args, callable $run, string $says = ''): mixed
{
    // Visual's calls are one session in the AI change history, grouped by the pauses between them.
    Confirmations\session_id(session: 'visual');

    return Journal\run_direct_call($name, $args, message_id: '', run: $run, says: $says);
}

/**
 * The words of the approval card, taken from AI Activity so both cards say the same in every language.
 *
 * @return array<string, string>
 */
function card_strings(): array
{
    $strings = function_exists('Novamira\\Confirmations\\script_strings') ? Confirmations\script_strings() : [];
    $words = ['says', 'whyAsks', 'aiAsks', 'showCode', 'approveRun', 'reject', 'rejectNote'];
    $shared = array_filter(
        array_intersect_key($strings, array_flip($words)),
        static fn(mixed $value): bool => is_string($value),
    );
    return [
        ...$shared,
        'heading' => \__('Approval needed', domain: 'novamira'),
        'showAction' => \__('Show the action', domain: 'novamira'),
    ];
}

/**
 * Who decides whether a Visual action waits, and the attention from which it asks, for the page actions the workspace
 * checks itself. Backend tools are checked by the site.
 *
 * With them, what the site's administrator wants to approve (Also ask before) and the abilities that always ask, which
 * the AI reads with the scale as it does through MCP and Novamira Chat.
 *
 * @return array{mode: string, cutoff: int, instructions: string}
 */
function workspace_approvals(): array
{
    $mode = approval_mode();
    return [
        'mode' => $mode,
        'cutoff' => function_exists('Novamira\\Confirmations\\settings') ? Confirmations\settings()['cutoff'] : 10,
        'instructions' => $mode === 'novamira' && function_exists('Novamira\\Confirmations\\site_approval_instructions')
            ? Confirmations\site_approval_instructions()
            : '',
    ];
}
