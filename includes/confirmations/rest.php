<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * AI approvals and the AI change history for direct REST runs of abilities: Novamira's runner, which Novamira CLI uses,
 * and WordPress core's Abilities API. The same rules as MCP, asked in the same page.
 */

namespace Novamira\Confirmations;

use Novamira\Vendor\WP\McpSchema\Server\Tools\DTO\CallToolResult;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit();
}

const REST_TOOL = 'rest-run-ability';

const REST_STATUS_ABILITY = 'novamira/confirmation-status';

/**
 * The ability a run route names, or an empty string when the route is something else. WordPress matches routes ignoring
 * case, so this does too. The name stays as written: no ability has capitals, so one with them is answered as not found.
 */
function run_route_ability(string $route): string
{
    $parts = [];
    $matched = preg_match(
        '#^/(?:novamira/v1|wp-abilities/v1)/abilities/([a-z0-9-]+(?:/[a-z0-9-]+)+)/run$#i',
        $route,
        $parts,
    );
    return $matched === 1 ? $parts[1] : '';
}

/**
 * What the call sends, from where the route's handler reads it: the check must judge the input that will run.
 *
 * @return array<array-key, mixed> The query on GET and DELETE, otherwise the JSON body, or nothing when it is not an object.
 */
function run_route_fields(WP_REST_Request $request): array
{
    if (in_array($request->get_method(), ['GET', 'DELETE'], strict: true)) {
        return $request->get_query_params();
    }
    // WordPress gives null for a body that is not JSON, whatever its stubs say.
    /** @var mixed $json */
    $json = $request->get_json_params();
    return is_array($json) ? $json : [];
}

/**
 * A review sent inside the input, as Novamira CLI can, moves out of it before WordPress checks the input against the
 * ability's schema: the ability never receives it.
 *
 * @param mixed $result
 * @return mixed
 */
function lift_rest_review($result, \WP_REST_Server $server, WP_REST_Request $request)
{
    unset($server);
    if (
        $result !== null
        || run_route_ability($request->get_route()) === ''
        || in_array($request->get_method(), ['GET', 'DELETE'], strict: true)
    ) {
        return $result;
    }
    $body = run_route_fields($request);
    // @mago-expect analysis:mixed-assignment -- The body is untyped until checked below.
    $input = $body['input'] ?? null;
    if (!is_array($input) || !array_key_exists(REVIEW_IN_PARAMETERS, $input)) {
        return $result;
    }
    $body['review'] ??= $input[REVIEW_IN_PARAMETERS];
    // The code of the user's request travels in the review too: Novamira CLI sends nothing beside the input.
    if (is_array($body['review']) && is_string($body['review']['request_id'] ?? null)) {
        $body['request_id'] ??= $body['review']['request_id'];
    }
    unset($input[REVIEW_IN_PARAMETERS]);
    $body['input'] = $input === [] ? null : $input;
    // The handler and the check both read the JSON body: rewriting it is what they see.
    $request->set_body((string) wp_json_encode($body));
    return $result;
}

/**
 * Decide a direct run before it starts, then let it run with its changes recorded.
 *
 * @param mixed $result
 * @param string $route
 * @param array<string, mixed> $handler
 * @return mixed
 */
function dispatch_ability_run($result, WP_REST_Request $request, $route, array $handler)
{
    $ability = run_route_ability($request->get_route());
    if ($result !== null || $ability === '' || novamira_is_read_mode() || !run_is_visible($ability)) {
        return $result;
    }
    $fields = run_route_fields($request);
    /** @var mixed $input */
    $input = $fields['input'] ?? null;
    $stopped = gate_rest_run($ability, $input, $fields['review'] ?? null, $fields['request_id'] ?? null);
    if ($stopped !== null) {
        return answers_as_result() ? stopped_result($stopped) : $stopped;
    }
    return record_rest_run($ability, $input, $fields['request_id'] ?? null, $handler['callback'] ?? null, $request);
}

/**
 * Let the route's own handler run with its changes recorded. Null lets WordPress call it unrecorded.
 *
 * @param mixed $input
 * @param mixed $request_id
 * @param mixed $callback
 * @return mixed
 */
