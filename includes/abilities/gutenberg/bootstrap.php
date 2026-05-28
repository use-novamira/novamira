<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Abilities\Gutenberg;

use WP_Block_Type_Registry;
use WP_Error;
use WP_Post;

if (!defined('ABSPATH')) {
    exit();
}

const POST_TYPE = 'novamira_gb_change';

const KIND_BATCH = 'batch';

const KIND_ITEM = 'item';

const META_KIND = '_novamira_gb_kind';

const META_STATUS = '_novamira_gb_status';

const META_STATUS_UPDATED_AT = '_novamira_gb_status_updated_at';

const META_AGENT_LABEL = '_novamira_gb_agent_label';

const META_AGENT_SESSION_ID = '_novamira_gb_agent_session_id';

const META_READY_AT = '_novamira_gb_ready_at';

const META_FINALIZED_AT = '_novamira_gb_finalized_at';

const META_LEASE_OWNER = '_novamira_gb_lease_owner';

const META_LEASE_EXPIRES_AT = '_novamira_gb_lease_expires_at';

const META_LAST_ERROR = '_novamira_gb_last_error';

const META_TARGET_ID = '_novamira_gb_target_id';

const META_TARGET_TYPE = '_novamira_gb_target_type';

const META_OPERATION = '_novamira_gb_operation';

const META_BASE_CONTENT_HASH = '_novamira_gb_base_content_hash';

const META_BASE_CONTENT = '_novamira_gb_base_content';

const META_BASE_REVISION_ID = '_novamira_gb_base_revision_id';

const META_SPEC_HASH = '_novamira_gb_spec_hash';

const META_BLOCK_SPEC = '_novamira_gb_block_spec';

const META_VALIDATION_ERRORS = '_novamira_gb_validation_errors';

const META_FINALIZATION_MODE = '_novamira_gb_finalization_mode';

const META_FINALIZED_CONTENT = '_novamira_gb_finalized_content';

const STATUS_DRAFT = 'draft';

const STATUS_READY = 'ready';

const STATUS_RUNNING = 'running';

const STATUS_PREPARED = 'prepared';

const STATUS_FINALIZED = 'finalized';

const STATUS_FAILED = 'failed';

const STATUS_CONFLICTED = 'conflicted';

const STATUS_CANCELED = 'canceled';

const STATUS_STALE = 'stale';

/** @var list<string> */
const NON_TERMINAL_STATUSES = [
    STATUS_DRAFT,
    STATUS_READY,
    STATUS_RUNNING,
    STATUS_PREPARED,
    STATUS_FAILED,
    STATUS_CONFLICTED,
];

/** @var list<string> */
const TERMINAL_STATUSES = [
    STATUS_FINALIZED,
    STATUS_CANCELED,
    STATUS_STALE,
];

const DRAFT_STALE_SECONDS = 86_400;

const LEASE_SECONDS = 300;

const STATUS_LOCK_SECONDS = 60;

const ITEM_CLAIM_ATTEMPTS = 3;

const RETENTION_SECONDS = 1_209_600;

const CLEANUP_START_DELAY_SECONDS = 3600;

const FINALIZER_RUNTIME_TRANSIENT = 'novamira_gb_finalizer_runtimes';

const FINALIZER_RUNTIME_POLL_TOKEN_OPTION = 'novamira_gb_finalizer_poll_token';

const FINALIZER_RUNTIME_POLL_TOKEN_BYTES = 16;

const FINALIZER_RUNTIME_STALE_SECONDS = 45;

const FINALIZER_RUNTIME_TTL_SECONDS = 120;

add_action('init', __NAMESPACE__ . '\\register_storage');
add_action('init', __NAMESPACE__ . '\\schedule_cleanup');
add_action('novamira_gutenberg_cleanup', __NAMESPACE__ . '\\cleanup_queue');

function register_storage(): void
{
    register_post_type(POST_TYPE, [
        'label' => __('Gutenberg pending changes', domain: 'novamira'),
        'public' => false,
        'show_ui' => false,
        'show_in_rest' => false,
        'supports' => ['title', 'excerpt'],
        'capability_type' => 'post',
        'map_meta_cap' => true,
        'has_archive' => false,
        'rewrite' => false,
        'query_var' => false,
    ]);

    foreach ([
        META_KIND,
        META_STATUS,
        META_STATUS_UPDATED_AT,
        META_AGENT_LABEL,
        META_AGENT_SESSION_ID,
        META_READY_AT,
        META_FINALIZED_AT,
        META_LEASE_OWNER,
        META_LEASE_EXPIRES_AT,
        META_LAST_ERROR,
        META_TARGET_TYPE,
        META_OPERATION,
        META_BASE_CONTENT_HASH,
        META_BASE_CONTENT,
        META_SPEC_HASH,
        META_BLOCK_SPEC,
        META_FINALIZATION_MODE,
        META_FINALIZED_CONTENT,
    ] as $key) {
        register_post_meta(POST_TYPE, $key, [
            'type' => 'string',
            'single' => true,
            'show_in_rest' => false,
            'sanitize_callback' => static fn(mixed $value): string => is_scalar($value) ? (string) $value : '',
        ]);
    }

    foreach ([META_TARGET_ID, META_BASE_REVISION_ID] as $key) {
        register_post_meta(POST_TYPE, $key, [
            'type' => 'integer',
            'single' => true,
            'show_in_rest' => false,
            'sanitize_callback' => static fn(mixed $value): int => is_scalar($value) ? (int) $value : 0,
        ]);
    }
}

function schedule_cleanup(): void
{
    if (wp_next_scheduled('novamira_gutenberg_cleanup') !== false) {
        return;
    }

    wp_schedule_event(
        timestamp: time() + CLEANUP_START_DELAY_SECONDS,
        recurrence: 'daily',
        hook: 'novamira_gutenberg_cleanup',
    );
}

function unschedule_cleanup(): void
{
    wp_clear_scheduled_hook('novamira_gutenberg_cleanup');
}

function cleanup_queue(): void
{
    mark_stale_drafts();
    mark_old_failed_batches_stale();

    $cutoff = gmdate('Y-m-d H:i:s', time() - RETENTION_SECONDS);
    foreach (get_batches(TERMINAL_STATUSES, posts_per_page: -1) as $batch) {
        $updated_at = meta_string($batch->ID, META_STATUS_UPDATED_AT);
        if ($updated_at === '' || strcmp($updated_at, $cutoff) > 0) {
            continue;
        }

        foreach (get_items($batch->ID) as $item) {
            wp_delete_post($item->ID, force_delete: true);
        }
        wp_delete_post($batch->ID, force_delete: true);
    }
}

function now_mysql(): string
{
    return gmdate('Y-m-d H:i:s');
}

function meta_string(int $post_id, string $key): string
{
    /** @var mixed $value */
    $value = get_post_meta($post_id, $key, single: true);

    return is_scalar($value) ? (string) $value : '';
}

function meta_int(int $post_id, string $key): int
{
    /** @var mixed $value */
    $value = get_post_meta($post_id, $key, single: true);

    return is_scalar($value) ? (int) $value : 0;
}

/**
 * @param list<array<string, mixed>> $items
 * @return list<array<string, mixed>>
 */
