<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Confirmations;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

if (!defined('ABSPATH')) {
    exit();
}

/** The end of the AI change history's table name, whatever the site's prefix. */
const HISTORY_TABLE_SUFFIX = 'novamira_journal';

/** Scores on the confirmation scale for each level the code check can assign. */
const ANALYSIS_LOW = 0;

const ANALYSIS_MEDIUM = 5;

const ANALYSIS_HIGH = 9;

/** The top of the scale: what changes every link of the site asks even at Ask less. */
const ANALYSIS_MAX = 10;

/** The options that decide the shape of every link on the site. */
const PERMALINK_OPTIONS = ['permalink_structure', 'category_base', 'tag_base'];

/**
 * Read the PHP sent to execute-php without running it. The result is a minimum the AI's score cannot go
 * below, never proof the code is safe: a call whose name is built at runtime is reported as refused, a
 * function the check does not know adds nothing, and what hooked plugins do is not seen.
 *
 * @param array<array-key, mixed> $args mcp-adapter-execute-ability arguments.
 * @return array{score: int, rules: list<string>, reject: bool}|null Null for anything but execute-php.
 */
function analyze_call(array $args): ?array
{
    if (($args['ability_name'] ?? null) !== 'novamira/execute-php') {
        return null;
    }
    $parameters = is_array($args['parameters'] ?? null) ? $args['parameters'] : [];
    $code = is_string($parameters['code'] ?? null) ? $parameters['code'] : '';
    if (!analysis_available()) {
        return analysis_result(['refuse:check_unavailable']);
    }
    try {
        return refuse_code_as_text(analyze_code($code));
    } catch (\Throwable) {
        // Code the check cannot read goes to a person: the check never disappears silently.
        return analysis_result(['refuse:check_unavailable']);
    }
}

/**
 * Inside execute-php, code that runs other code given as text (eval, create_function) is refused: the AI can write that
 * code directly in the call, where it can be checked, so refusing it takes nothing away. In a file it goes to a person.
 *
 * @param array{score: int, rules: list<string>, reject: bool} $analysis
 * @return array{score: int, rules: list<string>, reject: bool}
 */
function refuse_code_as_text(array $analysis): array
{
    if (!in_array('refuse:eval', $analysis['rules'], strict: true)) {
        return $analysis;
    }
    // The other findings stay, so the AI change history still knows the code may touch files.
    $others = array_filter($analysis['rules'], static fn(string $rule): bool => $rule !== 'refuse:eval');
    return analysis_result(['refuse:code_as_text', ...$others]);
}

/**
 * Whether the code check works on this site. Another plugin can load an incompatible version of the PHP parser first;
 * then every call whose code would be checked goes to a person, and the page and the AI's instructions say so.
 */
function analysis_available(): bool
{
    /** @var bool|null $available */
    static $available = null;
    if ($available !== null) {
        return $available;
    }
    try {
        require_once __DIR__ . '/analysis-visitor.php';
        $ast = (new ParserFactory())
            ->createForHostVersion()
            ->parse('<?php wp_delete_post(1);');
        $visitor = new AnalysisVisitor();
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast ?? []);
        $available = $visitor->rules === ['medium:deletes_content'];
    } catch (\Throwable) {
        $available = false;
    }
    return $available;
}

/** Code longer than this is shown as sent: laying it out again costs more than it helps. */
const READABLE_CODE_LIMIT = 50_000;

/**
 * The PHP sent to execute-php as a person reads it on a card: code packed together, on one line or with blocks and
 * long lines, is laid out again, one statement per line and blocks indented. Only for showing: what runs is always
 * the code as sent. Code already laid out, or that does not parse, stays as it is.
 */
function readable_code(string $code): string
{
    if (strlen($code) > READABLE_CODE_LIMIT || !code_is_packed($code)) {
        return $code;
    }
    try {
        $ast = (new ParserFactory())
            ->createForHostVersion()
            ->parse('<?php ' . $code);
        return $ast !== null ? (new Standard())->prettyPrint($ast) : $code;
    } catch (\Throwable) {
        return $code;
    }
}

/** Whether code is packed together: all on one line, a block opened and closed on one line, or a very long line. */
function code_is_packed(string $code): bool
{
    if (!str_contains($code, "\n")) {
        return true;
    }
    foreach (explode("\n", $code) as $line) {
        if (strlen($line) > 120 || preg_match('/\{[^{}\n]*;\s*\}/', $line) === 1) {
            return true;
        }
    }
    return false;
}

/** @return array{score: int, rules: list<string>, reject: bool} */
function analyze_code(string $code): array
{
    return analyze_source('<?php ' . $code);
}

/**
 * Read a whole PHP file (opening tag included) without running it.
 *
 * @return array{score: int, rules: list<string>, reject: bool}
 */
function analyze_source(string $source): array
{
    // Loaded only when code is checked: a parser problem then stays inside the caller's error handling.
    require_once __DIR__ . '/analysis-visitor.php';
    $parser = (new ParserFactory())->createForHostVersion();
    try {
        $ast = $parser->parse($source);
    } catch (\PhpParser\Error) {
        // PHP itself refuses code that does not parse before running any of it.
        return analysis_result(['parse_error']);
    }
    url_variables(single_assignments($ast ?? []));
    try {
        $visitor = new AnalysisVisitor();
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast ?? []);
    } finally {
        url_variables([]);
    }
    return analysis_result($visitor->rules);
}

/**
 * @param list<string> $rules
 * @return array{score: int, rules: list<string>, reject: bool}
 */
function analysis_result(array $rules): array
{
    $rules = array_values(array_unique($rules));
    $score = ANALYSIS_LOW;
    $reject = false;
    foreach ($rules as $rule) {
        $score = max($score, rule_score($rule));
        $reject = $reject || str_starts_with($rule, 'refuse:') || str_starts_with($rule, 'forbid:');
    }
    return ['score' => $score, 'rules' => $rules, 'reject' => $reject];
}

function rule_score(string $rule): int
{
    if (str_starts_with($rule, 'max:')) {
        return ANALYSIS_MAX;
    }
    if (
        str_starts_with($rule, 'high:')
        || str_starts_with($rule, 'refuse:')
        || str_starts_with($rule, 'forbid:')
        || str_starts_with($rule, 'access:')
        || str_starts_with($rule, 'money:')
    ) {
        return ANALYSIS_HIGH;
    }
    // Code that stays on the site and runs on every request: a big change, from the Balanced level up.
    if (str_starts_with($rule, 'big:')) {
        return LEVELS['big'];
    }
    if (str_starts_with($rule, 'medium:') || str_starts_with($rule, 'unverified:')) {
        return ANALYSIS_MEDIUM;
    }
    return ANALYSIS_LOW;
}

/** Whether WordPress keeps what goes to the trash on this site: EMPTY_TRASH_DAYS at 0 deletes at once. */
function trash_is_on(): bool
{
    return !defined('EMPTY_TRASH_DAYS') || (int) constant('EMPTY_TRASH_DAYS') > 0;
}