function record_rest_run(string $ability, $input, $request_id, $callback, WP_REST_Request $request)
{
    if (
        !is_callable($callback)
        || !function_exists('Novamira\\Journal\\run_direct_call')
        || !\Novamira\Journal\applies_to($ability)
        || \Novamira\Journal\is_recording()
    ) {
        return null;
    }
    /** @var array<string, mixed> $arguments */
    $arguments = is_array($input) ? $input : [];
    /** @var mixed $review */
    $review = $request->get_param('review');
    $inside = is_array($arguments['novamira_review'] ?? null) ? $arguments['novamira_review'] : [];
    $words = is_array($review) ? $review : $inside;
    /** @var mixed $ran */
    $ran = \Novamira\Journal\run_direct_call(
        $ability,
        $arguments,
        is_string($request_id) ? $request_id : '',
        static fn(): mixed => $callback($request),
        says: is_string($words['summary'] ?? null) ? $words['summary'] : '',
    );
    // A null here would make WordPress call the handler again.
    return $ran ?? new WP_REST_Response(null);
}

/**
 * Whether a call that did not run is answered as a result instead of an error: for clients signed in with Novamira's
 * OAuth, which is how Novamira CLI and the apps built on it connect. Those apps pass the AI a result but replace an
 * error with their own text, so an error would never tell the AI to send a review or to wait for a person.
 */
function answers_as_result(): bool
{
    return (
        function_exists('Novamira\\OAuth\\Middleware\\request_oauth_identity')
        && \Novamira\OAuth\Middleware\request_oauth_identity() !== null
    );
}

/** A call that did not run, as a result that says so: what the error said, with nothing executed. */
function stopped_result(WP_Error $stopped): WP_REST_Response
{
    /** @var mixed $data */
    $data = $stopped->get_error_data();
    $fields = is_array($data) ? $data : [];
    unset($fields['status']);
    return new WP_REST_Response([
        'novamira_confirmation' => array_merge([
            'executed' => false,
            'code' => $stopped->get_error_code(),
            'message' => $stopped->get_error_message(),
        ], $fields),
    ]);
}

/** Only an existing ability open to REST: anything else is answered by the route itself. */
function run_is_visible(string $ability): bool
{
    if (!function_exists('wp_has_ability') || !wp_has_ability($ability)) {
        return false;
    }
    $registered = wp_get_ability($ability);
    return $registered instanceof \WP_Ability && $registered->get_meta_item('show_in_rest', false) === true;
}

/**
 * The approval rules for one direct run. Null lets it run. While the connection has a decision waiting, the run joins
 * it instead.
 *
 * @param mixed $input
 * @param mixed $review
 * @param mixed $request_id The AI's code for the user's request, kept so that an approved call joins its other calls.
 */
function gate_rest_run(string $ability, $input, $review, $request_id = null): ?WP_Error
{
    if (!active()) {
        return null;
    }
    $policy = call_policy($ability, $input);
    if ($policy['confirm'] === 'never') {
        return unrated_summary_error($ability, $input, $review, $policy);
    }
    $invalid = invalid_input_text($ability, $input);
    if ($invalid !== '') {
        return new WP_Error('novamira_invalid_input', $invalid, ['status' => 400]);
    }
    $args = ['ability_name' => $ability, 'parameters' => $input, 'review' => $review];
    if (is_string($request_id) && $request_id !== '') {
        $args['request_id'] = $request_id;
    }
    $block = live_block(rest_scope());
    $answer = block_answer($block, REST_TOOL, strip_review($args), rest_owner());
    if (is_array($answer)) {
        return rest_pending_error($answer);
    }
    if (is_string($answer)) {
        return new WP_Error('novamira_confirmation_waiting', $answer, ['status' => 409]);
    }
    if ($policy['confirm'] === 'unchecked') {
        $unrated = unrated_summary_error($ability, $input, $review, $policy);
        return $unrated ?? ($block === null ? null : join_small_rest($args, call_review($args, $policy, cutoff: 0)));
    }
    return gate_rest_checked($ability, $args, $policy, $block !== null);
}

