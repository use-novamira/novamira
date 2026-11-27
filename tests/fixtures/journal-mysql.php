<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Runs the change journal against a real MySQL database through a minimal $wpdb.
 * Usage: php journal-mysql.php <plugin root> <host> <port> <user> <password> <database>
 */

[, $root, $host, $port, $user, $password, $database] = $argv;

define('ABSPATH', '/');
define('WPINC', 'wp-includes');
function wp_normalize_path(string $path): string { return str_replace('\\', '/', $path); }
define('DAY_IN_SECONDS', 86400);
function wp_timezone(): DateTimeZone { return new DateTimeZone('Europe/Rome'); }
define('DB_NAME', $database);
mysqli_report(MYSQLI_REPORT_OFF);

$GLOBALS['filters'] = [];
$GLOBALS['options'] = [];
$GLOBALS['mails_sent'] = [];
$GLOBALS['session'] = 'session-a';
$GLOBALS['current_filter'] = [];
$GLOBALS['confirmations_on'] = true;
$GLOBALS['query_count'] = 0;

class WP_Error
{
    public function __construct(public string $code = '', public string $message = '', public mixed $data = null) {}
    public function get_error_message(): string { return $this->message; }
}

class wpdb
{
    public string $prefix = 'wp_';
    public string $options = 'wp_options';
    public string $users = 'wp_users';
    public string $usermeta = 'wp_usermeta';
    public string $posts = 'wp_posts';
    public string $postmeta = 'wp_postmeta';
    public string $comments = 'wp_comments';
    public string $commentmeta = 'wp_commentmeta';
    public string $terms = 'wp_terms';
    public string $term_taxonomy = 'wp_term_taxonomy';
    public string $term_relationships = 'wp_term_relationships';
    public string $termmeta = 'wp_termmeta';
    public int $insert_id = 0;
    public string $last_error = '';
    public int $rows_affected = 0;
    public int $num_rows = 0;
    public string $last_query = '';
    public array $last_result = [];

    public function __construct(public mysqli $dbh) {}

    public function prepare(string $query, mixed ...$args): string
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        $index = 0;
        return (string) preg_replace_callback('/%[isd]/', function (array $m) use (&$index, $args): string {
            $value = $args[$index++];
            return match ($m[0]) {
                '%i' => '`' . str_replace('`', '``', (string) $value) . '`',
                '%d' => (string) (int) $value,
                default => "'" . $this->dbh->real_escape_string((string) $value) . "'",
            };
        }, $query);
    }

    public function esc_like(string $text): string { return addcslashes($text, '_%\\'); }
    public function get_charset_collate(): string { return 'DEFAULT CHARSET=utf8mb4'; }

    public function query(string $query): int|bool
    {
        $query = apply_filters('query', $query);
        $this->last_result = [];
        $this->last_error = '';
        $this->rows_affected = 0;
        $this->num_rows = 0;
        $this->last_query = (string) $query;
        if (!$query) {
            $this->insert_id = 0;
            return false;
        }
        $GLOBALS['query_count']++;
        $result = $this->dbh->query($query);
        if ($result === false) {
            $this->last_error = $this->dbh->error;
            return false;
        }
        if (preg_match('/^\s*(insert|replace)\s/i', $query)) {
            $this->insert_id = (int) $this->dbh->insert_id;
        }
        if ($result instanceof mysqli_result) {
            $this->last_result = $result->fetch_all(MYSQLI_ASSOC);
            return count($this->last_result);
        }
        $this->rows_affected = $this->dbh->affected_rows;
        return $this->rows_affected;
    }

    public function get_results(string $query, string $output = 'OBJECT'): ?array
    {
        return $this->query($query) === false ? null : $this->last_result;
    }

    public function get_row(string $query, string $output = 'OBJECT'): ?array
    {
        $rows = $this->get_results($query);
        return $rows[0] ?? null;
    }

    public function get_var(string $query): ?string
    {
        $row = $this->get_row($query);
        if ($row === null) return null;
        $value = array_values($row)[0] ?? null;
        return $value === null ? null : (string) $value;
    }

    public function get_col(string $query): array
    {
        return array_map(static fn(array $row): mixed => array_values($row)[0], $this->get_results($query) ?? []);
    }

    private function values(array $data): string
    {
        return implode(', ', array_map(fn($v) => $v === null ? 'NULL' : "'" . $this->dbh->real_escape_string((string) $v) . "'", $data));
    }

    public function insert(string $table, array $data): int|bool
    {
        $columns = implode(', ', array_map(static fn($c) => "`{$c}`", array_keys($data)));
        return $this->query("INSERT INTO `{$table}` ({$columns}) VALUES (" . $this->values($data) . ')');
    }

    private function pairs(array $data, string $glue): string
    {
        $parts = [];
        foreach ($data as $column => $value) {
            $parts[] = "`{$column}` = " . ($value === null ? 'NULL' : "'" . $this->dbh->real_escape_string((string) $value) . "'");
        }
        return implode($glue, $parts);
    }

    public function update(string $table, array $data, array $where): int|bool
    {
        return $this->query("UPDATE `{$table}` SET " . $this->pairs($data, ', ') . ' WHERE ' . $this->pairs($where, ' AND '));
    }

    public function delete(string $table, array $where): int|bool
    {
        return $this->query("DELETE FROM `{$table}` WHERE " . $this->pairs($where, ' AND '));
    }
}

function number_format_i18n(float|int $number): string
{
    return number_format((float) $number);
}

function add_filter(string $hook, callable|string $callback, int $priority = 10, int $accepted_args = 1): bool
{
    $GLOBALS['filters'][$hook][$priority][] = [$callback, $accepted_args];
    return true;
}
function add_action(string $hook, callable|string $callback, int $priority = 10, int $accepted_args = 1): bool
{
    return add_filter($hook, $callback, $priority, $accepted_args);
}
function remove_filter(string $hook, callable|string $callback, int $priority = 10): bool
{
    foreach ($GLOBALS['filters'][$hook][$priority] ?? [] as $i => [$registered]) {
        if ($registered === $callback) {
            unset($GLOBALS['filters'][$hook][$priority][$i]);
        }
    }
    return true;
}
function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    $callbacks = $GLOBALS['filters'][$hook] ?? [];
    ksort($callbacks);
    foreach ($callbacks as $list) {
        foreach ($list as [$callback, $count]) {
            $value = $callback($value, ...array_slice($args, 0, $count - 1));
        }
    }
    return $value;
}
function do_action(string $hook, mixed ...$args): void
{
    $callbacks = $GLOBALS['filters'][$hook] ?? [];
    ksort($callbacks);
    $GLOBALS['current_filter'][] = $hook;
    foreach ($callbacks as $list) {
        foreach ($list as [$callback, $count]) {
            $callback(...array_slice($args, 0, $count));
        }
    }
    array_pop($GLOBALS['current_filter']);
}
function current_filter(): string { return end($GLOBALS['current_filter']) ?: ''; }
function get_option(string $name, mixed $default_value = false): mixed { return $GLOBALS['options'][$name] ?? $default_value; }
function update_option(string $name, mixed $value, mixed $autoload = null): bool { $GLOBALS['options'][$name] = $value; return true; }
function wp_json_encode(mixed $value): string|false { return json_encode($value); }
function get_current_user_id(): int { return $GLOBALS['current_user'] ?? 1; }
function get_userdata(int $id): object|false { return $id === 1 ? (object) ['display_name' => 'Ada'] : false; }
function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
function wp_cache_flush(): bool { return true; }
function wp_doing_cron(): bool { return true; }
function __(string $text, string $domain = ''): string { return $text; }
function wp_mail($to, $subject, $message, $headers = '', $attachments = []): bool
{
    $GLOBALS['mails_sent'][] = $subject;
    return true;
}

// The confirmation functions the journal relies on.
eval(<<<'PHP'
namespace Novamira\Confirmations;
const PAGE_SLUG = 'novamira-ai-activity';
function active(): bool { return $GLOBALS['confirmations_on']; }
function covers_work(): bool { return $GLOBALS['confirmations_on']; }
function ability_is_ours(string $ability): bool { return str_starts_with($ability, 'novamira/'); }
function holds_emails(): bool { return $GLOBALS['hold_emails'] ?? true; }
function ability_policy(string $ability): array { return ['confirm' => $ability === 'novamira/read-file' ? 'never' : 'above']; }
function listed_ability_policy(string $ability): array { return ability_policy($ability); }
function rules_update(): string { return ''; }
function session_id(?object $request = null, ?string $chat = null): string { if ($chat !== null) { $GLOBALS['session'] = 'chat-' . $chat; } return $GLOBALS['session']; }
function response(array $data, bool $error = false): array { return $data + ['is_error' => $error]; }
function execution_timeout(): int { return 120; }
function analyze_call(array $args): ?array { $code = $args['parameters']['code'] ?? ''; return ['score' => 0, 'rules' => is_string($code) && str_contains($code, 'unlink') ? ['medium:files'] : [], 'reject' => false]; }
function dashboard_url(): string { return 'https://example.test/wp-admin/admin.php?page=novamira-ai-activity'; }
PHP);
eval(<<<'PHP'
namespace Novamira\Secrets;
function redact_string(string $value): string { return str_replace('SECRET-KEY', '[redacted]', $value); }
function hide_string(string $value): string { return redact_string($value); }
PHP);

$mysqli = new mysqli($host, $user, $password, $database, (int) $port);
$GLOBALS['wpdb'] = new wpdb($mysqli);
$wpdb = $GLOBALS['wpdb'];

require $root . '/vendor/autoload.php';
require $root . '/includes/confirmations/wp-cli-check.php';
require $root . '/includes/confirmations/wp-cli-words.php';
require $root . '/includes/journal/bootstrap.php';
require $root . '/includes/confirmations/RunningApproval.php';

use Novamira\Journal as J;

foreach (['wp_whole', 'wp_whole_small', 'wp_whole_empty', 'wp_too_large', 'wp_wanted', 'wp_nokey', 'wp_many', 'wp_postmeta', 'wp_term_relationships', 'wp_force', 'wp_restore', 'wp_mask', 'wp_bin', 'wp_anon', 'wp_chat', 'wp_posts', 'wp_options', 'wp_nopk', 'wp_swap', 'wp_bulk', 'wp_big', 'wp_huge', 'wp_two', 'wp_race', 'wp_users', 'wp_usermeta', 'wp_novamira_journal'] as $table) {
    $mysqli->query("DROP TABLE IF EXISTS `{$table}`");
}
foreach ($mysqli->query("SHOW TABLES LIKE 'wp\\_novamira\\_bak\\_%'")->fetch_all() as [$backup]) {
    $mysqli->query("DROP TABLE `{$backup}`");
}
$mysqli->query('CREATE TABLE wp_posts (ID BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, post_title VARCHAR(200), post_status VARCHAR(20), post_type VARCHAR(20), post_excerpt VARCHAR(200))');
$mysqli->query('CREATE TABLE wp_options (option_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, option_name VARCHAR(191) UNIQUE, option_value LONGTEXT, autoload VARCHAR(20))');
$mysqli->query('CREATE TABLE wp_nopk (a INT, b VARCHAR(20))');
$mysqli->query('CREATE TABLE wp_users (ID BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_login VARCHAR(60), user_pass VARCHAR(255), user_activation_key VARCHAR(255))');
$mysqli->query('CREATE TABLE wp_usermeta (umeta_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED, meta_key VARCHAR(255), meta_value LONGTEXT)');
$mysqli->query("INSERT INTO wp_users (user_login, user_pass, user_activation_key) VALUES ('alice', 'HASH-ONE', 'RESET-ONE')");
$mysqli->query("INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (1, 'session_tokens', 'TOKENS-ONE'), (1, 'nickname', 'Ali')");
foreach (['test 1', 'test 2', 'test 3', 'test 4', 'test 5', 'real 1', 'real 2'] as $title) {
    $mysqli->query("INSERT INTO wp_posts (post_title, post_status) VALUES ('{$title}', 'publish')");
}
$mysqli->query("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('blogname', 'Old name', 'on'), ('_transient_x', 'cache', 'off')");
$mysqli->query("INSERT INTO wp_nopk VALUES (1, 'one'), (2, 'two')");

/** The emails an execution holds, as the page lists them under its group. */
function held_emails(string $execution): array
{
    $group = J\group_of_execution($execution);
    return J\describe_group($group)['emails'];
}

function snapshot(mysqli $db): array
{
    return [
        'posts' => $db->query('SELECT post_title, post_status FROM wp_posts ORDER BY ID')->fetch_all(MYSQLI_ASSOC),
        'blogname' => $db->query("SELECT option_value FROM wp_options WHERE option_name = 'blogname'")->fetch_row()[0] ?? null,
        'nopk' => (int) $db->query('SELECT COUNT(*) FROM wp_nopk')->fetch_row()[0],
    ];
}

$out = [];
$before = snapshot($mysqli);

