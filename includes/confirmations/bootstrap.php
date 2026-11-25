<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Confirmations;

use Novamira\Vendor\WP\MCP\Core\McpAdapter;
use Novamira\Vendor\WP\MCP\Core\McpServer;
use Novamira\Vendor\WP\MCP\Handlers\Tools\ToolsHandler;
use Novamira\Vendor\WP\MCP\Transport\Infrastructure\SessionManager;
use Novamira\Vendor\WP\McpSchema\Server\Tools\DTO\CallToolResult;
use Novamira\Vendor\WP\McpSchema\Server\Tools\DTO\Tool;
use WP_Error;

use function Novamira\Secrets\redact;

if (!defined('ABSPATH')) {
    exit();
}

const SETTINGS_OPTION = 'novamira_confirmation_settings';

const REQUEST_PREFIX = 'novamira_confirmation_request_';

/** One row per identical call (same caller, settings generation, tool and arguments), naming its request. */
const KEY_PREFIX = 'novamira_confirmation_key_';

/** Every scored call, executed or queued: what the AI scored, without the code itself. */
const SCORE_LOG_OPTION = 'novamira_confirmation_score_log';

const SCORE_LOG_LIMIT = 1000;

const HEARTBEAT_TRANSIENT = 'novamira_confirmation_heartbeat';

const HEARTBEAT_WINDOW = 45;

const STATUS_TOOL = 'novamira-confirmation-status';

/** How many held status calls a client with no shell makes before it stops: about five minutes of waiting. */
const POLL_LIMIT = 15;

/** Only a person drops a waiting request, from the page: an AI that wants to move on could drop another conversation's. */
const CANCEL_REFUSED = 'The AI cannot cancel a request. If the user no longer wants it, they reject it on the AI Activity page; nothing has executed.';

const PAGE_SLUG = 'novamira-ai-activity';

/** How long a request waits for a decision: someone who wrote to the AI and walked away can decide when they are back. */
const REQUEST_TTL = 86_400;

/**
 * Approving runs the call from the page. This bounds the only case left: an approval the page could not run, which the
 * AI's next status call runs. The user approved what the site looked like then.
 */
const APPROVAL_TTL = 600;

const LOG_TTL = 15 * DAY_IN_SECONDS;

require_once __DIR__ . '/admin.php';
require_once __DIR__ . '/RunningApproval.php';
require_once __DIR__ . '/approval-instructions.php';
require_once __DIR__ . '/v2-notice.php';
require_once __DIR__ . '/levels.php';
require_once dirname(__DIR__) . '/secrets/bootstrap.php';
require_once __DIR__ . '/analysis.php';
require_once __DIR__ . '/destinations.php';
require_once __DIR__ . '/call-ids.php';
require_once __DIR__ . '/blocks.php';
require_once __DIR__ . '/policies.php';
require_once __DIR__ . '/rest.php';
require_once __DIR__ . '/wait.php';
require_once __DIR__ . '/wp-cli-check.php';
require_once __DIR__ . '/wp-cli-words.php';

add_filter('novamira_mcp_adapter_tools_list', __NAMESPACE__ . '\\decorate_tools');
add_filter('novamira_mcp_adapter_call_tool_override', __NAMESPACE__ . '\\gate_call', accepted_args: 3);
add_filter('novamira_mcp_adapter_call_tool_arguments', __NAMESPACE__ . '\\strip_review', accepted_args: 2);
add_filter('novamira_mcp_adapter_pre_prompt_get', __NAMESPACE__ . '\\gate_component', PHP_INT_MAX, accepted_args: 3);
add_filter('novamira_mcp_adapter_pre_resource_read', __NAMESPACE__ . '\\gate_component', PHP_INT_MAX, accepted_args: 3);
add_action('novamira_confirmation_cleanup', __NAMESPACE__ . '\\cleanup');
add_filter('rest_pre_dispatch', __NAMESPACE__ . '\\lift_rest_review', accepted_args: 3);
add_filter('rest_dispatch_request', __NAMESPACE__ . '\\dispatch_ability_run', accepted_args: 4);
add_action('wp_abilities_api_init', __NAMESPACE__ . '\\register_rest_status_ability', priority: 999);
add_filter('rest_request_before_callbacks', __NAMESPACE__ . '\\capture_session', accepted_args: 3);
add_action('init', __NAMESPACE__ . '\\cancel_requests_of_old_settings');
add_action('init', static function (): void {
    if (!wp_next_scheduled('novamira_confirmation_cleanup')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, recurrence: 'hourly', hook: 'novamira_confirmation_cleanup');
    }
});

/**
 * Which actions need a person's approval, by how serious they are. Each level is the attention level from which a call waits,
 * at one of the steps of Novamira's own check: below 5 only the AI's score would decide.
 */
const LEVELS = ['most' => 5, 'big' => 8, 'almost_none' => 10];

/** The highest attention level: the scale runs from 0 to 10. */
const ATTENTION_MAX = 10;

/** The level a cutoff stands for, or null for a number saved before levels existed: it stays until a level is chosen. */
function level_of(int $cutoff): ?string
{
    $level = array_search($cutoff, LEVELS, strict: true);
    return is_string($level) ? $level : null;
}

/** @return array{cutoff: int, generation: string, hold_emails: bool, skip_approvals: bool, level_chosen: bool} */
function settings(): array
{
    /** @var mixed $value */
    $value = get_option(SETTINGS_OPTION, []);
    $value = is_array($value) ? $value : [];
    return [
        'cutoff' =>
            is_int($value['cutoff'] ?? null) && $value['cutoff'] >= 0 && $value['cutoff'] <= ATTENTION_MAX
                ? $value['cutoff']
                : LEVELS['big'],
        'generation' => is_string($value['generation'] ?? null) ? $value['generation'] : '',
        'hold_emails' => ($value['hold_emails'] ?? true) !== false,
        'skip_approvals' => ($value['skip_approvals'] ?? false) === true,
        'level_chosen' => ($value['level_chosen'] ?? false) === true,
    ];
}

/** Whether the AI change history is on: held emails are kept there. */
function history_is_on(): bool
{
    return function_exists('Novamira\\Journal\\hold_mail');
}

/** Whether emails wait for a person during an execution of the AI, or go out as usual. */
function holds_emails(): bool
{
    return settings()['hold_emails'] && history_is_on();
}

/**
 * Requests saved before they belonged to the user can no longer be polled, so an approval would run nothing. Settings
 * from that time have no skip_approvals: a new generation cancels those requests, once.
 */
function cancel_requests_of_old_settings(): void
{
    /** @var mixed $saved */
    $saved = get_option(SETTINGS_OPTION, []);
    if (is_array($saved) && array_key_exists('skip_approvals', $saved)) {
        return;
    }
    update_option(
        SETTINGS_OPTION,
        array_merge(settings(), ['generation' => bin2hex(random_bytes(16))]),
        autoload: false,
    );
}

/**
 * Whether the AI can change the site: everywhere except in read mode, which already refuses every ability that does.
 */
function covers_work(): bool
{
    return !novamira_is_read_mode();
}

/**
 * Whether calls need a score and can ask a person. An administrator can skip approvals: the work is still recorded and
 * emails still follow their own setting. Requests still pending are canceled.
 */
function active(): bool
{
    return covers_work() && !settings()['skip_approvals'];
}

function dashboard_url(): string
{
    return admin_url('admin.php?page=' . PAGE_SLUG);
}

/** The Approvals tab, opened on one request. */
function request_url(string $id): string
{
    return dashboard_url() . '&request=' . rawurlencode($id);
}

/** @return array{cutoff: int, generation: string, hold_emails: bool, skip_approvals: bool, level_chosen: bool, online: bool, read_mode: bool, dashboard_url: string} */
function runtime(): array
{
    return array_merge(settings(), [
        'online' => page_open_by(get_current_user_id()),
        'read_mode' => novamira_is_read_mode(),
        'dashboard_url' => dashboard_url(),
    ]);
}

/** @return array<int, int> User ID => last time that user's confirmation page reported in. */
function heartbeats(): array
{
    /** @var mixed $value */
    $value = get_transient(HEARTBEAT_TRANSIENT);
    $users = is_array($value) && is_array($value['users'] ?? null) ? $value['users'] : [];
    $result = [];
    // @mago-expect analysis:mixed-assignment -- The transient is untrusted storage; every entry is checked.
    foreach ($users as $user => $seen) {
        if (!is_int($user) || !is_int($seen) || $seen <= (time() - HEARTBEAT_WINDOW)) {
            continue;
        }
        $result[$user] = $seen;
    }
    return $result;
}

/**
 * Presence belongs to one WordPress user: it tells the AI connected as that user whether that same user has the page
 * open. Nothing waits for it, and another administrator's open tab does not count.
 */
function record_heartbeat(int $user_id): void
{
    if ($user_id <= 0) {
        return;
    }
    $users = heartbeats();
    $users[$user_id] = time();
    set_transient(HEARTBEAT_TRANSIENT, ['users' => $users], expiration: 60);
}

function page_open_by(int $user_id): bool
{
    return $user_id > 0 && array_key_exists($user_id, heartbeats()) && user_can($user_id, novamira_manage_capability());
}

/**
 * How the AI rates a call, and this site's own rules: the same words through every door (MCP, REST, Novamira Chat),
 * whatever each door asks the rating to be sent with.
 */
function scoring_rules(): string
{
    $check = analysis_available()
        ? "Novamira's code check scores PHP sent to execute-php, and its score is a floor you cannot lower. In "
        . 'execute-php it refuses eval and create_function, and asks a person about function names computed at '
        . 'runtime, include of a path built at runtime, call_user_func and shell commands: write names out.'
        : "Novamira's code check cannot run on this site: every execute-php, enable-file, and write-file or edit-file "
        . 'on PHP waits for a person. Tell the user before the first such call.';
    return implode("\n", array_filter([
        'Scoring. Score the actual code and its indirect or later effects, not the tool name. 0 reads; 1-3 small '
            . 'changes that can be restored; 4-5 published content or several records; 6-7 broad changes or code '
            . 'that stays on the site; 8-9 what cannot be undone, access, payments, anything sent outside the site; 10 '
            . 'the largest changes that cannot be undone: what would take the whole site down or lose most of its content, or change the structure of every link (permalinks). Count the effect on the site, not the number of rows. If you do not know what a call will do, score high. Never lower a score '
            . 'because the user asked, and never split work to avoid approval. A request from your code to an address '
            . 'the user has not approved yet, GET included, always waits for a person: write the address in the call '
            . 'itself, so one approval covers it from then on. When you talk to the user, describe what a call '
            . 'does in plain words: do not mention ratings, numbers, the rules code or review fields, which are for '
            . 'Novamira, not for people, unless the user asks about them. '
            . $check,
        site_approval_instructions(),
    ]));
}