/**
 * The score, the code check, then a run (null) or a request for a person.
 *
 * @param array<array-key, mixed> $args
 * @param array{score: bool, confirm: string, check: string, floor: int} $policy
 */
// @mago-expect lint:no-boolean-flag-parameter -- Whether a decision of this connection waits, which a small run joins.
function gate_rest_checked(string $ability, array $args, array $policy, bool $joins): ?WP_Error
{
    // A call that asks a person anyway needs no new rating.
    $unread = $policy['score'] && $policy['confirm'] !== 'always' ? rules_refusal($args) : '';
    if ($unread !== '') {
        return new WP_Error('novamira_confirmation_rules_changed', $unread, ['status' => 400]);
    }
    $cutoff = settings()['cutoff'];
    $reviewed = call_review($args, $policy, $cutoff);
    if (is_wp_error($reviewed)) {
        return new WP_Error(
            'novamira_confirmation_review_required',
            rest_review_error($policy, $args['review'], $reviewed),
            ['status' => 400],
        );
    }
    $checked = check_code($args, $reviewed, $policy);
    if ($checked instanceof CallToolResult) {
        return new WP_Error('novamira_confirmation_refused', rest_call_text($checked), ['status' => 403]);
    }
    // As over MCP: a call after which the AI may lose this site, or that moves money, always asks.
    ['access' => $access, 'always' => $always] = always_asks($policy, $checked['analysis']);
    $queued = $always || requires_confirmation($checked['review']['attention'], $cutoff);
    log_score($args, $reviewed['attention'], $queued, $checked['analysis']);
    if (!$queued) {
        return $joins ? join_small_rest($args, $checked['review']) : null;
    }
    $asked = with_measured_writes($args, asked_review($ability, $policy['confirm'], $checked));
    if ($access !== []) {
        $asked['access'] = $access;
    }
    $stored = store_request(REST_TOOL, strip_review($args), $asked, rest_origin());
    if (is_wp_error($stored)) {
        return new WP_Error('novamira_confirmation_not_queued', $stored->get_error_message(), ['status' => 409]);
    }
    return rest_pending_error($stored);
}

/**
 * A run that would not ask on its own waits with the decision of its connection. If that decision was taken
 * meanwhile, it runs as usual.
 *
 * @param array<array-key, mixed> $args
 * @param array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}|WP_Error $review
 */
function join_small_rest(array $args, array|WP_Error $review): ?WP_Error
{
    if (is_wp_error($review)) {
        return null;
    }
    $small = with_measured_writes($args, array_merge($review, ['joined_small' => true]));
    $stored = store_request(REST_TOOL, strip_review($args), $small, rest_origin(), join_only: true);
    if (is_wp_error($stored)) {
        return (
            $stored->get_error_code() === BLOCK_GONE
                ? null
                : new WP_Error('novamira_confirmation_not_queued', $stored->get_error_message(), ['status' => 409])
        );
    }
    return rest_pending_error($stored);
}

/**
 * A run that waits for a person, as the error the client receives.
 *
 * @param array{id: string, state: string, ...} $stored
 */
function rest_pending_error(array $stored): WP_Error
{
    return new WP_Error('novamira_confirmation_pending', rest_waiting_text($stored['id'], $stored['state']), [
        'status' => 409,
        'request_id' => $stored['id'],
        'state' => $stored['state'],
        ...waiting_fields($stored['id']),
    ]);
}

/** The text of a refusal built for MCP. */
function rest_call_text(CallToolResult $result): string
{
    /** @var array{structuredContent?: array{error?: mixed}} $wire */
    $wire = $result->toArray();
    /** @var mixed $error */
    $error = $wire['structuredContent']['error'] ?? null;
    return is_string($error) ? $error : 'This call was refused. Nothing has executed.';
}

/**
 * Who sends a direct run, and from which connection.
 *
 * @return array{caller: string, session: string, scope: string}
 */
function rest_origin(): array
{
    return ['caller' => rest_owner(), 'session' => 'rest', 'scope' => rest_scope()];
}

