<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Confirmations;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit();
}

add_action('admin_head', static function (): void {
    if (!novamira_current_user_can_manage()) {
        return;
    }
    echo
        '<style>#adminmenu .nm-waiting-badge{display:inline-block;box-sizing:border-box;min-width:18px;height:18px;'
            . 'margin:1px 0 -1px 4px;padding:0 5px;border:1px solid currentColor;border-radius:9px;background:transparent;'
            . 'color:inherit;font-size:11px;font-weight:600;line-height:16px;text-align:center;vertical-align:top}</style>'
    ;
});

add_action(
    'admin_menu',
    static function (): void {
        // @mago-expect lint:no-global -- WordPress keeps its admin menu in a global.
        global $menu;
        $badge = waiting_badge();
        if ($badge !== '' && is_array($menu)) {
            $menu = menu_with_badge($menu, $badge);
        }
        add_submenu_page(
            'novamira-connect',
            __('AI Activity', domain: 'novamira'),
            __('AI Activity', domain: 'novamira') . $badge,
            novamira_manage_capability(),
            PAGE_SLUG,
            __NAMESPACE__ . '\\render_page',
        );
    },
    priority: 11,
);

/**
 * What waits for a person, for Novamira's entry in the admin bar, on the site too: until a request is decided, the
 * conversation that sent it waits. Held emails count as well, as in the menu.
 *
 * @return array{count: int, items: list<array{id: string, title: string, href: string}>}
 */
function admin_bar_waiting(): array
{
    if (!novamira_current_user_can_manage()) {
        return ['count' => 0, 'items' => []];
    }
    $items = [];
    ['requests' => $pending, 'emails' => $emails] = waiting();
    $waiting = $pending[0] ?? null;
    if ($waiting !== null) {
        $items[] = [
            'id' => 'novamira-ai-waiting',
            'title' => __('Approval waiting', domain: 'novamira'),
            'href' => request_url($waiting),
        ];
    }
    if ($emails > 0) {
        $items[] = [
            'id' => 'novamira-held-emails',
            /* translators: %s: number of emails. */
            'title' => sprintf(
                _n(single: '%s email held', plural: '%s emails held', number: $emails, domain: 'novamira'),
                number_format_i18n($emails),
            ),
            'href' => dashboard_url(),
        ];
    }
    return ['count' => count($pending) + $emails, 'items' => $items];
}

/** What the page's tabs are, in order. Sandbox and Block Editor Queue show only where they exist. */
const TABS = ['approvals', 'history', 'sandbox', 'block-editor', 'settings'];

/** Whether a tab exists here: the Block Editor Queue needs the block editor tools. */
function tab_available(string $tab): bool
{
    return match ($tab) {
        'sandbox' => function_exists('novamira_render_sandbox_tab'),
        'block-editor' => function_exists('Novamira\\GutenbergFinalizer\\render_gutenberg_finalizer_tab'),
        default => in_array($tab, TABS, strict: true),
    };
}

/** The AI Activity tabs. The Sandbox tab says when safe mode is on. */
function render_tabs(string $active): void
{
    $labels = [
        'approvals' => __('Approvals', domain: 'novamira'),
        'history' => __('Change History', domain: 'novamira'),
        'sandbox' => __('Sandbox', domain: 'novamira'),
        'block-editor' => __('Block Editor Queue', domain: 'novamira'),
        'settings' => __('Settings', domain: 'novamira'),
    ];
    ?>
    <nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e('AI Activity', domain: 'novamira'); ?>">
        <?php foreach (array_filter(TABS, __NAMESPACE__ . '\\tab_available') as $tab) {
            $flag = $tab === 'sandbox' && safe_mode_on() ? __('Safe mode', domain: 'novamira') : '';
            ?>
            <a class="nav-tab <?php echo $tab === $active ? 'nav-tab-active' : ''; ?>" <?php echo
                $tab === $active ? 'aria-current="page"' : ''
            ; ?> href="<?php echo esc_url(dashboard_url() . '&tab=' . $tab); ?>"><?php echo
                esc_html($labels[$tab])
            ; ?><?php if ($flag !== '') { ?>
                <span style="margin-left:6px;padding:1px 6px;border-radius:9px;background:#d63638;color:#fff;font-size:11px;"><?php echo
                    esc_html($flag)
                ; ?></span><?php } ?></a>
        <?php
        } ?>
    </nav>
    <?php
}

function safe_mode_on(): bool
{
    return function_exists('novamira_get_sandbox_dir') && file_exists(novamira_get_sandbox_dir() . '.crashed');
}

/** The tab asked for in the address, Approvals when none. */
function current_tab(): string
{
    /** @var mixed $tab */
    $tab = $_GET['tab'] ?? '';
    return is_string($tab) && tab_available($tab) ? $tab : 'approvals';
}

/**
 * What waits for a person: the ids of requests still open to a decision, and how many emails are held.
 *
 * @return array{requests: list<string>, emails: int}
 */
function waiting(): array
{
    // A block of requests is one decision: its first request stands for it.
    $requests = array_column(
        array_filter(
            active_requests(),
            static fn(array $record): bool => (
                $record['state'] === 'pending_confirmation'
                && ($record['block'] ?? $record['id']) === $record['id']
            ),
        ),
        'id',
    );
    $emails = function_exists('Novamira\\Journal\\held_mail_count') ? \Novamira\Journal\held_mail_count() : 0;
    return ['requests' => $requests, 'emails' => $emails];
}