function typed_array_list(array $items): array
{
    return $items;
}

/**
 * @param array<string, mixed> $items
 * @return array<string, mixed>
 */
function typed_string_map(array $items): array
{
    return $items;
}

function status(int $post_id): string
{
    $status = meta_string($post_id, META_STATUS);

    return $status !== '' ? $status : STATUS_DRAFT;
}

function set_status(int $post_id, string $status): void
{
    update_post_meta($post_id, META_STATUS, $status);
    update_post_meta($post_id, META_STATUS_UPDATED_AT, now_mysql());

    if ($status === STATUS_FINALIZED) {
        update_post_meta($post_id, META_FINALIZED_AT, now_mysql());
    }
}

function clear_lease(int $post_id): void
{
    delete_post_meta($post_id, META_LEASE_OWNER);
    delete_post_meta($post_id, META_LEASE_EXPIRES_AT);
}

function set_lease(int $post_id, string $lease_owner): void
{
    update_post_meta($post_id, META_LEASE_OWNER, $lease_owner);
    update_post_meta($post_id, META_LEASE_EXPIRES_AT, gmdate('Y-m-d H:i:s', time() + LEASE_SECONDS));
}

function lease_is_valid(int $post_id, string $lease_owner): bool
{
    if ($lease_owner === '' || meta_string($post_id, META_LEASE_OWNER) !== $lease_owner) {
        return false;
    }

    $expires_at = meta_string($post_id, META_LEASE_EXPIRES_AT);
    if ($expires_at === '') {
        return false;
    }

    $expires = strtotime($expires_at . ' UTC');

    return $expires !== false && $expires > time();
}

/** @param list<string> $from_statuses */
function atomic_status_transition(int $post_id, array $from_statuses, string $to_status): bool
{
    if ($from_statuses === []) {
        return false;
    }

    $lock_owner = status_transition_lock($post_id);
    if ($lock_owner === '') {
        return false;
    }

    try {
        if (!in_array(status($post_id), $from_statuses, strict: true)) {
            return false;
        }

        set_status($post_id, $to_status);

        return true;
    } finally {
        release_status_transition_lock($post_id, $lock_owner);
    }
}

function status_transition_lock(int $post_id): string
{
    $option = sprintf('novamira_gb_status_lock_%d', $post_id);
    $owner = (string) wp_generate_uuid4();
    $payload = status_transition_lock_payload($owner);

    if (add_option(option: $option, value: $payload, autoload: false)) {
        return $owner;
    }

    if (!status_transition_lock_is_stale(get_option($option, default_value: null))) {
        return '';
    }

    delete_option($option);

    return add_option(option: $option, value: $payload, autoload: false) ? $owner : '';
}

function status_transition_lock_payload(string $owner): string
{
    return $owner . '|' . (string) (time() + STATUS_LOCK_SECONDS);
}

function status_transition_lock_is_stale(mixed $value): bool
{
    if (!is_string($value)) {
        return true;
    }

    $parts = explode('|', $value, limit: 2);
    if (count($parts) !== 2) {
        return true;
    }

    $expires = is_numeric($parts[1]) ? (int) $parts[1] : 0;

    return $expires <= time();
}

function release_status_transition_lock(int $post_id, string $owner): void
{
    $option = sprintf('novamira_gb_status_lock_%d', $post_id);
    /** @var mixed $value */
    $value = get_option($option, default_value: '');
    if (is_string($value) && str_starts_with($value, $owner . '|')) {
        delete_option($option);
    }
}

function find_batch(int $batch_id): ?WP_Post
{
    /** @var WP_Post|null $post */
    $post = get_post($batch_id);
    if (
        !$post instanceof WP_Post
        || $post->post_type !== POST_TYPE
        || meta_string($post->ID, META_KIND) !== KIND_BATCH
    ) {
        return null;
    }

    return $post;
}

function find_item(int $item_id): ?WP_Post
{
    /** @var WP_Post|null $post */
    $post = get_post($item_id);
    if (
        !$post instanceof WP_Post
        || $post->post_type !== POST_TYPE
        || meta_string($post->ID, META_KIND) !== KIND_ITEM
    ) {
        return null;
    }

    return $post;
}

/**
 * @param list<string>|null $statuses
 * @return list<WP_Post>
 */
function get_batches(?array $statuses = null, int $posts_per_page = 50): array
{
    $meta_query = [
        [
            'key' => META_KIND,
            'value' => KIND_BATCH,
        ],
    ];

    if ($statuses !== null) {
        $meta_query[] = [
            'key' => META_STATUS,
            'value' => $statuses,
            'compare' => 'IN',
        ];
    }

    /** @var list<WP_Post> */
    return get_posts([
        'post_type' => POST_TYPE,
        'post_status' => 'any',
        'posts_per_page' => $posts_per_page,
        'orderby' => 'ID',
        'order' => 'DESC',
        'meta_query' => $meta_query,
    ]);
}

/**
 * @param list<string>|null $statuses
 * @return list<WP_Post>
 */
function get_items(int $batch_id, ?array $statuses = null): array
{
    $meta_query = [
        [
            'key' => META_KIND,
            'value' => KIND_ITEM,
        ],
    ];

    if ($statuses !== null) {
        $meta_query[] = [
            'key' => META_STATUS,
            'value' => $statuses,
            'compare' => 'IN',
        ];
    }

    /** @var list<WP_Post> */
    return get_posts([
        'post_type' => POST_TYPE,
        'post_status' => 'any',
        'post_parent' => $batch_id,
        'posts_per_page' => -1,
        'orderby' => 'ID',
        'order' => 'ASC',
        'meta_query' => $meta_query,
    ]);
}

function create_batch(string $label, string $agent_label, string $agent_session_id, string $agent_note): int|WP_Error
{
    $label = trim($label) !== '' ? sanitize_text_field($label) : __('Untitled Gutenberg batch', domain: 'novamira');
    $agent_label = sanitize_text_field($agent_label);
    $agent_session_id = sanitize_text_field($agent_session_id);

    $result = wp_insert_post([
        'post_type' => POST_TYPE,
        'post_status' => 'publish',
        'post_title' => $label,
        'post_excerpt' => wp_strip_all_tags($agent_note),
        'post_content' => '',
    ], wp_error: true);

    if (is_wp_error($result)) {
        return $result;
    }

    $batch_id = (int) $result;
    update_post_meta($batch_id, META_KIND, KIND_BATCH);
    update_post_meta($batch_id, META_STATUS, STATUS_DRAFT);
    update_post_meta($batch_id, META_STATUS_UPDATED_AT, now_mysql());
    update_post_meta($batch_id, META_AGENT_LABEL, $agent_label);
    update_post_meta($batch_id, META_AGENT_SESSION_ID, $agent_session_id);

    return $batch_id;
}

/**
 * @param list<array<string, mixed>> $blocks
 */