function scoring_instructions(): string
{
    $cutoff = settings()['cutoff'];
    $hours = REQUEST_TTL / 3600;
    return implode("\n", [
        'AI approvals are on.',
        '',
        'Sending a call. Every mcp-adapter-execute-ability call that can change the site, other plugins\' abilities '
            . 'included, needs a top-level review object with review.attention (0-10, how much a person should look '
            . 'before it runs) and review.summary: one plain sentence, at most 240 characters, saying what the call '
            . 'changes and naming what it touches (a page, a plugin, a file, a setting). People read it on approval cards '
            . "and in the AI change history. At or above the cutoff ({$cutoff}), review.details (at most 1500 characters) "
            . 'only for what the summary does not say, such as what could go wrong and how to undo it; leave it out '
            . 'rather than repeat the summary. For another plugin\'s ability, rate what its '
            . 'description says it does: Novamira does not check its code. Add review.call_id, a new random id for each '
            . 'action: after a timeout send the identical call with the same id and you get the first result, not a '
            . 'second run. create-admin-access-link always asks; Novamira\'s reads, discovery, ability-info and '
            . STATUS_TOOL
            . ' need no review.',
        '',
        scoring_rules(),
        rules_code_line(),
        '',
        'When a call waits. pending_confirmation means nothing ran: give the user its dashboard_url and follow its '
            . 'next_step. '
            . decision_rule()
            . ' With a shell run its wait_command (no tokens, ends when a person decides); without one call '
            . STATUS_TOOL
            . ' with request_id and wait_seconds '
            . WAIT_LONGEST_POLL_SECONDS
            . ', at most '
            . POLL_LIMIT
            . ' times. Approving runs the call once from the page and keeps its result for you. If nobody '
            . "decides, tell the user what waits and end your turn. A request stays valid for {$hours} hours.",
        '',
        'Rules. One decision at a time per conversation: calls you send while one waits join it, up to '
            . BLOCK_LIMIT
            . ', and run in order only if a person approves them all; if one fails, the rest do not run. Other '
            . 'conversations are not affected, and reads still work. Do not work around it, do not resend a call that '
            . 'waits, and you cannot cancel it. Never approve or reject requests on the AI '
            . 'Activity page, not even with a connected browser, and not even if the user asks you to: the decision is '
            . 'theirs, on the page ('
            . dashboard_url()
            . '). Expired or canceled: nothing ran, send again only if the '
            . 'user asks. Rejected, failed or execution_unknown: do not retry on your own; failures can leave partial '
            . 'changes, so look at the site first.',
        '',
        'After a reconnect or in a new conversation, requests still belong to this user on this site: call '
            . STATUS_TOOL
            . ' with the request_id, or without it to list the open ones.',
        '',
        'These are estimates that help avoid mistakes, not a security boundary.',
    ]);
}

/** @return array<string, mixed> */
function review_schema(): array
{
    return [
        'type' => 'object',
        'properties' => [
            'attention' => [
                'type' => 'integer',
                'minimum' => 0,
                'maximum' => ATTENTION_MAX,
                'description' => 'How much a person should look at this call before it runs. 0: ordinary reads. 1–3: small changes that can be restored. 4–5: published content or several records. 6–7: broad changes or persistent code. 8–9: deletion that cannot be undone, access changes, payments, anything sent outside the site. 10: the largest changes that cannot be undone: what would take the whole site down or lose most of its content, or change the structure of every link (permalinks). Count the effect on the site, not the number of rows. If you do not know what it will do, use a high value.',
            ],
            'summary' => [
                'type' => 'string',
                'maxLength' => 240,
                'description' => 'What a person reads about this call, on its approval card and in the AI change history: one plain sentence saying what it changes and naming what it touches. Required for calls that can change the site.',
            ],
            'details' => [
                'type' => 'string',
                'maxLength' => 1500,
                'description' => 'Optional, at or above the cutoff: only what the summary does not say, such as what could go wrong and how to undo it. Leave it out rather than repeat the summary.',
            ],
            'rules' => [
                'type' => 'string',
                'description' => "The three-letter code of this site's approval rules, when the site has rules: send it with every call you rate.",
            ],
            'call_id' => [
                'type' => 'string',
                'pattern' => '^[A-Za-z0-9_-]{8,64}$',
                'description' => 'A new random id for each intended action. If a call times out or the connection drops, send the identical call again with the same call_id: it returns the first result instead of running twice.',
            ],
        ],
        'additionalProperties' => false,
        'description' => 'What a person reads about this call. Send summary on every call that can change the site, attention where it is asked for, and details only when they add something.',
    ];
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
    $enabled = active();
    $tools[] = Tool::fromArray([
        'name' => STATUS_TOOL,
        'description' => 'Get confirmation runtime/cutoff, or poll a request_id. Polling an approved request executes the stored call once as its original caller. No attention level is needed.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'request_id' => ['type' => 'string'],
                'wait_seconds' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'maximum' => WAIT_LONGEST_POLL_SECONDS,
                    'description' => 'With a pending request_id, answer as soon as a person decides or after this many seconds. For clients that cannot run a shell command.',
                ],
            ],
        ],
    ]);
    $result = [];
    foreach ($tools as $tool) {
        $data = $tool->toArray();
        if (!$enabled || ($data['name'] ?? '') !== 'mcp-adapter-execute-ability') {
            $result[] = $tool;
            continue;
        }
        /** @var array{properties?: array<string, mixed>, required?: list<string>, ...} $schema */
        $schema = $data['inputSchema'];
        $schema['properties'] ??= [];
        $schema['properties']['review'] = review_schema();
        // Not required in the schema: only some abilities take a score (see abilities_with_score()).
        $data['inputSchema'] = $schema;
        // Pending confirmations are not the ability's output; normal execution is unchanged.
        unset($data['outputSchema']);
        $description = is_string($data['description'] ?? null) ? $data['description'] : '';
        $data['description'] = $description . "\n\n" . scoring_instructions();
        $result[] = Tool::fromArray($data);
    }
    return $result;
}

/**
 * Where the review can also travel, inside the ability's own input. A connector keeps the tool list it read when it
 * connected and drops fields that list does not have: one that connected while approvals were off never sends review.
 * Everything inside parameters (or a REST run's input) reaches the site, so the review can come there under a name no
 * ability uses, and is taken out before the ability runs.
 */
const REVIEW_IN_PARAMETERS = 'novamira_review';

/**
 * The review where the gate reads it, when it came inside parameters. One sent beside parameters wins.
 *
 * @param array<array-key, mixed> $args
 * @return array<array-key, mixed>
 */
function lift_review(array $args): array
{
    // @mago-expect analysis:mixed-assignment -- The call's arguments are untyped until checked below.
    $parameters = $args['parameters'] ?? null;
    if (!is_array($parameters) || !array_key_exists(REVIEW_IN_PARAMETERS, $parameters)) {
        return $args;
    }
    if (!array_key_exists('review', $args)) {
        $args['review'] = $parameters[REVIEW_IN_PARAMETERS];
    }
    return $args;
}

/**
 * @param array<array-key, mixed> $args
 * @return array<array-key, mixed>
 */
function strip_review(array $args, string $tool_name = 'mcp-adapter-execute-ability'): array
{
    if ($tool_name !== 'mcp-adapter-execute-ability') {
        return $args;
    }
    unset($args['review']);
    if (is_array($args['parameters'] ?? null)) {
        unset($args['parameters'][REVIEW_IN_PARAMETERS]);
    }
    return $args;
}

function requires_confirmation(int $score, int $cutoff): bool
{
    return $score >= $cutoff;
}

/** @return array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}|WP_Error */
function validate_review(mixed $review, int $cutoff): array|WP_Error
{
    if (
        !is_array($review)
        || !is_int($review['attention'] ?? null)
        || $review['attention'] < 0
        || $review['attention'] > ATTENTION_MAX
    ) {
        return new WP_Error(
            'invalid_review',
            'This call needs review.attention: an integer from 0 to 10. Refresh tools/list for the instructions. '
            . 'If your tool has no review field, put the review inside parameters as "novamira_review".',
        );
    }
    if (!requires_confirmation($review['attention'], $cutoff)) {
        return ['attention' => $review['attention']];
    }
    $summary = review_text($review, field: 'summary', max: 240);
    // Details are optional: only what the summary does not say. Sent, they must fit.
    $details = review_text($review, field: 'details', max: 1500);
    $details_sent = is_string($review['details'] ?? null) && trim($review['details']) !== '';
    if ($summary === null || $details_sent && $details === null) {
        $field = $summary === null ? 'summary' : 'details';
        $max = $summary === null ? 240 : 1500;
        return new WP_Error(
            'review_text_required',
            "Current cutoff is {$cutoff}. Include a non-empty review.{$field} (at most {$max} characters); nothing has executed.",
        );
    }
    $valid = ['attention' => $review['attention'], 'summary' => $summary];
    if ($details !== null) {
        $valid['details'] = $details;
    }
    return $valid;
}

/**
 * A trimmed, non-empty review text field within its length limit, or null.
 *
 * @param array<array-key, mixed> $review
 */
function review_text(array $review, string $field, int $max): ?string
{
    if (!is_string($review[$field] ?? null)) {
        return null;
    }
    $value = trim($review[$field]);
    return $value !== '' && mb_strlen($value, encoding: 'UTF-8') <= $max ? $value : null;
}

/** @param array<string, mixed> $handler */
function capture_session(mixed $response, array $handler, \WP_REST_Request $request): mixed
{
    session_id($request);
    return $response;
}

/**
 * The session the running AI call belongs to: an MCP session, or a Novamira Chat conversation, which never mixes
 * with one because of its prefix.
 */
