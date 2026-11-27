<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

ini_set('max_execution_time', '30');
define('ABSPATH', '/');
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('LOGGED_IN_COOKIE', 'logged_in');
define('AUTH_KEY', 'site-auth-key-"quoted"-value');
$GLOBALS['options'] = [];
$GLOBALS['transients'] = [];
$GLOBALS['filters'] = [];
$GLOBALS['user_id'] = 1;
$GLOBALS['can_manage'] = true;
$GLOBALS['can_execute'] = true;
$GLOBALS['executions'] = 0;
$GLOBALS['user_meta'] = [];

class WP_Error
{
    public function __construct(private string $code, private string $message, private mixed $data = null) {}
    public function get_error_message(): string { return $this->message; }
    public function get_error_code(): string { return $this->code; }
    public function get_error_data(): mixed { return $this->data; }
}
class WP_REST_Request
{
    public function __construct(private array $params = [], private array $headers = [], private string $method = 'POST', private array $query = [], private string $body = '', private string $route = '') {}
    public function get_json_params(): ?array { $json = json_decode($this->body, true); return is_array($json) ? $json : null; }
    public function set_body(string $body): void { $this->body = $body; }
    public function get_route(): string { return $this->route; }
    public function get_param(string $key): mixed { return $this->params[$key] ?? null; }
    public function get_header(string $key): ?string { return $this->headers[$key] ?? null; }
    public function get_method(): string { return $this->method; }
    public function get_query_params(): array { return $this->query; }
    public function get_body(): string { return $this->body; }
}
class WP_REST_Server {}
class WP_REST_Response
{
    public function __construct(public mixed $data = null) {}
}
class wpdb
{
    public string $options = 'wp_options';
    public string $posts = 'wp_posts';
    private array $queries = [];
    public function prepare(string $query, mixed ...$args): string
    {
        $id = (string) count($this->queries);
        $this->queries[$id] = [$query, $args];
        return $id;
    }
    public function query(string $query): int
    {
        [$sql, $args] = $this->queries[$query];
        if (str_starts_with($sql, 'INSERT IGNORE')) {
            [, $name, $value] = $args;
            // The sections before the one-at-a-time one each keep their own requests: slots are checked there alone.
            if (str_starts_with($name, 'novamira_confirmation_slot') && !$GLOBALS['one_at_a_time']) return 1;
            if (array_key_exists($name, $GLOBALS['options'])) return 0;
            $GLOBALS['options'][$name] = $value;
            return 1;
        }
        if (str_starts_with($sql, 'DELETE')) {
            [, $name, $value] = $args;
            if (($GLOBALS['options'][$name] ?? null) !== $value) return 0;
            unset($GLOBALS['options'][$name]);
            return 1;
        }
        if (count($args) === 3) {
            [, $new, $name] = $args;
            $GLOBALS['options'][$name] = $new;
            return 1;
        }
        [, $new, $name, $old] = $args;
        if (raw_row($name)) {
            if (($GLOBALS['options'][$name] ?? null) !== $old) return 0;
            $GLOBALS['options'][$name] = $new;
            return 1;
        }
        if (serialize($GLOBALS['options'][$name] ?? null) !== $old) return 0;
        $GLOBALS['options'][$name] = unserialize($new);
        return 1;
    }
    public function get_var(string $query): mixed
    {
        [, $args] = $this->queries[$query];
        if (str_starts_with((string) $args[1], 'novamira_confirmation_slot') && !$GLOBALS['one_at_a_time']) return null;
        $value = $GLOBALS['options'][$args[1]] ?? null;
        return is_string($value) ? $value : null;
    }
    public function get_col(string $query): array
    {
        [$sql, $args] = $this->queries[$query];
        $prefix = rtrim((string) $args[1], '%');
        $names = array_reverse(array_values(array_filter(array_keys($GLOBALS['options']),
            static fn(string $key): bool => str_starts_with($key, $prefix))));
        if (str_contains($sql, 'option_value LIKE')) {
            $active = !str_contains($sql, 'NOT (');
            $names = array_values(array_filter($names, static fn(string $name): bool => $active === in_array(
                $GLOBALS['options'][$name]['state'] ?? '', ['pending_confirmation', 'approved', 'executing'], true)));
        }
        if (str_contains($sql, 'LIMIT')) {
            $names = array_slice($names, (int) $args[count($args) - 1], (int) $args[count($args) - 2]);
        }
        return $names;
    }
    public function esc_like(string $value): string { return $value; }
}
/** Rows Novamira writes as plain text with its own queries, rather than through the options API. */
function raw_row(string $name): bool
{
    foreach (['novamira_confirmation_key_', 'novamira_confirmation_slot', 'novamira_confirmation_block_'] as $prefix) {
        if (str_starts_with($name, $prefix)) return true;
    }
    return false;
}
$GLOBALS['wpdb'] = new wpdb();
$GLOBALS['one_at_a_time'] = false;
function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void
{
    add_filter($hook, $callback, $priority, $accepted_args);
}
function do_action(string $hook, mixed ...$args): void
{
    foreach ($GLOBALS['filters'][$hook] ?? [] as [$callback, $count]) {
        $callback(...array_slice($args, 0, $count));
    }
}
function add_filter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void
{
    $GLOBALS['filters'][$hook][] = [$callback, $accepted_args];
}
function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    foreach ($GLOBALS['filters'][$hook] ?? [] as [$callback, $count]) {
        $value = $callback(...array_slice([$value, ...$args], 0, $count));
    }
    return $value;
}
function get_option(string $key, mixed $default_value = false): mixed { return $GLOBALS['options'][$key] ?? $default_value; }
function add_option(string $key, mixed $value, string $deprecated = '', mixed $autoload = null): bool
{
    if (array_key_exists($key, $GLOBALS['options'])) return false;
    $GLOBALS['options'][$key] = $value;
    $GLOBALS['autoload'][$key] = $autoload;
    return true;
}
function update_option(string $key, mixed $value, mixed $autoload = null): bool
{
    $GLOBALS['options'][$key] = $value;
    return true;
}
function delete_option(string $key): bool { unset($GLOBALS['options'][$key]); return true; }
function get_transient(string $key): mixed { return $GLOBALS['transients'][$key] ?? false; }
function set_transient(string $key, mixed $value, int $expiration = 0): bool
{
    $GLOBALS['transients'][$key] = $value;
    return true;
}
function delete_transient(string $key): bool { unset($GLOBALS['transients'][$key]); return true; }
function wp_clear_scheduled_hook(string $hook): void {}
function wp_cache_delete(string|int $key, string $group = ''): bool { return true; }
function maybe_serialize(mixed $value): string { return serialize($value); }
function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
function admin_url(string $path): string { return 'https://example.test/wp-admin/' . $path; }
function did_action(string $hook): int { return 0; }
function wp_set_current_user(int $id): void { $GLOBALS['user_id'] = $id; }
function rest_url(string $path): string { return 'https://example.test/wp-json/' . $path; }
function home_url(): string { return 'https://shop.example.test'; }
function site_url(): string { return 'https://shop.example.test'; }
function wp_parse_url(string $url, int $component = -1): mixed { return parse_url($url, $component); }
function add_query_arg(array $args, string $url): string { return $url . '?' . http_build_query($args); }
function get_current_user_id(): int { return $GLOBALS['user_id']; }
function is_user_logged_in(): bool { return get_current_user_id() > 0; }
function current_user_can(string $capability): bool { return $GLOBALS['can_read'] ?? true; }
function novamira_current_user_can_manage(): bool { return $GLOBALS['can_manage']; }
function novamira_manage_capability(): string { return 'manage_options'; }
function novamira_is_read_mode(): bool { return $GLOBALS['read_mode'] ?? false; }
function user_can(int $user, string $capability): bool { return $GLOBALS['can_manage']; }
function wp_salt(string $scheme): string { return 'test-salt'; }
function wp_json_encode(mixed $value): string { return json_encode($value, JSON_THROW_ON_ERROR); }
function wp_validate_auth_cookie(string $cookie = '', string $scheme = ''): int|false { return $GLOBALS['cookie_user'] ?? false; }
function wp_verify_nonce(string $nonce, string $action): int|false { return $nonce === 'browser-nonce' ? 1 : false; }
function __(string $text, string $domain = 'default'): string { return $text; }
function esc_html(string $text): string { return htmlspecialchars($text); }
function esc_html_e(string $text, string $domain = 'default'): void { echo htmlspecialchars($text); }
function esc_url(string $url): string { return $url; }
function esc_attr(string $text): string { return htmlspecialchars($text); }
function wp_kses(string $content, array $allowed): string { return $content; }
function wp_strip_all_tags(string $text): string { return strip_tags($text); }
function esc_attr_e(string $text, string $domain = 'default'): void { echo htmlspecialchars($text); }
function esc_html__(string $text, string $domain = 'default'): string { return htmlspecialchars($text); }
function checked(mixed $checked, mixed $current = true, bool $display = true): string { $out = $checked === $current ? " checked='checked'" : ''; if ($display) echo $out; return $out; }
function _n(string $single, string $plural, int $number, string $domain = 'default'): string { return $number === 1 ? $single : $plural; }
function number_format_i18n(float|int $number, int $decimals = 0): string { return number_format($number, $decimals); }
function date_i18n(string $format, int|false $timestamp = false): string { return date($format === '' ? 'Y-m-d' : $format, $timestamp === false ? time() : $timestamp); }
function sanitize_text_field(string $text): string { return trim(strip_tags($text)); }
function get_userdata(int $id): object|false { return false; }
function human_time_diff(int $from, int $to = 0): string { return '5 mins'; }
function is_multisite(): bool { return $GLOBALS['multisite'] ?? false; }
function get_current_blog_id(): int { return $GLOBALS['blog_id'] ?? 1; }
function get_user_meta(int $user, string $key, bool $single = false): mixed
{
    return $GLOBALS['user_meta'][$user][$key] ?? '';
}
function update_user_meta(int $user, string $key, mixed $value, mixed $previous = ''): int|bool
{
    if ($previous !== '' && ($GLOBALS['user_meta'][$user][$key] ?? '') !== $previous) return false;
    $GLOBALS['user_meta'][$user][$key] = $value;
    do_action('updated_user_meta', 1, $user, $key, $value);
    return true;
}
function delete_user_meta(int $user, string $key): bool
{
    unset($GLOBALS['user_meta'][$user][$key]);
    do_action('deleted_user_meta', [1], $user, $key, '');
    return true;
}
function rest_get_authenticated_app_password(): ?string { return $GLOBALS['app_password'] ?? null; }
final class WP_Application_Passwords
{
    public static function get_user_application_password(int $user_id, string $uuid): ?array { return null; }
}
function wp_generate_uuid4(): string { return bin2hex(random_bytes(16)); }
function get_user_by(string $field, int $id): object { return (object) ['ID' => $id]; }
function register_rest_route(string $route_namespace, string $route, array $args, bool $override = false): void
{
    $GLOBALS['routes'][$route] = $args;
}
class WP_Ability
{
    public function __construct(private string $name, public mixed $execute = null, public mixed $permission = null, private array $meta = []) {}
    public function get_name(): string { return $this->name; }
    public function get_meta(): array { return $this->meta; }
    public function get_meta_item(string $item, mixed $default = null): mixed { return $item === 'show_in_rest' ? true : $default; }
    public function execute(mixed $input = null): mixed { return ($this->execute)($input); }
}
function wp_has_ability(string $name): bool { return isset($GLOBALS['abilities'][$name]); }
function wp_get_ability(string $name): ?WP_Ability { return $GLOBALS['abilities'][$name] ?? null; }
function novamira_read_mode_mcp_component_ability(mixed $component): ?WP_Ability
{
    return is_array($component) && ($component['ability'] ?? null) instanceof WP_Ability ? $component['ability'] : null;
}
function novamira_read_mode_trusts_mcp_component(mixed $component): bool
{
    return is_array($component) && ($component['trusted'] ?? false) === true;
}
function novamira_read_mode_ability_callbacks(WP_Ability $ability): ?array
{
    return ['execute' => $ability->execute, 'permission' => $ability->permission];
}

$GLOBALS['test_root'] = sys_get_temp_dir() . '/novamira-confirmations-' . getmypid();
define('WP_PLUGIN_DIR', $GLOBALS['test_root'] . '/wp-content/plugins');
function novamira_resolve_path($path, $must_exist = false) { return str_starts_with($path, '/') ? $path : $GLOBALS['test_root'] . '/' . $path; }
function novamira_path_is_within_directory(string $path, string $directory): bool { return str_starts_with($path, rtrim($directory, '/') . '/') || $path === $directory; }
function novamira_get_sandbox_dir($ensure_exists = false) { return $GLOBALS['test_root'] . '/wp-content/novamira-sandbox/'; }
function novamira_is_disabled_file($path) { return str_ends_with($path, '.disabled'); }
function novamira_sandbox_disabled_marker_path(string $path): string { return dirname($path) . '/.' . basename($path) . '.disabled'; }
function novamira_sandbox_file_is_disabled(string $path): bool { return is_file(novamira_sandbox_disabled_marker_path($path)); }
function wp_upload_dir($time = null, $create_dir = true, $refresh_cache = false) { return ['basedir' => $GLOBALS['test_root'] . '/wp-content/uploads']; }
require $argv[1] . '/vendor/autoload.php';
require $argv[1] . '/vendor/novamira/mcp-adapter/autoload.php';
require $argv[1] . '/includes/confirmations/bootstrap.php';

use Novamira\Vendor\WP\MCP\Core\McpServer;
use Novamira\Vendor\WP\MCP\Domain\Tools\McpTool;
use Novamira\Vendor\WP\MCP\Handlers\Tools\ToolsHandler;
use Novamira\Vendor\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler;
use Novamira\Vendor\WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface;
use Novamira\Vendor\WP\MCP\Transport\Infrastructure\SessionManager;
use Novamira\Confirmations as C;
use Novamira\Secrets as S;

$GLOBALS['user_meta'][1]['novamira_mcp_adapter_sessions']['session-1'] = ['last_activity' => time()];
C\capture_session(null, [], new WP_REST_Request([], ['Mcp-Session-Id' => 'session-1']));

class ConfirmationTestServer extends McpServer
{
    public array $tools;
    public function __construct(private string $id = 'novamira')
    {
        $this->tools = [];
        foreach (['mcp-adapter-execute-ability', 'mcp-adapter-discover-abilities', 'mcp-adapter-get-ability-info'] as $name) {
            $this->tools[$name] = McpTool::fromArray([
                'name' => $name,
                'inputSchema' => $name === 'mcp-adapter-discover-abilities'
                    ? ['type' => 'object']
                    : ['type' => 'object', 'properties' => ['parameters' => ['type' => 'object']]],
                'handler' => static function (array $args) use ($name): array {
                    if ($name === 'mcp-adapter-execute-ability') $GLOBALS['executions']++;
                    if ($name === 'mcp-adapter-execute-ability' && ($GLOBALS['track_runs'] ?? false)) {
                        $GLOBALS['ran'][] = [$args['parameters']['code'] ?? '', C\RunningApproval::$current['group_request'] ?? null];
                    }
                    if ($name === 'mcp-adapter-execute-ability' && isset($GLOBALS['erase_request'])) {
                        delete_option(C\REQUEST_PREFIX . $GLOBALS['erase_request']);
                    }
                    if ($name === 'mcp-adapter-execute-ability' && ($GLOBALS['image_output'] ?? false)) {
                        return ['type' => 'image', 'results' => 'test-image-bytes', 'mimeType' => 'image/png'];
                    }
                    if ($name === 'mcp-adapter-execute-ability' && ($GLOBALS['end_session_during_action'] ?? false)) {
                        SessionManager::delete_session(1, C\session_id());
                    }
                    // As MCP's execute-ability wraps an ability: execute-php that caught an error, WP-CLI started in
                    // the background.
                    $code = $args['parameters']['code'] ?? '';
                    if ($name === 'mcp-adapter-execute-ability' && $code === 'soft-fail;') {
                        return ['success' => true, 'data' => ['success' => false, 'error' => 'Boom']];
                    }
                    if ($name === 'mcp-adapter-execute-ability' && $code === 'background;') {
                        return ['success' => true, 'data' => ['success' => true, 'job_id' => 'abc123']];
                    }
                    return ['received' => $args];
                },
                // A call written to fail is refused when it runs, after it was approved.
                'permission' => static fn(array $args): bool => $GLOBALS['can_execute'] && !array_key_exists('review', $args)
                    && !(($GLOBALS['track_runs'] ?? false) && ($GLOBALS['executing_approved'] ?? false) && ($args['parameters']['code'] ?? '') === 'fail;'),
            ]);
        }
    }
    public function get_server_id(): string { return $this->id; }
    public function get_mcp_tool(string $name): ?McpTool { return $this->tools[$name] ?? null; }
    public function get_tools(): array { return array_map(static fn(McpTool $tool) => $tool->get_protocol_dto(), $this->tools); }
    public function get_error_handler(): McpErrorHandlerInterface { return new ErrorLogMcpErrorHandler(); }
}

$server = new ConfirmationTestServer();
$handler = new ToolsHandler($server);
function call(ToolsHandler $handler, array $review = [], string $name = 'mcp-adapter-execute-ability'): array
{
    $args = ['ability_name' => 'novamira/execute-php', 'parameters' => ['code' => 'echo "test";']];
    if ($review !== []) $args['review'] = ($review['attention'] ?? null) === 8 ? $review : $review + ['summary' => 'Says what it does.'];
    return $handler->call_tool(['name' => $name, 'arguments' => $args])->toArray();
}
/** Identical calls queue one request, so a section that needs its own request first closes what is still open. */
function close_open_requests(): void
{
    foreach (C\active_requests() as $record) {
        C\transition($record, array_merge($record, ['state' => 'canceled', 'finished' => time()]));
    }
}

function poll(ToolsHandler $handler, string $id): array
{
    return $handler->call_tool(['name' => C\STATUS_TOOL, 'arguments' => ['request_id' => $id]])->toArray();
}
/** The call as it is stored: what the removed call-data route used to return, to check what is kept. */
function stored_call(mixed $id): WP_REST_Response|WP_Error
{
    $record = is_string($id) ? C\get_request($id) : null;
    if ($record === null) {
        return new WP_Error('not_found', 'Approval request not found.', ['status' => 404]);
    }
    return new WP_REST_Response(['tool' => $record['tool'], 'arguments' => $record['arguments']]);
}