function create_item(int $batch_id, int $target_id, string $target_type, string $operation, array $blocks): int|WP_Error
{
    $target = get_target($target_id);
    if (!$target instanceof WP_Post) {
        return new WP_Error('gutenberg_target_not_found', sprintf('Target post %d was not found.', $target_id));
    }

    $encoded = wp_json_encode($blocks);
    if (!is_string($encoded)) {
        return new WP_Error('gutenberg_invalid_block_spec', 'The block_spec could not be encoded as JSON.');
    }

    $result = wp_insert_post([
        'post_type' => POST_TYPE,
        'post_status' => 'publish',
        'post_parent' => $batch_id,
        'post_title' => target_title($target),
        'post_content' => '',
        'post_excerpt' => item_change_summary($target, $operation, $blocks),
    ], wp_error: true);

    if (is_wp_error($result)) {
        return $result;
    }

    $item_id = (int) $result;
    update_post_meta($item_id, META_KIND, KIND_ITEM);
    update_post_meta($item_id, META_STATUS, STATUS_DRAFT);
    update_post_meta($item_id, META_STATUS_UPDATED_AT, now_mysql());
    update_post_meta($item_id, META_TARGET_ID, $target_id);
    update_post_meta($item_id, META_TARGET_TYPE, $target_type);
    update_post_meta($item_id, META_OPERATION, $operation);
    update_post_meta($item_id, META_BASE_CONTENT_HASH, content_hash($target->post_content));
    update_post_meta($item_id, META_BASE_CONTENT, wp_slash($target->post_content));
    update_post_meta($item_id, META_BASE_REVISION_ID, latest_revision_id($target_id));
    update_post_meta($item_id, META_SPEC_HASH, hash('sha256', $encoded));
    update_post_meta($item_id, META_BLOCK_SPEC, wp_slash($encoded));
    update_post_meta($item_id, META_FINALIZATION_MODE, meta_value: 'js');

    return $item_id;
}

function get_target(int $target_id): ?WP_Post
{
    /** @var WP_Post|null $post */
    $post = get_post($target_id);

    return $post instanceof WP_Post ? $post : null;
}

/** @param array<string, mixed> $input */
function input_target_id(array $input): int
{
    if (array_key_exists('target_id', $input)) {
        return is_scalar($input['target_id']) ? (int) $input['target_id'] : 0;
    }

    if (array_key_exists('post_id', $input)) {
        return is_scalar($input['post_id']) ? (int) $input['post_id'] : 0;
    }

    return 0;
}

/** @param array<string, mixed> $input */
function input_target_type(array $input, WP_Post $target): string
{
    if (array_key_exists('target_type', $input)) {
        return is_scalar($input['target_type']) && (string) $input['target_type'] !== ''
            ? (string) $input['target_type']
            : $target->post_type;
    }

    if (array_key_exists('post_type', $input)) {
        return is_scalar($input['post_type']) && (string) $input['post_type'] !== ''
            ? (string) $input['post_type']
            : $target->post_type;
    }

    return $target->post_type;
}

function target_title(WP_Post $target): string
{
    $title = trim($target->post_title);

    return $title !== '' ? $title : sprintf(__('(no title) #%d', domain: 'novamira'), $target->ID);
}

function content_hash(string $content): string
{
    return hash('sha256', $content);
}

function latest_revision_id(int $target_id): int
{
    /** @var list<WP_Post> $revisions */
    $revisions = wp_get_post_revisions($target_id, [
        'numberposts' => 1,
        'orderby' => 'date',
        'order' => 'DESC',
    ]);

    if ($revisions === []) {
        return 0;
    }

    $revision = reset($revisions);

    return $revision->ID;
}

/**
 * @return list<array<string, mixed>>|WP_Error
 */
function normalize_blocks(mixed $value): array|WP_Error
{
    if (!is_array($value)) {
        return new WP_Error('gutenberg_invalid_block_spec', 'block_spec must be an array of Gutenberg block objects.');
    }

    if (($value['name'] ?? null) !== null && is_string($value['name'])) {
        $value = [$value];
    }

    $blocks = [];
    $values = array_values($value);
    for ($index = 0; $index < count($values); ++$index) {
        $normalized = normalize_block($values[$index], sprintf('block_spec[%s]', (string) $index));
        if (is_wp_error($normalized)) {
            return $normalized;
        }
        $blocks[] = $normalized;
    }

    if ($blocks === []) {
        return new WP_Error('gutenberg_empty_block_spec', 'block_spec must contain at least one block.');
    }

    return $blocks;
}

/**
 * @return array{name: string, attributes: array<string, mixed>, innerBlocks: list<array<string, mixed>>}|WP_Error
 */
function normalize_block(mixed $value, string $path): array|WP_Error
{
    if (!is_array($value)) {
        return new WP_Error('gutenberg_invalid_block_spec', sprintf('%s must be an object.', $path));
    }

    if (!array_key_exists('name', $value) || !is_string($value['name']) || trim($value['name']) === '') {
        return new WP_Error('gutenberg_invalid_block_spec', sprintf('%s.name must be a non-empty block name.', $path));
    }
    $name = trim($value['name']);

    if (array_key_exists('attributes', $value) && !is_array($value['attributes'])) {
        return new WP_Error('gutenberg_invalid_block_spec', sprintf(
            '%s.attributes must be an object when present.',
            $path,
        ));
    }
    $attributes = is_array($value['attributes'] ?? null) ? $value['attributes'] : [];

    if (array_key_exists('innerBlocks', $value) && !is_array($value['innerBlocks'])) {
        return new WP_Error('gutenberg_invalid_block_spec', sprintf(
            '%s.innerBlocks must be an array when present.',
            $path,
        ));
    }
    $inner_blocks = is_array($value['innerBlocks'] ?? null) ? array_values($value['innerBlocks']) : [];

    $normalized_inner_blocks = [];
    for ($index = 0; $index < count($inner_blocks); ++$index) {
        $normalized = normalize_block($inner_blocks[$index], sprintf('%s.innerBlocks[%s]', $path, (string) $index));
        if (is_wp_error($normalized)) {
            return $normalized;
        }
        $normalized_inner_blocks[] = $normalized;
    }

    /** @var array<string, mixed> $attributes */
    return [
        'name' => $name,
        'attributes' => $attributes,
        'innerBlocks' => $normalized_inner_blocks,
    ];
}

/**
 * @param array<string, mixed> $block
 * @return list<array<string, mixed>>
 */
function block_inner_specs(array $block): array
{
    $raw_inner_blocks = is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : [];
    $inner_blocks = array_values(array_filter($raw_inner_blocks, static fn(mixed $inner_block): bool => is_array(
        $inner_block,
    )));

    /** @var list<array<string, mixed>> $inner_blocks */
    return typed_array_list($inner_blocks);
}

/**
 * @param list<array<string, mixed>> $blocks
 */
function validate_dynamic_only_blocks(array $blocks): ?WP_Error
{
    foreach ($blocks as $block) {
        $name = is_string($block['name'] ?? null) ? $block['name'] : '';
        if (!is_novamira_dynamic_block($name)) {
            return new WP_Error(
                'gutenberg_static_blocks_require_finalization',
                sprintf(
                    'Block "%s" is not a registered Novamira-owned dynamic-only block. Native/static Gutenberg blocks must be queued with novamira/gutenberg-add-pending-change and finalized in a browser before they are live.',
                    $name !== '' ? $name : '(missing name)',
                ),
                [
                    'status' => 400,
                    'finalization_required' => true,
                    'queue_ability' => 'novamira/gutenberg-add-pending-change',
                    'enable_ability' => 'novamira/gutenberg-enable-batch-finalization',
                ],
            );
        }

        $inner_error = validate_dynamic_only_blocks(block_inner_specs($block));
        if ($inner_error !== null) {
            return $inner_error;
        }
    }

    return null;
}

