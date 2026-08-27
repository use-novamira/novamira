<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Novamira Chat admin workbench and REST API.
 */

if (!defined('ABSPATH')) {
    exit();
}

const NOVAMIRA_CHAT_PAGE = 'novamira-chat';

const NOVAMIRA_CHAT_REST_NAMESPACE = 'novamira/v1';

// Base64 inflates size by ~33%, so a single image must stay well under
// NOVAMIRA_CHAT_MAX_ROW_BYTES (the per-session storage guard); otherwise it would be
// blanked on persist and the model would never receive it. Keep the two in sync.
const NOVAMIRA_CHAT_MAX_IMAGE_BYTES = 3_145_728;

const NOVAMIRA_CHAT_MAX_ATTACHMENTS = 4;

const NOVAMIRA_CHAT_MAX_ROW_BYTES = 5_242_880;

const NOVAMIRA_CHAT_MAX_SESSIONS_PER_USER = 50;

const NOVAMIRA_CHAT_CONSENT_META = 'novamira_chat_consent';

/** Whether the Novamira Chat feature is active. */
function novamira_chat_is_enabled(): bool
{
    return \Novamira\Features\features()->is_active('novamira/chat');
}

/**
 * Whether the current user has accepted the one-time Novamira Chat cost notice.
 */
function novamira_chat_user_has_consented(): bool
{
    return get_user_meta(get_current_user_id(), NOVAMIRA_CHAT_CONSENT_META, single: true) === '1';
}

/**
 * Record that the current user accepted the one-time Novamira Chat cost notice.
 *
 * @return array{consented: bool}
 */
function novamira_chat_rest_record_consent(): array
{
    update_user_meta(get_current_user_id(), NOVAMIRA_CHAT_CONSENT_META, meta_value: '1');

    return ['consented' => true];
}

// Priority 70 places Chat directly before Visual (80) in the Novamira submenu.
add_action('admin_menu', callback: 'novamira_register_chat_menu', priority: 70);
add_action('admin_enqueue_scripts', callback: 'novamira_enqueue_chat_assets');
add_action('rest_api_init', callback: 'novamira_register_chat_routes');

function novamira_register_chat_menu(): void
{
    if (!novamira_chat_is_enabled()) {
        return;
    }

    add_submenu_page(
        parent_slug: 'novamira-connect',
        page_title: __('Novamira Chat', domain: 'novamira'),
        menu_title: __('Chat', domain: 'novamira'),
        capability: novamira_manage_capability(),
        menu_slug: NOVAMIRA_CHAT_PAGE,
        callback: 'novamira_render_chat_page',
    );
}

function novamira_enqueue_chat_assets(string $hook): void
{
    if (!novamira_chat_is_enabled() || $hook !== 'novamira_page_' . NOVAMIRA_CHAT_PAGE) {
        return;
    }

    $asset_file = __DIR__ . '/assets/chat/index.asset.php';
    // @mago-expect analysis:mixed-assignment
    $asset = is_file($asset_file) ? (require $asset_file) : ['dependencies' => [], 'version' => NOVAMIRA_VERSION];
    if (!is_array($asset)) {
        $asset = ['dependencies' => [], 'version' => NOVAMIRA_VERSION];
    }

    /** @var list<string> $dependencies */
    $dependencies = is_array($asset['dependencies'] ?? null) ? $asset['dependencies'] : [];
    $version = is_string($asset['version'] ?? null) ? $asset['version'] : NOVAMIRA_VERSION;

    wp_enqueue_script(
        'novamira-chat',
        (string) NOVAMIRA_PLUGIN_URL . 'includes/assets/chat/index.js',
        $dependencies,
        $version,
        args: true,
    );
    wp_enqueue_style(
        'novamira-chat',
        (string) NOVAMIRA_PLUGIN_URL . 'includes/assets/chat/style-index.tsx.css',
        ['wp-components'],
        $version,
    );
    if (function_exists('Novamira\\GutenbergFinalizer\\enqueue_gutenberg_finalizer_runtime_assets')) {
        \Novamira\GutenbergFinalizer\enqueue_gutenberg_finalizer_runtime_assets();
    }
    wp_set_script_translations('novamira-chat', domain: 'novamira');
    $settings_json = wp_json_encode([
        'root' => esc_url_raw(rest_url(NOVAMIRA_CHAT_REST_NAMESPACE)),
        'nonce' => wp_create_nonce('wp_rest'),
        'status' => novamira_chat_status(),
        'connectorsUrl' => admin_url('options-connectors.php'),
        'consented' => novamira_chat_user_has_consented(),
        'backUrl' => add_query_arg(['page' => 'novamira-connect'], admin_url('admin.php')),
    ]);
    if (!is_string($settings_json)) {
        $settings_json = '{}';
    }

    wp_add_inline_script('novamira-chat', 'window.novamiraChat = ' . $settings_json . ';', position: 'before');
}

function novamira_render_chat_page(): void
{
    if (!novamira_chat_is_enabled() || !novamira_current_user_can_manage()) {
        return;
    }

    novamira_render_admin_header(
        logo_file: 'novamira-chat-logo.svg',
        logo_alt: 'Novamira Chat',
        logo_width: 306,
        logo_height: 40,
    );
    ?>
    <div class="wrap novamira-chat-wrap">
        <h1 class="screen-reader-text"><?php esc_html_e('Novamira Chat', domain: 'novamira'); ?></h1>
        <div id="novamira-chat-root"></div>
        <?php novamira_render_chat_gutenberg_finalizer_runtime(); ?>
    </div>
    <?php
}

function novamira_render_chat_gutenberg_finalizer_runtime(): void
{
    if (!function_exists('Novamira\\GutenbergFinalizer\\enqueue_gutenberg_finalizer_runtime_assets')) {
        return;
    }

    ?>
    <div
        id="novamira-gb-finalizer"
        class="novamira-gb-finalizer-runtime novamira-gb-finalizer-runtime--embedded"
        aria-hidden="true"
        style="position:absolute;top:0;left:-10000px;width:1280px;height:900px;overflow:hidden;opacity:0;pointer-events:none;"
    ></div>
    <?php
}

/**
 * @return array{available: bool, reason: string, message: string}
 */
function novamira_chat_status(): array
{
    if (!function_exists('wp_ai_client_prompt')) {
        return [
            'available' => false,
            'reason' => 'missing_ai_client',
            'message' => __(
                'Novamira Chat requires WordPress 7 or newer, with an AI provider configured.',
                domain: 'novamira',
            ),
        ];
    }

    if (!novamira_chat_native_tools_available()) {
        return [
            'available' => false,
            'reason' => 'missing_native_tool_calling',
            'message' => __(
                'Novamira Chat requires WordPress AI Client native function calling support.',
                domain: 'novamira',
            ),
        ];
    }

    return [
        'available' => true,
        'reason' => 'available',
        'message' => __('Ready to run Novamira with native tool calls.', domain: 'novamira'),
    ];
}

function novamira_register_chat_routes(): void
{
    if (!novamira_chat_is_enabled()) {
        return;
    }

    register_rest_route(route_namespace: NOVAMIRA_CHAT_REST_NAMESPACE, route: '/chat/status', args: [
        'methods' => WP_REST_Server::READABLE,
        'callback' => static fn(): array => novamira_chat_status(),
        'permission_callback' => 'novamira_chat_rest_permission',
    ]);

    register_rest_route(route_namespace: NOVAMIRA_CHAT_REST_NAMESPACE, route: '/chat/sessions', args: [
        [
            'methods' => WP_REST_Server::READABLE,
            'callback' => 'novamira_chat_rest_list_sessions',
            'permission_callback' => 'novamira_chat_rest_permission',
        ],
        [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => 'novamira_chat_rest_create_session',
            'permission_callback' => 'novamira_chat_rest_permission',
        ],
    ]);

    register_rest_route(
        route_namespace: NOVAMIRA_CHAT_REST_NAMESPACE,
        route: '/chat/sessions/(?P<id>[a-zA-Z0-9_-]+)',
        args: [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => 'novamira_chat_rest_get_session',
                'permission_callback' => 'novamira_chat_rest_permission',
            ],
            [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => 'novamira_chat_rest_update_session',
                'permission_callback' => 'novamira_chat_rest_permission',
            ],
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => 'novamira_chat_rest_delete_session',
                'permission_callback' => 'novamira_chat_rest_permission',
            ],
        ],
    );

    register_rest_route(route_namespace: NOVAMIRA_CHAT_REST_NAMESPACE, route: '/chat/model-step', args: [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'novamira_chat_rest_model_step',
        'permission_callback' => 'novamira_chat_rest_permission',
    ]);

    register_rest_route(route_namespace: NOVAMIRA_CHAT_REST_NAMESPACE, route: '/chat/tools', args: [
        'methods' => WP_REST_Server::READABLE,
        'callback' => static fn(): array => ['tools' => novamira_chat_discover_tools()],
        'permission_callback' => 'novamira_chat_rest_permission',
    ]);

    register_rest_route(route_namespace: NOVAMIRA_CHAT_REST_NAMESPACE, route: '/chat/models', args: [
        'methods' => WP_REST_Server::READABLE,
        'callback' => 'novamira_chat_rest_list_models',
        'permission_callback' => 'novamira_chat_rest_permission',
    ]);

    register_rest_route(route_namespace: NOVAMIRA_CHAT_REST_NAMESPACE, route: '/chat/consent', args: [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'novamira_chat_rest_record_consent',
        'permission_callback' => 'novamira_chat_rest_permission',
    ]);

    register_rest_route(route_namespace: NOVAMIRA_CHAT_REST_NAMESPACE, route: '/chat/tools/execute', args: [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'novamira_chat_rest_execute_tool',
        'permission_callback' => 'novamira_chat_rest_permission',
    ]);

    register_rest_route(route_namespace: NOVAMIRA_CHAT_REST_NAMESPACE, route: '/chat/approvals', args: [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'novamira_chat_rest_approval',
        'permission_callback' => 'novamira_chat_rest_permission',
    ]);
}

function novamira_chat_rest_permission(): bool|WP_Error
{
    if (!novamira_current_user_can_manage()) {
        return new WP_Error('novamira_chat_forbidden', __('Permission denied.', domain: 'novamira'), [
            'status' => 403,
        ]);
    }

    return true;
}

/**
 * @return array<string, mixed>
 */
function novamira_chat_rest_list_sessions(): array
{
    $sessions = array_values(novamira_chat_get_sessions());
    usort(
        $sessions,
        static fn(array $a, array $b): int => (int) ($b['updated_at'] ?? 0) <=> (int) ($a['updated_at'] ?? 0),
    );

    return ['sessions' => $sessions];
}

function novamira_chat_rest_create_session(WP_REST_Request $request): array|WP_Error
{
    $params = $request->get_json_params();
    $message = is_string($params['message'] ?? null) ? sanitize_textarea_field($params['message']) : '';
    $attachments = novamira_chat_request_attachments($params);
    if (is_wp_error($attachments)) {
        return $attachments;
    }
    if ($message === '' && $attachments === []) {
        return new WP_Error('novamira_chat_missing_message', __('Message is required.', domain: 'novamira'), [
            'status' => 400,
        ]);
    }

    $provider = is_string($params['provider'] ?? null) ? sanitize_key($params['provider']) : '';
    $model = is_string($params['model'] ?? null) ? sanitize_text_field($params['model']) : '';
    $selection = novamira_chat_normalize_model_selection($provider, $model);
    if (is_wp_error($selection)) {
        return $selection;
    }

    $now = time();
    $session = [
        'id' => wp_generate_uuid4(),
        'provider' => $selection['provider'],
        'model' => $selection['model'],
        'status' => 'idle',
        'created_at' => $now,
        'updated_at' => $now,
        'messages' => [
            [
                'id' => wp_generate_uuid4(),
                'role' => 'user',
                'content' => $message,
                'attachments' => $attachments,
                'created_at' => $now,
            ],
        ],
        'tool_calls' => [],
        'allowlist' => [],
        'error' => '',
    ];

    novamira_chat_save_session($session);

    return ['session' => $session];
}

function novamira_chat_rest_list_models(): array|WP_Error
{
    return novamira_chat_list_text_models();
}

function novamira_chat_rest_get_session(WP_REST_Request $request): array|WP_Error
{
    $session = novamira_chat_get_session((string) $request['id']);
    if ($session === null) {
        return novamira_chat_not_found();
    }

    return ['session' => $session];
}

function novamira_chat_rest_delete_session(WP_REST_Request $request): array|WP_Error
{
    $session_id = (string) $request['id'];
    if (novamira_chat_get_session($session_id) === null) {
        return novamira_chat_not_found();
    }

    novamira_chat_delete_session($session_id);

    return ['deleted' => true];
}