/**
 * How many things wait for a person: requests still open to a decision, and held emails.
 */
function waiting_count(): int
{
    ['requests' => $requests, 'emails' => $emails] = waiting();
    return count($requests) + $emails;
}

/** WordPress's own count bubble for a menu entry, or nothing when nothing waits. */
function waiting_badge(): string
{
    if (!novamira_current_user_can_manage()) {
        return '';
    }
    $count = waiting_count();
    // Not WordPress's red bubble: something waiting is not an error. It takes the menu's own text colour.
    return $count > 0 ? ' <span class="nm-waiting-badge">' . number_format_i18n($count) . '</span>' : '';
}

/**
 * The submenu is closed on most admin pages, so the Novamira entry itself shows the count too.
 *
 * @param array<array-key, mixed> $menu WordPress's admin menu.
 * @return array<array-key, mixed>
 */
function menu_with_badge(array $menu, string $badge): array
{
    return array_map(static function (mixed $entry) use ($badge): mixed {
        if (!is_array($entry) || ($entry[2] ?? '') !== 'novamira-connect' || !is_string($entry[0] ?? null)) {
            return $entry;
        }
        $entry[0] .= $badge;
        return $entry;
    }, $menu);
}

/**
 * The version a file of the page loads with: the plugin's, and the file's date, so a build with the same version number
 * does not leave browsers on the old file.
 */
function asset_version(string $file): string
{
    return NOVAMIRA_VERSION . (is_file($file) ? '.' . (string) filemtime($file) : '');
}

add_action('admin_enqueue_scripts', static function (): void {
    if (($_GET['page'] ?? '') !== PAGE_SLUG || !novamira_current_user_can_manage()) {
        return;
    }
    $css = __DIR__ . '/assets/page.css';
    $js = dirname(__DIR__, levels: 2) . '/assets/confirmations.js';
    wp_enqueue_style(
        'novamira-ai-activity',
        (string) NOVAMIRA_PLUGIN_URL . 'includes/confirmations/assets/page.css',
        [],
        asset_version($css),
    );
    wp_enqueue_script(
        'novamira-confirmations',
        (string) NOVAMIRA_PLUGIN_URL . 'assets/confirmations.js',
        ['wp-api-fetch'],
        asset_version($js),
        args: true,
    );
    wp_add_inline_script(
        'novamira-confirmations',
        'window.novamiraConfirmations = '
        . (string) wp_json_encode([
            'path' => '/novamira/v1/confirmations',
            'strings' => [...script_strings(), ...card_strings()],
            'historyUrl' => history_is_on() ? dashboard_url() . '&tab=history' : '',
            'requestTtl' => REQUEST_TTL,
            'decisionNonce' => wp_create_nonce(DECISION_NONCE_ACTION),
        ])
        . ';',
        position: 'before',
    );
});

/**
 * The words of a waiting request and of a past decision, for the page's script.
 *
 * @return array<string, string>
 */
function card_strings(): array
{
    return [
        'undo' => __('Undo', domain: 'novamira'),
        'undoHistory' => __('Possible with the AI: ask it, it reads Change History.', domain: 'novamira'),
        'undoNo' => __('Change History cannot put this back.', domain: 'novamira'),
        /* translators: %s: what Novamira found in the code, such as "sends data to another service". */
        'undoNoBecause' => __('Change History cannot put this back: it %s.', domain: 'novamira'),
        'aiDetails' => __('The AI says', domain: 'novamira'),
        /* translators: %s: how long ago, as "3 minutes ago". */
        'askedAgo' => __('Asked %s', domain: 'novamira'),
        /* translators: %s: when, as "in 23 hours". */
        'expiresIn' => __('Expires %s', domain: 'novamira'),
        'throughYou' => __('through your connection', domain: 'novamira'),
        /* translators: %s: the name of the AI connection, as "Claude Desktop". */
        'throughYourNamed' => __('through %s, your connection', domain: 'novamira'),
        /* translators: %s: the name of the AI connection, as "Claude Desktop". */
        'throughNamed' => __('through %s', domain: 'novamira'),
        /* translators: %s: the name of the WordPress user the AI is connected as. */
        'throughUser' => __('through %s\'s connection', domain: 'novamira'),
        /* translators: 1: the name of the AI connection, as "Claude Desktop". 2: the name of the WordPress user. */
        'throughUserNamed' => __('through %1$s, %2$s\'s connection', domain: 'novamira'),
        /* translators: %d: number of actions decided together. */
        'approveAll' => __('Approve and run all %d', domain: 'novamira'),
        /* translators: %d: number of actions that need approval, excluding the minor actions. */
        'approveActions' => __('Approve %d actions', domain: 'novamira'),
        'minorAction' => __('If approved, 1 minor action will also run.', domain: 'novamira'),
        /* translators: %d: number of minor actions that run with the approved actions. */
        'minorActions' => __('If approved, %d minor actions will also run.', domain: 'novamira'),
        /* translators: %d: total number of actions in the execution sequence (at least two). */
        'executionOrder' => __('Execution order · %d actions', domain: 'novamira'),
        'executionApproval' => __('Requires approval', domain: 'novamira'),
        'executionMinor' => __('Accompanying action', domain: 'novamira'),
        'rejectAll' => __('Reject all', domain: 'novamira'),
        /* translators: 1: number of actions that ran. 2: number of actions decided together. */
        'blockRan' => __('%1$d of %2$d ran', domain: 'novamira'),
        'byYou' => __('by you', domain: 'novamira'),
        /* translators: %s: the name of the person who decided. */
        'byUser' => __('by %s', domain: 'novamira'),
    ];
}