function approve(string $id): mixed
{
    return C\decide(new WP_REST_Request(['id' => $id, 'decision' => 'approve', 'decision_nonce' => 'browser-nonce']));
}
$high = ['attention' => 8, 'summary' => 'Changes the test site.', 'details' => 'Runs the stored PHP once.'];
$out = [];
$out['default'] = C\settings();
// With the AI change history off nothing can hold an email: it would go out, so an email in the AI's code asks.
$out['no_history_email'] = [
    'history_on' => C\history_is_on(),
    'holds' => C\holds_emails(),
    'setting_stays' => C\settings()['hold_emails'],
    'ai_email_asks' => C\chat_call_check('novamira/execute-php', ['code' => 'wp_mail("a@example.test", "s", "m");'], 0)['must_ask'],
];
eval('namespace Novamira\\Journal; function hold_mail(): void {}');
$out['readable_code'] = [
    'one_line' => C\readable_code('$a = 1; if ($a) { return \'x\'; } // done'),
    'own_lines' => C\readable_code("\$a = 1;\nreturn \$a;"),
    'packed_lines' => C\readable_code("\$a = 1;\nif (\$a) { return 'x'; }"),
    'broken' => C\readable_code('if ( {'),
];
$out['cutoff_boundaries'] = [C\requires_confirmation(0, 0), C\requires_confirmation(9, 10), C\requires_confirmation(10, 10)];
$out['fresh_site_needs_review'] = call($handler);
C\save_settings(new WP_REST_Request(['cutoff' => 8]));
$out['missing_review'] = call($handler);
$out['invalid_reviews'] = [];
foreach ([null, [], ['attention' => -1], ['attention' => 11], ['attention' => '8'], ['attention' => 8.0], ['attention' => true]] as $review) {
    $out['invalid_reviews'][] = C\validate_review($review, 8) instanceof WP_Error;
}
$out['closed_page_low_score_runs'] = call($handler, ['attention' => 1, 'summary' => 'Says hello.']);
$out['discovery_offline'] = call($handler, [], 'mcp-adapter-discover-abilities');
$out['info_offline'] = call($handler, [], 'mcp-adapter-get-ability-info');
$out['status_offline'] = $handler->call_tool(['name' => C\STATUS_TOOL])->toArray();
$GLOBALS['can_manage'] = false;
$out['status_lower_privilege'] = $handler->call_tool(['name' => C\STATUS_TOOL])->toArray();
$GLOBALS['can_read'] = false;
$out['status_forbidden'] = $handler->call_tool(['name' => C\STATUS_TOOL])->toArray();
$GLOBALS['can_read'] = true;
$GLOBALS['can_manage'] = true;
set_transient('novamira_confirmation_heartbeat', ['users' => [1 => time()]], 60);
$out['below_cutoff'] = call($handler, ['attention' => 7]);
$out['text_required'] = call($handler, ['attention' => 8]);
$out['below_only_score'] = C\validate_review(['attention' => 1, 'summary' => 'Unused', 'details' => 'Unused'], 8);
$out['long_summary'] = C\validate_review(['attention' => 8, 'summary' => str_repeat('a', 241), 'details' => 'Details'], 8) instanceof WP_Error;
$out['long_details'] = C\validate_review(['attention' => 8, 'summary' => 'Summary', 'details' => str_repeat('a', 1501)], 8) instanceof WP_Error;
$out['no_details'] = C\validate_review(['attention' => 9, 'summary' => 'Deletes article 12.'], 8);
$out['empty_details'] = C\validate_review(['attention' => 9, 'summary' => 'Deletes article 12.', 'details' => '  '], 8);
$out['unicode_summary'] = C\validate_review(['attention' => 8, 'summary' => str_repeat('é', 240), 'details' => 'Details'], 8);
$before = $GLOBALS['executions'];
$queued = call($handler, $high)['structuredContent'];
$id = $queued['request_id'];
$out['queued'] = $queued;
$out['pending_call_data'] = stored_call($id)->data;
$out['duplicate_pending'] = call($handler, $high)['structuredContent']['request_id'] === $id;
$out['not_executed_while_pending'] = $before === $GLOBALS['executions'];
update_option(C\REQUEST_PREFIX . 'oldformat', ['id' => 'oldformat', 'state' => 'pending_confirmation', 'created' => time()]);
$out['record_without_review_ignored'] = C\get_request('oldformat') === null && get_option(C\REQUEST_PREFIX . 'oldformat', 'gone') === 'gone';
$out['stored_no_review'] = !array_key_exists('review', C\get_request($id)['arguments']);
$out['preserve_other_tool_review'] = C\strip_review(['review' => ['domain_data' => true]], 'third-party-tool');
$out['not_autoloaded'] = $GLOBALS['autoload'][C\REQUEST_PREFIX . $id] === false;
$out['pending_poll'] = poll($handler, $id)['structuredContent'];
$out['approve'] = approve($id)->data;
$out['double_approve'] = approve($id) instanceof WP_Error;
$out['completed'] = poll($handler, $id)['structuredContent'];
$out['completed_call_data'] = stored_call($id)->data;
$once = $GLOBALS['executions'];
$out['repeated_poll'] = poll($handler, $id)['structuredContent'];
$out['executed_once'] = $GLOBALS['executions'] === $once && $once === $before + 1;
$old = C\get_request($id);
$out['stale_cas'] = !C\transition(array_merge($old, ['state' => 'approved']), array_merge($old, ['state' => 'executing']));

$id = call($handler, $high)['structuredContent']['request_id'];
C\decide(new WP_REST_Request(['id' => $id, 'decision' => 'reject', 'decision_nonce' => 'browser-nonce']));
$out['rejected'] = poll($handler, $id)['structuredContent'];
$id = call($handler, $high)['structuredContent']['request_id'];
approve($id);
$GLOBALS['can_execute'] = false;
$out['permission_revoked'] = poll($handler, $id)['structuredContent'];
$GLOBALS['can_execute'] = true;
$id = call($handler, $high)['structuredContent']['request_id'];
approve($id);
$GLOBALS['erase_request'] = $id;
$out['queue_erased_by_action'] = poll($handler, $id)['structuredContent'];
unset($GLOBALS['erase_request']);
$out['queue_erased_no_replay'] = poll($handler, $id)['isError'];
$id = call($handler, $high)['structuredContent']['request_id'];
approve($id);
$GLOBALS['image_output'] = true;
$out['image_result'] = poll($handler, $id);
unset($GLOBALS['image_output']);
$id = call($handler, $high)['structuredContent']['request_id'];
approve($id);
$out['other_server'] = poll(new ToolsHandler(new ConfirmationTestServer('novamira-oauth')), $id);
$GLOBALS['user_id'] = 2;
$out['other_user'] = poll($handler, $id);
$GLOBALS['user_id'] = 1;
C\save_settings(new WP_REST_Request(['cutoff' => 9]));
C\save_settings(new WP_REST_Request(['cutoff' => 8]));
$out['toggle_canceled'] = poll($handler, $id)['structuredContent'];
$id = call($handler, $high)['structuredContent']['request_id'];
approve($id);
C\save_settings(new WP_REST_Request(['cutoff' => 9]));
$out['cutoff_canceled'] = poll($handler, $id)['structuredContent'];
$out['new_cutoff_immediate'] = call($handler, ['attention' => 8, 'summary' => 'Says what it does.']);
$id = call($handler, array_merge($high, ['attention' => 9]))['structuredContent']['request_id'];
$record = C\get_request($id);
C\transition($record, array_merge($record, ['created' => time() - C\REQUEST_TTL - 1]));
$out['expired'] = poll($handler, $id)['structuredContent'];
// A request that expired ran nothing, so the same call can be sent again: a new request, and a person decides again.
$out['expired_can_resend'] = str_contains((string) $out['expired']['next_step'], 'only if they ask');
$resent = call($handler, array_merge($high, ['attention' => 9]))['structuredContent'];
$out['resent_is_new'] = [$resent['state'], $resent['request_id'] !== $id, str_contains($resent['dashboard_url'], $resent['request_id'])];
// Sent again while it waits, it is still the same request.
$out['resent_while_waiting'] = call($handler, array_merge($high, ['attention' => 9]))['structuredContent']['request_id'] === $resent['request_id'];
// The wait URL says the state, only to who holds the URL, and never the call.
$wait_id = $resent['request_id'];
parse_str((string) parse_url($resent['wait_url'], PHP_URL_QUERY), $wait_query);
$wait_request = static fn(array $query = []): WP_REST_Request => new WP_REST_Request(array_merge(['id' => $wait_id], $wait_query, $query));
$out['wait_token_ok'] = C\wait_permission($wait_request());
$out['wait_token_wrong'] = C\wait_permission($wait_request(['token' => str_repeat('0', 32)])) instanceof WP_Error;
$out['wait_token_other_request'] = C\wait_permission(new WP_REST_Request(array_merge($wait_query, ['id' => str_repeat('a', 32)]))) instanceof WP_Error;
$past = time() - 1;
$out['wait_token_old'] = C\wait_permission($wait_request(['expires' => $past, 'token' => C\wait_token($wait_id, $past)])) instanceof WP_Error;
$waiting = C\wait_status($wait_request())->data;
$out['wait_pending'] = $waiting;
$out['wait_command_shape'] = [
    str_contains($resent['wait_command'], 'curl -sS -N'),
    str_contains($resent['wait_command'], escapeshellarg(C\stream_url($wait_id, C\get_request($wait_id)['created']))),
    (string) $resent['next_step'],
];
// The stream holds the connection while nobody decides, then tells the command to open the next one.
$stream = static function (string $id, ?callable $on_pause = null): array {
    $events = [];
    $pauses = 0;
    C\stream_wait(
        $id,
        emit: static function (string $text) use (&$events): void { $events[] = $text; },
        pause: static function (int $seconds) use (&$pauses, $on_pause): void { $pauses += $seconds; if ($on_pause !== null) { $on_pause($pauses); } },
        gone: static fn(): bool => false,
    );
    return [$events, $pauses];
};
[$quiet_events, $quiet_seconds] = $stream($wait_id);
$quiet_last = (string) end($quiet_events);
$out['stream_reconnects'] = [str_starts_with($quiet_last, 'event: reconnect'), $quiet_seconds < C\STREAM_DURATION_SECONDS, $quiet_seconds >= C\STREAM_DURATION_SECONDS - 4];
// Held for a moment, a pending call comes back still pending.
$started = microtime(true);
$held = $handler->call_tool(['name' => C\STATUS_TOOL, 'arguments' => ['request_id' => $wait_id, 'wait_seconds' => 2]])->toArray()['structuredContent'];
$out['held_poll'] = [$held['state'], microtime(true) - $started >= 1.5, $GLOBALS['executions']];
$before_decision = $GLOBALS['executions'];
approve($wait_id);
$decided = C\wait_status($wait_request())->data;
$out['wait_approved'] = [$decided['state'], $decided['waiting'], str_contains($decided['next_step'], C\STATUS_TOOL), $GLOBALS['executions'] === $before_decision];
$out['approved_runs_once'] = [poll($handler, $wait_id)['structuredContent']['state'], $GLOBALS['executions'] === $before_decision + 1];
// A decision taken while the stream is open ends it at the next check, with what to do next.
$stream_id = call($handler, array_merge($high, ['attention' => 9]))['structuredContent']['request_id'];
$stream_executions = $GLOBALS['executions'];
[$decided_events, $decided_seconds] = $stream($stream_id, static function (int $elapsed) use ($stream_id): void { if ($elapsed === 4) { approve($stream_id); } });
$decided_last = (string) end($decided_events);
$decided_data = json_decode(substr(trim(explode("\n", $decided_last)[1]), 6), true);
$out['stream_done'] = [str_starts_with($decided_last, 'event: done'), $decided_seconds, $decided_data['state'], $decided_data['waiting'], $GLOBALS['executions'] === $stream_executions];
// The stream only says a person decided: running the call still takes the authenticated status call.
$out['stream_then_runs'] = [poll($handler, $stream_id)['structuredContent']['state'], $GLOBALS['executions'] === $stream_executions + 1];
// Past the wait limit the command ends even though nobody decided, and the request stays valid.
$away_id = call($handler, array_merge($high, ['attention' => 9]))['structuredContent']['request_id'];
$away = C\get_request($away_id);
C\transition($away, array_merge($away, ['created' => time() - C\WAIT_LIMIT_SECONDS - 1]));
$away_reply = C\wait_status(new WP_REST_Request(['id' => $away_id]))->data;
$out['wait_stops_after_limit'] = [$away_reply['state'], $away_reply['waiting'], str_contains($away_reply['next_step'], 'end your turn')];
// Approving runs the call from the page, as the AI's user: the AI may have stopped, and its result waits for it.
$away = array_merge(C\get_request($away_id), ['state' => 'approved', 'decided_by' => 2, 'approved_at' => time()]);
C\transition(C\get_request($away_id), $away);
$GLOBALS['user_id'] = 2;
$before_page = $GLOBALS['executions'];
$ran = C\run_approved($away, $server);
$out['page_runs'] = [$ran['state'], $GLOBALS['executions'] === $before_page + 1, $GLOBALS['user_id'] === 2, array_key_exists('result', $ran)];
$GLOBALS['user_id'] = 1;
$read = poll($handler, $away_id)['structuredContent'];
$out['page_result_waits'] = [$read['state'], $read['tool_result'] !== null, $GLOBALS['executions'] === $before_page + 1];
$done = C\wait_status(new WP_REST_Request(['id' => $away_id]))->data;
$out['wait_after_page'] = [$done['state'], $done['waiting'], str_contains($done['next_step'], 'Do not send this call again')];
// Two clicks cannot run it twice.
$out['page_second_run'] = [C\run_approved($away, $server)['state'], $GLOBALS['executions'] === $before_page + 1];
// Numbers on the card that changed stop the approval, and show the new ones.
$card_id = call($handler, array_merge($high, ['attention' => 9]))['structuredContent']['request_id'];
$card = C\get_request($card_id);
$card['review']['measured'] = ['DELETE on wp_posts: 1 row(s)'];
C\transition(C\get_request($card_id), $card);
$changed = approve($card_id);
$out['card_changed'] = [
    $changed instanceof WP_Error ? $changed->get_error_code() : '',
    C\get_request($card_id)['state'],
    C\get_request($card_id)['review']['measured'] !== ['DELETE on wp_posts: 1 row(s)'],
];
$out['card_changed_then_approved'] = approve($card_id) instanceof WP_REST_Response;
poll($handler, $card_id);
$reject_id = call($handler, array_merge($high, ['attention' => 9]))['structuredContent']['request_id'];
C\decide(new WP_REST_Request(['id' => $reject_id, 'decision' => 'reject', 'decision_nonce' => 'browser-nonce']));
$rejected = poll($handler, $reject_id)['structuredContent'];
$out['rejected_not_resent'] = str_contains((string) $rejected['next_step'], 'only if they ask');
$id = call($handler, array_merge($high, ['attention' => 9]))['structuredContent']['request_id'];
$record = C\get_request($id);
C\transition($record, array_merge($record, ['state' => 'executing', 'started' => time() - 121]));
$out['unknown'] = poll($handler, $id)['structuredContent'];
C\save_settings(new WP_REST_Request(['cutoff' => 0]));
$out['zero_cutoff_read'] = call($handler, ['attention' => 0, 'summary' => 'Reads data.', 'details' => 'No changes.'])['structuredContent'];
$out['zero_cutoff_discovery'] = call($handler, [], 'mcp-adapter-discover-abilities');
$out['tools_enabled'] = $handler->list_tools()->toArray();
$id = call($handler, ['attention' => 0, 'summary' => 'Reads data.', 'details' => 'No changes.'])['structuredContent']['request_id'];
approve($id);
C\deactivate();
$out['deactivation_canceled'] = poll($handler, $id)['structuredContent'];
$out['deactivation_offline'] = C\runtime()['online'];
C\save_settings(new WP_REST_Request(['cutoff' => 10]));
$GLOBALS['read_mode'] = true;
$out['tools_disabled'] = $handler->list_tools()->toArray();
$GLOBALS['read_mode'] = false;
$out['browser_without_cookie'] = C\browser_permission(new WP_REST_Request([], ['X-WP-Nonce' => 'browser-nonce']));
$GLOBALS['cookie_user'] = 1;
$out['browser_without_nonce'] = C\browser_permission(new WP_REST_Request());
$out['browser_valid'] = C\browser_permission(new WP_REST_Request([], ['X-WP-Nonce' => 'browser-nonce']));
$GLOBALS['cookie_user'] = 2;
$out['browser_identity_mismatch'] = C\browser_permission(new WP_REST_Request([], ['X-WP-Nonce' => 'browser-nonce']));
$record = C\get_request($id);
C\transition($record, array_merge($record, ['finished' => time() - C\LOG_TTL - 1]));
C\cleanup();
$out['cleaned'] = C\get_request($id) === null;

C\save_settings(new WP_REST_Request(['cutoff' => 8]));
set_transient('novamira_confirmation_heartbeat', ['users' => [1 => time()]], 60);
C\session_id(new WP_REST_Request());
$out['session_required'] = call($handler, $high)['isError'];
C\session_id(new WP_REST_Request([], ['Mcp-Session-Id' => 'session-1']));
$id = call($handler, $high)['structuredContent']['request_id'];
$GLOBALS['user_meta'][1]['novamira_mcp_adapter_sessions']['session-1']['last_activity'] = time() - 100;
$activity = $GLOBALS['user_meta'][1]['novamira_mcp_adapter_sessions']['session-1']['last_activity'];
C\dashboard_page('active', 1)['requests'];
$out['dashboard_does_not_renew_session'] = $GLOBALS['user_meta'][1]['novamira_mcp_adapter_sessions']['session-1']['last_activity'] === $activity;
// A request belongs to the user on this server: the end of the connection does not touch it.
SessionManager::delete_session(1, 'session-1');
$out['ended_session_keeps_request'] = C\get_request($id)['state'];
$out['ended_session_call_data'] = stored_call($id)->data;
$before = $GLOBALS['executions'];
$out['dead_session_cannot_poll'] = poll($handler, $id)['isError'] && $GLOBALS['executions'] === $before;
$GLOBALS['user_meta'][1]['novamira_mcp_adapter_sessions']['session-2'] = ['last_activity' => time()];
C\session_id(new WP_REST_Request([], ['Mcp-Session-Id' => 'session-2']));
$open = $handler->call_tool(['name' => C\STATUS_TOOL, 'arguments' => []])->toArray()['structuredContent']['open_requests'];
$out['new_session_lists_request'] = array_column($open, 'request_id') === [$id];
$out['new_session_same_request'] = call($handler, $high)['structuredContent']['request_id'] === $id;
$waiting = poll($handler, $id)['structuredContent'];
$out['pending_stays_pending'] = $waiting['state'] === 'pending_confirmation' && $GLOBALS['executions'] === $before;
$out['pending_says_stop_polling'] = str_contains((string) $waiting['next_step'], 'at most ' . C\POLL_LIMIT . ' times');
$out['pending_says_check_again'] = [
    str_contains((string) $waiting['next_step'], C\STATUS_TOOL . ' without request_id'),
    str_contains((string) $waiting['next_step'], 'has been decided'),
];
approve($id);
$out['new_session_runs_approval'] = poll($handler, $id)['structuredContent']['tool_result'] !== null && $GLOBALS['executions'] === $before + 1;
$out['new_session_result_once'] = poll($handler, $id)['structuredContent']['tool_result'] !== null && $GLOBALS['executions'] === $before + 1;
$out['finished_not_listed'] = $handler->call_tool(['name' => C\STATUS_TOOL, 'arguments' => []])->toArray()['structuredContent']['open_requests'] === [];
$GLOBALS['user_meta'][1]['novamira_mcp_adapter_sessions']['session-3'] = ['last_activity' => time()];
C\session_id(new WP_REST_Request([], ['Mcp-Session-Id' => 'session-3']));
$id3 = call($handler, $high)['structuredContent']['request_id'];
$out['finished_call_queues_again'] = $id3 !== $id;
delete_user_meta(1, 'novamira_mcp_adapter_sessions');
$out['deleted_session_map'] = C\get_request($id3)['state'];
close_open_requests();