function is_novamira_dynamic_block(string $name): bool
{
    if (!str_starts_with($name, 'novamira/')) {
        return false;
    }

    if (!class_exists(WP_Block_Type_Registry::class)) {
        return false;
    }

    $block_type = WP_Block_Type_Registry::get_instance()->get_registered($name);

    return $block_type !== null && $block_type->is_dynamic();
}

/**
 * @param list<array<string, mixed>> $blocks
 */
function serialize_dynamic_blocks(array $blocks): string
{
    $parsed_blocks = [];
    foreach ($blocks as $block) {
        $parsed_blocks[] = spec_to_parsed_block($block);
    }

    /** @var array<int|string, array{attrs: array<array-key, mixed>, blockName: null|string, innerBlocks: array<array-key, array<array-key, mixed>>, innerContent: array<array-key, mixed>, innerHTML: string}> $parsed_blocks */
    return serialize_blocks($parsed_blocks);
}

/**
 * @param array<string, mixed> $block
 * @return array{blockName: string, attrs: array<array-key, mixed>, innerBlocks: list<array<array-key, mixed>>, innerHTML: string, innerContent: list<mixed>}
 */
function spec_to_parsed_block(array $block): array
{
    $inner_blocks = [];
    foreach (block_inner_specs($block) as $inner_block) {
        $inner_blocks[] = spec_to_parsed_block($inner_block);
    }

    $attributes = is_array($block['attributes'] ?? null) ? $block['attributes'] : [];
    /** @var array<string, mixed> $attributes */

    return [
        'blockName' => (string) $block['name'],
        'attrs' => $attributes,
        'innerBlocks' => $inner_blocks,
        'innerHTML' => '',
        'innerContent' => array_fill(0, count($inner_blocks), value: null),
    ];
}

/**
 * @return list<string>
 * @param list<array<string, mixed>> $blocks
 */
function top_level_block_names(array $blocks): array
{
    $names = [];
    foreach ($blocks as $block) {
        $name = is_string($block['name'] ?? null) ? $block['name'] : '';
        if ($name !== '') {
            $names[] = $name;
        }
    }

    return $names;
}

/**
 * @param list<array<string, mixed>> $blocks
 */
function item_change_summary(WP_Post $target, string $operation, array $blocks): string
{
    $names = top_level_block_names($blocks);
    $name_summary = $names === []
        ? __('no blocks', domain: 'novamira')
        : implode(', ', array_slice($names, offset: 0, length: 5));

    return sprintf(
        '%s for %s #%d: %d top-level block(s): %s',
        $operation,
        $target->post_type,
        $target->ID,
        count($blocks),
        $name_summary,
    );
}

function finalization_url(int $batch_id): string
{
    unset($batch_id);

    return finalizer_dashboard_url();
}

function finalizer_dashboard_url(): string
{
    return add_query_arg(['page' => 'novamira-gutenberg-finalize'], admin_url('admin.php'));
}

function batch_label(WP_Post $batch): string
{
    $label = trim($batch->post_title);

    return $label !== '' ? $label : sprintf(__('Gutenberg batch #%d', domain: 'novamira'), $batch->ID);
}

function user_instruction(WP_Post $batch): string
{
    $runtime = finalizer_runtime_status($batch);
    if (($runtime['online'] ?? false) === true && ($runtime['can_finalize_batch'] ?? false) === true) {
        return sprintf(
            'The Novamira Block Editor Queue page is open and should automatically finalize Gutenberg batch #%d: %s. Do not ask the user to do anything unless the page goes offline; stream %s with curl -N, or poll %s with curl, until the batch becomes finalized, failed, or conflicted. Do not treat these Gutenberg changes as live until finalization completes.',
            $batch->ID,
            batch_label($batch),
            (string) ($runtime['sse_url'] ?? finalizer_runtime_sse_url($batch->ID)),
            (string) ($runtime['poll_url'] ?? finalizer_runtime_poll_url($batch->ID)),
        );
    }

    if (($runtime['online'] ?? false) === true) {
        return sprintf(
            'A Novamira Block Editor Queue page is open, but that browser user may not be able to finalize Gutenberg batch #%d: %s. Ask the user to open %s as a user who can edit every target. Do not treat these Gutenberg changes as live until finalization completes.',
            $batch->ID,
            batch_label($batch),
            finalizer_dashboard_url(),
        );
    }

    return sprintf(
        'The Novamira Block Editor Queue page is not currently online. Ask the user to open %s and keep it open. It will automatically finalize Gutenberg batch #%d: %s when it can. Do not treat these Gutenberg changes as live until finalization completes.',
        finalizer_dashboard_url(),
        $batch->ID,
        batch_label($batch),
    );
}

function copy_back_prompt(WP_Post $batch): string
{
    $status = status($batch->ID);
    $label = batch_label($batch);

    return match ($status) {
        STATUS_FINALIZED => sprintf('Gutenberg batch #%d finalized: %s. Verify it and continue.', $batch->ID, $label),
        STATUS_FAILED => sprintf(
            'Gutenberg batch #%d failed: %s. Review the reported item errors and continue.',
            $batch->ID,
            $label,
        ),
        STATUS_CONFLICTED => sprintf(
            'Gutenberg batch #%d conflicted: %s. Re-read the changed target and queue a fresh batch.',
            $batch->ID,
            $label,
        ),
        STATUS_CANCELED => sprintf('Gutenberg batch #%d canceled: %s.', $batch->ID, $label),
        STATUS_STALE => sprintf(
            'Gutenberg batch #%d is stale: %s. Re-read the targets and queue a fresh batch.',
            $batch->ID,
            $label,
        ),
        default => sprintf('Gutenberg batch #%d is %s: %s.', $batch->ID, $status, $label),
    };
}

/**
 * @return array<string, int>
 * @param list<WP_Post> $items
 */
function count_item_statuses(array $items): array
{
    $counts = [];
    foreach ($items as $item) {
        $item_status = status($item->ID);
        $counts[$item_status] = ($counts[$item_status] ?? 0) + 1;
    }

    return $counts;
}

/**
 * @return array<string, mixed>
 */
function shape_batch_base(WP_Post $batch): array
{
    $items = get_items($batch->ID);
    $counts = count_item_statuses($items);
    $agent_label = meta_string($batch->ID, META_AGENT_LABEL);
    $agent_session_id = meta_string($batch->ID, META_AGENT_SESSION_ID);

    return [
        'batch_id' => $batch->ID,
        'label' => batch_label($batch),
        'agent_label' => $agent_label !== '' ? $agent_label : 'the originating agent',
        'agent_session_id' => $agent_session_id,
        'agent_note' => $batch->post_excerpt,
        'status' => status($batch->ID),
        'created_at' => $batch->post_date_gmt,
        'ready_at' => meta_string($batch->ID, META_READY_AT),
        'finalized_at' => meta_string($batch->ID, META_FINALIZED_AT),
        'item_count' => count($items),
        'item_counts' => $counts,
        'last_error' => meta_string($batch->ID, META_LAST_ERROR),
        'finalization_required' => !in_array(status($batch->ID), TERMINAL_STATUSES, strict: true),
        'finalization_url' => finalization_url($batch->ID),
        'finalizer_runtime' => finalizer_runtime_status($batch),
        'user_instruction' => user_instruction($batch),
        'copy_back_prompt' => copy_back_prompt($batch),
    ];
}