/**
 * What WordPress lets a person recover from the dashboard, without Novamira: the trash, and turning a plugin or a theme
 * back on. Done many times it stays recoverable, so a loop does not raise it.
 */
function recoverable_in_wordpress(string $rule): bool
{
    $name = substr($rule, offset: (int) strpos($rule, needle: ':') + 1);
    return $name === 'plugins_on_off' || $name === 'plugins_on' || $name === 'trash' && trash_is_on();
}

/** A change repeated in a loop has no fixed size: "medium" becomes "high", unless WordPress can recover it. */
function repeated_rule(string $rule): string
{
    if (!str_starts_with($rule, 'medium:') || recoverable_in_wordpress($rule)) {
        return $rule;
    }
    return 'high:' . substr($rule, offset: strlen('medium:')) . '_in_loop';
}

// @mago-expect lint:halstead -- One list of functions per rule.
/** @return array<string, string> Lower-case function name => rule. */
function function_rules(): array
{
    $rules = [];
    $groups = [
        'refuse:runs_other_code' => [
            'call_user_func',
            'call_user_func_array',
            'forward_static_call',
            'forward_static_call_array',
        ],
        // Code handed over as text to run: inside execute-php the AI can write it directly instead.
        'refuse:eval' => ['create_function'],
        'refuse:shell' => ['exec', 'system', 'passthru', 'shell_exec', 'proc_open', 'popen', 'pcntl_exec'],
        // Deleting a user always asks, whatever the level: see deletes_users().
        'high:deletes_user' => ['wp_delete_user', 'wpmu_delete_user'],
        'high:users_and_roles' => [
            'wp_insert_user',
            'wp_create_user',
            'wp_set_password',
            'add_role',
            'remove_role',
            'grant_super_admin',
            'revoke_super_admin',
        ],
        // Turning a plugin or a theme on changes what runs on every request, like turning on a sandbox file: a big
        // change. Turning one off counts less than deleting one.
        'big:plugins_on' => ['activate_plugin', 'activate_plugins', 'switch_theme'],
        'medium:plugins_on_off' => ['deactivate_plugins'],
        'high:plugins_and_themes' => ['delete_plugins', 'delete_theme'],
        // Money that leaves or moves cannot be brought back by the history or by the AI: a person always decides.
        'money:refund' => ['wc_create_refund', 'wc_refund_payment'],
        'high:network_site' => ['wp_update_site', 'update_blog_status'],
        // Deleting a site removes its uploaded files too, which the AI change history does not keep.
        'high:deletes_site' => ['wpmu_delete_blog', 'wp_delete_site'],
        'high:sign_in' => ['wp_set_auth_cookie', 'wp_destroy_all_sessions', 'wp_destroy_other_sessions'],
        'high:table_structure' => ['dbdelta', 'maybe_create_table', 'maybe_add_column', 'maybe_drop_column'],
        'medium:shop_data' => [
            'wc_create_order',
            'wc_update_product_stock',
            'wc_reduce_stock_levels',
            'wc_increase_stock_levels',
            'wc_maybe_reduce_stock_levels',
            'wc_maybe_increase_stock_levels',
        ],
        'medium:unscheduled' => ['wp_clear_scheduled_hook', 'wp_unschedule_event', 'wp_unschedule_hook'],
        'medium:removes_hooks' => ['remove_all_actions', 'remove_all_filters'],
        // Code left for the end of the request runs after the AI change history has closed.
        'medium:runs_later' => ['register_shutdown_function'],
        // WordPress keeps what goes to the trash: a person brings it back from the dashboard, without Novamira. With
        // EMPTY_TRASH_DAYS at 0 these delete at once, so they count as deleting (see below).
        'medium:trash' => ['wp_trash_post', 'wp_trash_comment'],
        'medium:deletes_content' => [
            // The comments of a trashed post do not show in the comment trash: they come back only with the post.
            'wp_trash_post_comments',
            'wp_delete_post',
            'wp_delete_term',
            'wp_delete_attachment',
            'wp_delete_comment',
            'wp_delete_object_term_relationships',
        ],
        'medium:user_change' => ['wp_update_user'],
        // An email that will wait for a person can be decided later; one that goes out at once is decided now.
        holds_emails() ? 'medium:email' : 'high:email' => [
            'wp_mail',
            'retrieve_password',
            'wp_new_user_notification',
            'wp_send_new_user_notifications',
            'wp_password_change_notification',
            'wp_send_user_request',
        ],
        'high:direct_mail' => ['mail'],
        // What the AI's own code sends out is its decision, and it cannot be taken back: a person is asked first.
        'high:external_request' => [
            'wp_remote_post',
            'wp_remote_request',
            'wp_safe_remote_post',
            'wp_safe_remote_request',
            'curl_exec',
            'curl_multi_exec',
            'fsockopen',
            'stream_socket_client',
        ],
        'medium:files' => [
            'unlink',
            'rmdir',
            'rename',
            'copy',
            'file_put_contents',
            'fwrite',
            'fputs',
            'ftruncate',
            'move_uploaded_file',
            'wp_delete_file',
            'wp_delete_file_from_directory',
            'unzip_file',
            'copy_dir',
            'move_dir',
            'download_url',
            'symlink',
            'chmod',
        ],
        'medium:scheduled' => ['wp_schedule_event', 'wp_schedule_single_event', 'as_schedule_single_action'],
        'unverified:direct_database' => ['mysqli_query', 'mysqli_real_query', 'mysqli_multi_query', 'mysql_query'],
        'low:triggers_hooks' => [
            'do_action',
            'apply_filters',
            'wp_insert_post',
            'wp_update_post',
            'update_post_meta',
            'add_post_meta',
            'delete_post_meta',
            'update_term_meta',
            'update_user_meta',
        ],
    ];
    $trash_on = trash_is_on();
    foreach ($groups as $rule => $names) {
        $rule = $rule === 'medium:trash' && !$trash_on ? 'medium:deletes_content' : $rule;
        foreach ($names as $name) {
            $rules[$name] = $rule;
        }
    }
    return $rules;
}

/** Options whose change can take the whole site down or change who controls it. */
function critical_option(string $name): bool
{
    return (
        in_array(
            $name,
            [
                'active_plugins',
                'active_sitewide_plugins',
                'siteurl',
                'home',
                'template',
                'stylesheet',
                'default_role',
                'users_can_register',
                'admin_email',
            ],
            strict: true,
        )
        || str_ends_with($name, 'user_roles')
    );
}