function session_id(?\WP_REST_Request $request = null, ?string $chat = null, ?string $session = null): string
{
    static $id = '';
    if ($session !== null) {
        $id = $session;
    }
    if ($request !== null) {
        $id = $request->get_header('Mcp-Session-Id') ?? '';
    }
    if ($chat !== null) {
        $id = 'chat-' . $chat;
    }
    return $id;
}

/** Read-only session lookup: dashboard heartbeats must not keep an abandoned AI session alive. */
function session_alive(int $user_id, string $id): bool
{
    // A concurrent DELETE may have invalidated the map since this PHP request first read it.
    wp_cache_delete($user_id, group: 'user_meta');
    return $id !== '' && is_array(SessionManager::get_session($user_id, $id));
}

/** @param array<array-key, mixed> $data */
function response(array $data, bool $error = false): CallToolResult
{
    return CallToolResult::fromArray([
        'content' => [['type' => 'text', 'text' => (string) wp_json_encode($data)]],
        'structuredContent' => $data,
        'isError' => $error,
    ]);
}

function error_response(string $message): CallToolResult
{
    return response(['error' => $message, 'executed' => false], error: true);
}

function execution_ticket(?string $set = null): ?string
{
    static $ticket = null;
    $previous = $ticket;
    $ticket = $set;
    return $previous;
}

/** @param array<string, mixed> $params */
function gate_call(mixed $override, array $params, McpServer $server): mixed
{
    if ($override !== null || novamira_is_read_mode()) {
        return $override;
    }
    $ticket = execution_ticket();
    if ($ticket !== null && hash_equals($ticket, hash('sha256', serialize($params)))) {
        return null;
    }
    $name = is_string($params['name'] ?? null) ? trim($params['name']) : '';
    $args = lift_review(is_array($params['arguments'] ?? null) ? $params['arguments'] : []);
    if ($name === STATUS_TOOL) {
        return status_call($args, $server);
    }
    if ($name !== 'mcp-adapter-execute-ability' || !active()) {
        return null;
    }
    return gate_scored_call($name, $args, settings()['cutoff'], $server);
}

/**
 * The review a call brings: no score for abilities a fixed rule decides. Whatever can ask a person always comes with the
 * AI's words for what the call does: a card nobody can understand is not a decision.
 *
 * @param array<array-key, mixed> $args
 * @param array{score: bool, confirm: string, check: string, floor: int} $policy
 * @return array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}|WP_Error
 */
function call_review(array $args, array $policy, int $cutoff): array|WP_Error
{
    $review = $policy['score'] ? validate_review($args['review'] ?? null, $cutoff) : ['attention' => 0];
    // Every call that can change the site says what it changes, asked about or not: the history shows it.
    if (is_wp_error($review) || $policy['confirm'] === 'never') {
        return $review;
    }
    $raw = is_array($args['review'] ?? null) ? $args['review'] : [];
    $words = review_text($raw, field: 'summary', max: 240) ?? fixed_rule_summary($args);
    if ($words === null) {
        return new WP_Error('review_text_required', summary_required_text());
    }
    $review = array_merge($review, ['summary' => $words]);
    // People approving do not read commands: Novamira says what this one means, next to the AI's words.
    return $policy['check'] === 'wp_cli' ? array_merge($review, ['means' => wp_cli_meaning($args)]) : $review;
}

/** What the AI is told when a call that changes the site comes without its summary. */
function summary_required_text(): string
{
    return (
        'This call changes the site and needs a summary: one plain sentence saying what it changes and naming what it '
        . 'touches. Send it as review.summary, or inside the input as "novamira_review": {"summary": "..."}, which also '
        . 'works through Novamira CLI. Nothing has executed.'
    );
}

/**
 * What a call does, said by Novamira when the AI sent no summary, for the abilities a fixed rule decides: their input
 * says it all. Clients that build these calls themselves, as Novamira CLI's upload does, have no way to add one.
 *
 * @param array<array-key, mixed> $args
 */
function fixed_rule_summary(array $args): ?string
{
    $ability = is_string($args['ability_name'] ?? null) ? $args['ability_name'] : '';
    $input = is_array($args['parameters'] ?? null) ? $args['parameters'] : [];
    $path = is_string($input['path'] ?? null) ? trim($input['path']) : '';
    $path = mb_strimwidth($path, start: 0, width: 160, trim_marker: '...', encoding: 'UTF-8');
    if ($ability === 'novamira/create-admin-access-link') {
        $minutes = (int) ceil(max(60, min(3_600, (int) ($input['session_expires_in'] ?? 1_800))) / 60);
        return sprintf(
            "Create a link that lets your AI open this site's admin in a browser, signed in as its WordPress user for %s. In the admin it has that user's access, approvals included.",
            $minutes === 1 ? '1 minute' : "{$minutes} minutes",
        );
    }
    if ($path === '') {
        return null;
    }
    return match ($ability) {
        'novamira/delete-file' => ($input['recursive'] ?? false) === true
            ? "Delete {$path} and everything inside it."
            : "Delete {$path}.",
        'novamira/create-upload-link' => "Create a link to upload a file to {$path}.",
        'novamira/enable-file' => "Enable the sandbox file {$path}, so that it runs.",
        default => null,
    };
}

/**
 * The confirmation flow for one mcp-adapter-execute-ability call: check the score, the page, the code,
 * then execute (null) or queue. While the conversation has a decision waiting, the call joins it instead of running.
 *
 * @param array<array-key, mixed> $args
 */
function gate_scored_call(string $name, array $args, int $cutoff, McpServer $server): ?CallToolResult
{
    $ability = is_string($args['ability_name'] ?? null) ? $args['ability_name'] : '';
    $policy = call_policy($ability, $args['parameters'] ?? null);
    if ($policy['confirm'] === 'never') {
        return null;
    }
    $block = live_block(mcp_scope($server));
    $answer = block_reply($block, $name, $args, $server);
    if ($answer !== null) {
        return $answer;
    }
    // A call that asks a person anyway needs no new rating.
    $unread = $policy['score'] && $policy['confirm'] !== 'always' ? rules_refusal($args) : '';
    if ($unread !== '') {
        return error_response($unread);
    }
    $review = call_review($args, $policy, $cutoff);
    if (is_wp_error($review)) {
        return error_response($review->get_error_message());
    }
    $call_id = call_id_of($args['review'] ?? null);
    if ($call_id === null) {
        return error_response('review.call_id must be 8 to 64 letters, digits, "-" or "_". Nothing has executed.');
    }
    $outcome = level_outcome($ability, $args, $review, $policy, $cutoff);
    if ($outcome instanceof CallToolResult) {
        return $outcome;
    }
    if ($outcome['ask']) {
        return queue_checked_call($name, $args, $outcome['review'], $server);
    }
    return $block === null
        ? run_unasked($call_id, $args, $server)
        : join_small_call($name, $args, $outcome['review'], $call_id, $server);
}

/** An MCP call's parameters as the MCP Adapter hands them to the ability: {} counts as no arguments. */
function mcp_input(string $ability_name, mixed $parameters): mixed
{
    $ability = function_exists('wp_get_ability') ? wp_get_ability($ability_name) : null;
    return $ability instanceof \WP_Ability
    && method_exists($ability, 'get_input_schema')
    && class_exists(\Novamira\Vendor\WP\MCP\Domain\Utils\AbilityArgumentNormalizer::class)
        ? \Novamira\Vendor\WP\MCP\Domain\Utils\AbilityArgumentNormalizer::normalize($ability, $parameters)
        : $parameters;
}

/**
 * Why a call's input does not fit its ability's schema, checked as WordPress checks it when the ability runs, so a
 * person is not asked to approve a call that would fail. Empty when it fits or the ability declares no schema.
 */
function invalid_input_text(string $ability_name, mixed $input): string
{
    $ability = function_exists('wp_get_ability') ? wp_get_ability($ability_name) : null;
    if (
        !$ability instanceof \WP_Ability
        || !method_exists($ability, 'get_input_schema')
        || $ability->get_input_schema() === []
    ) {
        return '';
    }
    /** @var mixed $normalized */
    $normalized = $ability->normalize_input($input);
    if (is_wp_error($normalized)) {
        return '';
    }
    $valid = $ability->validate_input($normalized);
    return is_wp_error($valid)
        ? $valid->get_error_message() . ' Nothing has executed: fix the input and send the call again.'
        : '';
}

/**
 * What a call meets before it can wait: input its ability would refuse, the request it already is in its
 * conversation's block, a refusal, or null to go on.
 *
 * @param array{lead: array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int}, members: list<array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int}>}|null $block
 * @param array<array-key, mixed> $args
 */
function block_reply(?array $block, string $name, array $args, McpServer $server): ?CallToolResult
{
    $ability = is_string($args['ability_name'] ?? null) ? $args['ability_name'] : '';
    $invalid = invalid_input_text($ability, mcp_input($ability, strip_review($args)['parameters'] ?? null));
    if ($invalid !== '') {
        return error_response($invalid);
    }
    $answer = block_answer($block, $name, strip_review($args), call_owner($server));
    if (is_array($answer)) {
        return queue_response(public_record($answer));
    }
    return is_string($answer) ? error_response($answer) : null;
}

/**
 * The code check and the level for a call: a refusal, or the review it runs with or asks a person with.
 *
 * @param array<array-key, mixed> $args
 * @param array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool} $review
 * @param array{score: bool, confirm: string, check: string, floor: int} $policy
 * @return CallToolResult|array{ask: bool, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}}
 */
function level_outcome(string $ability, array $args, array $review, array $policy, int $cutoff): CallToolResult|array
{
    // Another plugin's ability that is not rated runs as it is.
    if ($policy['confirm'] === 'unchecked') {
        return ['ask' => false, 'review' => $review];
    }
    $checked = check_code($args, $review, $policy);
    if ($checked instanceof CallToolResult) {
        return $checked;
    }
    ['access' => $access, 'always' => $always] = always_asks($policy, $checked['analysis']);
    $queued = $always || requires_confirmation($checked['review']['attention'], $cutoff);
    log_score($args, $review['attention'], $queued, $checked['analysis']);
    if (!$queued) {
        return ['ask' => false, 'review' => $checked['review']];
    }
    $asked = asked_review($ability, $policy['confirm'], $checked);
    return ['ask' => true, 'review' => $access === [] ? $asked : array_merge($asked, ['access' => $access])];
}