/**
 * @return array<string, mixed>
 */
function shape_batch(WP_Post $batch): array
{
    $data = shape_batch_base($batch);
    $data['items'] = array_map(static fn(WP_Post $item): array => shape_item($item), get_items($batch->ID));

    return $data;
}

/**
 * @return array<string, mixed>
 */
function shape_batch_summary(WP_Post $batch): array
{
    return shape_batch_base($batch);
}

/**
 * @return array<string, mixed>
 */
function shape_item(WP_Post $item): array
{
    $target_id = meta_int($item->ID, META_TARGET_ID);
    $target = get_target($target_id);
    $blocks = item_blocks($item);
    $block_names = is_wp_error($blocks) ? [] : top_level_block_names($blocks);

    return [
        'item_id' => $item->ID,
        'batch_id' => $item->post_parent,
        'target_id' => $target_id,
        'target_type' => meta_string($item->ID, META_TARGET_TYPE),
        'target_title' => $target instanceof WP_Post
            ? target_title($target)
            : sprintf(__('Missing target #%d', domain: 'novamira'), $target_id),
        'operation' => meta_string($item->ID, META_OPERATION),
        'status' => status($item->ID),
        'created_at' => $item->post_date_gmt,
        'finalized_at' => meta_string($item->ID, META_FINALIZED_AT),
        'top_level_block_count' => is_wp_error($blocks) ? 0 : count($blocks),
        'top_level_block_names' => array_slice($block_names, offset: 0, length: 10),
        'change_summary' => $item->post_excerpt,
        'validation_errors' => validation_errors($item->ID),
        'finalization_mode' => meta_string($item->ID, META_FINALIZATION_MODE),
    ];
}

/**
 * @return list<array<string, mixed>>|WP_Error
 */
function item_blocks(WP_Post $item): array|WP_Error
{
    $encoded = meta_string($item->ID, META_BLOCK_SPEC);
    if ($encoded === '') {
        $encoded = $item->post_content;
    }

    /** @var mixed $decoded */
    $decoded = json_decode($encoded, associative: true);
    if (!is_array($decoded)) {
        return new WP_Error('gutenberg_invalid_stored_block_spec', sprintf(
            'Item %d has an invalid stored block_spec.',
            $item->ID,
        ));
    }

    return normalize_blocks($decoded);
}

/**
 * @return array<string, mixed>
 */
function pending_summary_for_target(int $target_id, string $target_type): array
{
    $item = active_item_for_target($target_id, $target_type);
    if (!$item instanceof WP_Post) {
        return [];
    }

    $batch = find_batch($item->post_parent);

    return [
        'batch_id' => $item->post_parent,
        'batch_label' => $batch instanceof WP_Post ? batch_label($batch) : '',
        'batch_status' => $batch instanceof WP_Post ? status($batch->ID) : '',
        'item_id' => $item->ID,
        'item_status' => status($item->ID),
        'agent_label' => $batch instanceof WP_Post ? meta_string($batch->ID, META_AGENT_LABEL) : '',
        'finalization_url' => $batch instanceof WP_Post ? finalization_url($batch->ID) : '',
        'warning' => 'This target has a non-terminal queued Gutenberg change. Live saved content does not include the queued block_spec until finalization succeeds.',
    ];
}

function active_item_for_target(int $target_id, string $target_type): ?WP_Post
{
    /** @var list<WP_Post> $items */
    $items = get_posts([
        'post_type' => POST_TYPE,
        'post_status' => 'any',
        'posts_per_page' => 1,
        'orderby' => 'ID',
        'order' => 'ASC',
        'meta_query' => [
            [
                'key' => META_KIND,
                'value' => KIND_ITEM,
            ],
            [
                'key' => META_TARGET_ID,
                'value' => $target_id,
                'compare' => '=',
                'type' => 'NUMERIC',
            ],
            [
                'key' => META_TARGET_TYPE,
                'value' => $target_type,
            ],
            [
                'key' => META_STATUS,
                'value' => NON_TERMINAL_STATUSES,
                'compare' => 'IN',
            ],
        ],
    ]);

    return $items[0] ?? null;
}

/**
 * @return array<string, mixed>
 */
function conflict_payload(WP_Post $item): array
{
    $batch = find_batch($item->post_parent);
    $target_id = meta_int($item->ID, META_TARGET_ID);
    $target_type = meta_string($item->ID, META_TARGET_TYPE);
    $agent_label = $batch instanceof WP_Post ? meta_string($batch->ID, META_AGENT_LABEL) : '';

    return [
        'batch_id' => $item->post_parent,
        'batch_label' => $batch instanceof WP_Post ? batch_label($batch) : '',
        'batch_status' => $batch instanceof WP_Post ? status($batch->ID) : '',
        'agent_label' => $agent_label !== '' ? $agent_label : 'the originating agent',
        'target_id' => $target_id,
        'target_type' => $target_type,
        'cancel_ability' => 'novamira/gutenberg-delete-pending-batch',
        'cancel_params' => ['batch_id' => $item->post_parent],
    ];
}

function current_user_can_finalize_batch(WP_Post $batch): bool
{
    if (novamira_current_user_can_manage()) {
        return true;
    }

    foreach (get_items($batch->ID) as $item) {
        $target_id = meta_int($item->ID, META_TARGET_ID);
        if ($target_id <= 0 || !current_user_can('edit_post', $target_id)) {
            return false;
        }
    }

    return true;
}

function mark_stale_drafts(): void
{
    $cutoff = gmdate('Y-m-d H:i:s', time() - DRAFT_STALE_SECONDS);
    foreach (get_batches([STATUS_DRAFT], posts_per_page: -1) as $batch) {
        if (strcmp($batch->post_date_gmt, $cutoff) > 0) {
            continue;
        }

        set_status($batch->ID, STATUS_STALE);
        foreach (get_items($batch->ID, [STATUS_DRAFT]) as $item) {
            set_status($item->ID, STATUS_STALE);
        }
    }
}

function mark_old_failed_batches_stale(): void
{
    $cutoff = gmdate('Y-m-d H:i:s', time() - RETENTION_SECONDS);
    foreach (get_batches([STATUS_FAILED], posts_per_page: -1) as $batch) {
        $updated_at = meta_string($batch->ID, META_STATUS_UPDATED_AT);
        if ($updated_at !== '' && strcmp($updated_at, $cutoff) > 0) {
            continue;
        }

        set_status($batch->ID, STATUS_STALE);
        foreach (get_items($batch->ID, NON_TERMINAL_STATUSES) as $item) {
            set_status($item->ID, STATUS_STALE);
        }
    }
}