/**
 * Apply an optional provider/model change carried in a session update request.
 *
 * @param array<string, mixed> $session
 * @param array<array-key, mixed> $params
 * @return array<string, mixed>|WP_Error
 */
// @mago-expect lint:halstead
function novamira_chat_apply_model_change(array $session, array $params): array|WP_Error
{
    $provider = is_string($params['provider'] ?? null) ? sanitize_key($params['provider']) : '';
    $model = is_string($params['model'] ?? null) ? sanitize_text_field($params['model']) : '';
    if ($provider === '' && $model === '') {
        return $session;
    }

    if ($provider === '') {
        $provider = (string) ($session['provider'] ?? '');
    }
    if ($model === '') {
        $model = (string) ($session['model'] ?? '');
    }

    $selection = novamira_chat_normalize_model_selection($provider, $model);
    if (is_wp_error($selection)) {
        return $selection;
    }

    $session['provider'] = $selection['provider'];
    $session['model'] = $selection['model'];

    return $session;
}

function novamira_chat_rest_update_session(WP_REST_Request $request): array|WP_Error
{
    $session = novamira_chat_get_session((string) $request['id']);
    if ($session === null) {
        return novamira_chat_not_found();
    }

    $params = $request->get_json_params();
    $message = is_string($params['message'] ?? null) ? sanitize_textarea_field($params['message']) : '';
    $edit_message_id = is_string($params['message_id'] ?? null) ? sanitize_text_field($params['message_id']) : '';
    if ($edit_message_id !== '') {
        return novamira_chat_rest_edit_message($session, $edit_message_id, $message, $params);
    }

    $session = novamira_chat_apply_model_change($session, $params);
    if (is_wp_error($session)) {
        return $session;
    }

    $attachments = novamira_chat_request_attachments($params);
    if (is_wp_error($attachments)) {
        return $attachments;
    }
    if ($message === '' && $attachments === []) {
        $session['updated_at'] = time();
        novamira_chat_save_session($session);

        return ['session' => $session];
    }

    $now = time();
    $messages = novamira_chat_session_list($session, key: 'messages');
    $messages[] = [
        'id' => wp_generate_uuid4(),
        'role' => 'user',
        'content' => $message,
        'attachments' => $attachments,
        'created_at' => $now,
    ];
    $session['messages'] = $messages;
    $session['status'] = 'idle';
    $session['updated_at'] = $now;

    novamira_chat_save_session($session);

    return ['session' => $session];
}

/**
 * @param array<string, mixed> $session
 * @param mixed $params
 */
function novamira_chat_rest_edit_message(
    array $session,
    string $message_id,
    string $message,
    mixed $params,
): array|WP_Error {
    $messages = novamira_chat_session_list($session, key: 'messages');
    $message_index = novamira_chat_find_message_index($messages, $message_id);
    if ($message_index === null) {
        return new WP_Error('novamira_chat_message_not_found', __('Message not found.', domain: 'novamira'), [
            'status' => 404,
        ]);
    }

    if (($messages[$message_index]['role'] ?? '') !== 'user') {
        return new WP_Error(
            'novamira_chat_message_not_editable',
            __('Only user messages can be edited.', domain: 'novamira'),
            ['status' => 409],
        );
    }

    $attachments = novamira_chat_message_attachments($messages[$message_index]);
    if (is_array($params) && array_key_exists('attachments', $params)) {
        $attachments = novamira_chat_request_attachments($params);
        if (is_wp_error($attachments)) {
            return $attachments;
        }
    }

    if ($message === '' && $attachments === []) {
        return new WP_Error('novamira_chat_missing_message', __('Message is required.', domain: 'novamira'), [
            'status' => 400,
        ]);
    }

    $now = time();
    $messages[$message_index]['content'] = $message;
    $messages[$message_index]['attachments'] = $attachments;
    $session['messages'] = array_slice($messages, offset: 0, length: $message_index + 1);
    $session['tool_calls'] = novamira_chat_tool_calls_for_messages(
        $session,
        novamira_chat_message_ids($session['messages']),
        (int) ($messages[$message_index]['created_at'] ?? 0),
    );
    $session['status'] = 'idle';
    $session['error'] = '';
    $session['updated_at'] = $now;

    novamira_chat_save_session($session);

    return ['session' => $session];
}

/**
 * @param list<array<string, mixed>> $messages
 */
function novamira_chat_find_message_index(array $messages, string $message_id): ?int
{
    foreach ($messages as $index => $message) {
        if (($message['id'] ?? null) === $message_id) {
            return (int) $index;
        }
    }

    return null;
}

/**
 * @param list<array<string, mixed>> $messages
 * @return list<string>
 */
function novamira_chat_message_ids(array $messages): array
{
    $ids = [];
    foreach ($messages as $message) {
        if (!is_string($message['id'] ?? null) || $message['id'] === '') {
            continue;
        }
        $ids[] = $message['id'];
    }

    return $ids;
}

/**
 * @param array<string, mixed> $session
 * @param list<string> $message_ids
 * @return list<array<string, mixed>>
 */
function novamira_chat_tool_calls_for_messages(array $session, array $message_ids, int $cutoff_created_at): array
{
    $allowed_ids = array_flip($message_ids);
    $tool_calls = [];
    foreach (novamira_chat_session_list($session, key: 'tool_calls') as $tool_call) {
        $message_id = is_string($tool_call['message_id'] ?? null) ? $tool_call['message_id'] : '';
        if ($message_id !== '' && array_key_exists($message_id, $allowed_ids)) {
            $tool_calls[] = $tool_call;
            continue;
        }
        if ((int) ($tool_call['created_at'] ?? 0) < $cutoff_created_at) {
            $tool_calls[] = $tool_call;
        }
    }

    return $tool_calls;
}

/**
 * @param mixed $params
 * @return list<array{id: string, name: string, mime_type: string, data: string, size: int}>|WP_Error
 */
function novamira_chat_request_attachments(mixed $params): array|WP_Error
{
    if (!is_array($params) || !is_array($params['attachments'] ?? null)) {
        return [];
    }

    $attachments = [];
    $items = array_values($params['attachments']);
    if (count($items) > NOVAMIRA_CHAT_MAX_ATTACHMENTS) {
        return new WP_Error(
            'novamira_chat_too_many_attachments',
            sprintf(
                /* translators: %d: Maximum number of attachments. */
                __('Attach up to %d images per message.', domain: 'novamira'),
                NOVAMIRA_CHAT_MAX_ATTACHMENTS,
            ),
            ['status' => 400],
        );
    }

    // @mago-expect analysis:mixed-assignment
    foreach ($items as $item) {
        if (!is_array($item)) {
            return novamira_chat_bad_attachment();
        }

        $attachment = novamira_chat_normalize_attachment($item);
        if (is_wp_error($attachment)) {
            return $attachment;
        }
        $attachments[] = $attachment;
    }

    return $attachments;
}

/**
 * @param array<array-key, mixed> $item
 * @return array{id: string, name: string, mime_type: string, data: string, size: int}|WP_Error
 */
function novamira_chat_normalize_attachment(array $item): array|WP_Error
{
    $name = is_string($item['name'] ?? null) ? sanitize_file_name($item['name']) : '';
    $mime_type = is_string($item['mime_type'] ?? null) ? strtolower(sanitize_mime_type($item['mime_type'])) : '';
    $data = is_string($item['data'] ?? null) ? trim($item['data']) : '';
    $size = is_numeric($item['size'] ?? null) ? (int) $item['size'] : 0;

    if ($name === '') {
        $name = __('Attached image', domain: 'novamira');
    }

    $allowed_mime_types = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    if (!in_array($mime_type, $allowed_mime_types, strict: true)) {
        return new WP_Error(
            'novamira_chat_unsupported_attachment',
            __('Only JPEG, PNG, WebP, and GIF image attachments are supported.', domain: 'novamira'),
            ['status' => 400],
        );
    }

    $parsed = novamira_chat_parse_attachment_data($data, $mime_type, $size);
    if (is_wp_error($parsed)) {
        return $parsed;
    }

    return [
        'id' => (string) wp_generate_uuid4(),
        'name' => $name,
        'mime_type' => $mime_type,
        'data' => 'data:' . $mime_type . ';base64,' . $parsed['base64'],
        'size' => $parsed['size'],
    ];
}

/**
 * @return array{base64: string, size: int}|WP_Error
 */
function novamira_chat_parse_attachment_data(string $data, string $mime_type, int $size): array|WP_Error
{
    $pattern = '#^data:(' . preg_quote($mime_type, delimiter: '#') . ');base64,([A-Za-z0-9+/]*={0,2})$#';
    $matches = null;
    // @mago-expect analysis:redundant-type-comparison
    if (preg_match($pattern, $data, $matches) !== 1 || !is_string($matches[2] ?? null)) {
        return novamira_chat_bad_attachment();
    }

    $base64 = $matches[2];
    $decoded = base64_decode($base64, strict: true);
    if (!is_string($decoded)) {
        return novamira_chat_bad_attachment();
    }

    $decoded_size = strlen($decoded);
    if ($decoded_size < 1 || $decoded_size > NOVAMIRA_CHAT_MAX_IMAGE_BYTES) {
        return novamira_chat_attachment_too_large();
    }
    if ($size > 0 && $size !== $decoded_size) {
        return novamira_chat_attachment_too_large();
    }

    return [
        'base64' => $base64,
        'size' => $decoded_size,
    ];
}

function novamira_chat_attachment_too_large(): WP_Error
{
    return new WP_Error(
        'novamira_chat_attachment_too_large',
        __('Each image attachment must be 3 MB or smaller.', domain: 'novamira'),
        ['status' => 400],
    );
}

function novamira_chat_bad_attachment(): WP_Error
{
    return new WP_Error('novamira_chat_bad_attachment', __('Invalid image attachment.', domain: 'novamira'), [
        'status' => 400,
    ]);
}

function novamira_chat_rest_model_step(WP_REST_Request $request): array|WP_Error
{
    $status = novamira_chat_status();
    if (!$status['available']) {
        return new WP_Error('novamira_chat_unavailable', $status['message'], ['status' => 503]);
    }

    $session = novamira_chat_request_session($request);
    if (is_wp_error($session)) {
        return $session;
    }

    $session['status'] = 'running';
    $session['updated_at'] = time();
    novamira_chat_save_session($session);

    try {
        $tools = novamira_chat_discover_tools();
        $parsed = novamira_chat_generate_native_step($session, $tools);
    } catch (Throwable $e) {
        return novamira_chat_fail_session($session, $e->getMessage());
    }
    if (is_wp_error($parsed)) {
        return novamira_chat_fail_session($session, $parsed->get_error_message());
    }

    $step = novamira_chat_append_model_step($session, $parsed, $tools);
    $session = $step['session'];
    novamira_chat_save_session($session);

    return [
        'session' => $session,
        'message' => $step['message'],
        'tool_calls' => $step['tool_calls'],
    ];
}

/**
 * @param array<string, mixed> $session
 * @param array{content: string, complete: bool, tool_calls: list<array<string, mixed>>} $parsed
 * @param list<array<string, mixed>> $tools
 * @return array{session: array<string, mixed>, message: array<string, mixed>, tool_calls: list<array<string, mixed>>}
 */
function novamira_chat_append_model_step(array $session, array $parsed, array $tools): array
{
    $now = time();
    $message_id = (string) wp_generate_uuid4();
    $message = [
        'id' => $message_id,
        'role' => 'assistant',
        'content' => $parsed['content'],
        'created_at' => $now,
    ];
    if ($parsed['content'] !== '') {
        $messages = novamira_chat_session_list($session, key: 'messages');
        $messages[] = $message;
        $session['messages'] = $messages;
    }

    $created_calls = novamira_chat_build_tool_calls($session, $parsed['tool_calls'], $tools, $now, $message_id);
    $tool_calls = novamira_chat_session_list($session, key: 'tool_calls');

    foreach ($created_calls as $call) {
        $tool_calls[] = $call;
    }
    $session['tool_calls'] = $tool_calls;

    if ($created_calls !== []) {
        $session['status'] = 'waiting_for_tools';
    }
    if ($created_calls === [] && $parsed['complete']) {
        $session['status'] = 'completed';
    }
    if ($created_calls === [] && !$parsed['complete']) {
        $session['status'] = 'idle';
    }

    $session['updated_at'] = $now;

    return ['session' => $session, 'message' => $message, 'tool_calls' => $created_calls];
}

/**
 * @param array<string, mixed> $session
 * @return list<array<string, mixed>>
 */
function novamira_chat_session_list(array $session, string $key): array
{
    $items = is_array($session[$key] ?? null) ? $session[$key] : [];
    $list = [];
    // @mago-expect analysis:mixed-assignment
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $list[] = novamira_chat_assoc_array($item);
    }

    return $list;
}

/**
 * @param array<string, mixed> $session
 * @return list<string>
 */