$GLOBALS['user_meta'][1]['novamira_mcp_adapter_sessions']['session-4'] = ['last_activity' => time()];
C\session_id(new WP_REST_Request([], ['Mcp-Session-Id' => 'session-4']));
close_open_requests();
$id4 = call($handler, $high)['structuredContent']['request_id'];
approve($id4);
$out['retained_result'] = poll($handler, $id4)['structuredContent']['tool_result'];
$record = C\get_request($id4);
C\transition($record, array_merge($record, ['created' => time() - C\REQUEST_TTL]));
$out['retention_from_completion'] = poll($handler, $id4)['structuredContent']['tool_result'] === $out['retained_result'];
$record = C\get_request($id4);
C\transition($record, array_merge($record, ['finished' => time() - 14 * DAY_IN_SECONDS]));
$out['retained_after_two_weeks'] = poll($handler, $id4)['structuredContent']['tool_result'] === $out['retained_result'];
$record = C\get_request($id4);
C\transition($record, array_merge($record, ['finished' => time() - C\LOG_TTL - 1]));
$out['retention_expired_poll'] = poll($handler, $id4)['isError'];
$out['retention_deleted'] = C\get_request($id4) === null;
$out['purged_call_data_status'] = stored_call($id4)->get_error_data()['status'];

$id5 = call($handler, $high)['structuredContent']['request_id'];
$record = C\get_request($id5);
C\transition($record, array_merge($record, ['state' => 'executing', 'started' => time()]));
SessionManager::delete_session(1, 'session-4');
$out['executing_not_canceled'] = C\get_request($id5)['state'];
$record = C\get_request($id5);
C\transition($record, array_merge($record, ['started' => time() - 121]));
C\cleanup();
$out['interrupted_ended_session'] = C\get_request($id5);

$GLOBALS['user_meta'][1]['novamira_mcp_adapter_sessions']['ending-during-action'] = ['last_activity' => time()];
C\session_id(new WP_REST_Request([], ['Mcp-Session-Id' => 'ending-during-action']));
close_open_requests();
$id7 = call($handler, $high)['structuredContent']['request_id'];
approve($id7);
$GLOBALS['end_session_during_action'] = true;
$before = $GLOBALS['executions'];
$out['ended_during_execution'] = poll($handler, $id7)['structuredContent'];
unset($GLOBALS['end_session_during_action']);
$out['ended_during_execution_once'] = $GLOBALS['executions'] === $before + 1;
$out['ended_during_execution_logged_result'] = array_key_exists('result', C\get_request($id7));

C\capture_session(null, [], new WP_REST_Request([], ['Mcp-Session-Id' => 'captured-session']));
$out['captured_rest_header'] = C\session_id();
C\capture_session(null, [], new WP_REST_Request());
$out['rest_context_resets'] = C\session_id();

$GLOBALS['user_meta'][1]['novamira_mcp_adapter_sessions']['history-live'] = ['last_activity' => time()];
C\session_id(new WP_REST_Request([], ['Mcp-Session-Id' => 'history-live']));
close_open_requests();
$expired_id = call($handler, $high)['structuredContent']['request_id'];
$record = C\get_request($expired_id);
C\transition($record, array_merge($record, ['created' => time() - C\REQUEST_TTL - 1]));
$out['default_active_states'] = array_column(C\dashboard_page('active', 1)['requests'], 'state');
$out['expired_in_history'] = in_array($expired_id, array_column(C\dashboard_page('previous', 1)['requests'], 'request_id'), true);
$out['expired_not_active'] = !in_array($expired_id, array_column(C\dashboard_page('active', 1)['requests'], 'request_id'), true);
$out['ended_in_history'] = in_array($id7, array_column(C\dashboard_page('previous', 1)['requests'], 'request_id'), true);
$out['finished_timestamp'] = C\public_record(C\get_request($expired_id))['finished_at'];

for ($i = 0; $i < 27; $i++) {
    $history_id = sprintf('%032x', 200 + $i);
    add_option(C\REQUEST_PREFIX . $history_id, array_merge($record, [
        'id' => $history_id, 'state' => 'completed', 'created' => time(), 'finished' => time(),
    ]), '', false);
}
$first_page = C\dashboard_page('previous', 1);
$out['history_page_size'] = count($first_page['requests']);
$out['history_has_more'] = $first_page['has_more'];
$out['history_page_distinct'] = array_intersect(
    array_column($first_page['requests'], 'request_id'),
    array_column(C\dashboard_page('previous', 2)['requests'], 'request_id')
) === [];
$out['history_page_newest_first'] = $first_page['requests'][0]['request_id'] === sprintf('%032x', 226);
$out['history_page_past_end'] = C\dashboard_page('previous', 1000);

$out['missing_call_data_status'] = stored_call(str_repeat('f', 32))->get_error_data()['status'];
$out['invalid_call_data_status'] = stored_call(['invalid'])->get_error_data()['status'];
do_action('rest_api_init');
// Deciding needs the browser: a cookie and the REST nonce, never an AI token.
$data_route = $GLOBALS['routes']['/confirmations/(?P<id>[a-f0-9]{32})/decision'];
$out['call_data_method'] = $data_route['methods'];
$GLOBALS['cookie_user'] = false;
$out['call_data_requires_cookie'] = !$data_route['permission_callback'](new WP_REST_Request(['decision_nonce' => 'browser-nonce'], ['X-WP-Nonce' => 'browser-nonce']));
$GLOBALS['cookie_user'] = 1;
$out['call_data_requires_nonce'] = !$data_route['permission_callback'](new WP_REST_Request(['decision_nonce' => 'browser-nonce']));
$out['call_data_browser_allowed'] = $data_route['permission_callback'](new WP_REST_Request(['decision_nonce' => 'browser-nonce'], ['X-WP-Nonce' => 'browser-nonce']));
// The REST nonce of any admin page is not enough to decide: the decision carries the one only AI Activity prints.
$out['decision_needs_its_nonce'] = [
    $data_route['permission_callback'](new WP_REST_Request([], ['X-WP-Nonce' => 'browser-nonce']))->get_error_code(),
    $data_route['permission_callback'](new WP_REST_Request(['decision_nonce' => 'other'], ['X-WP-Nonce' => 'browser-nonce']))->get_error_code(),
];

// Read mode turns confirmations off: no score, no page, no status tool, and pending requests are canceled.
C\save_settings(new WP_REST_Request(['cutoff' => 8]));
$GLOBALS['can_execute'] = true;
$GLOBALS['transients']['novamira_confirmation_heartbeat'] = null;
$read_mode_id = sprintf('%032x', 900);
add_option(C\REQUEST_PREFIX . $read_mode_id, array_merge($record, [
    'id' => $read_mode_id, 'state' => 'pending_confirmation', 'created' => time(),
    'generation' => C\settings()['generation'],
]), '', false);
unset($GLOBALS['options'][C\REQUEST_PREFIX . $read_mode_id]['finished']);
$GLOBALS['read_mode'] = true;
$read_mode_before = $GLOBALS['executions'];
$out['read_mode_no_score'] = call($handler);
$out['read_mode_executed'] = $GLOBALS['executions'] === $read_mode_before + 1;
$read_mode_tools = array_map(
    static fn($tool): array => json_decode(json_encode($tool->toArray(), JSON_THROW_ON_ERROR), true),
    C\decorate_tools(array_values($server->get_tools())),
);
$out['read_mode_no_status_tool'] = !in_array(C\STATUS_TOOL, array_column($read_mode_tools, 'name'), true);
$out['read_mode_no_review_schema'] = array_filter(
    $read_mode_tools,
    static fn(array $tool): bool => isset($tool['inputSchema']['properties']['review']),
) === [];
$out['read_mode_runtime'] = C\runtime()['read_mode'];
$out['read_mode_pending_canceled'] = C\refresh_record(C\get_request($read_mode_id))['state'];
$GLOBALS['read_mode'] = false;

// Prompts and resources run abilities without a score, so active confirmations refuse untrusted ones of ours.
$untrusted = ['ability' => new WP_Ability('novamira/delete-file')];
$others = ['ability' => new WP_Ability('acme/delete-everything')];
$lookalike = ['ability' => new WP_Ability(
    'novamira/skill-prompt-fake',
    static fn(): array => [],
    'novamira_permission_callback',
)];
$component_refusal = static fn(mixed $component): string => ($r = C\gate_component([], 'name', $component)) instanceof WP_Error
    ? $r->get_error_code()
    : 'allowed';
foreach (['novamira_mcp_adapter_pre_prompt_get', 'novamira_mcp_adapter_pre_resource_read'] as $hook) {
    $out['component_hooked'][] = in_array('Novamira\\Confirmations\\gate_component', array_column($GLOBALS['filters'][$hook] ?? [], 0), true);
}
$out['component_untrusted'] = $component_refusal($untrusted);
$out['component_of_another_plugin'] = $component_refusal($others);
$out['component_no_ability'] = $component_refusal(['ability' => null]);
$out['component_lookalike_skill'] = $component_refusal($lookalike);
$out['component_trusted'] = $component_refusal(['ability' => new WP_Ability('novamira/read-file'), 'trusted' => true]);
C\set_ability_choice('acme/delete-everything', 'yes');
$out['component_forced'] = $component_refusal($others);
C\set_ability_choice('acme/delete-everything', 'level');
$earlier = new WP_Error('earlier', 'earlier');
$out['component_keeps_earlier_error'] = C\gate_component($earlier, 'name', $untrusted) === $earlier;
$GLOBALS['read_mode'] = true;
$out['component_read_mode'] = $component_refusal($untrusted);
$GLOBALS['read_mode'] = false;
$out['timeout_short_limit'] = C\execution_timeout();
ini_set('max_execution_time', '300');
$out['timeout_long_limit'] = C\execution_timeout();
ini_set('max_execution_time', '0');
$out['timeout_unlimited'] = C\execution_timeout();
ini_set('max_execution_time', '30');
$late = array_merge($record, ['id' => 'late', 'state' => 'executing', 'started' => time() - 200]);
unset($late['finished']);
update_option(C\REQUEST_PREFIX . 'late', $late);
$late = C\get_request('late');
$late_unknown = C\refresh_record($late);
$out['late_marked_unknown'] = $late_unknown['state'];
$out['late_result_kept'] = C\finish_execution($late, array_merge($late, [
    'state' => 'completed',
    'finished' => time(),
    'result' => ['content' => []],
]))['state'];
$out['late_stored_state'] = C\get_request('late')['state'];
$other = array_merge($late_unknown, ['id' => 'other', 'started' => time() - 999]);
update_option(C\REQUEST_PREFIX . 'other', $other);
$out['other_run_not_overwritten'] = C\finish_execution(
    array_merge($other, ['state' => 'executing', 'started' => time() - 200]),
    array_merge($other, ['state' => 'completed']),
)['state'];

// Identical calls share one request through an atomically reserved key; presence belongs to one user.
C\save_settings(new WP_REST_Request(['cutoff' => 7]));
C\save_settings(new WP_REST_Request(['cutoff' => 8]));
$GLOBALS['read_mode'] = false;
$GLOBALS['can_execute'] = true;
$GLOBALS['user_meta'][1]['novamira_mcp_adapter_sessions']['session-dedup'] = ['last_activity' => time()];
C\session_id(new WP_REST_Request([], ['Mcp-Session-Id' => 'session-dedup']));
close_open_requests();
set_transient('novamira_confirmation_heartbeat', ['users' => [2 => time()]], 60);
$out['other_user_page_not_online'] = C\runtime()['online'];
$keys_before = array_values(array_filter(array_keys($GLOBALS['options']), static fn(string $k): bool => str_starts_with($k, C\KEY_PREFIX)));
$out['closed_page_queues'] = call($handler, $high)['structuredContent']['state'] ?? '';
close_open_requests();
C\record_heartbeat(1);
$out['own_page_online'] = C\runtime()['online'];
$out['other_user_heartbeat_kept'] = array_keys(C\heartbeats());
$first = call($handler, $high)['structuredContent'];
$out['same_call_other_score'] = call($handler, array_merge($high, ['attention' => 10]))['structuredContent']['request_id'] === $first['request_id'];
$keys = array_values(array_diff(array_values(array_filter(array_keys($GLOBALS['options']), static fn(string $k): bool => str_starts_with($k, C\KEY_PREFIX))), $keys_before));
$out['one_key_per_call'] = count($keys);
$out['key_not_a_request'] = !str_starts_with($keys[0], C\REQUEST_PREFIX);
$race = 'novamira_confirmation_key_race';
$out['first_claim'] = C\claim_key($race, sprintf('%032x', 1), 'caller');
$out['gone_request_taken_over'] = C\claim_key($race, sprintf('%032x', 2), 'caller');
$out['gone_points_to_new'] = $GLOBALS['options'][$race] === sprintf('%032x', 2);
$live = sprintf('%032x', 3);
update_option(C\REQUEST_PREFIX . $live, array_merge(C\get_request($first['request_id']), ['id' => $live, 'caller' => 'caller']));
$GLOBALS['options'][$race] = $live;
$out['live_request_returned'] = (C\claim_key($race, sprintf('%032x', 4), 'caller')['id'] ?? null) === $live;
$out['other_caller_not_returned'] = C\claim_key($race, sprintf('%032x', 5), 'someone-else');
$requests_before = count(array_filter(array_keys($GLOBALS['options']), static fn(string $k): bool => str_starts_with($k, C\REQUEST_PREFIX)));
call($handler, $high);
$out['duplicate_not_stored'] = $requests_before === count(array_filter(array_keys($GLOBALS['options']), static fn(string $k): bool => str_starts_with($k, C\REQUEST_PREFIX)));
$record = C\get_request($first['request_id']);
C\transition($record, array_merge($record, ['state' => 'rejected']));
$out['ended_request_new_one'] = call($handler, $high)['structuredContent']['request_id'] !== $first['request_id'];
$GLOBALS['options'][$race] = sprintf('%032x', 6);
C\prune_keys();
$out['pruned_gone'] = !array_key_exists($race, $GLOBALS['options']);
$out['kept_live_key'] = array_key_exists($keys[0], $GLOBALS['options']);

// Requests that can no longer execute keep their log without secrets; every scored call leaves one line.
$GLOBALS['options']['novamira_oauth_private_key'] = 'oauth-private-key-value';
$secret_code = 'echo "$P$BabcdefghijklmnopqrstuvwxyzABCD"; // site-auth-key-"quoted"-value sk_live_abcdefghijklmnop1234';
$log_before = count(get_option(C\SCORE_LOG_OPTION, []));
$secret_id = $handler->call_tool(['name' => 'mcp-adapter-execute-ability', 'arguments' => [
    'ability_name' => 'novamira/execute-php',
    'parameters' => ['code' => $secret_code],
    'review' => ['attention' => 8, 'summary' => 'Uses sk_live_summaryabcdefgh12345.', 'details' => 'Reads site-auth-key-"quoted"-value.'],
]])->toArray()['structuredContent']['request_id'];
$out['pending_keeps_exact_code'] = C\get_request($secret_id)['arguments']['parameters']['code'] === $secret_code;
approve($secret_id);
$secret_poll = poll($handler, $secret_id)['structuredContent'];
$stored = serialize(C\get_request($secret_id));
$out['stored_no_hash'] = !str_contains($stored, '$P$Babcdefghijklmnop');
$out['stored_no_site_secret'] = !str_contains($stored, 'site-auth-key');
$out['stored_no_api_key'] = !str_contains($stored, 'sk_live_abcdefghijklmnop1234');
$out['stored_marked'] = substr_count($stored, S\REDACTED) >= 3;
$out['stored_review'] = C\get_request($secret_id)['review'];
$out['secret_call_completed'] = $secret_poll['state'];
$out['agent_result_redacted'] = !str_contains(json_encode($secret_poll), 'site-auth-key');
$out['redact_json_escaped'] = S\redact('{"h":"$P$B\/bcdefghijklmnopqrstuvwxyzABCD"}');
$out['redact_site_option'] = S\redact(['nested' => ['x oauth-private-key-value y']]);
$out['redact_leaves_plain'] = S\redact('just a normal sentence with $ signs');
$below = call($handler, ['attention' => 5]);
$log = get_option(C\SCORE_LOG_OPTION, []);
$last = end($log);
$out['score_log_lines'] = count($log) - $log_before;
$out['score_log_last'] = ['ability' => $last['ability'], 'score' => $last['attention'], 'queued' => $last['queued']];
$out['score_log_no_code'] = !str_contains(serialize($log), 'echo');

// An approval lasts ten minutes from the click; an OAuth token refresh keeps the request reachable.
$GLOBALS['user_meta'][1]['novamira_mcp_adapter_sessions']['session-ttl'] = ['last_activity' => time()];
C\session_id(new WP_REST_Request([], ['Mcp-Session-Id' => 'session-ttl']));
close_open_requests();
C\record_heartbeat(1);
C\save_settings(new WP_REST_Request(['cutoff' => 8]));
$ttl_id = $handler->call_tool(['name' => 'mcp-adapter-execute-ability', 'arguments' => [
    'ability_name' => 'novamira/execute-php', 'parameters' => ['code' => 'echo "ttl";'], 'review' => $high,
]])->toArray()['structuredContent']['request_id'];
$record = C\get_request($ttl_id);
C\transition($record, array_merge($record, ['created' => time() - C\REQUEST_TTL + 5]));
approve($ttl_id);
$out['late_approval_has_time'] = C\refresh_record(C\get_request($ttl_id))['state'];
$record = C\get_request($ttl_id);
C\transition($record, array_merge($record, ['approved_at' => time() - C\APPROVAL_TTL - 1]));
$out['stale_approval_expires'] = C\refresh_record(C\get_request($ttl_id))['state'];
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer first-token';
$owner_first = C\call_owner($server);
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer refreshed-token';
$out['credential_change_same_owner'] = $owner_first === C\call_owner($server);
unset($_SERVER['HTTP_AUTHORIZATION']);