function release_expired_leases_for_batch(WP_Post $batch): void
{
    if (
        status($batch->ID) === STATUS_RUNNING
        && !lease_is_valid($batch->ID, meta_string($batch->ID, META_LEASE_OWNER))
    ) {
        set_status($batch->ID, STATUS_FAILED);
        update_post_meta(
            $batch->ID,
            META_LAST_ERROR,
            __(
                'A previous Block Editor Queue tab stopped before renewing its lease. Retry finalization for this batch.',
                domain: 'novamira',
            ),
        );
        clear_lease($batch->ID);
    }

    foreach (get_items($batch->ID, [STATUS_RUNNING]) as $item) {
        if (lease_is_valid($item->ID, meta_string($item->ID, META_LEASE_OWNER))) {
            continue;
        }

        set_status($item->ID, STATUS_FAILED);
        update_post_meta($item->ID, META_VALIDATION_ERRORS, [
            [
                'message' => 'A previous Block Editor Queue tab stopped before completing this item.',
                'category' => 'abandoned-finalizer',
                'code' => 'lease_expired',
                'suppressed_count' => 0,
            ],
        ]);
        clear_lease($item->ID);
    }
}

function refresh_batch_runtime_state(WP_Post $batch): WP_Post
{
    release_expired_leases_for_batch($batch);

    return find_batch($batch->ID) ?? $batch;
}

/**
 * @return array<string, mixed>|WP_Error
 */
function claim_batch(int $batch_id): array|WP_Error
{
    $batch = find_batch($batch_id);
    if (!$batch instanceof WP_Post) {
        return new WP_Error('gutenberg_batch_not_found', sprintf('Gutenberg batch %d was not found.', $batch_id), [
            'status' => 404,
        ]);
    }

    release_expired_leases_for_batch($batch);
    $batch = find_batch($batch_id);
    if (!$batch instanceof WP_Post) {
        return new WP_Error('gutenberg_batch_not_found', sprintf('Gutenberg batch %d was not found.', $batch_id), [
            'status' => 404,
        ]);
    }

    $current_status = status($batch->ID);
    if ($current_status === STATUS_DRAFT) {
        return new WP_Error(
            'gutenberg_batch_not_ready',
            'Draft Gutenberg batches cannot be finalized until novamira/gutenberg-enable-batch-finalization is called.',
            ['status' => 409],
        );
    }

    if (!in_array($current_status, [STATUS_READY, STATUS_FAILED], strict: true)) {
        return new WP_Error(
            'gutenberg_batch_not_claimable',
            sprintf('Gutenberg batch %d is %s and cannot be claimed for finalization.', $batch->ID, $current_status),
            ['status' => 409, 'batch' => shape_batch($batch)],
        );
    }

    $lease_owner = (string) wp_generate_uuid4();
    if (!atomic_status_transition($batch->ID, [STATUS_READY, STATUS_FAILED], STATUS_RUNNING)) {
        $fresh_batch = find_batch($batch->ID);

        return new WP_Error('gutenberg_batch_claim_raced', 'Another Block Editor Queue tab claimed this batch first.', [
            'status' => 409,
            'batch' => $fresh_batch instanceof WP_Post ? shape_batch($fresh_batch) : null,
        ]);
    }

    set_lease($batch->ID, $lease_owner);
    update_post_meta($batch->ID, META_LAST_ERROR, meta_value: '');

    foreach (get_items($batch->ID, [STATUS_FAILED, STATUS_CONFLICTED]) as $item) {
        set_status($item->ID, STATUS_READY);
        update_post_meta($item->ID, META_VALIDATION_ERRORS, []);
        clear_lease($item->ID);
    }

    $fresh_batch = find_batch($batch->ID);

    return [
        'lease_owner' => $lease_owner,
        'batch' => $fresh_batch instanceof WP_Post ? shape_batch($fresh_batch) : shape_batch($batch),
    ];
}

/**
 * @return array<string, mixed>|WP_Error
 */
function claim_next_item(int $batch_id, string $lease_owner): array|WP_Error
{
    $batch = find_batch($batch_id);
    if (!$batch instanceof WP_Post) {
        return new WP_Error('gutenberg_batch_not_found', sprintf('Gutenberg batch %d was not found.', $batch_id), [
            'status' => 404,
        ]);
    }

    if (status($batch->ID) !== STATUS_RUNNING || !lease_is_valid($batch->ID, $lease_owner)) {
        return new WP_Error('gutenberg_batch_lease_invalid', 'The batch finalization lease is no longer active.', [
            'status' => 409,
        ]);
    }

    set_lease($batch->ID, $lease_owner);
    $item = null;
    for ($attempt = 0; $attempt < ITEM_CLAIM_ATTEMPTS; ++$attempt) {
        $ready_items = get_items($batch->ID, [STATUS_READY]);
        if ($ready_items === []) {
            return finish_batch_if_complete($batch);
        }

        $candidate = $ready_items[0];
        if (atomic_status_transition($candidate->ID, [STATUS_READY], STATUS_RUNNING)) {
            $item = $candidate;
            break;
        }
    }

    if (!$item instanceof WP_Post) {
        return new WP_Error(
            'gutenberg_item_claim_raced',
            'Another Block Editor Queue request claimed the next item first.',
            [
                'status' => 409,
                'batch' => shape_batch($batch),
            ],
        );
    }

    set_lease($item->ID, $lease_owner);
    $fresh_item = find_item($item->ID);
    $claimed_item = $fresh_item instanceof WP_Post ? $fresh_item : $item;
    $blocks = item_blocks($claimed_item);
    if (is_wp_error($blocks)) {
        $failed = fail_item(
            $claimed_item->ID,
            $lease_owner,
            [
                [
                    'message' => $blocks->get_error_message(),
                    'category' => 'stored-spec',
                    'code' => $blocks->get_error_code(),
                ],
            ],
            message: 'The stored Gutenberg block_spec is invalid; canonical content was not written.',
        );

        if (is_wp_error($failed)) {
            return $failed;
        }

        /** @var array<string, mixed> $failed */
        return typed_string_map($failed);
    }

    return [
        'done' => false,
        'item' => shape_item($claimed_item),
        'batch' => shape_batch($batch),
    ];
}

/**
 * @param list<WP_Post> $prepared_items
 * @return array<string, mixed>|WP_Error
 */