function novamira_chat_string_list(array $session, string $key): array
{
    $items = is_array($session[$key] ?? null) ? $session[$key] : [];
    $list = [];
    // @mago-expect analysis:mixed-assignment
    foreach ($items as $item) {
        if (!is_string($item)) {
            continue;
        }
        $list[] = $item;
    }

    return $list;
}

/**
 * @param array<array-key, mixed> $items
 * @return array<string, mixed>
 */
function novamira_chat_assoc_array(array $items): array
{
    $assoc = [];
    // @mago-expect analysis:mixed-assignment
    foreach ($items as $key => $value) {
        if (!is_string($key)) {
            continue;
        }
        $assoc[$key] = $value;
    }

    return $assoc;
}

/**
 * @param array<string, mixed> $session
 * @param list<array<string, mixed>> $requested_calls
 * @param list<array<string, mixed>> $tools
 * @return list<array<string, mixed>>
 */
function novamira_chat_build_tool_calls(
    array $session,
    array $requested_calls,
    array $tools,
    int $now,
    string $message_id,
): array {
    $created_calls = [];
    foreach ($requested_calls as $tool_call) {
        $created_calls[] = novamira_chat_build_tool_call_record($session, $tool_call, $tools, $now, $message_id);
    }

    return $created_calls;
}

/**
 * Build one stored tool_call record from a model-requested call, translating the
 * progressive-disclosure meta-tools into either a read-only discovery call or a
 * concrete ability execution keyed on the inner ability name.
 *
 * @param array<string, mixed> $session
 * @param array<string, mixed> $tool_call
 * @param list<array<string, mixed>> $tools
 * @return array<string, mixed>
 */
function novamira_chat_build_tool_call_record(
    array $session,
    array $tool_call,
    array $tools,
    int $now,
    string $message_id,
): array {
    $function_name = is_string($tool_call['function_name'] ?? null)
        ? $tool_call['function_name']
        : (string) ($tool_call['name'] ?? '');
    $model_arguments = is_array($tool_call['arguments'] ?? null)
        ? novamira_chat_assoc_array($tool_call['arguments'])
        : [];
    $base = [
        'id' => (string) ($tool_call['id'] ?? wp_generate_uuid4()),
        'message_id' => $message_id,
        'function_name' => $function_name,
        'model_arguments' => $model_arguments,
        'created_at' => $now,
        'updated_at' => $now,
        'result' => null,
        'error' => '',
    ];

    if (novamira_chat_is_discovery_meta($function_name)) {
        return $base
        + [
            'kind' => 'meta',
            'ability' => $function_name,
            'arguments' => $model_arguments,
            'status' => 'approved',
            'risk' => novamira_chat_readonly_risk(),
            'reason' => '',
        ];
    }

    return $base
    + novamira_chat_resolve_ability_call(
        $session,
        $function_name,
        $model_arguments,
        $tools,
        (string) ($tool_call['name'] ?? ''),
    );
}

/**
 * Resolve the ability-execution fields for an execute-ability call (or a legacy direct
 * ability call), computing risk and approval from the inner ability name.
 *
 * @param array<string, mixed> $session
 * @param array<string, mixed> $model_arguments
 * @param list<array<string, mixed>> $tools
 * @return array{kind: string, ability: string, arguments: array<string, mixed>, status: string, risk: array<string, mixed>, reason: string}
 */
function novamira_chat_resolve_ability_call(
    array $session,
    string $function_name,
    array $model_arguments,
    array $tools,
    string $parsed_name,
): array {
    if ($function_name === 'execute-ability') {
        $ability_name = is_string($model_arguments['ability_name'] ?? null) ? $model_arguments['ability_name'] : '';
        $arguments = is_array($model_arguments['parameters'] ?? null)
            ? novamira_chat_assoc_array($model_arguments['parameters'])
            : [];

        return novamira_chat_ability_call_fields(
            $session,
            $ability_name,
            $arguments,
            novamira_chat_confirmation_reason_from_arguments($model_arguments),
            $tools,
        );
    }

    // Legacy/direct ability call: the arguments carry confirmation_reason inline.
    $reason = novamira_chat_confirmation_reason_from_arguments($model_arguments);
    unset($model_arguments['confirmation_reason']);

    return novamira_chat_ability_call_fields($session, $parsed_name, $model_arguments, $reason, $tools);
}

/**
 * Assemble the shared ability-execution fields (risk + approval status).
 *
 * @param array<string, mixed> $session
 * @param array<string, mixed> $arguments
 * @param list<array<string, mixed>> $tools
 * @return array{kind: string, ability: string, arguments: array<string, mixed>, status: string, risk: array<string, mixed>, reason: string}
 */
function novamira_chat_ability_call_fields(
    array $session,
    string $ability_name,
    array $arguments,
    string $reason,
    array $tools,
): array {
    $tool = novamira_chat_find_tool($ability_name, $tools);
    $risk = $tool !== null && is_array($tool['risk'] ?? null)
        ? novamira_chat_assoc_array($tool['risk'])
        : novamira_chat_unknown_risk();

    return [
        'kind' => 'ability',
        'ability' => $ability_name,
        'arguments' => $arguments,
        'status' => novamira_chat_tool_needs_approval($session, $ability_name, $risk, yolo: false)
            ? 'pending_approval'
            : 'approved',
        'risk' => $risk,
        'reason' => $reason,
    ];
}

/**
 * @param array<array-key, mixed> $arguments
 */
function novamira_chat_confirmation_reason_from_arguments(array $arguments): string
{
    $reason = is_string($arguments['confirmation_reason'] ?? null) ? $arguments['confirmation_reason'] : '';
    $reason = sanitize_text_field($reason);
    $reason = preg_replace(pattern: '/\s+/', replacement: ' ', subject: trim($reason));

    return is_string($reason) ? $reason : '';
}

/**
 * @param array<string, mixed> $tool_call
 * @return array<string, mixed>
 */
function novamira_chat_execution_arguments(array $tool_call): array
{
    $arguments = is_array($tool_call['arguments'] ?? null) ? novamira_chat_assoc_array($tool_call['arguments']) : [];
    unset($arguments['confirmation_reason']);

    return $arguments;
}

/**
 * @param array<string, mixed> $tool_call
 * @return array<string, mixed>
 */
function novamira_chat_model_arguments(array $tool_call): array
{
    // Meta-tools and execute-ability store the exact model-facing arguments so the
    // FunctionCall replayed in history matches what the model actually sent.
    if (is_array($tool_call['model_arguments'] ?? null)) {
        return novamira_chat_assoc_array($tool_call['model_arguments']);
    }

    $arguments = is_array($tool_call['arguments'] ?? null) ? novamira_chat_assoc_array($tool_call['arguments']) : [];
    $reason = is_string($tool_call['reason'] ?? null)
        ? sanitize_text_field($tool_call['reason'])
        : novamira_chat_confirmation_reason_from_arguments($arguments);
    unset($arguments['confirmation_reason']);
    if ($reason !== '') {
        $arguments['confirmation_reason'] = $reason;
    }

    return $arguments;
}

function novamira_chat_rest_approval(WP_REST_Request $request): array|WP_Error
{
    $session = novamira_chat_request_session($request);
    if (is_wp_error($session)) {
        return $session;
    }

    $params = $request->get_json_params();
    $call_id = is_string($params['tool_call_id'] ?? null) ? sanitize_text_field($params['tool_call_id']) : '';
    $decision = is_string($params['decision'] ?? null) ? sanitize_key($params['decision']) : '';
    if ($call_id === '' || !in_array($decision, ['approve', 'deny', 'allow_session', 'yolo'], strict: true)) {
        return novamira_chat_bad_request();
    }

    $session = novamira_chat_approve_tool_call($session, $call_id, $decision);
    if (is_wp_error($session)) {
        return $session;
    }

    novamira_chat_save_session($session);

    return ['session' => $session];
}

/**
 * @param array<string, mixed> $session
 * @return array<string, mixed>|WP_Error
 */
function novamira_chat_approve_tool_call(array $session, string $call_id, string $decision): array|WP_Error
{
    $call_index = novamira_chat_find_tool_call_index($session, $call_id);
    if ($call_index === null) {
        return new WP_Error('novamira_chat_call_not_found', __('Tool call not found.', domain: 'novamira'), [
            'status' => 404,
        ]);
    }

    $tool_calls = novamira_chat_session_list($session, key: 'tool_calls');
    if (($tool_calls[$call_index]['status'] ?? '') !== 'pending_approval') {
        return new WP_Error(
            'novamira_chat_call_not_pending',
            __('This tool call is no longer pending approval.', domain: 'novamira'),
            ['status' => 409],
        );
    }

    $session = novamira_chat_apply_approval($session, $tool_calls[$call_index], $decision);
    $tool_calls[$call_index]['updated_at'] = time();
    $session['tool_calls'] = $tool_calls;
    $session['updated_at'] = time();

    return $session;
}

/**
 * @param array<string, mixed> $session
 * @param array<string, mixed> $tool_call
 * @return array<string, mixed>
 */
function novamira_chat_apply_approval(array $session, array &$tool_call, string $decision): array
{
    if ($decision === 'deny') {
        $tool_call['status'] = 'denied';
        $tool_call['error'] = __('Tool execution was denied by the user.', domain: 'novamira');
        $session['status'] = 'interrupted';
        return $session;
    }

    $tool_call['status'] = 'approved';
    if ($decision === 'allow_session') {
        $ability = (string) ($tool_call['ability'] ?? '');
        if ($ability !== '') {
            $allowlist = novamira_chat_string_list($session, key: 'allowlist');
            $allowlist[] = $ability;
            $session['allowlist'] = array_values(array_unique($allowlist));
        }
    }
    return $session;
}

/**
 * Execute a read-only discovery meta-tool call (list categories/abilities, get schema).
 * No approval is needed; the result is stored and fed back to the model.
 *
 * @param array<string, mixed> $session
 * @return array<string, mixed>
 */
function novamira_chat_execute_meta_call(array $session, int $call_index): array
{
    $tool_calls = novamira_chat_session_list($session, key: 'tool_calls');
    $tool_call = $tool_calls[$call_index];
    $function_name = is_string($tool_call['function_name'] ?? null) ? $tool_call['function_name'] : '';
    $arguments = is_array($tool_call['arguments'] ?? null) ? novamira_chat_assoc_array($tool_call['arguments']) : [];

    $result = novamira_chat_run_meta_discovery($function_name, $arguments, novamira_chat_discover_tools());

    $now = time();
    $tool_calls[$call_index]['status'] = 'succeeded';
    $tool_calls[$call_index]['result'] = $result;
    $tool_calls[$call_index]['updated_at'] = $now;
    $session['tool_calls'] = $tool_calls;
    $session['status'] = 'idle';
    $session['updated_at'] = $now;
    novamira_chat_save_session($session);

    return ['session' => $session, 'tool_call' => $tool_calls[$call_index]];
}

function novamira_chat_rest_execute_tool(WP_REST_Request $request): array|WP_Error
{
    $session = novamira_chat_request_session($request);
    if (is_wp_error($session)) {
        return $session;
    }

    $params = $request->get_json_params();
    $call_id = is_string($params['tool_call_id'] ?? null) ? sanitize_text_field($params['tool_call_id']) : '';
    $call_index = novamira_chat_find_tool_call_index($session, $call_id);
    if ($call_index === null) {
        return new WP_Error('novamira_chat_call_not_found', __('Tool call not found.', domain: 'novamira'), [
            'status' => 404,
        ]);
    }

    if (($session['tool_calls'][$call_index]['kind'] ?? '') === 'meta') {
        return novamira_chat_execute_meta_call($session, $call_index);
    }

    $prepared = novamira_chat_prepare_tool_execution($session, $call_index, ($params['yolo'] ?? false) === true);
    if (is_wp_error($prepared)) {
        return $prepared;
    }

    $tool_calls = novamira_chat_session_list($session, key: 'tool_calls');
    $tool_calls[$call_index]['status'] = 'running';
    $tool_calls[$call_index]['updated_at'] = time();
    $session['tool_calls'] = $tool_calls;
    $session['status'] = 'running';
    novamira_chat_save_session($session);

    $arguments = novamira_chat_execution_arguments($prepared['tool_call']);
    // @mago-expect analysis:mixed-assignment
    $result = $prepared['ability']->execute($arguments);
    if (is_wp_error($result)) {
        return novamira_chat_record_tool_error($session, $call_index, $result->get_error_message());
    }
    // @mago-expect analysis:mixed-assignment
    $result = novamira_chat_prepare_tool_result($prepared['ability_name'], $result);

    $now = time();
    $tool_calls = novamira_chat_session_list($session, key: 'tool_calls');
    $tool_calls[$call_index]['status'] = 'succeeded';
    $tool_calls[$call_index]['result'] = $result;
    $tool_calls[$call_index]['updated_at'] = $now;
    $session['tool_calls'] = $tool_calls;
    $session['status'] = 'idle';
    $session['updated_at'] = $now;
    novamira_chat_save_session($session);

    return ['session' => $session, 'tool_call' => $tool_calls[$call_index]];
}