/** @return list<string> Rules for the SQL in one string, where the string's text is known. */
function sql_rules(string $sql): array
{
    $rules = [];
    // Deleting rows of the users table directly deletes users, like wp_delete_user: it always asks.
    if (
        preg_match(
            '/\b(?:DELETE\s+(?:\w+\s+)?FROM|TRUNCATE(?:\s+TABLE)?|DROP\s+TABLE(?:\s+IF\s+EXISTS)?)\s+`?(?:\w*_)?users`?(?!\w)/i',
            $sql,
        ) === 1
    ) {
        $rules[] = 'high:deletes_user';
    }
    if (preg_match('/\b(?:DROP|TRUNCATE|ALTER)\s+TABLE\b|\bTRUNCATE\b/i', $sql) === 1) {
        $rules[] = 'high:table_structure';
    }
    if (preg_match('/\b(?:DELETE\s+FROM|UPDATE\s+\S+\s+SET)\b/i', $sql) !== 1) {
        return $rules;
    }
    $broad =
        preg_match('/\bWHERE\b/i', $sql) !== 1
        || preg_match('/\bWHERE\s+(?:1\s*=\s*1|TRUE)\b/i', $sql) === 1
        || preg_match('/\bLIKE\s+[\'"]%/i', $sql) === 1;
    $rules[] = $broad ? 'high:broad_sql_write' : 'medium:sql_write';
    return $rules;
}

function is_loop(Node $node): bool
{
    return (
        $node instanceof Node\Stmt\Foreach_
        || $node instanceof Node\Stmt\For_
        || $node instanceof Node\Stmt\While_
        || $node instanceof Node\Stmt\Do_
    );
}

/**
 * A file of WordPress itself, loaded once with its path written out: require_once ABSPATH .
 * 'wp-admin/includes/plugin.php', ABSPATH . WPINC . '/x.php', ABSPATH . 'wp-load.php'. Loading one only makes
 * WordPress functions available, as the code would get them in an admin request. Only the _once forms count: loading
 * a file twice ends in a fatal error. Files of wp-includes that act as soon as they load are left out.
 */
function wordpress_file(Node\Expr\Include_ $include): bool
{
    if (
        $include->type !== Node\Expr\Include_::TYPE_REQUIRE_ONCE
        && $include->type !== Node\Expr\Include_::TYPE_INCLUDE_ONCE
    ) {
        return false;
    }
    $parts = path_parts($include->expr);
    if ($parts === null || ($parts[0] ?? null) !== 'ABSPATH') {
        return false;
    }
    $rest = ltrim(implode('', array_slice($parts, offset: 1)), characters: '/');
    if ($rest === 'wp-load.php') {
        return true;
    }
    $match = [];
    if (preg_match('#^(?:wp-admin/includes|wp-includes|WPINC)/([A-Za-z0-9_-]+\.php)$#', $rest, $match) !== 1) {
        return false;
    }
    return !in_array($match[1], ['ms-files.php', 'ms-settings.php', 'template-loader.php'], strict: true);
}

/**
 * The pieces of a path written out: string literals and the ABSPATH and WPINC constants, nothing built while it runs.
 *
 * @return list<string>|null
 */
function path_parts(Node\Expr $path): ?array
{
    if ($path instanceof Node\Scalar\String_) {
        return [$path->value];
    }
    if ($path instanceof Node\Expr\ConstFetch) {
        $name = $path->name->toString();
        return in_array($name, ['ABSPATH', 'WPINC'], strict: true) ? [$name] : null;
    }
    if ($path instanceof Node\Expr\BinaryOp\Concat) {
        $left = path_parts($path->left);
        $right = path_parts($path->right);
        return $left === null || $right === null ? null : [...$left, ...$right];
    }
    return null;
}

/** @return list<string> Rules for one node of the code. */
function node_rules(Node $node): array
{
    if ($node instanceof Node\Expr\Eval_) {
        return ['refuse:eval'];
    }
    if ($node instanceof Node\Expr\Include_) {
        return wordpress_file($node) ? [] : ['refuse:include'];
    }
    if ($node instanceof Node\Expr\ShellExec) {
        return ['refuse:shell'];
    }
    if ($node instanceof Node\Expr\FuncCall) {
        return function_call_rules($node);
    }
    if ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall) {
        return method_call_rules($node);
    }
    if ($node instanceof Node\Expr\StaticCall) {
        return static_call_rules($node);
    }
    if ($node instanceof Node\Expr\New_) {
        return new_object_rules($node);
    }
    return string_rules($node);
}

/** @return list<string> */
function string_rules(Node $node): array
{
    if ($node instanceof Node\Scalar\String_) {
        return sql_rules($node->value);
    }
    if ($node instanceof Node\Scalar\InterpolatedString) {
        return sql_rules(interpolated_text($node));
    }
    return [];
}

/** Functions that write an option, its name first. */
const OPTION_WRITERS = [
    'update_option',
    'delete_option',
    'add_option',
    'update_site_option',
    'delete_site_option',
    'add_site_option',
    'update_network_option',
    'delete_network_option',
    'add_network_option',
];

/** @return list<string> */
function function_call_rules(Node\Expr\FuncCall $node): array
{
    if (!$node->name instanceof Node\Name) {
        return ['refuse:dynamic_call'];
    }
    $name = strtolower($node->name->toString());
    if (str_starts_with($name, 'novamira\\journal\\') || str_starts_with($name, 'novamira\\confirmations\\')) {
        return ['refuse:novamira_internals'];
    }
    $hook_rules = hook_call_rules($node, $name);
    if ($hook_rules === ['refuse:novamira_internals']) {
        return $hook_rules;
    }
    $callback_rules = callbacks_by_name($node, $name);
    if (in_array('refuse:novamira_internals', $callback_rules, strict: true)) {
        return ['refuse:novamira_internals'];
    }
    $rules = [...$callback_rules, ...$hook_rules];
    if (in_array($name, OPTION_WRITERS, strict: true)) {
        $option_rules = option_call_rules($name, $node);
        return $option_rules === ['refuse:novamira_internals'] ? $option_rules : [...$rules, ...$option_rules];
    }
    // Turning Novamira off from its own code cuts the AI off this site.
    if (
        in_array($name, ['deactivate_plugins', 'delete_plugins', 'uninstall_plugin'], strict: true)
        && names_novamira($node)
    ) {
        $rules[] = 'access:novamira_off';
    }
    $rule = function_rules()[$name] ?? null;
    if ($rule !== null) {
        $rules[] = $rule;
    }
    return [...$rules, ...destination_rules($node, $name)];
}

/** @return list<string> */
function method_call_rules(Node\Expr\MethodCall|Node\Expr\NullsafeMethodCall $node): array
{
    if (!$node->name instanceof Node\Identifier) {
        return ['refuse:dynamic_call'];
    }
    $method = strtolower($node->name->toString());
    if (in_array($method, ['set_role', 'add_role', 'remove_role', 'add_cap', 'remove_cap'], strict: true)) {
        return ['high:users_and_roles'];
    }
    if ($method === 'update_status') {
        return ['high:order_status'];
    }
    if (in_array($method, ['payment_complete', 'process_payment', 'process_refund'], strict: true)) {
        return ['money:payment'];
    }
    if (is_global_object($node->var, name: 'wp_filesystem')) {
        return in_array($method, FILESYSTEM_WRITES, strict: true) ? ['medium:files'] : [];
    }
    if (is_wpdb($node->var)) {
        return wpdb_method_rules($method, $node->args[0] ?? null);
    }
    $rule = OBJECT_METHOD_RULES[$method] ?? null;
    return $rule === null ? [] : [$rule];
}