/**
 * The page's words, for its script.
 *
 * @return array<string, mixed>
 */
function script_strings(): array
{
    return [
        'offline' => __('Disconnected. Reconnecting…', domain: 'novamira'),
        'online' => __('Connected.', domain: 'novamira'),
        'requestGone' => __('This request is no longer waiting: look for it in Change History.', domain: 'novamira'),
        'empty' => __('All clear. Nothing is waiting for you.', domain: 'novamira'),
        'emptySkipped' => __('Approvals are skipped, so nothing waits here.', domain: 'novamira'),
        'emptySkippedHelp' => __(
            'Your AI runs its actions without asking. To be asked again, choose an approval level with Change.',
            domain: 'novamira',
        ),
        'emptyHelp' => __(
            'When the AI is about to do something that deserves a look, it stops and asks you here. You see what it wants to do, in plain words, and you decide. Everything else runs without asking.',
            domain: 'novamira',
        ),
        'levelSaved' => __('Saved.', domain: 'novamira'),
        'levelWarningOne' => level_warning(1),
        'levelWarningMany' => level_warning(2),
        'whyAsks' => __('Why it asks', domain: 'novamira'),
        'emptyPrevious' => __('No decisions in the last 15 days.', domain: 'novamira'),
        'seeChanges' => __('See what changed, in the list above', domain: 'novamira'),
        'says' => __('The AI says it will', domain: 'novamira'),
        'means' => __('This command means', domain: 'novamira'),
        'accessTitle' => __('After this, the AI may lose access to this site', domain: 'novamira'),
        'accessUndo' => __(
            'If it does, the AI cannot come back to fix it: only a person with access to the server or the database can undo it.',
            domain: 'novamira',
        ),
        'showDetails' => __('Show details', domain: 'novamira'),
        // The AI change history and Novamira Visual's card use these.
        'showCode' => __('Show the code', domain: 'novamira'),
        'aiAsks' => __('The AI judged it needs a look', domain: 'novamira'),
        'codeLoading' => __('Loading…', domain: 'novamira'),
        /* translators: %s: path of a file. */
        'codeFile' => __('File: %s', domain: 'novamira'),
        'approveRun' => __('Approve and run', domain: 'novamira'),
        'decidedRan' => __('Approved and run.', domain: 'novamira'),
        'linked' => __('This is the request the AI sent you here for.', domain: 'novamira'),
        'created' => __('Requested', domain: 'novamira'),
        'finished' => __('Finished', domain: 'novamira'),
        'approve' => __('Approve', domain: 'novamira'),
        'reject' => __('Reject', domain: 'novamira'),
        /* translators: %s: time the rows were counted. */
        'measured' => __('Counted on the site at %s', domain: 'novamira'),
        'running' => __('Running…', domain: 'novamira'),
        'ran' => __(
            'Done: the action ran. If your AI is no longer waiting, write to it in your chat so it can read the result and continue.',
            domain: 'novamira',
        ),
        'ranTitle' => __('Done: the action ran.', domain: 'novamira'),
        'ranHelp' => __(
            'If your AI is no longer waiting, write to it in your chat so it can read the result and continue.',
            domain: 'novamira',
        ),
        'saved' => __('Settings saved.', domain: 'novamira'),
        'rulesSaved' => __(
            'Settings saved. Your AI gets the updated list with the result of its next change to the site. To use it from the very next action, start a new conversation with your AI.',
            domain: 'novamira',
        ),
        'states' => [
            'pending_confirmation' => __('Waiting for you', domain: 'novamira'),
            'approved' => sprintf(
                /* translators: %d: minutes an approval stays valid. */
                __('Approved: this call runs when the AI checks. Write to it within %d minutes', domain: 'novamira'),
                APPROVAL_TTL / 60,
            ),
            'executing' => __('Executing', domain: 'novamira'),
            'completed' => __('Completed', domain: 'novamira'),
            'failed' => __('Failed: check the AI’s result before retrying', domain: 'novamira'),
            'rejected' => __('Rejected', domain: 'novamira'),
            'canceled' => __('Canceled', domain: 'novamira'),
            'expired' => __('Expired', domain: 'novamira'),
            'session_ended' => __('Ended with the AI connection', domain: 'novamira'),
            'execution_unknown' => __('Execution interrupted: inspect the site before retrying', domain: 'novamira'),
            'not_run' => __('Not run: an action before it did not complete', domain: 'novamira'),
        ],
    ];
}

