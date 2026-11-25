<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * The journal in the AI Activity page: recent AI requests with what they changed, and held emails to release or
 * discard. Work is restored by the AI, with a new change: this page only prepares that request. Only a
 * logged-in administrator in the browser can use it, never the AI connection.
 */

namespace Novamira\Journal;

use Novamira\Confirmations;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit();
}

add_action('rest_api_init', static function (): void {
    $permission = 'Novamira\\Confirmations\\browser_permission';
    register_rest_route('novamira/v1', route: '/journal', args: [
        'methods' => 'GET',
        'permission_callback' => $permission,
        'callback' => static function (WP_REST_Request $request): WP_REST_Response {
            /** @var mixed $page */
            $page = $request->get_param('page');
            return new WP_REST_Response(list_operations(is_numeric($page) ? max(1, (int) $page) : 1));
        },
    ]);
    register_rest_route('novamira/v1', route: '/journal/held', args: [
        'methods' => 'GET',
        'permission_callback' => $permission,
        'callback' => static fn(): WP_REST_Response => new WP_REST_Response(['emails' => held_mails()]),
    ]);
    register_rest_route('novamira/v1', route: '/journal/clear', args: [
        'methods' => 'POST',
        'permission_callback' => $permission,
        'callback' => static function (): WP_REST_Response {
            clear();
            return new WP_REST_Response(['cleared' => true]);
        },
    ]);
    register_rest_route('novamira/v1', route: '/journal/mail/(?P<id>\d+)', args: [
        'methods' => 'POST',
        'permission_callback' => $permission,
        'callback' => static function (WP_REST_Request $request): WP_REST_Response|WP_Error {
            /** @var mixed $action */
            $action = $request->get_param('action');
            if (!in_array($action, ['release', 'discard'], strict: true)) {
                return new WP_Error('invalid_action', __('Choose Send or Discard.', domain: 'novamira'), [
                    'status' => 400,
                ]);
            }
            /** @var mixed $id */
            $id = $request->get_param('id');
            $done = decide_mail(is_numeric($id) ? (int) $id : 0, $action === 'release');
            return new WP_REST_Response(['done' => $done]);
        },
    ]);
});

add_action('admin_enqueue_scripts', static function (): void {
    if (($_GET['page'] ?? '') !== Confirmations\PAGE_SLUG || !novamira_current_user_can_manage()) {
        return;
    }
    wp_enqueue_script(
        'novamira-journal',
        (string) NOVAMIRA_PLUGIN_URL . 'assets/journal.js',
        // The list shows the decisions too, with the approvals' words.
        ['wp-api-fetch', 'novamira-confirmations'],
        Confirmations\asset_version(dirname(__DIR__, levels: 2) . '/assets/journal.js'),
        args: true,
    );
    wp_add_inline_script(
        'novamira-journal',
        'window.novamiraJournal = '
        . (string) wp_json_encode([
            'path' => '/novamira/v1/journal',
            'me' => user_name(get_current_user_id()),
            'strings' => page_strings(),
        ])
        . ';',
        position: 'before',
    );
});

add_action('novamira_ai_activity_held_emails', static function (): void { ?>
    <div id="novamira-held-emails-section" hidden>
        <div class="nm-bar is-section">
            <h2><?php esc_html_e('Held emails', domain: 'novamira'); ?></h2>
            <span id="novamira-held-emails-count" class="nm-count"></span>
        </div>
        <div id="novamira-held-emails" aria-live="polite"></div>
    </div>
    <?php });

add_action('novamira_ai_activity_history', static function (): void { ?>
    <p class="nm-lead"><?php esc_html_e(
        'What the AI changed on this site and what was decided on what it asked, newest first. To undo something, copy it for your AI: nothing changes from this page.',
        domain: 'novamira',
    ); ?></p>
    <details class="nm-help">
        <summary><?php esc_html_e('How restoring works, and what is kept', domain: 'novamira'); ?></summary>
        <div>
            <p><?php esc_html_e(
                'Novamira records the database rows each AI request changes, with their previous values. To restore, the AI reads them here and makes a new change with the functions WordPress and your plugins use, so their hooks and caches keep up. It is a change like any other: it is recorded too, and it can be wrong, so it is not an exact rollback.',
                domain: 'novamira',
            ); ?></p>
            <p><?php esc_html_e(
                'Not covered: changes made outside $wpdb, files, emails already sent and outgoing requests already made. Each item says when some of these happened.',
                domain: 'novamira',
            ); ?></p>
            <p><?php esc_html_e(
                'Saved rows never include password hashes, password reset keys, login sessions, application passwords or the site\'s secret keys. A copy of the users table, made before the AI drops, empties or alters it, does keep password hashes, so restoring from it does not lock everyone out. Everything else the AI changed is kept with its previous value, personal data included, and so is what each call ran, with secrets hidden, for 30 days or until you empty it, so the AI can read it when you ask it to restore something. What people ask the AI is not kept.',
                domain: 'novamira',
            ); ?></p>
            <p><?php esc_html_e(
                'Recording one write can keep up to 50,000 rows and 256 MB of previous values in the database. The history keeps at most 200,000 rows, for 30 days. Decisions on what the AI asked are kept for 15 days.',
                domain: 'novamira',
            ); ?></p>
            <p><?php esc_html_e(
                'These records stay on this site: Novamira does not receive them. The site owner is responsible for them as for any other data on the site.',
                domain: 'novamira',
            ); ?></p>
        </div>
    </details>
    <div id="novamira-journal" aria-live="polite"></div>
    <?php });