/** @return list<string> Rules for a $wpdb method, given its first argument. */
function wpdb_method_rules(string $method, mixed $first): array
{
    if (!in_array($method, ['query', 'insert', 'update', 'delete', 'replace'], strict: true)) {
        return [];
    }
    if ($method === 'query') {
        return ['low:wpdb_query'];
    }
    // $wpdb->delete() on the users table deletes users, like wp_delete_user: it always asks.
    if ($method === 'delete' && $first instanceof Node\Arg && names_users_table($first->value)) {
        return ['medium:wpdb_write', 'high:deletes_user'];
    }
    return ['medium:wpdb_write'];
}

/** Whether a table argument is the users table: $wpdb->users or a literal name ending in "_users". */
function names_users_table(Node\Expr $table): bool
{
    if (is_users_table($table)) {
        return true;
    }
    return $table instanceof Node\Scalar\String_ && preg_match('/(?:^|_)users$/', $table->value) === 1;
}

/** @return list<string> */
function new_object_rules(Node\Expr\New_ $node): array
{
    if ($node->class instanceof Node\Stmt\Class_) {
        return [];
    }
    if (!$node->class instanceof Node\Name) {
        return ['refuse:dynamic_call'];
    }
    $class = strtolower(ltrim($node->class->toString(), characters: '\\'));
    if (in_array($class, ['mysqli', 'pdo'], strict: true)) {
        return ['unverified:direct_database'];
    }
    if (in_array($class, ['reflectionfunction', 'reflectionmethod', 'reflectionclass'], strict: true)) {
        return ['refuse:runs_other_code'];
    }
    if ($class === 'core_upgrader') {
        return ['high:core_update'];
    }
    if (in_array($class, UPGRADER_CLASSES, strict: true)) {
        return ['high:plugins_and_themes'];
    }
    return [];
}

/** Upgraders install or update plugins, themes and translations. */
const UPGRADER_CLASSES = [
    'plugin_upgrader',
    'theme_upgrader',
    'language_pack_upgrader',
    'wp_upgrader',
    'wp_automatic_updater',
];

/** Methods of WP_Filesystem that change files. */
const FILESYSTEM_WRITES = ['delete', 'rmdir', 'move', 'copy', 'put_contents', 'mkdir', 'chmod', 'chown', 'touch'];

/**
 * Methods whose name says what they do on any object: WooCommerce orders, products and coupons, upgraders, session
 * tokens. The object's class is not known from the code, so the name alone decides.
 */
const OBJECT_METHOD_RULES = [
    'delete' => 'medium:object_delete',
    'trash' => 'medium:object_delete',
    'delete_permanently' => 'medium:object_delete',
    'save' => 'medium:object_save',
    'install' => 'high:plugins_and_themes',
    'upgrade' => 'high:plugins_and_themes',
    'bulk_upgrade' => 'high:plugins_and_themes',
    // Every link on the site changes, and links shared elsewhere stop working.
    'set_permalink_structure' => 'max:permalinks',
    'set_category_base' => 'max:permalinks',
    'set_tag_base' => 'max:permalinks',
    'destroy_all' => 'high:sign_in',
    'destroy_others' => 'high:sign_in',
];

/** Static methods that change who can sign in. Lower-case Class::method => rule. */
const STATIC_METHOD_RULES = [
    // @mago-expect lint:no-literal-password -- A method name, not a password.
    'wp_application_passwords::create_new_application_password' => 'high:sign_in',
    // @mago-expect lint:no-literal-password -- A method name, not a password.
    'wp_application_passwords::delete_application_password' => 'high:sign_in',
    'wp_application_passwords::delete_all_application_passwords' => 'high:sign_in',
    'wp_session_tokens::destroy_all_for_all_users' => 'high:sign_in',
];

/** @return list<string> */
function static_call_rules(Node\Expr\StaticCall $node): array
{
    if (!$node->name instanceof Node\Identifier || !$node->class instanceof Node\Name) {
        return ['refuse:dynamic_call'];
    }
    $key = strtolower(ltrim($node->class->toString(), characters: '\\')) . '::' . strtolower($node->name->toString());
    if (str_starts_with($key, 'novamira\\journal\\') || str_starts_with($key, 'novamira\\confirmations\\')) {
        return ['refuse:novamira_internals'];
    }
    $rule = STATIC_METHOD_RULES[$key] ?? null;
    return $rule === null ? [] : [$rule];
}

/**
 * The closures given to array_map() and the like: they run once per item, as the body of a loop does.
 *
 * @return list<Node\Expr\Closure|Node\Expr\ArrowFunction>
 */
function loop_bodies(Node $node): array
{
    if (
        !$node instanceof Node\Expr\FuncCall
        || !$node->name instanceof Node\Name
        || !in_array(strtolower($node->name->toString()), ITERATING_FUNCTIONS, strict: true)
    ) {
        return [];
    }
    $bodies = [];
    foreach ($node->args as $arg) {
        if (!$arg instanceof Node\Arg) {
            continue;
        }
        if ($arg->value instanceof Node\Expr\Closure || $arg->value instanceof Node\Expr\ArrowFunction) {
            $bodies[] = $arg->value;
        }
    }
    return $bodies;
}

/** Whether the expression is the global variable $name, written as $name or $GLOBALS['name']. */
function is_global_object(Node\Expr $var, string $name): bool
{
    if ($var instanceof Node\Expr\Variable) {
        return $var->name === $name;
    }
    return (
        $var instanceof Node\Expr\ArrayDimFetch
        && $var->var instanceof Node\Expr\Variable
        && $var->var->name === 'GLOBALS'
        && $var->dim instanceof Node\Scalar\String_
        && $var->dim->value === $name
    );
}

/** The hooks Novamira's recording and approvals hang on: code that takes them off would hide what it does. */
const NOVAMIRA_HOOKS = ['query', 'pre_wp_mail', 'pre_http_request', 'schedule_event', 'shutdown'];

/**
 * Hooks the AI's code adds or removes. Code hooked to shutdown runs after the call, outside the AI change history;
 * taking Novamira's own hooks off is refused like any other change to Novamira.
 *
 * @return list<string>
 */
function hook_call_rules(Node\Expr\FuncCall $node, string $name): array
{
    $hook = literal_argument($node, position: 0);
    if ($hook === null) {
        return [];
    }
    $hook = strtolower($hook);
    if (in_array($name, ['add_action', 'add_filter'], strict: true)) {
        return $hook === 'shutdown' ? ['medium:runs_later'] : [];
    }
    $removes_one = in_array($name, ['remove_action', 'remove_filter'], strict: true);
    $removes_all = in_array($name, ['remove_all_actions', 'remove_all_filters'], strict: true);
    if (!$removes_one && !$removes_all || !in_array($hook, NOVAMIRA_HOOKS, strict: true)) {
        return [];
    }
    // Other plugins hang their own work on these hooks too (wp_ob_end_flush_all on shutdown, a mock on
    // pre_http_request): taking one of those off is a change like any other. Taking them all off, or one of
    // Novamira's, is refused.
    if ($removes_one && !names_novamira($node)) {
        return ['medium:removes_hooks'];
    }
    return ['refuse:novamira_internals'];
}