function novamira_chat_prepare_tool_result(string $ability_name, mixed $result): mixed
{
    if (!novamira_chat_is_gutenberg_ability($ability_name) || !is_array($result)) {
        return $result;
    }

    return novamira_chat_prepare_gutenberg_tool_result($result);
}

/**
 * @param array<array-key, mixed> $result
 * @return array<array-key, mixed>
 */
function novamira_chat_prepare_gutenberg_tool_result(array $result): array
{
    // @mago-expect analysis:mixed-assignment
    foreach ($result as $key => $value) {
        if (!is_array($value)) {
            continue;
        }

        $result[$key] = novamira_chat_prepare_gutenberg_tool_result($value);
    }

    if (array_key_exists('finalization_url', $result) && is_string($result['finalization_url'])) {
        $result['finalization_url'] = novamira_chat_url();
    }

    if (!is_array($result['finalizer_runtime'] ?? null)) {
        return $result;
    }

    $runtime = novamira_chat_assoc_array($result['finalizer_runtime']);
    $runtime['dashboard_url'] = novamira_chat_url();
    $result['finalizer_runtime'] = $runtime;
    $result['user_instruction'] = novamira_chat_gutenberg_user_instruction($result, $runtime);

    return $result;
}

function novamira_chat_url(): string
{
    return add_query_arg(['page' => NOVAMIRA_CHAT_PAGE], admin_url('admin.php'));
}

/**
 * @param array<array-key, mixed> $result
 * @param array<string, mixed> $runtime
 */
function novamira_chat_gutenberg_user_instruction(array $result, array $runtime): string
{
    $batch_id = novamira_chat_gutenberg_result_batch_id($result);
    $batch_label = novamira_chat_gutenberg_result_batch_label($result);
    $watch = novamira_chat_gutenberg_watch_instruction($runtime);
    $online = ($runtime['online'] ?? false) === true;
    $can_finalize = ($runtime['can_finalize_batch'] ?? null) !== false;

    if (!$online) {
        return sprintf(
            'The Novamira Chat page embeds the Gutenberg finalizer runtime, but it is currently offline. Ask the user to reload %s before treating queued Gutenberg changes as live. %s',
            novamira_chat_url(),
            $watch,
        );
    }

    if ($batch_id <= 0) {
        return sprintf(
            'The Novamira Chat page embeds the Gutenberg finalizer runtime and it is online. Use the Gutenberg queue tools normally; after enabling a batch, check the batch status until finalization completes. %s',
            $watch,
        );
    }

    if (!$can_finalize) {
        return sprintf(
            'The Novamira Chat page is online, but the current browser user may not be able to finalize Gutenberg batch #%d%s. Ask the user to reload Novamira Chat as a user who can edit every target. Do not treat queued Gutenberg changes as live until finalization completes. %s',
            $batch_id,
            $batch_label !== '' ? ': ' . $batch_label : '',
            $watch,
        );
    }

    return sprintf(
        'The Novamira Chat page embeds the Gutenberg finalizer runtime and should automatically finalize Gutenberg batch #%d%s. Do not ask the user to open a separate Block Editor Queue page unless the embedded runtime goes offline. Do not treat queued Gutenberg changes as live until the batch reports finalized. %s',
        $batch_id,
        $batch_label !== '' ? ': ' . $batch_label : '',
        $watch,
    );
}

/**
 * @param array<array-key, mixed> $result
 */
function novamira_chat_gutenberg_result_batch_id(array $result): int
{
    foreach (['batch_id', 'id'] as $key) {
        if (is_scalar($result[$key] ?? null)) {
            return (int) $result[$key];
        }
    }

    return 0;
}

/**
 * @param array<array-key, mixed> $result
 */
function novamira_chat_gutenberg_result_batch_label(array $result): string
{
    foreach (['label', 'batch_label'] as $key) {
        if (is_scalar($result[$key] ?? null)) {
            return trim((string) $result[$key]);
        }
    }

    return '';
}

/**
 * @param array<string, mixed> $runtime
 */
function novamira_chat_gutenberg_watch_instruction(array $runtime): string
{
    unset($runtime);

    return 'Use novamira/gutenberg-get-pending-batch or novamira/gutenberg-list-pending-batches to verify the batch reaches finalized, failed, conflicted, canceled, or stale.';
}

/**
 * @param array<string, mixed> $session
 * @return array{ability: WP_Ability, ability_name: string, tool_call: array<string, mixed>}|WP_Error
 */
function novamira_chat_prepare_tool_execution(array $session, int $call_index, bool $yolo): array|WP_Error
{
    $tool_call = is_array($session['tool_calls'][$call_index] ?? null) ? $session['tool_calls'][$call_index] : [];
    $ability_name = (string) ($tool_call['ability'] ?? '');
    $ability = function_exists('wp_get_ability') ? wp_get_ability($ability_name) : null;
    if (!$ability instanceof WP_Ability) {
        return new WP_Error('novamira_chat_ability_unavailable', __('Ability is not available.', domain: 'novamira'), [
            'status' => 404,
        ]);
    }

    $risk = is_array($tool_call['risk'] ?? null)
        ? novamira_chat_assoc_array($tool_call['risk'])
        : novamira_chat_ability_risk($ability);
    $approval_missing =
        novamira_chat_tool_needs_approval($session, $ability_name, $risk, $yolo)
        && ($tool_call['status'] ?? '') !== 'approved';
    if ($approval_missing) {
        return new WP_Error(
            'novamira_chat_approval_required',
            __('This tool call requires approval before execution.', domain: 'novamira'),
            ['status' => 409],
        );
    }
    if (!in_array($tool_call['status'] ?? '', ['approved', 'pending_approval'], strict: true)) {
        return new WP_Error(
            'novamira_chat_call_not_executable',
            __('This tool call cannot be executed in its current state.', domain: 'novamira'),
            ['status' => 409],
        );
    }

    return [
        'ability' => $ability,
        'ability_name' => $ability_name,
        'tool_call' => novamira_chat_assoc_array($tool_call),
    ];
}

/**
 * @return array<string, mixed>|WP_Error
 */
function novamira_chat_request_session(WP_REST_Request $request): array|WP_Error
{
    $params = $request->get_json_params();
    $session_id = is_string($params['session_id'] ?? null) ? sanitize_text_field($params['session_id']) : '';
    if ($session_id === '' && is_string($request['id'] ?? null)) {
        $session_id = sanitize_text_field($request['id']);
    }

    $session = novamira_chat_get_session($session_id);
    return $session ?? novamira_chat_not_found();
}

function novamira_chat_not_found(): WP_Error
{
    return new WP_Error('novamira_chat_not_found', __('Chat not found.', domain: 'novamira'), [
        'status' => 404,
    ]);
}

function novamira_chat_bad_request(): WP_Error
{
    return new WP_Error('novamira_chat_bad_request', __('Invalid request.', domain: 'novamira'), [
        'status' => 400,
    ]);
}

/**
 * @return array<string, array<string, mixed>>
 */
function novamira_chat_get_sessions(): array
{
    $user_id = get_current_user_id();
    if ($user_id <= 0) {
        return [];
    }

    novamira_chat_ensure_storage_ready();

    $wpdb = novamira_chat_wpdb();
    $table = novamira_chat_sessions_table();
    $rows = novamira_chat_select_sessions_for_user($wpdb, $table, $user_id);

    $sessions = [];
    foreach ($rows as $row) {
        $session_id = is_string($row['id'] ?? null) ? $row['id'] : '';
        $session = novamira_chat_decode_session_json($row['data'] ?? null, $session_id);
        if ($session_id === '' || $session === null) {
            continue;
        }
        $sessions[$session_id] = $session;
    }

    return $sessions;
}

/**
 * @return array<string, mixed>|null
 */
function novamira_chat_get_session(string $session_id): ?array
{
    $user_id = get_current_user_id();
    if ($session_id === '' || $user_id <= 0) {
        return null;
    }

    novamira_chat_ensure_storage_ready();

    $wpdb = novamira_chat_wpdb();
    $table = novamira_chat_sessions_table();
    $data = novamira_chat_select_session_data($wpdb, $table, $session_id, $user_id);

    return novamira_chat_decode_session_json($data, $session_id);
}

/**
 * @param array<string, mixed> $session
 */
function novamira_chat_save_session(array $session): void
{
    $session_id = is_string($session['id'] ?? null) ? $session['id'] : '';
    $user_id = get_current_user_id();
    if ($session_id === '' || strlen($session_id) > 64 || $user_id <= 0) {
        return;
    }

    novamira_chat_ensure_storage_ready();

    $wpdb = novamira_chat_wpdb();
    $table = novamira_chat_sessions_table();
    $existing_user_id = novamira_chat_select_session_owner($wpdb, $table, $session_id);
    if ($existing_user_id !== null && (int) $existing_user_id !== $user_id) {
        return;
    }

    $now = time();
    if (!is_numeric($session['created_at'] ?? null)) {
        $session['created_at'] = $now;
    }
    if (!is_numeric($session['updated_at'] ?? null)) {
        $session['updated_at'] = $now;
    }

    $json = novamira_chat_encode_session_for_storage($session);
    if ($json === null) {
        return;
    }

    $row = novamira_chat_storage_row($session, $session_id, $user_id, $json);
    novamira_chat_persist_session_row($session_id, $user_id, $row, $existing_user_id);

    novamira_chat_prune_sessions_for_user($user_id);
}

function novamira_chat_delete_session(string $session_id): void
{
    $user_id = get_current_user_id();
    if ($session_id === '' || $user_id <= 0) {
        return;
    }

    novamira_chat_ensure_storage_ready();

    $wpdb = novamira_chat_wpdb();
    $deleted = $wpdb->delete(
        novamira_chat_sessions_table(),
        [
            'id' => $session_id,
            'user_id' => $user_id,
        ],
        ['%s', '%d'],
    );
    if ($deleted === false) {
        novamira_chat_log_storage_failure($session_id);
    }
}

/**
 * Inserts a new session row, or updates the existing one, logging on failure.
 * A null $existing_user_id means no row exists yet.
 *
 * @param array{id: string, user_id: int, created_at: string, updated_at: string, data: string} $row
 */
function novamira_chat_persist_session_row(string $session_id, int $user_id, array $row, ?int $existing_user_id): void
{
    $wpdb = novamira_chat_wpdb();
    $table = novamira_chat_sessions_table();

    if ($existing_user_id !== null) {
        if (novamira_chat_update_session_row($wpdb, $table, $session_id, $user_id, $row) === false) {
            novamira_chat_log_storage_failure($session_id);
        }
        return;
    }

    if ($wpdb->insert($table, $row, ['%s', '%d', '%s', '%s', '%s']) !== false) {
        return;
    }

    // A concurrent request created this id first (or the write failed). Retry as an
    // owner-scoped update so the save is not lost and a racing row belonging to
    // another user is never overwritten.
    $recovered = novamira_chat_update_session_row($wpdb, $table, $session_id, $user_id, $row);
    if ($recovered === false || $recovered === 0) {
        novamira_chat_log_storage_failure($session_id);
    }
}

/**
 * Updates an existing session row, scoped to its owner so a racing row that
 * belongs to another user is never overwritten.
 *
 * @param array{id: string, user_id: int, created_at: string, updated_at: string, data: string} $row
 * @return int|false Rows affected, or false on a database error.
 */
function novamira_chat_update_session_row(
    wpdb $wpdb,
    string $table,
    string $session_id,
    int $user_id,
    array $row,
): int|false {
    return $wpdb->update(
        $table,
        [
            'updated_at' => $row['updated_at'],
            'data' => $row['data'],
        ],
        [
            'id' => $session_id,
            'user_id' => $user_id,
        ],
        ['%s', '%s'],
        ['%s', '%d'],
    );
}

/**
 * Records a session persistence failure. Logs the session id and the database
 * error only, never the session payload.
 */
function novamira_chat_log_storage_failure(string $session_id): void
{
    $wpdb = novamira_chat_wpdb();
    error_log(sprintf('Novamira Chat: failed to persist session %s. %s', $session_id, $wpdb->last_error));
}

function novamira_chat_ensure_storage_ready(): void
{
    if (function_exists('novamira_chat_schema_maybe_install')) {
        novamira_chat_schema_maybe_install();
    }
}

/**
 * @return list<array<string, mixed>>
 */