// Last on the tab, after the past decisions, which emptying the history does not touch.
add_action('novamira_ai_activity_history_end', static function (): void { ?>
    <div class="nm-danger-zone">
        <p><?php esc_html_e(
            'Emptying the history deletes every recorded change and table copy: the AI can no longer read what was done so far.',
            domain: 'novamira',
        ); ?></p>
        <button type="button" class="button button-reject" id="novamira-journal-clear"><?php esc_html_e(
            'Empty the AI change history',
            domain: 'novamira',
        ); ?></button>
    </div>
    <?php });

/**
 * @return array<string, string>
 */
function page_strings(): array
{
    return [
        'copyPrompt' => __('Copy to restore with AI', domain: 'novamira'),
        'details' => __('Details', domain: 'novamira'),
        /* translators: %d: number of steps the AI took for one request. */
        'operations' => __('%d steps', domain: 'novamira'),
        'older' => __('Older', domain: 'novamira'),
        'copied' => __('Copied. Paste it into your AI chat.', domain: 'novamira'),
        'copyByHand' => __('Your browser blocked copying. Select the text below and copy it:', domain: 'novamira'),
        'prompt' => __(
            'I want to restore operation %1$s (part of request %2$s) of the Novamira AI change history. Look at what that operation did, if it is not the latest operation ask me whether to undo only this or also what came after it, explain what you would change and what you could not bring back, and wait for my OK. Reply in the language we have been using in this conversation.',
            domain: 'novamira',
        ),
        'changed' => __('What changed', domain: 'novamira'),
        'notCovered' => __('Not covered by the history', domain: 'novamira'),
        'today' => __('Today', domain: 'novamira'),
        'yesterday' => __('Yesterday', domain: 'novamira'),
        'noChanges' => __('No database changes recorded.', domain: 'novamira'),
        /* translators: %s: the path of a file. */
        'fileChanged' => __('Changed %s', domain: 'novamira'),
        'filesChanged' => __('Changed files', domain: 'novamira'),
        'filesNote' => __('The history does not keep the previous content of files.', domain: 'novamira'),
        'state_completed' => __('Completed', domain: 'novamira'),
        'by' => __('By', domain: 'novamira'),
        /* translators: %s: the name of the person who approved. */
        'approvedBy' => __('Approved by %s', domain: 'novamira'),
        'aiWords' => __('In the AI\'s words, not verified', domain: 'novamira'),
        'loadMore' => __('Show older', domain: 'novamira'),
        'empty' => __('Nothing the AI did in the last 30 days.', domain: 'novamira'),
        'unverified' => __('Calls with no previous values saved', domain: 'novamira'),
        'description' => __('The AI says about its request (not verified):', domain: 'novamira'),
        'technical' => __('other technical changes', domain: 'novamira'),
        'state_running' => __('Running', domain: 'novamira'),
        'state_halted' => __('Stopped', domain: 'novamira'),
        'state_failed' => __('Failed', domain: 'novamira'),
        'state_interrupted' => __('Interrupted', domain: 'novamira'),
        'sentEmails' => __(
            'Emails the site tried to send during this work (not held and not recoverable)',
            domain: 'novamira',
        ),
        'sentRequests' => __(
            'Outgoing requests the site tried to make during this work (not recoverable)',
            domain: 'novamira',
        ),
        'emails' => __('Held emails', domain: 'novamira'),
        'onHold' => __('Email on hold', domain: 'novamira'),
        'heldHere' => __('Emails on hold: send or discard them under Approvals', domain: 'novamira'),
        'to' => __('To', domain: 'novamira'),
        'attachmentsChanged' => __(
            'An attachment changed or no longer exists, so this email cannot be sent. Discard it, and ask the AI to send it again if it is still needed.',
            domain: 'novamira',
        ),
        'send' => __('Send', domain: 'novamira'),
        'showEmail' => __('Show the email', domain: 'novamira'),
        'discard' => __('Discard', domain: 'novamira'),
        'sendingUnknown' => __(
            'Sending was interrupted or is still running: it is not known whether this email went out. Check before sending it again yourself.',
            domain: 'novamira',
        ),
        'closeEmail' => __('Close', domain: 'novamira'),
        'clear' => __('Empty the AI change history', domain: 'novamira'),
        'confirmClear' => __(
            'Delete every recorded change and table copy? The AI will no longer be able to read what was done so far. Emails still waiting stay under Approvals, until you send or discard them.',
            domain: 'novamira',
        ),
    ] + list_strings();
}

/**
 * The words of the list itself: badges, search and filters.
 *
 * @return array<string, string>
 */
function list_strings(): array
{
    return [
        'ran' => __('Ran', domain: 'novamira'),
        'showDecisions' => __('Show rejected and expired requests', domain: 'novamira'),
        'partlyRestorable' => __('Partly restorable', domain: 'novamira'),
        'notRestorable' => __('Not restorable', domain: 'novamira'),
        'notSavedOne' => __(
            '1 change has no previous value saved: it cannot be put back from the history.',
            domain: 'novamira',
        ),
        /* translators: %d: number of database changes without a saved previous value. */
        'notSavedMany' => __(
            '%d changes have no previous value saved: they cannot be put back from the history.',
            domain: 'novamira',
        ),
        'you' => __('you', domain: 'novamira'),
        /* translators: %s: the name of the person who asked the AI. */
        'askedBy' => __('Asked by %s', domain: 'novamira'),
        /* translators: %s: what the AI said its latest step of a request does. */
        'lastStep' => __('Last: %s', domain: 'novamira'),
        'search' => __('Search the history', domain: 'novamira'),
        'day' => __('Day', domain: 'novamira'),
        'clearFilters' => __('Clear search', domain: 'novamira'),
        'noMatch' => __('Nothing matches these filters in the history loaded so far.', domain: 'novamira'),
    ];
}