add_action('rest_api_init', static function (): void {
    // Decisions/settings require browser cookie authentication plus the REST nonce. An MCP
    // Application Password or OAuth token must never be able to approve its own queued actions.
    $permission = __NAMESPACE__ . '\\browser_permission';
    register_rest_route('novamira/v1', route: '/confirmations/runtime', args: [
        'methods' => 'POST',
        'permission_callback' => $permission,
        'callback' => static function (WP_REST_Request $request): WP_REST_Response {
            record_heartbeat(get_current_user_id());
            /** @var mixed $view */
            $view = $request->get_param('view');
            $view = $view === 'previous' ? 'previous' : 'active';
            /** @var mixed $page */
            $page = $request->get_param('page');
            $page = is_int($page) && $page > 0 ? min($page, 100_000) : 1;
            return new WP_REST_Response(array_merge(
                [
                    'runtime' => runtime(),
                    'waiting' => waiting_count(),
                ],
                dashboard_page($view, $page),
            ));
        },
    ]);
    register_rest_route('novamira/v1', route: '/confirmations/settings', args: [
        'methods' => 'POST',
        'permission_callback' => $permission,
        'callback' => __NAMESPACE__ . '\\save_settings',
    ]);
    // The exact code behind a card, for whoever can read it. Loaded only when asked, never with the heartbeat.
    register_rest_route('novamira/v1', route: '/confirmations/(?P<id>[a-f0-9]{32})/code', args: [
        'methods' => 'GET',
        'permission_callback' => $permission,
        'callback' => __NAMESPACE__ . '\\call_code',
    ]);
    register_rest_route('novamira/v1', route: '/confirmations/(?P<id>[a-f0-9]{32})/decision', args: [
        'methods' => 'POST',
        'permission_callback' => __NAMESPACE__ . '\\decision_permission',
        'callback' => __NAMESPACE__ . '\\decide',
    ]);
});

/**
 * What a request will run, as its author wrote it: the PHP, the WP-CLI command, or the file and its new content. The
 * card does not need it to be understood; it is there for whoever can read code, and for the calls Novamira cannot
 * read itself. Stored arguments are exact while a request waits, and have their secrets hidden once it is finished.
 */
function call_code(WP_REST_Request $request): WP_REST_Response|WP_Error
{
    /** @var mixed $id */
    $id = $request->get_param('id');
    $record = is_string($id) ? get_request($id) : null;
    if ($record === null) {
        return new WP_Error('not_found', __('Approval request not found.', domain: 'novamira'), ['status' => 404]);
    }
    return new WP_REST_Response(code_view($record['arguments']));
}

/**
 * @param array<array-key, mixed> $arguments
 * @return array{kind: string, path: string, text: string}
 */