function novamira_chat_select_sessions_for_user(wpdb $wpdb, string $table, int $user_id): array
{
    // @mago-expect analysis:possibly-invalid-argument -- The table name is derived from $wpdb->prefix.
    $sql = $wpdb->prepare(
        "SELECT id, data FROM {$table} WHERE user_id = %d ORDER BY updated_at DESC, id DESC LIMIT %d",
        $user_id,
        NOVAMIRA_CHAT_MAX_SESSIONS_PER_USER,
    );
    if (!is_string($sql)) {
        return [];
    }

    $rows = $wpdb->get_results($sql, 'ARRAY_A');
    if (!is_array($rows)) {
        return [];
    }

    $clean = [];
    foreach ($rows as $row) {
        $clean[] = novamira_chat_assoc_array($row);
    }

    return $clean;
}

function novamira_chat_select_session_data(wpdb $wpdb, string $table, string $session_id, int $user_id): ?string
{
    // @mago-expect analysis:possibly-invalid-argument -- The table name is derived from $wpdb->prefix.
    $sql = $wpdb->prepare("SELECT data FROM {$table} WHERE id = %s AND user_id = %d", $session_id, $user_id);
    if (!is_string($sql)) {
        return null;
    }

    $data = $wpdb->get_var($sql);

    return is_string($data) ? $data : null;
}

function novamira_chat_select_session_owner(wpdb $wpdb, string $table, string $session_id): ?int
{
    // @mago-expect analysis:possibly-invalid-argument -- The table name is derived from $wpdb->prefix.
    $sql = $wpdb->prepare("SELECT user_id FROM {$table} WHERE id = %s", $session_id);
    if (!is_string($sql)) {
        return null;
    }

    $owner = $wpdb->get_var($sql);
    if ($owner === null) {
        return null;
    }

    return (int) $owner;
}

/**
 * @param array<string, mixed> $session
 * @return array{id: string, user_id: int, created_at: string, updated_at: string, data: string}
 */
function novamira_chat_storage_row(array $session, string $session_id, int $user_id, string $json): array
{
    return [
        'id' => $session_id,
        'user_id' => $user_id,
        'created_at' => novamira_chat_mysql_datetime((int) $session['created_at']),
        'updated_at' => novamira_chat_mysql_datetime((int) $session['updated_at']),
        'data' => $json,
    ];
}

/**
 * @return array<string, mixed>|null
 */
function novamira_chat_decode_session_json(mixed $data, string $session_id): ?array
{
    if (!is_string($data) || $data === '') {
        return null;
    }

    // @mago-expect analysis:mixed-assignment -- JSON storage is decoded and validated before use.
    $decoded = json_decode($data, associative: true);
    if (!is_array($decoded)) {
        return null;
    }

    $session = novamira_chat_assoc_array($decoded);
    if (!is_string($session['id'] ?? null) || $session['id'] === '') {
        $session['id'] = $session_id;
    }

    return $session;
}

function novamira_chat_max_row_bytes(): int
{
    $max = (int) apply_filters('novamira_chat_max_row_bytes', NOVAMIRA_CHAT_MAX_ROW_BYTES);

    return $max > 0 ? $max : NOVAMIRA_CHAT_MAX_ROW_BYTES;
}

/**
 * @param array<string, mixed> $session
 */
function novamira_chat_encode_session_for_storage(array &$session): ?string
{
    $json = novamira_chat_json_encode($session);
    if ($json === null) {
        return null;
    }

    $max_bytes = novamira_chat_max_row_bytes();
    if (strlen($json) <= $max_bytes) {
        return $json;
    }

    $session = novamira_chat_prune_attachment_data($session, $max_bytes);
    $json = novamira_chat_json_encode($session);
    if ($json === null || strlen($json) > $max_bytes) {
        return null;
    }

    return $json;
}

/**
 * @param array<string, mixed> $session
 */
function novamira_chat_json_encode(array $session): ?string
{
    $json = wp_json_encode($session, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    return is_string($json) ? $json : null;
}

/**
 * @param array<string, mixed> $session
 * @return array<string, mixed>
 */
function novamira_chat_prune_attachment_data(array $session, int $max_bytes): array
{
    $messages = novamira_chat_session_list($session, key: 'messages');
    foreach ($messages as $message_index => $message) {
        $attachments = is_array($message['attachments'] ?? null) ? $message['attachments'] : [];
        // @mago-expect analysis:mixed-assignment
        foreach ($attachments as $attachment_index => $attachment) {
            if (!is_array($attachment)) {
                continue;
            }
            if (!is_string($attachment['data'] ?? null) || $attachment['data'] === '') {
                continue;
            }

            $attachment['data'] = '';
            $attachments[$attachment_index] = $attachment;
            $message['attachments'] = $attachments;
            $messages[$message_index] = $message;
            $session['messages'] = $messages;

            $json = novamira_chat_json_encode($session);
            if ($json !== null && strlen($json) <= $max_bytes) {
                return $session;
            }
        }
    }

    return $session;
}

function novamira_chat_mysql_datetime(int $timestamp): string
{
    if ($timestamp <= 0) {
        $timestamp = time();
    }

    return gmdate('Y-m-d H:i:s', $timestamp);
}

function novamira_chat_prune_sessions_for_user(int $user_id): void
{
    if ($user_id <= 0) {
        return;
    }

    $wpdb = novamira_chat_wpdb();
    $table = novamira_chat_sessions_table();
    $old_ids = novamira_chat_select_prunable_session_ids($wpdb, $table, $user_id);

    foreach ($old_ids as $old_id) {
        if ($old_id === '') {
            continue;
        }

        $wpdb->delete(
            $table,
            [
                'id' => $old_id,
                'user_id' => $user_id,
            ],
            ['%s', '%d'],
        );
    }
}

/**
 * @return list<string>
 */
function novamira_chat_select_prunable_session_ids(wpdb $wpdb, string $table, int $user_id): array
{
    // @mago-expect analysis:possibly-invalid-argument -- The table name is derived from $wpdb->prefix.
    $sql = $wpdb->prepare(
        "SELECT id FROM {$table} WHERE user_id = %d ORDER BY updated_at DESC, id DESC LIMIT 1000 OFFSET %d",
        $user_id,
        NOVAMIRA_CHAT_MAX_SESSIONS_PER_USER,
    );
    if (!is_string($sql)) {
        return [];
    }

    $old_ids = $wpdb->get_col($sql);
    $clean = [];
    // @mago-expect analysis:mixed-assignment
    foreach ($old_ids as $old_id) {
        if (!is_string($old_id) || $old_id === '') {
            continue;
        }

        $clean[] = $old_id;
    }

    return $clean;
}

/**
 * @return array{providers: list<array{id: string, name: string, configured: bool, models: list<array{id: string, name: string, supports_image_input: bool}>}>, default: array{provider: string, model: string}}|WP_Error
 */
// @mago-expect lint:cyclomatic-complexity
function novamira_chat_list_text_models(): array|WP_Error
{
    if (
        !class_exists('WordPress\\AiClient\\AiClient')
        || !class_exists('WordPress\\AiClient\\Messages\\DTO\\Message')
        || !class_exists('WordPress\\AiClient\\Messages\\DTO\\MessagePart')
        || !class_exists('WordPress\\AiClient\\Files\\DTO\\File')
        || !class_exists('WordPress\\AiClient\\Messages\\Enums\\MessageRoleEnum')
        || !class_exists('WordPress\\AiClient\\Providers\\Models\\DTO\\ModelConfig')
        || !class_exists('WordPress\\AiClient\\Providers\\Models\\DTO\\ModelRequirements')
        || !class_exists('WordPress\\AiClient\\Providers\\Models\\Enums\\CapabilityEnum')
        || !novamira_chat_native_tools_available()
    ) {
        return new WP_Error(
            'novamira_chat_missing_ai_client',
            __('WordPress AI Client native function calling model discovery is not available.', domain: 'novamira'),
            ['status' => 503],
        );
    }

    try {
        $registry = \WordPress\AiClient\AiClient::defaultRegistry();
        if (!method_exists($registry, 'getRegisteredProviderIds')) {
            return new WP_Error(
                'novamira_chat_missing_provider_registry',
                __('WordPress AI Client provider discovery is not available.', domain: 'novamira'),
                ['status' => 503],
            );
        }

        $requirements = novamira_chat_text_model_requirements();
        $image_requirements = novamira_chat_image_model_requirements();

        $providers = [];
        $default_provider = '';
        $default_model = '';
        foreach ($registry->getRegisteredProviderIds() as $provider_id) {
            if ($provider_id === '') {
                continue;
            }

            $configured = method_exists($registry, 'isProviderConfigured')
                ? $registry->isProviderConfigured($provider_id)
                : true;
            $provider_name = novamira_chat_provider_name($registry, $provider_id);

            $models = [];
            if ($configured) {
                $models = novamira_chat_provider_model_rows(
                    $registry,
                    $provider_id,
                    $requirements,
                    $image_requirements,
                );
            }

            if ($default_provider === '' && $models !== []) {
                $default_provider = $provider_id;
                $default_model = $models[0]['id'];
            }

            $providers[] = [
                'id' => $provider_id,
                'name' => $provider_name,
                'configured' => $configured,
                'models' => $models,
            ];
        }

        return [
            'providers' => $providers,
            'default' => [
                'provider' => $default_provider,
                'model' => $default_model,
            ],
        ];
    } catch (Throwable $e) {
        return new WP_Error('novamira_chat_model_discovery_failed', $e->getMessage(), ['status' => 500]);
    }
}

function novamira_chat_text_model_requirements(): object
{
    $message =
        new \WordPress\AiClient\Messages\DTO\Message(\WordPress\AiClient\Messages\Enums\MessageRoleEnum::user(), [new \WordPress\AiClient\Messages\DTO\MessagePart(
            'test',
        )]);

    return \WordPress\AiClient\Providers\Models\DTO\ModelRequirements::fromPromptData(
        \WordPress\AiClient\Providers\Models\Enums\CapabilityEnum::textGeneration(),
        [$message],
        novamira_chat_tool_model_config(),
    );
}

function novamira_chat_image_model_requirements(): object
{
    $message =
        new \WordPress\AiClient\Messages\DTO\Message(\WordPress\AiClient\Messages\Enums\MessageRoleEnum::user(), [
            new \WordPress\AiClient\Messages\DTO\MessagePart('test'),
            new \WordPress\AiClient\Messages\DTO\MessagePart(new \WordPress\AiClient\Files\DTO\File(
                'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=',
                'image/png',
            )),
        ]);

    return \WordPress\AiClient\Providers\Models\DTO\ModelRequirements::fromPromptData(
        \WordPress\AiClient\Providers\Models\Enums\CapabilityEnum::textGeneration(),
        [$message],
        novamira_chat_tool_model_config(),
    );
}

function novamira_chat_tool_model_config(): \WordPress\AiClient\Providers\Models\DTO\ModelConfig
{
    $config = new \WordPress\AiClient\Providers\Models\DTO\ModelConfig();
    $config->setFunctionDeclarations([novamira_chat_support_function_declaration()]);

    return $config;
}

function novamira_chat_provider_name(mixed $registry, string $provider_id): string
{
    if (!is_object($registry) || !method_exists($registry, 'getProviderClassName')) {
        return $provider_id;
    }

    // @mago-expect analysis:mixed-assignment
    $class_name = $registry->getProviderClassName($provider_id);
    if (!is_string($class_name) || !method_exists($class_name, 'metadata')) {
        return $provider_id;
    }

    // @mago-expect analysis:mixed-assignment
    $metadata = $class_name::metadata();
    if (!is_object($metadata) || !method_exists($metadata, 'getName')) {
        return $provider_id;
    }

    // @mago-expect analysis:mixed-assignment
    $name = $metadata->getName();

    return is_string($name) && $name !== '' ? $name : $provider_id;
}

/**
 * @return list<array{id: string, name: string, supports_image_input: bool}>
 */
function novamira_chat_provider_model_rows(
    mixed $registry,
    string $provider_id,
    object $requirements,
    object $image_requirements,
): array {
    $image_model_ids = novamira_chat_provider_model_ids($registry, $provider_id, $image_requirements);
    $models = [];
    foreach (novamira_chat_provider_model_metadata($registry, $provider_id, $requirements) as $model_metadata) {
        $model_id = novamira_chat_model_metadata_id($model_metadata);
        if ($model_id === '') {
            continue;
        }

        $models[] = [
            'id' => $model_id,
            'name' => novamira_chat_model_metadata_name($model_metadata, $model_id),
            'supports_image_input' => in_array($model_id, $image_model_ids, strict: true),
        ];
    }

    return $models;
}

/**
 * @return list<string>
 */
function novamira_chat_provider_model_ids(mixed $registry, string $provider_id, object $requirements): array
{
    $model_ids = [];
    foreach (novamira_chat_provider_model_metadata($registry, $provider_id, $requirements) as $model_metadata) {
        $model_id = novamira_chat_model_metadata_id($model_metadata);
        if ($model_id !== '') {
            $model_ids[] = $model_id;
        }
    }

    return $model_ids;
}

/**
 * @return list<object>
 */
function novamira_chat_provider_model_metadata(mixed $registry, string $provider_id, object $requirements): array
{
    if (!is_object($registry) || !method_exists($registry, 'findProviderModelsMetadataForSupport')) {
        return [];
    }

    $metadata_items = [];
    /** @var iterable<mixed> $found_metadata */
    // @mago-expect analysis:mixed-assignment
    $found_metadata = $registry->findProviderModelsMetadataForSupport($provider_id, $requirements);
    foreach ($found_metadata as $model_metadata) {
        if (!is_object($model_metadata)) {
            continue;
        }
        $metadata_items[] = $model_metadata;
    }

    return $metadata_items;
}

function novamira_chat_model_metadata_id(object $model_metadata): string
{
    if (!method_exists($model_metadata, 'getId')) {
        return '';
    }

    // @mago-expect analysis:mixed-assignment
    $model_id = $model_metadata->getId();

    return is_string($model_id) ? $model_id : '';
}

function novamira_chat_model_metadata_name(object $model_metadata, string $fallback): string
{
    if (!method_exists($model_metadata, 'getName')) {
        return $fallback;
    }

    // @mago-expect analysis:mixed-assignment
    $name = $model_metadata->getName();

    return is_string($name) && $name !== '' ? $name : $fallback;
}

/**
 * @return array{provider: string, model: string}|WP_Error
 */
function novamira_chat_normalize_model_selection(string $provider, string $model): array|WP_Error
{
    $catalog = novamira_chat_list_text_models();
    if (is_wp_error($catalog)) {
        return $catalog;
    }

    if ($provider === '') {
        $provider = $catalog['default']['provider'];
    }
    if ($model === '') {
        $model = $provider === $catalog['default']['provider'] ? $catalog['default']['model'] : '';
    }

    foreach ($catalog['providers'] as $provider_entry) {
        // @mago-expect analysis:redundant-null-coalesce
        if (($provider_entry['id'] ?? '') !== $provider) {
            continue;
        }
        foreach ($provider_entry['models'] as $model_entry) {
            // @mago-expect analysis:redundant-null-coalesce
            if (($model_entry['id'] ?? '') === $model) {
                return [
                    'provider' => $provider,
                    'model' => $model,
                ];
            }
        }
    }

    return new WP_Error(
        'novamira_chat_invalid_model',
        __('Select a configured provider and model that supports native function calling.', domain: 'novamira'),
        ['status' => 400],
    );
}

/**
 * @return list<array<string, mixed>>
 */
function novamira_chat_discover_tools(): array
{
    if (!function_exists('wp_get_abilities')) {
        return [];
    }

    $tools = [];
    foreach (wp_get_abilities() as $ability) {
        $meta = $ability->get_meta();
        if (!novamira_ability_is_exposed($meta)) {
            continue;
        }
        if (($meta['mcp']['type'] ?? 'tool') !== 'tool') {
            continue;
        }
        if (novamira_ability_is_hub_hidden($ability->get_name())) {
            continue;
        }
        if (novamira_chat_is_hidden_tool($ability->get_name())) {
            continue;
        }

        $category_slug = $ability->get_category();
        $category = $category_slug !== '' ? wp_get_ability_category($category_slug) : null;
        $tools[] = [
            'name' => $ability->get_name(),
            'label' => $ability->get_label(),
            'description' => novamira_chat_tool_description($ability),
            'category' => $category !== null ? $category->get_label() : $category_slug,
            'input_schema' => $ability->get_input_schema(),
            'output_schema' => $ability->get_output_schema(),
            'risk' => novamira_chat_ability_risk($ability),
        ];
    }

    usort($tools, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));

    return $tools;
}