/**
 * A call that would not ask on its own waits with the decision of its conversation, so it never runs before the
 * calls sent ahead of it. If that decision was taken meanwhile, it runs as usual.
 *
 * @param array<array-key, mixed> $args
 * @param array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool} $review
 */
function join_small_call(string $name, array $args, array $review, string $call_id, McpServer $server): ?CallToolResult
{
    $queued = queue_checked_call(
        $name,
        $args,
        array_merge($review, ['joined_small' => true]),
        $server,
        join_only: true,
    );
    return $queued ?? run_unasked($call_id, $args, $server);
}

/**
 * Whether a call asks whatever the chosen level: its ability always does, or the AI may lose this site after it, or it
 * moves money, deletes users, or the AI's own code sends data to an address not approved yet. A person decides those.
 *
 * @param array{score: bool, confirm: string, check: string, floor: int} $policy
 * @param array{score: int, rules: list<string>, reject: bool}|null $analysis
 * @return array{access: list<string>, always: bool}
 */
function always_asks(array $policy, ?array $analysis): array
{
    $access = access_lines($analysis);
    return [
        'access' => $access,
        'always' =>
            $policy['confirm'] === 'always'
                || $access !== []
                || moves_money($analysis)
                || deletes_users($analysis)
                || sends_to_new_destination($analysis),
    ];
}

/**
 * Whether the check found a refund or a payment: money that leaves cannot be brought back, so a person decides whatever
 * the chosen level.
 *
 * @param array{score: int, rules: list<string>, reject: bool}|null $analysis
 */
function moves_money(?array $analysis): bool
{
    foreach ($analysis['rules'] ?? [] as $rule) {
        if (str_starts_with($rule, 'money:')) {
            return true;
        }
    }
    return false;
}

/**
 * Whether the check found users being deleted: their content and access go with them, so a person decides whatever
 * the chosen level.
 *
 * @param array{score: int, rules: list<string>, reject: bool}|null $analysis
 */
function deletes_users(?array $analysis): bool
{
    foreach ($analysis['rules'] ?? [] as $rule) {
        if (str_ends_with($rule, ':deletes_user')) {
            return true;
        }
    }
    return false;
}

/** What each access finding means: the AI may not be able to come back to fix it. */
const ACCESS_WORDS = [
    'site_address' => 'It changes the site\'s address. Novamira is tied to the address, so the AI may lose access to this site.',
    'novamira_off' => 'It turns Novamira off, so the AI loses access to this site.',
];

/**
 * The calls after which the AI may lose this site, in plain words for the card.
 *
 * @param array{score: int, rules: list<string>, reject: bool}|null $analysis
 * @return list<string>
 */
function access_lines(?array $analysis): array
{
    $lines = [];
    foreach ($analysis['rules'] ?? [] as $rule) {
        if (!str_starts_with($rule, 'access:')) {
            continue;
        }
        $lines[] = ACCESS_WORDS[substr($rule, offset: 7)] ?? '';
    }
    return array_values(array_unique(array_filter($lines, static fn(string $line): bool => $line !== '')));
}

/** What each finding means to the person approving, in plain words. Findings missing here say nothing useful. */
const FOUND_WORDS = [
    'broad_sql_write' => 'Changes many database rows at once',
    'critical_option' => 'Changes a setting the whole site depends on',
    'deletes_code' => 'Deletes plugin or theme files',
    'runs_on_every_request' => 'Turns on a sandbox PHP file so it runs on every request',
    'deletes_directory' => 'Deletes a whole folder',
    'direct_mail' => 'Sends email',
    'email' => 'Sends email',
    'external_request' => 'Sends data to another service',
    'order_status' => 'Changes the status of orders',
    'plugins_and_themes' => 'Installs, updates or removes plugins or themes',
    'plugins_on_off' => 'Turns plugins or themes on or off',
    'plugins_on' => 'Turns on a plugin or a theme, which then runs on every request',
    'refund' => 'Issues a refund',
    'payment' => 'Takes, completes or refunds a payment',
    'table_structure' => 'Changes or deletes a database table',
    'users_and_roles' => 'Changes users or their permissions',
    'deletes_user' => 'Deletes users',
    'permalinks' => 'Changes the structure of every link on the site',
    'wp_cli_arguments' => 'Uses a command option that cannot be checked',
    'wp_cli_database' => 'Works directly on the database',
    'wp_cli_post_delete' => 'Deletes several posts, or deletes them for good',
    'wp_cli_search_replace' => 'Replaces text across the whole database',
    'deletes_content' => 'Deletes content',
    'trash' => 'Moves content to the trash',
    'deletes_file' => 'Deletes a file',
    'deletes_sandbox_protection' => 'Removes what keeps sandbox code from opening on the web',
    'file_outside_sandbox' => 'Changes files outside Novamira\'s folder',
    'replaces_file' => 'Replaces a file outside Novamira\'s folder',
    'files' => 'Changes files',
    'option' => 'Changes a site setting',
    'scheduled' => 'Schedules something to run later',
    'sql_write' => 'Writes to the database',
    'wpdb_write' => 'Writes to the database',
    'user_change' => 'Changes a user',
    'dynamic_call' => 'What it changes can only be seen once it runs',
    'eval' => 'What it changes can only be seen once it runs',
    'include' => 'What it changes can only be seen once it runs',
    'runs_other_code' => 'What it changes can only be seen once it runs',
    'callback_by_name' => 'What it changes can only be seen once it runs',
    'unknown_callback' => 'Calls a function known only once it runs',
    'shell' => 'Runs a system command',
    'novamira_internals' => 'Changes Novamira itself',
    'check_failed' => 'Novamira could not check this code',
    'check_unavailable' => 'Code cannot be checked on this site',
    'object_delete' => 'Calls delete() or trash() on an object',
    'object_save' => 'Calls save() on an object',
    'sign_in' => 'Changes how people sign in, or signs them out',
    'network_site' => 'Changes a site of the network',
    'deletes_site' => 'Deletes a site of the network and its uploaded files',
    'core_update' => 'Updates WordPress itself',
    'unscheduled' => 'Removes scheduled tasks',
    'shop_data' => 'Changes orders or stock',
    'removes_hooks' => 'Turns off what other plugins do on this call',
    'runs_later' => 'Leaves code that runs after this call, outside the AI change history',
    'direct_database' => 'Opens its own database connection',
    'file_content' => 'Writes file content known only once it runs',
    'path' => 'Works on a file known only once it runs',
];

/** A rule as a person reads it: high:users_and_roles is "Changes users or their permissions", or nothing. */
function found_rule_words(string $rule): string
{
    if (str_starts_with($rule, 'send:')) {
        return destination_words(substr($rule, offset: 5));
    }
    $colon = strpos($rule, needle: ':');
    $name = $colon === false ? $rule : substr($rule, offset: $colon + 1);
    // A change repeated in a loop says what it does and that it repeats.
    if (str_ends_with($name, '_in_loop')) {
        $words = FOUND_WORDS[substr($name, offset: 0, length: -strlen('_in_loop'))] ?? '';
        return $words === '' ? '' : $words . ', many times';
    }
    return FOUND_WORDS[$name] ?? '';
}

/**
 * What the AI change history does not keep: files, emails that go out, money and data that reach another service, and
 * code that runs outside the call. The AI undoes a change by reading what the history kept, so here it has nothing to
 * read. An email is kept while emails are held: nothing goes out until a person sends it.
 */
const OUTSIDE_HISTORY = [
    'core_update',
    'deletes_code',
    'deletes_directory',
    'deletes_file',
    'deletes_site',
    'deletes_sandbox_protection',
    'direct_database',
    'direct_mail',
    'email',
    'external_request',
    'file_content',
    'file_outside_sandbox',
    'files',
    'path',
    'payment',
    'plugins_and_themes',
    'refund',
    'replaces_file',
    'runs_later',
    'shell',
];

/** What the check cannot read, so it cannot tell whether the history keeps it. */
const UNKNOWN_TO_HISTORY = [
    'callback_by_name',
    'check_failed',
    'check_unavailable',
    'dynamic_call',
    'eval',
    'include',
    'runs_other_code',
    'unknown_callback',
    'wp_cli_arguments',
];

/**
 * Whether the AI could undo the call later from the history: "history" when everything found is kept there, "no" when
 * part of it is not, and nothing when Novamira cannot tell or the history is off.
 *
 * @param list<string> $rules
 * @return array{undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}
 */
function undo_review(array $rules): array
{
    $names = array_map(static function (string $rule): string {
        $colon = strpos($rule, needle: ':');
        $name = $colon === false ? $rule : substr($rule, offset: $colon + 1);
        return str_ends_with($name, '_in_loop') ? substr($name, offset: 0, length: -strlen('_in_loop')) : $name;
    }, $rules);
    foreach ($names as $name) {
        if (in_array($name, OUTSIDE_HISTORY, strict: true) && ($name !== 'email' || !holds_emails())) {
            // The reason is what Novamira found, in the words the request already shows.
            return ['undo' => 'no', 'undo_reason' => FOUND_WORDS[$name] ?? ''];
        }
    }
    if ($names === [] || !history_is_on() || array_intersect($names, UNKNOWN_TO_HISTORY) !== []) {
        return [];
    }
    return ['undo' => 'history'];
}

/**
 * What a person reads on the card: what the AI said and what the check found.
 *
 * @param array{review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, analysis: array{score: int, rules: list<string>, reject: bool}|null} $checked
 * @return array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}
 */
function asked_review(string $ability, string $confirm, array $checked): array
{
    $asked = with_what_was_found($checked['review'], $checked['analysis']);
    return $confirm === 'always' ? always_review($ability, $asked) : $asked;
}

/**
 * @param array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool} $review
 * @param array{score: int, rules: list<string>, reject: bool}|null $analysis
 * @return array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}
 */
function with_what_was_found(array $review, ?array $analysis): array
{
    $summary = trim($review['summary'] ?? '');
    if ($analysis === null || $analysis['rules'] === []) {
        return $review;
    }
    $found = array_filter(
        array_map(static fn(string $rule): string => found_rule_words($rule), $analysis['rules']),
        static fn(string $words): bool => $words !== '',
    );
    // Kept apart from the AI's words, so the card shows what Novamira found next to what the AI says.
    return array_merge($review, undo_review($analysis['rules']), [
        'summary' => $summary,
        'found' => array_values(array_unique([
            ...($review['found'] ?? []),
            ...$found,
        ])),
    ]);
}