function code_view(array $arguments): array
{
    $parameters = is_array($arguments['parameters'] ?? null) ? $arguments['parameters'] : [];
    unset($parameters[REVIEW_IN_PARAMETERS]);
    $path = is_string($parameters['path'] ?? null) ? $parameters['path'] : '';
    if (is_string($parameters['code'] ?? null)) {
        return ['kind' => 'php', 'path' => '', 'text' => readable_code($parameters['code'])];
    }
    if (is_array($parameters['args'] ?? null)) {
        return ['kind' => 'command', 'path' => '', 'text' => command_line($parameters['args'])];
    }
    if (is_string($parameters['content'] ?? null)) {
        return ['kind' => 'file', 'path' => $path, 'text' => $parameters['content']];
    }
    if (is_string($parameters['old_string'] ?? null) && is_string($parameters['new_string'] ?? null)) {
        $text = marked_lines('- ', $parameters['old_string']) . "\n" . marked_lines('+ ', $parameters['new_string']);
        return ['kind' => 'edit', 'path' => $path, 'text' => $text];
    }
    $json = wp_json_encode($parameters, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return ['kind' => 'call', 'path' => $path, 'text' => is_string($json) ? $json : ''];
}

/** @param array<array-key, mixed> $args */
function command_line(array $args): string
{
    return 'wp '
    . implode(' ', array_map(static fn(mixed $word): string => is_scalar($word) ? (string) $word : '', $args));
}

function marked_lines(string $mark, string $text): string
{
    return $mark . str_replace(search: "\n", replace: "\n" . $mark, subject: $text);
}

/** @return array{requests: list<array<string, mixed>>, has_more: bool} */
function dashboard_page(string $view, int $page): array
{
    if ($view !== 'previous') {
        $active = active_requests();
        // A chain of approved calls cut halfway runs on while the page is open.
        if (drive_blocks($active)) {
            $active = active_requests();
        }
        return ['requests' => active_cards($active), 'has_more' => false];
    }
    // Active rows are refreshed first so a request that just expired is stored as ended and listed here.
    active_requests();
    // One extra row tells whether another page exists, without loading the whole log.
    $records = finished_requests(26, (max(1, $page) - 1) * 25);
    return [
        'requests' => array_map(__NAMESPACE__ . '\\public_record', array_slice($records, offset: 0, length: 25)),
        'has_more' => count($records) > 25,
    ];
}

/**
 * One card per block with something still open: a request that is joining a block shows once it has its place.
 *
 * @param list<array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int}> $active
 * @return list<array<string, mixed>>
 */
function active_cards(array $active): array
{
    $cards = [];
    foreach ($active as $record) {
        $lead_id = $record['block'] ?? $record['id'];
        if (array_key_exists($lead_id, $cards)) {
            continue;
        }
        $lead = $lead_id === $record['id'] ? $record : get_request($lead_id);
        if ($lead === null || !in_array($record['id'], array_column(block_of($lead), 'id'), strict: true)) {
            continue;
        }
        $cards[$lead_id] = card_record($lead);
    }
    return array_values($cards);
}

function browser_permission(WP_REST_Request $request): bool
{
    $cookie_user = wp_validate_auth_cookie('', scheme: 'logged_in');
    return (
        $cookie_user !== false
        && $cookie_user > 0
        && $cookie_user === get_current_user_id()
        && novamira_current_user_can_manage()
        && wp_verify_nonce($request->get_header('X-WP-Nonce') ?? '', action: 'wp_rest') !== false
    );
}

/**
 * The cutoff a settings request asks for: a level, or a number (kept for callers that send one), or the current one
 * when neither is sent, so saving only the email setting keeps a number saved before levels existed.
 */
function requested_cutoff(WP_REST_Request $request, int $current): int|WP_Error
{
    /** @var mixed $level */
    $level = $request->get_param('level');
    if ($level !== null) {
        return (
            is_string($level) && array_key_exists($level, LEVELS)
                ? LEVELS[$level]
                : new WP_Error('invalid_settings', __('Choose which actions need your approval.', domain: 'novamira'), [
                    'status' => 400,
                ])
        );
    }
    /** @var mixed $cutoff */
    $cutoff = $request->get_param('cutoff');
    if ($cutoff === null) {
        return $current;
    }
    return (
        is_int($cutoff) && $cutoff >= 0 && $cutoff <= ATTENTION_MAX
            ? $cutoff
            : new WP_Error('invalid_settings', __('Use an integer threshold from 0 to 10.', domain: 'novamira'), [
                'status' => 400,
            ])
    );
}

function save_settings(WP_REST_Request $request): WP_REST_Response|WP_Error
{
    $current = settings();
    // Skipping approvals is the last answer to which actions need approval: the threshold stays as it was.
    /** @var mixed $level */
    $level = $request->get_param('level');
    $skip_chosen = $level === 'skip';
    $cutoff = $skip_chosen ? $current['cutoff'] : requested_cutoff($request, $current['cutoff']);
    if ($cutoff instanceof WP_Error) {
        return $cutoff;
    }
    /** @var mixed $instructions */
    $instructions = $request->get_param('instructions');
    if (is_string($instructions)) {
        save_approval_instructions($instructions);
    }
    /** @var mixed $forget */
    $forget = $request->get_param('forget_destinations');
    if (is_array($forget)) {
        forget_destinations($forget);
    }
    // Choosing a level is the first-time choice too: the Approvals tab stops asking for it.
    $level_chosen = $current['level_chosen'] || $request->get_param('level') !== null;
    /** @var mixed $hold_emails */
    $hold_emails = $request->get_param('hold_emails');
    $hold_emails = is_bool($hold_emails) ? $hold_emails : $current['hold_emails'];
    /** @var mixed $skip_approvals */
    $skip_approvals = $request->get_param('skip_approvals');
    if ($level !== null) {
        $skip_approvals = $skip_chosen;
    }
    $skip_approvals = is_bool($skip_approvals) ? $skip_approvals : $current['skip_approvals'];
    // Only what decides whether a call asks cancels the requests waiting: holding emails does not.
    $generation =
        $current['cutoff'] !== $cutoff || $current['skip_approvals'] !== $skip_approvals
            ? bin2hex(random_bytes(16))
            : $current['generation'];
    if (
        $generation !== $current['generation']
        || $hold_emails !== $current['hold_emails']
        || $level_chosen !== $current['level_chosen']
    ) {
        update_option(
            SETTINGS_OPTION,
            [
                'cutoff' => $cutoff,
                'generation' => $generation,
                'hold_emails' => $hold_emails,
                'skip_approvals' => $skip_approvals,
                'level_chosen' => $level_chosen,
            ],
            autoload: false,
        );
        requests();
    }
    return new WP_REST_Response(runtime());
}

/**
 * The nonce a decision carries: printed only on AI Activity, so a script running on another admin page, where WordPress
 * prints the REST nonce, cannot decide.
 */
const DECISION_NONCE_ACTION = 'novamira_confirmation_decision';

/** Who can decide: the browser of someone who manages Novamira, with the nonce only AI Activity prints. */
function decision_permission(WP_REST_Request $request): bool|WP_Error
{
    if (!browser_permission($request)) {
        return false;
    }
    /** @var mixed $nonce */
    $nonce = $request->get_param('decision_nonce');
    if (is_string($nonce) && wp_verify_nonce($nonce, DECISION_NONCE_ACTION) !== false) {
        return true;
    }
    return new WP_Error('novamira_decision_nonce', __('Reload AI Activity and decide again.', domain: 'novamira'), [
        'status' => 403,
    ]);
}

function decide(WP_REST_Request $request): WP_REST_Response|WP_Error
{
    /** @var mixed $id */
    $id = $request->get_param('id');
    /** @var mixed $decision */
    $decision = $request->get_param('decision');
    if (!is_string($id) || !in_array($decision, ['approve', 'reject'], strict: true)) {
        return new WP_Error('invalid_decision', __('Choose Approve or Reject.', domain: 'novamira'), ['status' => 400]);
    }
    $record = get_request($id);
    // The card stands for its whole block, named by the first request.
    $lead_id = $record['block'] ?? $id;
    $lead = $record === null ? null : get_request($lead_id);
    if ($lead === null) {
        return new WP_Error('not_found', __('Approval request not found.', domain: 'novamira'), ['status' => 404]);
    }
    $lead = refresh_record($lead);
    if ($lead['state'] !== 'pending_confirmation') {
        return new WP_Error(
            'already_decided',
            __('This request is no longer waiting for a decision.', domain: 'novamira'),
            [
                'status' => 409,
            ],
        );
    }
    /** @var mixed $shown */
    $shown = $request->get_param('members');
    $shown = is_array($shown) ? array_values(array_filter($shown, callback: 'is_string')) : [$lead_id];
    $decided = decide_block($lead, $decision === 'approve', $shown);
    if (is_wp_error($decided)) {
        return $decided;
    }
    if ($decision === 'approve') {
        drive_block($lead_id);
    }
    return new WP_REST_Response(card_record(get_request($lead_id) ?? $lead));
}

/**
 * Decide every request of a block at once. The block is sealed first, so a call arriving now is either on the card
 * the person saw or withdraws itself. A card that no longer matches what waits is drawn again before anything runs.
 *
 * @param array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int} $lead
 * @param list<string> $shown The requests the card showed.
 */
// @mago-expect lint:no-boolean-flag-parameter -- Approve or reject, as the person chose.
function decide_block(array $lead, bool $approve, array $shown): ?WP_Error
{
    $header = BLOCK_PREFIX . $lead['id'];
    $seal = 'decided:' . (string) time();
    $sealed =
        !array_key_exists('block', $lead)
        || swap_row($header, from: 'open', to: $seal)
        || take_stale_seal($header, $seal);
    if (!$sealed) {
        return decision_raced();
    }
    $pending = array_values(array_filter(
        block_of($lead),
        static fn(array $member): bool => $member['state'] === 'pending_confirmation',
    ));
    $changed = card_changed($pending, $approve, $shown);
    if ($changed !== null) {
        if (array_key_exists('block', $lead)) {
            swap_row($header, from: $seal, to: 'open');
        }
        return $changed;
    }
    return apply_decision($pending, $approve) > 0 ? null : decision_raced();
}

/** How long a decision may take before a block sealed by it, with requests still waiting, can be decided again. */
const SEAL_TIMEOUT = 120;

/**
 * Take over the seal of a decision that stopped halfway, when the request deciding it was cut: its block would stay
 * sealed with requests still waiting, and no one could decide them. A seal younger than SEAL_TIMEOUT belongs to a
 * decision still running.
 */
function take_stale_seal(string $header, string $seal): bool
{
    $current = key_value($header);
    if ($current === null || !str_starts_with($current, 'decided')) {
        return false;
    }
    $since = (int) substr($current, offset: strlen('decided:'));
    if (($since + SEAL_TIMEOUT) > time()) {
        return false;
    }
    return swap_row($header, $current, $seal);
}

/**
 * Whether what waits is still what the card showed: the same requests, with the same numbers.
 *
 * @param list<array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int}> $pending
 * @param list<string> $shown
 */
// @mago-expect lint:no-boolean-flag-parameter -- Only an approval counts the rows again.
function card_changed(array $pending, bool $approve, array $shown): ?WP_Error
{
    $ids = array_column($pending, 'id');
    sort($ids);
    sort($shown);
    if ($ids !== $shown) {
        return new WP_Error(
            'novamira_card_changed',
            __(
                'More actions of this conversation joined this request after the page showed it. Check them and decide again.',
                domain: 'novamira',
            ),
            ['status' => 409],
        );
    }
    if (!$approve || !recount_block($pending)) {
        return null;
    }
    return new WP_Error(
        'novamira_card_changed',
        __(
            'The site changed since this request was made. Check the new numbers and approve again.',
            domain: 'novamira',
        ),
        ['status' => 409],
    );
}

/**
 * Approve or reject each request that still waits. How many were decided: one that withdrew while the block was
 * sealed is not part of the decision.
 *
 * @param list<array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int}> $pending
 */
// @mago-expect lint:no-boolean-flag-parameter -- Approve or reject, as the person chose.
function apply_decision(array $pending, bool $approve): int
{
    $decided = 0;
    foreach ($pending as $member) {
        $updated = array_merge($member, [
            'state' => $approve ? 'approved' : 'rejected',
            'decided_by' => get_current_user_id(),
        ]);
        if ($approve) {
            $updated['approved_at'] = time();
        }
        if (!transition($member, $updated)) {
            continue;
        }
        $decided++;
        if ($approve) {
            remember_destinations_of(
                is_string($member['arguments']['ability_name'] ?? null) ? $member['arguments']['ability_name'] : '',
                is_array($member['arguments']['parameters'] ?? null) ? $member['arguments']['parameters'] : [],
            );
        }
    }
    return $decided;
}

function decision_raced(): WP_Error
{
    return new WP_Error(
        'decision_raced',
        __('Another tab updated this request. Refresh to see its decision.', domain: 'novamira'),
        ['status' => 409],
    );
}

/**
 * Count again what the cards counted: a person may decide long after they were written. Whether any number changed.
 *
 * @param list<array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int}> $pending
 */
function recount_block(array $pending): bool
{
    $changed = false;
    foreach ($pending as $member) {
        $recounted = recounted_card($member);
        if ($recounted === null) {
            continue;
        }
        transition($member, $recounted);
        $changed = true;
    }
    return $changed;
}

/**
 * A card as the page shows it: the first request of a block, with every request of the block when there are several.
 *
 * @param array{id: string, state: string, created: int, generation: string, caller: string, user_id: int, tool: string, arguments: array<array-key, mixed>, review: array{attention: int, summary?: string, details?: string, measured?: list<string>, measured_at?: int, found?: list<string>, means?: string, access?: list<string>, undo?: string, undo_reason?: string, unverified?: bool, joined_small?: bool}, session_id?: string, finished?: int, started?: int, result?: array<string, mixed>, decided_by?: int, approved_at?: int, file_hash?: string, connection?: string, scope?: string, block?: string, position?: int, block_started?: int} $lead
 * @return array<string, mixed>
 */
function card_record(array $lead): array
{
    $members = block_of($lead);
    $card = public_record($lead, count($members) > 1 ? $members : []);
    if (count($members) > 1) {
        $card['actions'] = array_map(static fn(array $member): array => public_record($member, $members), $members);
    }
    return $card;
}

function render_page(): void
{
    if (!novamira_current_user_can_manage()) {
        return;
    }
    $tab = current_tab();
    if (function_exists('novamira_render_admin_header')) {
        novamira_render_admin_header();
    }
    ?>
    <div class="wrap" id="novamira-confirmations">
        <h1><?php esc_html_e('AI Activity', domain: 'novamira'); ?></h1>
        <div id="novamira-approvals-skipped" class="nm-warn" <?php echo
            settings()['skip_approvals'] ? '' : 'hidden'
        ; ?>><?php esc_html_e('Actions run without approval: approvals are skipped.', domain: 'novamira'); ?></div>
        <?php if (!analysis_available()) { ?>
            <div class="nm-warn"><?php esc_html_e(
                'Code cannot be checked on this site: another plugin loads an incompatible version of the PHP parser Novamira uses. Every call that runs PHP waits for your approval until that is fixed.',
                domain: 'novamira',
            ); ?></div>
        <?php } ?>
        <?php

        render_tabs($tab);

        match ($tab) {
            'history' => render_history_tab(),
            'sandbox' => novamira_render_sandbox_tab(),
            'block-editor' => \Novamira\GutenbergFinalizer\render_gutenberg_finalizer_tab(),
            'settings' => render_settings_tab(),
            default => render_approvals_tab(),
        };
        ?>
    </div>
    <?php
}

/**
 * The past lives here, and Approvals keeps only what waits: what the AI changed and what was decided on what it asked,
 * in one list. With the change history off, the decisions stay on their own.
 */
function render_history_tab(): void
{
    // With the history on, its list holds the decisions too, in the order things happened.
    if (has_action('novamira_ai_activity_history')) {
        do_action('novamira_ai_activity_history');
        do_action('novamira_ai_activity_history_end');
        return;
    }
    ?>
    <p><?php esc_html_e(
        'AI change history is off, so what the AI changes is not recorded and the AI cannot undo it. Turn it on under Novamira → Features.',
        domain: 'novamira',
    ); ?></p>
    <div class="nm-bar is-section">
        <h2><?php esc_html_e('Past decisions', domain: 'novamira'); ?></h2>
    </div>
    <div id="novamira-confirmation-requests" data-view="previous"></div>
    <p id="novamira-confirmation-pagination" hidden>
        <button type="button" class="button" data-page="previous"><?php esc_html_e(
            'Newer',
            domain: 'novamira',
        ); ?></button>
        <button type="button" class="button" data-page="next"><?php esc_html_e('Older', domain: 'novamira'); ?></button>
    </p>
    <?php

    do_action('novamira_ai_activity_history_end');
}

function render_settings_tab(): void
{
    $config = settings();
    ?>
    <div class="nm-settings">
        <p id="novamira-confirmation-message" class="nm-message" role="status"></p>
        <form id="novamira-confirmation-settings">
            <h2 class="nm-settings-title"><label for="novamira-approval-instructions"><?php esc_html_e(
                'Also ask before',
                domain: 'novamira',
            ); ?></label></h2>
            <div class="nm-card">
                <p class="description"><?php esc_html_e(
                    'Actions of this site your AI should ask you about.',
                    domain: 'novamira',
                ); ?></p>
                <textarea id="novamira-approval-instructions" name="instructions" rows="4" class="large-text" maxlength="<?php echo
                    esc_attr((string) INSTRUCTIONS_MAX)
                ; ?>" placeholder="<?php esc_attr_e(
                    'Cancelling an event, since it emails the attendees, and editing product prices.',
                    domain: 'novamira',
                ); ?>"><?php echo esc_textarea(approval_instructions()); ?></textarea>
                <p class="description"><?php echo
                    wp_kses(
                        sprintf(
                            /* translators: %s: link to the Abilities Hub. */
                            __(
                                'Your AI reads this list with the scale and asks before these actions, however it does them. To make an ability ask every time, choose Always ask in the %s.',
                                domain: 'novamira',
                            ),
                            '<a href="'
                            . esc_url(admin_url('admin.php?page=novamira-abilities'))
                            . '">'
                            . esc_html__('Abilities Hub', domain: 'novamira')
                            . '</a>',
                        ),
                        ['a' => ['href' => []]],
                    )
                ; ?></p>
            </div>
            <h2 class="nm-settings-title"><?php esc_html_e('Emails and websites', domain: 'novamira'); ?></h2>
            <?php if (!history_is_on()) { ?>
                <div class="nm-warn"><?php esc_html_e(
                    'AI change history is off under Novamira → Features, so emails are not held: they go out as usual, and an email in the AI\'s own code asks first. Emails already held stay saved and show up again when you turn it back on.',
                    domain: 'novamira',
                ); ?></div>
            <?php } ?>
            <div class="nm-card">
                <label><input type="checkbox" name="hold_emails" <?php checked(
                    $config['hold_emails'],
                ); ?>> <?php esc_html_e('Hold emails while the AI works', domain: 'novamira'); ?></label>
                <p class="description"><?php esc_html_e(
                    'Emails sent through WordPress while the AI works wait under Approvals until you send or discard them. When this is off, they go out as usual, but an email sent directly by your AI\'s code still asks first, unless approvals are skipped.',
                    domain: 'novamira',
                ); ?></p>
            </div>
            <?php render_destinations_card(); ?>
            <p><button class="button button-primary" type="submit"><?php esc_html_e(
                'Save settings',
                domain: 'novamira',
            ); ?></button></p>
        </form>
    </div>
    <?php
}

/**
 * The addresses approved for the AI's own requests: approving a call adds its addresses, unticking one here and saving
 * removes it, so the next request there asks again.
 */
function render_destinations_card(): void
{
    $hosts = approved_destinations();
    ?>
    <div class="nm-card nm-destinations">
        <p class="nm-card-title"><?php esc_html_e('Approved for outside requests', domain: 'novamira'); ?></p>
        <p class="description"><?php esc_html_e(
            'When code written by your AI sends a request to another website, you are asked first, whatever approval level you chose, unless approvals are skipped. Once you approve, that website is added here and you are not asked again for it. Untick one and save to be asked again.',
            domain: 'novamira',
        ); ?></p>
        <?php if ($hosts === []) { ?>
            <p class="description"><em><?php esc_html_e('None yet.', domain: 'novamira'); ?></em></p>
        <?php } ?>
        <?php foreach ($hosts as $host) { ?>
            <label class="nm-destination"><input type="checkbox" name="destinations" value="<?php echo
                esc_attr($host)
            ; ?>" checked> <code><?php echo esc_html($host); ?></code></label>
        <?php } ?>
    </div>
    <?php
}

/**
 * Which actions ask, in one line above the requests, and the place to change it: the choice opens in place, so the
 * requests stay in view.
 */
function render_asks_before(int $cutoff): void
{
    $config = settings();
    $skip = $config['skip_approvals'];
    // While the first choice is asked above, this line waits for it.
    $hidden = !$config['level_chosen'] && !$skip;
    $level = level_of($cutoff);
    $name = $level === null ? null : level_names()[$level];
    // Saving cancels what waits: said only while something does, and kept current by the page's script.
    $waiting = count(waiting()['requests']);
    ?>
    <form class="nm-level-change" id="novamira-level-change" <?php echo $hidden ? 'hidden' : ''; ?>>
        <div class="nm-asks">
            <span class="nm-asks-label" <?php echo $skip ? 'hidden' : ''; ?>><?php esc_html_e(
                'Approval level:',
                domain: 'novamira',
            ); ?></span>
            <strong class="nm-asks-name"><?php echo
                esc_html(match (true) {
                    $skip => __('Approvals skipped', domain: 'novamira'),
                    $name !== null => $name,
                    default => sprintf(
                        /* translators: %d: the attention level from which the AI asks. */
                        __('Your earlier setting (%d)', domain: 'novamira'),
                        $cutoff,
                    ),
                })
            ; ?></strong>
            <button type="button" class="button-link nm-asks-change" aria-expanded="false" aria-controls="nm-level-panel"><?php esc_html_e(
                'Change',
                domain: 'novamira',
            ); ?></button>
        </div>
        <div class="nm-card nm-level-panel" id="nm-level-panel" hidden>
            <?php render_level_choice($cutoff, id: 'nm-levels');
            render_level_details($cutoff); ?>
            <p class="nm-level-warning" <?php echo $waiting === 0 ? 'hidden' : ''; ?>><?php echo
                esc_html(level_warning($waiting))
            ; ?></p>
            <p class="nm-actions">
                <button class="button button-primary" type="submit"><?php esc_html_e(
                    'Save',
                    domain: 'novamira',
                ); ?></button>
                <button class="button nm-level-cancel" type="button"><?php esc_html_e(
                    'Cancel',
                    domain: 'novamira',
                ); ?></button>
            </p>
        </div>
    </form>
    <?php
}

/** What saving another level does to the requests waiting for a decision. */
function level_warning(int $waiting): string
{
    return sprintf(
        /* translators: %s: number of requests. */
        _n(
            single: 'Saving cancels the %s request waiting for a decision.',
            plural: 'Saving cancels the %s requests waiting for a decision.',
            number: $waiting,
            domain: 'novamira',
        ),
        number_format_i18n($waiting),
    );
}

function render_approvals_tab(): void
{
    $config = settings();
    $first = !$config['level_chosen'] && !$config['skip_approvals'] && !novamira_is_read_mode();
    render_skip_dialog();
    ?>
    <p id="novamira-confirmation-message" class="nm-message" role="status"></p>
    <?php if ($first) { ?>
        <form class="nm-card nm-first" id="novamira-level-first">
            <?php render_level_choice($config['cutoff'], id: 'nm-first-levels');
            render_level_details($config['cutoff']); ?>
            <p class="nm-actions">
                <button class="button button-primary" type="submit"><?php esc_html_e(
                    'Save this choice',
                    domain: 'novamira',
                ); ?></button>
                <span class="description"><?php esc_html_e(
                    'You can change it any time on this page.',
                    domain: 'novamira',
                ); ?></span>
            </p>
        </form>
    <?php } ?>
    <?php if (!novamira_is_read_mode()) {
        render_asks_before($config['cutoff']);
    } ?>
    <div class="nm-bar is-quiet">
        <span id="novamira-confirmation-connection" class="nm-live" role="status"></span>
    </div>
    <div id="novamira-confirmation-requests" data-view="active"></div>
    <?php

    do_action('novamira_ai_activity_held_emails');
}