function novamira_chat_is_hidden_tool(string $ability_name): bool
{
    return $ability_name === 'novamira/create-admin-access-link';
}

function novamira_chat_tool_description(WP_Ability $ability): string
{
    $description = $ability->get_description();
    if (!novamira_chat_is_gutenberg_ability($ability->get_name())) {
        return $description;
    }
    $description = novamira_chat_gutenberg_tool_description($description);

    return trim(
        $description . ' '
            . implode(' ', [
                'Novamira Chat embeds the Gutenberg finalizer runtime on this page.',
                'Do not ask the user to open or keep a separate Block Editor Queue/finalization page while this dashboard is open.',
                'After enabling a batch, use Gutenberg pending-batch status tools until the batch is finalized, failed, or conflicted.',
                'If finalizer_runtime.online is false, ask the user to reload Novamira Chat.',
            ]),
    );
}

function novamira_chat_gutenberg_tool_description(string $description): string
{
    return str_replace(
        [
            'an open Block Editor Queue page completes it',
            'If the Block Editor Queue page is open, it can pick up the batch automatically; otherwise the response tells the agent to ask the user to open the generic Block Editor Queue page.',
            'The response also includes token-gated SSE and poll URLs agents can watch with curl.',
            'reports the Block Editor Queue runtime with curl SSE/poll URLs',
            'curl SSE/poll URLs',
            'This also reports the Block Editor Queue runtime plus curl SSE/poll URLs so agents can ask the user to open the queue page before queueing static/native block changes.',
            'Reports whether the Novamira Block Editor Queue admin page is open and heartbeating, including token-gated SSE and poll URLs that agents can watch with curl.',
            'Returns compact status, target summaries, validation errors, Block Editor Queue runtime status, and curl SSE/poll URLs for one pending batch.',
            'current Block Editor Queue runtime status and curl SSE/poll URLs',
            'generic Block Editor Queue admin page URL',
            'generic Block Editor Queue page URL',
            'send the finalization link to the user',
        ],
        [
            'the embedded Novamira Chat finalizer runtime completes it',
            'If the embedded Novamira Chat finalizer runtime is online, it can pick up the batch automatically.',
            'Novamira Chat verifies completion with Gutenberg pending-batch status tools.',
            'reports the Gutenberg finalizer runtime status',
            'Gutenberg finalizer runtime status',
            'This also reports the Gutenberg finalizer runtime status before queueing static/native block changes.',
            'Reports whether the Novamira Gutenberg finalizer runtime is open and heartbeating.',
            'Returns compact status, target summaries, validation errors, and Gutenberg finalizer runtime status for one pending batch.',
            'current Gutenberg finalizer runtime status',
            'Novamira Chat admin page URL',
            'Novamira Chat admin page URL',
            'let the embedded Novamira Chat finalizer runtime complete the queued batch',
        ],
        $description,
    );
}

function novamira_chat_is_gutenberg_ability(string $name): bool
{
    return str_starts_with($name, 'novamira/gutenberg-');
}

/**
 * @return array{readonly: bool, destructive: bool, code_execution: bool, filesystem_write: bool, unknown: bool, requires_approval: bool, reason: string}
 */
function novamira_chat_ability_risk(WP_Ability $ability): array
{
    $name = $ability->get_name();
    $category = $ability->get_category();
    $meta = $ability->get_meta();
    $annotations = is_array($meta['annotations'] ?? null) ? $meta['annotations'] : [];
    $readonly = ($annotations['readonly'] ?? null) === true;
    $destructive = ($annotations['destructive'] ?? null) === true;
    $code_execution = novamira_chat_is_code_execution_ability($name, $category);
    $filesystem_write = novamira_chat_is_filesystem_write_ability($name);
    $unknown = ($annotations['readonly'] ?? null) !== true && ($annotations['readonly'] ?? null) !== false;
    $requires_approval = !$readonly || $destructive || $code_execution || $filesystem_write;

    return [
        'readonly' => $readonly,
        'destructive' => $destructive,
        'code_execution' => $code_execution,
        'filesystem_write' => $filesystem_write,
        'unknown' => $unknown,
        'requires_approval' => $requires_approval,
        'reason' => novamira_chat_risk_reason(
            $requires_approval,
            $code_execution,
            $filesystem_write,
            $destructive,
            $unknown,
        ),
    ];
}

function novamira_chat_is_code_execution_ability(string $name, string $category): bool
{
    return $category === 'code-execution' || str_contains($name, 'execute') || str_contains($name, 'wp-cli');
}

function novamira_chat_is_filesystem_write_ability(string $name): bool
{
    foreach (['write', 'edit', 'delete', 'enable-file', 'disable-file'] as $needle) {
        if (str_contains($name, $needle)) {
            return true;
        }
    }

    return false;
}

// Risk is a set of independent boolean flags; passing them individually is the
// natural signature for turning them into a human-readable reason.
function novamira_chat_risk_reason(
    bool $requires_approval,
    bool $code_execution,
    bool $filesystem_write,
    bool $destructive,
    bool $unknown,
): string {
    return match (true) {
        !$requires_approval => __('Read-only ability.', domain: 'novamira'),
        $code_execution => __('Code execution or WP-CLI ability.', domain: 'novamira'),
        $filesystem_write => __('Filesystem or content write ability.', domain: 'novamira'),
        $destructive => __('Destructive ability.', domain: 'novamira'),
        $unknown => __('Unknown risk metadata.', domain: 'novamira'),
        default => __('Non-read ability.', domain: 'novamira'),
    };
}

/**
 * @return array{readonly: bool, destructive: bool, code_execution: bool, filesystem_write: bool, unknown: bool, requires_approval: bool, reason: string}
 */
function novamira_chat_unknown_risk(): array
{
    return [
        'readonly' => false,
        'destructive' => false,
        'code_execution' => false,
        'filesystem_write' => false,
        'unknown' => true,
        'requires_approval' => true,
        'reason' => __('Unknown ability.', domain: 'novamira'),
    ];
}

/**
 * @param list<array<string, mixed>> $tools
 * @return array<string, mixed>|null
 */
function novamira_chat_find_tool(string $name, array $tools): ?array
{
    foreach ($tools as $tool) {
        if (($tool['name'] ?? null) === $name) {
            return $tool;
        }
    }

    return null;
}

/**
 * @param array<string, mixed> $session
 * @param array<string, mixed> $risk
 */
// @mago-expect lint:no-boolean-flag-parameter
function novamira_chat_tool_needs_approval(array $session, string $ability_name, array $risk, bool $yolo): bool
{
    if (($risk['requires_approval'] ?? true) !== true) {
        return false;
    }
    if ($yolo) {
        return false;
    }
    $allowlist = is_array($session['allowlist'] ?? null) ? $session['allowlist'] : [];

    return !in_array($ability_name, $allowlist, strict: true);
}

/**
 * @param array<string, mixed> $session
 * @return int|null
 */
function novamira_chat_find_tool_call_index(array $session, string $call_id): ?int
{
    if ($call_id === '' || !is_array($session['tool_calls'] ?? null)) {
        return null;
    }
    // @mago-expect analysis:mixed-assignment
    foreach ($session['tool_calls'] as $index => $tool_call) {
        if (is_array($tool_call) && ($tool_call['id'] ?? null) === $call_id) {
            return (int) $index;
        }
    }

    return null;
}

function novamira_chat_native_tools_available(): bool
{
    return (
        class_exists('WordPress\\AiClient\\Tools\\DTO\\FunctionDeclaration')
        && class_exists('WordPress\\AiClient\\Tools\\DTO\\FunctionCall')
        && class_exists('WordPress\\AiClient\\Tools\\DTO\\FunctionResponse')
        && class_exists('WordPress\\AiClient\\Messages\\DTO\\Message')
        && class_exists('WordPress\\AiClient\\Messages\\DTO\\MessagePart')
        && class_exists('WordPress\\AiClient\\Messages\\Enums\\MessageRoleEnum')
    );
}

/**
 * @return \WordPress\AiClient\Tools\DTO\FunctionDeclaration
 */
function novamira_chat_support_function_declaration(): object
{
    return new \WordPress\AiClient\Tools\DTO\FunctionDeclaration(
        'novamira_chat_support_check',
        'Checks whether the selected model supports native function calling.',
        [
            'type' => 'object',
            'properties' => [
                'ok' => ['type' => 'boolean'],
            ],
            'required' => ['ok'],
        ],
    );
}

/**
 * @param list<array<string, mixed>> $tools
 * @return list<\WordPress\AiClient\Tools\DTO\FunctionDeclaration>|WP_Error
 */
function novamira_chat_build_function_declarations(array $tools): array|WP_Error
{
    if (!novamira_chat_native_tools_available()) {
        return new WP_Error(
            'novamira_chat_missing_native_tool_calling',
            __('WordPress AI Client native function calling support is required.', domain: 'novamira'),
            ['status' => 503],
        );
    }

    // Abilities are not declared one-by-one (there can be hundreds, which is costly to
    // send every turn and overwhelms the model). Instead expose a small, fixed set of
    // progressive-disclosure meta-tools: the model browses categories, lists abilities,
    // reads a schema, then runs the specific ability it needs.
    unset($tools);

    $declarations = [];
    foreach (novamira_chat_meta_tool_specs() as $spec) {
        $declarations[] = new \WordPress\AiClient\Tools\DTO\FunctionDeclaration(
            $spec['name'],
            $spec['description'],
            $spec['schema'],
        );
    }

    return $declarations;
}