function is_wpdb(Node\Expr $var): bool
{
    if ($var instanceof Node\Expr\Variable) {
        return $var->name === 'wpdb';
    }
    return (
        $var instanceof Node\Expr\ArrayDimFetch
        && $var->var instanceof Node\Expr\Variable
        && $var->var->name === 'GLOBALS'
        && $var->dim instanceof Node\Scalar\String_
        && $var->dim->value === 'wpdb'
    );
}

/**
 * A function passed to an argument that takes a callback, as in array_map('wp_delete_post', $ids), runs as if the
 * code called it: it gets the same rules, and array_map() and the like call it once per item. Only the arguments
 * that take a callback count: a text such as the meta key in get_post_meta(1, 'system') is not a call. A callback the
 * check cannot name (a variable, a method of an object it does not know, arguments spread from a variable) counts as a medium change.
 *
 * @return list<string>
 */
function callbacks_by_name(Node\Expr\FuncCall $node, string $name): array
{
    $slots = CALLBACK_ARGUMENTS[$name] ?? null;
    if ($slots === null || $node->isFirstClassCallable()) {
        return [];
    }
    $repeats = in_array($name, ITERATING_FUNCTIONS, strict: true);
    $args = call_arguments($node);
    // Arguments spread from a variable: which of them is the callback is not known.
    $rules = $args === null ? ['medium:unknown_callback'] : found_callback_rules($args, $slots, $name);
    return array_map(static fn(string $rule): string => $repeats ? repeated_rule($rule) : $rule, $rules);
}

/**
 * @param array<int|string, Node\Expr> $args
 * @param list<int|string> $slots
 * @return list<string>
 */
function found_callback_rules(array $args, array $slots, string $name): array
{
    $data = callback_data_texts($args, $name);
    $rules = [];
    foreach (callback_values($args, $slots) as $value) {
        $rules = [...$rules, ...one_callback_rules($value, $data)];
    }
    return $rules;
}

/**
 * Where each function takes a callback: a zero-based position, "last" for the last positional argument,
 * "values:N" for the values of the array at N (preg_replace_callback_array), "keys:N" for the callback keys of the
 * array at N (register_rest_route). A named argument called callback counts for every function here.
 */
const CALLBACK_ARGUMENTS = [
    'array_map' => [0],
    'array_walk' => [1],
    'array_walk_recursive' => [1],
    'array_filter' => [1],
    'array_reduce' => [1],
    'usort' => [1],
    'uasort' => [1],
    'uksort' => [1],
    'iterator_apply' => [1],
    'preg_replace_callback' => [1],
    'preg_replace_callback_array' => ['values:0'],
    'array_udiff' => ['last'],
    'array_udiff_assoc' => ['last'],
    'array_udiff_uassoc' => ['last'],
    'array_uintersect' => ['last'],
    'array_uintersect_assoc' => ['last'],
    'array_uintersect_uassoc' => ['last'],
    'array_diff_ukey' => ['last'],
    'array_diff_uassoc' => ['last'],
    'array_intersect_ukey' => ['last'],
    'array_intersect_uassoc' => ['last'],
    'ob_start' => [0],
    'add_action' => [1],
    'add_filter' => [1],
    'add_shortcode' => [1],
    'register_activation_hook' => [1],
    'register_deactivation_hook' => [1],
    'register_uninstall_hook' => [1],
    'register_rest_route' => ['keys:2'],
    'register_shutdown_function' => [0],
    'register_tick_function' => [0],
    'spl_autoload_register' => [0],
    'set_error_handler' => [0],
    'set_exception_handler' => [0],
];

/** Names a callback argument goes by when the code names it. */
const CALLBACK_ARGUMENT_NAMES = ['callback', 'error_handler', 'handler'];

/**
 * The call's arguments by position, with named ones under their lower-case name. An array written out and spread
 * (...['unlink', $files]) counts as its items; arguments spread from anything else give null.
 *
 * @return array<int|string, Node\Expr>|null
 */
function call_arguments(Node\Expr\FuncCall $node): ?array
{
    $args = [];
    foreach ($node->args as $arg) {
        if (!$arg instanceof Node\Arg) {
            continue;
        }
        if ($arg->name !== null) {
            $args[strtolower($arg->name->toString())] = $arg->value;
            continue;
        }
        if (!$arg->unpack) {
            $args[] = $arg->value;
            continue;
        }
        if (!$arg->value instanceof Node\Expr\Array_) {
            return null;
        }
        foreach ($arg->value->items as $item) {
            if ($item->unpack || $item->key !== null) {
                return null;
            }
            $args[] = $item->value;
        }
    }
    return $args;
}

/**
 * The expressions that are callbacks, given the slots of CALLBACK_ARGUMENTS.
 *
 * @param array<int|string, Node\Expr> $args
 * @param list<int|string> $slots
 * @return list<Node\Expr>
 */
function callback_values(array $args, array $slots): array
{
    $values = [];
    foreach (CALLBACK_ARGUMENT_NAMES as $name) {
        $named = $args[$name] ?? null;
        if ($named === null) {
            continue;
        }
        $values[] = $named;
    }
    $positional = array_values(array_filter(
        $args,
        static fn(int|string $key): bool => is_int($key),
        ARRAY_FILTER_USE_KEY,
    ));
    foreach ($slots as $slot) {
        if (is_int($slot)) {
            $at_slot = $positional[$slot] ?? null;
            $values = [...$values, ...($at_slot === null ? [] : [$at_slot])];
            continue;
        }
        if ($slot === 'last') {
            $values = [...$values, ...($positional === [] ? [] : [$positional[count($positional) - 1]])];
            continue;
        }
        [$kind, $at] = explode(':', $slot);
        $array = $positional[(int) $at] ?? null;
        $values = [...$values, ...($kind === 'keys' ? callback_keys_in_array($array) : values_in_array($array))];
    }
    return $values;
}

/**
 * Every value of an array argument as a callback (preg_replace_callback_array). Anything other than an array written
 * out counts as one callback the check cannot name.
 *
 * @return list<Node\Expr>
 */
function values_in_array(?Node\Expr $array): array
{
    if ($array === null) {
        return [];
    }
    if (!$array instanceof Node\Expr\Array_) {
        return [$array];
    }
    return array_values(array_map(static fn(Node\ArrayItem $item): Node\Expr => $item->value, $array->items));
}

/**
 * The values under the callback keys of an array argument, also in a list of such arrays (register_rest_route).
 *
 * @return list<Node\Expr>
 */