// 1. One execution that changes a bit of everything.
J\begin_tool_call(['ability_name' => 'novamira/execute-php'], 'mcp-adapter-execute-ability');
$out['update_rows'] = $wpdb->query("UPDATE wp_posts SET post_status = 'draft' WHERE post_title LIKE 'test%'");
$out['delete_rows'] = $wpdb->query("DELETE FROM wp_posts WHERE post_title = 'real 1'");
$wpdb->insert('wp_posts', ['post_title' => 'inserted', 'post_status' => 'publish']);
$wpdb->query("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('blogname', 'New name', 'on') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)");
$wpdb->query("UPDATE wp_options SET option_value = 'cache2' WHERE option_name = '_transient_x'");
$out['mail_return'] = apply_filters('pre_wp_mail', null, ['to' => 'a@example.test', 'subject' => 'Hello', 'message' => 'Body']);
$out['held_view'] = array_column(J\held_mails(), 'subject');
$out['held_count'] = J\held_mail_count();
$out['http_post_untouched'] = apply_filters('pre_http_request', false, ['method' => 'POST', 'body' => 'x=1'], 'https://api.example.test/refund') === false;
$out['http_get_untouched'] = apply_filters('pre_http_request', false, ['method' => 'GET'], 'https://example.test/') === false;
$wpdb->query('TRUNCATE TABLE wp_nopk');
$result = J\end_tool_call(['ok' => true], [], 'mcp-adapter-execute-ability');
$summary = $result['novamira_journal'];
$out['summary'] = [
    'state' => $summary['state'],
    'changed_rows' => $summary['changed_rows'],
    'unverified' => $summary['unverified_writes'],
    'held_emails' => $summary['held_emails'],
    'sent_requests' => $summary['sent_requests'],
];
$out['filter_removed'] = empty(array_filter($GLOBALS['filters']['query'] ?? [], static fn($list) => $list !== []));
$after = snapshot($mysqli);
$out['changed_on_site'] = $after !== $before;
$out['transient_changed'] = $mysqli->query("SELECT option_value FROM wp_options WHERE option_name = '_transient_x'")->fetch_row()[0];

// 2. A person edits one of the AI's rows afterwards.
$mysqli->query("UPDATE wp_posts SET post_title = 'test 2 edited' WHERE post_title = 'test 2'");

// 3. The scenarios below start from the rows as they were, the person's edit aside.
$mysqli->query("UPDATE wp_posts SET post_status = 'publish' WHERE post_title <> 'test 2 edited'");
$mysqli->query("DELETE FROM wp_posts WHERE post_title = 'inserted'");
$mysqli->query("INSERT INTO wp_posts (post_title, post_status) VALUES ('real 1', 'publish')");
$mysqli->query("UPDATE wp_options SET option_value = 'Old name' WHERE option_name = 'blogname'");
$mysqli->query("INSERT INTO wp_nopk VALUES (1, 'one'), (2, 'two')");

// 4. A write to the history stops the execution before the query runs, and nothing after it runs.
$GLOBALS['session'] = 'session-b';
J\begin_tool_call(['ability_name' => 'novamira/execute-php'], 'mcp-adapter-execute-ability');
$status_before = $mysqli->query("SELECT GROUP_CONCAT(post_status) FROM wp_posts")->fetch_row()[0];
try {
    // Code that protects its own writes with catch (Exception) does not swallow the stop.
    try {
        $wpdb->query('DELETE FROM wp_novamira_journal');
        $out['halt_thrown'] = false;
    } catch (\Exception) {
        $out['halt_swallowed_by_exception'] = true;
    }
} catch (J\Halted $e) {
    $out['halt_thrown'] = true;
}
$out['halt_swallowed_by_exception'] ??= false;
$out['read_after_halt'] = $wpdb->get_var('SELECT COUNT(*) FROM wp_posts');
$halted = J\end_tool_call(['secret output'], [], 'mcp-adapter-execute-ability');
$out['halt_is_error'] = $halted instanceof WP_Error;
$out['halt_message'] = $halted instanceof WP_Error ? $halted->get_error_message() : '';
$out['halt_status'] = $halted instanceof WP_Error ? ($halted->data['status'] ?? 0) : 0;
$out['nothing_changed_by_halt'] = $mysqli->query("SELECT GROUP_CONCAT(post_status) FROM wp_posts")->fetch_row()[0] === $status_before;

// 4b. An ability that lets the stop escape as an exception still closes its execution: the rest of the
// request runs normally.
J\begin_tool_call(['ability_name' => 'novamira/write-file'], 'mcp-adapter-execute-ability');
try {
    $wpdb->query('DELETE FROM wp_novamira_journal');
} catch (J\Halted $e) {
    $escaped = J\on_tool_exception(null, $e);
}
$out['escaped_reply'] = is_array($escaped ?? null) ? [$escaped['is_error'], isset($escaped['novamira_journal'])] : null;
$out['query_after_escaped_stop'] = $wpdb->get_var('SELECT COUNT(*) FROM wp_posts');

// 5. A large write just runs: what is decided is decided before the code starts.
J\begin_tool_call(['ability_name' => 'novamira/execute-php'], 'mcp-adapter-execute-ability');
$out['approved_rows'] = $wpdb->query("UPDATE wp_posts SET post_status = 'private' WHERE post_status = 'publish'");
J\end_tool_call([], [], 'mcp-adapter-execute-ability');

// 6. The journal refuses changes to itself; read-only abilities are not journaled.
J\begin_tool_call(['ability_name' => 'novamira/execute-php'], 'mcp-adapter-execute-ability');
try {
    $wpdb->query('DELETE FROM wp_novamira_journal');
    $out['journal_protected'] = false;
} catch (J\Halted $e) {
    $out['journal_protected'] = true;
}
J\end_tool_call([], [], 'mcp-adapter-execute-ability');
J\begin_tool_call(['ability_name' => 'novamira/read-file'], 'mcp-adapter-execute-ability');
$out['read_ability_not_journaled'] = !J\state()['active'];
J\begin_tool_call(['ability_name' => 'novamira/run-wp-cli', 'parameters' => ['args' => ['post', 'delete', '1']]], 'mcp-adapter-execute-ability');
$out['wp_cli_change_journaled'] = J\state()['active'];
$out['wp_cli_change_says_so'] = isset(J\end_tool_call([], [], 'mcp-adapter-execute-ability')['novamira_journal']['not_restorable']);

// 7. Releasing a held email sends it once.
$GLOBALS['session'] = 'session-c';
J\begin_tool_call(['ability_name' => 'novamira/execute-php'], 'mcp-adapter-execute-ability');
apply_filters('pre_wp_mail', null, ['to' => 'b@example.test', 'subject' => 'Release me', 'message' => 'Body']);
$listed = J\end_tool_call([], [], 'mcp-adapter-execute-ability')['novamira_journal']['execution_id'];
$mail = held_emails($listed)[0];
// The person deciding reads the email; once it is sent, only who it was for and its subject stay.
$out['held_shows_text'] = array_column(J\held_mails(), 'message', 'subject')['Release me'] ?? null;
$out['release'] = [J\decide_mail($mail['id'], true), J\decide_mail($mail['id'], true)];
$out['sent_text_forgotten'] = json_decode((string) $wpdb->get_var($wpdb->prepare('SELECT old_value FROM %i WHERE id = %d', [J\table(), $mail['id']])), true);
$out['mails_sent'] = $GLOBALS['mails_sent'];

// 7b. Attachments are not copied: an email goes out only with the files it was held with.
$attachment = tempnam(sys_get_temp_dir(), 'nm');
$attachment_mail = static function (string $subject) use ($attachment): string {
    file_put_contents($attachment, 'the invoice');
    J\begin_tool_call(['ability_name' => 'novamira/execute-php'], 'mcp-adapter-execute-ability');
    apply_filters('pre_wp_mail', null, ['to' => 'b@example.test', 'subject' => $subject, 'message' => 'Body', 'attachments' => [$attachment]]);
    return J\end_tool_call([], [], 'mcp-adapter-execute-ability')['novamira_journal']['execution_id'];
};
$GLOBALS['session'] = 'session-attachments';
$same = $attachment_mail('Attachment same');
$other = $attachment_mail('Attachment other');
$gone = $attachment_mail('Attachment gone');
$email_named = static function (string $subject) use ($gone): array {
    foreach (held_emails($gone) as $held) {
        if ($held['subject'] === $subject) {
            return $held;
        }
    }
    return [];
};
$out['attachment_same_flag'] = $email_named('Attachment same')['attachments_changed'];
$out['attachment_same_sent'] = J\decide_mail($email_named('Attachment same')['id'], true);
file_put_contents($attachment, 'another customer');
$out['attachment_other'] = [$email_named('Attachment other')['attachments_changed'], J\decide_mail($email_named('Attachment other')['id'], true)];
unlink($attachment);
$out['attachment_gone'] = [$email_named('Attachment gone')['attachments_changed'], J\decide_mail($email_named('Attachment gone')['id'], true)];
$gone_id = $email_named('Attachment gone')['id'];
$out['attachment_gone_can_be_discarded'] = J\decide_mail($gone_id, false);
$out['discarded_text_forgotten'] = array_keys((array) json_decode((string) $wpdb->get_var($wpdb->prepare('SELECT old_value FROM %i WHERE id = %d', [J\table(), $gone_id])), true));
$out['attachment_mails_sent'] = array_values(array_filter($GLOBALS['mails_sent'], static fn(string $s): bool => str_starts_with($s, 'Attachment')));

// 8. Secrets are never kept.
$GLOBALS['session'] = 'session-d';
J\begin_tool_call(['ability_name' => 'novamira/execute-php'], 'mcp-adapter-execute-ability');
$wpdb->query("UPDATE wp_users SET user_pass = 'HASH-TWO', user_login = 'renamed' WHERE ID = 1");
$wpdb->query('DELETE FROM wp_usermeta WHERE user_id = 1');
$secret_execution = J\end_tool_call([], [], 'mcp-adapter-execute-ability')['novamira_journal']['execution_id'];
$out['secrets_in_journal'] = (int) $mysqli->query("SELECT COUNT(*) FROM wp_novamira_journal WHERE old_value LIKE '%HASH-ONE%' OR old_value LIKE '%RESET-ONE%' OR old_value LIKE '%TOKENS-ONE%'")->fetch_row()[0];
$mysqli->query("UPDATE wp_users SET user_login = 'alice' WHERE ID = 1");
$mysqli->query("INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (1, 'nickname', 'Ali')");

// 9. Without confirmations the journal records but never brakes: no budget, emails go out.
$GLOBALS['confirmations_on'] = false;
$GLOBALS['session'] = 'session-e';
J\begin_tool_call(['ability_name' => 'novamira/execute-php'], 'mcp-adapter-execute-ability');
$queries_before = $GLOBALS['query_count'];
$out['unbraked_rows'] = $wpdb->query("UPDATE wp_posts SET post_status = 'pending'");
$wpdb->query('SELECT 1');
$out['extra_queries_for_one_update'] = $GLOBALS['query_count'] - $queries_before - 2;
$out['unbraked_mail'] = apply_filters('pre_wp_mail', null, ['to' => 'c@example.test', 'subject' => 'Sent', 'message' => 'Body']);
$unbraked = J\end_tool_call([], [], 'mcp-adapter-execute-ability')['novamira_journal'];
$out['unbraked_summary'] = ['changed_rows' => $unbraked['changed_rows'], 'held_emails' => $unbraked['held_emails']];
$GLOBALS['confirmations_on'] = true;

// 10a. With the setting off, emails go out and are counted, and scheduled events are not watched.
$GLOBALS['hold_emails'] = false;
$GLOBALS['session'] = 'session-no-hold';
J\begin_tool_call(['ability_name' => 'novamira/execute-php'], 'mcp-adapter-execute-ability');
$out['no_hold_mail'] = apply_filters('pre_wp_mail', null, ['to' => 'c@example.test', 'subject' => 'Sent', 'message' => 'x']);
apply_filters('schedule_event', (object) ['hook' => 'nm_no_hold', 'args' => [1], 'timestamp' => time() + 60]);
$no_hold = J\end_tool_call([], [], 'mcp-adapter-execute-ability')['novamira_journal'];
$out['no_hold_summary'] = ['held' => $no_hold['held_emails'], 'sent' => $no_hold['sent_emails'], 'watched' => $no_hold['watched_scheduled_events']];
unset($GLOBALS['hold_emails']);

// 10b. Emails sent later by an event the AI scheduled are held under the execution that scheduled it.
$GLOBALS['confirmations_on'] = true;
$GLOBALS['session'] = 'session-f';
J\begin_tool_call(['ability_name' => 'novamira/execute-php'], 'mcp-adapter-execute-ability');
apply_filters('schedule_event', (object) ['hook' => 'nm_digest', 'args' => [7], 'timestamp' => time() + 60]);
$scheduled = J\end_tool_call([], [], 'mcp-adapter-execute-ability')['novamira_journal'];
$out['watched_events'] = $scheduled['watched_scheduled_events'];
add_action('nm_digest', static function ($id): void {
    $atts = ['to' => 'list@example.test', 'subject' => "Digest {$id}", 'message' => 'Body'];
    if (apply_filters('pre_wp_mail', null, $atts) === null) {
        wp_mail($atts['to'], $atts['subject'], $atts['message']);
    }
}, 10, 1);
J\watch_marked_events();
do_action('nm_digest', 7);
do_action('nm_digest', 8);
// The mark of a single event is used up by its first run: the same hook later (scheduled by someone else) runs.
do_action('nm_digest', 7);
// With the setting now off, an event already marked no longer holds anything.
$GLOBALS['session'] = 'session-f2';
J\begin_tool_call(['ability_name' => 'novamira/execute-php'], 'mcp-adapter-execute-ability');
apply_filters('schedule_event', (object) ['hook' => 'nm_digest', 'args' => [9], 'timestamp' => time() + 60]);
$scheduled_off = J\end_tool_call([], [], 'mcp-adapter-execute-ability')['novamira_journal'];
$GLOBALS['hold_emails'] = false;
do_action('nm_digest', 9);
unset($GLOBALS['hold_emails']);
$out['cron_setting_off_holds_nothing'] = held_emails($scheduled_off['execution_id']);
$out['cron_held'] = array_map(static fn(array $mail): string => $mail['subject'], held_emails($scheduled['execution_id']));
$out['cron_unmarked_sent'] = in_array('Digest 8', $GLOBALS['mails_sent'], true);
$out['cron_marked_sent_once_later'] = count(array_keys($GLOBALS['mails_sent'], 'Digest 7', true));