/**
 * Let a call run with no person asked: with a call_id, a repeat of it gets the first result instead of running twice.
 *
 * @param array<array-key, mixed> $args
 */
function run_unasked(string $call_id, array $args, McpServer $server): ?CallToolResult
{
    return $call_id === '' ? null : begin_call(call_owner($server), $call_id, $args);
}

/**
 * Queue a call for a person, with the sizes counted now on the live site rather than the AI's description of them.
 * Null when a small call found no decision left to join.
 *
 * @param array<array-key, mixed> $args
 * @param array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool} $queue_review
 */
function queue_checked_call(
    string $name,
    array $args,
    array $queue_review,
    McpServer $server,
    bool $join_only = false,
): ?CallToolResult {
    return queue_call($name, $args, with_measured_writes($args, $queue_review), $server, $join_only);
}

/**
 * The sizes counted now on the live site, rather than the AI's description of them.
 *
 * @param array<array-key, mixed> $args
 * @param array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool} $review
 * @return array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}
 */
function with_measured_writes(array $args, array $review): array
{
    $measured = measured_writes($args);
    if ($measured !== []) {
        $review['measured'] = $measured;
        $review['measured_at'] = time();
    }
    return $review;
}

/**
 * Apply an ability's fixed check. A refusal stops the call, and the score used is the higher of the AI's and the
 * check's. Abilities that send no AI score always use their check: there is nothing else to decide with.
 *
 * @param array<array-key, mixed> $args
 * @param array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool} $review
 * @param array{score: bool, confirm: string, check: string, floor: int} $policy
 * @return CallToolResult|array{review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, analysis: array{score: int, rules: list<string>, reject: bool}|null}
 */
function check_code(array $args, array $review, array $policy): CallToolResult|array
{
    $analysis = ability_check($policy, $args);
    if ($analysis === null) {
        return ['review' => $review, 'analysis' => $analysis];
    }
    if (is_forbidden($analysis)) {
        log_score($args, $review['attention'], queued: false, analysis: $analysis);
        return error_response(refusal_text($analysis['rules']));
    }
    if ($analysis['reject']) {
        return ['review' => unreadable_review($review, $analysis), 'analysis' => $analysis];
    }
    // The AI may raise the score above the check, never lower it below.
    if ($analysis['score'] <= $review['attention']) {
        return ['review' => $review, 'analysis' => $analysis];
    }
    return ['review' => raised_review($review, $analysis['score'], $analysis), 'analysis' => $analysis];
}

/** @param list<string> $rules */
function refusal_text(array $rules): string
{
    if (($rules[0] ?? '') === 'refuse:code_as_text') {
        return (
            'Novamira refuses code that runs other code given as text (eval, create_function) inside execute-php, and '
            . 'no approval changes this. Write that code directly in this call instead. Nothing has executed.'
        );
    }
    if (str_starts_with($rules[0] ?? '', 'forbid:')) {
        return (
            'Novamira refuses this WP-CLI command ('
            . implode(', ', $rules)
            . '), and no approval changes this. To run PHP use novamira/execute-php. Commands that would drop the whole '
            . 'database, touch the AI change history or change Novamira\'s own settings are left to a person. '
            . 'Nothing has executed.'
        );
    }
    return (
        "Novamira's check refused this call ("
        . implode(', ', $rules)
        . "). Code cannot call Novamira's own functions or change Novamira's own settings, and no approval "
        . 'changes this: a person changes those settings from the Novamira pages. '
        . 'Nothing has executed.'
    );
}

/**
 * The review shown for abilities that always ask, which carry no AI summary of their own.
 *
 * @param array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool} $review
 * @return array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}
 */
function always_review(string $ability, array $review): array
{
    $reasons = [
        'novamira/enable-file' => 'Turns on a sandbox PHP file so it runs on every request.',
        'novamira/create-admin-access-link' => 'Creates a link that opens an administrator session in a browser.',
    ];
    return array_merge($review, [
        'summary' => trim($review['summary'] ?? ''),
        'found' => [...($review['found'] ?? []), $reasons[$ability] ?? FORCED_REASON],
        'details' => trim($review['details'] ?? ''),
    ]);
}

/**
 * Code the check cannot read goes to a person, who is told so rather than given a score that means nothing.
 *
 * @param array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool} $review
 * @param array{score: int, rules: list<string>, reject: bool} $analysis
 * @return array{attention: int, summary: string, details: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}
 */
function unreadable_review(array $review, array $analysis): array
{
    // Its findings already say the code cannot be read in advance. The card also says that the AI's words are all
    // there is to go on, and offers the code to whoever can read it.
    return array_merge(raised_review($review, score: ATTENTION_MAX, analysis: $analysis), ['unverified' => true]);
}

/**
 * The review shown for approval when the code check, not the AI, put the call over the cutoff.
 *
 * @param array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool} $review
 * @param array{score: int, rules: list<string>, reject: bool} $analysis
 * @return array{attention: int, summary: string, details: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}
 */
function raised_review(array $review, int $score, array $analysis): array
{
    // What raised it is on the card as Novamira's findings: the AI's own words stay as it wrote them.
    return array_merge(with_what_was_found($review, $analysis), [
        'attention' => $score,
        'summary' => trim($review['summary'] ?? ''),
        'details' => trim($review['details'] ?? ''),
    ]);
}

/**
 * One line per scored call, below the cutoff too, so the AI's scores can be compared later with what the
 * calls actually did. Stores a hash of the arguments, never the code. Concurrent calls may drop a line:
 * this is for statistics, not an audit trail.
 *
 * @param array<array-key, mixed> $args
 * @param array{score: int, rules: list<string>, reject: bool}|null $analysis
 */
function log_score(array $args, int $score, bool $queued, ?array $analysis = null): void
{
    /** @var mixed $stored */
    $stored = get_option(SCORE_LOG_OPTION, []);
    $lines = [];
    if (is_array($stored)) {
        // @mago-expect analysis:mixed-assignment -- Stored lines are checked one by one.
        foreach ($stored as $line) {
            if (!is_array($line) || !is_int($line['time'] ?? null) || $line['time'] < (time() - LOG_TTL)) {
                continue;
            }
            $lines[] = $line;
        }
    }
    $lines[] = [
        'time' => time(),
        'user_id' => get_current_user_id(),
        'ability' => is_string($args['ability_name'] ?? null) ? $args['ability_name'] : '',
        'attention' => $score,
        'queued' => $queued,
        'hash' => hash('sha256', serialize(strip_review($args))),
        // What the code check decided, next to the AI's own score.
        'analysis_score' => $analysis['score'] ?? null,
        'analysis_rules' => $analysis['rules'] ?? [],
        'analysis_reject' => $analysis['reject'] ?? false,
    ];
    update_option(SCORE_LOG_OPTION, array_slice($lines, -SCORE_LOG_LIMIT), autoload: false);
}

/**
 * Prompts and resources run abilities too, through prompts/get and resources/read, but carry no review
 * score and cannot wait for a confirmation. While confirmations are active they are refused unless the
 * component is backed by an ability read mode trusts, or by one of Novamira's own skill prompts (which
 * only return the skill text). Everything else goes through mcp-adapter-execute-ability, where the score
 * and the confirmation apply.
 */
function gate_component(mixed $arguments, mixed $name, mixed $component): mixed
{
    if (!active() || is_wp_error($arguments) || component_allowed($component)) {
        return $arguments;
    }
    $subject = is_string($name) && $name !== '' ? $name : 'this component';
    return new WP_Error(
        'novamira_confirmation_component',
        sprintf(
            'While AI approvals are on, %s cannot run as an MCP prompt or resource. Call it through '
            . 'mcp-adapter-execute-ability with a review instead. Nothing has executed.',
            $subject,
        ),
        ['status' => 403],
    );
}

function component_allowed(mixed $component): bool
{
    $ability = novamira_read_mode_mcp_component_ability($component);
    if ($ability === null || ability_choice($ability->get_name()) === 'yes') {
        return false;
    }
    // Another plugin's ability is that plugin's to answer for.
    if (!ability_is_ours($ability->get_name())) {
        return true;
    }
    if (novamira_read_mode_trusts_mcp_component($component)) {
        return true;
    }
    return is_skill_prompt($ability);
}

/**
 * Whether an ability is one of Novamira's own skill prompts: exactly WP_Ability, named
 * novamira/skill-prompt-*, gated by novamira_permission_callback, and executed by the closure that
 * includes/skills/prompts.php defines (it only returns the skill text).
 */
function is_skill_prompt(\WP_Ability $ability): bool
{
    if (
        get_class($ability) !== \WP_Ability::class
        || !str_starts_with($ability->get_name(), 'novamira/skill-prompt-')
    ) {
        return false;
    }
    $callbacks = novamira_read_mode_ability_callbacks($ability);
    if ($callbacks === null || $callbacks['permission'] !== 'novamira_permission_callback') {
        return false;
    }
    if (!$callbacks['execute'] instanceof \Closure) {
        return false;
    }
    $file = (new \ReflectionFunction($callbacks['execute']))->getFileName();
    $expected = realpath(dirname(__DIR__) . '/skills/prompts.php');
    return is_string($file) && $expected !== false && realpath($file) === $expected;
}

/** @param array<array-key, mixed> $args */
function status_call(array $args, McpServer $server): CallToolResult
{
    if (!is_user_logged_in() || !current_user_can('read')) {
        return error_response('You are not allowed to access confirmations.');
    }
    if (($args['request_id'] ?? null) === null) {
        return response(array_merge(runtime(), ['open_requests' => open_requests_of(call_owner($server))]));
    }
    if (!is_string($args['request_id']) || preg_match('/^[a-f0-9]{32}$/', $args['request_id']) !== 1) {
        return error_response('Invalid confirmation request ID.');
    }
    if (array_key_exists('cancel', $args)) {
        return error_response(CANCEL_REFUSED);
    }
    $wait = is_int($args['wait_seconds'] ?? null) ? $args['wait_seconds'] : 0;
    return poll_request($args['request_id'], $server, $wait);
}