function callback_keys_in_array(?Node\Expr $array): array
{
    if ($array === null) {
        return [];
    }
    if (!$array instanceof Node\Expr\Array_) {
        return [$array];
    }
    $values = [];
    foreach ($array->items as $item) {
        if ($item->value instanceof Node\Expr\Array_ && $item->key === null) {
            $values = [...$values, ...callback_keys_in_array($item->value)];
            continue;
        }
        $key = $item->key instanceof Node\Scalar\String_ ? strtolower($item->key->value) : '';
        if (!in_array($key, ['callback', 'permission_callback'], strict: true)) {
            continue;
        }
        $values[] = $item->value;
    }
    return $values;
}

/**
 * The texts the callback receives as data, where they are written out: the first array array_map() goes through, the
 * array array_walk(), array_filter() and the like go through. Nothing for hooks: what a hook passes is not known.
 *
 * @param array<int|string, Node\Expr> $args
 * @return list<string>
 */
function callback_data_texts(array $args, string $name): array
{
    $position = match ($name) {
        'array_map' => 1,
        'array_walk', 'array_walk_recursive', 'array_filter', 'array_reduce', 'iterator_apply' => 0,
        default => null,
    };
    $value = $position === null ? null : $args[$position] ?? null;
    if ($value instanceof Node\Scalar\String_) {
        return [$value->value];
    }
    if (!$value instanceof Node\Expr\Array_) {
        return [];
    }
    $texts = [];
    foreach ($value->items as $item) {
        if (!$item->value instanceof Node\Scalar\String_) {
            continue;
        }
        $texts[] = $item->value->value;
    }
    return $texts;
}

/**
 * The rules of one callback expression.
 *
 * @param list<string> $data
 * @return list<string>
 */
function one_callback_rules(Node\Expr $value, array $data): array
{
    // A closure's own code is read where it is written.
    if ($value instanceof Node\Expr\Closure || $value instanceof Node\Expr\ArrowFunction) {
        return [];
    }
    if ($value instanceof Node\Expr\ConstFetch && strtolower($value->name->toString()) === 'null') {
        return [];
    }
    $names = callback_names($value);
    if ($names === null) {
        return ['medium:unknown_callback'];
    }
    $rules = [];
    foreach ($names as $callback) {
        $rules = [...$rules, ...callback_rules($callback, $data)];
    }
    return $rules;
}

/**
 * The function a callback names when it is written out: 'unlink', wp_delete_post(...), or ['Class', 'method'] as
 * Class::method. A method of an object counts by the method's name, as a direct call does. Null when the callback
 * cannot be named.
 *
 * @return list<string>|null
 */
function callback_names(Node\Expr $value): ?array
{
    if ($value instanceof Node\Scalar\String_) {
        return [strtolower(ltrim($value->value, characters: '\\'))];
    }
    if ($value instanceof Node\Expr\FuncCall && $value->isFirstClassCallable() && $value->name instanceof Node\Name) {
        return [strtolower(ltrim($value->name->toString(), characters: '\\'))];
    }
    if (!$value instanceof Node\Expr\Array_ || count($value->items) !== 2) {
        return null;
    }
    $class = $value->items[0]->value;
    $method = $value->items[1]->value;
    if (!$method instanceof Node\Scalar\String_) {
        return null;
    }
    $method_name = strtolower($method->value);
    if ($class instanceof Node\Scalar\String_) {
        return [strtolower(ltrim($class->value, characters: '\\')) . '::' . $method_name];
    }
    $rule = OBJECT_METHOD_RULES[$method_name] ?? null;
    return $rule === null ? null : ['->' . $method_name];
}

/**
 * The rules of one function given as a callback, the same as for a direct call, with the texts it receives as data
 * standing in for its arguments.
 *
 * @param list<string> $data
 * @return list<string>
 */
function callback_rules(string $callback, array $data): array
{
    if (str_starts_with($callback, 'novamira\\journal\\') || str_starts_with($callback, 'novamira\\confirmations\\')) {
        return ['refuse:novamira_internals'];
    }
    if (str_starts_with($callback, '->')) {
        return [OBJECT_METHOD_RULES[substr($callback, offset: 2)] ?? 'medium:unknown_callback'];
    }
    $rules = [];
    if (in_array($callback, OPTION_WRITERS, strict: true)) {
        $rules = option_callback_rules($data);
    }
    $names_novamira = array_filter(
        $data,
        static fn(string $text): bool => stripos($text, needle: 'novamira') !== false,
    );
    if (
        in_array($callback, ['deactivate_plugins', 'delete_plugins', 'uninstall_plugin'], strict: true)
        && $names_novamira !== []
    ) {
        $rules[] = 'access:novamira_off';
    }
    $rule = function_rules()[$callback] ?? STATIC_METHOD_RULES[$callback] ?? null;
    if ($rule !== null && !str_starts_with($rule, 'low:')) {
        $rules[] = $rule;
    }
    // A request function given by name sends to addresses only known once it runs.
    if (array_key_exists($callback, DESTINATION_ARGUMENTS)) {
        $rules[] = 'send:?';
    }
    return $rules;
}

/**
 * update_option() and the like given as a callback: the option names are the texts it receives as data.
 *
 * @param list<string> $data
 * @return list<string>
 */
function option_callback_rules(array $data): array
{
    $names = array_map(static fn(string $text): string => strtolower($text), $data);
    foreach ($names as $name) {
        if (str_starts_with($name, 'novamira_')) {
            return ['refuse:novamira_internals'];
        }
    }
    $rules = [];
    foreach ($names as $name) {
        if (in_array($name, PERMALINK_OPTIONS, strict: true)) {
            $rules[] = 'max:permalinks';
        }
        if (in_array($name, ['siteurl', 'home'], strict: true)) {
            $rules[] = 'access:site_address';
        }
        if (critical_option($name)) {
            $rules[] = 'high:critical_option';
        }
    }
    return $rules === [] ? ['medium:option'] : $rules;
}

/** Functions that call the callback they are given once per item. */
const ITERATING_FUNCTIONS = [
    'array_map',
    'array_walk',
    'array_walk_recursive',
    'array_filter',
    'array_reduce',
    'usort',
    'uasort',
    'uksort',
    'iterator_apply',
];

/** @return list<string> */
function option_call_rules(string $name, Node\Expr\FuncCall $node): array
{
    // The network functions take the network first and the option's name second.
    $option = literal_argument($node, position: str_contains($name, 'network') ? 1 : 0);
    // Novamira's options hold its approval settings and requests: the AI's code never changes them.
    if ($option !== null && str_starts_with(strtolower($option), 'novamira_')) {
        return ['refuse:novamira_internals'];
    }
    if ($option !== null && in_array(strtolower($option), PERMALINK_OPTIONS, strict: true)) {
        return ['max:permalinks'];
    }
    $rule = $option !== null && critical_option($option) ? 'high:critical_option' : 'medium:option';
    return [$rule, ...access_rules_for_option($node, (string) $option)];
}

