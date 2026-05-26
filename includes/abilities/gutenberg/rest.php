<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Abilities\Gutenberg;

use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit();
}

add_action('rest_api_init', __NAMESPACE__ . '\\register_rest_routes');

function register_rest_routes(): void
{
    register_rest_route(route_namespace: 'novamira/v1', route: '/gutenberg/batches', args: [
        'methods' => 'GET',
        'callback' => __NAMESPACE__ . '\\rest_list_batches',
        'permission_callback' => __NAMESPACE__ . '\\rest_can_access_dashboard',
    ]);

    register_rest_route(route_namespace: 'novamira/v1', route: '/gutenberg/finalizer-runtime/heartbeat', args: [
        'methods' => 'POST',
        'callback' => __NAMESPACE__ . '\\rest_finalizer_runtime_heartbeat',
        'permission_callback' => __NAMESPACE__ . '\\rest_can_access_dashboard',
    ]);

    register_rest_route(route_namespace: 'novamira/v1', route: '/gutenberg/finalizer-runtime/status', args: [
        'methods' => 'GET',
        'callback' => __NAMESPACE__ . '\\rest_poll_finalizer_runtime_status',
        'permission_callback' => __NAMESPACE__ . '\\rest_can_poll_finalizer_runtime',
    ]);

    register_rest_route(route_namespace: 'novamira/v1', route: '/gutenberg/finalizer-runtime/events', args: [
        'methods' => 'GET',
        'callback' => __NAMESPACE__ . '\\rest_stream_finalizer_runtime_events',
        'permission_callback' => __NAMESPACE__ . '\\rest_can_poll_finalizer_runtime',
    ]);

    register_rest_route(route_namespace: 'novamira/v1', route: '/gutenberg/batches/(?P<batch_id>\d+)/claim', args: [
        'methods' => 'POST',
        'callback' => __NAMESPACE__ . '\\rest_claim_batch',
        'permission_callback' => __NAMESPACE__ . '\\rest_can_access_batch',
    ]);

    register_rest_route(
        route_namespace: 'novamira/v1',
        route: '/gutenberg/batches/(?P<batch_id>\d+)/items/claim-next',
        args: [
            'methods' => 'POST',
            'callback' => __NAMESPACE__ . '\\rest_claim_next_item',
            'permission_callback' => __NAMESPACE__ . '\\rest_can_access_batch',
        ],
    );

    register_rest_route(route_namespace: 'novamira/v1', route: '/gutenberg/items/(?P<item_id>\d+)/spec', args: [
        'methods' => 'GET',
        'callback' => __NAMESPACE__ . '\\rest_get_item_spec',
        'permission_callback' => __NAMESPACE__ . '\\rest_can_access_item',
    ]);

    register_rest_route(route_namespace: 'novamira/v1', route: '/gutenberg/items/(?P<item_id>\d+)/complete', args: [
        'methods' => 'POST',
        'callback' => __NAMESPACE__ . '\\rest_complete_item',
        'permission_callback' => __NAMESPACE__ . '\\rest_can_access_item',
    ]);

    register_rest_route(route_namespace: 'novamira/v1', route: '/gutenberg/items/(?P<item_id>\d+)/fail', args: [
        'methods' => 'POST',
        'callback' => __NAMESPACE__ . '\\rest_fail_item',
        'permission_callback' => __NAMESPACE__ . '\\rest_can_access_item',
    ]);

    register_rest_route(route_namespace: 'novamira/v1', route: '/gutenberg/batches/(?P<batch_id>\d+)/cancel', args: [
        'methods' => 'POST',
        'callback' => __NAMESPACE__ . '\\rest_cancel_batch',
        'permission_callback' => __NAMESPACE__ . '\\rest_can_access_batch',
    ]);
}

function rest_can_access_dashboard(): bool|WP_Error
{
    if (!current_user_can('edit_posts')) {
        return new WP_Error('rest_forbidden', 'You are not allowed to access the Block Editor Queue dashboard.', [
            'status' => 403,
        ]);
    }

    return true;
}