// Novamira's code check refuses dynamic calls and sets a floor under the AI's rating.
$GLOBALS['user_meta'][1]['novamira_mcp_adapter_sessions']['session-analysis'] = ['last_activity' => time()];
C\session_id(new WP_REST_Request([], ['Mcp-Session-Id' => 'session-analysis']));
close_open_requests();
C\record_heartbeat(1);
C\save_settings(new WP_REST_Request(['cutoff' => 8]));
$run = static fn(string $code, int $score): array => $handler->call_tool(['name' => 'mcp-adapter-execute-ability', 'arguments' => [
    'ability_name' => 'novamira/execute-php', 'parameters' => ['code' => $code], 'review' => ['attention' => $score, 'summary' => 'Says what it does.'],
]])->toArray();
$before = $GLOBALS['executions'];
$refused = $run('$f = "x"; $f();', 5);
$out['enforce_asks_about_dynamic'] = [$refused['structuredContent']['state'] ?? null, $refused['structuredContent']['attention'] ?? null, str_contains(implode(' ', $refused['structuredContent']['found'] ?? []), 'can only be seen once it runs')];
$internals = $run('\\Novamira\\Confirmations\\execution_ticket("x");', 5);
$out['enforce_refuses_internals'] = $internals['isError'] && str_contains($internals['structuredContent']['error'] ?? '', 'refuse:novamira_internals');
$out['enforce_refused_not_run'] = $GLOBALS['executions'] === $before;
$raised = $run('update_option("active_plugins", ["a.php"]);', 5)['structuredContent'];
$out['enforce_raises_to_confirmation'] = $raised['state'] ?? null;
$out['enforce_raised_score'] = $raised['attention'] ?? null;
$out['enforce_raised_summary'] = ($raised['summary'] ?? '') === 'Says what it does.' && in_array('Changes a setting the whole site depends on', $raised['found'] ?? [], true);
$out['refund_over_mcp_score'] = $run('wc_create_refund(["order_id" => 1]);', 5)['structuredContent']['attention'] ?? null;
$out['enforce_read_runs'] = ($run('return get_option("blogname");', 5)['isError'] ?? false) === false
    && $GLOBALS['executions'] === $before + 1;
$out['write_php_refused'] = $handler->call_tool(['name' => 'mcp-adapter-execute-ability', 'arguments' => [
    'ability_name' => 'novamira/write-file',
    'parameters' => ['path' => 'wp-content/novamira-sandbox/x.php', 'content' => "<?php\n\$f = 'x'; \$f();\n"],
    'review' => ['attention' => 5, 'summary' => 'Says what it does.'],
]])->toArray()['structuredContent']['state'] ?? null;
$out['chat_check'] = [
    'read' => C\chat_call_check('novamira/read-file', ['path' => 'x.txt']),
    'small' => C\chat_call_check('novamira/execute-php', ['code' => 'echo get_option("blogname");'], 0),
    'refused' => C\chat_call_check('novamira/execute-php', ['code' => '\\Novamira\\Confirmations\\execution_ticket("x");'], 0)['refused'] !== '',
    'unreadable_asks' => C\chat_call_check('novamira/execute-php', ['code' => '$f = "unlink"; $f("x");'], 0)['must_ask'],
    'high' => C\chat_call_check('novamira/execute-php', ['code' => 'update_option("active_plugins", []);'], 0)['must_ask'],
    'high_why' => C\chat_call_check('novamira/execute-php', ['code' => 'update_option("active_plugins", []);'], 0)['why'],
    // Turning on a sandbox file counts as a big change: it asks from the Balanced level up, whatever the AI says.
    'always' => C\chat_call_check('novamira/enable-file', ['path' => 'wp-content/novamira-sandbox/feature.php'], 0)['must_ask'],
    'no_attention_refused' => C\chat_call_check('novamira/execute-php', ['code' => 'echo 1;'])['refused'] !== '',
    'model_raises' => C\chat_call_check('novamira/execute-php', ['code' => 'echo 1;'], 9)['must_ask'],
    'model_low_runs' => !C\chat_call_check('novamira/execute-php', ['code' => 'echo 1;'], 1)['must_ask'],
    'model_cannot_lower' => C\chat_call_check('novamira/execute-php', ['code' => 'update_option("active_plugins", []);'], 0)['must_ask'],
    'mode' => C\chat_approval_mode(),
];
C\save_settings(new WP_REST_Request(['cutoff' => 8, 'hold_emails' => false]));
$out['email_setting'] = [
    'saved' => C\holds_emails(),
    'generation_kept' => C\settings()['generation'],
    'ai_email_asks' => C\chat_call_check('novamira/execute-php', ['code' => 'wp_mail("a@example.test", "s", "m");'], 0)['must_ask'],
];
C\save_settings(new WP_REST_Request(['cutoff' => 8, 'hold_emails' => true]));
$out['email_setting']['same_generation'] = $out['email_setting']['generation_kept'] === C\settings()['generation'];
$out['email_setting']['held_again'] = C\holds_emails();
$out['email_setting']['direct_mail_asks'] = C\chat_call_check('novamira/execute-php', ['code' => 'mail("a@example.test", "s", "m");'], 0)['must_ask'];
$out['email_setting']['ai_email_waits_later'] = C\chat_call_check('novamira/execute-php', ['code' => 'wp_mail("a@example.test", "s", "m");'], 0)['must_ask'];
unset($out['email_setting']['generation_kept']);
$out['refund_check'] = [
    'ai_request_asks' => C\chat_call_check('novamira/execute-php', ['code' => 'wp_remote_post("https://example.test/");'], 0)['must_ask'],
    'parser_asks_a_person' => C\chat_call_check('novamira/execute-php', ['code' => 'wc_create_refund(["order_id" => 1]);'], 0)['must_ask'],
    'ability_code_is_not_read' => C\chat_call_check('acme/refund-order', ['order_id' => 1])['must_ask'],
    'row_count_alone_asks_nothing' => C\chat_call_check('novamira/execute-php', ['code' => 'wp_update_post(["ID" => 1]);'], 0)['must_ask'],
];
$out['chat_print'] = [
    'same_card_same_print' => C\chat_call_check('novamira/execute-php', ['code' => 'update_option("active_plugins", []);'], 0)['fingerprint'] === C\chat_call_check('novamira/execute-php', ['code' => 'update_option("active_plugins", []);'], 0)['fingerprint'],
    'other_code_other_print' => C\chat_call_check('novamira/execute-php', ['code' => 'update_option("active_plugins", []);'], 0)['fingerprint'] !== C\chat_call_check('novamira/execute-php', ['code' => 'update_option("siteurl", "x");'], 0)['fingerprint'],
];
$executions_before_wp_cli = $GLOBALS['executions'];
$wp_cli = static fn(array $args, array $review): array => $handler->call_tool(['name' => 'mcp-adapter-execute-ability', 'arguments' => [
    'ability_name' => 'novamira/run-wp-cli', 'parameters' => ['args' => $args], 'review' => $review,
]])->toArray();
$says = static fn(int $score): array => ['attention' => $score, 'summary' => 'Deletes article 12.', 'details' => 'Details.'];
$wp_before = $GLOBALS['executions'];
$out['wp_cli_read_runs'] = ($wp_cli(['plugin', 'list'], $says(5))['isError'] ?? false) === false && $GLOBALS['executions'] === $wp_before + 1;
$out['wp_cli_needs_words'] = $wp_cli(['plugin', 'list'], ['attention' => 5]);
$out['wp_cli_small_change_runs'] = ($wp_cli(['post', 'delete', '12'], $says(5))['isError'] ?? false) === false && $GLOBALS['executions'] === $wp_before + 2;
$out['wp_cli_ai_raises'] = $wp_cli(['post', 'delete', '13'], $says(9))['structuredContent'];
$out['wp_cli_rules_raise'] = $wp_cli(['user', 'create', 'bob', 'b@example.test'], $says(5))['structuredContent'];
// The card says what a command means, not the command: people approving do not read WP-CLI.
$means = static fn(array $words): string => C\wp_cli_meaning(['parameters' => ['args' => $words]]);
// Novamira's own settings: the AI's code and commands never change them, and reading them stays allowed.
$out['novamira_settings_refused'] = [
    C\analyze_code("update_option('novamira_confirmation_settings', ['skip_approvals' => true]);")['rules'],
    C\analyze_code("update_network_option(1, 'novamira_read_mode', false);")['rules'],
    C\analyze_code("get_option('novamira_confirmation_settings');")['reject'],
    C\wp_cli_rules(['option', 'update', 'novamira_confirmation_settings', '{}']),
    C\wp_cli_rules(['option', 'get', 'novamira_confirmation_settings']),
    C\wp_cli_rules(['db', 'query', "DELETE FROM wp_options WHERE option_name LIKE 'novamira_%'"]),
];
// Calls after which the AI may lose this site always ask a person, whatever the threshold.
$out['access_rules'] = [
    C\wp_cli_rules(['search-replace', 'https://shop.example.test', 'https://new.example.test']),
    C\wp_cli_rules(['search-replace', 'old-text', 'new-text']),
    C\wp_cli_rules(['option', 'update', 'siteurl', 'https://new.example.test']),
    C\wp_cli_rules(['plugin', 'deactivate', 'novamira']),
    C\wp_cli_rules(['plugin', 'deactivate', '--all']),
    C\wp_cli_rules(['plugin', 'deactivate', 'hello-dolly']),
    C\analyze_code("update_option('home', 'https://new.example.test');")['rules'],
    C\analyze_code("update_option('active_plugins', []);")['rules'],
    C\analyze_code("update_option('active_plugins', get_option('active_plugins'));")['rules'],
    C\analyze_code("deactivate_plugins('novamira/novamira.php');")['rules'],
];
$out['access_lines'] = C\access_lines(C\analysis_result(['access:site_address', 'high:critical_option']));
// What the check sees beyond plain function calls: callbacks, closures run per item, methods, statics, hooks.
$score = static fn(string $code): int => C\analyze_code($code)['score'];
$out['check_scores'] = [
    'callback_in_array_map' => $score("array_map('wp_delete_post', \$ids);"),
    'foreach_same_work' => $score('foreach ($ids as $id) { wp_delete_post($id); }'),
    'closure_in_array_map' => $score('array_map(fn($id) => wp_delete_post($id), $ids);'),
    'closure_elsewhere' => $score('$f = fn($id) => wp_delete_post($id);'),
    'callback_kept_rule' => $score("add_action('init', 'wp_delete_post');"),
    'order_delete' => $score('$order->delete(true);'),
    'products_saved_in_loop' => $score('foreach ($products as $p) { $p->save(); }'),
    'wpdb_delete' => C\analyze_code("\$wpdb->delete('wp_posts', ['ID' => 1]);")['rules'],
    'filesystem_delete' => C\analyze_code("\$wp_filesystem->delete('/tmp/x');")['rules'],
    'app_password' => $score("WP_Application_Passwords::create_new_application_password(1, ['name' => 'x']);"),
    'plain_static' => $score('Foo::bar();'),
    'upgrader' => $score('$u = new Plugin_Upgrader();'),
    'network_site' => $score('wpmu_delete_blog(2, true);'),
    'db_delta' => $score("dbDelta('CREATE TABLE x (id int)');"),
    'stock' => $score('wc_update_product_stock($product, 5);'),
    'shutdown_hook' => C\analyze_code("add_action('shutdown', function () { wp_delete_post(1); });")['rules'],
    'shutdown_function' => $score('register_shutdown_function(fn() => 1);'),
    'removes_query_filter' => C\analyze_code("remove_all_filters('query');")['rules'],
    'removes_other_filter' => $score("remove_all_filters('the_content');"),
    'password_email' => $score("retrieve_password('bob');"),
];
$out['loop_words'] = C\found_rule_words('high:deletes_content_in_loop');
// Turning a plugin or theme on or off is undone the same way; deleting one is not.
$out['on_off_scores'] = [
    'activate' => $score("activate_plugin('hello-dolly/hello.php');"),
    'switch_theme' => $score("switch_theme('twentytwentyfive');"),
    'delete_plugins' => $score("delete_plugins(['hello-dolly/hello.php']);"),
    'deactivate_novamira' => C\analyze_code("deactivate_plugins('novamira/novamira.php');")['rules'],
    'cli_activate' => C\wp_cli_rules(['plugin', 'activate', 'hello-dolly']),
    'cli_theme_activate' => C\wp_cli_rules(['theme', 'activate', 'twentytwentyfive']),
    'cli_install' => C\wp_cli_rules(['plugin', 'install', 'hello-dolly']),
    'cli_delete' => C\wp_cli_rules(['theme', 'delete', 'twentytwenty']),
];
// Only an argument that takes a callback names a function; a callback gets the rules of a direct call.
$rules = static fn(string $code): array => C\analyze_code($code)['rules'];
$out['callback_fixes'] = [
    'meta_key_system' => $rules("get_post_meta(1, 'system', true);"),
    'option_named_mail' => $score("get_option('mail');"),
    'filter_with_system' => $rules("array_filter(\$rows, 'system');"),
    'deactivate_via_map' => $rules("array_map('deactivate_plugins', ['novamira/novamira.php']);"),
    'option_callback_novamira' => $rules("array_map('update_option', ['novamira_read_mode'], [0]);"),
    'option_callback_siteurl' => $rules("array_map('update_option', ['siteurl'], ['https://x.test']);"),
    'option_callback_plain' => $rules("array_map('update_option', ['blogname'], ['x']);"),
    'internal_callback' => $rules("array_map('Novamira\\\\Journal\\\\halt', ['x']);"),
    'static_callback' => $score("array_map(['WP_Application_Passwords', 'delete_all_application_passwords'], [1]);"),
    'named_callback' => $score("array_map(callback: 'wp_delete_post', array: \$ids);"),
    'shutdown_flush_off' => $rules("remove_action('shutdown', 'wp_ob_end_flush_all', 1);"),
    'shutdown_all_off' => $rules("remove_all_actions('shutdown');"),
    'shutdown_novamira_off' => $rules("remove_action('shutdown', 'novamira_purge');"),
    'query_one_off' => $rules("remove_filter('query', 'some_cb');"),
    'cli_toggle_novamira' => C\wp_cli_rules(['plugin', 'toggle', 'novamira']),
    'cli_toggle_other' => C\wp_cli_rules(['plugin', 'toggle', 'hello-dolly']),
    'object_delete_words' => C\found_rule_words('medium:object_delete'),
    'object_save_words' => C\found_rule_words('high:object_save_in_loop'),
    'spread_literal' => $score("array_map(...['wp_delete_post', [1, 2]]);"),
    'spread_variable' => $rules('array_map(...$args);'),
    'variable_in_map' => $rules('array_map($f, $ids);'),
    'variable_in_hook' => $rules("add_action('init', \$f);"),
    'object_in_map' => $rules("array_map([\$wpdb, 'query'], \$sqls);"),
    'object_method_in_hook' => $rules("add_action('init', [\$this, 'setup']);"),
    'first_class_in_map' => $score('array_map(wp_delete_post(...), $ids);'),
    'null_in_map' => $rules('array_map(null, $a, $b);'),
    'callback_array' => $rules("preg_replace_callback_array(['/a/' => 'system'], \$text);"),
    'udiff_last' => $rules("array_udiff(\$a, \$b, 'wp_delete_post');"),
    'rest_route' => $rules("register_rest_route('x/v1', '/y', ['methods' => 'POST', 'callback' => 'wp_delete_post']);"),
    'error_handler_named' => $rules("set_error_handler(error_handler: 'wp_delete_post');"),
    'option_value_not_name' => $rules("array_map('update_option', ['ordinary'], ['novamira_example']);"),
    'hook_name_not_option' => $rules("add_filter('novamira_instructions', 'update_option');"),
    'other_plugin_query_filter_off' => $rules("remove_filter('query', 'other_plugin_cb');"),
    'novamira_query_filter_off' => $rules("remove_filter('query', 'Novamira\\\\Journal\\\\on_query', PHP_INT_MAX);"),
];
$out['check_available'] = C\analysis_available();
$out['wp_cli_meanings'] = [
    $means(['plugin', 'install', 'seo-tools', '--activate']),
    $means(['plugin', 'list']),
    $means(['user', 'create', 'bob', 'b@example.test', '--role=editor']),
    $means(['option', 'update', 'blogname', 'Nuovo nome']),
    $means(['post', 'delete', '13', '--force']),
    $means(['search-replace', 'http://old.test', 'https://new.test', '--dry-run']),
    $means(['db', 'query', 'SELECT 1']),
    $means(['@prod', 'cache', 'flush']),
    $means(['acme', 'do-thing']),
];
$out['wp_cli_unknown_runs'] = ($wp_cli(['acme', 'fix'], $says(5))['isError'] ?? false) === false && $GLOBALS['executions'] === $wp_before + 3;
$out['wp_cli_insert_ai_raises'] = $wp_cli(['db', 'query', 'INSERT INTO wp_options VALUES (1)'], $says(9))['structuredContent']['state'] ?? null;
$wp_before = $GLOBALS['executions'];
$out['wp_cli_forbidden_call'] = $wp_cli(['eval', 'echo 1;'], $says(5));
$out['wp_cli_forbidden_not_run'] = $GLOBALS['executions'] === $wp_before;
$out['wp_cli_forbidden_in_chat'] = C\chat_call_check('novamira/run-wp-cli', ['args' => ['db', 'drop']], 0)['refused'] !== '';
$before += $GLOBALS['executions'] - $executions_before_wp_cli;
// Money that leaves cannot be brought back: a refund or a payment asks a person even at the level that asks least.
$settings_before_money = get_option(C\SETTINGS_OPTION);
C\save_settings(new WP_REST_Request(['level' => 'almost_none']));
close_open_requests();
$refund = "wc_create_refund(['order_id' => 1, 'amount' => 5]);";
$out['money'] = [
    'refund_rule' => C\analyze_code($refund)['rules'],
    'payment_rule' => C\analyze_code('$order->payment_complete();')['rules'],
    'status_rule' => C\analyze_code("\$order->update_status('completed');")['rules'],
    'cli_refund' => C\wp_cli_rules(['wc', 'shop_order_refund', 'create', '1', '--amount=5']),
    'cli_list' => C\wp_cli_rules(['wc', 'shop_order', 'list']),
    'mcp_waits' => $handler->call_tool(['name' => 'mcp-adapter-execute-ability', 'arguments' => [
        'ability_name' => 'novamira/execute-php',
        'parameters' => ['code' => $refund],
        'review' => ['attention' => 5, 'summary' => 'Refunds five.'],
    ]])->toArray()['structuredContent']['state'] ?? '',
];
close_open_requests();
$out['money']['rest_waits'] = C\gate_rest_run('novamira/execute-php', ['code' => $refund], ['attention' => 5, 'summary' => 'Refunds five.'])?->get_error_code();
close_open_requests();
$out['money']['rest_plain_runs'] = C\gate_rest_run('novamira/execute-php', ['code' => 'echo 6;'], ['attention' => 5, 'summary' => 'Says six.']);
$out['money']['chat_asks'] = C\chat_call_check('novamira/execute-php', ['code' => $refund], 0)['must_ask'];
close_open_requests();
// A connector that read the tool list while approvals were off drops review: it can come inside parameters.
$inside = $handler->call_tool(['name' => 'mcp-adapter-execute-ability', 'arguments' => [
    'ability_name' => 'novamira/execute-php',
    'parameters' => ['code' => $refund, 'novamira_review' => ['attention' => 5, 'summary' => 'Refunds five.']],
]])->toArray()['structuredContent'] ?? [];
$out['review_inside'] = [
    'state' => $inside['state'] ?? '',
    'summary' => $inside['summary'] ?? '',
    'stored_clean' => !array_key_exists(
        'novamira_review',
        (array) (C\get_request((string) ($inside['request_id'] ?? ''))['arguments']['parameters'] ?? []),
    ),
    'stripped' => C\strip_review(['ability_name' => 'x', 'parameters' => ['a' => 1, 'novamira_review' => []], 'review' => []]),
    'outside_wins' => C\lift_review([
        'parameters' => ['novamira_review' => ['summary' => 'inside']],
        'review' => ['summary' => 'outside'],
    ])['review']['summary'],
];
close_open_requests();
$rest_request = new WP_REST_Request(
    method: 'POST',
    body: (string) json_encode(['input' => ['code' => 'echo 1;', 'novamira_review' => ['attention' => 1, 'summary' => 'Says one.']]]),
    route: '/novamira/v1/abilities/novamira/execute-php/run',
);
C\lift_rest_review(null, new WP_REST_Server(), $rest_request);
$out['review_inside']['rest'] = $rest_request->get_json_params();
$only_review = new WP_REST_Request(
    method: 'POST',
    body: (string) json_encode(['input' => ['novamira_review' => ['summary' => 'Nothing else.']]]),
    route: '/novamira/v1/abilities/acme/thing/run',
);
C\lift_rest_review(null, new WP_REST_Server(), $only_review);
$out['review_inside']['rest_empty_input'] = $only_review->get_json_params()['input'];
$with_request = new WP_REST_Request(
    method: 'POST',
    body: (string) json_encode(['input' => ['a' => 1, 'novamira_review' => ['summary' => 'Says it.', 'request_id' => 'req-42']]]),
    route: '/novamira/v1/abilities/acme/thing/run',
);
C\lift_rest_review(null, new WP_REST_Server(), $with_request);
$out['review_inside']['rest_request_id'] = $with_request->get_json_params()['request_id'] ?? null;
update_option(C\SETTINGS_OPTION, $settings_before_money);

