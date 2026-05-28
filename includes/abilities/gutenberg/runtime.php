<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Abilities\Gutenberg;

use WP_Post;

if (!defined('ABSPATH')) {
    exit();
}

function finalizer_runtime_startup_instruction(): string
{
    $runtime = finalizer_runtime_status();
    if (($runtime['online'] ?? false) === true) {
        return sprintf(
            'The Novamira Block Editor Queue page is open. Keep %s open while Gutenberg static/native changes are queued and finalized. You can stream %s with curl -N, or poll %s with curl, to check whether the page is still online. If a later status shows finalizer_runtime.online=false, ask the user to reopen it before treating queued changes as live.',
            finalizer_dashboard_url(),
            (string) ($runtime['sse_url'] ?? finalizer_runtime_sse_url()),
            (string) ($runtime['poll_url'] ?? finalizer_runtime_poll_url()),
        );
    }

    return sprintf(
        'The Novamira Block Editor Queue page is not currently online. Before queueing Gutenberg static/native changes, ask the user to open %s in wp-admin and keep it open while you work. Stream %s with curl -N, or poll %s with curl; if it stays or becomes offline, ask the user to reopen it before treating queued changes as live.',
        finalizer_dashboard_url(),
        (string) ($runtime['sse_url'] ?? finalizer_runtime_sse_url()),
        (string) ($runtime['poll_url'] ?? finalizer_runtime_poll_url()),
    );
}

function finalizer_runtime_poll_token(): string
{
    /** @var mixed $option_value */
    $option_value = get_option(FINALIZER_RUNTIME_POLL_TOKEN_OPTION, default_value: '');
    if (is_string($option_value) && preg_match('/^[A-Za-z0-9_-]{22}$/', $option_value) === 1) {
        return $option_value;
    }

    $token = rtrim(
        strtr(base64_encode(random_bytes(FINALIZER_RUNTIME_POLL_TOKEN_BYTES)), from: '+/', to: '-_'),
        characters: '=',
    );
    update_option(FINALIZER_RUNTIME_POLL_TOKEN_OPTION, $token, autoload: false);

    return $token;
}

function finalizer_runtime_batch_token(int $batch_id): string
{
    return substr(hash_hmac('sha256', (string) $batch_id, finalizer_runtime_poll_token()), offset: 0, length: 32);
}

function finalizer_runtime_batch_token_is_valid(int $batch_id, string $token): bool
{
    return $batch_id > 0 && $token !== '' && hash_equals(finalizer_runtime_batch_token($batch_id), $token);
}

/**
 * @return array<string, string|int>
 */
function finalizer_runtime_url_args(?int $batch_id = null): array
{
    $args = ['token' => finalizer_runtime_poll_token()];
    if ($batch_id !== null && $batch_id > 0) {
        $args['batch_id'] = $batch_id;
        $args['batch_token'] = finalizer_runtime_batch_token($batch_id);
    }

    return $args;
}

function finalizer_runtime_poll_url(?int $batch_id = null): string
{
    return add_query_arg(
        finalizer_runtime_url_args($batch_id),
        rest_url('novamira/v1/gutenberg/finalizer-runtime/status'),
    );
}

function finalizer_runtime_sse_url(?int $batch_id = null): string
{
    return add_query_arg(
        finalizer_runtime_url_args($batch_id),
        rest_url('novamira/v1/gutenberg/finalizer-runtime/events'),
    );
}

/**
 * @return array<string, mixed>
 */
function record_finalizer_runtime_heartbeat(): array
{
    $user_id = get_current_user_id();
    if ($user_id <= 0) {
        return finalizer_runtime_status();
    }

    $records = finalizer_runtime_records();
    $records[(string) $user_id] = [
        'user_id' => $user_id,
        'last_seen' => time(),
        'last_seen_at' => now_mysql(),
    ];

    set_transient(FINALIZER_RUNTIME_TRANSIENT, $records, FINALIZER_RUNTIME_TTL_SECONDS);

    return finalizer_runtime_status();
}

/**
 * @return array<string, mixed>
 */