function rest_can_poll_finalizer_runtime(WP_REST_Request $request): bool|WP_Error
{
    $token = rest_query_string_param($request, name: 'token');
    if ($token === '' || !hash_equals(finalizer_runtime_poll_token(), $token)) {
        return new WP_Error('rest_forbidden', 'The Block Editor Queue status token is invalid.', [
            'status' => 403,
        ]);
    }

    return true;
}

function rest_can_access_batch(WP_REST_Request $request): bool|WP_Error
{
    $batch_id = rest_int_param($request, name: 'batch_id');
    $batch = find_batch($batch_id);
    if (!$batch instanceof WP_Post) {
        return new WP_Error('gutenberg_batch_not_found', sprintf('Gutenberg batch %d was not found.', $batch_id), [
            'status' => 404,
        ]);
    }

    if (!current_user_can_finalize_batch($batch)) {
        return new WP_Error('rest_forbidden', 'You are not allowed to finalize this Gutenberg batch.', [
            'status' => 403,
        ]);
    }

    return true;
}

function rest_can_access_item(WP_REST_Request $request): bool|WP_Error
{
    $item_id = rest_int_param($request, name: 'item_id');
    $item = find_item($item_id);
    if (!$item instanceof WP_Post) {
        return new WP_Error('gutenberg_item_not_found', sprintf('Gutenberg item %d was not found.', $item_id), [
            'status' => 404,
        ]);
    }

    $target_id = meta_int($item->ID, META_TARGET_ID);
    if (!current_user_can('manage_options') && ($target_id <= 0 || !current_user_can('edit_post', $target_id))) {
        return new WP_Error('rest_forbidden', 'You are not allowed to finalize this Gutenberg item.', [
            'status' => 403,
        ]);
    }

    return true;
}

function rest_int_param(WP_REST_Request $request, string $name): int
{
    $params = $request->get_params();
    if (!array_key_exists($name, $params)) {
        return 0;
    }

    return is_scalar($params[$name]) ? (int) $params[$name] : 0;
}

function rest_query_int_param(WP_REST_Request $request, string $name): int
{
    $params = $request->get_query_params();
    if (!array_key_exists($name, $params)) {
        return 0;
    }

    return is_scalar($params[$name]) ? (int) $params[$name] : 0;
}

function rest_query_string_param(WP_REST_Request $request, string $name): string
{
    $params = $request->get_query_params();
    if (!array_key_exists($name, $params)) {
        return '';
    }

    return is_scalar($params[$name]) ? (string) $params[$name] : '';
}

/** @return WP_REST_Response|WP_Error */
function rest_response(array|WP_Error $value): WP_REST_Response|WP_Error
{
    if (is_wp_error($value)) {
        return $value;
    }

    return new WP_REST_Response($value);
}

/** @return WP_REST_Response|WP_Error */
function rest_list_batches(WP_REST_Request $request): WP_REST_Response|WP_Error
{
    mark_stale_drafts();

    $query_params = $request->get_query_params();
    $statuses = rest_string_list_query_param($query_params['status'] ?? null);
    $batches = get_batches($statuses !== [] ? $statuses : null, posts_per_page: 50);
    $visible_batches = [];

    foreach ($batches as $batch) {
        $batch = refresh_batch_runtime_state($batch);
        if (current_user_can_finalize_batch($batch)) {
            $visible_batches[] = shape_batch_summary($batch);
        }
    }

    return new WP_REST_Response([
        'batches' => $visible_batches,
        'finalizer_runtime' => finalizer_runtime_status(),
    ]);
}

/** @return WP_REST_Response|WP_Error */
function rest_finalizer_runtime_heartbeat(): WP_REST_Response|WP_Error
{
    return new WP_REST_Response(record_finalizer_runtime_heartbeat());
}

/** @return WP_REST_Response|WP_Error */
function rest_poll_finalizer_runtime_status(WP_REST_Request $request): WP_REST_Response|WP_Error
{
    return rest_response(rest_finalizer_runtime_status_payload(
        rest_query_int_param($request, name: 'batch_id'),
        rest_query_string_param($request, name: 'batch_token'),
    ));
}