// Whether the AI could undo a call from the history: database changes yes, files, money and sent emails no.
$settings_before_undo = get_option(C\SETTINGS_OPTION);
update_option(C\SETTINGS_OPTION, array_merge((array) $settings_before_undo, ['hold_emails' => true]));
$out['undo'] = [
    'option' => C\undo_review(['medium:option']),
    'option_in_loop' => C\undo_review(['high:option_in_loop']),
    'refund' => C\undo_review(C\analyze_code($refund)['rules']),
    'file' => C\undo_review(['high:deletes_file', 'medium:option']),
    'site' => C\undo_review(C\analyze_code('wpmu_delete_blog(2, true);')['rules']),
    'cli_site' => C\wp_cli_rules(['site', 'delete', '2', '--yes']),
    'site_update' => C\undo_review(C\analyze_code("update_blog_status(2, 'archived', 1);")['rules']),
    'unknown' => C\undo_review(['medium:option', 'high:dynamic_call']),
    'nothing' => C\undo_review([]),
    'held_email' => C\undo_review(['medium:email']),
    'card' => C\with_what_was_found(['attention' => 9], ['score' => 9, 'rules' => ['money:refund'], 'reject' => false])['undo'] ?? null,
    'record' => C\public_record([
        'id' => 'x',
        'state' => 'pending_confirmation',
        'review' => ['attention' => 9, 'undo' => 'history'],
        'created' => 1,
        'tool' => 'mcp-adapter-execute-ability',
        'arguments' => [],
        'user_id' => 1,
    ])['undo'],
    'names' => array_intersect_key(C\public_record([
        'id' => 'x',
        'state' => 'completed',
        'review' => ['attention' => 9],
        'created' => 1,
        'tool' => 'mcp-adapter-execute-ability',
        'arguments' => [],
        'user_id' => get_current_user_id(),
        'decided_by' => get_current_user_id() + 1,
    ]), array_flip(['asked_by', 'asked_by_you', 'decided_by', 'decided_by_you'])),
];
// Which connection asked: the name the program sent when it opened its MCP session.
$GLOBALS['user_meta'][1]['novamira_mcp_adapter_sessions']['session-named'] = [
    'last_activity' => time(),
    'client_params' => ['clientInfo' => ['name' => 'cursor-vscode', 'title' => 'Cursor']],
];
$GLOBALS['user_meta'][1]['novamira_mcp_adapter_sessions']['session-plain'] = [
    'last_activity' => time(),
    'client_params' => ['clientInfo' => ['name' => 'claude-ai']],
];
$out['undo']['connection'] = [
    'title' => C\connection_name('session-named'),
    'name' => C\connection_name('session-plain'),
    'none' => C\connection_name('rest'),
    'waiting' => C\waiting_text([
        'id' => str_repeat('b', 32),
        'user_id' => 1,
        'created' => time(),
        'review' => ['attention' => 9],
        'connection' => 'Cursor',
    ]),
];
unset(
    $GLOBALS['user_meta'][1]['novamira_mcp_adapter_sessions']['session-named'],
    $GLOBALS['user_meta'][1]['novamira_mcp_adapter_sessions']['session-plain'],
);
update_option(C\SETTINGS_OPTION, array_merge((array) $settings_before_undo, ['hold_emails' => false]));
$out['undo']['sent_email'] = C\undo_review(['medium:email']);
update_option(C\SETTINGS_OPTION, $settings_before_undo);
$out['analysis_other_ability'] = C\analyze_call(['ability_name' => 'novamira/read-file', 'parameters' => ['path' => 'x']]);
foreach ([
    'read' => 'return get_option("blogname");',
    'interpolated_delete' => 'global $wpdb; $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_title LIKE \'%test%\'");',
    'concat_delete' => 'global $wpdb; $wpdb->query("DELETE FROM " . $wpdb->posts . " WHERE ID = 5");',
    'loop_delete' => 'foreach (get_posts(["posts_per_page" => -1]) as $p) { wp_delete_post($p->ID, true); }',
    'single_delete' => 'wp_delete_post(12);',
    'callback_by_name' => 'array_map("unlink", $files);',
    'include' => 'include "/tmp/x.php";',
    'require_wordpress_admin' => 'require_once ABSPATH . "wp-admin/includes/plugin.php";',
    'require_wpinc' => 'require_once ABSPATH . WPINC . "/pluggable.php";',
    'require_climbs_out' => 'require_once ABSPATH . "wp-includes/../../x.php";',
    'require_twice_fatal' => 'require ABSPATH . "wp-admin/includes/plugin.php";',
    'require_wp_load' => 'require_once ABSPATH . "wp-load.php";',
    'require_acts_on_load' => 'require_once ABSPATH . WPINC . "/ms-files.php";',
    'require_subfolder' => 'require_once ABSPATH . "wp-includes/rest-api/x.php";',
    'parse_error' => 'this is not php',
    'truncate' => 'global $wpdb; $wpdb->query("TRUNCATE TABLE wp_posts");',
    'role' => '$u = get_user_by("id", 2); $u->set_role("administrator");',
] as $case => $code) {
    $out['analysis_cases'][$case] = C\analyze_code($code)['score'];
}

// Each ability gets its own treatment: no score for reads, always for enable-file and admin links,
// fixed checks for delete-file, run-wp-cli and PHP files.
@mkdir($GLOBALS['test_root'] . '/wp-content/novamira-sandbox', 0777, true);
@mkdir(WP_PLUGIN_DIR . '/demo', 0777, true);
file_put_contents($GLOBALS['test_root'] . '/wp-content/novamira-sandbox/feature.php', "<?php\nupdate_option('active_plugins', []);\n");
// Disabled files: enable-file has something to switch on, so it asks.
touch($GLOBALS['test_root'] . '/wp-content/novamira-sandbox/.feature.php.disabled');
touch($GLOBALS['test_root'] . '/wp-content/novamira-sandbox/.changes.php.disabled');
file_put_contents($GLOBALS['test_root'] . '/wp-content/novamira-sandbox/running.php', "<?php\necho 'ok';\n");
$GLOBALS['user_meta'][1]['novamira_mcp_adapter_sessions']['session-policy'] = ['last_activity' => time()];
C\session_id(new WP_REST_Request([], ['Mcp-Session-Id' => 'session-policy']));
close_open_requests();
$ability = static fn(string $name, array $parameters, ?array $review = null): array => $handler->call_tool(['name' => 'mcp-adapter-execute-ability', 'arguments' => array_filter([
    'ability_name' => $name, 'parameters' => $parameters, 'review' => ($review ?? []) + ['summary' => 'Says what it does.', 'attention' => 0],
], static fn($v): bool => $v !== null)])->toArray();
$GLOBALS['transients']['novamira_confirmation_heartbeat'] = null;
$before = $GLOBALS['executions'];
$out['policy_read_runs_without_score_offline'] = ($ability('novamira/read-file', ['path' => 'x.txt'])['isError'] ?? false) === false
    && $GLOBALS['executions'] === $before + 1;
C\record_heartbeat(1);
$out['policy_enable_always'] = $ability('novamira/enable-file', ['path' => 'wp-content/novamira-sandbox/feature.php'])['structuredContent'];
$before = $GLOBALS['executions'];
$running = $ability('novamira/enable-file', ['path' => 'wp-content/novamira-sandbox/running.php']);
$out['enable_running_does_not_ask'] = ($running['structuredContent']['state'] ?? null) !== 'pending_confirmation'
    && ($running['isError'] ?? false) === false
    && $GLOBALS['executions'] === $before + 1;
$out['enable_running_chat_does_not_ask'] = !C\chat_call_check('novamira/enable-file', ['path' => 'wp-content/novamira-sandbox/running.php'])['must_ask'];
$out['permalinks'] = [
    C\analyze_code("update_option('permalink_structure', '/%postname%/');")['score'],
    C\analyze_code("global \$wp_rewrite; \$wp_rewrite->set_permalink_structure('/%postname%/');")['score'],
    C\wp_cli_rules(['rewrite', 'structure', '/%postname%/']),
    C\wp_cli_rules(['rewrite', 'flush']),
];
$out['rule_words'] = [C\found_rule_words('parse_error'), C\found_rule_words('high:users_and_roles')];
$out['policy_admin_link_always'] = $ability('novamira/create-admin-access-link', [])['structuredContent'];
// A file that changed after the card was shown is not what the person approved.
$changed_path = $GLOBALS['test_root'] . '/wp-content/novamira-sandbox/changes.php';
foreach (['changed_after_approval' => "<?php\nwp_delete_user(2);\n", 'unchanged_after_approval' => null] as $case => $later) {
    file_put_contents($changed_path, "<?php\necho 'shown on the card';\n");
    $changed_id = $ability('novamira/enable-file', ['path' => 'wp-content/novamira-sandbox/changes.php'])['structuredContent']['request_id'];
    approve($changed_id);
    if ($later !== null) {
        file_put_contents($changed_path, $later);
    }
    $before = $GLOBALS['executions'];
    $changed_poll = poll($handler, $changed_id)['structuredContent'];
    $out[$case] = [
        'state' => $changed_poll['state'],
        'ran' => $GLOBALS['executions'] - $before,
        'says_file_changed' => str_contains((string) json_encode($changed_poll['tool_result'] ?? null), 'file changed'),
    ];
}
$before = $GLOBALS['executions'];
delete_transient(C\HEARTBEAT_TRANSIENT);
$out['policy_unknown_runs_unasked'] = ($ability('other-plugin/do-thing', [])['isError'] ?? false) === false
    && $GLOBALS['executions'] === $before + 1;
C\record_heartbeat(1);
// Each ability can follow the level, always ask or never ask; the ones that run code or touch files never stop asking.
C\set_ability_choice('other-plugin/do-thing', 'yes');
$out['forced_other_ability_asks'] = ($ability('other-plugin/do-thing', [])['structuredContent']['state'] ?? null) === 'pending_confirmation';
$out['forced_chat_asks'] = C\chat_call_check('other-plugin/do-thing', [], 0);
$out['choices'] = [
    'no_for_read' => C\set_ability_choice('novamira/read-file', 'no'),
    'read_policy' => C\ability_policy('novamira/read-file')['confirm'],
    'no_for_php' => C\set_ability_choice('novamira/execute-php', 'no'),
    'php_policy' => C\ability_policy('novamira/execute-php')['confirm'],
    'yes_for_php' => C\set_ability_choice('novamira/execute-php', 'yes'),
    'php_now' => C\ability_policy('novamira/execute-php')['confirm'],
    'admin_link' => [C\allowed_choices('novamira/create-admin-access-link'), C\ability_choice('novamira/create-admin-access-link')],
    'enable_file' => C\allowed_choices('novamira/enable-file'),
];
// An ability registered as read-only runs at once, another plugin's included, unless it is set to always ask.
$GLOBALS['abilities']['acme/list-tickets'] = new WP_Ability('acme/list-tickets', meta: ['annotations' => ['readonly' => true]]);
$GLOBALS['abilities']['acme/close-ticket'] = new WP_Ability('acme/close-ticket', meta: ['annotations' => ['readonly' => false]]);
$out['readonly'] = [
    'read' => C\ability_policy('acme/list-tickets')['confirm'],
    'write' => C\ability_policy('acme/close-ticket')['confirm'],
    'php_listed' => C\listed_ability_policy('novamira/execute-php')['confirm'],
];
C\set_ability_choice('acme/list-tickets', 'yes');
$out['readonly']['read_always'] = C\ability_policy('acme/list-tickets')['confirm'];
C\set_ability_choice('acme/list-tickets', 'level');
unset($GLOBALS['abilities']['acme/list-tickets'], $GLOBALS['abilities']['acme/close-ticket']);
// MCP's readOnlyHint counts too, and wins over readonly when both are there, as in the MCP Adapter.
$GLOBALS['abilities']['acme/list-forms'] = new WP_Ability('acme/list-forms', meta: ['annotations' => ['readOnlyHint' => true]]);
$GLOBALS['abilities']['acme/edit-form'] = new WP_Ability('acme/edit-form', meta: ['annotations' => ['readOnlyHint' => false]]);
$GLOBALS['abilities']['acme/mixed-form'] = new WP_Ability('acme/mixed-form', meta: [
    'annotations' => ['readonly' => true, 'readOnlyHint' => false],
]);
$out['read_only_hint'] = [
    'read' => C\ability_policy('acme/list-forms')['confirm'],
    'write' => C\ability_policy('acme/edit-form')['confirm'],
    'hint_wins' => C\ability_policy('acme/mixed-form')['confirm'],
];
C\set_ability_choice('acme/list-forms', 'yes');
$out['read_only_hint']['read_always'] = C\ability_policy('acme/list-forms')['confirm'];
C\set_ability_choice('acme/list-forms', 'level');
unset($GLOBALS['abilities']['acme/list-forms'], $GLOBALS['abilities']['acme/edit-form'], $GLOBALS['abilities']['acme/mixed-form']);
C\set_ability_choice('novamira/read-file', 'level');
C\set_ability_choice('novamira/execute-php', 'level');
// Never ask removes the questions, not the history: recording reads the policy without the administrator's choice.
C\set_ability_choice('other-plugin/do-thing', 'no');
$out['never_ask_still_recorded'] = [
    C\ability_policy('other-plugin/do-thing')['confirm'],
    C\listed_ability_policy('other-plugin/do-thing')['confirm'],
];
C\set_ability_choice('other-plugin/do-thing', 'level');
$before = $GLOBALS['executions'];
$out['unforced_runs_again'] = ($ability('other-plugin/do-thing', ['again' => true])['isError'] ?? false) === false
    && $GLOBALS['executions'] === $before + 1;
$out['forced_list_empty'] = C\ability_choices();
$out['ours'] = [C\ability_is_ours('novamira/execute-php'), C\ability_is_ours('novamira-pro/x'), C\ability_is_ours('acme/x')];
// Novamira Pro declares how its abilities ask in their meta; another plugin cannot, and an undeclared one is not rated.
foreach (['never', 'level', 'always'] as $declared) {
    $GLOBALS['abilities']["novamira/declared-$declared"] = new WP_Ability("novamira/declared-$declared", meta: ['novamira_approval' => $declared]);
}
$GLOBALS['abilities']['acme/declared'] = new WP_Ability('acme/declared', meta: ['novamira_approval' => 'always']);
$GLOBALS['abilities']['novamira/undeclared'] = new WP_Ability('novamira/undeclared');
$out['declared_policies'] = array_map(
    static fn(string $name): string => C\ability_policy($name)['confirm'] . (C\ability_policy($name)['score'] ? '+score' : ''),
    ['novamira/declared-never', 'novamira/declared-level', 'novamira/declared-always', 'acme/declared', 'novamira/undeclared'],
);
$out['policy_delete_plugin_asks'] = ($ability('novamira/delete-file', ['path' => 'wp-content/plugins/demo'])['structuredContent']['state'] ?? null) === 'pending_confirmation';
$before = $GLOBALS['executions'];
$out['policy_delete_upload_runs'] = ($ability('novamira/delete-file', ['path' => 'wp-content/uploads/a.jpg'])['isError'] ?? false) === false
    && $GLOBALS['executions'] === $before + 1;