// 10c. Counting a write before it runs, for the confirmation card.
$out['measured'] = [
    J\measure_sql("DELETE FROM wp_posts WHERE post_title LIKE 'test%'"),
    J\measure_sql("UPDATE wp_posts SET post_status = 'x' WHERE post_title LIKE 'test%' LIMIT 2"),
    J\measure_sql("INSERT INTO wp_posts (post_title) VALUES ('a'), ('b'), ('c')"),
    J\measure_sql('TRUNCATE TABLE wp_nopk'),
    J\measure_sql('SELECT nonsense FROM'),
];
$out['measure_changed_nothing'] = (int) $mysqli->query("SELECT COUNT(*) FROM wp_posts WHERE post_title LIKE 'test%'")->fetch_row()[0];

// 11. Recording never changes what a query does, never takes inserted ids from a failed insert, and stays
// within a fixed size without the brakes.
$GLOBALS['confirmations_on'] = false;
$GLOBALS['session'] = 'session-g';
$mysqli->query('DROP TABLE IF EXISTS wp_swap');
$mysqli->query('DROP TABLE IF EXISTS wp_bulk');
$mysqli->query('CREATE TABLE wp_swap (id INT PRIMARY KEY, pos INT UNIQUE)');
$mysqli->query('INSERT INTO wp_swap VALUES (1, 1), (2, 2), (3, 3)');
$mysqli->query('CREATE TABLE wp_bulk (id INT AUTO_INCREMENT PRIMARY KEY, v INT)');
$mysqli->query('INSERT INTO wp_bulk (v) VALUES ' . implode(', ', array_fill(0, 2001, '(0)')));
J\begin_tool_call(['ability_name' => 'novamira/execute-php'], 'mcp-adapter-execute-ability');
// Only works in this order: run as written, it never collides on the unique key.
$out['ordered_update_rows'] = $wpdb->query('UPDATE wp_swap SET pos = pos + 1 ORDER BY pos DESC');
$wpdb->insert('wp_posts', ['post_title' => 'kept', 'post_status' => 'publish']);
$kept_id = $wpdb->insert_id;
$out['failed_insert'] = $wpdb->query("INSERT INTO wp_posts (ID, post_title) VALUES ({$kept_id}, 'duplicate')");
$out['bulk_rows'] = $wpdb->query('UPDATE wp_bulk SET v = 1');
$wpdb->query('SELECT 1');
$recorded = J\end_tool_call([], [], 'mcp-adapter-execute-ability')['novamira_journal'];
$out['recording'] = [
    'unverified' => $recorded['unverified_writes'],
    'inserted' => array_map(static fn(array $r): string => $r[0], $mysqli->query(
        "SELECT pk FROM wp_novamira_journal WHERE execution = '" . $mysqli->real_escape_string($recorded['execution_id']) . "' AND kind = 'insert' AND tbl = 'wp_posts'",
    )->fetch_all()),
    'kept_id' => (string) $kept_id,
    'bulk_saved' => (int) $mysqli->query("SELECT COUNT(*) FROM wp_novamira_journal WHERE tbl = 'wp_bulk'")->fetch_row()[0],
];