function rest_stream_finalizer_runtime_events(WP_REST_Request $request): void
{
    $batch_id = rest_query_int_param($request, name: 'batch_id');
    $requested_interval = rest_query_int_param($request, name: 'interval');
    $requested_duration = rest_query_int_param($request, name: 'duration');
    $interval = max(1, min(10, $requested_interval > 0 ? $requested_interval : 2));
    $duration = max(5, min(25, $requested_duration > 0 ? $requested_duration : 25));
    $started_at = time();

    rest_send_sse_headers();
    echo 'retry: ' . (string) ($interval * 1000) . "\n\n";
    rest_flush_sse();

    while (!connection_aborted()) {
        $payload = rest_finalizer_runtime_status_payload($batch_id, rest_query_string_param(
            $request,
            name: 'batch_token',
        ));
        if (is_wp_error($payload)) {
            rest_sse_event('error', [
                'code' => $payload->get_error_code(),
                'message' => $payload->get_error_message(),
            ]);
            exit();
        }

        rest_sse_event('status', $payload);
        if (rest_finalizer_runtime_payload_is_terminal($payload)) {
            rest_sse_event('done', ['reason' => 'terminal']);
            exit();
        }

        if ((time() - $started_at + $interval) >= $duration) {
            rest_sse_event('reconnect', [
                'reason' => 'connection_duration_limit',
                'after_seconds' => 1,
            ]);
            exit();
        }

        sleep($interval);
    }

    exit();
}

/**
 * @return array<string, mixed>|WP_Error
 */
function rest_finalizer_runtime_status_payload(int $batch_id, string $batch_token): array|WP_Error
{
    mark_stale_drafts();

    if ($batch_id <= 0) {
        return [
            'finalizer_runtime' => finalizer_runtime_status(),
            'user_instruction' => finalizer_runtime_startup_instruction(),
        ];
    }

    $batch = find_batch($batch_id);
    if (!$batch instanceof WP_Post) {
        return new WP_Error('gutenberg_batch_not_found', sprintf('Gutenberg batch %d was not found.', $batch_id), [
            'status' => 404,
        ]);
    }

    if (!current_user_can_finalize_batch($batch) && !finalizer_runtime_batch_token_is_valid($batch->ID, $batch_token)) {
        return new WP_Error('rest_forbidden', 'The Block Editor Queue batch status token is invalid.', [
            'status' => 403,
        ]);
    }

    $batch = refresh_batch_runtime_state($batch);

    return [
        'batch' => shape_batch($batch),
        'finalizer_runtime' => finalizer_runtime_status($batch),
        'user_instruction' => user_instruction($batch),
    ];
}

/**
 * @param array<string, mixed> $payload
 */
function rest_finalizer_runtime_payload_is_terminal(array $payload): bool
{
    $batch = is_array($payload['batch'] ?? null) ? $payload['batch'] : null;
    if ($batch === null || !is_scalar($batch['status'] ?? null)) {
        return false;
    }

    return in_array(
        (string) $batch['status'],
        [
            STATUS_FINALIZED,
            STATUS_FAILED,
            STATUS_CONFLICTED,
            STATUS_CANCELED,
            STATUS_STALE,
        ],
        strict: true,
    );
}

function rest_send_sse_headers(): void
{
    if (!headers_sent()) {
        status_header(200);
        header('Content-Type: text/event-stream; charset=UTF-8');
        header('Cache-Control: no-cache, no-transform');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');
    }
}

/**
 * @param array<string, mixed> $data
 */
function rest_sse_event(string $event, array $data): void
{
    $json = wp_json_encode($data, JSON_UNESCAPED_SLASHES);
    echo 'event: ' . $event . "\n";
    echo 'data: ' . ($json !== false ? $json : '{}') . "\n\n";
    rest_flush_sse();
}

function rest_flush_sse(): void
{
    if (ob_get_level() > 0) {
        ob_flush();
    }

    flush();
}

