<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Emails sent with wp_mail() wait for a person to release or discard them, and answer as if
 * they succeeded, so the code does not look for another way to send. Requests to other services are not held: Novamira
 * reacts to what the AI does, not to how the site reacts, so they go out and are counted. A request the AI's own code
 * makes is asked about before that code runs.
 */

namespace Novamira\Journal;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The execution whose effects are being held: the AI's running execution, or the one that scheduled the cron
 * event now running. '' when nothing is held.
 */
function holding_execution(): string
{
    $state = state();
    if ($state['cron_execution'] !== '') {
        return $state['cron_execution'];
    }
    return $state['active'] && $state['confirming'] ? $state['execution'] : '';
}

/**
 * @param mixed $return
 * @param mixed $atts
 * @return mixed
 */
function hold_mail($return, $atts)
{
    $state = &state();
    $execution = holding_execution();
    if ($execution === '' || $state['internal'] || $return !== null || !is_array($atts)) {
        return $return;
    }
    $saved = add_entry([
        'execution' => $execution,
        'kind' => 'mail',
        'old_value' => readable_text([
            'to' => $atts['to'] ?? '',
            'subject' => $atts['subject'] ?? '',
            'message' => $atts['message'] ?? '',
            'headers' => $atts['headers'] ?? '',
            'attachments' => $atts['attachments'] ?? [],
            'attachment_hashes' => attachment_hashes($atts['attachments'] ?? []),
        ]),
        'status' => 'held',
    ]);
    // An email that could not be kept is reported as not sent: saying it went out would lose it without a trace.
    if ($saved === 0) {
        return false;
    }
    $state['mail']++;
    return true;
}

/**
 * Without confirmations emails go out: they are only counted, so the history can say they left. Nothing they hold is kept.
 *
 * @param mixed $return
 * @return mixed
 */
function count_mail($return)
{
    $state = &state();
    if ($return === null && !$state['internal']) {
        $state['sent_mail']++;
    }
    return $return;
}

/**
 * Requests to other services go out: those other than GET and HEAD are counted, so the history can say they left.
 *
 * @param mixed $preempt
 * @param mixed $args
 * @return mixed
 */
function count_http($preempt, $args)
{
    $state = &state();
    $method = strtoupper(is_array($args) && is_string($args['method'] ?? null) ? $args['method'] : 'GET');
    if ($preempt === false && !$state['internal'] && !in_array($method, ['GET', 'HEAD'], strict: true)) {
        $state['sent_http']++;
    }
    return $preempt;
}

/**
 * The files an email attaches, by path, with what each held when the email was held. Files are not copied: a plugin may
 * delete a generated file, or put something else at its path, and an email must not go out with what nobody wrote it
 * for. A file that was already missing has an empty hash.
 *
 * @return array<string, string>
 */
function attachment_hashes(mixed $attachments): array
{
    $paths = is_array($attachments)
        ? $attachments
        : explode("\n", str_replace("\r\n", replace: "\n", subject: is_string($attachments) ? $attachments : ''));
    $hashes = [];
    // @mago-expect analysis:mixed-assignment -- Whatever the caller gave wp_mail(), checked to be a string just below.
    foreach ($paths as $path) {
        if (!is_string($path) || $path === '') {
            continue;
        }
        $hash = is_file($path) ? hash_file('sha256', $path) : false;
        $hashes[$path] = is_string($hash) ? $hash : '';
    }
    return $hashes;
}

/**
 * Whether a held email's attachments are still the files it was held with.
 *
 * @param array<array-key, mixed> $mail
 */
function attachments_changed(array $mail): bool
{
    return attachment_hashes($mail['attachments'] ?? []) !== ($mail['attachment_hashes'] ?? []);
}

/**
 * Send a held email now, or discard it.
 */
// @mago-expect lint:no-boolean-flag-parameter -- Release or discard is the decision itself.
function decide_mail(int $id, bool $release): bool
{
    if (state()['active']) {
        return false;
    }
    $found = entries('id = %d AND kind = %s', [$id, 'mail']);
    $status = $found[0]['status'] ?? '';
    // An email left in "sending" means the request died while sending it, or is still sending it: it is not known
    // whether it went out, so it cannot be sent again from here, only closed.
    if ($status === 'sending' && !$release) {
        return (
            claim_entry($id, from: 'sending', to: 'discarded') && forget_mail_text($id, $found[0]['old_value'] ?? null)
        );
    }
    // @mago-expect analysis:mixed-assignment -- Stored JSON; checked to be an array below.
    $mail = $found[0]['old_value'] ?? null;
    if ($release && is_array($mail) && attachments_changed($mail)) {
        return false;
    }
    // Claimed atomically: when two people click at once, only one of them sends or discards it.
    if ($status !== 'held' || !claim_entry($id, from: 'held', to: 'sending')) {
        return false;
    }
    if (!$release || !is_array($mail)) {
        update_entry($id, ['status' => 'discarded']);
        forget_mail_text($id, $mail);
        return true;
    }
    return send_held_mail($id, $mail);
}

/**
 * @param array<array-key, mixed> $mail
 */
function send_held_mail(int $id, array $mail): bool
{
    /** @var string|list<string> $to */
    $to = $mail['to'] ?? '';
    /** @var string|list<string> $headers */
    $headers = $mail['headers'] ?? '';
    /** @var string|list<string> $attachments */
    $attachments = $mail['attachments'] ?? [];
    $sent = wp_mail($to, (string) ($mail['subject'] ?? ''), (string) ($mail['message'] ?? ''), $headers, $attachments);
    update_entry($id, ['status' => $sent ? 'released' : 'held']);
    if ($sent) {
        forget_mail_text($id, $mail);
    }
    return $sent;
}

/**
 * Once an email is sent or discarded, only who it was for and its subject stay: what it said, its headers and its
 * attachments' paths are no longer needed, and could hold personal data or a link that signs someone in.
 */
function forget_mail_text(int $id, mixed $mail): bool
{
    $value = is_array($mail) ? $mail : [];
    $kept = wp_json_encode(['to' => $value['to'] ?? '', 'subject' => $value['subject'] ?? '']);
    return is_string($kept) && update_entry($id, ['old_value' => $kept]);
}

/**
 * @param array{id: int, status: string, ...} $entry
 * @param array<array-key, mixed> $value
 * @return array<string, mixed>
 */
function mail_view(array $entry, array $value): array
{
    return [
        'id' => $entry['id'],
        'status' => $entry['status'],
        'to' => $value['to'] ?? '',
        'subject' => $value['subject'] ?? '',
        'attachments_changed' => $entry['status'] === 'held' && attachments_changed($value),
    ];
}

/**
 * The emails waiting for a person, oldest first: held, or left sending by a request that died.
 *
 * @return list<array<string, mixed>>
 */
function held_mails(): array
{
    if (!table_ready()) {
        return [];
    }
    $mails = [];
    foreach (entries(WAITING_MAIL . ' AND id > %d', [0]) as $entry) {
        $value = is_array($entry['old_value'] ?? null) ? $entry['old_value'] : [];
        // The person deciding reads what the email says; the AI's view of the history never gets it.
        $mails[] = [
            ...mail_view($entry, $value),
            'message' => is_string($value['message'] ?? null) ? $value['message'] : '',
        ];
    }
    return $mails;
}

/** How many emails wait. The menu asks on every admin page: counted through an index, without reading any email. */
function held_mail_count(): int
{
    if (!table_ready()) {
        return 0;
    }
    return (int) own_var(prepared('SELECT COUNT(*) FROM %i WHERE ' . WAITING_MAIL, [table()]));
}