/**
 * @param array<array-key, mixed> $args
 * @param array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool} $review
 */
function queue_call(
    string $name,
    array $args,
    array $review,
    McpServer $server,
    bool $join_only = false,
): ?CallToolResult {
    $session_id = session_id();
    if (!session_alive(get_current_user_id(), $session_id)) {
        return error_response(
            'A live MCP session is required to queue a confirmation. Reconnect before proposing a new action.',
        );
    }
    $tool = $server->get_mcp_tool($name);
    if ($tool === null) {
        return error_response('Tool not found.');
    }
    $clean_args = strip_review($args);
    $permission = $tool->check_permission($clean_args);
    if ($permission !== true) {
        return error_response(is_wp_error($permission) ? $permission->get_error_message() : 'Permission denied.');
    }
    $origin = ['caller' => call_owner($server), 'session' => $session_id, 'scope' => mcp_scope($server)];
    $stored = store_request($name, $clean_args, $review, $origin, $join_only);
    if (is_wp_error($stored)) {
        return $stored->get_error_code() === BLOCK_GONE ? null : error_response($stored->get_error_message());
    }
    if (($stored['block'] ?? '') === $stored['id'] && $origin['scope'] === $origin['caller'] . ':' . $session_id) {
        remember_block_session($origin['caller'], $session_id);
    }
    return queue_response(public_record($stored));
}

/**
 * Save a call for a person to decide, or return the live request an identical call already has.
 *
 * @param array<array-key, mixed> $clean_args
 * @param array{caller: string, session: string, scope: string} $origin Who sent it, and from which conversation.
 * @param array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool} $review
 * @return array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int}|WP_Error
 */
function store_request(
    string $name,
    array $clean_args,
    array $review,
    array $origin,
    bool $join_only = false,
): array|WP_Error {
    ['caller' => $caller, 'session' => $session_id, 'scope' => $scope] = $origin;
    $config = settings();
    if (strlen(serialize($clean_args)) > 262_144) {
        return new WP_Error('call_too_large', 'Call is too large for the confirmation queue (256 KiB limit).');
    }
    $id = bin2hex(random_bytes(16));
    $record = [
        'id' => $id,
        'file_hash' => request_file_hash($clean_args),
        'state' => 'pending_confirmation',
        'created' => time(),
        'generation' => $config['generation'],
        'caller' => $caller,
        'session_id' => $session_id,
        'user_id' => get_current_user_id(),
        'connection' => connection_name($session_id),
        'tool' => $name,
        'arguments' => $clean_args,
        'review' => $review,
        'scope' => $scope,
        'block' => $id,
        'position' => 0,
    ];
    if (!add_option(REQUEST_PREFIX . $id, $record, deprecated: '', autoload: false)) {
        return new WP_Error('not_saved', 'Could not save confirmation request. Nothing has executed.');
    }
    // The request is saved before its key, so a key never points to a request that is still being written.
    // The score is not part of the key: the same call sent again with a different score is the same request.
    $key =
        KEY_PREFIX
        . hash_hmac('sha256', serialize([$caller, $config['generation'], $name, $clean_args]), wp_salt('auth'));
    $claim = claim_key($key, $id, $caller);
    if ($claim === true) {
        return place_request($record, $key, $scope, $join_only);
    }
    delete_option(REQUEST_PREFIX . $id);
    if (is_array($claim)) {
        return $claim;
    }
    return new WP_Error(
        'identical_call',
        'An identical call is being queued at the same time. Ask for the requests still open instead of resending. Nothing has executed.',
    );
}

/**
 * Atomically reserve the key of an identical call, so two identical calls arriving together queue one request.
 * Returns true when this call owns the key, the live request it points to when one exists, or false when
 * concurrent identical calls kept taking the key over.
 *
 * @return bool|array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int}
 */
function claim_key(string $key, string $id, string $caller): bool|array
{
    $wpdb = database();
    $value = $id;
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $insert = $wpdb->prepare(
            "INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
            $wpdb->options,
            $key,
            $value,
        );
        if (!is_string($insert)) {
            return false;
        }
        if ($wpdb->query($insert) === 1) {
            return true;
        }
        $current = key_value($key);
        if ($current === null) {
            continue; // Released between the insert and the read: try to insert again.
        }
        $existing = is_request_id($current) ? get_request($current) : null;
        if ($existing !== null) {
            $existing = refresh_record($existing);
            if (
                hash_equals($existing['caller'], $caller)
                && in_array($existing['state'], ['pending_confirmation', 'approved', 'executing'], strict: true)
            ) {
                return $existing;
            }
        }
        // The previous request is over or gone: take the key over only if nobody else did meanwhile.
        $update = $wpdb->prepare(
            'UPDATE %i SET option_value = %s WHERE option_name = %s AND BINARY option_value = %s',
            $wpdb->options,
            $value,
            $key,
            $current,
        );
        if (is_string($update) && $wpdb->query($update) === 1) {
            return true;
        }
    }
    return false;
}