function finalizer_runtime_status(?WP_Post $batch = null): array
{
    $all_records = finalizer_runtime_records();
    $records = finalizer_runtime_online_records();
    $can_finalize_batch = false;

    if ($batch instanceof WP_Post) {
        foreach ($records as $record) {
            $user_id = is_scalar($record['user_id'] ?? null) ? (int) $record['user_id'] : 0;
            if ($user_id > 0 && finalizer_runtime_user_can_finalize_batch($user_id, $batch)) {
                $can_finalize_batch = true;
                break;
            }
        }
    }

    return [
        'online' => $records !== [],
        'can_finalize_batch' => $batch instanceof WP_Post ? $can_finalize_batch : null,
        'online_runtime_count' => count($records),
        'dashboard_url' => finalizer_dashboard_url(),
        'poll_url' => finalizer_runtime_poll_url($batch instanceof WP_Post ? $batch->ID : null),
        'sse_url' => finalizer_runtime_sse_url($batch instanceof WP_Post ? $batch->ID : null),
        'last_seen_at' => finalizer_runtime_last_seen_at($records),
        'last_known_seen_at' => finalizer_runtime_last_seen_at(array_values($all_records)),
        'offline_reason' => finalizer_runtime_offline_reason($records, $all_records),
        'stale_after_seconds' => FINALIZER_RUNTIME_STALE_SECONDS,
    ];
}

/**
 * @return array<string, array<string, mixed>>
 */
function finalizer_runtime_records(): array
{
    /** @var mixed $raw */
    $raw = get_transient(FINALIZER_RUNTIME_TRANSIENT);
    if (!is_array($raw)) {
        return [];
    }

    $raw_records = array_filter($raw, static fn(mixed $record): bool => is_array($record));
    /** @var array<array-key, array<array-key, mixed>> $raw_records */

    $records = [];
    foreach ($raw_records as $key => $record) {
        $string_record = array_filter(
            $record,
            static fn(mixed $record_value, mixed $record_key): bool => is_string($record_key),
            ARRAY_FILTER_USE_BOTH,
        );
        /** @var array<string, mixed> $string_record */

        $records[(string) $key] = typed_string_map($string_record);
    }

    return $records;
}

/**
 * @return list<array<string, mixed>>
 */
function finalizer_runtime_online_records(): array
{
    $cutoff = time() - FINALIZER_RUNTIME_STALE_SECONDS;
    $records = [];

    foreach (finalizer_runtime_records() as $record) {
        $last_seen = is_scalar($record['last_seen'] ?? null) ? (int) $record['last_seen'] : 0;
        if ($last_seen < $cutoff) {
            continue;
        }

        $records[] = $record;
    }

    return $records;
}

/**
 * @param list<array<string, mixed>> $online_records
 * @param array<string, array<string, mixed>> $all_records
 */
function finalizer_runtime_offline_reason(array $online_records, array $all_records): string
{
    if ($online_records !== []) {
        return '';
    }

    if ($all_records !== []) {
        return 'last_heartbeat_stale';
    }

    return 'not_seen';
}

/**
 * @param list<array<string, mixed>> $records
 */
function finalizer_runtime_last_seen_at(array $records): string
{
    $latest_seen = 0;
    $latest_seen_at = '';

    foreach ($records as $record) {
        $last_seen = is_scalar($record['last_seen'] ?? null) ? (int) $record['last_seen'] : 0;
        if ($last_seen <= $latest_seen) {
            continue;
        }

        $latest_seen = $last_seen;
        $latest_seen_at = is_scalar($record['last_seen_at'] ?? null) ? (string) $record['last_seen_at'] : '';
    }

    return $latest_seen_at;
}

function finalizer_runtime_user_can_finalize_batch(int $user_id, WP_Post $batch): bool
{
    if (novamira_user_can_manage($user_id)) {
        return true;
    }

    foreach (get_items($batch->ID) as $item) {
        $target_id = meta_int($item->ID, META_TARGET_ID);
        if ($target_id <= 0 || !user_can($user_id, 'edit_post', $target_id)) {
            return false;
        }
    }

    return true;
}
