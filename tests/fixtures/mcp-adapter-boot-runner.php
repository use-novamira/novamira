<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Boots the scoped MCP Adapter together with Novamira's Ability hooks under a minimal WordPress
 * emulation and prints what ended up registered, as JSON.
 *
 * The emulation mirrors the parts of WordPress 6.9 that matter for hook ordering:
 * - WP_Hook iteration: callbacks added while an action runs execute only when their priority is
 *   above the one currently running (same-priority additions are appended but skipped, lower ones
 *   are never reached); callbacks removed from a priority not yet reached do not run; callbacks are
 *   keyed like _wp_filter_build_unique_id() so remove_action() matches object callables and returns
 *   whether the callback existed. Not modelled: removing a callback from the priority currently
 *   running, which is an edge case of WP_Hook's active-iteration pointer — nothing exercised here
 *   does that.
 * - The Abilities registries fire `wp_abilities_api_categories_init` / `wp_abilities_api_init`
 *   exactly once when first used, after `init`; Abilities can only be registered while that action
 *   runs; duplicates, unknown categories and wp_get_ability() on an unknown name raise
 *   `_doing_it_wrong()`.
 *
 * Scenarios (second argument):
 * - normal:           the adapter's own init() on rest_api_init:15 boots the registry.
 * - wp-cli:           as normal, but the adapter initializes on init:20 as it does under WP-CLI.
 * - race:             wp_get_abilities() is called after init but before rest_api_init.
 * - foreign-below:    race, plus a foreign wp_abilities_api_init callback at priority 3 (below
 *                     Novamira's built-in registration at 10) that registers an Ability and queries
 *                     the adapter's built-ins.
 * - init-between:     race, plus a foreign wp_abilities_api_init callback at priority 7 (below 10)
 *                     that calls the adapter's init() (creating the MCP server) mid-action.
 * - init-above:       as init-between, at priority 25 (above Novamira's priority 20).
 * - server-disabled:  normal, with `novamira_mcp_adapter_create_default_server` filtered to false.
 * - filter-context:   normal, with that filter returning true outside wp_abilities_api_init and
 *                     false inside it.
 * - pre-init:         normal, plus wp_has_ability() called before `init` (core refuses to boot the
 *                     registry there, with a _doing_it_wrong notice, and no boot is consumed).
 * - hook-conformance: no adapter; only checks the WP_Hook emulation itself.
 * - read-mode-init-between: init-between with read mode on, the read-mode policy at PHP_INT_MAX, and a
 *                     foreign plugin registering public tool, resource, and prompt Abilities outside the
 *                     read-mode allowlist before the nested adapter init() snapshots the server.
 * - read-mode-late-provider / late-provider-off: a plugin loaded after Novamira statically attaches a
 *                     wp_abilities_api_init callback at PHP_INT_MAX that registers a write Ability, with
 *                     read mode on or off, and Novamira's real policy hook.
 * - read-mode-dynamic-providers / dynamic-providers-off: providers attached to PHP_INT_MAX while
 *                     wp_abilities_api_init runs (from priority 10, and from PHP_INT_MAX - 1 after
 *                     Novamira), a direct registry registration after the action, a mirror server and a
 *                     foreign server snapshotting the late Abilities, then Novamira's registry surfaces and
 *                     the bundled adapter's tools/call, prompts/get, and resources/read handlers.
 *
 * - stdio-session:   the dynamic-providers setup with read mode read from the file NOVAMIRA_TEST_STATE_FILE
 *                     names (which the test rewrites between calls, as another process such as Novamira
 *                     Pro in a web request would update the database), plus a plugin loaded after
 *                     Novamira whose PHP_INT_MAX list filters add tools, resources, and prompts; then the
 *                     canonical server is served over STDIO by the adapter's own
 *                     StdioServerBridge::serve() loop. Call counters go to stderr at shutdown.
 *
 * Every adapter scenario also attaches a foreign priority-10 wp_abilities_api_init probe at plugin
 * load, after Novamira's hooks, that records whether execute-ability is registered when it runs.
 *
 * Usage: php mcp-adapter-boot-runner.php <plugin-root> <scenario>
 */

const NOVAMIRA_TEST_SCENARIOS = [
    'normal',
    'wp-cli',
    'race',
    'foreign-below',
    'init-between',
    'init-above',
    'server-disabled',
    'filter-context',
    'pre-init',
    'hook-conformance',
    'read-mode-init-between',
    'read-mode-late-provider',
    'late-provider-off',
    'read-mode-dynamic-providers',
    'dynamic-providers-off',
    'stdio-session',
];

if ($argc !== 3 || !in_array($argv[2], NOVAMIRA_TEST_SCENARIOS, strict: true)) {
    fwrite(STDERR, 'Usage: php mcp-adapter-boot-runner.php <plugin-root> <' . implode('|', NOVAMIRA_TEST_SCENARIOS) . ">\n");
    exit(2);
}

$novamira_root = rtrim($argv[1], '/');
$novamira_scenario = $argv[2];

define('ABSPATH', '/');
define('NOVAMIRA_MAX_EXECUTION_TIME', 30);
define('MINUTE_IN_SECONDS', 60);
define('DAY_IN_SECONDS', 86_400);
if ($novamira_scenario === 'wp-cli') {
    define('WP_CLI', true);
}

$GLOBALS['novamira_test_hooks'] = [];
$GLOBALS['novamira_test_actions_done'] = [];
$GLOBALS['novamira_test_current_hooks'] = [];
$GLOBALS['novamira_test_notices'] = [];
$GLOBALS['novamira_test_ability_args'] = [];
$GLOBALS['novamira_test_rest_routes'] = [];
$GLOBALS['novamira_test_trace'] = [];
$GLOBALS['novamira_test_current_user_can_manage'] = false;

// --- Plugin API (mirrors wp-includes/plugin.php + class-wp-hook.php) ---------------------------

function novamira_test_hook_id(callable|array|string $callback): string
{
    if (is_string($callback)) {
        return $callback;
    }
    if (is_object($callback)) {
        return spl_object_hash($callback);
    }
    return (is_object($callback[0]) ? spl_object_hash($callback[0]) : $callback[0]) . '::' . $callback[1];
}

function add_filter(string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1): bool
{
    $GLOBALS['novamira_test_hooks'][$hook_name][$priority][novamira_test_hook_id($callback)] = [$callback, $accepted_args];
    return true;
}

function add_action(string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1): bool
{
    return add_filter($hook_name, $callback, $priority, $accepted_args);
}

function has_filter(string $hook_name, callable|array|string|false $callback = false): int|false
{
    $priorities = $GLOBALS['novamira_test_hooks'][$hook_name] ?? [];
    if ($callback === false) {
        return $priorities === [] ? false : min(array_keys($priorities));
    }
    $id = novamira_test_hook_id($callback);
    foreach ($priorities as $priority => $callbacks) {
        if (isset($callbacks[$id])) {
            return $priority;
        }
    }
    return false;
}

function has_action(string $hook_name, callable|array|string|false $callback = false): int|false
{
    return has_filter($hook_name, $callback);
}

function remove_filter(string $hook_name, callable|array|string $callback, int $priority = 10): bool
{
    $id = novamira_test_hook_id($callback);
    if (!isset($GLOBALS['novamira_test_hooks'][$hook_name][$priority][$id])) {
        return false;
    }
    unset($GLOBALS['novamira_test_hooks'][$hook_name][$priority][$id]);
    if ($GLOBALS['novamira_test_hooks'][$hook_name][$priority] === []) {
        unset($GLOBALS['novamira_test_hooks'][$hook_name][$priority]);
    }
    $GLOBALS['novamira_test_trace'][] = 'remove_action ' . $hook_name . ':' . $priority . ' ' . (is_array($callback) ? $callback[1] : $id);
    return true;
}

function remove_action(string $hook_name, callable|array|string $callback, int $priority = 10): bool
{
    return remove_filter($hook_name, $callback, $priority);
}

/** Run every callback of a hook the way WP_Hook::apply_filters() iterates priorities. */
function novamira_test_run_hook(string $hook_name, bool $is_filter, mixed $value, array $args): mixed
{
    $GLOBALS['novamira_test_current_hooks'][] = $hook_name;
    $current = null;
    while (true) {
        $pending = array_filter(
            array_keys($GLOBALS['novamira_test_hooks'][$hook_name] ?? []),
            static fn(int $priority): bool => $current === null || $priority > $current,
        );
        if ($pending === []) {
            break;
        }
        $current = min($pending);
        // foreach iterates a copy: callbacks appended to this priority meanwhile do not run.
        foreach ($GLOBALS['novamira_test_hooks'][$hook_name][$current] as [$callback, $accepted_args]) {
            if ($is_filter) {
                $value = $callback($value, ...array_slice($args, 0, max(0, $accepted_args - 1)));
            } else {
                $callback(...array_slice($args, 0, $accepted_args));
            }
        }
    }
    array_pop($GLOBALS['novamira_test_current_hooks']);
    return $value;
}

function apply_filters(string $hook_name, mixed $value, mixed ...$args): mixed
{
    return novamira_test_run_hook($hook_name, is_filter: true, value: $value, args: $args);
}

function do_action(string $hook_name, mixed ...$args): void
{
    $GLOBALS['novamira_test_actions_done'][$hook_name] = ($GLOBALS['novamira_test_actions_done'][$hook_name] ?? 0) + 1;
    novamira_test_run_hook($hook_name, is_filter: false, value: null, args: $args);
}

function did_action(string $hook_name): int
{
    return $GLOBALS['novamira_test_actions_done'][$hook_name] ?? 0;
}

function doing_action(?string $hook_name = null): bool
{
    if ($hook_name === null) {
        return $GLOBALS['novamira_test_current_hooks'] !== [];
    }
    return in_array($hook_name, $GLOBALS['novamira_test_current_hooks'], strict: true);
}

function _doing_it_wrong(string $function_name, string $message, string $version): void
{
    $GLOBALS['novamira_test_notices'][] = $function_name . ': ' . $message;
}

// --- Misc. WordPress functions ----------------------------------------------------------------

class WP_Error
{
    public function __construct(
        private string|int $code = '',
        private string $message = '',
        private mixed $data = null,
    ) {}

    public function get_error_code(): string|int
    {
        return $this->code;
    }

    public function get_error_message(): string
    {
        return $this->message;
    }

    public function get_error_data(): mixed
    {
        return $this->data;
    }
}

function is_wp_error(mixed $value): bool
{
    return $value instanceof WP_Error;
}

function __(string $text, string $domain = 'default'): string
{
    return $text;
}

function esc_html(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES);
}

function esc_html__(string $text, string $domain = 'default'): string
{
    return esc_html($text);
}

function remove_accents(string $text): string
{
    return $text;
}

function wp_parse_args(mixed $args, array $defaults = []): array
{
    return array_merge($defaults, is_array($args) ? $args : []);
}

function wp_json_encode(mixed $value): string|false
{
    return json_encode($value);
}

function get_current_user_id(): int
{
    return ($GLOBALS['novamira_test_logged_in'] ?? false) ? 1 : 0;
}

function is_user_logged_in(): bool
{
    return $GLOBALS['novamira_test_logged_in'] ?? false;
}

function current_user_can(string $capability): bool
{
    return $GLOBALS['novamira_test_logged_in'] ?? false;
}

function get_transient(string $transient): mixed
{
    return $GLOBALS['novamira_test_transients'][$transient] ?? false;
}

function set_transient(string $transient, mixed $value, int $expiration = 0): bool
{
    unset($expiration);
    $GLOBALS['novamira_test_transients'][$transient] = $value;
    return true;
}

function register_rest_route(string $route_namespace, string $route, array $args = []): bool
{
    $GLOBALS['novamira_test_rest_routes'][] = '/' . $route_namespace . '/' . $route;
    $GLOBALS['novamira_test_rest_route_args']['/' . $route_namespace . '/' . $route] = $args;
    return true;
}

// --- Abilities API (mirrors wp-includes/abilities-api in WordPress 6.9) ------------------------

final class WP_Ability_Category
{
    public function __construct(private string $slug, private array $args) {}

    public function get_slug(): string
    {
        return $this->slug;
    }
}

final class WP_Ability_Categories_Registry
{
    private static ?self $instance = null;
    /** @var array<string, WP_Ability_Category> */
    private array $registered = [];

    public static function get_instance(): ?self
    {
        if (!did_action('init')) {
            _doing_it_wrong(__METHOD__, 'Ability API should not be initialized before the init action has fired.', '6.9.0');
            return null;
        }
        if (self::$instance === null) {
            self::$instance = new self();
            do_action('wp_abilities_api_categories_init', self::$instance);
        }
        return self::$instance;
    }

    public function register(string $slug, array $args): ?WP_Ability_Category
    {
        if ($this->is_registered($slug)) {
            _doing_it_wrong(__METHOD__, sprintf('Ability category "%s" is already registered.', $slug), '6.9.0');
            return null;
        }
        $this->registered[$slug] = new WP_Ability_Category($slug, $args);
        return $this->registered[$slug];
    }

    public function is_registered(string $slug): bool
    {
        return isset($this->registered[$slug]);
    }
}

class WP_Ability
{
    /** As in WordPress 6.9, where read mode's identity check reads them. */
    protected mixed $permission_callback;

    protected mixed $execute_callback;

    public function __construct(private string $name, private array $args)
    {
        $this->permission_callback = $args['permission_callback'] ?? null;
        $this->execute_callback = $args['execute_callback'] ?? null;
    }

    public function get_name(): string
    {
        return $this->name;
    }

    public function get_label(): string
    {
        return $this->args['label'];
    }

    public function get_description(): string
    {
        return $this->args['description'];
    }

    public function get_category(): string
    {
        return $this->args['category'];
    }

    public function get_input_schema(): array
    {
        return $this->args['input_schema'] ?? [];
    }

    public function get_output_schema(): array
    {
        return $this->args['output_schema'] ?? [];
    }

    public function get_meta(): array
    {
        return $this->args['meta'] ?? [];
    }

    /** As in WordPress 6.9: the callback gets the input only when the Ability declares an input schema. */
    public function check_permissions(mixed $input = null): mixed
    {
        return is_callable($this->permission_callback)
            ? $this->invoke_callback($this->permission_callback, $input)
            : new WP_Error('ability_invalid_permission_callback', 'Invalid permission callback.');
    }

    /** As in WordPress 6.9: permission first, and no filter can veto execution. */
    public function execute(mixed $input = null): mixed
    {
        $permission = $this->check_permissions($input);
        if ($permission !== true) {
            return new WP_Error('ability_invalid_permissions', sprintf('Ability "%s" does not have necessary permission.', $this->name));
        }

        return $this->invoke_callback($this->execute_callback, $input);
    }

    private function invoke_callback(callable $callback, mixed $input): mixed
    {
        return $this->get_input_schema() !== [] ? $callback($input) : $callback();
    }
}

final class WP_Abilities_Registry
{
    private static ?self $instance = null;
    /** @var array<string, WP_Ability> */
    private array $registered = [];

    public static function get_instance(): ?self
    {
        if (!did_action('init')) {
            _doing_it_wrong(__METHOD__, 'Ability API should not be initialized before the init action has fired.', '6.9.0');
            return null;
        }
        if (self::$instance === null) {
            self::$instance = new self();
            WP_Ability_Categories_Registry::get_instance();
            do_action('wp_abilities_api_init', self::$instance);
        }
        return self::$instance;
    }

    public function register(string $name, array $args): ?WP_Ability
    {
        if ($this->is_registered($name)) {
            _doing_it_wrong(__METHOD__, sprintf('Ability "%s" is already registered.', $name), '6.9.0');
            return null;
        }
        // As in WordPress 6.9, before the category is validated and the object built.
        $args = apply_filters('wp_register_ability_args', $args, $name);
        if (!isset($args['category']) || !wp_has_ability_category($args['category'])) {
            _doing_it_wrong(
                __METHOD__,
                sprintf('Ability category "%s" is not registered for ability "%s".', $args['category'] ?? '', $name),
                '6.9.0',
            );
            return null;
        }
        $this->registered[$name] = new WP_Ability($name, $args);
        $GLOBALS['novamira_test_ability_args'][$name] = $args;
        $GLOBALS['novamira_test_trace'][] = 'register ' . $name;
        return $this->registered[$name];
    }

    public function unregister(string $name): ?WP_Ability
    {
        if (!$this->is_registered($name)) {
            _doing_it_wrong(__METHOD__, sprintf('Ability "%s" not found.', $name), '6.9.0');
            return null;
        }
        $ability = $this->registered[$name];
        unset($this->registered[$name], $GLOBALS['novamira_test_ability_args'][$name]);
        $GLOBALS['novamira_test_trace'][] = 'unregister ' . $name;
        return $ability;
    }

    public function is_registered(string $name): bool
    {
        return isset($this->registered[$name]);
    }

    public function get_registered(string $name): ?WP_Ability
    {
        if (!$this->is_registered($name)) {
            _doing_it_wrong(__METHOD__, sprintf('Ability "%s" not found.', $name), '6.9.0');
            return null;
        }
        return $this->registered[$name];
    }

    /** @return array<string, WP_Ability> */
    public function get_all_registered(): array
    {
        return $this->registered;
    }
}

function wp_register_ability(string $name, array $args): ?WP_Ability
{
    if (!doing_action('wp_abilities_api_init')) {
        _doing_it_wrong(
            'wp_register_ability',
            sprintf('Abilities must be registered on the wp_abilities_api_init action. The ability %s was not registered.', $name),
            '6.9.0',
        );
        return null;
    }
    return WP_Abilities_Registry::get_instance()?->register($name, $args);
}

function wp_unregister_ability(string $name): ?WP_Ability
{
    return WP_Abilities_Registry::get_instance()?->unregister($name);
}

function wp_has_ability(string $name): bool
{
    return WP_Abilities_Registry::get_instance()?->is_registered($name) ?? false;
}

function wp_get_ability(string $name): ?WP_Ability
{
    return WP_Abilities_Registry::get_instance()?->get_registered($name);
}

/** @return array<string, WP_Ability> */
function wp_get_abilities(): array
{
    return WP_Abilities_Registry::get_instance()?->get_all_registered() ?? [];
}

function wp_register_ability_category(string $slug, array $args): ?WP_Ability_Category
{
    if (!doing_action('wp_abilities_api_categories_init')) {
        _doing_it_wrong(
            'wp_register_ability_category',
            sprintf('Ability categories must be registered on the wp_abilities_api_categories_init action. The ability category %s was not registered.', $slug),
            '6.9.0',
        );
        return null;
    }
    return WP_Ability_Categories_Registry::get_instance()?->register($slug, $args);
}

function wp_has_ability_category(string $slug): bool
{
    return WP_Ability_Categories_Registry::get_instance()?->is_registered($slug) ?? false;
}

// --- Novamira plugin surface the Ability files depend on ---------------------------------------

function novamira_is_mcp_adapter_available(): bool
{
    return true;
}

function novamira_wordpress_abilities_supported(): bool
{
    return true;
}

function novamira_current_user_can_manage(): bool
{
    return $GLOBALS['novamira_test_current_user_can_manage'];
}

function novamira_build_server_instructions(): string
{
    return '';
}

// --- Hook emulation conformance ---------------------------------------------------------------------

if ($novamira_scenario === 'hook-conformance') {
    $ran = [];
    $record = static function (string $label) use (&$ran): Closure {
        return static function () use (&$ran, $label): void {
            $ran[] = $label;
        };
    };
    $future = $record('15: removed while 10 runs');
    add_action('conformance', $record('5'), 5);
    add_action('conformance', static function () use (&$ran, $record, $future): void {
        $ran[] = '10: mutating';
        add_action('conformance', $record('3: added while 10 runs'), 3);
        add_action('conformance', $record('10: added while 10 runs'), 10);
        add_action('conformance', $record('12: added while 10 runs'), 12);
        $ran[] = 'remove 15 -> ' . var_export(remove_action('conformance', $future, 15), return: true);
        $ran[] = 'remove unknown -> ' . var_export(remove_action('conformance', 'novamira_unknown', 15), return: true);
    }, 10);
    add_action('conformance', $future, 15);
    add_action('conformance', $record('20'), 20);
    do_action('conformance');
    do_action('conformance');
    echo json_encode(['scenario' => $novamira_scenario, 'ran' => $ran], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    exit();
}

// --- Plugin load (mirrors novamira.php) ---------------------------------------------------------

require $novamira_root . '/vendor/novamira/mcp-adapter/autoload.php';
require $novamira_root . '/includes/abilities/bootstrap.php';

novamira_register_ability_hooks();

if (in_array($novamira_scenario, ['read-mode-init-between', 'read-mode-late-provider', 'late-provider-off', 'read-mode-dynamic-providers', 'dynamic-providers-off', 'stdio-session'], strict: true)) {
    $GLOBALS['novamira_test_read_mode'] = !in_array($novamira_scenario, ['late-provider-off', 'dynamic-providers-off', 'stdio-session'], strict: true);
    if ($novamira_scenario === 'stdio-session') {
        $GLOBALS['novamira_test_read_mode'] = trim((string) file_get_contents((string) getenv('NOVAMIRA_TEST_STATE_FILE'))) === '1';
    }

    function get_option(string $option, mixed $default_value = false): mixed
    {
        return $default_value;
    }

    // Stand in for helpers.php's hub rules and screen check: no rule turns an ability off, and no screen is the Hub's.
    function novamira_ability_has_disabled_rule(string $ability_name): bool
    {
        return false;
    }

    function novamira_is_ability_management_screen(): bool
    {
        return false;
    }

    // Stands in for helpers.php's policy, which also applies hub rules this runner does not load.
    function novamira_apply_ability_policy(): void
    {
        $GLOBALS['novamira_test_trace'][] = 'policy sweep';
        if (novamira_is_read_mode()) {
            novamira_apply_read_mode_policy();
        }
    }

    /** A MySQL client handle that never connects, as wpdb holds after connecting. */
    class Test_Mysqli extends mysqli
    {
        public function __construct() {}
    }

    // A scripted database connection, enough for read mode's state and read-query's read-only transaction.
    class wpdb
    {
        public string $options = 'wp_options';
        protected mixed $dbh;
        public string $last_error = '';
        public string $prefix = 'wp_';
        public string $base_prefix = 'wp_';
        /** @var list<string> */
        public array $log = [];
        private string $read_only = '0';

        public function __construct()
        {
            $this->dbh = new Test_Mysqli();
        }

        public function suppress_errors(bool $suppress = true): bool
        {
            return false;
        }

        public function db_server_info(): string
        {
            return '8.4.11';
        }

        public function query(string $query): int|bool
        {
            $this->log[] = $query;
            $this->last_error = '';
            if ($query === 'SET SESSION TRANSACTION READ ONLY') {
                $this->read_only = '1';
            }
            if ($query === 'SET SESSION TRANSACTION READ WRITE') {
                $this->read_only = '0';
            }
            return 0;
        }

        public function get_var(string $query): ?string
        {
            $this->last_error = '';
            // Read mode's state query, answered with the record Novamira's state API stores.
            if (str_contains($query, "option_name = 'novamira_read_mode'")) {
                $GLOBALS['novamira_test_state_queries'] = ($GLOBALS['novamira_test_state_queries'] ?? 0) + 1;
                $state_file = (string) getenv('NOVAMIRA_TEST_STATE_FILE');
                $enabled = $state_file !== ''
                    ? trim((string) file_get_contents($state_file)) === '1'
                    : $GLOBALS['novamira_test_read_mode'];
                return serialize(['schema_version' => 1, 'enabled' => $enabled]);
            }
            $this->log[] = $query;
            return match (true) {
                str_contains($query, 'transaction_read_only') => $this->read_only,
                str_contains($query, 'max_execution_time') => '0',
                default => null,
            };
        }

        public function get_results(string $query, string $output = 'OBJECT'): ?array
        {
            $this->log[] = $query;
            $this->last_error = '';
            return [['one' => '1']];
        }

        public function get_col_info(string $info_type = 'name', int $col_offset = -1): array
        {
            return ['one'];
        }
    }
    $GLOBALS['wpdb'] = new wpdb();

    require $novamira_root . '/includes/read-mode/policy.php';
    novamira_register_read_mode_hooks();
    novamira_register_ability_policy_hook();

    // A stand-in for Novamira Pro: it adds read-query to read mode's abilities through the filter, and registers it.
    function test_pro_read_execute(mixed $input = null): array
    {
        return ['rows' => [['one' => '1']]];
    }

    add_filter('novamira_read_mode_abilities', static function (mixed $abilities): mixed {
        if (is_array($abilities)) {
            $abilities['novamira/read-query'] = [
                'execute' => 'test_pro_read_execute',
                'permission' => 'novamira_permission_callback',
            ];
        }

        return $abilities;
    });
    // Like Novamira Pro, it registers only while read mode is on.
    add_action('wp_abilities_api_categories_init', static function (): void {
        if (novamira_is_read_mode()) {
            wp_register_ability_category('database', ['label' => 'Database', 'description' => 'Stand-in category.']);
        }
    });
    add_action('wp_abilities_api_init', static function (): void {
        if (!novamira_is_read_mode()) {
            return;
        }
        wp_register_ability('novamira/read-query', [
            'label' => 'Read query',
            'description' => 'Stand-in for the read-query ability of Novamira Pro.',
            'category' => 'database',
            'input_schema' => ['type' => 'object'],
            'output_schema' => ['type' => 'object'],
            'permission_callback' => 'novamira_permission_callback',
            'execute_callback' => 'test_pro_read_execute',
            'meta' => [
                'show_in_rest' => true,
                'mcp' => ['public' => true],
                'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            ],
        ]);
    });

    if (in_array($novamira_scenario, ['stdio-session', 'read-mode-dynamic-providers', 'dynamic-providers-off'], strict: true)) {
        // A plugin that, while a list is being built, adds its own filter at the maximum priority: in that
        // same pass it runs after every callback already attached there.
        foreach ([
            'tools' => static fn(): object => \Novamira\Vendor\WP\McpSchema\Server\Tools\DTO\Tool::fromArray(['name' => 'acme-added-during-pass', 'inputSchema' => ['type' => 'object']]),
            'resources' => static fn(): object => \Novamira\Vendor\WP\McpSchema\Server\Resources\DTO\Resource::fromArray(['name' => 'acme-added-during-pass', 'uri' => 'acme://added-during-pass']),
            'prompts' => static fn(): object => \Novamira\Vendor\WP\McpSchema\Server\Prompts\DTO\Prompt::fromArray(['name' => 'acme-added-during-pass']),
        ] as $kind => $item) {
            add_filter("novamira_mcp_adapter_{$kind}_list", static function (array $items) use ($kind, $item): array {
                static $added = [];
                if (!isset($added[$kind])) {
                    $added[$kind] = true;
                    add_filter("novamira_mcp_adapter_{$kind}_list", static fn(array $items): array => [...$items, $item()], PHP_INT_MAX);
                }
                return $items;
            });
        }
    }

    if ($novamira_scenario === 'stdio-session') {
        // A plugin loaded after Novamira adds its own items to every list, at the same maximum priority.
        add_filter('novamira_mcp_adapter_tools_list', static fn(array $tools): array => [
            ...$tools,
            \Novamira\Vendor\WP\McpSchema\Server\Tools\DTO\Tool::fromArray(['name' => 'acme-listed-tool', 'inputSchema' => ['type' => 'object']]),
        ], PHP_INT_MAX);
        add_filter('novamira_mcp_adapter_resources_list', static fn(array $resources): array => [
            ...$resources,
            \Novamira\Vendor\WP\McpSchema\Server\Resources\DTO\Resource::fromArray(['name' => 'acme-listed-resource', 'uri' => 'acme://listed']),
        ], PHP_INT_MAX);
        add_filter('novamira_mcp_adapter_prompts_list', static fn(array $prompts): array => [
            ...$prompts,
            \Novamira\Vendor\WP\McpSchema\Server\Prompts\DTO\Prompt::fromArray(['name' => 'acme-listed-prompt']),
        ], PHP_INT_MAX);
    }
}

add_filter(
    'novamira_mcp_adapter_tool_name',
    static function (string $tool_name, mixed $ability): string {
        if (!$ability instanceof WP_Ability) {
            return $tool_name;
        }
        return match ($ability->get_name()) {
            'novamira-mcp-adapter/discover-abilities' => 'mcp-adapter-discover-abilities',
            'novamira-mcp-adapter/get-ability-info' => 'mcp-adapter-get-ability-info',
            'novamira-mcp-adapter/execute-ability' => 'mcp-adapter-execute-ability',
            default => $tool_name,
        };
    },
    accepted_args: 2,
);

add_filter('novamira_mcp_adapter_default_server_config', static function (mixed $config): mixed {
    if (!is_array($config)) {
        return $config;
    }
    $config['server_id'] = 'novamira';
    $config['server_route'] = 'novamira';
    $config['server_name'] = 'Novamira';
    return $config;
});

if ($novamira_scenario === 'server-disabled') {
    add_filter('novamira_mcp_adapter_create_default_server', static fn(): bool => false);
}

if ($novamira_scenario === 'filter-context') {
    // A context-sensitive filter: true when the adapter decides in init(), false when asked from
    // inside wp_abilities_api_init.
    add_filter('novamira_mcp_adapter_create_default_server', static function (mixed $create): mixed {
        $GLOBALS['novamira_test_trace'][] = 'filter novamira_mcp_adapter_create_default_server -> '
            . var_export(!doing_action('wp_abilities_api_init'), return: true);
        return !doing_action('wp_abilities_api_init');
    });
}

\Novamira\Vendor\WP\MCP\Core\McpAdapter::instance();

// A foreign plugin loaded after Novamira, hooking wp_abilities_api_init at the default priority.
add_action('wp_abilities_api_init', static function (): void {
    $GLOBALS['novamira_test_trace'][] = 'probe@10: execute-ability registered='
        . var_export(wp_has_ability('novamira-mcp-adapter/execute-ability'), return: true);
});

// --- Foreign plugins ------------------------------------------------------------------------------

$novamira_foreign_priority = match ($novamira_scenario) {
    'foreign-below' => 3,
    'init-between', 'read-mode-init-between' => 7,
    'init-above' => 25,
    default => null,
};

if ($novamira_scenario === 'foreign-below') {
    add_action('wp_abilities_api_categories_init', static function (): void {
        wp_register_ability_category('foreign', ['label' => 'Foreign', 'description' => 'Foreign plugin.']);
    });
    add_action('wp_abilities_api_init', static function (): void {
        $GLOBALS['novamira_test_trace'][] = 'foreign callback: execute-ability registered='
            . var_export(wp_has_ability('novamira-mcp-adapter/execute-ability'), return: true);
        wp_register_ability('foreign/ping', [
            'label' => 'Ping',
            'description' => 'Foreign ability registered before Novamira.',
            'category' => 'foreign',
            'permission_callback' => static fn(): bool => true,
            'execute_callback' => static fn(): string => 'pong',
            'meta' => ['mcp' => ['public' => true, 'type' => 'tool']],
        ]);
    }, $novamira_foreign_priority);
}

if (in_array($novamira_scenario, ['read-mode-dynamic-providers', 'dynamic-providers-off', 'stdio-session'], strict: true)) {
    require $novamira_root . '/includes/mcp-mirror-server.php';
    $GLOBALS['novamira_test_late_calls'] = ['permission' => 0, 'execute' => 0, 'callable' => 0];
    // The adapter's error handler logs refused and unknown components with error_log().
    ini_set('error_log', '/dev/null');

    // Novamira's own permission callback, as helpers.php defines it.
    function novamira_permission_callback(): bool
    {
        return novamira_current_user_can_manage();
    }


    // A callable-backed MCP tool, which has no Ability behind it.
    function novamira_test_callable_tool(string $name): \Novamira\Vendor\WP\MCP\Domain\Tools\McpTool
    {
        $tool = \Novamira\Vendor\WP\MCP\Domain\Tools\McpTool::fromArray([
            'name' => $name,
            'description' => 'Callable-backed tool.',
            'handler' => static function (): array {
                $GLOBALS['novamira_test_late_calls']['callable']++;
                return ['written' => true];
            },
            'permission' => static fn(): bool => true,
        ]);
        if (is_wp_error($tool)) {
            throw new RuntimeException($tool->get_error_message());
        }
        return $tool;
    }

    // A plugin loaded after Novamira adds a callable-backed tool to the default server's configuration at
    // the same maximum priority as Novamira's read-mode filter.
    add_filter('novamira_mcp_adapter_default_server_config', static function (mixed $config): mixed {
        if (is_array($config)) {
            $config['tools'][] = novamira_test_callable_tool('acme-callable');
        }
        return $config;
    }, PHP_INT_MAX);

    // And tries to undo any refusal on tool calls, after Novamira's own pre-call filter.
    add_filter(
        'novamira_mcp_adapter_pre_tool_call',
        static fn(mixed $arguments): mixed => is_wp_error($arguments) ? [] : $arguments,
        PHP_INT_MAX,
    );

    function novamira_test_late_args(string $label, string $type, array $mcp = []): array
    {
        return [
            'label' => $label,
            'description' => 'Late write Ability.',
            'category' => 'late',
            'permission_callback' => static function (): bool {
                $GLOBALS['novamira_test_late_calls']['permission']++;
                return true;
            },
            'execute_callback' => static function (): mixed {
                $GLOBALS['novamira_test_late_calls']['execute']++;
                return 'written';
            },
            'meta' => ['mcp' => array_merge(['public' => true, 'type' => $type], $mcp), 'show_in_rest' => true],
        ];
    }

    add_action('wp_abilities_api_categories_init', static function (): void {
        wp_register_ability_category('late', ['label' => 'Late', 'description' => 'Late plugin.']);
    });

    // A normal priority-10 callback that attaches a maximum-priority provider while the action runs.
    add_action('wp_abilities_api_init', static function (): void {
        add_action('wp_abilities_api_init', static function (): void {
            $GLOBALS['novamira_test_trace'][] = 'dynamic provider registers late/dynamic-write';
            wp_register_ability('late/dynamic-write', novamira_test_late_args('Dynamic write', 'tool'));
        }, PHP_INT_MAX);
    }, 10);

    // Attached after Novamira at PHP_INT_MAX - 1, so its provider lands after the sweep: only the
    // surface-entry sweep and the execution guards can catch it.
    add_action('wp_abilities_api_init', static function (): void {
        add_action('wp_abilities_api_init', static function (): void {
            $GLOBALS['novamira_test_trace'][] = 'escaping provider registers late/escaping-*';
            wp_register_ability('late/escaping-write', novamira_test_late_args('Escaping write', 'tool'));
            wp_register_ability('late/escaping-prompt', novamira_test_late_args('Escaping prompt', 'prompt'));
            wp_register_ability(
                'late/escaping-resource',
                novamira_test_late_args('Escaping resource', 'resource', ['uri' => 'late://escaping']),
            );
        }, PHP_INT_MAX);
    }, PHP_INT_MAX - 1);

    // Novamira's legacy mirror, plus a foreign server that snapshots the escaped Abilities.
    add_action('novamira_mcp_adapter_init', static function (mixed $adapter): void {
        novamira_create_mirror_mcp_server(
            $adapter,
            server_id: 'novamira-mirror',
            route: 'novamira-mirror',
            name: 'Mirror',
            description: 'Mirror of the Novamira server.',
        );
        $adapter->create_server(
            'foreign-snapshot',
            'mcp',
            'foreign-snapshot',
            'Foreign',
            'Foreign server built from Abilities registered after the sweep.',
            'v1.0.0',
            [\Novamira\Vendor\WP\MCP\Transport\HttpTransport::class],
            \Novamira\Vendor\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
            \Novamira\Vendor\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
            ['late/escaping-write', novamira_test_callable_tool('acme-foreign-callable')],
            ['late/escaping-resource'],
            ['late/escaping-prompt'],
        );
    }, 20);

    // After the action: straight into the registry, which wp_register_ability() would refuse.
    add_action('rest_api_init', static function (): void {
        $GLOBALS['novamira_test_after_action'] = [
            'dynamic' => wp_has_ability('late/dynamic-write'),
            'escaping' => wp_has_ability('late/escaping-write'),
        ];
    }, 90);
}

if (in_array($novamira_scenario, ['read-mode-late-provider', 'late-provider-off'], strict: true)) {
    // A plugin loaded after Novamira, attached statically at the same maximum priority as the policy.
    add_action('wp_abilities_api_categories_init', static function (): void {
        wp_register_ability_category('late', ['label' => 'Late', 'description' => 'Late plugin.']);
    });
    add_action('wp_abilities_api_init', static function (): void {
        $GLOBALS['novamira_test_trace'][] = 'late provider registers late/write';
        wp_register_ability('late/write', [
            'label' => 'Late write',
            'description' => 'Write Ability registered at PHP_INT_MAX by a plugin loaded after Novamira.',
            'category' => 'late',
            'permission_callback' => static fn(): bool => true,
            'execute_callback' => static fn(): string => 'written',
            'meta' => ['mcp' => ['public' => true, 'type' => 'tool'], 'show_in_rest' => true],
        ]);
    }, PHP_INT_MAX);
}

if ($novamira_scenario === 'read-mode-init-between') {
    add_action('wp_abilities_api_categories_init', static function (): void {
        wp_register_ability_category('foreign', ['label' => 'Foreign', 'description' => 'Foreign plugin.']);
    });
    add_action('wp_abilities_api_init', static function (): void {
        $common = [
            'category' => 'foreign',
            'permission_callback' => static fn(): bool => true,
            'execute_callback' => static function (): string {
                $GLOBALS['novamira_test_trace'][] = 'foreign ability executed';
                return 'side effect';
            },
        ];
        wp_register_ability('foreign/write-tool', $common + [
            'label' => 'Write tool',
            'description' => 'Blocked public tool.',
            'meta' => ['mcp' => ['public' => true, 'type' => 'tool']],
        ]);
        wp_register_ability('foreign/secret-resource', $common + [
            'label' => 'Secret resource',
            'description' => 'Blocked public resource.',
            'meta' => ['mcp' => ['public' => true, 'type' => 'resource', 'uri' => 'foreign://secret']],
        ]);
        wp_register_ability('foreign/write-prompt', $common + [
            'label' => 'Write prompt',
            'description' => 'Blocked public prompt.',
            'meta' => ['mcp' => ['public' => true, 'type' => 'prompt']],
        ]);
    }, 3);
}

if (in_array($novamira_scenario, ['init-between', 'init-above', 'read-mode-init-between'], strict: true)) {
    add_action('wp_abilities_api_init', static function (): void {
        $GLOBALS['novamira_test_trace'][] = 'foreign callback: adapter init()';
        \Novamira\Vendor\WP\MCP\Core\McpAdapter::instance()->init();
        $server = \Novamira\Vendor\WP\MCP\Core\McpAdapter::instance()->get_server('novamira');
        $GLOBALS['novamira_test_trace'][] = 'foreign callback: server created=' . var_export($server !== null, return: true);
    }, $novamira_foreign_priority);
}

// --- Request lifecycle ----------------------------------------------------------------------------

if ($novamira_scenario === 'pre-init') {
    $GLOBALS['novamira_test_trace'][] = 'before init: execute-ability registered='
        . var_export(wp_has_ability('novamira-mcp-adapter/execute-ability'), return: true);
}

do_action('init');

$abilities_before_rest_api_init = null;
if (in_array($novamira_scenario, ['race', 'foreign-below', 'init-between', 'init-above', 'read-mode-init-between'], strict: true)) {
    // Another plugin (or the public mcp-adapter plugin) uses the Abilities API before the bundled
    // adapter's rest_api_init:15 initialization: this boots the registry and fires the one-shot
    // wp_abilities_api_init action right now.
    $abilities_before_rest_api_init = array_keys(wp_get_abilities());
}

if ($novamira_scenario !== 'wp-cli') {
    do_action('rest_api_init');
}

$adapter_abilities = [
    'novamira-mcp-adapter/discover-abilities',
    'novamira-mcp-adapter/get-ability-info',
    'novamira-mcp-adapter/execute-ability',
];
$registered = [];
foreach ($adapter_abilities as $ability_name) {
    $registered[$ability_name] = wp_has_ability($ability_name);
}

$server = \Novamira\Vendor\WP\MCP\Core\McpAdapter::instance()->get_server('novamira');
$tools = [];
$resources = [];
$prompts = [];
if ($server !== null) {
    $resources = array_map('strval', array_keys($server->get_resources()));
    $prompts = array_map('strval', array_keys($server->get_prompts()));
}
if ($server !== null) {
    $GLOBALS['novamira_test_trace'][] = 'server novamira: created';
} else {
    $GLOBALS['novamira_test_trace'][] = 'server novamira: not created';
}
if ($server !== null) {
    foreach ($server->get_tools() as $tool) {
        $tool_array = json_decode(json_encode($tool->toArray(), JSON_THROW_ON_ERROR), associative: true, flags: JSON_THROW_ON_ERROR);
        $tools[$tool_array['name']] = [
            'description' => $tool_array['description'],
            'parameters' => $tool_array['inputSchema']['properties']['parameters'] ?? null,
        ];
    }
}
ksort($tools);

$discover_callback = $GLOBALS['novamira_test_ability_args']['novamira-mcp-adapter/discover-abilities']['execute_callback']
    ?? null;
$subscriber_discovery = [];
$manager_discovery = [];
if (is_callable($discover_callback)) {
    $GLOBALS['novamira_test_current_user_can_manage'] = false;
    $subscriber_result = $discover_callback();
    $subscriber_discovery = array_column($subscriber_result['abilities'], 'name');

    $GLOBALS['novamira_test_current_user_can_manage'] = true;
    $manager_result = $discover_callback();
    $manager_discovery = array_column($manager_result['abilities'], 'name');
}

if ($novamira_scenario === 'stdio-session') {
    // Written however the process ends, including when the session ends itself.
    register_shutdown_function(static function (): void {
        fwrite(STDERR, 'COUNTERS ' . json_encode([
            'calls' => $GLOBALS['novamira_test_late_calls'],
            'registered' => array_keys(wp_get_abilities()),
        ], JSON_UNESCAPED_SLASHES) . "\n");
    });
    // WP-CLI serves as an administrator. The adapter's own loop reads JSON-RPC lines until stdin closes.
    $GLOBALS['novamira_test_logged_in'] = true;
    $GLOBALS['novamira_test_current_user_can_manage'] = true;
    $stdio_server = \Novamira\Vendor\WP\MCP\Core\McpAdapter::instance()->get_server('novamira');
    (new \Novamira\Vendor\WP\MCP\Cli\StdioServerBridge($stdio_server))->serve();
    exit(0);
}

$surfaces = null;
$handlers = null;
if (in_array($novamira_scenario, ['read-mode-dynamic-providers', 'dynamic-providers-off'], strict: true)) {
    $register_direct = static function (): void {
        if (!wp_has_ability('late/direct-write')) {
            WP_Abilities_Registry::get_instance()?->register('late/direct-write', novamira_test_late_args('Direct write', 'tool'));
        }
    };
    $request = static fn(string $route): object => new class($route) {
        public function __construct(
            private string $route,
        ) {}

        public function get_route(): string
        {
            return $this->route;
        }
    };
    $discover = $GLOBALS['novamira_test_ability_args']['novamira-mcp-adapter/discover-abilities']['execute_callback'];
    $info_permission = $GLOBALS['novamira_test_ability_args']['novamira-mcp-adapter/get-ability-info']['permission_callback'];

    // Components of the foreign server before any registry-backed surface was entered.
    $foreign_server = \Novamira\Vendor\WP\MCP\Core\McpAdapter::instance()->get_server('foreign-snapshot');
    $components_before = $foreign_server === null ? null : [
        'tools' => array_keys($foreign_server->get_tools()),
        'resources' => array_keys($foreign_server->get_resources()),
        'prompts' => array_keys($foreign_server->get_prompts()),
    ];

    $surfaces = [];
    foreach ([
        'core_abilities_list' => static fn(): mixed => novamira_read_mode_rest_sweep(null, [], $request('/wp-abilities/v1/abilities')),
        'core_ability_run' => static fn(): mixed => novamira_read_mode_rest_sweep(null, [], $request('/wp-abilities/v1/abilities/late/direct-write/run')),
        'mcp_http_route' => static fn(): mixed => novamira_read_mode_rest_sweep(null, [], $request('/mcp/novamira')),
        'novamira_route' => static fn(): mixed => novamira_read_mode_rest_sweep(null, [], $request('/novamira/v1/abilities/late/direct-write/run')),
        'unrelated_route' => static fn(): mixed => novamira_read_mode_rest_sweep(null, [], $request('/wp/v2/posts')),
        'mcp_stdio_start' => static fn(): mixed => apply_filters('novamira_mcp_adapter_enable_stdio_transport', true),
        'discover_abilities' => static fn(): mixed => in_array('late/direct-write', array_column($discover()['abilities'], 'name'), strict: true),
        'get_ability_info_permission' => static fn(): mixed => $info_permission(['ability_name' => 'late/direct-write']),
    ] as $surface => $call) {
        $register_direct();
        $outcome = $call();
        $surfaces[$surface] = [
            'still_registered' => wp_has_ability('late/direct-write'),
            'outcome' => $outcome instanceof WP_Error ? $outcome->get_error_code() : $outcome,
        ];
    }
    $surfaces['after_all'] = [
        'dynamic' => wp_has_ability('late/dynamic-write'),
        'escaping' => wp_has_ability('late/escaping-write'),
    ];

    $to_array = static fn(mixed $result): array => json_decode(
        json_encode($result->toArray(), JSON_THROW_ON_ERROR),
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );
    $handlers = [];
    $GLOBALS['novamira_test_logged_in'] = true;
    $GLOBALS['novamira_test_current_user_can_manage'] = true;

    // The MCP HTTP route: entering it, then the request router its transport uses for every call.
    $mcp_route = $GLOBALS['novamira_test_rest_route_args']['/mcp/novamira'] ?? [];
    $http_entry = novamira_read_mode_rest_sweep(null, $mcp_route, $request('/mcp/novamira'));
    $http_transport = $mcp_route['callback'][0] ?? null;
    $http_router = (static fn(object $transport): object => $transport->request_handler->transport_context->request_router)
        ->bindTo(null, \Novamira\Vendor\WP\MCP\Transport\HttpTransport::class)($http_transport);
    $http_lists = [];
    foreach (['tools/list' => ['tools', 'name'], 'resources/list' => ['resources', 'uri'], 'prompts/list' => ['prompts', 'name']] as $method => [$key, $field]) {
        $listed = $http_router->route_request($method, [], 1, 'http');
        $http_lists[$key] = array_column($listed[$key] ?? [], $field);
    }
    $handlers['http'] = ['entry' => $http_entry instanceof WP_Error ? $http_entry->get_error_code() : $http_entry, 'lists' => $http_lists];
    foreach (['novamira', 'novamira-mirror', 'foreign-snapshot'] as $server_id) {
        $server = \Novamira\Vendor\WP\MCP\Core\McpAdapter::instance()->get_server($server_id);
        if ($server === null) {
            $handlers[$server_id] = null;
            continue;
        }
        $before = $GLOBALS['novamira_test_late_calls'];
        $tools_handler = new \Novamira\Vendor\WP\MCP\Handlers\Tools\ToolsHandler($server);
        $prompts = new \Novamira\Vendor\WP\MCP\Handlers\Prompts\PromptsHandler($server);
        $resources = new \Novamira\Vendor\WP\MCP\Handlers\Resources\ResourcesHandler($server);
        $handlers[$server_id] = [
            'tool_names' => array_keys($server->get_tools()),
            'tools_call' => $to_array($tools_handler->call_tool(['name' => 'late-escaping-write', 'arguments' => []], 1)),
            'prompts_get' => $to_array($prompts->get_prompt(['name' => 'late-escaping-prompt', 'arguments' => []], 2)),
            'resources_read' => $to_array($resources->read_resource(['uri' => 'late://escaping'], 3)),
            'ability_info_blocked' => $server->get_mcp_tool('mcp-adapter-get-ability-info') !== null
                ? $to_array($tools_handler->call_tool(['name' => 'mcp-adapter-get-ability-info', 'arguments' => ['ability_name' => 'late/escaping-write']], 4))
                : null,
            'ability_info_allowed' => $server->get_mcp_tool('mcp-adapter-get-ability-info') !== null
                ? $to_array($tools_handler->call_tool(['name' => 'mcp-adapter-get-ability-info', 'arguments' => ['ability_name' => 'novamira/read-file']], 5))
                : null,
            'callable_tool_call' => $to_array($tools_handler->call_tool(['name' => 'acme-callable', 'arguments' => []], 6)),
            'foreign_callable_tool_call' => $to_array($tools_handler->call_tool(['name' => 'acme-foreign-callable', 'arguments' => []], 7)),
            'execute_read_query' => $server->get_mcp_tool('mcp-adapter-execute-ability') !== null
                ? $to_array($tools_handler->call_tool([
                    'name' => 'mcp-adapter-execute-ability',
                    'arguments' => ['ability_name' => 'novamira/read-query', 'parameters' => ['sql' => 'SELECT 1 AS one']],
                ], 8))
                : null,
            'execute_php' => $server->get_mcp_tool('mcp-adapter-execute-ability') !== null
                ? $to_array($tools_handler->call_tool([
                    'name' => 'mcp-adapter-execute-ability',
                    'arguments' => ['ability_name' => 'novamira/execute-php', 'parameters' => ['code' => 'return 6 * 7;']],
                ], 9))
                : null,
            'execute_late' => $server->get_mcp_tool('mcp-adapter-execute-ability') !== null
                ? $to_array($tools_handler->call_tool([
                    'name' => 'mcp-adapter-execute-ability',
                    'arguments' => ['ability_name' => 'late/escaping-write', 'parameters' => []],
                ], 10))
                : null,
            'late_permission_calls' => $GLOBALS['novamira_test_late_calls']['permission'] - $before['permission'],
            'late_executions' => $GLOBALS['novamira_test_late_calls']['execute'] - $before['execute'],
            'callable_calls' => $GLOBALS['novamira_test_late_calls']['callable'] - $before['callable'],
        ];
    }
    $GLOBALS['novamira_test_logged_in'] = false;
    $GLOBALS['novamira_test_current_user_can_manage'] = false;
}

echo json_encode([
    'scenario' => $novamira_scenario,
    'after_action' => $GLOBALS['novamira_test_after_action'] ?? null,
    'surfaces' => $surfaces,
    'handlers' => $handlers,
    'foreign_components_before' => $components_before ?? null,
    'abilities_before_rest_api_init' => $abilities_before_rest_api_init,
    'wp_abilities_api_init_count' => did_action('wp_abilities_api_init'),
    'category_registered' => wp_has_ability_category('novamira-mcp-adapter'),
    'registered' => $registered,
    'ability_count' => count(wp_get_abilities()),
    'tools' => $tools,
    'resources' => $resources,
    'prompts' => $prompts,
    'registered_after_policy' => array_keys(wp_get_abilities()),
    'rest_routes' => $GLOBALS['novamira_test_rest_routes'],
    'get_ability_info_permission_callback' => $GLOBALS['novamira_test_ability_args']['novamira-mcp-adapter/get-ability-info']['permission_callback'] ?? null,
    'execute_ability_parameters' => $GLOBALS['novamira_test_ability_args']['novamira-mcp-adapter/execute-ability']['input_schema']['properties']['parameters'] ?? null,
    'subscriber_discovery' => $subscriber_discovery,
    'manager_discovery' => $manager_discovery,
    'trace' => $GLOBALS['novamira_test_trace'],
    'notices' => $GLOBALS['novamira_test_notices'],
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