/**
 * Declarations for the progressive-disclosure meta-tools.
 *
 * @return list<array{name: string, description: string, schema: array<string, mixed>}>
 */
function novamira_chat_meta_tool_specs(): array
{
    return [
        [
            'name' => 'discover-abilities',
            'description' => 'Discover the WordPress abilities available in this system. Returns the list of ability names (which are descriptive, e.g. novamira/update-post). Call this first to find the exact ability name for a task, then copy the name verbatim; use get-ability-info if a name is unclear.',
            'schema' => [
                'type' => 'object',
                'properties' => ['_' => ['type' => 'string', 'description' => 'Unused; pass an empty string.']],
            ],
        ],
        [
            'name' => 'get-ability-info',
            'description' => 'Get detailed information about one ability, including its description and full input schema, so you can build valid parameters for execute-ability.',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'ability_name' => [
                        'type' => 'string',
                        'description' => 'The full ability name from discover-abilities, e.g. novamira/update-post.',
                    ],
                ],
                'required' => ['ability_name'],
            ],
        ],
        [
            'name' => 'execute-ability',
            'description' => 'Execute one ability by its exact name with parameters matching its schema. Use only names returned by discover-abilities; do not construct names from categories. Prefer a specific, scoped ability over general code execution.',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'ability_name' => [
                        'type' => 'string',
                        'description' => 'The exact ability name to execute, copied from discover-abilities.',
                    ],
                    'parameters' => [
                        'type' => 'object',
                        'description' => 'Parameters object matching the ability input schema.',
                    ],
                    'confirmation_reason' => [
                        'type' => 'string',
                        'description' => 'Required approval reason. A very short single-line question to the user, e.g. "May I update this post now?"',
                        'minLength' => 1,
                    ],
                ],
                'required' => ['ability_name', 'confirmation_reason'],
            ],
        ],
    ];
}

/**
 * The read-only discovery meta-tools (everything except execute-ability). These
 * execute server-side and never require approval.
 *
 * @return list<string>
 */
function novamira_chat_discovery_meta_names(): array
{
    return ['discover-abilities', 'get-ability-info'];
}

function novamira_chat_is_discovery_meta(string $function_name): bool
{
    return in_array($function_name, novamira_chat_discovery_meta_names(), strict: true);
}

/**
 * @return array{readonly: bool, destructive: bool, code_execution: bool, filesystem_write: bool, unknown: bool, requires_approval: bool, reason: string}
 */
function novamira_chat_readonly_risk(): array
{
    return [
        'readonly' => true,
        'destructive' => false,
        'code_execution' => false,
        'filesystem_write' => false,
        'unknown' => false,
        'requires_approval' => false,
        'reason' => '',
    ];
}

/**
 * Resolve a discovery meta-tool call against the ability catalog.
 *
 * @param array<string, mixed> $args
 * @param list<array<string, mixed>> $tools
 * @return array<string, mixed>
 */
function novamira_chat_run_meta_discovery(string $function_name, array $args, array $tools): array
{
    if ($function_name === 'discover-abilities') {
        return novamira_chat_meta_discover($tools);
    }
    if ($function_name === 'get-ability-info') {
        return novamira_chat_meta_ability_info($args, $tools);
    }

    return ['error' => 'Unknown discovery tool.'];
}

/**
 * The discover-abilities result: the shared usage guidance plus a flat list of
 * ability names — the same delivery an MCP client gets (an MCP server carries no
 * init instructions; the guidance reaches the agent through discover-abilities).
 * Ability names follow a descriptive `plugin/action-object` convention, so names
 * alone are enough to pick one; get-ability-info returns the description and input
 * schema for a specific ability. Keeping the list name-only avoids resending
 * hundreds of full descriptions every turn.
 *
 * @param list<array<string, mixed>> $tools
 * @return array<string, mixed>
 */
function novamira_chat_meta_discover(array $tools): array
{
    $abilities = [];
    foreach ($tools as $tool) {
        $name = (string) ($tool['name'] ?? '');
        if ($name !== '') {
            $abilities[] = $name;
        }
    }

    return [
        'novamira_instructions' => novamira_chat_server_instructions(),
        'abilities' => $abilities,
    ];
}

/**
 * @param array<string, mixed> $args
 * @param list<array<string, mixed>> $tools
 * @return array<string, mixed>
 */
function novamira_chat_meta_ability_info(array $args, array $tools): array
{
    $name = is_string($args['ability_name'] ?? null) ? $args['ability_name'] : '';
    $tool = novamira_chat_find_tool($name, $tools);
    if ($tool === null) {
        return ['error' => sprintf('Unknown ability: %s. Call discover-abilities to get valid ability names.', $name)];
    }

    return [
        'ability_name' => $name,
        'description' => (string) ($tool['description'] ?? ''),
        'input_schema' => $tool['input_schema'] ?? null,
    ];
}

/**
 * OpenAI's native tool schema support rejects top-level composition keywords,
 * while WP abilities may use them for alias constraints such as target_id/post_id.
 * Keep execution validation on the original ability schema; this only prepares
 * the provider declaration.
 *
 * @param array<string, mixed> $schema
 * @return array<string, mixed>
 */
function novamira_chat_prepare_function_schema(array $schema): array
{
    unset($schema['oneOf'], $schema['anyOf'], $schema['allOf'], $schema['enum'], $schema['not']);

    if (($schema['type'] ?? null) !== 'object') {
        $schema['type'] = 'object';
    }
    if (!is_array($schema['properties'] ?? null)) {
        $schema['properties'] = [];
    }
    if ($schema['properties'] === []) {
        $schema['properties'] = new stdClass();
    }

    return $schema;
}

/**
 * @param array<string, mixed>|null $schema
 * @return array<string, mixed>
 */
function novamira_chat_schema_with_confirmation_reason(?array $schema): array
{
    if ($schema === null) {
        $schema = [
            'type' => 'object',
            'properties' => [],
        ];
    }

    if (($schema['type'] ?? null) !== 'object') {
        $schema['type'] = 'object';
    }
    if (!is_array($schema['properties'] ?? null)) {
        $schema['properties'] = [];
    }

    /** @var array<string, mixed> $properties */
    $properties = $schema['properties'];
    $properties['confirmation_reason'] = [
        'type' => 'string',
        'description' => 'Required approval reason. Phrase it as a very short single-line question to the user, such as "May I update this file now?"',
        'minLength' => 1,
    ];
    $schema['properties'] = $properties;

    $required = is_array($schema['required'] ?? null)
        ? array_values(array_filter($schema['required'], static fn(mixed $item): bool => is_string($item)))
        : [];
    $required[] = 'confirmation_reason';
    $schema['required'] = array_values(array_unique($required));

    return $schema;
}

function novamira_chat_ability_to_function_name(string $ability_name): string
{
    return 'wpab__' . str_replace(search: '/', replace: '__', subject: $ability_name);
}

function novamira_chat_function_name_to_ability(string $function_name): string
{
    $prefix = 'wpab__';
    if (!str_starts_with($function_name, $prefix)) {
        return $function_name;
    }

    return str_replace(search: '__', replace: '/', subject: substr($function_name, strlen($prefix)));
}

function novamira_chat_system_instruction(): string
{
    $lines = [
        'You are Novamira Chat inside WordPress admin.',
        'Your abilities are not all declared up front. Discover them first: call discover-abilities to get the list of ability names, then call get-ability-info with an exact ability_name to read its input schema. Then call execute-ability with that exact ability_name and parameters matching the schema.',
        'Copy ability names verbatim from discover-abilities. Never construct a name from a category or guess it; if execute-ability reports an unknown ability, call discover-abilities again and use an exact name.',
        'Prefer a specific, scoped ability (for example updating a post or a product) over general code execution. Reach for code execution only when no scoped ability fits the task.',
        'Do not emit JSON-encoded tool calls in text.',
        'execute-ability must include confirmation_reason: a very short, single-line question for the user, ideally under 12 words. Discovery calls do not need it.',
        'This Novamira Chat page embeds the Gutenberg finalizer runtime needed to serialize native/static Gutenberg blocks. For Gutenberg queue abilities, do not ask the user to open or keep a separate Block Editor Queue/finalization page while this dashboard is open. If finalizer_runtime.online is false, ask the user to reload Novamira Chat; after enabling a batch, use Gutenberg pending-batch status abilities until it is finalized, failed, or conflicted.',
        'If the task is complete, answer normally in text.',
    ];

    // The environment/skills/Context guidance is not repeated here: like an MCP
    // server (which carries no init instructions), it reaches the model through the
    // discover-abilities result, keeping a single copy.
    return implode("\n", $lines);
}

/**
 * The shared Novamira server instructions (environment, WordPress-native guidance,
 * available skills, and the administrator Context), as sent to MCP agents. Returns
 * an empty string when the builder is unavailable.
 */
function novamira_chat_server_instructions(): string
{
    if (!function_exists('novamira_build_server_instructions')) {
        return '';
    }

    /** @var mixed $instructions */
    $instructions = apply_filters('novamira_discover_abilities_instructions', novamira_build_server_instructions());

    return is_string($instructions) ? trim($instructions) : '';
}

/**
 * @param array<string, mixed> $session
 * /**
 * @param list<array<string, mixed>> $tools
 * @return array{content: string, complete: bool, tool_calls: list<array<string, mixed>>}|WP_Error
 */
function novamira_chat_generate_native_step(array $session, array $tools): array|WP_Error
{
    if (!function_exists('wp_ai_client_prompt')) {
        return new WP_Error(
            'novamira_chat_missing_ai_client',
            __('WordPress AI text generation is not available.', domain: 'novamira'),
            ['status' => 503],
        );
    }

    $declarations = novamira_chat_build_function_declarations($tools);
    if (is_wp_error($declarations)) {
        return $declarations;
    }

    $provider = is_string($session['provider'] ?? null) ? sanitize_key($session['provider']) : '';
    $model = is_string($session['model'] ?? null) ? sanitize_text_field($session['model']) : '';
    $selection = novamira_chat_normalize_model_selection($provider, $model);
    if (is_wp_error($selection)) {
        return $selection;
    }

    $messages = novamira_chat_build_ai_history($session);
    if ($messages === []) {
        return new WP_Error(
            'novamira_chat_empty_history',
            __('The session has no prompt history.', domain: 'novamira'),
            ['status' => 400],
        );
    }

    /** @var mixed $builder */
    // @mago-expect analysis:mixed-assignment
    $builder = wp_ai_client_prompt($messages);
    if (!is_object($builder)) {
        return new WP_Error(
            'novamira_chat_bad_ai_builder',
            __('WordPress AI Client did not return a prompt builder.', domain: 'novamira'),
            ['status' => 500],
        );
    }

    // The WP AI Client prompt builder exposes its fluent using_* methods through
    // __call, so their return type cannot be inferred (each returns mixed); the
    // configured builder is validated as an object below.
    /** @var mixed $configured */
    // @mago-expect analysis:mixed-assignment
    $configured = $builder
        // @mago-expect analysis:ambiguous-object-method-access
        ->using_provider($selection['provider'])
        // @mago-expect analysis:mixed-method-access
        ->using_model_preference([$selection['provider'], $selection['model']])
        // @mago-expect analysis:mixed-method-access
        ->using_system_instruction(novamira_chat_system_instruction())
        // @mago-expect analysis:mixed-method-access
        ->using_function_declarations(...$declarations);
    if (!is_object($configured)) {
        return new WP_Error(
            'novamira_chat_bad_ai_builder',
            __('WordPress AI Client prompt builder cannot configure native tools.', domain: 'novamira'),
            ['status' => 500],
        );
    }

    /** @var mixed $supported */
    // @mago-expect analysis:mixed-assignment
    // @mago-expect analysis:ambiguous-object-method-access
    $supported = $configured->is_supported_for_text_generation();
    if (is_wp_error($supported)) {
        return $supported;
    }
    if ($supported !== true) {
        return new WP_Error(
            'novamira_chat_native_tools_unsupported',
            __(
                'The selected model cannot handle this request. Try a different model, or remove any attached image.',
                domain: 'novamira',
            ),
            ['status' => 400],
        );
    }

    /** @var mixed $result */
    // @mago-expect analysis:mixed-assignment
    // @mago-expect analysis:ambiguous-object-method-access
    $result = $configured->generate_text_result();
    if (is_wp_error($result)) {
        return $result;
    }
    if (!is_object($result)) {
        return new WP_Error(
            'novamira_chat_bad_model_response',
            __('The model did not return a native AI Client result.', domain: 'novamira'),
            ['status' => 500],
        );
    }

    return novamira_chat_parse_native_result($result);
}