$score = static fn(array $result): int|string => $result['reject'] ? 'refuse' : $result['score'];
$out['delete_scores'] = [
    'plugin_dir' => C\file_delete_check(['path' => 'wp-content/plugins/demo'])['score'],
    'sandbox_file' => C\file_delete_check(['path' => 'wp-content/novamira-sandbox/feature.php'])['score'],
    'sandbox_htaccess' => C\file_delete_check(['path' => 'wp-content/novamira-sandbox/.htaccess'])['score'],
    'sandbox_web_config' => C\file_delete_check(['path' => 'wp-content/novamira-sandbox/web.config'])['score'],
    'upload' => C\file_delete_check(['path' => 'wp-content/uploads/2026/11/a.jpg'])['score'],
    'other_htaccess' => C\file_delete_check(['path' => 'wp-content/x/.htaccess'])['score'],
    'recursive' => C\file_delete_check(['path' => 'wp-content/uploads/2026', 'recursive' => true])['score'],
];
// An upload link asks only when it replaces a file outside the sandbox: Novamira CLI's upload never replaces one.
@mkdir($GLOBALS['test_root'] . '/wp-content/themes/demo', recursive: true);
@mkdir($GLOBALS['test_root'] . '/wp-content/novamira-sandbox', recursive: true);
file_put_contents($GLOBALS['test_root'] . '/wp-content/themes/demo/style.css', 'a{}');
file_put_contents($GLOBALS['test_root'] . '/wp-content/novamira-sandbox/upload-test.txt', 'x');
$out['upload_link_scores'] = [
    'new' => C\upload_link_check(['path' => 'wp-content/uploads/new.zip'])['score'],
    'existing_no_overwrite' => C\upload_link_check(['path' => 'wp-content/themes/demo/style.css'])['score'],
    'replaces' => C\upload_link_check(['path' => 'wp-content/themes/demo/style.css', 'overwrite' => true])['score'],
    'replaces_in_sandbox' => C\upload_link_check(['path' => 'wp-content/novamira-sandbox/upload-test.txt', 'overwrite' => true])['score'],
];
foreach ([
    'list' => ['plugin', 'list', '--format=json'],
    'eval' => ['eval', 'echo 1;'],
    'exec_flag' => ['option', 'get', 'home', '--exec=echo 1;'],
    'package' => ['package', 'install', 'x/y'],
    'plugin_install' => ['plugin', 'install', 'hello-dolly', '--activate'],
    'option_critical' => ['option', 'update', 'active_plugins', '[]'],
    'option_other' => ['option', 'update', 'blogname', 'X'],
    'option_get' => ['option', 'get', 'blogname'],
    'db_query_delete' => ['db', 'query', 'DELETE FROM wp_posts'],
    'db_query_select' => ['db', 'query', 'SELECT 1'],
    'search_replace' => ['search-replace', 'a', 'b'],
    'search_replace_dry' => ['search-replace', 'a', 'b', '--dry-run'],
    'post_delete' => ['post', 'delete', '12'],
    'post_delete_several' => ['post', 'delete', '12', '13'],
    'post_delete_several_in_one' => ['post delete 12 13'],
    'post_delete_force' => ['post', 'delete', '12', '--force'],
    'post_update' => ['post', 'update', '12', '--post_title=x'],
    'user_create' => ['wp', 'user', 'create', 'bob', 'bob@example.test'],
] as $case => $args) {
    $out['wp_cli_scores'][$case] = $score(C\wp_cli_check(['args' => $args]));
}
foreach ([
    'alias_read' => ['@prod', 'plugin', 'list'],
    'user_meta_read' => ['user', 'meta', 'get', '1', 'x'],
    'user_meta_write' => ['user', 'meta', 'update', '1', 'x', 'y'],
    'plugin_install_alias' => ['@prod', 'plugin', 'install', 'x'],
    'db_import' => ['sql', 'import', 'x.sql'],
    'dry_export' => ['search-replace', 'a', 'b', '--dry-run', '--export=x.sql'],
    'no_dry_run' => ['search-replace', 'a', 'b', '--dry-run', '--no-dry-run'],
    'unknown' => ['acme', 'list'],
    'db_export' => ['db', 'export', 'x.sql'],
    'insert' => ['db', 'query', 'INSERT INTO wp_options VALUES (1)'],
    'history_text' => ['db', 'query', "UPDATE wp_posts SET post_content = 'wp_novamira_journal' WHERE ID = 1"],
    'minus_minus' => ['db', 'query', 'SELECT 5--1'],
] as $case => $args) {
    $out['wp_cli_more_scores'][$case] = $score(C\wp_cli_check(['args' => $args]));
}
foreach ([
    'eval' => ['eval', 'echo 1;'],
    'eval_file' => ['eval-file', 'x.php'],
    'shell' => ['shell'],
    'exec' => ['option', 'get', 'home', '--exec=echo 1;'],
    'require' => ['plugin', 'list', '--require=x.php'],
    'package' => ['package', 'install', 'x/y'],
    'db_drop' => ['db', 'drop', '--yes'],
    'db_reset' => ['db', 'reset', '--yes'],
    'db_clean' => ['db', 'clean', '--yes'],
    'history_query' => ['db', 'query', 'DELETE FROM wp_NOVAMIRA_journal'],
    'history_table' => ['search-replace', 'a', 'b', 'wp_novamira_journal'],
    'all_tables' => ['search-replace', 'a', 'b', '--all-tables'],
    'all_with_prefix' => ['search-replace', 'a', 'b', '--all-tables-with-prefix'],
    'wildcard' => ['search-replace', 'a', 'b', 'wp_*'],
    'alias_eval' => ['@prod', 'eval', 'echo 1;'],
    'alias_drop' => ['@prod', 'db', 'drop', '--yes'],
    'wp_alias_drop' => ['wp', '@prod', 'db', 'drop'],
    'sql_drop' => ['sql', 'drop', '--yes'],
    'one_string' => ['eval phpinfo();'],
    'drop_database' => ['db', 'query', 'DROP DATABASE wp'],
    'run_comment' => ['db', 'query', '/*!50000 DELETE FROM wp_novamira_journal */'],
    'init_command' => ['db', 'query', 'SELECT 1', '--init-command=DELETE FROM wp_novamira_journal'],
] as $case => $args) {
    $out['wp_cli_forbidden'][$case] = C\wp_cli_forbidden($args) !== '';
}
foreach ([['plugin', 'list'], ['search-replace', 'a', 'b'], ['db', 'import', 'x.sql'], ['db', 'query', 'SELECT 1'], ['post', 'update', '1', '--post_content=cita wp_novamira_journal']] as $args) {
    $out['wp_cli_allowed'][] = C\wp_cli_forbidden($args) === '';
}
$out['wp_cli_scored'] = in_array('novamira/run-wp-cli', C\abilities_with_score(), strict: true);
$feature = 'wp-content/novamira-sandbox/feature.php';
$out['write_scores'] = [
    'php_file' => C\file_write_check(['path' => 'wp-content/novamira-sandbox/new.php', 'content' => "<?php\nwp_delete_user(2);\n"])['score'],
    'php_append' => C\file_write_check(['path' => $feature, 'content' => "\n// harmless", 'mode' => 'append'])['score'],
    'php_edit' => C\file_write_check(['path' => $feature, 'old_string' => "'active_plugins'", 'new_string' => "'siteurl'"])['score'],
    'css_outside' => C\file_write_check(['path' => 'wp-content/themes/x/style.css', 'content' => 'a{}'])['score'],
    'css_sandbox' => C\file_write_check(['path' => 'wp-content/novamira-sandbox/style.css', 'content' => 'a{}'])['score'],
];
// A retry with the same call_id returns the first result instead of running the code again.
$GLOBALS['user_meta'][1]['novamira_mcp_adapter_sessions']['session-retry'] = ['last_activity' => time()];
C\session_id(new WP_REST_Request([], ['Mcp-Session-Id' => 'session-retry']));
close_open_requests();
C\save_settings(new WP_REST_Request(['cutoff' => 8]));
C\record_heartbeat(1);
$retry = static fn(string $code, array $review): array => $handler->call_tool(['name' => 'mcp-adapter-execute-ability', 'arguments' => [
    'ability_name' => 'novamira/execute-php', 'parameters' => ['code' => $code], 'review' => $review + ['summary' => 'Says what it does.'],
]])->toArray();
$before = $GLOBALS['executions'];
$first = $retry('echo "retry";', ['attention' => 5, 'call_id' => 'retry-0001']);
$again = $retry('echo "retry";', ['attention' => 7, 'call_id' => 'retry-0001']);
$out['retry_runs_once'] = $GLOBALS['executions'] - $before;
$out['retry_first_error'] = $first['isError'] ?? false;
$out['retry_replayed'] = [$again['structuredContent']['replayed'] ?? null, $again['structuredContent']['state'] ?? null];
$out['retry_same_result'] = ($again['structuredContent']['result'] ?? null) == ($first['structuredContent'] ?? 'x');
$other = $retry('echo "other";', ['attention' => 5, 'call_id' => 'retry-0001']);
$out['retry_other_call_refused'] = [$other['isError'] ?? false, str_contains($other['content'][0]['text'] ?? '', 'different call')];
// The connection drops and the client comes back on a new MCP session: same user, same id, same result.
C\session_id(new WP_REST_Request([], ['Mcp-Session-Id' => 'retry-reconnected']));
$reconnect_before = $GLOBALS['executions'];
$reconnected = $retry('echo "retry";', ['attention' => 5, 'call_id' => 'retry-0001']);
$out['retry_after_reconnect'] = [
    'ran' => $GLOBALS['executions'] - $reconnect_before,
    'replayed' => $reconnected['structuredContent']['replayed'] ?? false,
];
C\session_id(new WP_REST_Request([], ['Mcp-Session-Id' => 'session-retry']));
$out['retry_bad_id'] = $retry('echo "retry";', ['attention' => 5, 'call_id' => 'x'])['isError'] ?? false;
$running_option = C\call_option(C\call_owner($server), 'retry-0002');
$fingerprint = C\call_fingerprint(['ability_name' => 'novamira/execute-php', 'parameters' => ['code' => 'echo 2;'], 'review' => []]);
$GLOBALS['options'][$running_option] = json_encode(['state' => 'running', 'started' => time(), 'args' => $fingerprint]);
$out['retry_still_running'] = $retry('echo 2;', ['attention' => 5, 'call_id' => 'retry-0002'])['structuredContent']['state'] ?? null;
$GLOBALS['options'][$running_option] = json_encode(['state' => 'running', 'started' => time() - 7200, 'args' => $fingerprint]);
$stale = $retry('echo 2;', ['attention' => 5, 'call_id' => 'retry-0002']);
$out['retry_stale_unknown'] = [$stale['isError'] ?? false, str_contains($stale['content'][0]['text'] ?? '', 'outcome is unknown')];
$before = $GLOBALS['executions'];
$retry('echo "no id";', ['attention' => 5]);
$retry('echo "no id";', ['attention' => 5]);
$out['without_id_runs_twice'] = $GLOBALS['executions'] - $before;

// Direct REST runs, the ones Novamira CLI makes: the same rules, the same page.
close_open_requests();
C\save_settings(new WP_REST_Request(['cutoff' => 8]));
C\record_heartbeat(1);
$GLOBALS['rest_runs'] = 0;
$GLOBALS['abilities']['novamira/execute-php'] = new WP_Ability('novamira/execute-php', static function (mixed $input): array {
    $GLOBALS['rest_runs']++;
    return ['ran' => $input['code'] ?? ''];
});
$rest = static fn(string $code, mixed $review): ?WP_Error => C\gate_rest_run('novamira/execute-php', ['code' => $code], $review);
$out['rest_routes'] = [
    C\run_route_ability('/novamira/v1/abilities/novamira/execute-php/run'),
    C\run_route_ability('/wp-abilities/v1/abilities/novamira/execute-php/run'),
    C\run_route_ability('/novamira/v1/abilities/novamira/execute-php'),
    C\run_route_ability('/wp/v2/posts'),
];
// WordPress matches routes ignoring case: the rules must apply however the address is written.
$out['rest_routes_any_case'] = [
    C\run_route_ability('/novamira/V1/abilities/novamira/execute-php/run'),
    C\run_route_ability('/NOVAMIRA/v1/ABILITIES/novamira/execute-php/RUN'),
    C\run_route_ability('/WP-Abilities/V1/abilities/novamira/execute-php/run'),
    C\run_route_ability('/novamira/v1/abilities/NOVAMIRA/execute-php/run'),
];
// The check reads the input from where the handler does.
$fields_body = '{"input":{"code":"from the body"},"review":{"summary":"body"}}';
$out['rest_fields'] = [
    C\run_route_fields(new WP_REST_Request(method: 'GET', query: ['input' => ['code' => 'from the query']], body: $fields_body))['input']['code'],
    C\run_route_fields(new WP_REST_Request(method: 'DELETE', query: ['input' => ['code' => 'from the query']], body: $fields_body))['input']['code'],
    C\run_route_fields(new WP_REST_Request(method: 'POST', query: ['input' => ['code' => 'from the query']], body: $fields_body))['input']['code'],
    C\run_route_fields(new WP_REST_Request(method: 'POST', query: ['input' => ['code' => 'from the query']], body: 'not json')),
];
$no_review = $rest('echo 1;', null);
$out['rest_no_review'] = [$no_review?->get_error_code(), str_contains((string) $no_review?->get_error_message(), '"novamira_review"') && str_contains((string) $no_review?->get_error_message(), 'Novamira CLI')];
// Clients signed in with Novamira's OAuth get a call that did not run as a result, since apps built on Novamira CLI
// replace errors with their own text.
eval('namespace Novamira\OAuth\Middleware; function request_oauth_identity(): ?array { return $GLOBALS["oauth_identity"] ?? null; }');
$out['rest_error_without_oauth'] = C\answers_as_result();
$GLOBALS['oauth_identity'] = ['user_id' => 1, 'scopes' => ['full_access']];
$out['rest_result_with_oauth'] = C\answers_as_result();
unset($GLOBALS['oauth_identity']);
$as_result = C\stopped_result(new WP_Error('novamira_confirmation_pending', 'Waiting. Nothing has executed.', ['status' => 409, 'request_id' => 'abc']));
$out['rest_stopped_result'] = $as_result->data;
// Novamira CLI's upload builds its call itself: abilities a fixed rule decides get Novamira's summary when none came.
$summary = static fn(string $ability, array $input): ?string => C\fixed_rule_summary(['ability_name' => $ability, 'parameters' => $input]);
$out['fixed_rule_summaries'] = [
    $summary('novamira/create-upload-link', ['path' => 'wp-content/upgrade/x.zip']),
    $summary('novamira/delete-file', ['path' => 'wp-content/old', 'recursive' => true]),
    $summary('novamira/enable-file', ['path' => 'tool.php']),
    $summary('novamira/create-admin-access-link', []),
    $summary('novamira/delete-file', []),
    $summary('novamira/execute-php', ['path' => 'x']),
];
$upload_review = C\call_review(['ability_name' => 'novamira/create-upload-link', 'parameters' => ['path' => 'a.zip']], C\ability_policy('novamira/create-upload-link'), 8);
$out['fixed_rule_review'] = is_array($upload_review) ? $upload_review['summary'] : $upload_review->get_error_code();
$ai_review = C\call_review(['ability_name' => 'novamira/create-upload-link', 'parameters' => ['path' => 'a.zip'], 'review' => ['summary' => 'Uploads the backup.']], C\ability_policy('novamira/create-upload-link'), 8);
$out['fixed_rule_ai_wins'] = is_array($ai_review) ? $ai_review['summary'] : '';
$out['rest_low_runs'] = $rest('echo 1;', ['attention' => 5, 'summary' => 'Says hello.']);
$missing_details = $rest('echo 1;', ['attention' => 9, 'summary' => 'Says hello.', 'details' => str_repeat('a', 1501)]);
$out['rest_review_problem'] = [$missing_details?->get_error_code(), str_contains((string) $missing_details?->get_error_message(), 'review.details')];
$queued_code = "update_option('active_plugins', []);";
$queued_review = ['attention' => 9, 'summary' => 'Turns the plugins off.', 'details' => 'Every plugin stops until they are turned on again.'];
$queued = $rest($queued_code, $queued_review);
$rest_id = $queued?->get_error_data()['request_id'] ?? '';
$out['rest_says_check_again'] = [
    str_contains((string) $queued?->get_error_message(), C\REST_STATUS_ABILITY . ' without request_id'),
    str_contains((string) $queued?->get_error_message(), 'has been decided'),
];
$out['rest_raised'] = [$queued?->get_error_code(), $queued?->get_error_data()['status'] ?? 0, C\get_request($rest_id)['review']['attention'] ?? 0];
$out['rest_nothing_ran'] = $GLOBALS['rest_runs'];
$out['rest_same_call_same_request'] = ($rest($queued_code, array_merge($queued_review, ['attention' => 10]))?->get_error_data()['request_id'] ?? '') === $rest_id;
$out['rest_lists_open'] = array_column(C\rest_status()['open_requests'], 'request_id') === [$rest_id];
$out['rest_pending'] = [C\rest_status(['request_id' => $rest_id])['state'], $GLOBALS['rest_runs']];
approve($rest_id);
$approved = C\rest_status(['request_id' => $rest_id]);
$out['rest_approved'] = [$approved['state'], $GLOBALS['rest_runs'], $approved['tool_result']['structuredContent']['ran'] ?? ''];
$out['rest_runs_once'] = [C\rest_status(['request_id' => $rest_id])['state'], $GLOBALS['rest_runs']];
$user = $GLOBALS['user_id'];
$GLOBALS['user_id'] = 2;
$out['rest_other_user'] = C\rest_status(['request_id' => $rest_id]) instanceof WP_Error;
$GLOBALS['user_id'] = $user;
$out['rest_refusal_text'] = C\rest_call_text(C\error_response('Refused for a reason.'));
$out['rest_read_passes'] = C\gate_rest_run('novamira/read-file', ['path' => 'x'], null);
// Another plugin's ability is not rated and runs at once, but says what it changes, for the history.
$out['rest_other_plugin_needs_summary'] = C\gate_rest_run('acme/refund-order', [], null)?->get_error_code();
$out['rest_other_plugin_passes'] = C\gate_rest_run('acme/refund-order', [], ['summary' => 'Refunds order 12 in Acme.', 'attention' => 3]);
$GLOBALS['transients']['novamira_confirmation_heartbeat'] = null;
$out['rest_closed_page_low_runs'] = $rest('echo 1;', ['attention' => 5, 'summary' => 'Says hello.']);
$closed = $rest('echo "closed";', ['attention' => 9, 'summary' => 'Says closed.', 'details' => 'Only prints a word.']);
$closed_id = $closed?->get_error_data()['request_id'] ?? '';
$out['rest_closed_page_waits'] = $closed?->get_error_code();
approve($closed_id);
$out['rest_closed_page_runs_when_approved'] = C\rest_status(['request_id' => $closed_id])['state'];
C\record_heartbeat(1);
eval('namespace Novamira\\Journal; function run_direct_call(string $ability, array $arguments, string $message_id, callable $run): mixed { $GLOBALS["journal_codes"][] = $message_id; return $run(); }');
$GLOBALS['journal_codes'] = [];
$coded = C\gate_rest_run('novamira/execute-php', ['code' => 'echo "coded";'], ['attention' => 9, 'summary' => 'Says coded.', 'details' => 'Only prints a word.'], 'req-cli');
$coded_id = $coded?->get_error_data()['request_id'] ?? '';
approve($coded_id);
C\rest_status(['request_id' => $coded_id]);
$out['rest_approved_keeps_code'] = $GLOBALS['journal_codes'];
$GLOBALS['read_mode'] = true;
$out['rest_read_mode_passes'] = $rest('echo 1;', null);
$GLOBALS['read_mode'] = false;
C\save_settings(new WP_REST_Request(['cutoff' => 8]));

