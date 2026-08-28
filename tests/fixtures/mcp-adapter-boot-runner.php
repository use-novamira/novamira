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
];

if ($argc !== 3 || !in_array($argv[2], NOVAMIRA_TEST_SCENARIOS, strict: true)) {
    fwrite(STDERR, 'Usage: php mcp-adapter-boot-runner.php <plugin-root> <' . implode('|', NOVAMIRA_TEST_SCENARIOS) . ">\n");
    exit(2);
}

$novamira_root = rtrim($argv[1], '/');
$novamira_scenario = $argv[2];

define('ABSPATH', '/');
define('NOVAMIRA_MAX_EXECUTION_TIME', 30);
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
    return 0;
}

function is_user_logged_in(): bool
{
    return false;
}

function current_user_can(string $capability): bool
{
    return false;
}

function register_rest_route(string $route_namespace, string $route, array $args = []): bool
{
    $GLOBALS['novamira_test_rest_routes'][] = '/' . $route_namespace . '/' . $route;
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
    public function __construct(private string $name, private array $args) {}

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

    public function check_permissions(mixed $input = null): mixed
    {
        return ($this->args['permission_callback'])($input);
    }

    public function execute(mixed $input = null): mixed
    {
        return ($this->args['execute_callback'])($input);
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
    return false;
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
    'init-between' => 7,
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

if (in_array($novamira_scenario, ['init-between', 'init-above'], strict: true)) {
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
if (in_array($novamira_scenario, ['race', 'foreign-below', 'init-between', 'init-above'], strict: true)) {
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

echo json_encode([
    'scenario' => $novamira_scenario,
    'abilities_before_rest_api_init' => $abilities_before_rest_api_init,
    'wp_abilities_api_init_count' => did_action('wp_abilities_api_init'),
    'category_registered' => wp_has_ability_category('novamira-mcp-adapter'),
    'registered' => $registered,
    'ability_count' => count(wp_get_abilities()),
    'tools' => $tools,
    'rest_routes' => $GLOBALS['novamira_test_rest_routes'],
    'get_ability_info_permission_callback' => $GLOBALS['novamira_test_ability_args']['novamira-mcp-adapter/get-ability-info']['permission_callback'] ?? null,
    'execute_ability_parameters' => $GLOBALS['novamira_test_ability_args']['novamira-mcp-adapter/execute-ability']['input_schema']['properties']['parameters'] ?? null,
    'trace' => $GLOBALS['novamira_test_trace'],
    'notices' => $GLOBALS['novamira_test_notices'],
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