/**
 * @param array<string, mixed> $session
 * @return list<object>
 */
function novamira_chat_build_ai_history(array $session): array
{
    $events = array_merge(novamira_chat_build_ai_text_events($session), novamira_chat_build_ai_tool_events($session));
    usort($events, static function (array $a, array $b): int {
        $time_order = (int) $a['created_at'] <=> (int) $b['created_at'];
        if ($time_order !== 0) {
            return $time_order;
        }

        return (int) $a['order'] <=> (int) $b['order'];
    });

    $messages = [];
    foreach ($events as $event) {
        $messages[] = $event['message'];
    }

    return $messages;
}

/**
 * @param array<string, mixed> $session
 * @return list<array{created_at: int, order: int, message: object}>
 */
function novamira_chat_build_ai_text_events(array $session): array
{
    $events = [];
    foreach (novamira_chat_session_list($session, key: 'messages') as $message) {
        $role = is_string($message['role'] ?? null) ? $message['role'] : '';
        if ($role === 'tool') {
            continue;
        }
        $content = is_string($message['content'] ?? null) ? $message['content'] : '';
        $attachments = novamira_chat_message_attachments($message);
        if ($content === '' && $attachments === []) {
            continue;
        }
        $events[] = [
            'created_at' => (int) ($message['created_at'] ?? 0),
            'order' => $role === 'assistant' ? 1 : 0,
            'message' => novamira_chat_text_message($role, $content, $attachments),
        ];
    }

    return $events;
}

/**
 * @param array<string, mixed> $message
 * @return list<array{id: string, name: string, mime_type: string, data: string, size: int}>
 */
function novamira_chat_message_attachments(array $message): array
{
    $items = is_array($message['attachments'] ?? null) ? $message['attachments'] : [];
    $attachments = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $attachment = novamira_chat_message_attachment($item);
        if ($attachment === null) {
            continue;
        }
        $attachments[] = $attachment;
    }

    return $attachments;
}

/**
 * @param array<array-key, mixed> $item
 * @return array{id: string, name: string, mime_type: string, data: string, size: int}|null
 */
function novamira_chat_message_attachment(array $item): ?array
{
    $name = is_string($item['name'] ?? null) ? $item['name'] : '';
    $mime_type = is_string($item['mime_type'] ?? null) ? $item['mime_type'] : '';
    $data = is_string($item['data'] ?? null) ? $item['data'] : '';
    $id = is_string($item['id'] ?? null) ? $item['id'] : '';
    $size = is_numeric($item['size'] ?? null) ? (int) $item['size'] : 0;
    if ($id === '' || $mime_type === '' || $data === '' || $size < 1) {
        return null;
    }

    return [
        'id' => $id,
        'name' => $name,
        'mime_type' => $mime_type,
        'data' => $data,
        'size' => $size,
    ];
}

/**
 * @param array<string, mixed> $session
 * @return list<array{created_at: int, order: int, message: object}>
 */
function novamira_chat_build_ai_tool_events(array $session): array
{
    $events = [];
    foreach (novamira_chat_session_list($session, key: 'tool_calls') as $tool_call) {
        $function_call_message = novamira_chat_tool_call_function_message($tool_call);
        if ($function_call_message === null) {
            continue;
        }
        $events[] = [
            'created_at' => (int) ($tool_call['created_at'] ?? 0),
            'order' => 2,
            'message' => $function_call_message,
        ];

        $function_response_message = novamira_chat_tool_call_response_message($tool_call);
        if ($function_response_message === null) {
            continue;
        }
        $events[] = [
            'created_at' => (int) ($tool_call['updated_at'] ?? $tool_call['created_at'] ?? 0),
            'order' => 3,
            'message' => $function_response_message,
        ];
    }

    return $events;
}

/**
 * @param array<string, mixed> $tool_call
 */
function novamira_chat_tool_call_function_message(array $tool_call): ?object
{
    $call_id = is_string($tool_call['id'] ?? null) ? $tool_call['id'] : '';
    $ability = is_string($tool_call['ability'] ?? null) ? $tool_call['ability'] : '';
    if ($call_id === '' || $ability === '') {
        return null;
    }

    $function_name = is_string($tool_call['function_name'] ?? null)
        ? $tool_call['function_name']
        : novamira_chat_ability_to_function_name($ability);
    $arguments = novamira_chat_model_arguments($tool_call);

    return new \WordPress\AiClient\Messages\DTO\Message(\WordPress\AiClient\Messages\Enums\MessageRoleEnum::model(), [new \WordPress\AiClient\Messages\DTO\MessagePart(
        new \WordPress\AiClient\Tools\DTO\FunctionCall($call_id, $function_name, $arguments !== [] ? $arguments : null),
    )]);
}

/**
 * @param array<string, mixed> $tool_call
 */
function novamira_chat_tool_call_response_message(array $tool_call): ?object
{
    $call_id = is_string($tool_call['id'] ?? null) ? $tool_call['id'] : '';
    if ($call_id === '') {
        return null;
    }

    $ability = is_string($tool_call['ability'] ?? null) ? $tool_call['ability'] : '';
    if ($ability === '') {
        return null;
    }

    $status = is_string($tool_call['status'] ?? null) ? $tool_call['status'] : '';
    if (!novamira_chat_tool_call_has_response($status)) {
        return null;
    }

    $function_name = is_string($tool_call['function_name'] ?? null)
        ? $tool_call['function_name']
        : novamira_chat_ability_to_function_name($ability);

    return new \WordPress\AiClient\Messages\DTO\Message(\WordPress\AiClient\Messages\Enums\MessageRoleEnum::user(), [new \WordPress\AiClient\Messages\DTO\MessagePart(
        new \WordPress\AiClient\Tools\DTO\FunctionResponse(
            $call_id,
            $function_name,
            novamira_chat_tool_call_response_payload($tool_call, $status),
        ),
    )]);
}

function novamira_chat_tool_call_has_response(string $status): bool
{
    return in_array($status, ['succeeded', 'failed', 'denied'], strict: true);
}

/**
 * @param array<string, mixed> $tool_call
 */
function novamira_chat_tool_call_response_payload(array $tool_call, string $status): mixed
{
    if ($status === 'succeeded') {
        return $tool_call['result'] ?? null;
    }

    $error = is_string($tool_call['error'] ?? null) ? $tool_call['error'] : '';

    return [
        'error' => $error !== '' ? $error : __('Tool execution did not complete.', domain: 'novamira'),
    ];
}

/**
 * @param list<array{id: string, name: string, mime_type: string, data: string, size: int}> $attachments
 */
function novamira_chat_text_message(string $role, string $content, array $attachments = []): object
{
    $message_role = $role === 'assistant'
        ? \WordPress\AiClient\Messages\Enums\MessageRoleEnum::model()
        : \WordPress\AiClient\Messages\Enums\MessageRoleEnum::user();

    $parts = [];
    if ($content !== '') {
        $parts[] = new \WordPress\AiClient\Messages\DTO\MessagePart($content);
    }
    if ($role !== 'assistant') {
        foreach ($attachments as $attachment) {
            $parts[] = new \WordPress\AiClient\Messages\DTO\MessagePart(
                new \WordPress\AiClient\Files\DTO\File($attachment['data'], $attachment['mime_type']),
            );
        }
    }

    return new \WordPress\AiClient\Messages\DTO\Message($message_role, $parts);
}

/**
 * @return array{content: string, complete: bool, tool_calls: list<array<string, mixed>>}|WP_Error
 */
function novamira_chat_parse_native_result(object $result): array|WP_Error
{
    if (!method_exists($result, 'toMessages') && !method_exists($result, 'toMessage')) {
        return new WP_Error(
            'novamira_chat_bad_model_response',
            __('The AI Client result cannot expose native messages.', domain: 'novamira'),
            ['status' => 500],
        );
    }

    /** @var list<object> $messages */
    // @mago-expect analysis:ambiguous-object-method-access
    $messages = method_exists($result, 'toMessages') ? $result->toMessages() : [$result->toMessage()];
    $content = [];
    $tool_calls = [];
    foreach ($messages as $message) {
        $parsed = novamira_chat_parse_native_message($message);
        $content = array_merge($content, $parsed['content']);
        $tool_calls = array_merge($tool_calls, $parsed['tool_calls']);
    }

    $text = trim(implode("\n\n", $content));
    if ($text === '' && $tool_calls === []) {
        return new WP_Error(
            'novamira_chat_empty_model_response',
            __('The model returned no text or native tool calls.', domain: 'novamira'),
            ['status' => 500],
        );
    }

    return [
        'content' => $text,
        'complete' => $tool_calls === [],
        'tool_calls' => $tool_calls,
    ];
}

/**
 * @return array{content: list<string>, tool_calls: list<array<string, mixed>>}
 */
function novamira_chat_parse_native_message(object $message): array
{
    if (!method_exists($message, 'getParts')) {
        return ['content' => [], 'tool_calls' => []];
    }

    $content = [];
    $tool_calls = [];
    /** @var iterable<mixed> $parts */
    // @mago-expect analysis:mixed-assignment
    $parts = $message->getParts();
    foreach ($parts as $part) {
        if (!is_object($part)) {
            continue;
        }
        $parsed = novamira_chat_parse_native_part($part);
        if (is_string($parsed)) {
            $content[] = $parsed;
            continue;
        }
        if (is_array($parsed)) {
            $tool_calls[] = $parsed;
        }
    }

    return ['content' => $content, 'tool_calls' => $tool_calls];
}

/**
 * @return string|array<string, mixed>|null
 */
function novamira_chat_parse_native_part(object $part): string|array|null
{
    if (!method_exists($part, 'getType')) {
        return null;
    }

    $type = $part->getType();
    if (!is_object($type)) {
        return null;
    }

    $text = novamira_chat_parse_native_text_part($part, $type);
    if ($text !== null) {
        return $text;
    }

    return novamira_chat_parse_native_function_call_part($part, $type);
}

function novamira_chat_parse_native_text_part(object $part, object $type): ?string
{
    if (novamira_chat_native_part_type($type) !== 'text' || !method_exists($part, 'getText')) {
        return null;
    }

    $text = $part->getText();
    return is_string($text) && $text !== '' ? $text : null;
}

function novamira_chat_native_part_type(object $type): string
{
    if (method_exists($type, 'jsonSerialize')) {
        $serialized = $type->jsonSerialize();
        if (is_string($serialized)) {
            return $serialized;
        }
    }

    if (method_exists($type, '__toString')) {
        return (string) $type;
    }

    return '';
}

/**
 * @return array<string, mixed>|null
 */
function novamira_chat_parse_native_function_call_part(object $part, object $type): ?array
{
    if (novamira_chat_native_part_type($type) !== 'function_call' || !method_exists($part, 'getFunctionCall')) {
        return null;
    }

    // @mago-expect analysis:mixed-assignment
    $call = $part->getFunctionCall();
    if (!is_object($call) || !method_exists($call, 'getName')) {
        return null;
    }
    // @mago-expect analysis:mixed-assignment
    $function_name = $call->getName();
    if (!is_string($function_name) || $function_name === '') {
        return null;
    }

    // @mago-expect analysis:mixed-assignment
    $args = method_exists($call, 'getArgs') ? $call->getArgs() : [];
    // @mago-expect analysis:mixed-assignment
    $call_id = method_exists($call, 'getId') ? $call->getId() : '';

    return [
        'id' => is_string($call_id) && $call_id !== '' ? $call_id : wp_generate_uuid4(),
        'name' => novamira_chat_function_name_to_ability($function_name),
        'function_name' => $function_name,
        'arguments' => is_array($args) ? $args : [],
    ];
}

/**
 * @param array<string, mixed> $session
 */
function novamira_chat_fail_session(array $session, string $message): WP_Error
{
    $session['status'] = 'failed';
    $session['error'] = $message;
    $session['updated_at'] = time();
    novamira_chat_save_session($session);

    return new WP_Error('novamira_chat_model_failed', $message, ['status' => 500]);
}

/**
 * @param array<string, mixed> $session
 */
function novamira_chat_record_tool_error(array $session, int $call_index, string $message): array
{
    $now = time();
    $tool_calls = novamira_chat_session_list($session, key: 'tool_calls');
    $tool_calls[$call_index]['status'] = 'failed';
    $tool_calls[$call_index]['error'] = $message;
    $tool_calls[$call_index]['updated_at'] = $now;
    $session['tool_calls'] = $tool_calls;
    $session['status'] = 'idle';
    $session['updated_at'] = $now;
    novamira_chat_save_session($session);

    return ['session' => $session, 'tool_call' => $tool_calls[$call_index]];
}