/** REST requests belong to the WordPress user on this site, whichever client sends them. */
function rest_owner(): string
{
    return hash_hmac('sha256', REST_TOOL . ':' . get_current_user_id(), wp_salt('auth'));
}

/**
 * A call that never asks runs at once. A read needs nothing; another plugin's ability is not rated, but still says what
 * it changes, for the history.
 *
 * @param mixed $input
 * @param mixed $review
 * @param array{score: bool, confirm: 'never'|'unchecked'|'above'|'always', check: string, floor: int} $policy
 */
function unrated_summary_error(string $ability, $input, $review, array $policy): ?WP_Error
{
    $args = ['ability_name' => $ability, 'parameters' => $input, 'review' => $review];
    return (
        is_wp_error(call_review($args, $policy, cutoff: 0))
            ? new WP_Error('novamira_confirmation_review_required', rest_review_help(), ['status' => 400])
            : null
    );
}

/**
 * A call with no review is told how to send one; a review that came with a problem is told the problem.
 *
 * @param array{score: bool, confirm: string, check: string, floor: int} $policy
 * @param mixed $review
 */
function rest_review_error(array $policy, $review, WP_Error $error): string
{
    if ($review === null) {
        return $policy['score'] ? rest_scored_review_help() : rest_review_help();
    }
    return $error->get_error_message();
}

function rest_review_help(): string
{
    return (
        'This call changes the site and needs a summary: one plain sentence saying what it changes and naming what it '
        . 'touches. Send {"input":...,"review":{"summary":"..."}}, or put it inside the input as "novamira_review": '
        . '{"summary":"..."}, which also works through Novamira CLI. Nothing has executed.'
    );
}

function rest_scored_review_help(): string
{
    return (
        'This call needs an attention level and a one-sentence summary of what it does. Attention is how much a '
        . 'person should look at this call before it runs, from 0 to 10: 0 reads; 1-3 small changes that can be '
        . 'restored; 4-5 published content or several records; 6-7 broad changes or code that stays on the site; 8-9 '
        . 'what cannot be undone, access, payments, anything sent outside the site; 10 '
        . 'the largest changes that cannot be undone: what would take the whole site down or lose most of its content, or change the structure of every link (permalinks). Count the effect on the site, not the number of rows. If you do not know what it will do, use a high value. '
        . trim(site_approval_instructions() . ' ' . rules_code_line() . ' ')
        . ' Send {"input":...,"review":{"attention":<0-10>,"summary":"..."}}, or put it inside the input as '
        . '"novamira_review": {"attention":<0-10>,"summary":"..."}, which also works through Novamira CLI. Nothing has '
        . 'executed.'
    );
}

function rest_waiting_text(string $id, string $state): string
{
    $poll = 'novamira/confirmation-status with {"request_id":"' . $id . '"}';
    if ($state === 'approved' || $state === 'executing') {
        return "This call was already approved. Get its result by running {$poll}. Do not resend the call.";
    }
    $record = get_request($id);
    return (
        'Waiting for a person to approve this call. Nothing has executed. Tell the user what is waiting and that they decide at '
        . request_url($id)
        . '.'
        . first_approval_hint()
        . ' Then run this shell command, which uses no tokens and ends when they have decided, so do not ask them to tell you, and follow the next_step it prints: '
        . wait_command($id, $record['created'] ?? time())
        . " If you cannot run shell commands, or the command does not work, run {$poll} about once a minute instead: "
        . 'after 30 minutes, tell the user what is waiting and end your turn.'
        . " Approving runs the call once and keeps its result: read it by running {$poll}. "
        . 'Do not resend the original call while it waits. '
        . decision_rule()
        . ' '
        . check_before_saying_waiting(REST_STATUS_ABILITY)
    );
}