// Skipping approvals: no score and no request, while the work is still recorded and emails follow their setting.
close_open_requests();
C\save_settings(new WP_REST_Request(['cutoff' => 8]));
C\record_heartbeat(1);
$pending_skip = call($handler, $high)['structuredContent']['request_id'] ?? '';
C\save_settings(new WP_REST_Request(['cutoff' => 8, 'skip_approvals' => true]));
$out['skip_saved'] = [C\settings()['skip_approvals'], C\active(), C\covers_work()];
$out['skip_cancels_pending'] = C\refresh_record(C\get_request($pending_skip))['state'];
$skip_before = $GLOBALS['executions'];
$out['skip_mcp_no_review'] = call($handler)['isError'] ?? false;
$out['skip_mcp_ran'] = $GLOBALS['executions'] - $skip_before;
$out['skip_tool_has_no_review'] = !array_key_exists('review', array_column($handler->list_tools()->toArray()['tools'], null, 'name')['mcp-adapter-execute-ability']['inputSchema']['properties']);
$out['skip_rest_no_review'] = C\gate_rest_run('novamira/execute-php', ['code' => 'echo 1;'], null);
$out['skip_always_asks_no_more'] = C\gate_rest_run('novamira/create-admin-access-link', [], null);
$out['skip_keeps_threshold'] = C\settings()['cutoff'];
C\save_settings(new WP_REST_Request(['cutoff' => 8]));
$out['skip_stays_when_not_sent'] = C\settings()['skip_approvals'];
C\save_settings(new WP_REST_Request(['cutoff' => 8, 'skip_approvals' => false]));
$out['skip_off_asks_again'] = call($handler)['isError'] ?? false;

// The AI Activity page: what waits for a person, and where the link goes.
close_open_requests();
$out['waiting_none'] = C\waiting_count();
$waiting = call($handler, $high)['structuredContent'];
$out['waiting_one'] = C\waiting_count();
$out['waiting_link'] = str_ends_with($waiting['dashboard_url'] ?? '', '&request=' . ($waiting['request_id'] ?? 'x'));
$bar = C\admin_bar_waiting();
$out['bar_waiting'] = [$bar['count'], array_column($bar['items'], 'title'), str_ends_with($bar['items'][0]['href'] ?? '', '&request=' . ($waiting['request_id'] ?? 'x'))];
approve($waiting['request_id']);
$out['waiting_decided'] = C\waiting_count();
$out['bar_decided'] = C\admin_bar_waiting();
close_open_requests();
// The Novamira entry itself shows the count: its submenu is closed on most admin pages.
$out['top_menu_badge'] = array_column(C\menu_with_badge(
    [5 => ['Posts', 'edit_posts', 'edit.php'], 3 => ['Novamira', 'manage', 'novamira-connect'], 4 => ['', 'read', 'separator1', '', 'wp-menu-separator']],
    ' <b>3</b>',
), 0);
// Requests saved before they belonged to the user can no longer be polled: they are canceled, once.
$old_request = call($handler, $high)['structuredContent']['request_id'] ?? '';
$old_settings = C\settings();
unset($old_settings['skip_approvals']);
$GLOBALS['options'][C\SETTINGS_OPTION] = $old_settings + ['enabled' => true];
C\cancel_requests_of_old_settings();
$new_generation = C\settings()['generation'];
$out['old_requests'] = [
    'canceled' => C\refresh_record(C\get_request($old_request))['state'],
    'generation_changed' => $new_generation !== $old_settings['generation'],
    'threshold_kept' => C\settings()['cutoff'] === $old_settings['cutoff'],
];
C\cancel_requests_of_old_settings();
$out['old_requests']['only_once'] = C\settings()['generation'] === $new_generation;
$_GET['tab'] = 'history';
$out['tab_history'] = C\current_tab();
$_GET['tab'] = 'nonsense';
$out['tab_unknown'] = C\current_tab();
unset($_GET['tab']);
$out['tab_default'] = C\current_tab();

$out['known_writes'] = C\known_writes(<<<'CODE'
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_title LIKE '%test%'");
$wpdb->query("UPDATE " . $wpdb->options . " SET option_value = 'x' WHERE option_name = 'a'");
$wpdb->query($sql);
$wpdb->query($wpdb->prepare("DELETE FROM x WHERE id = %d", 1));
$wpdb->query("SELECT 1");
CODE);

// One decision at a time per conversation: while one request waits, the conversation's later calls join it.
close_open_requests();
$GLOBALS['one_at_a_time'] = true;
$asks = static fn(string $code, int $attention = 9): array => $handler->call_tool(['name' => 'mcp-adapter-execute-ability', 'arguments' => [
    'ability_name' => 'novamira/execute-php',
    'parameters' => ['code' => $code],
    'review' => $attention >= 8
        ? ['attention' => $attention, 'summary' => 'Step ' . $code, 'details' => 'One step of the work.']
        : ['attention' => $attention, 'summary' => 'Small step ' . $code],
]])->toArray();
$first_step = $asks('echo 1;')['structuredContent'] ?? [];
$before_one = $GLOBALS['executions'];
$second_step = $asks('echo 2;');
$small_step = $asks('echo 3;', 5);
$out['one_at_a_time'] = [
    'first_waits' => $first_step['state'] ?? '',
    'single_payload' => [$first_step['block_id'] === $first_step['request_id'], $first_step['block_position'], $first_step['block_size'], array_key_exists('block', $first_step)],
    'second_joined' => [($second_step['isError'] ?? false), $second_step['structuredContent']['state'] ?? '', ($second_step['structuredContent']['block_id'] ?? '') === $first_step['request_id'], $second_step['structuredContent']['block_position'] ?? null, $second_step['structuredContent']['joined_small'] ?? null],
    'small_joined' => [$small_step['structuredContent']['state'] ?? '', $small_step['structuredContent']['block_position'] ?? null, $small_step['structuredContent']['joined_small'] ?? null, $small_step['structuredContent']['block']['size'] ?? null],
    'nothing_ran' => $GLOBALS['executions'] === $before_one,
    'one_decision' => [count(C\waiting()['requests']), count(C\active_requests())],
    'same_call_same_request' => ($asks('echo 1;')['structuredContent']['request_id'] ?? '') === ($first_step['request_id'] ?? 'x'),
    'resent_member_same_request' => ($asks('echo 2;')['structuredContent']['request_id'] ?? '') === ($second_step['structuredContent']['request_id'] ?? 'x'),
    'read_passes' => C\gate_call(null, ['name' => 'mcp-adapter-execute-ability', 'arguments' => ['ability_name' => 'novamira/read-file', 'parameters' => ['path' => 'x']]], $server) === null,
    'other_plugin_joins' => (C\gate_call(null, ['name' => 'mcp-adapter-execute-ability', 'arguments' => ['ability_name' => 'acme/refund-order', 'parameters' => [], 'review' => ['summary' => 'Refunds order 12 in Acme.', 'attention' => 3, 'call_id' => 'acme-refund-0001']]], $server)?->toArray()['structuredContent']['block_position'] ?? null),
    'rest_own_connection' => C\gate_rest_run('novamira/execute-php', ['code' => 'echo 4;'], ['attention' => 9, 'summary' => 'Four.', 'details' => 'Four.'])?->get_error_code(),
    'chat_not_refused' => C\chat_call_check('novamira/execute-php', ['code' => 'update_option("active_plugins", []);'], 0)['refused'],
];
$cancel_try = $handler->call_tool(['name' => C\STATUS_TOOL, 'arguments' => ['request_id' => $first_step['request_id'], 'cancel' => true]])->toArray();
$out['one_at_a_time']['ai_cannot_cancel'] = ($cancel_try['isError'] ?? false) === true
    && str_contains($cancel_try['content'][0]['text'] ?? '', 'cannot cancel')
    && (C\get_request($first_step['request_id'])['state'] ?? '') === 'pending_confirmation';
$out['one_at_a_time']['no_cancel_in_schema'] = !str_contains(wp_json_encode(array_map(static fn($t) => $t->toArray(), C\decorate_tools([]))), '"cancel"');
$cards = array_column(C\dashboard_page('active', 1)['requests'], null, 'request_id');
$card = $cards[$first_step['request_id']] ?? [];
$out['one_at_a_time']['card'] = [count($cards), array_column($card['actions'] ?? [], 'block_position'), array_column($card['actions'] ?? [], 'joined_small')];
$members = array_column($card['actions'] ?? [], 'request_id');
C\decide(new WP_REST_Request(['id' => $first_step['request_id'], 'decision' => 'reject', 'decision_nonce' => 'browser-nonce', 'members' => $members]));
$out['one_at_a_time']['page_rejects'] = array_values(array_unique(array_map(static fn(string $id): string => C\get_request($id)['state'] ?? '', $members)));
$next_step = $asks('echo 2;')['structuredContent'] ?? [];
$out['one_at_a_time']['next_after_reject'] = [$next_step['state'] ?? '', ($next_step['block_id'] ?? '') === ($next_step['request_id'] ?? 'x')];
$waiting_record = C\get_request($next_step['request_id']);
C\transition($waiting_record, array_merge($waiting_record, ['created' => time() - C\REQUEST_TTL - 1]));
$out['one_at_a_time']['expired_frees'] = ($asks('echo 5;')['structuredContent']['state'] ?? '') === 'pending_confirmation';
close_open_requests();

// Blocks: each conversation decides on its own, calls run in the order they were sent, and only after the one before.
$session = static function (string $id): void {
    $GLOBALS['user_meta'][1]['novamira_mcp_adapter_sessions'][$id] = ['last_activity' => time()];
    C\session_id(new WP_REST_Request([], ['Mcp-Session-Id' => $id]));
};
$card_of = static fn(string $lead): array => C\card_record(C\get_request($lead));
$decide_block = static function (string $lead, string $decision, ?array $members = null) use ($card_of): mixed {
    $members ??= array_column($card_of($lead)['actions'] ?? [['request_id' => $lead]], 'request_id');
    return C\decide(new WP_REST_Request(['id' => $lead, 'decision' => $decision, 'decision_nonce' => 'browser-nonce', 'members' => $members]));
};
$states = static fn(array $ids): array => array_map(static fn(string $id): string => C\get_request($id)['state'] ?? '', $ids);
$GLOBALS['track_runs'] = true;
$session('block-a');
$lead_a = $asks('echo 10;')['structuredContent']['request_id'];
$session('block-b');
$other_session = $asks('echo 20;')['structuredContent'];
$session('block-a');
$member_a = $asks('echo 11;')['structuredContent']['request_id'];
$small_a = $asks('echo 12;', 1)['structuredContent'];
$out['blocks']['other_session'] = [$other_session['state'], $other_session['block_id'] === $other_session['request_id'], $other_session['block_size']];
$out['blocks']['small_flagged'] = [$small_a['block_id'] === $lead_a, $small_a['joined_small'], $small_a['block_size']];
$GLOBALS['ran'] = [];
$approved = $decide_block($lead_a, 'approve');
$out['blocks']['approved'] = [$approved instanceof WP_REST_Response, $states([$lead_a, $member_a, $small_a['request_id']])];
// Each request polls its own result: polling the last one runs the ones before it first.
$last = poll($handler, $small_a['request_id'])['structuredContent'];
$out['blocks']['ran_in_order'] = array_column($GLOBALS['ran'], 0);
$out['blocks']['one_history_item'] = array_values(array_unique(array_column($GLOBALS['ran'], 1)));
$out['blocks']['own_results'] = [
    $last['state'],
    $last['tool_result'] !== null,
    poll($handler, $lead_a)['structuredContent']['state'],
    poll($handler, $member_a)['structuredContent']['tool_result'] !== null,
    $last['block'],
];
$out['blocks']['history_group'] = [C\history_group_of(C\get_request($member_a)), C\history_group_of(C\get_request($other_session['request_id']))];
// A failure halfway: the ones after it do not run, and say why.
$lead_f = $asks('echo 30;')['structuredContent']['request_id'];
$fail_f = $asks('fail;')['structuredContent']['request_id'];
$after_f = $asks('echo 31;')['structuredContent']['request_id'];
$decide_block($lead_f, 'approve');
$GLOBALS['ran'] = [];
$GLOBALS['executing_approved'] = true;
$after = poll($handler, $after_f)['structuredContent'];
unset($GLOBALS['executing_approved']);
$out['blocks']['failure'] = [
    array_column($GLOBALS['ran'], 0),
    $states([$lead_f, $fail_f, $after_f]),
    $after['block'],
    str_contains((string) $after['next_step'], 'did not complete'),
    str_contains(wp_json_encode($after['tool_result']), 'failed, so this one did not run'),
];
// A call that joins after the card was drawn: the decision stops and the card is drawn again.
$lead_c = $asks('echo 40;')['structuredContent']['request_id'];
$shown = array_column($card_of($lead_c)['actions'] ?? [['request_id' => $lead_c]], 'request_id');
$late = $asks('echo 41;')['structuredContent']['request_id'];
$changed = $decide_block($lead_c, 'approve', $shown);
$joins_after = $asks('echo 42;')['structuredContent'];
$out['blocks']['card_changed'] = [
    $changed instanceof WP_Error ? $changed->get_error_code() : '',
    $states([$lead_c, $late]),
    $joins_after['block_id'] === $lead_c,
];
// A call that takes its place while the person decides withdraws, and is told to send again.
$GLOBALS['options'][C\BLOCK_PREFIX . $lead_c] = 'decided';
$racing = $asks('echo 43;');
$GLOBALS['options'][C\BLOCK_PREFIX . $lead_c] = 'open';
$out['blocks']['racing'] = [
    ($racing['isError'] ?? false) === true,
    str_contains($racing['content'][0]['text'] ?? '', 'send this call again'),
    count(C\block_ids($lead_c)),
];
// Up to ten together: the eleventh is refused.
for ($i = 44; $i < 51; $i++) {
    $asks("echo {$i};");
}
$eleventh = $asks('echo 51;');
$out['blocks']['eleventh'] = [count(C\block_ids($lead_c)), ($eleventh['isError'] ?? false) === true, str_contains($eleventh['content'][0]['text'] ?? '', '10 actions')];
$decide_block($lead_c, 'reject');
// A chain cut after its first call goes on from a later status call; a call waits while the one before it runs.
$lead_d = $asks('echo 60;')['structuredContent']['request_id'];
$member_d = $asks('echo 61;')['structuredContent']['request_id'];
$last_d = $asks('echo 62;')['structuredContent']['request_id'];
$decide_block($lead_d, 'approve');
$GLOBALS['ran'] = [];
poll($handler, $lead_d);
$cut = $states([$lead_d, $member_d, $last_d]);
$running = C\get_request($member_d);
C\transition($running, array_merge($running, ['state' => 'executing', 'started' => time()]));
$out['blocks']['waits_for_earlier'] = C\wait_payload(C\get_request($last_d))['waiting'];
C\transition(C\get_request($member_d), $running);
$running_reply = $asks('echo 63;');
poll($handler, $last_d);
$out['blocks']['cut_then_driven'] = [$cut, array_column($GLOBALS['ran'], 0), $states([$lead_d, $member_d, $last_d])];
$out['blocks']['running_refuses'] = str_contains($running_reply['content'][0]['text'] ?? '', 'are running');
// Each step must start within the approval time of the one before it: past it, the rest do not run.
$lead_e = $asks('echo 70;')['structuredContent']['request_id'];
$member_e = $asks('echo 71;')['structuredContent']['request_id'];
$last_e = $asks('echo 72;')['structuredContent']['request_id'];
$decide_block($lead_e, 'approve');
poll($handler, $lead_e);
$done_e = C\get_request($lead_e);
C\transition($done_e, array_merge($done_e, ['finished' => time() - C\APPROVAL_TTL - 1]));
$GLOBALS['ran'] = [];
$late_e = poll($handler, $last_e)['structuredContent'];
$out['blocks']['step_deadline'] = [$states([$lead_e, $member_e, $last_e]), $GLOBALS['ran'], $late_e['block']['not_run'] ?? null];
// Over REST, each credential is its own conversation.
$GLOBALS['app_password'] = 'connection-a';
$rest_a = C\gate_rest_run('novamira/execute-php', ['code' => 'echo 80;'], ['attention' => 9, 'summary' => 'Eighty.'])->get_error_data();
$GLOBALS['app_password'] = 'connection-b';
$rest_b = C\gate_rest_run('novamira/execute-php', ['code' => 'echo 81;'], ['attention' => 9, 'summary' => 'Eighty-one.'])->get_error_data();
$GLOBALS['app_password'] = 'connection-a';
$rest_small = C\gate_rest_run('novamira/execute-php', ['code' => 'echo 82;'], ['attention' => 1, 'summary' => 'Eighty-two.']);
$out['blocks']['rest_scope'] = [
    $rest_a['request_id'] !== $rest_b['request_id'],
    C\get_request($rest_b['request_id'])['block'] === $rest_b['request_id'],
    (C\get_request($rest_small?->get_error_data()['request_id'] ?? '')['block'] ?? '') === $rest_a['request_id'],
];
unset($GLOBALS['app_password']);
// An ability that reports a failure fails, and a command left running in the background stops the rest of its block.
$lead_s = $asks('echo 90;')['structuredContent']['request_id'];
$soft_s = $asks('soft-fail;')['structuredContent']['request_id'];
$after_s = $asks('echo 91;')['structuredContent']['request_id'];
$decide_block($lead_s, 'approve');
poll($handler, $after_s);
$lead_g = $asks('echo 92;')['structuredContent']['request_id'];
$bg_g = $asks('background;')['structuredContent']['request_id'];
$after_g = $asks('echo 93;')['structuredContent']['request_id'];
$decide_block($lead_g, 'approve');
poll($handler, $after_g);
$out['blocks']['reported_failure'] = [$states([$lead_s, $soft_s, $after_s]), $states([$lead_g, $bg_g, $after_g])];
// A decision cut halfway leaves its block sealed: after a while a person can decide it again; a fresh seal is a
// decision still running.
$stale = $asks('echo 94;')['structuredContent']['request_id'];
$GLOBALS['options'][C\BLOCK_PREFIX . $stale] = 'decided:' . (time() - 300);
$stale_decided = $decide_block($stale, 'reject');
$fresh = $asks('echo 95;')['structuredContent']['request_id'];
$GLOBALS['options'][C\BLOCK_PREFIX . $fresh] = 'decided:' . time();
$fresh_decided = $decide_block($fresh, 'reject');
$GLOBALS['options'][C\BLOCK_PREFIX . $fresh] = 'open';
$decide_block($fresh, 'reject');
$out['blocks']['stale_seal'] = [
    $stale_decided instanceof WP_Error,
    $states([$stale]),
    $fresh_decided instanceof WP_Error ? $fresh_decided->get_error_code() : '',
];
// A reconnected conversation finds the decision it has waiting: its next calls join it instead of running ahead.
// A session still alive is another conversation.
$session('reconnect-1');
$waiting_r = $asks('echo 96;')['structuredContent']['request_id'];
$session('reconnect-other');
$other_r = $asks('echo 97;')['structuredContent'];
SessionManager::delete_session(1, 'reconnect-1');
$session('reconnect-2');
$joined_r = $asks('echo 98;')['structuredContent'];
$small_r = $asks('echo 99;', 1)['structuredContent'];
$out['blocks']['reconnect'] = [
    $other_r['block_id'] === $other_r['request_id'],
    $joined_r['block_id'] === $waiting_r,
    $small_r['block_id'] ?? '',
    $waiting_r,
];
$decide_block($waiting_r, 'reject');
$decide_block($other_r['request_id'], 'reject');
// Novamira Chat and Novamira Visual check their calls with the same function: a block of another conversation does not hold them.
$out['blocks']['chat_while_waiting'] = C\chat_call_check('novamira/execute-php', ['code' => 'update_option("blogname", "x");'], 9)['refused'];
$GLOBALS['track_runs'] = false;
close_open_requests();
C\cleanup();
$out['blocks']['slots_pruned'] = count(array_filter(array_keys($GLOBALS['options']), static fn(string $name): bool => str_starts_with($name, C\SLOT_PREFIX)));
$GLOBALS['one_at_a_time'] = false;