function commit_prepared_items(WP_Post $batch, array $prepared_items): array|WP_Error
{
    if ($prepared_items === []) {
        return finalize_batch($batch);
    }

    foreach ($prepared_items as $item) {
        $target_id = meta_int($item->ID, META_TARGET_ID);
        $target = get_target($target_id);
        if (!$target instanceof WP_Post) {
            return fail_prepared_item(
                $item,
                [['message' => 'The target post no longer exists.']],
                message: 'Target post missing; live content was left unchanged.',
            );
        }

        $base_hash = meta_string($item->ID, META_BASE_CONTENT_HASH);
        if ($base_hash !== '' && !hash_equals($base_hash, content_hash($target->post_content))) {
            return conflict_prepared_item($item);
        }
    }

    $written_items = [];
    foreach ($prepared_items as $item) {
        $target_id = meta_int($item->ID, META_TARGET_ID);
        $target = get_target($target_id);
        if (!$target instanceof WP_Post) {
            restore_written_prepared_items($written_items);

            return fail_prepared_item(
                $item,
                [['message' => 'The target post no longer exists.']],
                message: 'Target post missing; live content was left unchanged.',
            );
        }

        $base_hash = meta_string($item->ID, META_BASE_CONTENT_HASH);
        if ($base_hash !== '' && !hash_equals($base_hash, content_hash($target->post_content))) {
            restore_written_prepared_items($written_items);

            return conflict_prepared_item($item);
        }

        $updated = wp_update_post([
            'ID' => $target->ID,
            'post_content' => meta_string($item->ID, META_FINALIZED_CONTENT),
        ], wp_error: true);

        if (is_wp_error($updated)) {
            $restored = restore_written_prepared_items($written_items);

            return fail_prepared_item(
                $item,
                [['message' => $updated->get_error_message()]],
                message: $restored
                    ? 'WordPress failed to write post_content; live content was left unchanged.'
                    : 'WordPress failed to write post_content and rollback failed; inspect the affected targets before retrying.',
            );
        }

        $written_items[] = $item;
    }

    foreach ($prepared_items as $item) {
        set_status($item->ID, STATUS_FINALIZED);
        clear_lease($item->ID);
        delete_post_meta($item->ID, META_BASE_CONTENT);
        delete_post_meta($item->ID, META_FINALIZED_CONTENT);
    }

    set_status($batch->ID, STATUS_FINALIZED);
    clear_lease($batch->ID);

    $fresh_batch = find_batch($batch->ID) ?? $batch;

    return [
        'done' => true,
        'batch' => shape_batch($fresh_batch),
    ];
}

/**
 * @param list<WP_Post> $written_items
 */
function restore_written_prepared_items(array $written_items): bool
{
    $restored = true;
    foreach (array_reverse($written_items) as $item) {
        $target = get_target(meta_int($item->ID, META_TARGET_ID));
        if (!$target instanceof WP_Post) {
            $restored = false;
            continue;
        }

        $updated = wp_update_post([
            'ID' => $target->ID,
            'post_content' => meta_string($item->ID, META_BASE_CONTENT),
        ], wp_error: true);

        if (is_wp_error($updated)) {
            $restored = false;
        }
    }

    return $restored;
}

/**
 * @return array<string, mixed>
 */
function finalize_batch(WP_Post $batch): array
{
    set_status($batch->ID, STATUS_FINALIZED);
    clear_lease($batch->ID);
    $fresh_batch = find_batch($batch->ID) ?? $batch;

    return [
        'done' => true,
        'batch' => shape_batch($fresh_batch),
    ];
}

/**
 * @return array<string, mixed>|WP_Error
 */
function finish_batch_if_complete(WP_Post $batch): array|WP_Error
{
    $ready = get_items($batch->ID, [STATUS_READY]);
    if ($ready !== []) {
        return [
            'done' => false,
            'batch' => shape_batch($batch),
        ];
    }

    $failed = get_items($batch->ID, [STATUS_FAILED, STATUS_CONFLICTED]);
    if ($failed !== []) {
        set_status($batch->ID, STATUS_FAILED);
        clear_lease($batch->ID);
        $fresh_batch = find_batch($batch->ID) ?? $batch;

        return [
            'done' => true,
            'batch' => shape_batch($fresh_batch),
        ];
    }

    $running = get_items($batch->ID, [STATUS_RUNNING]);
    if ($running !== []) {
        return [
            'done' => false,
            'batch' => shape_batch($batch),
        ];
    }

    return commit_prepared_items($batch, get_items($batch->ID, [STATUS_PREPARED]));
}

/**
 * @param mixed $validations
 */
function validation_payload_has_failures(mixed $validations): bool
{
    if (!is_array($validations)) {
        return true;
    }

    return (
        array_filter(
            $validations,
            static fn(mixed $validation): bool => !is_array($validation) || ($validation['isValid'] ?? false) !== true,
        ) !== []
    );
}

/**
 * @param mixed $raw_errors
 * @return list<array<string, mixed>>
 */
function raw_validation_error_rows(mixed $raw_errors): array
{
    if (!is_array($raw_errors)) {
        return [['message' => is_scalar($raw_errors) ? (string) $raw_errors : 'Unknown validation error.']];
    }

    return array_map(
        static function (mixed $error): array {
            if (is_array($error)) {
                /** @var array<string, mixed> $error */
                return $error;
            }

            return ['message' => is_scalar($error) ? (string) $error : 'Unknown validation error.'];
        },
        array_values($raw_errors),
    );
}