// 12. The AI can find a table's copy and restore from it, confirmations on, keeping rows written since.
$GLOBALS['confirmations_on'] = true;
$GLOBALS['session'] = 'session-h';
J\begin_tool_call(['ability_name' => 'novamira/execute-php'], 'mcp-adapter-execute-ability');
$wpdb->query('TRUNCATE TABLE wp_swap');
$truncated = J\end_tool_call([], [], 'mcp-adapter-execute-ability')['novamira_journal'];
$mysqli->query('INSERT INTO wp_swap VALUES (9, 9)');
$table_entry = J\ai_group_details($truncated['group_id'])['saved_rows'][0];
$table_row = J\ai_saved_row($table_entry['entry_id']);
$copy_name = $table_row['copy'];
J\begin_tool_call(['ability_name' => 'novamira/execute-php'], 'mcp-adapter-execute-ability');
$copy_read = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$copy_name}`");
$wpdb->query("INSERT INTO wp_swap SELECT * FROM `{$copy_name}`");
$from_copy = J\end_tool_call([], [], 'mcp-adapter-execute-ability');
$out['copy_restore'] = [
    'kind' => $table_entry['kind'],
    'operation' => $table_row['operation'],
    'in_copy' => $table_row['rows_in_copy'],
    'read' => $copy_read,
    'rows_now' => (int) $mysqli->query('SELECT COUNT(*) FROM wp_swap')->fetch_row()[0],
    'listed' => $from_copy['novamira_journal']['unverified_writes'] ?? null,
];
J\begin_tool_call(['ability_name' => 'novamira/execute-php'], 'mcp-adapter-execute-ability');
$wpdb->query("DROP TABLE `{$copy_name}`");
J\end_tool_call([], [], 'mcp-adapter-execute-ability');
$out['copy_gone'] = J\ai_saved_row($table_entry['entry_id'])['copy'];
$GLOBALS['confirmations_on'] = true;

// 13. A copy of a table holding secrets keeps none of them.
$GLOBALS['session'] = 'session-i';
J\begin_tool_call(['ability_name' => 'novamira/execute-php'], 'mcp-adapter-execute-ability');
$wpdb->query('ALTER TABLE wp_users MODIFY user_login VARCHAR(61)');
J\end_tool_call([], [], 'mcp-adapter-execute-ability');
$copies = $mysqli->query("SHOW TABLES LIKE 'wp\\_novamira\\_bak\\_%'")->fetch_all();
$secrets_in_copies = 0;
$passwords_in_copies = 0;
$users_copies = 0;
foreach ($copies as [$copy]) {
    if ($mysqli->query("SHOW COLUMNS FROM `{$copy}` LIKE 'user_pass'")->num_rows > 0) {
        $users_copies++;
        $secrets_in_copies += (int) $mysqli->query("SELECT COUNT(*) FROM `{$copy}` WHERE user_activation_key <> ''")->fetch_row()[0];
        $passwords_in_copies += (int) $mysqli->query("SELECT COUNT(*) FROM `{$copy}` WHERE user_pass <> ''")->fetch_row()[0];
    }
}
$out['secrets_in_table_copies'] = $secrets_in_copies;
$out['passwords_in_users_copy'] = $passwords_in_copies;
$out['users_copies'] = $users_copies;

// 14. When the rows a write changes cannot be saved, a braked execution stops before the write.
$GLOBALS['session'] = 'session-j';
$mysqli->query('RENAME TABLE wp_novamira_journal TO wp_novamira_journal_away');
J\state()['active'] = true;
J\state()['confirming'] = true;
J\state()['execution'] = 'unsaved';
add_filter('query', 'Novamira\\Journal\\on_query', PHP_INT_MAX);
$unsaved_listed_before = J\state()['unverified'];
$wpdb->query("UPDATE wp_posts SET post_status = 'trash' WHERE post_title = 'real 2'");
$out['unsaved_listed'] = J\state()['unverified'] - $unsaved_listed_before;
remove_filter('query', 'Novamira\\Journal\\on_query', PHP_INT_MAX);
J\state()['active'] = false;
$mysqli->query('RENAME TABLE wp_novamira_journal_away TO wp_novamira_journal');
$out['unsaved_ran'] = $mysqli->query("SELECT post_status FROM wp_posts WHERE post_title = 'real 2'")->fetch_row()[0];
$mysqli->query("UPDATE wp_posts SET post_status = 'private' WHERE post_title = 'real 2'");

// 15. The AI may read the history but never change it, counting never runs code, and the AI keeps its insert id.
$GLOBALS['session'] = 'session-k';
J\begin_tool_call(['ability_name' => 'novamira/execute-php'], 'mcp-adapter-execute-ability');
$out['history_read_runs'] = $wpdb->get_var('SELECT COUNT(*) FROM wp_novamira_journal') !== null;
try {
    $wpdb->query('DELETE FROM wp_novamira_journal');
    $out['history_write_blocked'] = false;
} catch (J\Halted $e) {
    $out['history_write_blocked'] = true;
}
J\end_tool_call([], [], 'mcp-adapter-execute-ability');
// Novamira's own options: its code may write them during the execution, the AI's code may not, however it names them.
J\begin_tool_call(['ability_name' => 'novamira/execute-php'], 'mcp-adapter-execute-ability');
$out['novamira_option_own_write'] = $wpdb->query("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('novamira_confirmation_settings', 'own', 'off') ON DUPLICATE KEY UPDATE option_value = 'own'") !== false;
try {
    eval('global $wpdb; $wpdb->query("UPDATE wp_options SET option_value = \'skip\' WHERE option_name = \'novamira_confirmation_settings\'");');
    $out['novamira_option_ai_write_blocked'] = false;
} catch (J\Halted $e) {
    $out['novamira_option_ai_write_blocked'] = str_contains($e->getMessage(), "Novamira's own settings");
    $out['novamira_option_named_in_message'] = str_contains(
        $e->getMessage(),
        'the option novamira_confirmation_settings',
    );
}
J\end_tool_call([], [], 'mcp-adapter-execute-ability');
$out['novamira_option_kept'] = $mysqli->query("SELECT option_value FROM wp_options WHERE option_name = 'novamira_confirmation_settings'")->fetch_row()[0];
J\begin_tool_call(['ability_name' => 'novamira/execute-php'], 'mcp-adapter-execute-ability');
$out['other_option_ai_write_runs'] = eval('global $wpdb; return $wpdb->query("UPDATE wp_options SET option_value = \'AI name\' WHERE option_name = \'blogname\'");') !== false;
J\end_tool_call([], [], 'mcp-adapter-execute-ability');
// The same with confirmations off.
$confirmations_were = $GLOBALS['confirmations_on'];
$GLOBALS['confirmations_on'] = false;
J\begin_tool_call(['ability_name' => 'novamira/execute-php'], 'mcp-adapter-execute-ability');
$out['history_read_runs_unconfirmed'] = $wpdb->get_var('SELECT COUNT(*) FROM wp_novamira_journal') !== null;
try {
    $wpdb->query('DELETE FROM wp_novamira_journal');
    $out['history_write_blocked_unconfirmed'] = false;
} catch (J\Halted $e) {
    $out['history_write_blocked_unconfirmed'] = true;
}
J\end_tool_call([], [], 'mcp-adapter-execute-ability');
// A text that quotes the table's name does not touch the table.
J\begin_tool_call(['ability_name' => 'novamira/execute-php'], 'mcp-adapter-execute-ability');
$out['history_quoted_runs'] = $wpdb->query("UPDATE wp_posts SET post_excerpt = 'see wp_novamira_journal' WHERE post_title = 'real 2'") !== false;
J\end_tool_call([], [], 'mcp-adapter-execute-ability');
// Maintenance changes no row of it.
J\begin_tool_call(['ability_name' => 'novamira/execute-php'], 'mcp-adapter-execute-ability');
$out['history_maintenance_runs'] = $wpdb->query('OPTIMIZE TABLE wp_novamira_journal') !== false;
J\end_tool_call([], [], 'mcp-adapter-execute-ability');
// A comment MySQL runs is code.
J\begin_tool_call(['ability_name' => 'novamira/execute-php'], 'mcp-adapter-execute-ability');
try {
    $wpdb->query('/*!50000 DELETE FROM wp_novamira_journal */');
    $out['history_run_comment_blocked'] = false;
} catch (J\Halted $e) {
    $out['history_run_comment_blocked'] = true;
}
J\end_tool_call([], [], 'mcp-adapter-execute-ability');
// Another plugin's ability is only recorded.
J\begin_tool_call(['ability_name' => 'acme/maintenance'], 'mcp-adapter-execute-ability');
$out['history_other_plugin_not_stopped'] = $wpdb->query("UPDATE wp_novamira_journal SET status = status WHERE id = 0") !== false;
J\end_tool_call([], [], 'mcp-adapter-execute-ability');
$GLOBALS['confirmations_on'] = $confirmations_were;
$out['measure_with_sleep'] = J\measure_sql("DELETE FROM wp_posts WHERE post_title = 'x' OR SLEEP(5)");
$out['measure_with_subquery'] = J\measure_sql('DELETE FROM wp_posts WHERE ID IN (SELECT 1)');
$out['measure_with_in_list'] = J\measure_sql("DELETE FROM wp_posts WHERE post_title IN ('real 2', 'nope')")['rows'] ?? null;
$GLOBALS['session'] = 'session-l';
J\begin_tool_call(['ability_name' => 'novamira/execute-php'], 'mcp-adapter-execute-ability');
$wpdb->insert('wp_posts', ['post_title' => 'id kept', 'post_status' => 'draft']);
$agent_id = $wpdb->insert_id;
$wpdb->query("UPDATE wp_posts SET post_status = 'draft' WHERE post_title = 'real 2'");
$out['insert_id_kept'] = [
    'wpdb' => $wpdb->insert_id === $agent_id,
    'sql' => (int) $wpdb->get_var('SELECT LAST_INSERT_ID()') === $agent_id,
];
J\end_tool_call([], [], 'mcp-adapter-execute-ability');

// 16. Without the brakes, a condition that cannot be read twice runs once, unread and listed as not recorded.
$GLOBALS['confirmations_on'] = false;
$GLOBALS['session'] = 'session-m';
$mysqli->query('DROP TABLE IF EXISTS wp_big');
$mysqli->query('CREATE TABLE wp_big (id INT AUTO_INCREMENT PRIMARY KEY, n INT DEFAULT 0, body LONGTEXT)');
$mysqli->query('INSERT INTO wp_big (n) VALUES (0), (0), (0)');
J\begin_tool_call(['ability_name' => 'novamira/execute-php'], 'mcp-adapter-execute-ability');
$wpdb->query('SET @nm_seen = 0');
$wpdb->query('UPDATE wp_big SET n = 1 WHERE (@nm_seen := @nm_seen + 1) > 0');
$out['variable_evaluated_once'] = (int) $wpdb->get_var('SELECT @nm_seen');
// A function known to be pure, and parentheses around a condition, are read and recorded as usual.
$wpdb->query("UPDATE wp_big SET n = 2 WHERE (id = 1 OR id = 2) AND LOWER('A') = 'a'");
// A LIMIT without ORDER BY lets the server pick the rows.
$wpdb->query('UPDATE wp_big SET n = 3 LIMIT 1');
$unread = J\end_tool_call([], [], 'mcp-adapter-execute-ability')['novamira_journal'];
$out['unread'] = ['unverified' => $unread['unverified_writes'], 'changed' => $unread['changed_rows'], 'said_so' => isset($unread['not_restorable'])];

// 17. Rows are measured before they are loaded: 40 MB of rows stay out of PHP without the brakes.
$mysqli->query('UPDATE wp_big SET body = REPEAT("x", 1000)');
$GLOBALS['session'] = 'session-n';
$big = str_repeat('y', 4 * 1024 * 1024);
for ($i = 0; $i < 10; $i++) {
    $stmt = $mysqli->prepare('INSERT INTO wp_big (n, body) VALUES (7, ?)');
    $stmt->bind_param('s', $big);
    $stmt->execute();
}
unset($big);
$GLOBALS['loaded_bytes'] = 0;
J\begin_tool_call(['ability_name' => 'novamira/execute-php'], 'mcp-adapter-execute-ability');
$memory_before = memory_get_usage();
$peak_before = memory_get_peak_usage();
$wpdb->query('UPDATE wp_big SET n = 8 WHERE n = 7');
$out['big_peak_mb'] = (int) ((memory_get_peak_usage() - max($memory_before, $peak_before)) / 1048576);
$big_summary = J\end_tool_call([], [], 'mcp-adapter-execute-ability')['novamira_journal'];
$out['big_unverified'] = $big_summary['unverified_writes'];

// 18. With confirmations on, a write over two pages is saved in full, in pages.
$GLOBALS['confirmations_on'] = true;
$GLOBALS['session'] = 'session-o';
J\begin_tool_call(['ability_name' => 'novamira/execute-php'], 'mcp-adapter-execute-ability');
$out['approved_big_rows'] = $wpdb->query('UPDATE wp_big SET n = 9 WHERE n = 8');
$approved_big = J\end_tool_call([], [], 'mcp-adapter-execute-ability')['novamira_journal'];
$out['approved_big'] = ['changed' => $approved_big['changed_rows'], 'unverified' => $approved_big['unverified_writes']];

// 19. With confirmations on too, a condition that cannot be read first runs, listed.
$GLOBALS['session'] = 'session-p';
J\begin_tool_call(['ability_name' => 'novamira/execute-php'], 'mcp-adapter-execute-ability');
$wpdb->query('UPDATE wp_big SET n = n WHERE RAND() < 2');
$out['unreadable_listed'] = J\end_tool_call([], [], 'mcp-adapter-execute-ability')['novamira_journal']['unverified_writes'];
$out['unreadable_rows_kept'] = (int) $mysqli->query('SELECT COUNT(*) FROM wp_big')->fetch_row()[0];

// 20. A write beyond what the history can save runs, listed as not recorded.
$GLOBALS['session'] = 'session-q';
$mysqli->query('DROP TABLE IF EXISTS wp_huge');
$mysqli->query('CREATE TABLE wp_huge (id INT AUTO_INCREMENT PRIMARY KEY, n INT DEFAULT 0)');
$mysqli->query('INSERT INTO wp_huge (n) VALUES (0)');
for ($i = 0; $i < 16; $i++) {
    $mysqli->query('INSERT INTO wp_huge (n) SELECT n FROM wp_huge');
}
J\begin_tool_call(['ability_name' => 'novamira/execute-php'], 'mcp-adapter-execute-ability');
$wpdb->query('UPDATE wp_huge SET n = 1');
$out['too_large_listed'] = J\end_tool_call([], [], 'mcp-adapter-execute-ability')['novamira_journal']['unverified_writes'];
$out['too_large_ran'] = (int) $mysqli->query('SELECT COUNT(*) FROM wp_huge WHERE n = 1')->fetch_row()[0];
$mysqli->query('DROP TABLE wp_huge');

// 21. A REPLACE that a second unique key could turn into a delete of another row is listed as not recorded.
$GLOBALS['session'] = 'session-r';
J\begin_tool_call(['ability_name' => 'novamira/execute-php'], 'mcp-adapter-execute-ability');
$mysqli->query('DROP TABLE IF EXISTS wp_two');
$mysqli->query('CREATE TABLE wp_two (id INT PRIMARY KEY, slug VARCHAR(20) UNIQUE)');
$mysqli->query("INSERT INTO wp_two VALUES (1, 'a'), (2, 'b')");
$wpdb->query("REPLACE INTO wp_two (id, slug) VALUES (1, 'b')");
$replace = J\end_tool_call([], [], 'mcp-adapter-execute-ability')['novamira_journal'];
$out['replace'] = ['unverified' => $replace['unverified_writes'], 'changed' => $replace['changed_rows']];

// 22. A row written between the read and the write is listed as not recorded.
$GLOBALS['confirmations_on'] = false;
$GLOBALS['session'] = 'session-s';
J\begin_tool_call(['ability_name' => 'novamira/execute-php'], 'mcp-adapter-execute-ability');
$mysqli->query('DROP TABLE IF EXISTS wp_race');
$mysqli->query('CREATE TABLE wp_race (id INT AUTO_INCREMENT PRIMARY KEY, n INT)');
$mysqli->query('INSERT INTO wp_race (n) VALUES (1), (1)');
$racer = new mysqli($host, $user, $password, $database, (int) $port);
add_filter('query', static function ($query) use ($racer) {
    if (is_string($query) && str_starts_with($query, 'UPDATE wp_race')) {
        $racer->query('INSERT INTO wp_race (n) VALUES (1)');
    }
    return $query;
}, PHP_INT_MAX);
$wpdb->query('UPDATE wp_race SET n = 2 WHERE n = 1');
$race = J\end_tool_call([], [], 'mcp-adapter-execute-ability')['novamira_journal'];
$out['race'] = ['changed' => $race['changed_rows'], 'unverified' => $race['unverified_writes']];
$mysqli->query('DROP TABLE wp_race');

// 23. What $wpdb reports about the AI's last query survives the journal running queries in between.
$GLOBALS['confirmations_on'] = true;
$GLOBALS['session'] = 'session-t';
J\begin_tool_call(['ability_name' => 'novamira/execute-php'], 'mcp-adapter-execute-ability');
$wpdb->get_results("SELECT post_title FROM wp_posts WHERE post_title = 'real 2'");
$found_before = $wpdb->last_result;
$query_before = $wpdb->last_query;
apply_filters('pre_wp_mail', null, ['to' => 'd@example.test', 'subject' => 'Between', 'message' => 'Body']);
$out['wpdb_state_kept'] = $wpdb->last_result === $found_before && $wpdb->last_query === $query_before;
J\end_tool_call([], [], 'mcp-adapter-execute-ability');
// With no insert id left, LAST_INSERT_ID() does not hand the code one of the journal's own ids.
$GLOBALS['session'] = 'session-u';
$wpdb->insert_id = 0;
$mysqli->query('SELECT LAST_INSERT_ID(0)');
J\begin_tool_call(['ability_name' => 'novamira/execute-php'], 'mcp-adapter-execute-ability');
$out['insert_id_zero'] = (int) $wpdb->get_var('SELECT LAST_INSERT_ID()');
J\end_tool_call([], [], 'mcp-adapter-execute-ability');

// 24. Without the brakes the AI's code may read the history (a backup of every table), and it is not recorded.
$GLOBALS['confirmations_on'] = false;
$GLOBALS['session'] = 'session-v';
J\begin_tool_call(['ability_name' => 'novamira/execute-php'], 'mcp-adapter-execute-ability');
$out['history_readable_unbraked'] = $wpdb->get_var('SELECT COUNT(*) FROM wp_novamira_journal') !== null;
J\end_tool_call([], [], 'mcp-adapter-execute-ability');
$GLOBALS['confirmations_on'] = true;

// 26. Copies leave out sessions and keys altogether.
$mysqli->query("INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (1, 'session_tokens', 'TOKENS-TWO')");
$GLOBALS['session'] = 'session-x';
J\begin_tool_call(['ability_name' => 'novamira/execute-php'], 'mcp-adapter-execute-ability');
$wpdb->query('ALTER TABLE wp_usermeta MODIFY meta_key VARCHAR(190)');
J\end_tool_call([], [], 'mcp-adapter-execute-ability');
$meta_secrets = 0;
foreach ($mysqli->query("SHOW TABLES LIKE 'wp\\_novamira\\_bak\\_%'")->fetch_all() as [$copy]) {
    if ($mysqli->query("SHOW COLUMNS FROM `{$copy}` LIKE 'meta_key'")->num_rows > 0) {
        $meta_secrets += (int) $mysqli->query("SELECT COUNT(*) FROM `{$copy}` WHERE meta_key = 'session_tokens'")->fetch_row()[0];
    }
}
$out['sessions_in_copies'] = $meta_secrets;

// 27. An email whose sending died cannot be sent again from here, only closed.
$GLOBALS['session'] = 'session-y';
J\begin_tool_call(['ability_name' => 'novamira/execute-php'], 'mcp-adapter-execute-ability');
apply_filters('pre_wp_mail', null, ['to' => 'e@example.test', 'subject' => 'Died', 'message' => 'Body']);
$died = J\end_tool_call([], [], 'mcp-adapter-execute-ability')['novamira_journal']['execution_id'];
$died_mail = held_emails($died)[0];
J\claim_entry($died_mail['id'], from: 'held', to: 'sending');
$out['died_mail'] = [J\decide_mail($died_mail['id'], true), J\decide_mail($died_mail['id'], false)];
$out['died_mail_sent'] = in_array('Died', $GLOBALS['mails_sent'], true);

// 28. Two events with the same hook and arguments: only the one the AI scheduled is held.
$GLOBALS['session'] = 'session-z';
J\begin_tool_call(['ability_name' => 'novamira/execute-php'], 'mcp-adapter-execute-ability');
apply_filters('schedule_event', (object) ['hook' => 'nm_twin', 'args' => [1], 'timestamp' => 5000]);
$twin = J\end_tool_call([], [], 'mcp-adapter-execute-ability')['novamira_journal']['execution_id'];
add_action('nm_twin', static function ($id): void {
    $atts = ['to' => 'twin@example.test', 'subject' => 'Twin ' . $GLOBALS['twin_run'], 'message' => 'Body'];
    if (apply_filters('pre_wp_mail', null, $atts) === null) {
        wp_mail($atts['to'], $atts['subject'], $atts['message']);
    }
}, 10, 1);
J\watch_marked_events();
$GLOBALS['twin_run'] = 'someone else';
apply_filters('pre_unschedule_event', null, 4000, 'nm_twin', [1]);
do_action('nm_twin', 1);
$GLOBALS['twin_run'] = 'the AI';
apply_filters('pre_unschedule_event', null, 5000, 'nm_twin', [1]);
do_action('nm_twin', 1);
$out['twin_held'] = array_map(static fn(array $mail): string => $mail['subject'], held_emails($twin));
$out['twin_sent'] = in_array('Twin someone else', $GLOBALS['mails_sent'], true);

// The scenarios below run without the brakes.
$GLOBALS['confirmations_on'] = false;
$run = static function (string $session, array $args, callable $work) use ($wpdb): array {
    $GLOBALS['session'] = $session;
    J\begin_tool_call($args + ['ability_name' => 'novamira/execute-php'], 'mcp-adapter-execute-ability');
    $work();
    return J\end_tool_call([], [], 'mcp-adapter-execute-ability')['novamira_journal'];
};

// 29. A table with a composite primary key is recorded like any other, and its saved rows can be read.
$mysqli->query('CREATE TABLE wp_term_relationships (object_id BIGINT UNSIGNED, term_taxonomy_id BIGINT UNSIGNED, term_order INT DEFAULT 0, PRIMARY KEY (object_id, term_taxonomy_id))');
$mysqli->query('INSERT INTO wp_term_relationships VALUES (1, 1, 0), (2, 1, 0)');
$composite = $run('session-aa', [], static function () use ($wpdb): void {
    $wpdb->insert('wp_term_relationships', ['object_id' => 900, 'term_taxonomy_id' => 3]);
    $wpdb->query('UPDATE wp_term_relationships SET term_order = 5 WHERE object_id = 1');
    $wpdb->query('DELETE FROM wp_term_relationships WHERE object_id = 2');
});
$out['composite_summary'] = ['changed' => $composite['changed_rows'], 'unverified' => $composite['unverified_writes']];
$composite_rows = J\ai_group_details($composite['group_id'])['saved_rows'];
$composite_update = array_values(array_filter($composite_rows, static fn(array $row): bool => $row['kind'] === 'update'))[0];
$composite_saved = J\ai_saved_row($composite_update['entry_id']);
$out['composite_saved'] = ['before' => $composite_saved['before']['term_order'], 'now' => $composite_saved['now']['term_order']];

// Tables for the scenarios below.
$mysqli->query('CREATE TABLE wp_postmeta (meta_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, post_id BIGINT UNSIGNED, meta_key VARCHAR(255), meta_value LONGTEXT)');
$mysqli->query('CREATE TABLE wp_force (id INT AUTO_INCREMENT PRIMARY KEY, v VARCHAR(40), w VARCHAR(40))');
$mysqli->query("INSERT INTO wp_force (id, v, w) VALUES (1, 'A', 'keep'), (2, 'A', 'keep'), (3, 'A', 'keep'), (4, 'A', 'keep')");

// 37. The call is recorded with secrets hidden, and a write with nothing saved is listed as such.
$called = $run('session-aj', ['parameters' => ['code' => 'echo "SECRET-KEY";']], static fn() => $wpdb->query("UPDATE wp_force SET v = 'R' WHERE id = 2 LIMIT 1"));
$out['call_recorded'] = J\group_steps($called['group_id'])[0]['arguments'];
$out['not_restorable'] = str_contains($called['not_restorable'] ?? '', 'previous values');

// 38. Calls of one request form one group, with what happened in plain words; a code says nothing about the request.
$group_run = static function (string $request_id, callable $work) use ($run): array {
    apply_filters('novamira_mcp_adapter_call_tool_arguments', ['request_id' => $request_id], 'mcp-adapter-execute-ability');
    return $run('session-group', [], $work);
};
$step_one = $group_run('req-1', static fn() => $wpdb->insert('wp_posts', ['post_title' => 'Grouped page', 'post_status' => 'draft']));
$grouped_id = (int) $mysqli->query("SELECT ID FROM wp_posts WHERE post_title = 'Grouped page'")->fetch_row()[0];
$step_two = $group_run('req-1', static function () use ($wpdb, $grouped_id): void {
    $wpdb->insert('wp_postmeta', ['post_id' => $grouped_id, 'meta_key' => '_elementor_data', 'meta_value' => '[]']);
    $wpdb->insert('wp_postmeta', ['post_id' => $grouped_id, 'meta_key' => '_edit_lock', 'meta_value' => '1']);
    $wpdb->insert('wp_options', ['option_name' => 'site_tagline', 'option_value' => 'x', 'autoload' => 'off']);
});
$other_request = $group_run('req-2', static fn() => $wpdb->query("UPDATE wp_force SET w = 'other' WHERE id = 1"));
$no_code = $group_run('', static fn() => $wpdb->query("UPDATE wp_force SET w = 'other2' WHERE id = 1"));
$grouped = J\describe_group($step_one['group_id']);
$out['group'] = [
    'same_group' => $step_one['group_id'] === $step_two['group_id'],
    'other_group' => $other_request['group_id'] !== $step_one['group_id'],
    'joins_latest' => $no_code['group_id'] === $other_request['group_id'],
    'steps' => $grouped['steps'],
    'changes' => $grouped['changes'],
    'technical' => $grouped['technical_changes'],
    'request_not_kept' => (int) $mysqli->query("SELECT COUNT(*) FROM wp_novamira_journal WHERE old_value LIKE '%user_request%'")->fetch_row()[0],
];
$read_only = $group_run('req-3', static fn() => $wpdb->get_var('SELECT 1'));
$out['reads_not_listed'] = !in_array($read_only['group_id'], J\group_ids(50, 0), true);

// 39. The AI's sentence about what it did is kept as its own, and a search finds work by it.
$GLOBALS['session'] = 'session-group';
$out['description'] = J\save_description('req-1', 'Added the SECRET-KEY Contacts page');
$found = J\list_groups(1, 'contacts');
$out['search'] = array_map(static fn(array $group): string => $group['group_id'], $found['groups']) === [$step_one['group_id']];
$out['nothing_to_describe'] = J\save_description('never-used', 'x');
// The page shows each operation on its own: the two calls of req-1 are two items, each with its own changes.
$operations = array_values(array_filter(
    J\list_operations(1)['operations'],
    static fn(array $operation): bool => $operation['group_id'] === $step_one['group_id'],
));
// Restoring one operation: its details come with the request it was part of, and the guide says to ask about later work.
$single = $run('session-later', [], static fn() => $wpdb->insert('wp_options', ['option_name' => 'later_test', 'option_value' => '1', 'autoload' => 'off']));
$detail = J\ai_operation_details($single['execution_id']);
$out['operation_detail'] = [
    'operation' => ($detail['operation']['execution_id'] ?? null) === $single['execution_id'],
    'request' => ($detail['group']['group_id'] ?? null) === $single['group_id'],
    'latest' => $detail['operations_since'] ?? null,
    'guide_asks' => str_contains($detail['how_to_restore'] ?? '', 'operations_since'),
    'guide_hand_edits' => str_contains($detail['how_to_restore'] ?? '', 'edited the site by hand'),
    'unknown' => J\ai_operation_details(str_repeat('0', 32)),
];
$run('session-later', [], static fn() => $wpdb->insert('wp_options', ['option_name' => 'later_other', 'option_value' => 'x', 'autoload' => 'off']));
$out['operation_detail']['after_one_more'] = J\ai_operation_details($single['execution_id'])['operations_since'] ?? null;
$out['operations'] = [
    'count' => count($operations),
    'own_ids' => array_map(static fn(array $operation): string => $operation['execution_id'], $operations)
        === [$step_two['execution_id'], $step_one['execution_id']],
    'second_changes' => $operations[0]['changes'] ?? null,
    'first_changes' => $operations[1]['changes'] ?? null,
    'one_step_each' => array_map(static fn(array $operation): int => $operation['steps'], $operations),
    'request_description' => $operations[0]['description'] ?? null,
];
eval('namespace Novamira\\Confirmations; function get_request(string $id): ?array { return $GLOBALS["approvals"][$id] ?? null; }');
$approved_work = $group_run('', static fn() => $wpdb->query("UPDATE wp_force SET w = 'approved' WHERE id = 1"));
$approval = static fn(int $user): array => ['user_id' => $user, 'result' => ['structuredContent' => ['novamira_journal' => ['group_id' => $approved_work['group_id']]]]];
$GLOBALS['approvals'] = ['0123456789abcdef0123456789abcdef' => $approval(1), 'fedcba9876543210fedcba9876543210' => $approval(2)];
$out['described_by_approval'] = J\save_description('0123456789abcdef0123456789abcdef', 'Installed Akismet.')['group_id'] ?? '';
$out['approved_group'] = $approved_work['group_id'];
$out['other_users_approval'] = J\save_description('fedcba9876543210fedcba9876543210', 'x');

$GLOBALS['confirmations_on'] = true;

// Rows and a helper for the scenarios below: a request in whatever session is current.
$mysqli->query("INSERT INTO wp_force (id, v, w) VALUES (5, 'A', 'keep'), (6, 'A', 'keep'), (7, 'A', 'keep'), (8, 'A', 'keep'), (9, 'A', 'keep'), (10, 'A', 'keep'), (11, 'A', 'keep')");
$here_run = static function (string $request_id, callable $work) use ($run): array {
    apply_filters('novamira_mcp_adapter_call_tool_arguments', ['request_id' => $request_id], 'mcp-adapter-execute-ability');
    return $run($GLOBALS['session'], [], $work);
};

// 43. Every administrator's work is listed, the page says whose it is, and the AI is not told.
$GLOBALS['session'] = 'session-ada';
$ada_run = $here_run('req-7', static fn() => $wpdb->query("UPDATE wp_force SET w = 'ada' WHERE id = 3"));
$GLOBALS['current_user'] = 2;
$GLOBALS['session'] = 'session-bob';
$bob_sees = array_map(static fn(array $group): string => $group['group_id'], J\list_groups(1)['groups']);
$ada_view = J\ai_group(J\describe_group($ada_run['group_id']));
$ada_details = J\ai_group_details($ada_run['group_id']);
$out['other_admin'] = [
    'listed' => in_array($ada_run['group_id'], $bob_sees, true),
    'whose_hidden' => !array_key_exists('user_id', $ada_view) && !array_key_exists('this_session', $ada_view),
    'on_the_page' => J\describe_group($ada_run['group_id'])['user_id'],
    'steps' => count($ada_details['steps']),
    'call' => $ada_details['steps'][0]['ability'],
    'details_whose_hidden' => !array_key_exists('user_id', $ada_details['group']),
    'saved_rows_total' => $ada_details['saved_rows_total'],
    'how_to_restore' => str_contains($ada_details['how_to_restore'], 'new change'),
    'no_group' => J\ai_group_details(str_repeat('f', 32)),
];

// 44. A restore is a change like any other: the AI reads the old value, writes it back, and that is a new item
// with its own saved values, so it can be read and restored in turn. Nothing else about the site is touched.
$GLOBALS['current_user'] = 1;
$GLOBALS['confirmations_on'] = false;
$GLOBALS['session'] = 'session-restore';
$mysqli->query('CREATE TABLE wp_restore (id INT AUTO_INCREMENT PRIMARY KEY, w VARCHAR(40))');
$mysqli->query("INSERT INTO wp_restore (w) VALUES ('first'), ('other')");
$changed = $here_run('req-20', static fn() => $wpdb->query("UPDATE wp_restore SET w = 'second' WHERE id = 1"));
$mysqli->query("UPDATE wp_restore SET w = 'person' WHERE id = 2");
$saved = J\ai_saved_row(J\ai_group_details($changed['group_id'])['saved_rows'][0]['entry_id']);
$old_value = $saved['before']['w'];
$restored = $here_run('req-21', static fn() => $wpdb->query("UPDATE wp_restore SET w = '{$old_value}' WHERE id = 1"));
$restored_saved = J\ai_saved_row(J\ai_group_details($restored['group_id'])['saved_rows'][0]['entry_id']);
$out['restore'] = [
    'before' => $old_value,
    'now_at_read' => $saved['now']['w'],
    'value' => $mysqli->query('SELECT w FROM wp_restore WHERE id = 1')->fetch_row()[0],
    'other_kept' => $mysqli->query('SELECT w FROM wp_restore WHERE id = 2')->fetch_row()[0],
    'new_item' => $restored['group_id'] !== $changed['group_id'],
    'its_before' => $restored_saved['before']['w'],
    'engine_gone' => !function_exists('Novamira\\Journal\\execute_plan') && !function_exists('Novamira\\Journal\\undo'),
];

// 44d. Writing back the placeholder of a secret would lose the real value: it stops, confirmations on or off.
$GLOBALS['session'] = 'session-mask';
$GLOBALS['confirmations_on'] = false;
$mysqli->query('CREATE TABLE wp_mask (id INT AUTO_INCREMENT PRIMARY KEY, v TEXT, w VARCHAR(40))');
$mysqli->query("INSERT INTO wp_mask (v, w) VALUES ('key SECRET-KEY', 'a'), ('already [redacted] here', 'b'), ('', 'c'), ('nothing secret', 'e'), ('[redacted] and SECRET-KEY', 'f')");
$mask_value = static fn(int $id) => $mysqli->query("SELECT v FROM wp_mask WHERE id = {$id}")->fetch_row()[0];
$mask_try = static function (string $sql) use ($wpdb, $run): array {
    $GLOBALS['session'] = 'session-mask';
    J\begin_tool_call(['ability_name' => 'novamira/execute-php'], 'mcp-adapter-execute-ability');
    $stopped = false;
    try {
        $wpdb->query($sql);
    } catch (J\Halted $e) {
        $stopped = true;
    }
    $end = J\end_tool_call([], [], 'mcp-adapter-execute-ability');
    return ['stopped' => $stopped, 'error' => $end instanceof WP_Error];
};
$out['mask_real'] = $mask_try("UPDATE wp_mask SET v = 'key [redacted]' WHERE id = 1") + ['value' => $mask_value(1)];
$out['mask_real_alias'] = $mask_try("UPDATE wp_mask m SET m.v = 'key [redacted]' WHERE m.id = 1") + ['value' => $mask_value(1)];
$out['mask_already'] = $mask_try("UPDATE wp_mask SET v = 'still [redacted] there' WHERE id = 2") + ['value' => $mask_value(2)];
$out['mask_empty'] = $mask_try("UPDATE wp_mask SET v = 'about [redacted]' WHERE id = 3") + ['value' => $mask_value(3)];
$out['mask_other_column'] = $mask_try("UPDATE wp_mask SET w = 'z', v = v WHERE id = 1") + ['value' => $mask_value(1)];
$out['mask_word'] = $mask_try("UPDATE wp_mask SET v = 'nothing secret, the word [redacted]' WHERE id = 4") + ['value' => $mask_value(4)];
$out['mask_both'] = $mask_try("UPDATE wp_mask SET v = '[redacted] and [redacted]' WHERE id = 5") + ['value' => $mask_value(5)];
$out['mask_new_row'] = $mask_try("INSERT INTO wp_mask (id, v, w) VALUES (6, 'new [redacted]', 'd')") + ['value' => $mask_value(6)];
$out['mask_not_kept'] = $mask_try("UPDATE wp_mask SET w = '[not kept in the AI change history]' WHERE id = 2") + ['value' => $mysqli->query('SELECT w FROM wp_mask WHERE id = 2')->fetch_row()[0]];
$out['mask_normal'] = $mask_try("UPDATE wp_mask SET v = 'key changed' WHERE id = 1") + ['value' => $mask_value(1)];

// 44e2. Text in an old charset, or cut in the middle of a character, is kept: nothing held disappears.
$GLOBALS['confirmations_on'] = true;
$GLOBALS['session'] = 'session-charset';
$long_text = str_repeat('è', 20000);
$charset_run = $here_run('req-32', static function () use ($long_text): void {
    $GLOBALS['mail_result'] = apply_filters('pre_wp_mail', null, ['to' => 'c@example.test', 'subject' => "Citt\xE0", 'message' => "Conferma \xE8 ok"]);
});
$charset_mail = J\entries('execution = %s AND kind = %s', [$charset_run['execution_id'], 'mail'])[0] ?? null;
$out['charset'] = [
    'mail_result' => $GLOBALS['mail_result'],
    'subject' => $charset_mail['old_value']['subject'] ?? null,
    'message' => $charset_mail['old_value']['message'] ?? null,
    'held_count' => $charset_run['held_emails'],
];
J\begin_tool_call(['ability_name' => 'novamira/execute-php', 'parameters' => ['code' => str_repeat('è', 40000)]], 'mcp-adapter-execute-ability');
$charset_call = J\end_tool_call([], [], 'mcp-adapter-execute-ability')['novamira_journal'];
$out['charset_call'] = ($charset_steps = J\group_steps($charset_call['group_id'])) !== [] ? mb_check_encoding(end($charset_steps)['arguments'], 'UTF-8') && end($charset_steps)['arguments'] !== '' : false;
$GLOBALS['confirmations_on'] = false;

// 44f. A value that cannot be stored costs that value, not the rest of the write, and is listed, never converted.
$mysqli->query('CREATE TABLE wp_bin (id INT AUTO_INCREMENT PRIMARY KEY, b BLOB, t VARCHAR(20))');
$mysqli->query("INSERT INTO wp_bin (b, t) VALUES (0xFFFE00, 'one'), ('plain', 'two'), (0xFFFD00, 'three')");
$bin_value = static fn(int $id) => bin2hex((string) $mysqli->query("SELECT b FROM wp_bin WHERE id = {$id}")->fetch_row()[0]);
$bin_other = $run('session-bin', [], static fn() => $wpdb->query("UPDATE wp_bin SET t = 'new' WHERE id IN (1, 2)"));
$bin_saved = J\entries('execution = %s AND kind = %s', [$bin_other['execution_id'], 'update']);
$bin_row = J\ai_saved_row($bin_saved[0]['pk'] === '1' ? $bin_saved[0]['id'] : $bin_saved[1]['id']);
$bin_written = $run('session-bin', [], static fn() => $wpdb->query("UPDATE wp_bin SET b = 'ai2' WHERE id = 2 OR id = 1"));
$bin_deleted = $run('session-bin', [], static fn() => $wpdb->query('DELETE FROM wp_bin WHERE id = 3'));
$out['binary_recording'] = [
    'other_unverified' => $bin_other['unverified_writes'],
    'other_changed' => $bin_other['changed_rows'],
    'before_text' => $bin_row['before']['t'] ?? null,
    'before_has_binary' => array_key_exists('b', $bin_row['before'] ?? []),
    'not_saved_columns' => $bin_row['not_saved_columns'] ?? null,
    'written_unverified' => $bin_written['unverified_writes'],
    'written_changed' => $bin_written['changed_rows'],
    'deleted_unverified' => $bin_deleted['unverified_writes'],
];
$mysqli->query("INSERT INTO wp_bin (id, b, t) VALUES (10, 0xFFFE00, 'braked')");
$GLOBALS['confirmations_on'] = true;
$bin_confirming = static function (string $sql) use ($wpdb): int {
    $GLOBALS['session'] = 'session-bin-confirming';
    J\begin_tool_call(['ability_name' => 'novamira/execute-php'], 'mcp-adapter-execute-ability');
    $wpdb->query($sql);
    return J\end_tool_call([], [], 'mcp-adapter-execute-ability')['novamira_journal']['unverified_writes'];
};
$mysqli->query("INSERT INTO wp_bin (id, b, t) VALUES (11, 0xFFFE00, 'to delete')");
$out['binary_confirming'] = [
    'other_column_listed' => $bin_confirming("UPDATE wp_bin SET t = 'braked2' WHERE id = 10"),
    'other_column_value' => $mysqli->query('SELECT t FROM wp_bin WHERE id = 10')->fetch_row()[0],
    'same_column_listed' => $bin_confirming("UPDATE wp_bin SET b = 'gone' WHERE id = 10"),
    'delete_listed' => $bin_confirming('DELETE FROM wp_bin WHERE id = 11'),
    'delete_rows' => (int) $mysqli->query('SELECT COUNT(*) FROM wp_bin WHERE id = 11')->fetch_row()[0],
];
$GLOBALS['confirmations_on'] = false;

// 44f-bis. The placeholder on more rows than can be checked is a deliberate write: it runs and the summary says so.
$mysqli->query('CREATE TABLE wp_anon (id INT AUTO_INCREMENT PRIMARY KEY, v TEXT)');
$mysqli->query("INSERT INTO wp_anon (v) VALUES ('a comment')");
for ($i = 0; $i < 8; $i++) {
    $mysqli->query('INSERT INTO wp_anon (v) SELECT v FROM wp_anon');
}
$mysqli->query("INSERT INTO wp_anon (v) SELECT v FROM wp_anon LIMIT 44");
$anon_many = $run('session-anon', [], static fn() => $wpdb->query("UPDATE wp_anon SET v = '[redacted]'"));
$mysqli->query("UPDATE wp_anon SET v = 'a comment'");
$anon_few = $run('session-anon', [], static fn() => $wpdb->query("UPDATE wp_anon SET v = '[redacted]' WHERE id <= 150"));
$out['placeholder_many'] = [
    'few_recorded_all' => ($anon_few['changed_rows']['wp_anon'] ?? 0) === (int) $mysqli->query("SELECT COUNT(*) FROM wp_anon WHERE v = '[redacted]'")->fetch_row()[0],
    'many_note' => array_key_exists('not_checked', $anon_many),
    'many_changed' => $anon_many['changed_rows']['wp_anon'] ?? 0,
    'few_note' => array_key_exists('not_checked', $anon_few),
];

// 44f-ter. A Novamira Chat call is recorded and braked like an MCP call, in its own session, grouped by the message.
$mysqli->query('CREATE TABLE wp_chat (id INT AUTO_INCREMENT PRIMARY KEY, v VARCHAR(20))');
$mysqli->query("INSERT INTO wp_chat (v) VALUES ('a'), ('b'), ('c')");
$chat_value = static fn(int $id) => $mysqli->query("SELECT v FROM wp_chat WHERE id = {$id}")->fetch_row()[0] ?? null;
\Novamira\Confirmations\session_id(chat: 'conv-1');
$chat_first = J\run_direct_call('novamira/execute-php', ['code' => 'x'], 'msg-1', static function () use ($wpdb): array {
    $wpdb->query("UPDATE wp_chat SET v = 'chat' WHERE id = 1");
    return ['done' => true];
});
$chat_second = J\run_direct_call('novamira/execute-php', ['code' => 'y'], 'msg-1', static function () use ($wpdb): array {
    $wpdb->query("UPDATE wp_chat SET v = 'chat' WHERE id = 2");
    return ['done' => true];
});
$chat_other = J\run_direct_call('novamira/execute-php', ['code' => 'z'], 'msg-2', static fn(): array => ['done' => true]);
$chat_read = J\run_direct_call('novamira/read-file', ['path' => 'x'], 'msg-2', static fn(): array => ['read' => true]);
$GLOBALS['confirmations_on'] = true;
$chat_confirming = J\run_direct_call('novamira/execute-php', ['code' => 'w'], 'msg-3', static function () use ($wpdb): array {
    $wpdb->query("UPDATE wp_chat SET v = 'ok' WHERE id > 0");
    return ['done' => true];
});
$GLOBALS['confirmations_on'] = false;
$chat_groups = [$chat_first['novamira_journal']['group_id'], $chat_second['novamira_journal']['group_id'], $chat_other['novamira_journal']['group_id']];
$out['chat_calls'] = [
    'recorded' => $chat_first['novamira_journal']['changed_rows'],
    'same_message_same_group' => $chat_groups[0] === $chat_groups[1],
    'other_message_other_group' => $chat_groups[0] !== $chat_groups[2],
    'session' => J\entries('execution = %s AND kind = %s', [$chat_first['novamira_journal']['execution_id'], 'execution'])[0]['session'],
    'read_untouched' => $chat_read === ['read' => true],
    'runs_with_confirmations' => is_array($chat_confirming),
    'value' => $chat_value(3),
];

// 44g. An execution that never closed reads as running, until it could no longer be.
$GLOBALS['session'] = 'session-stop';
$stop_run = $here_run('req-31', static fn() => $wpdb->query("UPDATE wp_force SET w = 'stop' WHERE id = 5"));
$dead = J\entries('grp = %s AND kind = %s', [$stop_run['group_id'], 'execution'])[0];
J\update_entry($dead['id'], ['status' => 'running']);
$out['running_now'] = J\describe_group($stop_run['group_id'])['state'];
$mysqli->query("UPDATE wp_novamira_journal SET created = created - 100000 WHERE id = {$dead['id']}");
$out['running_long_ago'] = J\describe_group($stop_run['group_id'])['state'];
J\update_entry($dead['id'], ['status' => 'completed']);

// 48. A WP-CLI call says its previous values were not saved.
$cli = $run('session-cli', ['ability_name' => 'novamira/run-wp-cli'], static function (): void {});
$out['cli_said'] = str_contains($cli['not_restorable'] ?? '', 'WP-CLI');

// 49. What the AI reads: everything on the site, secrets aside.
$GLOBALS['confirmations_on'] = true;
$GLOBALS['session'] = 'session-private';
$private = $here_run('req-11', static function () use ($wpdb): void {
    $wpdb->query("UPDATE wp_users SET user_login = 'After AI' WHERE ID = 1");
    apply_filters('pre_wp_mail', null, ['to' => 'customer@example.test', 'subject' => 'Private subject', 'message' => 'x']);
});
$mysqli->query("UPDATE wp_users SET user_login = 'Person Name' WHERE ID = 1");
$listed_private = J\ai_group(J\describe_group($private['group_id']));
$saved_rows = J\ai_group_details($private['group_id'])['saved_rows'];
$saved_row = J\ai_saved_row($saved_rows[0]['entry_id']);
$out['ai_view'] = [
    'recipient' => $listed_private['emails'][0]['to'] ?? '',
    'subject' => $listed_private['emails'][0]['subject'] ?? '',
    'before' => $saved_row['before']['user_login'] ?? null,
    'now' => $saved_row['now']['user_login'] ?? null,
    'password_shown' => array_key_exists('user_pass', $saved_row['now'] ?? []) || array_key_exists('user_pass', $saved_row['before'] ?? []),
    'marker_shown' => array_key_exists('__not_kept', $saved_row['now'] ?? []),
    'hidden' => in_array('user_pass', $saved_row['hidden_columns'], true),
    'unknown' => J\ai_saved_row(999999),
];
$mysqli->query("UPDATE wp_users SET user_login = 'After AI' WHERE ID = 1");

// 50. A write that failed or changed nothing is not reported as a change.
$GLOBALS['confirmations_on'] = false;
$GLOBALS['session'] = 'session-failed';
$nothing = $here_run('req-12', static function () use ($wpdb): void {
    $wpdb->query("UPDATE wp_force SET w = w WHERE id = 10");
});
$failed_write = $here_run('req-13', static function () use ($wpdb): void {
    $wpdb->query("UPDATE wp_force SET id = 11 WHERE id = 10");
});
$out['no_change'] = ['rows' => $nothing['changed_rows'], 'saved' => (int) $mysqli->query("SELECT COUNT(*) FROM wp_novamira_journal WHERE execution = '{$nothing['execution_id']}' AND kind = 'update'")->fetch_row()[0], 'facts' => J\describe_group($nothing['group_id'])['changes']];
$out['failed_write'] = ['rows' => $failed_write['changed_rows'], 'saved' => (int) $mysqli->query("SELECT COUNT(*) FROM wp_novamira_journal WHERE execution = '{$failed_write['execution_id']}' AND kind = 'update'")->fetch_row()[0]];

// 51. A pause counts from the last change: reads do not keep a request open, and no session means one per user.
$GLOBALS['session'] = '';
$no_session_one = $here_run('', static fn() => $wpdb->query("UPDATE wp_force SET w = 'ns1' WHERE id = 7"));
$no_session_two = $here_run('', static fn() => $wpdb->query("UPDATE wp_force SET w = 'ns2' WHERE id = 8"));
$out['no_session_group'] = $no_session_one['group_id'] === $no_session_two['group_id'];
$GLOBALS['session'] = 'session-pause';
$before_pause = $here_run('', static fn() => $wpdb->query("UPDATE wp_force SET w = 'p1' WHERE id = 7"));
$mysqli->query("UPDATE wp_novamira_journal SET created = created - 700 WHERE execution = '{$before_pause['execution_id']}'");
$here_run('', static fn() => $wpdb->query('SELECT 1'));
$after_pause = $here_run('', static fn() => $wpdb->query("UPDATE wp_force SET w = 'p2' WHERE id = 8"));
$out['read_keeps_nothing_open'] = $after_pause['group_id'] !== $before_pause['group_id'];
$GLOBALS['confirmations_on'] = true;

// 52. An upgrade that fails is tried again: the version is saved only once the table really matches it.
$mysqli->query('RENAME TABLE wp_novamira_journal TO wp_novamira_journal_kept');
$mysqli->query("CREATE TABLE wp_old_journal (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, execution CHAR(32) NOT NULL, session VARCHAR(191) NOT NULL DEFAULT '', user_id BIGINT UNSIGNED NOT NULL DEFAULT 0, created INT UNSIGNED NOT NULL DEFAULT 0, kind VARCHAR(20) NOT NULL DEFAULT '', tbl VARCHAR(191) NOT NULL DEFAULT '', pk_col VARCHAR(64) NOT NULL DEFAULT '', pk VARCHAR(191) NOT NULL DEFAULT '', old_value LONGTEXT NULL, new_hash CHAR(64) NULL, status VARCHAR(20) NOT NULL DEFAULT 'recorded')");
$mysqli->query("INSERT INTO wp_old_journal (execution, kind) VALUES ('abc', 'execution')");
$mysqli->query('CREATE VIEW wp_novamira_journal AS SELECT * FROM wp_old_journal');
$GLOBALS['options'][J\SCHEMA_OPTION] = 1;
J\ensure_table();
$out['upgrade_failed_version'] = $GLOBALS['options'][J\SCHEMA_OPTION];
$mysqli->query('DROP VIEW wp_novamira_journal');
$mysqli->query('RENAME TABLE wp_old_journal TO wp_novamira_journal');
J\ensure_table();
$out['upgrade_retried'] = ['version' => $GLOBALS['options'][J\SCHEMA_OPTION], 'grp' => $mysqli->query("SELECT grp FROM wp_novamira_journal WHERE execution = 'abc'")->fetch_row()[0], 'new_hash_gone' => $mysqli->query("SHOW COLUMNS FROM wp_novamira_journal LIKE 'new_hash'")->num_rows === 0, 'waiting_key' => $mysqli->query("SHOW INDEX FROM wp_novamira_journal WHERE Key_name = 'waiting'")->num_rows === 2];
$mysqli->query('DROP TABLE wp_novamira_journal');
$mysqli->query('RENAME TABLE wp_novamira_journal_kept TO wp_novamira_journal');

// 46. Whole tables are listed; one too large to copy runs, listed as not recorded.
$session_chat = static fn(string $name) => \Novamira\Confirmations\session_id(chat: $name);
$rows_in = static fn(string $table): int => (int) $mysqli->query("SELECT COUNT(*) FROM {$table}")->fetch_row()[0];
$GLOBALS['confirmations_on'] = true;
foreach (['wp_whole' => 5, 'wp_whole_small' => 2] as $whole_table => $whole_rows) {
    $mysqli->query("CREATE TABLE {$whole_table} (id INT AUTO_INCREMENT PRIMARY KEY, v VARCHAR(20))");
    for ($i = 0; $i < $whole_rows; $i++) {
        $mysqli->query("INSERT INTO {$whole_table} (v) VALUES ('x')");
    }
}
$mysqli->query('CREATE TABLE wp_whole_empty (id INT AUTO_INCREMENT PRIMARY KEY)');
$session_chat('conv-whole');
$whole_any_size = J\run_direct_call('novamira/execute-php', ['code' => 'a'], 'msg-w1', static function () use ($wpdb): array {
    $wpdb->query('TRUNCATE TABLE wp_whole');
    return ['done' => true];
});
$whole_ok = J\run_direct_call('novamira/execute-php', ['code' => 'b'], 'msg-w2', static function () use ($wpdb): array {
    $wpdb->query('TRUNCATE TABLE wp_whole_small');
    return ['done' => true];
});
$whole_group = J\describe_group($whole_ok['novamira_journal']['group_id']);
$session_chat('conv-whole-empty');
$whole_empty = J\run_direct_call('novamira/execute-php', ['code' => 'c'], 'msg-w3', static function () use ($wpdb): array {
    $wpdb->query('DROP TABLE wp_whole_empty');
    return ['done' => true];
});
$whole_empty_listed = (int) $mysqli->query(sprintf(
    "SELECT listed FROM wp_novamira_journal WHERE execution = '%s' AND kind = 'execution'",
    $whole_empty['novamira_journal']['execution_id'],
))->fetch_row()[0];
$mysqli->query('CREATE TABLE wp_too_large (id INT PRIMARY KEY)');
$mysqli->query('SET SESSION cte_max_recursion_depth = 60000');
$mysqli->query('INSERT INTO wp_too_large (id) WITH RECURSIVE s(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM s WHERE n < 50001) SELECT n FROM s');
$session_chat('conv-big');
$big = J\run_direct_call('novamira/execute-php', ['code' => 'd'], 'msg-w4', static function () use ($wpdb): array {
    $wpdb->query('TRUNCATE TABLE wp_too_large');
    return ['done' => true];
});
$GLOBALS['confirmations_on'] = false;
$out['whole_tables'] = [
    'runs' => is_array($whole_any_size),
    'emptied' => $rows_in('wp_whole'),
    'counted' => $whole_ok['novamira_journal']['changed_rows'],
    'listed_in_words' => in_array('Whole table wp_whole_small: TRUNCATE', $whole_group['changes'], strict: true),
    'empty_table_listed' => $whole_empty_listed,
    'too_large_runs' => !is_wp_error($big),
    'too_large_not_recorded' => is_array($big) ? $big['novamira_journal']['unverified_writes'] : null,
];
$mysqli->query('DROP TABLE wp_too_large');

// 48. The history says what it cannot see: emails and requests that left, and files.
$session_chat('conv-honest');
$left = J\run_direct_call('novamira/execute-php', ['code' => 'f'], 'msg-h1', static function (): array {
    apply_filters('pre_wp_mail', null, ['to' => 'a@example.com']);
    apply_filters('pre_http_request', false, ['method' => 'POST'], 'https://example.com/hook');
    apply_filters('pre_http_request', false, ['method' => 'GET'], 'https://example.com/page');
    return ['done' => true];
});
$nothing = J\run_direct_call('novamira/execute-php', ['code' => 'echo 1;'], 'msg-h2', static fn(): array => ['done' => true]);
$file_call = J\run_direct_call('novamira/delete-file', ['path' => 'x'], 'msg-h3', static fn(): array => ['done' => true]);
$file_code = J\run_direct_call('novamira/execute-php', ['code' => 'unlink("x");'], 'msg-h4', static fn(): array => ['done' => true]);
$listed_of = static fn(array $call): int => (int) $mysqli->query(sprintf(
    "SELECT listed FROM wp_novamira_journal WHERE execution = '%s' AND kind = 'execution'",
    $call['novamira_journal']['execution_id'],
))->fetch_row()[0];
$out['honest_summary'] = [
    'sent_emails' => $left['novamira_journal']['sent_emails'],
    'sent_requests' => $left['novamira_journal']['sent_requests'],
    'sent_listed' => $listed_of($left),
    'group_sent' => J\describe_group($left['novamira_journal']['group_id'])['sent_emails'],
    'no_false_comfort' => !str_contains($nothing['novamira_journal']['restore'], 'Nothing to restore'),
    'says_not_in_history' => str_contains($nothing['novamira_journal']['restore'], 'not in this history'),
    'plain_read_not_listed' => $listed_of($nothing),
    'file_ability_listed' => $listed_of($file_call),
    'file_ability_says_so' => array_key_exists('files', $file_call['novamira_journal']),
    'file_code_listed' => $listed_of($file_code),
    'group_files' => J\describe_group($file_code['novamira_journal']['group_id'])['files'],
];

// 49. A word in a query proves nothing: only a write naming a cache or queue row stays out of the history.
$options_table = $wpdb->options;
$out['untracked_options'] = [
    'delete_transient' => J\is_untracked_option("DELETE FROM `{$options_table}` WHERE `option_name` = '_transient_x'"),
    'update_transient' => J\is_untracked_option("UPDATE `{$options_table}` SET `option_value` = 'a' WHERE `option_name` = '_site_transient_x'"),
    'insert_queue' => J\is_untracked_option("INSERT IGNORE INTO `{$options_table}` (`option_name`, `option_value`) VALUES ('novamira_confirmation_x', 'a')"),
    'like_transient' => J\is_untracked_option("DELETE FROM {$options_table} WHERE option_name LIKE '\\_transient\\_%'"),
    'narrowed' => J\is_untracked_option("UPDATE {$options_table} SET option_value = 'b' WHERE option_name = 'novamira_confirmation_x' AND option_value = 'a'"),
    'real_option_next_to_transient' => J\is_untracked_option("DELETE FROM {$options_table} WHERE option_name = 'blogname' OR option_name = '_transient_x'"),
    'transient_then_or' => J\is_untracked_option("DELETE FROM {$options_table} WHERE option_name = '_transient_x' OR option_name = 'blogname'"),
    'word_in_a_value' => J\is_untracked_option("UPDATE {$options_table} SET option_value = '_transient_x' WHERE option_name = 'blogname'"),
    'like_everything' => J\is_untracked_option("DELETE FROM {$options_table} WHERE option_name LIKE '%_transient_%'"),
];

// 50. A write on a table without a key that cannot be saved leaves the rows of the write before it alone.
$mysqli->query('CREATE TABLE wp_nokey (v VARCHAR(20))');
$mysqli->query("INSERT INTO wp_nokey (v) VALUES ('first')");
$mysqli->query("INSERT INTO wp_nokey (v) WITH RECURSIVE s(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM s WHERE n < 300) SELECT 'bulk' FROM s");
$session_chat('conv-nokey');
$nokey = J\run_direct_call('novamira/execute-php', ['code' => 'g'], 'msg-n1', static function () use ($wpdb): array {
    $wpdb->query("UPDATE wp_nokey SET v = 'one' WHERE v = 'first'");
    $wpdb->query('SELECT 1');
    $wpdb->query("UPDATE wp_nokey SET v = 'many' WHERE v = 'bulk'");
    return ['done' => true];
});
$out['nokey'] = [
    'first_write_kept' => (int) $mysqli->query("SELECT COUNT(*) FROM wp_novamira_journal WHERE tbl = 'wp_nokey' AND kind = 'update'")->fetch_row()[0],
    'second_not_saved' => $nokey['novamira_journal']['unverified_writes'],
];

// 51. A large change can be read to the end, page by page, and many rows at once.
$mysqli->query('CREATE TABLE wp_many (id INT AUTO_INCREMENT PRIMARY KEY, v VARCHAR(20), post_password VARCHAR(20))');
$mysqli->query("INSERT INTO wp_many (v, post_password) WITH RECURSIVE s(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM s WHERE n < 250) SELECT 'old', 'hunter2' FROM s");
$session_chat('conv-many');
$many = J\run_direct_call('novamira/execute-php', ['code' => 'h'], 'msg-m1', static function () use ($wpdb): array {
    $wpdb->query("UPDATE wp_many SET v = 'new' WHERE id > 0");
    return ['done' => true];
});
$many_group = $many['novamira_journal']['group_id'];
$many_first = J\ai_group_details($many_group);
$many_second = J\ai_group_details($many_group, 2);
$many_ids = array_map(static fn(array $row): int => $row['entry_id'], array_slice($many_second['saved_rows'], 0, 3));
$many_read = J\ai_saved_rows([...$many_ids, 999999999]);
$many_row = reset($many_read['rows']);
$out['many_rows'] = [
    'total' => $many_first['saved_rows_total'],
    'first_page' => count($many_first['saved_rows']),
    'second_page' => count($many_second['saved_rows']),
    'no_overlap' => array_intersect(array_column($many_first['saved_rows'], 'entry_id'), array_column($many_second['saved_rows'], 'entry_id')) === [],
    'read_at_once' => count($many_read['rows']),
    'not_found' => $many_read['not_found'],
    'before' => $many_row['before']['v'],
    'protected' => $many_row['protected_post'],
    'names_hq_shows' => array_values(array_filter(
        array_merge(array_keys($many_row), array_keys($many_first['saved_rows'][0])),
        static fn(string $name): bool => in_array(strtolower($name), ['auth', 'key', 'pass', 'pw', 'pwd', 'sig'], true) || preg_match('/password|secret|token|signature/i', $name) === 1,
    )),
    'password_hidden' => !array_key_exists('post_password', $many_row['before']),
    'one_note' => $many_read['note'] !== '' && !array_key_exists('note', $many_row),
    'guide' => str_contains(J\RESTORE_GUIDE, 'wp_insert_post with import_id')
        && str_contains(J\RESTORE_GUIDE, 'what putting this back may set off'),
    'guide_cases' => [
        str_contains(J\RESTORE_GUIDE, 'newest first'),
        str_contains(J\RESTORE_GUIDE, 'already holds'),
        str_contains(J\RESTORE_GUIDE, 'deleted user'),
    ],
];

// 10. Emptying the journal removes every row and every table copy.
// 49. What did not happen does not count, and adding to a table keeps no copy.
$mysqli->query('DROP TABLE IF EXISTS wp_cap, wp_cap_small');
$mysqli->query('CREATE TABLE wp_cap (id INT AUTO_INCREMENT PRIMARY KEY, v VARCHAR(20))');
for ($i = 0; $i < 10; $i++) {
    $mysqli->query("INSERT INTO wp_cap (v) VALUES ('x')");
}
$mysqli->query('CREATE TABLE wp_cap_small (id INT AUTO_INCREMENT PRIMARY KEY, v VARCHAR(20))');
$mysqli->query("INSERT INTO wp_cap_small (v) VALUES ('x')");
$cap_backups = static fn(): int => $mysqli->query("SHOW TABLES LIKE 'wp\\_novamira\\_bak\\_%'")->num_rows;
$session_chat('conv-cap');
$GLOBALS['confirmations_on'] = true;
$cap_run = static fn(string $sql, string $message) => J\run_direct_call('novamira/execute-php', ['code' => $sql], $message, static function () use ($wpdb, $sql): array {
    $wpdb->query($sql);
    return ['done' => true];
});
$cap_backups_before = $cap_backups();
$session_chat('conv-cap-structure');
$cap_failed_alter = $cap_run('ALTER TABLE wp_cap_small DROP COLUMN nope', 'cap-4');
$cap_adds = $cap_run('ALTER TABLE wp_cap ADD INDEX cap_v (v)', 'cap-5');
$GLOBALS['confirmations_on'] = false;
$out['approval_cap'] = [
    'failed_alter_counts_nothing' => $cap_failed_alter['novamira_journal']['changed_rows'] ?? null,
    'failed_alter_keeps_no_copy' => $cap_backups() === $cap_backups_before,
    'adding_an_index_runs' => is_array($cap_adds),
    'adding_an_index_counts_nothing' => $cap_adds['novamira_journal']['changed_rows'] ?? null,
    'adding_an_index_keeps_no_copy' => $cap_backups() === $cap_backups_before,
];
$out['alter_only_adds'] = array_map('Novamira\\Journal\\alter_only_adds', [
    'ALTER TABLE t ADD COLUMN x INT NULL',
    'ALTER TABLE t ADD INDEX i (x), ADD COLUMN y INT',
    "ALTER TABLE t ADD COLUMN x VARCHAR(9) DEFAULT 'drop'",
    'ALTER TABLE t DROP COLUMN x',
    'ALTER TABLE t ADD COLUMN x INT, DROP COLUMN y',
    'ALTER TABLE t MODIFY x BIGINT',
    'ALTER TABLE t ALTER COLUMN x SET DEFAULT 1',
    'ALTER TABLE t RENAME TO u',
]);
// A value that mentions Novamira is not one of its settings: WordPress rewrites the plugin update list, which names
// every installed plugin, when the AI's code calls delete_plugins().
J\begin_tool_call(['ability_name' => 'novamira/execute-php'], 'mcp-adapter-execute-ability');
$GLOBALS['nm_value_query'] =
    "UPDATE wp_options SET option_value = 'a:1:{s:21:\"novamira/novamira.php\";}' WHERE option_name = 'blogname'";
try {
    $out['value_mentioning_novamira_runs'] =
        eval('global $wpdb; return $wpdb->query($GLOBALS["nm_value_query"]);') !== false;
} catch (J\Halted) {
    $out['value_mentioning_novamira_runs'] = false;
}
J\end_tool_call([], [], 'mcp-adapter-execute-ability');
$out['novamira_option_named'] = array_map('Novamira\\Journal\\novamira_option_named', [
    'plugin_list_value' => "UPDATE `wp_options` SET `option_value` = 'a:1:{s:21:\"novamira/novamira.php\";}' WHERE `option_name` = '_site_transient_update_plugins'",
    'insert_value' => "INSERT INTO `wp_options` (`option_name`, `option_value`) VALUES ('patchstack_plugins', 'novamira') ON DUPLICATE KEY UPDATE `option_value` = VALUES(`option_value`)",
    'quote_inside_value' => "UPDATE wp_options SET option_value = 'x\\' WHERE option_name = \\'novamira_a' WHERE option_name = 'blogname'",
    'update' => "UPDATE wp_options SET option_value = 'skip' WHERE option_name = 'novamira_confirmation_settings'",
    'like' => "DELETE FROM wp_options WHERE option_name LIKE 'novamira%'",
    'in_list' => "DELETE FROM wp_options WHERE option_name IN ('blogname', 'novamira_read_mode')",
    'second_row' => "INSERT INTO wp_options (option_name, option_value) VALUES ('a', '1'), ('novamira_features', 'x')",
    'columns_the_other_way_round' => "INSERT INTO wp_options (option_value, option_name) VALUES ('novamira', 'novamira_x')",
    'renamed_into' => "UPDATE wp_options SET option_name = 'novamira_a' WHERE option_id = 5",
    'big_value' => "UPDATE wp_options SET option_value = '" . str_repeat('ab\\\'', 20000) . "' WHERE option_name = 'novamira_x'",
    'transient' => "UPDATE wp_options SET option_value = '1' WHERE option_name = '_transient_novamira_confirmation_heartbeat'",
    'transient_timeout' => "DELETE FROM wp_options WHERE option_name = '_site_transient_timeout_novamira_x'",
    'autoload_only' => "UPDATE wp_options SET autoload = 'off' WHERE option_name IN ('blogname', 'novamira_features')",
    'comment' => "UPDATE wp_options /* novamira options cleanup */ SET option_value = 'x' WHERE option_name = 'blogname'",
    'line_comment' => "UPDATE wp_options SET option_value = 'x' WHERE option_name = 'blogname' -- novamira",
    'executable_comment' => "/*!50000 UPDATE wp_options SET option_value = 'x' WHERE option_name = 'novamira_x' */",
]);
// A write by option_id names no option: the rows it would change are read first.
$mysqli->query("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('novamira_by_id', 'kept', 'off') ON DUPLICATE KEY UPDATE option_value = 'kept'");
$GLOBALS['nm_by_id'] = (int) $mysqli->query("SELECT option_id FROM wp_options WHERE option_name = 'novamira_by_id'")->fetch_row()[0];
$GLOBALS['nm_blog_id'] = (int) $mysqli->query("SELECT option_id FROM wp_options WHERE option_name = 'blogname'")->fetch_row()[0];
J\begin_tool_call(['ability_name' => 'novamira/execute-php'], 'mcp-adapter-execute-ability');
try {
    eval('global $wpdb; $wpdb->query("UPDATE wp_options SET option_value = \'skip\' WHERE option_id = " . $GLOBALS["nm_by_id"]);');
    $out['by_id_blocked'] = false;
} catch (J\Halted $e) {
    $out['by_id_blocked'] = str_contains($e->getMessage(), 'the option novamira_by_id');
}
J\end_tool_call([], [], 'mcp-adapter-execute-ability');
$out['by_id_kept'] = $mysqli->query("SELECT option_value FROM wp_options WHERE option_name = 'novamira_by_id'")->fetch_row()[0];
J\begin_tool_call(['ability_name' => 'novamira/execute-php'], 'mcp-adapter-execute-ability');
try {
    $out['by_id_other_runs'] = eval('global $wpdb; return $wpdb->query("UPDATE wp_options SET option_value = \'By id\' WHERE option_id = " . $GLOBALS["nm_blog_id"]);') !== false;
} catch (J\Halted) {
    $out['by_id_other_runs'] = false;
}
J\end_tool_call([], [], 'mcp-adapter-execute-ability');
// Transients are a cache: clearing all of them, Novamira's included, goes on.
$mysqli->query("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('_transient_novamira_cache_test', '1', 'off'), ('_site_transient_other_cache_test', '1', 'off') ON DUPLICATE KEY UPDATE option_value = '1'");
J\begin_tool_call(['ability_name' => 'novamira/execute-php'], 'mcp-adapter-execute-ability');
try {
    $out['transient_cleanup_runs'] = eval('global $wpdb; return $wpdb->query("DELETE FROM wp_options WHERE option_name LIKE \'\\\\_transient\\\\_%\' OR option_name LIKE \'\\\\_site\\\\_transient\\\\_%\'");') !== false;
} catch (J\Halted) {
    $out['transient_cleanup_runs'] = false;
}
J\end_tool_call([], [], 'mcp-adapter-execute-ability');
$out['transients_left'] = (int) $mysqli->query("SELECT COUNT(*) FROM wp_options WHERE option_name IN ('_transient_novamira_cache_test', '_site_transient_other_cache_test')")->fetch_row()[0];
$mysqli->query("DELETE FROM wp_options WHERE option_name = 'novamira_by_id'");
$out['untracked_insert'] = [
    'one_transient' => J\is_untracked_option("INSERT INTO wp_options (option_name, option_value) VALUES ('_transient_a', '1')"),
    'transient_then_setting' => J\is_untracked_option("INSERT INTO wp_options (option_name, option_value) VALUES ('_transient_a', '1'), ('siteurl', 'x')"),
    'parenthesis_in_a_value' => J\is_untracked_option("INSERT INTO wp_options (option_name, option_value) VALUES ('_transient_a', '), (')"),
    'columns_the_other_way_round' => J\is_untracked_option("INSERT INTO wp_options (option_value, option_name) VALUES ('_transient_a', 'siteurl')"),
    'name_second_with_backticks' => J\is_untracked_option("INSERT INTO wp_options (`autoload`, `option_name`) VALUES ('_transient_a', 'siteurl')"),
];
$mysqli->query("DELETE FROM wp_posts WHERE post_title LIKE 'card%'");
$mysqli->query("INSERT INTO wp_posts (post_title, post_type) VALUES ('card one', 'post'), ('card two', 'post'), ('card three', 'product')");
$card_sql = "DELETE FROM wp_posts WHERE post_title LIKE 'card%'";
$out['card_words'] = [
    'known_table' => J\measured_sentence($card_sql, J\measure_sql($card_sql)),
    'other_table' => J\measured_sentence('DELETE FROM wp_cap WHERE id > 0', J\measure_sql('DELETE FROM wp_cap WHERE id > 0')),
    'whole_table' => J\measured_sentence('TRUNCATE TABLE wp_cap', J\measure_sql('TRUNCATE TABLE wp_cap')),
    'inserts' => J\measured_sentence("INSERT INTO wp_cap (v) VALUES ('a')", J\measure_sql("INSERT INTO wp_cap (v) VALUES ('a')")),
];
$mysqli->query("DELETE FROM wp_posts WHERE post_title LIKE 'card%'");
$mysqli->query('DROP TABLE wp_cap, wp_cap_small');

// 53. A direct call keeps a list a list: the summary would turn it into an object for the client.
$out['direct_list'] = J\run_direct_call('novamira/execute-php', ['code' => 'list'], 'msg-list', static fn(): array => ['a', 'b']);
$out['direct_object_has_summary'] = isset(J\run_direct_call('novamira/execute-php', ['code' => 'object'], 'msg-list', static fn(): array => ['a' => 'b'])['novamira_journal']);

// 54. An email waiting for a person outlives the cleanup and the button: the code was told it went out.
$rows_not_waiting = static fn(): int => (int) $mysqli->query("SELECT COUNT(*) FROM wp_novamira_journal WHERE NOT (kind = 'mail' AND status IN ('held', 'sending'))")->fetch_row()[0];
$subjects_waiting = static fn(): array => array_column(J\held_mails(), 'subject');
$GLOBALS['confirmations_on'] = true;
unset($GLOBALS['hold_emails']);
$GLOBALS['session'] = 'session-waiting';
J\begin_tool_call(['ability_name' => 'novamira/execute-php'], 'mcp-adapter-execute-ability');
apply_filters('pre_wp_mail', null, ['to' => 'w@example.test', 'subject' => 'Wait for me', 'message' => 'Body']);
J\end_tool_call([], [], 'mcp-adapter-execute-ability');
$count_before = J\held_mail_count();
$mysqli->query('UPDATE wp_novamira_journal SET created = 1');
J\prune();
$out['waiting_after_prune'] = [in_array('Wait for me', $subjects_waiting(), true), J\held_mail_count() === $count_before, $rows_not_waiting()];
J\begin_tool_call(['ability_name' => 'novamira/execute-php'], 'mcp-adapter-execute-ability');
J\end_tool_call([], [], 'mcp-adapter-execute-ability');
$out['waiting_row_before_clear'] = $rows_not_waiting() > 0;
J\clear();
$out['waiting_after_clear'] = [in_array('Wait for me', $subjects_waiting(), true), J\held_mail_count() === $count_before, $rows_not_waiting()];
$out['waiting_not_in_history'] = J\list_groups(1)['groups'] ?? null;
foreach (J\held_mails() as $waiting_mail) {
    J\decide_mail($waiting_mail['id'], false);
}
$out['nothing_waits'] = J\held_mail_count();

J\clear();
$out['cleared_rows'] = (int) $mysqli->query('SELECT COUNT(*) FROM wp_novamira_journal')->fetch_row()[0];
$out['cleared_backups'] = $mysqli->query("SHOW TABLES LIKE 'wp\\_novamira\\_bak\\_%'")->num_rows;

// A query past the size the history reads is neither parsed nor counted.
$out['parse_limit'] = J\parse_limit();
$out['big_query_measured'] = J\measure_sql(
    "DELETE FROM wp_posts WHERE post_content = '" . str_repeat('a', J\PARSE_LIMIT_BYTES) . "'",
);

// A write too large to read still stops when it carries the placeholder; one without it runs unrecorded.
$GLOBALS['session'] = 'session-big-placeholder';
add_filter('novamira_journal_max_parse_bytes', static fn(): int => 64);
$blogname = static fn(): string => (string) $mysqli->query("SELECT option_value FROM wp_options WHERE option_name = 'blogname'")->fetch_row()[0];
$name_before = $blogname();
J\begin_tool_call(['ability_name' => 'novamira/execute-php'], 'mcp-adapter-execute-ability');
try {
    $wpdb->query("UPDATE wp_options SET option_value = 'a long value with [redacted] in it' WHERE option_name = 'blogname'");
    $out['big_placeholder_stopped'] = false;
} catch (J\Halted) {
    $out['big_placeholder_stopped'] = true;
}
$big_halt = J\end_tool_call([], [], 'mcp-adapter-execute-ability');
$out['big_placeholder_message'] = $big_halt instanceof WP_Error ? $big_halt->get_error_message() : '';
$out['big_placeholder_kept'] = $blogname() === $name_before;
J\begin_tool_call(['ability_name' => 'novamira/execute-php'], 'mcp-adapter-execute-ability');
$wpdb->query("UPDATE wp_options SET option_value = 'a long plain value, nothing hidden' WHERE option_name = 'blogname'");
J\end_tool_call([], [], 'mcp-adapter-execute-ability');
$out['big_plain_ran'] = $blogname() === 'a long plain value, nothing hidden';
unset($GLOBALS['filters']['novamira_journal_max_parse_bytes']);

// Each call keeps what the AI said it changes, and who approved it when a person did, with the words of the card.
$GLOBALS['session'] = 'session-words';
J\begin_tool_call(['ability_name' => 'novamira/execute-php', 'parameters' => ['code' => 'x'], 'review' => ['summary' => 'Adds the words_one setting.']], 'mcp-adapter-execute-ability');
$wpdb->insert('wp_options', ['option_name' => 'words_one', 'option_value' => '1', 'autoload' => 'off']);
$said = J\end_tool_call([], [], 'mcp-adapter-execute-ability')['novamira_journal']['execution_id'];
\Novamira\Confirmations\RunningApproval::$current = ['by' => 1, 'says' => 'Adds the words_two setting, as approved.'];
J\begin_tool_call(['ability_name' => 'novamira/execute-php', 'parameters' => ['code' => 'y']], 'mcp-adapter-execute-ability');
$wpdb->insert('wp_options', ['option_name' => 'words_two', 'option_value' => '1', 'autoload' => 'off']);
$approved = J\end_tool_call([], [], 'mcp-adapter-execute-ability')['novamira_journal']['execution_id'];
\Novamira\Confirmations\RunningApproval::$current = null;
$out['call_words'] = [
    [J\describe_operation($said, '')['says'], J\describe_operation($said, '')['approved_by']],
    [J\describe_operation($approved, '')['says'], J\describe_operation($approved, '')['approved_by']],
];

echo json_encode($out);