/** @return WP_REST_Response|WP_Error */
function rest_claim_batch(WP_REST_Request $request): WP_REST_Response|WP_Error
{
    return rest_response(claim_batch(rest_int_param($request, name: 'batch_id')));
}

/** @return WP_REST_Response|WP_Error */
function rest_claim_next_item(WP_REST_Request $request): WP_REST_Response|WP_Error
{
    $params = rest_json_params($request);
    $lease_owner = is_scalar($params['lease_owner'] ?? null) ? (string) $params['lease_owner'] : '';

    return rest_response(claim_next_item(rest_int_param($request, name: 'batch_id'), $lease_owner));
}

/** @return WP_REST_Response|WP_Error */
function rest_get_item_spec(WP_REST_Request $request): WP_REST_Response|WP_Error
{
    $item_id = rest_int_param($request, name: 'item_id');
    $item = find_item($item_id);
    if (!$item instanceof WP_Post) {
        return new WP_Error('gutenberg_item_not_found', sprintf('Gutenberg item %d was not found.', $item_id), [
            'status' => 404,
        ]);
    }

    $query_params = $request->get_query_params();
    $lease_owner = array_key_exists('lease_owner', $query_params) && is_scalar($query_params['lease_owner'])
        ? (string) $query_params['lease_owner']
        : '';
    if (status($item->ID) !== STATUS_RUNNING || !lease_is_valid($item->ID, $lease_owner)) {
        return new WP_Error('gutenberg_item_lease_invalid', 'The item finalization lease is no longer active.', [
            'status' => 409,
        ]);
    }

    $blocks = item_blocks($item);
    if (is_wp_error($blocks)) {
        return $blocks;
    }

    return new WP_REST_Response([
        'item' => shape_item($item),
        'blocks' => $blocks,
    ]);
}

/** @return WP_REST_Response|WP_Error */
function rest_complete_item(WP_REST_Request $request): WP_REST_Response|WP_Error
{
    $params = rest_json_params($request);
    $lease_owner = is_scalar($params['lease_owner'] ?? null) ? (string) $params['lease_owner'] : '';
    $content = is_scalar($params['content'] ?? null) ? (string) $params['content'] : '';

    return rest_response(complete_item(
        rest_int_param($request, name: 'item_id'),
        $lease_owner,
        $content,
        $params['validations'] ?? null,
    ));
}

/** @return WP_REST_Response|WP_Error */
function rest_fail_item(WP_REST_Request $request): WP_REST_Response|WP_Error
{
    $params = rest_json_params($request);
    $lease_owner = is_scalar($params['lease_owner'] ?? null) ? (string) $params['lease_owner'] : '';
    $message = is_scalar($params['message'] ?? null) ? (string) $params['message'] : '';

    return rest_response(fail_item(
        rest_int_param($request, name: 'item_id'),
        $lease_owner,
        $params['errors'] ?? null,
        message: $message,
    ));
}

/** @return WP_REST_Response|WP_Error */
function rest_cancel_batch(WP_REST_Request $request): WP_REST_Response|WP_Error
{
    return rest_response(cancel_batch(rest_int_param($request, name: 'batch_id')));
}

/**
 * @return array<string, mixed>
 */
function rest_json_params(WP_REST_Request $request): array
{
    $raw_params = $request->get_json_params();
    $params = array_filter(
        $raw_params,
        static fn(mixed $value, mixed $key): bool => is_string($key),
        ARRAY_FILTER_USE_BOTH,
    );

    /** @var array<string, mixed> $params */
    return typed_string_map($params);
}

/**
 * @return list<string>
 */
function rest_string_list_query_param(mixed $value): array
{
    if (is_string($value)) {
        return array_values(array_filter(
            array_map(static fn(string $item): string => trim($item), explode(',', $value)),
            static fn(string $item): bool => $item !== '',
        ));
    }

    if (!is_array($value)) {
        return [];
    }

    return array_values(array_filter(
        array_map(static fn(mixed $item): string => is_scalar($item) ? trim((string) $item) : '', $value),
        static fn(string $item): bool => $item !== '',
    ));
}