function compact_validation_message(mixed $value): string
{
    $message = is_scalar($value) ? (string) $value : 'Unknown validation error.';
    $message = preg_replace(pattern: '/\s+/', replacement: ' ', subject: $message) ?? $message;

    return mb_substr(trim($message), start: 0, length: 300);
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function compact_validation_error_row(array $row, int $target_id, string $target_title, int $suppressed_count): array
{
    return [
        'target_id' => $target_id,
        'target_title' => $target_title,
        'block_name' => is_scalar($row['block_name'] ?? null) ? (string) $row['block_name'] : '',
        'path' => is_scalar($row['path'] ?? null) ? (string) $row['path'] : '',
        'category' => is_scalar($row['category'] ?? null) ? (string) $row['category'] : 'validation',
        'code' => is_scalar($row['code'] ?? null) ? (string) $row['code'] : 'block_validation_failed',
        'message' => compact_validation_message($row['message'] ?? null),
        'suppressed_count' => $suppressed_count,
    ];
}

/**
 * @param mixed $raw_errors
 * @return list<array<string, mixed>>
 */
function compact_validation_errors(mixed $raw_errors, ?WP_Post $item = null): array
{
    $target_id = $item instanceof WP_Post ? meta_int($item->ID, META_TARGET_ID) : 0;
    $target = $target_id > 0 ? get_target($target_id) : null;
    $target_title = $target instanceof WP_Post ? target_title($target) : '';
    $errors = raw_validation_error_rows($raw_errors);
    $suppressed_count = max(0, count($errors) - 5);

    $compact = [];
    foreach (array_slice($errors, offset: 0, length: 5) as $row) {
        $compact[] = compact_validation_error_row($row, $target_id, $target_title, $suppressed_count);
    }

    return $compact;
}

/**
 * @return list<array<string, mixed>>
 */
function validation_errors(int $item_id): array
{
    /** @var mixed $value */
    $value = get_post_meta($item_id, META_VALIDATION_ERRORS, single: true);
    if (!is_array($value)) {
        return [];
    }

    $errors = array_values(array_filter($value, static fn(mixed $error): bool => is_array($error)));

    /** @var list<array<string, mixed>> $errors */
    return typed_array_list($errors);
}

function conflict_prepared_item(WP_Post $item): WP_Error
{
    set_status($item->ID, STATUS_CONFLICTED);
    clear_lease($item->ID);
    update_post_meta($item->ID, META_VALIDATION_ERRORS, compact_validation_errors([
        [
            'message' => 'The target content changed after this Gutenberg item was queued. Re-read the target and queue a fresh change.',
            'category' => 'content-conflict',
            'code' => 'base_content_changed',
        ],
    ], $item));
    mark_batch_failed(
        $item->post_parent,
        message: 'At least one target changed after it was queued; live content was left unchanged.',
    );

    return new WP_Error(
        'gutenberg_target_changed',
        'The target content changed after this Gutenberg item was queued. Live content was left unchanged.',
        ['status' => 409, 'item' => shape_item($item)],
    );
}

/**
 * @param mixed $errors
 */
function fail_prepared_item(WP_Post $item, mixed $errors, string $message): WP_Error
{
    set_status($item->ID, STATUS_FAILED);
    clear_lease($item->ID);
    update_post_meta($item->ID, META_VALIDATION_ERRORS, compact_validation_errors($errors, $item));
    mark_batch_failed($item->post_parent, $message);

    return new WP_Error('gutenberg_prepared_item_failed', $message, ['status' => 500, 'item' => shape_item($item)]);
}

/**
 * @param mixed $validations
 */
function complete_item(int $item_id, string $lease_owner, string $content, mixed $validations): array|WP_Error
{
    $item = find_item($item_id);
    if (!$item instanceof WP_Post) {
        return new WP_Error('gutenberg_item_not_found', sprintf('Gutenberg item %d was not found.', $item_id), [
            'status' => 404,
        ]);
    }

    if (status($item->ID) !== STATUS_RUNNING || !lease_is_valid($item->ID, $lease_owner)) {
        return new WP_Error('gutenberg_item_lease_invalid', 'The item finalization lease is no longer active.', [
            'status' => 409,
        ]);
    }

    if (validation_payload_has_failures($validations)) {
        return fail_item(
            $item->ID,
            $lease_owner,
            $validations,
            message: 'JS validation failed; canonical content was not written.',
        );
    }

    $target_id = meta_int($item->ID, META_TARGET_ID);
    $target = get_target($target_id);
    if (!$target instanceof WP_Post) {
        return fail_item(
            $item->ID,
            $lease_owner,
            [['message' => 'The target post no longer exists.']],
            message: 'Target post missing.',
        );
    }

    $base_hash = meta_string($item->ID, META_BASE_CONTENT_HASH);
    if ($base_hash !== '' && !hash_equals($base_hash, content_hash($target->post_content))) {
        return conflict_prepared_item($item);
    }

    $staged = update_post_meta($item->ID, META_FINALIZED_CONTENT, wp_slash($content));
    if ($staged === false) {
        return fail_item(
            $item->ID,
            $lease_owner,
            [['message' => 'WordPress failed to stage finalized Gutenberg content.']],
            message: 'WordPress failed to stage finalized content; live content was left unchanged.',
        );
    }

    set_status($item->ID, STATUS_PREPARED);
    clear_lease($item->ID);
    update_post_meta($item->ID, META_VALIDATION_ERRORS, []);

    $batch = find_batch($item->post_parent);
    $batch_result = $batch instanceof WP_Post ? finish_batch_if_complete($batch) : ['done' => true, 'batch' => null];
    if (is_wp_error($batch_result)) {
        return $batch_result;
    }
    $fresh_item = find_item($item->ID);

    return [
        'item' => $fresh_item instanceof WP_Post ? shape_item($fresh_item) : shape_item($item),
        'batch' => $batch_result['batch'],
        'done' => $batch_result['done'] ?? false,
    ];
}

/**
 * @param mixed $errors
 */
function fail_item(int $item_id, string $lease_owner, mixed $errors, string $message = ''): array|WP_Error
{
    $item = find_item($item_id);
    if (!$item instanceof WP_Post) {
        return new WP_Error('gutenberg_item_not_found', sprintf('Gutenberg item %d was not found.', $item_id), [
            'status' => 404,
        ]);
    }

    if (status($item->ID) !== STATUS_RUNNING || !lease_is_valid($item->ID, $lease_owner)) {
        return new WP_Error('gutenberg_item_lease_invalid', 'The item finalization lease is no longer active.', [
            'status' => 409,
        ]);
    }

    set_status($item->ID, STATUS_FAILED);
    clear_lease($item->ID);
    update_post_meta($item->ID, META_VALIDATION_ERRORS, compact_validation_errors($errors, $item));
    mark_batch_failed(
        $item->post_parent,
        $message !== '' ? $message : 'One or more Gutenberg items failed validation.',
    );

    $batch = find_batch($item->post_parent);
    $fresh_item = find_item($item->ID);

    return [
        'item' => $fresh_item instanceof WP_Post ? shape_item($fresh_item) : shape_item($item),
        'batch' => $batch instanceof WP_Post ? shape_batch($batch) : null,
        'done' => true,
    ];
}

function mark_batch_failed(int $batch_id, string $message): void
{
    $batch = find_batch($batch_id);
    if (!$batch instanceof WP_Post) {
        return;
    }

    set_status($batch->ID, STATUS_FAILED);
    clear_lease($batch->ID);
    update_post_meta($batch->ID, META_LAST_ERROR, $message);
}

/** @return array<string, mixed>|WP_Error */
function cancel_batch(int $batch_id): array|WP_Error
{
    $batch = find_batch($batch_id);
    if (!$batch instanceof WP_Post) {
        return new WP_Error('gutenberg_batch_not_found', sprintf('Gutenberg batch %d was not found.', $batch_id), [
            'status' => 404,
        ]);
    }

    if (status($batch->ID) === STATUS_FINALIZED) {
        return new WP_Error('gutenberg_batch_already_finalized', 'Finalized Gutenberg batches cannot be canceled.', [
            'status' => 409,
        ]);
    }

    set_status($batch->ID, STATUS_CANCELED);
    clear_lease($batch->ID);
    foreach (get_items($batch->ID) as $item) {
        if (status($item->ID) === STATUS_FINALIZED) {
            continue;
        }

        set_status($item->ID, STATUS_CANCELED);
        clear_lease($item->ID);
    }

    $fresh_batch = find_batch($batch->ID) ?? $batch;

    return shape_batch($fresh_batch);
}

/** @return array<string, mixed>|WP_Error */
function cancel_item(int $item_id): array|WP_Error
{
    $item = find_item($item_id);
    if (!$item instanceof WP_Post) {
        return new WP_Error('gutenberg_item_not_found', sprintf('Gutenberg item %d was not found.', $item_id), [
            'status' => 404,
        ]);
    }

    if (in_array(status($item->ID), [STATUS_FINALIZED, STATUS_CANCELED, STATUS_STALE], strict: true)) {
        return new WP_Error('gutenberg_item_not_cancelable', 'This Gutenberg pending item is already terminal.', [
            'status' => 409,
        ]);
    }

    set_status($item->ID, STATUS_CANCELED);
    clear_lease($item->ID);
    $batch = find_batch($item->post_parent);
    if ($batch instanceof WP_Post && get_items($batch->ID, NON_TERMINAL_STATUSES) === []) {
        set_status($batch->ID, STATUS_CANCELED);
        clear_lease($batch->ID);
    }

    $fresh_item = find_item($item->ID) ?? $item;

    return shape_item($fresh_item);
}