function key_value(string $key): ?string
{
    $wpdb = database();
    $select = $wpdb->prepare('SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $key);
    if (!is_string($select)) {
        return null;
    }
    /** @var mixed $value */
    $value = $wpdb->get_var($select);
    return is_string($value) ? $value : null;
}

function is_request_id(string $value): bool
{
    return preg_match('/^[a-f0-9]{32}$/', $value) === 1;
}

/**
 * Whether a call is the one already waiting: sent again, it gets the same request back.
 *
 * @param array{caller: string, tool: string, arguments: array<array-key, mixed>, ...} $holder
 * @param array<array-key, mixed> $clean_args
 */
function holds_same_call(array $holder, string $name, array $clean_args, string $caller): bool
{
    return (
        hash_equals($holder['caller'], $caller)
        && $holder['tool'] === $name
        && serialize($holder['arguments']) === serialize($clean_args)
    );
}

/**
 * What the AI is told when its conversation already has BLOCK_LIMIT calls waiting together: what waits, since when,
 * and where any administrator decides it.
 *
 * @param array{id: string, user_id: int, created: int, review: array{summary?: string, ...}, ...} $holder
 */
function waiting_text(array $holder): string
{
    $summary = $holder['review']['summary'] ?? '';
    $user = get_userdata($holder['user_id']);
    $connection = is_string($holder['connection'] ?? null) ? $holder['connection'] : '';
    $by = $user instanceof \WP_User ? ', asked through ' . $user->display_name . '\'s connection' : '';
    if ($connection !== '') {
        $by = ', asked through ' . $connection . ($user instanceof \WP_User ? ' (' . $user->display_name . ')' : '');
    }
    return (
        (string) BLOCK_LIMIT
        . ' actions of this conversation already wait together for a person\'s decision'
        . $by
        . ', the first one '
        . human_time_diff($holder['created'])
        . ' ago'
        . ($summary !== '' ? ': "' . $summary . '"' : '')
        . '. That is as many as one decision covers. Read-only calls still work. Any administrator of this site can '
        . 'approve or reject them at '
        . request_url($holder['id'])
        . ': tell the user so. Nothing has executed: send this call again after they are decided.'
    );
}

/** Delete a key only while it still points to the given request. */
function release_key(string $key, string $id): void
{
    $wpdb = database();
    $delete = $wpdb->prepare(
        'DELETE FROM %i WHERE option_name = %s AND BINARY option_value = %s',
        $wpdb->options,
        $key,
        $id,
    );
    if (is_string($delete)) {
        $wpdb->query($delete);
    }
}

/** Drop keys whose request is over or already purged. */
function prune_keys(): void
{
    foreach (option_names(KEY_PREFIX) as $key) {
        $current = key_value($key);
        if ($current === null) {
            continue;
        }
        $record = is_request_id($current) ? get_request($current) : null;
        if (
            $record !== null
            && in_array($record['state'], ['pending_confirmation', 'approved', 'executing'], strict: true)
        ) {
            continue;
        }
        release_key($key, $current);
    }
}

/** @param array<string, mixed> $record */
function queue_response(array $record): CallToolResult
{
    $id = is_string($record['request_id'] ?? null) ? $record['request_id'] : '';
    return response(array_merge($record, $id !== '' ? waiting_fields($id) : ['dashboard_url' => dashboard_url()], [
        'next_step' => waiting_step(),
    ]));
}

/** @return array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int}|null */
function get_request(string $id): ?array
{
    /** @var array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int}|false $value */
    $value = get_option(REQUEST_PREFIX . $id);
    if (!is_array($value)) {
        return null;
    }
    // A record without a review cannot be shown or run: one bad record must not take the whole page down.
    // @mago-expect analysis:redundant-null-coalesce,impossible-condition -- Records saved in an older shape lack it.
    if (!is_array($value['review'] ?? null)) {
        delete_option(REQUEST_PREFIX . $id);
        return null;
    }
    if (($value['finished'] ?? $value['created']) < (time() - LOG_TTL)) {
        delete_option(REQUEST_PREFIX . $id);
        return null;
    }
    return $value;
}

/** Compare-and-swap prevents double approval/execution across concurrent requests and tabs.
 * @param array{id: string, ...} $old
 * @param array{id: string, ...} $new
 */
function transition(array $old, array $new): bool
{
    if (!in_array($new['state'] ?? '', ['pending_confirmation', 'approved', 'executing'], strict: true)) {
        $new['finished'] ??= time();
        // A request that can no longer execute keeps its log without the secrets it may contain.
        foreach (['arguments', 'result', 'review'] as $field) {
            if (!array_key_exists($field, $new)) {
                continue;
            }
            $new[$field] = redact($new[$field]);
        }
    }
    $wpdb = database();
    $query = $wpdb->prepare(
        'UPDATE %i SET option_value = %s WHERE option_name = %s AND BINARY option_value = %s',
        $wpdb->options,
        maybe_serialize($new),
        REQUEST_PREFIX . $old['id'],
        maybe_serialize($old),
    );
    if (!is_string($query)) {
        return false;
    }
    $changed = $wpdb->query($query);
    wp_cache_delete(REQUEST_PREFIX . $old['id'], group: 'options');
    return $changed === 1;
}

/** Seconds after which an execution still marked as running is reported as interrupted.
 * Derived from PHP's own limit so a slow but healthy execution is not declared lost while it runs.
 */
function execution_timeout(): int
{
    $limit = (int) ini_get('max_execution_time');
    if ($limit <= 0) {
        return 3600;
    }
    return max(120, $limit + 60);
}

/** Store the final outcome even if a heartbeat already marked this execution as interrupted.
 * @param array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int} $claimed
 * @param array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int} $finished
 * @return array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int}
 */
function finish_execution(array $claimed, array $finished): array
{
    if (transition($claimed, $finished)) {
        return get_request($claimed['id']) ?? $finished;
    }
    $current = get_request($claimed['id']);
    if (
        $current !== null
        && $current['state'] === 'execution_unknown'
        && ($current['started'] ?? null) === ($claimed['started'] ?? null)
        && transition($current, $finished)
    ) {
        return get_request($claimed['id']) ?? $finished;
    }
    return $current ?? $finished;
}

/**
 * Pending requests wait up to REQUEST_TTL for a decision, counted from the first request of their block; approved ones
 * must run within APPROVAL_TTL of it. Later requests of a block count from the one before them, which their block
 * checks when it runs them.
 *
 * @param array{state: string, created: int, approved_at?: int, position?: int, block_started?: int, ...} $record
 */
function decision_deadline(array $record): int
{
    if ($record['state'] === 'approved' && is_int($record['approved_at'] ?? null)) {
        // The later calls of a block wait their turn: each must start within APPROVAL_TTL of the one before it
        // (late_reason), not within the day the block was first sent, which an approval near its end would cut.
        return ($record['position'] ?? 0) === 0
            ? $record['approved_at'] + APPROVAL_TTL
            : $record['approved_at'] + REQUEST_TTL;
    }
    return ($record['block_started'] ?? $record['created']) + REQUEST_TTL;
}

/** @param array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int} $record
 * @return array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int}
 */
function refresh_record(array $record): array
{
    $config = settings();
    $state = $record['state'];
    $new = $record;
    if (in_array($state, ['pending_confirmation', 'approved'], strict: true)) {
        if (!active() || $record['generation'] !== $config['generation']) {
            $new['state'] = 'canceled';
        }
        if ($new['state'] === $state && decision_deadline($record) < time()) {
            $new['state'] = 'expired';
        }
    }
    if ($state === 'executing' && ($record['started'] ?? 0) < (time() - execution_timeout())) {
        $new['state'] = 'execution_unknown';
    }
    if (!in_array($new['state'], ['pending_confirmation', 'approved', 'executing'], strict: true)) {
        $new['finished'] ??= $new['state'] === $state ? $record['created'] : time();
    }
    if ($new !== $record) {
        transition($record, $new);
        return get_request($record['id']) ?? $new;
    }
    return $record;
}

/**
 * @param array{id: string, state: string, created: int, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, finished?: int, block?: string, position?: int, ...} $record
 * @param list<array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int}>|null $members The block, when the caller already has it.
 * @return array<string, mixed>
 */
function public_record(array $record, ?array $members = null): array
{
    return [
        'request_id' => $record['id'],
        'state' => $record['state'],
        'attention' => $record['review']['attention'],
        'summary' => $record['review']['summary'] ?? '',
        'details' => $record['review']['details'] ?? '',
        'found' => $record['review']['found'] ?? [],
        'means' => $record['review']['means'] ?? '',
        'access' => $record['review']['access'] ?? [],
        'undo' => $record['review']['undo'] ?? '',
        'undo_reason' => $record['review']['undo_reason'] ?? '',
        'unverified' => $record['review']['unverified'] ?? false,
        'measured' => $record['review']['measured'] ?? [],
        'measured_at' => array_key_exists('measured_at', $record['review'])
            ? gmdate('c', $record['review']['measured_at'])
            : null,
        'created_at' => gmdate('c', $record['created']),
        'finished_at' => array_key_exists('finished', $record) ? gmdate('c', $record['finished']) : null,
        'tool' => $record['tool'],
        'ability_name' => is_string($record['arguments']['ability_name'] ?? null)
            ? $record['arguments']['ability_name']
            : '',
        'user_id' => $record['user_id'],
        'joined_small' => $record['review']['joined_small'] ?? false,
        ...people_fields($record),
        ...block_fields($record, $members),
    ];
}

/**
 * Who asked, through which connection, and who decided, with "you" for the person looking.
 *
 * @param array{user_id: int, ...} $record
 * @return array{asked_by: string, connection: string, asked_by_you: bool, decided_by: string, decided_by_you: bool}
 */
function people_fields(array $record): array
{
    $decider = is_int($record['decided_by'] ?? null) ? $record['decided_by'] : 0;
    return [
        'asked_by' => user_name($record['user_id']),
        'connection' => is_string($record['connection'] ?? null) ? $record['connection'] : '',
        'asked_by_you' => $record['user_id'] === get_current_user_id(),
        'decided_by' => $decider === 0 ? '' : user_name($decider),
        'decided_by_you' => $decider !== 0 && $decider === get_current_user_id(),
    ];
}

/**
 * Which of a person's AI connections asked, as they would recognise it: the name they gave the application password
 * it signs in with, else the name the program sent when it opened its MCP session. Nothing when neither is known.
 */
function connection_name(string $session_id): string
{
    $user_id = get_current_user_id();
    $uuid = function_exists('rest_get_authenticated_app_password') ? rest_get_authenticated_app_password() : null;
    if (is_string($uuid) && $uuid !== '') {
        $password = \WP_Application_Passwords::get_user_application_password($user_id, $uuid);
        if (is_array($password) && trim($password['name']) !== '') {
            return mb_substr(sanitize_text_field($password['name']), start: 0, length: 80);
        }
    }
    $session = $session_id === '' ? null : SessionManager::get_session($user_id, $session_id);
    /** @var mixed $client */
    $client = is_array($session) ? $session['client_params']['clientInfo'] ?? null : null;
    if (!is_array($client)) {
        return '';
    }
    /** @var mixed $name */
    $name = $client['title'] ?? $client['name'] ?? '';
    return is_string($name) ? mb_substr(sanitize_text_field($name), start: 0, length: 80) : '';
}

/** The name a person sees for a WordPress user, or nothing when the user no longer exists. */
function user_name(int $user_id): string
{
    $user = get_userdata($user_id);
    return $user instanceof \WP_User ? $user->display_name : '';
}

/**
 * The file a queued call acts on, as it is now. An approval covers the file the card was about, so the two are compared
 * again right before the call runs.
 *
 * @param array<array-key, mixed> $arguments
 */
function request_file_hash(array $arguments): string
{
    $ability = is_string($arguments['ability_name'] ?? null) ? $arguments['ability_name'] : '';
    $parameters = is_array($arguments['parameters'] ?? null) ? $arguments['parameters'] : [];
    return active_file_hash(ability_policy($ability), $parameters);
}

function poll_request(string $id, McpServer $server, int $wait = 0): CallToolResult
{
    $record = get_request($id);
    if ($record === null || !hash_equals($record['caller'], call_owner($server))) {
        return error_response('Confirmation request not found for this WordPress user.');
    }
    $record = refresh_record($record);
    if (!session_alive(get_current_user_id(), session_id())) {
        return error_response(
            'This MCP session is not valid. Reconnect and poll the same request_id: the request is still there.',
        );
    }
    $record = await_decision($record, $wait);
    if ($record['state'] === 'approved' && in_block_of_many($record['block'] ?? $id)) {
        // The calls approved with it run first, in the order they were sent.
        drive_block($record['block'] ?? $id, $server, until: $id);
        wp_cache_delete(REQUEST_PREFIX . $id, group: 'options');
        $record = refresh_record(get_request($id) ?? $record);
    }
    if ($record['state'] === 'approved' && !in_block_of_many($record['block'] ?? $id)) {
        $claimed = array_merge($record, ['state' => 'executing', 'started' => time()]);
        if (transition($record, $claimed)) {
            if (!session_alive(get_current_user_id(), session_id())) {
                transition($claimed, $record);
                return error_response(
                    'This MCP session ended before execution. Nothing has executed. Reconnect and poll the same request_id.',
                );
            }
            $record = execute_claimed($claimed, static fn(): array => run_stored_mcp_call($claimed, $server));
        }
        $record = refresh_record(get_request($id) ?? $record);
    }
    return poll_reply($record);
}

/**
 * Run a call a person approved through the adapter, which rechecks permissions and keeps its normal result encoding.
 *
 * @param array{tool: string, arguments: array<array-key, mixed>, ...} $record
 * @return array<string, mixed>
 */
function run_stored_mcp_call(array $record, McpServer $server): array
{
    $params = ['name' => $record['tool'], 'arguments' => $record['arguments']];
    execution_ticket(hash('sha256', serialize($params)));
    try {
        return (new ToolsHandler($server))
            ->call_tool($params)
            ->toArray();
    } finally {
        execution_ticket();
    }
}

/**
 * The MCP server a request came through, recognized from the owner it was saved with.
 *
 * @param array{caller: string, user_id: int, ...} $record
 */
function server_of_request(array $record): ?McpServer
{
    // Before the adapter has started, asking for it would start it here.
    if (did_action('novamira_mcp_adapter_init') === 0) {
        return null;
    }
    foreach (McpAdapter::instance()->get_servers() as $server) {
        $owner = hash_hmac('sha256', $server->get_server_id() . ':' . $record['user_id'], wp_salt('auth'));
        if (hash_equals($record['caller'], $owner)) {
            return $server;
        }
    }
    return null;
}

/**
 * Run a call right after a person approved it, from their page: the AI may have stopped long ago. It runs as the user
 * the AI is connected as, on the AI's session, so the same rules and the same history apply, and the result waits for
 * the AI. When its server is not around the request stays approved, and the AI's next status call runs it.
 *
 * @param array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int} $record
 * @return array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int}
 */
function run_approved(array $record, ?McpServer $server = null): array
{
    $is_rest = $record['tool'] === REST_TOOL;
    $server ??= $is_rest ? null : server_of_request($record);
    if (!$is_rest && $server === null) {
        return $record;
    }
    $claimed = array_merge($record, ['state' => 'executing', 'started' => time()]);
    if (!transition($record, $claimed)) {
        return get_request($record['id']) ?? $record;
    }
    // Closing the page must not cut the call in half.
    ignore_user_abort(true);
    $approver = get_current_user_id();
    wp_set_current_user($record['user_id']);
    if (!$is_rest) {
        session_id(session: $record['session_id'] ?? '');
    }
    // The request travels with the call, so the history shows the decision and what it changed as one thing, and the
    // calls approved together as one item.
    RunningApproval::$current = [
        'by' => $record['decided_by'] ?? $approver,
        'says' => $record['review']['summary'] ?? '',
        'attention' => $record['review']['attention'],
        'request' => $record['id'],
        'group_request' => history_group_of($record),
    ];
    try {
        return execute_claimed($claimed, static fn(): array => $server === null
            ? run_stored_rest_call($claimed['arguments'])
            : run_stored_mcp_call($claimed, $server));
    } finally {
        RunningApproval::$current = null;
        wp_set_current_user($approver);
    }
}

/**
 * Count again what the card counted: a person may decide long after it was written. Returns the record with the new
 * numbers when they changed, or null when they did not.
 *
 * @param array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int} $record
 * @return array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int}|null
 */
function recounted_card(array $record): ?array
{
    $old = $record['review']['measured'] ?? [];
    if ($old === []) {
        return null;
    }
    $now = measured_writes($record['arguments']);
    if ($now === $old) {
        return null;
    }
    $record['review']['measured'] = $now;
    $record['review']['measured_at'] = time();
    return $record;
}

/**
 * Run a request already claimed as executing, once, and keep what happened. An approval covers the file the card was
 * about, so a file that changed since then runs nothing.
 *
 * @param array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int} $claimed
 * @param callable(): array<string, mixed> $run
 * @return array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int}
 */
function execute_claimed(array $claimed, callable $run): array
{
    if (($claimed['file_hash'] ?? '') !== request_file_hash($claimed['arguments'])) {
        return finish_execution($claimed, array_merge($claimed, [
            'state' => 'failed',
            'finished' => time(),
            'result' => error_response(
                'The file changed after this request was shown for approval. Nothing has executed. Read the file again and send a new call.',
            )->toArray(),
        ]));
    }
    try {
        $wire_result = $run();
        $failed =
            ($wire_result['isError'] ?? false) === true
            || array_key_exists('error', $wire_result)
            || reports_failure($wire_result);
        return finish_execution($claimed, array_merge($claimed, [
            'state' => $failed ? 'failed' : 'completed',
            'finished' => time(),
            'result' => $wire_result,
        ]));
    } catch (\Throwable $error) {
        return finish_execution($claimed, array_merge($claimed, [
            'state' => 'execution_unknown',
            'finished' => time(),
            'result' => response([
                'error' => $error->getMessage(),
                'executed' => null,
                'warning' => 'Some changes may already have happened. Do not automatically retry.',
            ], error: true)->toArray(),
        ]));
    }
}

/**
 * What an ability answered, inside a call's result: the result itself over REST, its "data" over MCP's
 * execute-ability, which wraps it as {success, data}.
 *
 * @param array<array-key, mixed> $wire
 * @return list<array<array-key, mixed>>
 */
function answers_in(array $wire): array
{
    if (!array_key_exists('structuredContent', $wire) || !is_array($wire['structuredContent'])) {
        return [];
    }
    $structured = $wire['structuredContent'];
    $answers = [$structured];
    if (array_key_exists('data', $structured) && is_array($structured['data'])) {
        $answers[] = $structured['data'];
    }
    return $answers;
}

/**
 * Whether the ability itself reported a failure without the call being an error: execute-php catches what the code
 * throws and answers success false, and a WP-CLI command that exits with an error answers the same. The requests
 * approved after it in the same block must not run.
 *
 * @param array<array-key, mixed> $wire
 */
function reports_failure(array $wire): bool
{
    foreach (answers_in($wire) as $answer) {
        if (($answer['success'] ?? null) === false) {
            return true;
        }
    }
    return false;
}

/**
 * Whether the call only started a background job, such as WP-CLI with async: it answers before the job is done, so
 * the next request of a block would run while it is still changing the site.
 *
 * @param array<array-key, mixed> $wire
 */
function started_in_background(array $wire): bool
{
    foreach (answers_in($wire) as $answer) {
        if (is_string($answer['job_id'] ?? null) && $answer['job_id'] !== '') {
            return true;
        }
    }
    return false;
}

/** @param array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int} $record */
function poll_reply(array $record): CallToolResult
{
    $waiting = $record['state'] === 'pending_confirmation';
    $reply = response(array_merge(public_record($record), $waiting ? waiting_fields($record['id']) : [], [
        'runtime' => runtime(),
        'next_step' => $waiting ? waiting_step() : polled_next_step($record),
        'tool_result' => $record['result'] ?? null,
        'warning' => $record['state'] === 'execution_unknown'
            ? 'Execution was interrupted. Some changes may already have happened; inspect the site, do not automatically retry.'
            : null,
    ]));
    return attach_native_content($reply, $record['result'] ?? []);
}

/** Keep approved images/resources usable by MCP clients, not just JSON buried in tool_result.
 * @param array<string, mixed> $result
 */
function attach_native_content(CallToolResult $reply, array $result): CallToolResult
{
    /** @var array{content: list<array<array-key, mixed>>, structuredContent: array<array-key, mixed>, isError: bool} $wire */
    $wire = $reply->toArray();
    /** @var mixed $content */
    $content = $result['content'] ?? [];
    if (!is_array($content)) {
        return $reply;
    }
    // @mago-expect analysis:mixed-assignment -- Stored content comes from the adapter's protocol DTO.
    foreach ($content as $block) {
        if (!is_array($block) || ($block['type'] ?? 'text') === 'text') {
            continue;
        }
        $wire['content'][] = $block;
    }
    return CallToolResult::fromArray($wire);
}

/**
 * The link to decide, and how to wait for it without spending tokens.
 *
 * @return array{dashboard_url: string, wait_url: string, wait_command: string}
 */
function waiting_fields(string $id): array
{
    $record = get_request($id);
    $created = $record['created'] ?? time();
    return [
        'dashboard_url' => request_url($id),
        'wait_url' => wait_url($id, $created),
        'wait_command' => wait_command($id, $created),
    ];
}

/**
 * What follows a status call that returned a request no longer waiting: its result is already in the reply when it ran.
 *
 * @param array{id: string, state: string, tool: string, ...} $record
 */
function polled_next_step(array $record): ?string
{
    return in_array(
        $record['state'],
        ['expired', 'canceled', 'rejected', 'executing', 'approved', 'not_run'],
        strict: true,
    )
        ? next_step_of($record)
        : null;
}

/**
 * The requests of a caller still open, so a client that came back in a new conversation can find what it asked for.
 *
 * @return list<array<string, mixed>>
 */
function open_requests_of(string $caller): array
{
    $open = [];
    foreach (active_requests() as $record) {
        if (!hash_equals($record['caller'], $caller)) {
            continue;
        }
        $open[] = public_record($record);
    }
    return $open;
}

const ACTIVE_STATES = ['pending_confirmation', 'approved', 'executing'];

/**
 * Every stored request, refreshed. Loads all of them with their payloads, so it runs only from the hourly
 * cleanup and on deactivation; the dashboard heartbeat and the MCP calls use active_requests().
 *
 * @return list<array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int}>
 */
function requests(): array
{
    return load_requests(request_names(null));
}

/**
 * Requests that can still execute, refreshed (a refresh may end them, e.g. on expiry). Only rows whose stored
 * state is active are read: a stored terminal state never becomes active again.
 *
 * @return list<array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int}>
 */
function active_requests(): array
{
    return array_values(array_filter(load_requests(request_names(true)), static fn(array $record): bool => in_array(
        $record['state'],
        ACTIVE_STATES,
        strict: true,
    )));
}

/**
 * One page of requests that can no longer execute, newest first.
 *
 * @return list<array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int}>
 */
function finished_requests(int $limit, int $offset): array
{
    return load_requests(request_names(false, $limit, $offset));
}

/**
 * Option names of stored requests, newest first. The state filter matches the serialized record, so a payload
 * that happens to contain the same text only makes a row load needlessly; the loaded state is what counts.
 *
 * @return list<string>
 */
function request_names(?bool $active, int $limit = 0, int $offset = 0): array
{
    $wpdb = database();
    $sql = 'SELECT option_name FROM %i WHERE option_name LIKE %s';
    $args = [$wpdb->options, $wpdb->esc_like(REQUEST_PREFIX) . '%'];
    if ($active !== null) {
        $clauses = [];
        foreach (ACTIVE_STATES as $state) {
            $clauses[] = 'option_value LIKE %s';
            $args[] = '%' . $wpdb->esc_like(serialize('state') . serialize($state)) . '%';
        }
        $sql .= $active ? ' AND (' . implode(' OR ', $clauses) . ')' : ' AND NOT (' . implode(' OR ', $clauses) . ')';
    }
    $sql .= ' ORDER BY option_id DESC';
    if ($limit > 0) {
        $sql .= ' LIMIT %d OFFSET %d';
        $args[] = $limit;
        $args[] = max(0, $offset);
    }
    $query = $wpdb->prepare($sql, ...$args);
    if (!is_string($query)) {
        return [];
    }
    /** @var list<string> */
    return $wpdb->get_col($query);
}

/**
 * @param list<string> $names
 * @return list<array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int}>
 */
function load_requests(array $names): array
{
    $result = [];
    foreach ($names as $name) {
        $record = get_request(substr($name, strlen(REQUEST_PREFIX)));
        if ($record !== null) {
            $result[] = refresh_record($record);
        }
    }
    return $result;
}

function cleanup(): void
{
    // Refresh/prune without prolonging the MCP session or resetting terminal-result retention.
    requests();
    prune_keys();
    prune_blocks();
    prune_calls();
}

function deactivate(): void
{
    wp_clear_scheduled_hook('novamira_confirmation_cleanup');
    delete_transient(HEARTBEAT_TRANSIENT);
    update_option(
        SETTINGS_OPTION,
        array_merge(settings(), ['generation' => bin2hex(random_bytes(16))]),
        autoload: false,
    );
    requests();
}

function database(): \wpdb
{
    // @mago-expect lint:no-global -- WordPress provides the database handle globally.
    global $wpdb;
    /** @var \wpdb $wpdb */
    return $wpdb;
}