/**
 * Changing the site's address, or the list of active plugins to one without Novamira, can cut the AI off this site:
 * Novamira is tied to the address, and an inactive plugin answers nothing.
 *
 * @return list<string>
 */
function access_rules_for_option(Node\Expr\FuncCall $node, string $option): array
{
    if (in_array($option, ['siteurl', 'home'], strict: true)) {
        return ['access:site_address'];
    }
    if (!in_array($option, ['active_plugins', 'active_sitewide_plugins'], strict: true)) {
        return [];
    }
    $value = $node->args[1] ?? null;
    // Only a list written out without Novamira in it is sure to turn it off; a list built from the current one is not.
    return (
        $value instanceof Node\Arg && $value->value instanceof Node\Expr\Array_ && !names_novamira($node)
            ? ['access:novamira_off']
            : []
    );
}

/** Whether a literal text in the call's arguments, or in an array among them, names Novamira. */
function names_novamira(Node\Expr\FuncCall $node): bool
{
    foreach (literal_texts($node) as $text) {
        if (stripos($text, needle: 'novamira') !== false) {
            return true;
        }
    }
    return false;
}

/**
 * The literal texts in a call's arguments, and in arrays among them.
 *
 * @return list<string>
 */
function literal_texts(Node\Expr\FuncCall $node): array
{
    $texts = [];
    foreach ($node->args as $arg) {
        if (!$arg instanceof Node\Arg) {
            continue;
        }
        $values = $arg->value instanceof Node\Expr\Array_
            ? array_map(static fn(?Node\ArrayItem $item): ?Node\Expr => $item?->value, $arg->value->items)
            : [$arg->value];
        foreach ($values as $value) {
            if (!$value instanceof Node\Scalar\String_) {
                continue;
            }
            $texts[] = $value->value;
        }
    }
    return $texts;
}

function literal_argument(Node\Expr\FuncCall $node, int $position): ?string
{
    $arg = $node->args[$position] ?? null;
    if (!$arg instanceof Node\Arg || !$arg->value instanceof Node\Scalar\String_) {
        return null;
    }
    return $arg->value->value;
}

/** The literal text of an interpolated string, with each interpolated part replaced by a placeholder. */
function interpolated_text(Node\Scalar\InterpolatedString $node): string
{
    $text = '';
    foreach ($node->parts as $part) {
        $text .= $part instanceof Node\InterpolatedStringPart ? $part->value : text_placeholder($part);
    }
    return $text;
}

/** The text of a concatenation: literal strings kept, every other part replaced by a placeholder. */
function concat_text(Node\Expr $node): string
{
    return match (true) {
        $node instanceof Node\Expr\BinaryOp\Concat => concat_text($node->left) . concat_text($node->right),
        $node instanceof Node\Scalar\String_ => $node->value,
        $node instanceof Node\Scalar\InterpolatedString => interpolated_text($node),
        default => text_placeholder($node),
    };
}

/**
 * The stand-in for an unknown part of a string: $wpdb->users and the table prefix keep a table-like name, so a write
 * on the users table is still recognized; any other part is "x".
 */
function text_placeholder(Node $node): string
{
    if (is_users_table($node)) {
        return 'wp_users';
    }
    $is_prefix =
        $node instanceof Node\Expr\PropertyFetch
        && is_wpdb($node->var)
        && $node->name instanceof Node\Identifier
        && in_array($node->name->toString(), ['prefix', 'base_prefix'], strict: true);
    return $is_prefix ? 'wp_' : 'x';
}

/** Whether a node is $wpdb->users, the table of the site's users. */
function is_users_table(Node $node): bool
{
    return (
        $node instanceof Node\Expr\PropertyFetch
        && is_wpdb($node->var)
        && $node->name instanceof Node\Identifier
        && $node->name->toString() === 'users'
    );
}

/**
 * Write statements in the code whose text is fully known: $wpdb->query() with a literal string whose only
 * variable parts are $wpdb table names. These can be counted on the site before the code runs. At most five.
 *
 * @return list<string>
 */
function known_writes(string $code): array
{
    require_once __DIR__ . '/analysis-visitor.php';
    try {
        $ast = (new ParserFactory())
            ->createForHostVersion()
            ->parse('<?php ' . $code);
    } catch (\Throwable) {
        return [];
    }
    $found = [];
    foreach ((new NodeFinder())->findInstanceOf($ast ?? [], Node\Expr\MethodCall::class) as $call) {
        $arg = $call->args[0] ?? null;
        if (
            !is_wpdb($call->var)
            || !$call->name instanceof Node\Identifier
            || $call->name->toString() !== 'query'
            || !$arg instanceof Node\Arg
        ) {
            continue;
        }
        $sql = resolved_text($arg->value);
        if ($sql !== null && preg_match('/^\s*(?:DELETE|UPDATE|INSERT|REPLACE|TRUNCATE|DROP|ALTER)\b/i', $sql) === 1) {
            $found[] = $sql;
        }
    }
    return array_slice($found, offset: 0, length: 5);
}

/** The text of a string whose only variable parts are $wpdb table names, or null when any part is unknown. */
function resolved_text(Node $node): ?string
{
    if ($node instanceof Node\Scalar\String_) {
        return $node->value;
    }
    if ($node instanceof Node\InterpolatedStringPart) {
        return $node->value;
    }
    if ($node instanceof Node\Expr\PropertyFetch) {
        return wpdb_property($node);
    }
    if ($node instanceof Node\Expr\BinaryOp\Concat) {
        $left = resolved_text($node->left);
        $right = resolved_text($node->right);
        return $left === null || $right === null ? null : $left . $right;
    }
    if (!$node instanceof Node\Scalar\InterpolatedString) {
        return null;
    }
    $text = '';
    foreach ($node->parts as $part) {
        $resolved = resolved_text($part);
        if ($resolved === null) {
            return null;
        }
        $text .= $resolved;
    }
    return $text;
}

/** The value of $wpdb->posts, $wpdb->prefix and the like on this site. */
function wpdb_property(Node\Expr\PropertyFetch $node): ?string
{
    if (!is_wpdb($node->var) || !$node->name instanceof Node\Identifier) {
        return null;
    }
    // @mago-expect analysis:mixed-assignment -- Any public property of $wpdb; only table-name strings are used.
    $value = get_object_vars(database())[$node->name->toString()] ?? null;
    return is_string($value) && preg_match('/^[A-Za-z0-9_]+$/', $value) === 1 ? $value : null;
}

/**
 * What the known writes of an execute-php call would change on the site right now, one line each.
 *
 * @param array<array-key, mixed> $args
 * @return list<string>
 */
function measured_writes(array $args): array
{
    return array_map(static fn(array $write): string => $write['line'], measured_details($args));
}

/**
 * The known writes of an execute-php call, with the rows each would change: null when they could not be counted.
 *
 * @param array<array-key, mixed> $args
 * @return list<array{line: string, rows: ?int}>
 */