function register_rest_status_ability(): void
{
    wp_register_ability(REST_STATUS_ABILITY, [
        'label' => __('AI Approval Status', domain: 'novamira'),
        'description' => __(
            'For clients that call abilities over REST. With request_id, says whether a person has decided a call waiting for approval; an approved call runs once here and its result is returned. Without it, lists the requests still open.',
            domain: 'novamira',
        ),
        'category' => 'context',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'request_id' => ['type' => 'string'],
            ],
            'additionalProperties' => false,
            'default' => [],
        ],
        'execute_callback' => __NAMESPACE__ . '\\rest_status',
        'permission_callback' => 'novamira_permission_callback',
        'meta' => [
            'show_in_rest' => true,
            'mcp' => ['public' => false],
            'annotations' => [
                'instructions' => 'A request that was approved runs the first time you call this, so call it only to get the result.',
                'readonly' => false,
                'destructive' => false,
                'idempotent' => true,
            ],
        ],
    ]);
}

/**
 * @param mixed $input
 * @return array<string, mixed>|WP_Error
 */
function rest_status($input = null): array|WP_Error
{
    /** @var mixed $id */
    $id = is_array($input) ? $input['request_id'] ?? null : null;
    if ($id === null) {
        return array_merge(runtime(), ['open_requests' => open_requests_of(rest_owner())]);
    }
    if (!is_string($id) || !is_request_id($id)) {
        return new WP_Error('invalid_request_id', 'Invalid confirmation request ID.', ['status' => 400]);
    }
    $record = get_request($id);
    if ($record === null || !hash_equals($record['caller'], rest_owner())) {
        return new WP_Error('request_not_found', 'Confirmation request not found for this WordPress user.', [
            'status' => 404,
        ]);
    }
    $record = run_rest_if_approved(refresh_record($record));
    return array_merge(public_record($record), [
        'runtime' => runtime(),
        'next_step' => $record['state'] === 'pending_confirmation'
            ? rest_waiting_text($id, $record['state'])
            : polled_next_step($record),
        'tool_result' => $record['result'] ?? null,
        'warning' => $record['state'] === 'execution_unknown'
            ? 'Execution was interrupted. Some changes may already have happened; inspect the site, do not automatically retry.'
            : null,
    ]);
}

/**
 * An approved request runs the first time its status is asked for, once.
 *
 * @param array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int} $record
 * @return array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int}
 */
function run_rest_if_approved(array $record): array
{
    if ($record['state'] !== 'approved') {
        return $record;
    }
    $lead = $record['block'] ?? $record['id'];
    if (in_block_of_many($lead)) {
        // The runs approved with it go first, in the order they were sent.
        drive_block($lead, until: $record['id']);
        wp_cache_delete(REQUEST_PREFIX . $record['id'], group: 'options');
        return refresh_record(get_request($record['id']) ?? $record);
    }
    $claimed = array_merge($record, ['state' => 'executing', 'started' => time()]);
    if (transition($record, $claimed)) {
        execute_claimed($claimed, static fn(): array => run_stored_rest_call($claimed['arguments']));
    }
    return refresh_record(get_request($record['id']) ?? $record);
}

/**
 * Run the call a person approved, its changes recorded.
 *
 * @param array<array-key, mixed> $arguments
 * @return array<string, mixed>
 */
function run_stored_rest_call(array $arguments): array
{
    $name = is_string($arguments['ability_name'] ?? null) ? $arguments['ability_name'] : '';
    /** @var mixed $input */
    $input = $arguments['parameters'] ?? null;
    $ability = $name !== '' && wp_has_ability($name) ? wp_get_ability($name) : null;
    if (!$ability instanceof \WP_Ability) {
        return error_response('Ability not found: ' . $name)->toArray();
    }
    /** @var array<string, mixed> $arguments_of_call */
    $arguments_of_call = is_array($input) ? $input : [];
    $run = static fn(): mixed => $ability->execute($input);
    /** @var mixed $result */
    $result = function_exists('Novamira\\Journal\\run_direct_call')
        ? \Novamira\Journal\run_direct_call(
            $name,
            $arguments_of_call,
            message_id: is_string($arguments['request_id'] ?? null) ? $arguments['request_id'] : '',
            run: $run,
        )
        : $run();
    if (is_wp_error($result)) {
        return error_response($result->get_error_message())->toArray();
    }
    return response(is_array($result) ? $result : ['value' => $result])->toArray();
}