// Which actions need approval: three levels saved as cutoffs, a number from before kept until a level is chosen.
$out['levels'] = [
    'of' => [C\level_of(5), C\level_of(8), C\level_of(10), C\level_of(7)],
    'waiting_says_any_admin' => str_contains(
        C\waiting_text(['id' => str_repeat('a', 32), 'user_id' => 1, 'created' => time(), 'review' => ['attention' => 9]]),
        'Any administrator',
    ),
];
C\save_settings(new WP_REST_Request(['cutoff' => 7]));
C\save_settings(new WP_REST_Request(['hold_emails' => false]));
$out['levels']['number_kept'] = [C\settings()['cutoff'], C\settings()['level_chosen']];
C\save_settings(new WP_REST_Request(['hold_emails' => true]));
$out['levels']['invalid'] = C\save_settings(new WP_REST_Request(['level' => 'never']))->get_error_code();
C\save_settings(new WP_REST_Request(['level' => 'most']));
$out['levels']['most'] = [C\settings()['cutoff'], C\settings()['level_chosen']];
C\save_settings(new WP_REST_Request(['level' => 'big']));
$out['levels']['big'] = C\settings()['cutoff'];
$asks_before = static function (int $cutoff): string {
    ob_start();
    C\render_asks_before($cutoff);
    return (string) ob_get_clean();
};
$out['levels']['asks_before'] = [
    'big' => $asks_before(8),
    'legacy' => $asks_before(7),
];
$now = time();
update_option(C\SCORE_LOG_OPTION, [
    ['time' => $now, 'ability' => 'novamira/execute-php', 'attention' => 1, 'analysis_score' => 5],
    ['time' => $now, 'ability' => 'novamira/execute-php', 'attention' => 9, 'analysis_score' => 0],
    ['time' => $now, 'ability' => 'novamira/execute-php', 'attention' => 5, 'analysis_score' => 9, 'analysis_reject' => true],
    ['time' => $now, 'ability' => 'novamira/enable-file', 'attention' => 0],
    ['time' => $now, 'ability' => 'novamira/execute-php', 'attention' => 0, 'analysis_score' => 0],
    ['time' => $now - (8 * DAY_IN_SECONDS), 'ability' => 'novamira/execute-php', 'attention' => 10],
]);
$out['levels']['counts'] = C\level_counts();
update_option(C\SCORE_LOG_OPTION, []);
$out['levels']['no_counts'] = C\level_counts();

// Code that cannot be checked says so on its card; the exact code is there for whoever reads it.
$php_policy = C\ability_policy('novamira/execute-php');
$checked = static fn(string $code): array => C\check_code(
    ['ability_name' => 'novamira/execute-php', 'parameters' => ['code' => $code]],
    ['attention' => 5, 'summary' => 'Does a thing.'],
    $php_policy,
);
$unread = $checked('$f = "unl" . "ink"; $f("x");');
$read = $checked('update_option("active_plugins", get_option("active_plugins"));');
$out['code_card'] = [
    'unreadable_flagged' => ($unread['review']['unverified'] ?? false) === true,
    'unreadable_score' => $unread['review']['attention'],
    'readable_not_flagged' => !array_key_exists('unverified', $read['review']),
    'public_flag' => C\public_record([
        'id' => str_repeat('c', 32),
        'state' => 'pending_confirmation',
        'created' => time(),
        'user_id' => 1,
        'tool' => 'mcp-adapter-execute-ability',
        'arguments' => [],
        'review' => $unread['review'],
    ])['unverified'],
];
$out['code_view'] = [
    'php' => C\code_view(['parameters' => ['code' => 'echo 1;', 'novamira_review' => ['summary' => 'x']]]),
    'command' => C\code_view(['parameters' => ['args' => ['user', 'create', 'bob', 'b@x.it']]]),
    'file' => C\code_view(['parameters' => ['path' => 'wp-content/x.php', 'content' => '<?php echo 1;']]),
    'edit' => C\code_view(['parameters' => ['path' => 'a.php', 'old_string' => 'a', 'new_string' => 'b']]),
    'other' => C\code_view(['parameters' => ['id' => 4]]),
];
$code_route = $GLOBALS['routes']['/confirmations/(?P<id>[a-f0-9]{32})/code'] ?? null;
$out['code_route'] = [
    'method' => $code_route['methods'] ?? '',
    'needs_nonce' => $code_route !== null && !$code_route['permission_callback'](new WP_REST_Request()),
    'missing' => C\call_code(new WP_REST_Request(['id' => str_repeat('f', 32)]))->get_error_data()['status'],
];


// What WordPress lets a person recover from the dashboard does not grow with a loop: the trash, plugins on and off.
if (!function_exists('get_post_type')) {
    eval('function get_post_type($id) { return $GLOBALS["post_types"][$id] ?? false; }');
}
if (!function_exists('get_post_status')) {
    eval('function get_post_status($id) { return $GLOBALS["post_statuses"][$id] ?? "publish"; }');
}
$GLOBALS['post_types'] = [12 => 'post', 13 => 'page', 14 => 'product', 15 => 'post'];
$GLOBALS['post_statuses'] = [15 => 'trash'];
$trash_score = static fn(string $code): int => C\analyze_code($code)['score'];
$cli_score = static fn(array $args): int => C\wp_cli_check(['args' => $args])['score'];
$recoverable = static fn(): array => [
    'trash_in_loop' => $trash_score('foreach ($ids as $id) { wp_trash_post($id); }'),
    'trash_in_array_map' => $trash_score("array_map('wp_trash_post', \$ids);"),
    'plugins_off_in_loop' => $trash_score('foreach ($p as $x) { deactivate_plugins(["a/a.php"]); }'),
    'plugins_off_unknown_in_loop' => $trash_score('foreach ($p as $x) { deactivate_plugins($x); }'),
    'plugins_off_novamira_in_loop' => $trash_score('foreach (["novamira/novamira.php"] as $p) { deactivate_plugins($p); }'),
    'plugins_off_array_map' => $trash_score("array_map('deactivate_plugins', \$p);"),
    'single_trash' => $trash_score('wp_trash_post(12);'),
    'trash_post_comments_in_loop' => $trash_score('foreach ($ids as $id) { wp_trash_post_comments($id); }'),
    'cli_already_trashed' => $cli_score(['post', 'delete', '12', '15']),
    'cli_one_already_trashed' => $cli_score(['post', 'delete', '15']),
    'delete_in_loop' => $trash_score('foreach ($ids as $id) { wp_delete_post($id, true); }'),
    'cli_posts_to_trash' => $cli_score(['post', 'delete', '12', '13']),
    'cli_product_not_trash' => $cli_score(['post', 'delete', '12', '14']),
    'cli_force' => $cli_score(['post', 'delete', '12', '13', '--force']),
];
$out['recoverable_trash_on'] = $recoverable();
define('EMPTY_TRASH_DAYS', 0);
$out['recoverable_trash_off'] = $recoverable();

// Code given as text to run: refused inside execute-php, where the AI can write it directly; in a file, a person decides.
$code_call = static fn(string $code): ?array => C\analyze_call(['ability_name' => 'novamira/execute-php', 'parameters' => ['code' => $code]]);
$out['instructions_say'] = [
    'no_browser_approval' => str_contains(C\scoring_instructions(), 'Never approve or reject requests on the AI Activity page'),
    'eval_refused' => str_contains(C\scoring_instructions(), 'it refuses eval and create_function'),
];
$out['code_as_text'] = [
    'eval_refused' => C\is_forbidden($code_call('eval("echo 1;");')),
    'create_function_refused' => C\is_forbidden($code_call('$f = create_function("", "return 1;");')),
    'message' => str_contains(C\refusal_text($code_call('eval("echo 1;");')['rules']), 'Write that code directly'),
    'dynamic_call_still_asks' => !C\is_forbidden($code_call('$f = "unl" . "ink"; $f("x");'))
        && $code_call('$f = "unl" . "ink"; $f("x");')['reject'],
    'eval_in_a_file_asks' => !C\is_forbidden(C\analyze_source("<?php eval('echo 1;');"))
        && C\analyze_source("<?php eval('echo 1;');")['reject'],
    'refusal_keeps_other_findings' => $code_call('eval("1;"); unlink("x");')['rules'],
];

// The AI's own requests: an address not approved yet asks whatever the level, GET included; approving remembers it.
$sends = static fn(string $code): array => array_values(array_filter(
    C\analyze_code($code)['rules'],
    static fn(string $rule): bool => str_starts_with($rule, 'send:'),
));
$out['destinations'] = [
    'get_literal' => $sends('wp_remote_get("https://api.example.com/track?email=" . $email);'),
    'post_variable_once' => $sends('$url = "https://hooks.example.com/x"; wp_remote_post($url, ["body" => $data]);'),
    'variable_twice' => $sends('$url = "https://a.example.com"; $url = $other; wp_remote_get($url);'),
    'host_unknown' => $sends('wp_remote_get($base . "/x");'),
    'interpolated_host_unknown' => $sends('wp_remote_get("https://{$host}/x");'),
    'host_cut_short' => $sends('wp_remote_get("https://api." . $domain);'),
    'reader_local_file' => $sends('file_get_contents(__DIR__ . "/x.json");'),
    'reader_url' => $sends('file_get_contents("https://feeds.example.com/a.xml");'),
    'curl' => $sends('$c = curl_init(); curl_setopt($c, CURLOPT_URL, "https://crm.example.com/api"); curl_exec($c);'),
    'callback' => $sends('array_map("wp_remote_get", $urls);'),
    'socket' => $sends('fsockopen("ssl://mail.example.com", 465);'),
];
C\save_settings(new WP_REST_Request(['cutoff' => 10]));
$get = 'return wp_remote_get("https://api.example.com/track?x=1");';
$asks = static fn(string $code): bool => C\chat_call_check('novamira/execute-php', ['code' => $code], 0)['must_ask'];
$out['destinations_ask'] = [
    'new_asks_at_100' => $asks($get),
    'why_names_it' => str_contains(C\chat_call_check('novamira/execute-php', ['code' => $get], 0)['why'], 'api.example.com'),
    'own_site_free' => $asks('return wp_remote_get("https://shop.example.test/wp-json/");'),
    'wordpress_org_free' => $asks('return wp_remote_get("https://api.wordpress.org/core/version-check/1.7/");'),
];
C\remember_destinations_of('novamira/execute-php', ['code' => $get]);
$out['destinations_ask']['remembered'] = C\approved_destinations();
$out['destinations_ask']['approved_runs'] = !$asks($get);
$out['destinations_ask']['other_address_asks'] = $asks('return wp_remote_get("https://other.example.com/");');
$out['destinations_ask']['unknown_still_asks'] = $asks('return wp_remote_get($u);');
C\remember_destinations_of('novamira/execute-php', ['code' => 'return wp_remote_get($u);']);
$out['destinations_ask']['unknown_not_remembered'] = C\approved_destinations();
C\save_settings(new WP_REST_Request(['cutoff' => 10, 'forget_destinations' => ['api.example.com', 7]]));
$out['destinations_ask']['forgotten'] = C\approved_destinations();
$out['destinations_ask']['asks_again'] = $asks($get);
$out['destinations_ask']['skip_asks_nothing'] = (static function () use ($asks, $get): bool {
    C\save_settings(new WP_REST_Request(['cutoff' => 10, 'skip_approvals' => true]));
    $asked = $asks($get);
    C\save_settings(new WP_REST_Request(['cutoff' => 10, 'skip_approvals' => false]));
    return $asked;
})();
$out['destinations_ask']['mcp_waits'] = $run($get, 5)['structuredContent']['state'] ?? null;
$out['destinations_ask']['instructions'] = str_contains(C\scoring_instructions(), 'GET included');

// Skipping approvals is the last answer to which actions need approval; picking a level turns it back off.
C\save_settings(new WP_REST_Request(['level' => 'big']));
C\save_settings(new WP_REST_Request(['level' => 'skip']));
$skip_settings = C\settings();
C\save_settings(new WP_REST_Request(['level' => 'most']));
$out['skip_as_level'] = [
    'skipped' => $skip_settings['skip_approvals'],
    'cutoff_kept' => $skip_settings['cutoff'],
    'chosen' => $skip_settings['level_chosen'],
    'level_turns_it_off' => C\settings()['skip_approvals'],
    'cutoff_now' => C\settings()['cutoff'],
];

// What the AI reads about this site: the abilities set to always ask, and the administrator's instructions.
if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field(string $text): string { return strip_tags($text); }
}
C\save_approval_instructions("  <b>Ticket statuses</b> notify customers.  ");
C\set_ability_choice('acme/change-ticket-status', 'yes');
$out['site_instructions'] = [
    'saved' => C\approval_instructions(),
    'text' => C\site_approval_instructions(),
    'in_scoring' => str_contains(C\scoring_instructions(), 'Ticket statuses notify customers.'),
    'in_rest_help' => str_contains(C\rest_scored_review_help(), '- acme/change-ticket-status'),
    'first_sentence' => [
        C\first_sentence("Changes a ticket.  You MUST always\nsend the id."),
        C\first_sentence('No dot here'),
        C\first_sentence(str_repeat('a', 300)),
    ],
];
// The rules travel with a three-letter code: a rated call without the code of the rules in force goes back to the AI
// with them, before it runs.
C\set_ability_choice('acme/change-ticket-status', 'level');
C\save_approval_instructions('Changing the site tagline.');
$code = C\rules_code();
close_open_requests();
$before = $GLOBALS['executions'];
$without_code = call($handler, ['attention' => 1]);
$ran_without = $GLOBALS['executions'] - $before;
$with_code = call($handler, ['attention' => 1, 'rules' => strtoupper($code)]);
$ran_with = $GLOBALS['executions'] - $before;
C\save_approval_instructions('Changing the site title.');
$new_code = C\rules_code();
$old_code = call($handler, ['attention' => 1, 'rules' => $code]);
$out['rules_gate'] = [
    'code' => $code,
    'refused' => ($without_code['isError'] ?? false) === true,
    'text' => (string) ($without_code['content'][0]['text'] ?? ''),
    'ran_without' => $ran_without,
    'ran_with' => $ran_with,
    'changed' => $new_code !== $code,
    'old_code_text' => (string) ($old_code['content'][0]['text'] ?? ''),
    'old_code_ran' => $GLOBALS['executions'] - $before,
    'in_scoring' => str_contains(C\scoring_instructions(), "Rules code: {$new_code}."),
    'in_rest_help' => str_contains(C\rest_scored_review_help(), "Rules code: {$new_code}."),
    'in_context' => function_exists('novamira_build_server_instructions')
        ? str_contains(novamira_build_server_instructions(), "Rules code: {$new_code}.")
        : null,
];
C\save_approval_instructions('');
$out['site_instructions']['empty'] = C\site_approval_instructions();
$out['rules_gate']['none'] = [C\rules_code(), C\rules_code_line()];

// The AI is told to explain approvals to a person once, the first time a request of theirs waits.
$GLOBALS['user_id'] = 1;
delete_user_meta(1, C\FIRST_APPROVAL_META);
$out['first_hint'] = [C\first_approval_hint() !== '', C\first_approval_hint()];
$out['decision_rule'] = [str_contains(C\scoring_instructions(), 'Only a person decides'), str_contains(C\waiting_step(), 'Only a person decides'), str_contains(C\rest_waiting_text('abc', 'pending_confirmation'), 'Only a person decides')];

// Deleting users always asks, in Chat too, and so does a direct SQL delete on the users table.
$out['deletes_users'] = [
    'chat' => C\chat_call_check('novamira/execute-php', ['code' => 'wp_delete_user(5);'], 0)['must_ask'],
    'sql' => C\analyze_code('$wpdb->query("DELETE FROM {$wpdb->users} WHERE ID = 5");')['rules'],
    'concat' => C\analyze_code('$wpdb->query("DELETE FROM " . $wpdb->users . " WHERE ID = 5");')['rules'],
    'prefix' => C\analyze_code('$wpdb->query("DELETE FROM {$wpdb->prefix}users WHERE ID = 5");')['rules'],
    'method' => C\analyze_code('$wpdb->delete($wpdb->users, ["ID" => 5]);')['rules'],
    'usermeta' => C\analyze_code('$wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE user_id = 5");')['rules'],
    'posts' => C\analyze_code('$wpdb->delete($wpdb->posts, ["ID" => 5]);')['rules'],
];

echo json_encode($out, JSON_THROW_ON_ERROR);