function measured_details(array $args): array
{
    if (
        ($args['ability_name'] ?? null) !== 'novamira/execute-php'
        || !function_exists('Novamira\\Journal\\measure_sql')
    ) {
        return [];
    }
    $parameters = is_array($args['parameters'] ?? null) ? $args['parameters'] : [];
    $code = is_string($parameters['code'] ?? null) ? $parameters['code'] : '';
    $lines = [];
    foreach (known_writes($code) as $sql) {
        $measured = \Novamira\Journal\measure_sql($sql);
        if ($measured === null) {
            $lines[] = [
                'line' => 'A write Novamira could not count: ' . mb_substr($sql, start: 0, length: 120),
                'rows' => null,
            ];
            continue;
        }
        $lines[] = [
            'line' => \Novamira\Journal\measured_sentence($sql, $measured),
            'rows' => $measured['capped'] ? null : $measured['rows'],
        ];
    }
    return $lines;
}

/**
 * Functions that send a request, with the position of the address. A call to one of them is a "send:" finding: the
 * host it sends to, or "?" when the address is known only once the code runs.
 */
const DESTINATION_ARGUMENTS = [
    'wp_remote_get' => 0,
    'wp_remote_post' => 0,
    'wp_remote_head' => 0,
    'wp_remote_request' => 0,
    'wp_safe_remote_get' => 0,
    'wp_safe_remote_post' => 0,
    'wp_safe_remote_head' => 0,
    'wp_safe_remote_request' => 0,
    'download_url' => 0,
    'curl_init' => 0,
    'fsockopen' => 0,
    'stream_socket_client' => 0,
];

/** Functions that read a local file or an address: they count only when an address is written in the code. */
const URL_READERS = ['file_get_contents', 'fopen', 'file', 'readfile'];

/** An address read by a function that also reads local files: only with a web scheme is it an address. */
const WEB_SCHEME = '(?:https?|ftps?)://';

/** An address given to a function that only sends requests: the scheme may be any, or none (fsockopen). */
const ANY_SCHEME = '(?:[a-z][a-z0-9+.-]*://)?';

/**
 * Where the code sends a request. A host in the address is enough, even when the rest of it is built at runtime: a
 * GET carries data in its address as a POST does in its body.
 *
 * @return list<string>
 */
function destination_rules(Node\Expr\FuncCall $node, string $name): array
{
    if (in_array($name, URL_READERS, strict: true)) {
        $arg = $node->args[0] ?? null;
        $host = $arg instanceof Node\Arg ? address_host($arg->value, WEB_SCHEME) : null;
        return $host === null ? [] : ['send:' . $host];
    }
    if ($name === 'curl_setopt_array') {
        return ['send:?'];
    }
    $position = destination_position($node, $name);
    if ($position === null) {
        return [];
    }
    $arg = $node->args[$position] ?? null;
    if (!$arg instanceof Node\Arg) {
        // curl_init() without an address: the address comes with curl_setopt().
        return $name === 'curl_init' ? [] : ['send:?'];
    }
    return ['send:' . (address_host($arg->value, ANY_SCHEME) ?? '?')];
}

/** Where the address is among a request function's arguments, or null when the call sends nothing. */
function destination_position(Node\Expr\FuncCall $node, string $name): ?int
{
    if ($name !== 'curl_setopt') {
        return DESTINATION_ARGUMENTS[$name] ?? null;
    }
    $option = $node->args[1] ?? null;
    $is_url =
        $option instanceof Node\Arg
        && $option->value instanceof Node\Expr\ConstFetch
        && strtoupper($option->value->name->toString()) === 'CURLOPT_URL';
    return $is_url ? 2 : null;
}

/**
 * The host an address written in the code goes to, or null when the host itself is known only once the code runs.
 * "https://api.example.com/track?email=" . $email is api.example.com.
 */
function address_host(Node\Expr $address, string $scheme): ?string
{
    [$text, $complete] = literal_prefix($address, depth: 0);
    // With a scheme written, the host comes after it: "https://" . $host is not the host "https".
    if ($scheme === ANY_SCHEME && str_contains($text, needle: '://')) {
        $scheme = '[a-z][a-z0-9+.-]*://';
    }
    $match = null;
    if (preg_match('~^' . $scheme . '([a-z0-9.-]+|\\[[0-9a-f:.]+\\])([/?#:]|$)~i', $text, $match) !== 1) {
        return null;
    }
    // A host that runs to the end of what is known may go on in the unknown part.
    if ($match[2] === '' && !$complete) {
        return null;
    }
    $host = rtrim(strtolower($match[1]), characters: '.');
    return $host === '' ? null : $host;
}

/**
 * The text an expression starts with, as far as it is written in the code, and whether that is all of it.
 *
 * @return array{0: string, 1: bool}
 */
function literal_prefix(Node\Expr $node, int $depth): array
{
    if ($node instanceof Node\Scalar\String_) {
        return [$node->value, true];
    }
    if ($node instanceof Node\Scalar\InterpolatedString) {
        $text = '';
        foreach ($node->parts as $part) {
            if (!$part instanceof Node\InterpolatedStringPart) {
                return [$text, false];
            }
            $text .= $part->value;
        }
        return [$text, true];
    }
    if ($node instanceof Node\Expr\BinaryOp\Concat) {
        [$left, $left_complete] = literal_prefix($node->left, $depth);
        if (!$left_complete) {
            return [$left, false];
        }
        [$right, $right_complete] = literal_prefix($node->right, $depth);
        return [$left . $right, $right_complete];
    }
    $assigned =
        $node instanceof Node\Expr\Variable && is_string($node->name) && $depth < 3
            ? url_variables()[$node->name] ?? null
            : null;
    return $assigned === null ? ['', false] : literal_prefix($assigned, $depth + 1);
}

/**
 * Variables assigned once in the code being read, so "$url = '...'; wp_remote_get($url);" has a known host. Set
 * before the code is read and cleared after.
 *
 * @param array<string, Node\Expr>|null $set
 * @return array<string, Node\Expr>
 */
function url_variables(?array $set = null): array
{
    /** @var array<string, Node\Expr> $variables */
    static $variables = [];
    if ($set !== null) {
        $variables = $set;
    }
    return $variables;
}

/**
 * @param array<Node> $ast
 * @return array<string, Node\Expr>
 */
function single_assignments(array $ast): array
{
    $values = [];
    $counts = [];
    foreach ((new NodeFinder())->findInstanceOf($ast, Node\Expr\Assign::class) as $assign) {
        if (!$assign->var instanceof Node\Expr\Variable || !is_string($assign->var->name)) {
            continue;
        }
        $name = $assign->var->name;
        $counts[$name] = ($counts[$name] ?? 0) + 1;
        $values[$name] = $assign->expr;
    }
    return array_filter($values, static fn(string $name): bool => $counts[$name] === 1, ARRAY_FILTER_USE_KEY);
}
