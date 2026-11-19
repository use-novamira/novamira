<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Child-process runner for ReadModeRetainedAbilityTest:
 * php read-mode-retained-runner.php <plugin root> <on|off|off-unguarded|on-wp71|off-wp71|off-wp71-unguarded>.
 *
 * off-unguarded runs without Novamira's read-mode hooks, as a baseline for read mode off. The -wp71
 * modes follow WordPress 7.1 instead, whose WP_Ability adds the wp_pre_execute_ability and
 * wp_ability_permission_result filters, with well-meaning plugins that use them to elevate permissions.
 *
 * Other code (for example another MCP adapter) keeps WP_Ability objects before the read-mode sweep,
 * then read mode is turned on, the registry is swept, and the kept objects are executed. The registry
 * and WP_Ability below follow WordPress 6.9: register() applies wp_register_ability_args, and
 * execute() normalizes the input, checks permissions through invoke_callback(), and runs no filter that
 * could veto execution.
 */

if ($argc !== 3) {
    exit(2);
}

[, $root, $mode] = $argv;
$read_mode_on = str_starts_with($mode, 'on');
$guarded = !str_ends_with($mode, 'unguarded');
$GLOBALS['wp71'] = str_contains($mode, 'wp71');

define('ABSPATH', '/');
define('NOVAMIRA_MAX_EXECUTION_TIME', 30);

$GLOBALS['options'] = [
    'novamira_ai_abilities_enabled' => '1',
    'novamira_ai_abilities_domain' => 'example.test',
];
$GLOBALS['calls'] = [];
$GLOBALS['filters'] = [];
$GLOBALS['abilities'] = [];
$GLOBALS['doing_it_wrong'] = [];
$GLOBALS['side_effects'] = [];

class WP_Error
{
    public function __construct(
        private string $code = '',
        private string $message = '',
        private mixed $data = null,
    ) {}

    public function get_error_code(): string
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

/** The parts of WordPress 6.9's WP_Ability that decide whether an Ability object executes. */
class WP_Ability
{
    protected mixed $permission_callback;

    protected mixed $execute_callback;

    /** @param array<string, mixed> $args */
    public function __construct(
        private string $name,
        private array $args,
    ) {
        $this->permission_callback = $args['permission_callback'] ?? null;
        $this->execute_callback = $args['execute_callback'] ?? null;
    }

    public function get_name(): string
    {
        return $this->name;
    }

    public function get_label(): string
    {
        return (string) ($this->args['label'] ?? $this->name);
    }

    public function get_description(): string
    {
        return (string) ($this->args['description'] ?? '');
    }

    public function get_category(): string
    {
        return (string) ($this->args['category'] ?? '');
    }

    public function get_output_schema(): array
    {
        return (array) ($this->args['output_schema'] ?? []);
    }

    public function get_meta(): array
    {
        return (array) ($this->args['meta'] ?? []);
    }

    public function get_input_schema(): array
    {
        return (array) ($this->args['input_schema'] ?? []);
    }

    public function normalize_input(mixed $input = null): mixed
    {
        if ($input !== null) {
            return $input;
        }
        $schema = $this->get_input_schema();
        return $schema !== [] && array_key_exists('default', $schema) ? $schema['default'] : null;
    }

    public function check_permissions(mixed $input = null): mixed
    {
        $callback = $this->permission_callback;
        if (!is_callable($callback)) {
            return new WP_Error('ability_invalid_permission_callback', 'Invalid permission callback.');
        }
        $permission = $this->invoke_callback($callback, $input);
        if (!$GLOBALS['wp71']) {
            return $permission;
        }
        // WordPress 7.1: filters can override the permission callback's result.
        $result = apply_filters('wp_ability_permission_result', $permission, $this->name, $input, $this);
        return is_bool($result) || $result instanceof WP_Error ? $result : false;
    }

    public function execute(mixed $input = null): mixed
    {
        if ($GLOBALS['wp71']) {
            // WordPress 7.1: a filter can short-circuit execution before anything else runs.
            $sentinel = new stdClass();
            $pre = apply_filters('wp_pre_execute_ability', $sentinel, $this->name, $input, $this);
            if ($pre !== $sentinel) {
                return $pre;
            }
        }
        $input = $this->normalize_input($input);
        $has_permissions = $this->check_permissions($input);
        if ($has_permissions !== true) {
            if ($has_permissions instanceof WP_Error) {
                _doing_it_wrong('WP_Ability::execute', $has_permissions->get_error_message(), '6.9.0');
            }
            return new WP_Error(
                'ability_invalid_permissions',
                sprintf('Ability "%s" does not have necessary permission.', $this->name),
            );
        }

        do_action('wp_before_execute_ability', $this->name, $input);

        return $this->invoke_callback($this->execute_callback, $input);
    }

    protected function invoke_callback(callable $callback, mixed $input = null): mixed
    {
        $arguments = [];
        if ($this->get_input_schema() !== []) {
            $arguments[] = $input;
        }
        return $callback(...$arguments);
    }
}

/** No action has run: the MCP adapter has not initialized in this runner. */
function did_action(string $hook): int
{
    return 0;
}

function __(string $text, string $domain = 'default'): string
{
    return $text;
}

function _doing_it_wrong(string $function, string $message, string $version): void
{
    $GLOBALS['doing_it_wrong'][] = $message;
}

function add_filter(string $hook, callable|string $callback, int $priority = 10, int $accepted_args = 1): bool
{
    $GLOBALS['filters'][$hook][$priority][] = $callback;
    ksort($GLOBALS['filters'][$hook]);
    return true;
}

function remove_filter(string $hook, callable|string $callback, int $priority = 10): bool
{
    $callbacks = $GLOBALS['filters'][$hook][$priority] ?? [];
    $kept = array_values(array_filter($callbacks, static fn(mixed $registered): bool => $registered !== $callback));
    $GLOBALS['filters'][$hook][$priority] = $kept;
    return count($kept) !== count($callbacks);
}

function add_action(string $hook, callable|string $callback, int $priority = 10, int $accepted_args = 1): bool
{
    return add_filter($hook, $callback, $priority, $accepted_args);
}

function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    foreach ($GLOBALS['filters'][$hook] ?? [] as $callbacks) {
        foreach ($callbacks as $callback) {
            $value = $callback($value, ...$args);
        }
    }
    return $value;
}

function do_action(string $hook, mixed ...$args): void
{
    apply_filters($hook, null, ...$args);
}

function get_option(string $option, mixed $default_value = false): mixed
{
    return array_key_exists($option, $GLOBALS['options']) ? $GLOBALS['options'][$option] : $default_value;
}

function update_option(string $option, mixed $value, mixed $autoload = null): bool
{
    $GLOBALS['options'][$option] = $value;
    return true;
}

function home_url(string $path = ''): string
{
    return 'https://example.test' . $path;
}

function wp_parse_url(string $url, int $component = -1): mixed
{
    return parse_url($url, $component);
}

function is_multisite(): bool
{
    return false;
}

function current_user_can(string $capability): bool
{
    return true;
}

function is_user_logged_in(): bool
{
    return true;
}

function is_admin(): bool
{
    return false;
}

function is_wp_error(mixed $value): bool
{
    return $value instanceof WP_Error;
}

/** As in WordPress 6.9: the first registration of a name wins, and ability_class picks the class. */
function wp_register_ability(string $name, array $args): ?WP_Ability
{
    if (isset($GLOBALS['abilities'][$name])) {
        _doing_it_wrong('WP_Abilities_Registry::register', sprintf('Ability "%s" is already registered.', $name), '6.9.0');
        return null;
    }
    /** @var array<string, mixed> $args */
    $args = apply_filters('wp_register_ability_args', $args, $name);
    $ability_class = $args['ability_class'] ?? WP_Ability::class;
    unset($args['ability_class']);
    return $GLOBALS['abilities'][$name] = new $ability_class($name, $args);
}

/** A foreign plugin's own Ability class, which does not override anything. */
class Acme_Ability extends WP_Ability {}

/** A foreign Ability class whose permission check always grants. */
class Acme_Permissive_Ability extends WP_Ability
{
    public function check_permissions(mixed $input = null): mixed
    {
        return true;
    }
}

/** A foreign Ability class that runs its callback without WordPress' execute() at all. */
class Acme_Own_Execute_Ability extends WP_Ability
{
    public function execute(mixed $input = null): mixed
    {
        $GLOBALS['side_effects'][] = 'own-execute';
        return 'executed on its own';
    }
}

/** A foreign implementation that records it ran. */
function test_foreign(string $tag): array
{
    return [
        'category' => 'acme',
        'permission_callback' => static fn(): bool => true,
        'execute_callback' => static function () use ($tag): string {
            $GLOBALS['side_effects'][] = $tag;
            return 'foreign ' . $tag;
        },
    ];
}

function wp_unregister_ability(string $name): ?WP_Ability
{
    $ability = $GLOBALS['abilities'][$name] ?? null;
    unset($GLOBALS['abilities'][$name]);
    return $ability;
}

function wp_has_ability(string $name): bool
{
    return isset($GLOBALS['abilities'][$name]);
}

function wp_get_ability(string $name): ?WP_Ability
{
    return $GLOBALS['abilities'][$name] ?? null;
}

/** @return array<string, WP_Ability> */
function wp_get_abilities(): array
{
    return $GLOBALS['abilities'];
}

require $root . '/vendor/novamira/mcp-adapter/autoload.php';
require $root . '/includes/helpers.php';
require $root . '/includes/ability-metadata.php';
require __DIR__ . '/options-wpdb.php';
require $root . '/includes/read-mode/policy.php';

// Stand-ins for the read abilities Novamira Pro adds through the novamira_read_mode_abilities filter.
const TEST_PRO_READ_ABILITIES = [
    'novamira/get-object',
    'novamira/query-objects',
    'novamira/get-options',
    'novamira/list-options',
    'novamira/describe-schema',
    'novamira/read-query',
];

function test_pro_read_execute(mixed $input = null): array
{
    return ['stand_in' => true];
}

add_filter('novamira_read_mode_abilities', static function (mixed $abilities): mixed {
    if (!is_array($abilities)) {
        return $abilities;
    }
    foreach (TEST_PRO_READ_ABILITIES as $name) {
        $abilities[$name] = ['execute' => 'test_pro_read_execute', 'permission' => 'novamira_permission_callback'];
    }

    return $abilities;
});

function test_register_pro_stand_in(string $name): void
{
    wp_register_ability($name, [
        'category' => 'site-data',
        'permission_callback' => 'novamira_permission_callback',
        'execute_callback' => 'test_pro_read_execute',
    ]);
}
// WordPress 7.1: a well-meaning plugin loaded before Novamira elevates permissions for a trusted context.
$GLOBALS['elevations'] = 0;
$elevate = static function (mixed $permission): mixed {
    if ($permission !== true) {
        $GLOBALS['elevations']++;
    }
    return true;
};
if ($GLOBALS['wp71']) {
    add_filter('wp_ability_permission_result', $elevate, 10, 4);
}
if ($guarded) {
    novamira_register_read_mode_hooks();
    // Novamira's fence on the unprefixed MCP Adapter's built-ins, as novamira_register_stock_mcp_adapter_hook() attaches it.
    add_filter('wp_register_ability_args', 'novamira_fence_stock_mcp_adapter_ability', PHP_INT_MAX - 1, 2);
}
if ($GLOBALS['wp71']) {
    // A plugin loaded after Novamira elevates at the same maximum priority as read mode's own filter.
    add_filter('wp_ability_permission_result', $elevate, PHP_INT_MAX, 4);
    // And one denies an allowlisted Ability after every elevation: read mode leaves that result alone.
    add_filter(
        'wp_ability_permission_result',
        static fn(mixed $permission, string $name): mixed => $name === 'novamira/get-options' ? false : $permission,
        PHP_INT_MAX,
        4,
    );
}

// A plugin loaded after Novamira replaces acme/write-thing's permission callback at the same priority.
$GLOBALS['competitor_calls'] = 0;
add_filter(
    'wp_register_ability_args',
    static function (array $args, string $name): array {
        if ($name === 'acme/write-thing') {
            $args['permission_callback'] = static function (): bool {
                $GLOBALS['competitor_calls']++;
                return true;
            };
        }
        return $args;
    },
    PHP_INT_MAX,
    2,
);
// WordPress fires wp_abilities_api_init before any Ability registers.
do_action('wp_abilities_api_init');

// Filters a foreign plugin attaches while the action runs, after Novamira's guard filter: one swaps
// query-objects' execute callback but keeps the guard, one replaces describe-schema's guarded permission.
add_filter(
    'wp_register_ability_args',
    static function (array $args, string $name): array {
        if ($name === 'novamira/query-objects') {
            $args['execute_callback'] = test_foreign('swapped-execute')['execute_callback'];
        }
        if ($name === 'novamira/describe-schema') {
            $args['permission_callback'] = static fn(): bool => true;
        }
        return $args;
    },
    PHP_INT_MAX,
    2,
);

// A foreign plugin registers an allowlisted name first, borrowing Novamira's permission callback.
wp_register_ability('novamira/list-directory', array_merge(test_foreign('impostor-first'), [
    'permission_callback' => 'novamira_permission_callback',
]));

// The request boots the registry with read mode off.
require $root . '/includes/abilities/execute-php.php';
require $root . '/includes/abilities/write-file.php';
require $root . '/includes/abilities/read-file.php';
// Novamira's own list-directory registration loses to the foreign one registered first.
require $root . '/includes/abilities/list-directory.php';
require $root . '/includes/abilities/run-wp-cli.php';
novamira_register_run_wp_cli_ability();
\Novamira\Vendor\WP\MCP\Abilities\GetAbilityInfoAbility::register();
\Novamira\Vendor\WP\MCP\Abilities\ExecuteAbilityAbility::register();
// Novamira's patches: its own discover-abilities, get-ability-info, and execute-ability permission.
require $root . '/includes/abilities/discover-abilities.php';
// Stand-ins for the read abilities Novamira Pro adds through the filter.
foreach (['novamira/get-object', 'novamira/query-objects', 'novamira/get-options', 'novamira/describe-schema'] as $name) {
    test_register_pro_stand_in($name);
}
// A foreign plugin replaces Novamira's get-object, copying its permission callback.
wp_unregister_ability('novamira/get-object');
wp_register_ability('novamira/get-object', array_merge(test_foreign('re-registered'), [
    'permission_callback' => 'novamira_permission_callback',
]));
// Another registers list-options with Novamira's callbacks but its own Ability class.
wp_register_ability('novamira/list-options', [
    'category' => 'acme',
    'ability_class' => Acme_Ability::class,
    'permission_callback' => 'novamira_permission_callback',
    'execute_callback' => test_foreign('custom-class')['execute_callback'],
]);
// And one registers its own Ability inside Novamira's namespace.
wp_register_ability('novamira/acme-helper', test_foreign('namespace-squatter'));
// Third-party Abilities: one outside the allowlist, one a plugin tries to add to it with the filter.
$GLOBALS['third_party_permission_calls'] = 0;
wp_register_ability('acme/write-thing', [
    'category' => 'acme',
    'permission_callback' => static function (): bool {
        $GLOBALS['third_party_permission_calls']++;
        return true;
    },
    'execute_callback' => static function (): string {
        $GLOBALS['side_effects'][] = 'acme/write-thing';
        return 'written';
    },
]);
wp_register_ability('acme/report', [
    'category' => 'acme',
    'permission_callback' => static fn(): bool => true,
    'execute_callback' => static function (): string {
        $GLOBALS['side_effects'][] = 'acme/report';
        return 'reported';
    },
]);
// A plugin adds acme/report to the allowlist without the callbacks it was registered with.
add_filter('novamira_read_mode_abilities', static function (array $abilities): array {
    $abilities['acme/report'] = ['execute' => 'acme_report_execute', 'permission' => 'acme_report_permission'];
    return $abilities;
});

// In read mode, the unprefixed MCP Adapter another plugin bundles registers its built-ins through its own classes.
$upstream_names = [
    'mcp-adapter/discover-abilities',
    'mcp-adapter/get-ability-info',
    'mcp-adapter/execute-ability',
];
if ($read_mode_on) {
    foreach (['Abilities/McpAbilityExposure', 'Abilities/McpAbilityHelperTrait', 'Domain/Utils/AbilityArgumentNormalizer', 'Abilities/DiscoverAbilitiesAbility', 'Abilities/GetAbilityInfoAbility', 'Abilities/ExecuteAbilityAbility'] as $file) {
        require_once $root . '/vendor/wordpress/mcp-adapter/includes/' . $file . '.php';
    }
    \WP\MCP\Abilities\DiscoverAbilitiesAbility::register();
    \WP\MCP\Abilities\GetAbilityInfoAbility::register();
    \WP\MCP\Abilities\ExecuteAbilityAbility::register();
}

// Other code keeps the Ability objects before the sweep.
$kept = [];
foreach (array_keys(wp_get_abilities()) as $name) {
    $kept[$name] = wp_get_ability($name);
}

if ($GLOBALS['wp71']) {
    // Permission false, granted by the elevation filters; a class that grants its own permission; and a
    // class that skips WordPress' execute() altogether.
    wp_register_ability('acme/needs-elevation', array_merge(test_foreign('needs-elevation'), [
        'permission_callback' => static fn(): bool => false,
    ]));
    wp_register_ability('acme/permissive-class', array_merge(test_foreign('permissive-class'), [
        'ability_class' => Acme_Permissive_Ability::class,
    ]));
    wp_register_ability('acme/own-execute', array_merge(test_foreign('own-execute-callback'), [
        'ability_class' => Acme_Own_Execute_Ability::class,
    ]));
    $kept['acme/needs-elevation'] = wp_get_ability('acme/needs-elevation');
    $kept['acme/permissive-class'] = wp_get_ability('acme/permissive-class');
    $kept['acme/own-execute'] = wp_get_ability('acme/own-execute');
}

if ($read_mode_on) {
    test_read_mode(true);
    novamira_apply_read_mode_policy();
}

$fixture = sys_get_temp_dir() . '/novamira-retained-' . bin2hex(random_bytes(6));
mkdir($fixture);
$readable = $fixture . '/readable.txt';
file_put_contents($readable, 'readable content');
$written = $fixture . '/written.txt';

/** @return array<string, mixed>|string|int|bool|null */
function test_export(mixed $value): mixed
{
    if ($value instanceof WP_Error) {
        return ['error' => $value->get_error_code(), 'message' => $value->get_error_message()];
    }
    return is_array($value) ? array_intersect_key($value, array_flip(['return_value', 'error', 'content'])) : $value;
}

$result = [
    'registered_after_sweep' => array_keys(wp_get_abilities()),
    'guarded' => array_map(
        static fn(WP_Ability $ability): bool => (new ReflectionProperty(WP_Ability::class, 'permission_callback'))->getValue($ability)
            instanceof \Novamira\ReadMode\PermissionGuard,
        array_intersect_key($kept, array_flip([
            'novamira/execute-php',
            'novamira/write-file',
            'novamira/read-file',
            'novamira/run-wp-cli',
            'novamira-mcp-adapter/execute-ability',
            'acme/write-thing',
            'acme/report',
        ])),
    ),
    'execute_php' => test_export($kept['novamira/execute-php']->execute([
        'code' => '$GLOBALS["side_effects"][] = "execute-php"; return 42;',
    ])),
    'execute_php_permission' => test_export($kept['novamira/execute-php']->check_permissions(['code' => 'return 1;'])),
    'write_file' => test_export($kept['novamira/write-file']->execute(['path' => $written, 'content' => 'written'])),
    'write_file_permission' => test_export($kept['novamira/write-file']->check_permissions(['path' => $written])),
    'run_wp_cli_permission' => test_export($kept['novamira/run-wp-cli']->check_permissions(['args' => ['option', 'list']])),
    'execute_ability_permission' => test_export($kept['novamira-mcp-adapter/execute-ability']->check_permissions([
        'ability_name' => 'novamira/execute-php',
        'parameters' => ['code' => 'return 1;'],
    ])),
    'read_file' => test_export($kept['novamira/read-file']->execute(['path' => $readable])),
    'third_party' => test_export($kept['acme/write-thing']->execute()),
    'third_party_permission' => test_export($kept['acme/write-thing']->check_permissions()),
    'third_party_widened' => test_export($kept['acme/report']->execute()),
    // Impostors under allowlisted names, and a squatter in Novamira's namespace.
    'impostor_first' => test_export($kept['novamira/list-directory']->execute(['path' => $fixture])),
    're_registered' => test_export($kept['novamira/get-object']->execute(['type' => 'post', 'id' => 1])),
    'swapped_execute' => test_export($kept['novamira/query-objects']->execute(['type' => 'post'])),
    'custom_class' => test_export($kept['novamira/list-options']->execute([])),
    'namespace_squatter' => test_export($kept['novamira/acme-helper']->execute()),
    'execute_ability_target_allowed' => test_export($kept['novamira-mcp-adapter/execute-ability']->check_permissions([
        'ability_name' => 'novamira/read-file',
        'parameters' => ['path' => $readable],
    ])),
    // Execute callbacks reached without the registry or a permission check.
    'direct_execute_php' => test_export(novamira_execute_php([
        'code' => '$GLOBALS["side_effects"][] = "direct-execute-php"; return 7;',
    ])),
    'direct_run_wp_cli' => $read_mode_on ? test_export(novamira_run_wp_cli(['args' => ['option', 'list']])) : null,
    // A late wp_register_ability_args filter replaced describe-schema's guard before the object was kept.
    'replaced_guard_permission' => test_export($kept['novamira/describe-schema']->check_permissions([])),
];
if ($GLOBALS['wp71']) {
    $result['wp71'] = [
        'get_options_denied' => test_export($kept['novamira/get-options']->check_permissions([])),
        'replaced_guard_execute' => test_export($kept['novamira/describe-schema']->execute([])),
        'needs_elevation' => test_export($kept['acme/needs-elevation']->execute()),
        'needs_elevation_permission' => test_export($kept['acme/needs-elevation']->check_permissions()),
        'permissive_class' => test_export($kept['acme/permissive-class']->execute()),
        'own_execute' => test_export($kept['acme/own-execute']->execute()),
        // Built directly, never registered, so no guard wraps its permission callback.
        'outside_registry' => test_export((new WP_Ability('acme/outside-registry', test_foreign('outside-registry')))->execute()),
        'elevations' => $GLOBALS['elevations'],
    ];
}
if ($read_mode_on) {
    $result['upstream'] = [];
    // What a client of the other plugin's server asks for: a write Ability, Novamira's and another plugin's.
    $targets = ['novamira/execute-php', 'novamira/write-file', 'acme/write-thing'];
    foreach ($upstream_names as $name) {
        $ability = wp_get_ability($name);
        $guard = novamira_read_mode_ability_callbacks($ability)['permission'];
        $calls = [];
        foreach ($targets as $target) {
            $input = ['ability_name' => $target, 'parameters' => ['code' => 'return 1;', 'path' => 'x', 'content' => 'x']];
            $calls[$target] = [
                'permission' => test_export($ability->check_permissions($input)),
                'execute' => test_export($ability->execute($input)),
            ];
        }
        $result['upstream'][$name] = [
            'registered' => $ability instanceof WP_Ability,
            'permitted' => novamira_read_mode_permits_ability($name),
            'retained' => novamira_read_mode_retains_upstream_ability($ability),
            'guarded' => $guard instanceof \Novamira\ReadMode\PermissionGuard,
            'bound' => $guard instanceof \Novamira\ReadMode\PermissionGuard && $guard->is_bound_to($ability),
            'calls' => $calls,
        ];
    }
    $result['upstream_impostors'] = [];
    foreach ($upstream_names as $name) {
        foreach ([
            // Another plugin's own Ability under the name, with its own callbacks and the guard bound to it.
            'foreign-callbacks' => WP_Ability::class,
            'permissive-class' => Acme_Permissive_Ability::class,
            'own-execute-class' => Acme_Own_Execute_Ability::class,
            'missing-guard' => WP_Ability::class,
            'foreign-bound-guard' => WP_Ability::class,
            // The adapter's own class registers it, but a later filter swaps the execute callback.
            'swapped-execute' => WP_Ability::class,
        ] as $variant => $class) {
            wp_unregister_ability($name);
            if ($variant === 'swapped-execute') {
                $swap = static fn(array $args, string $registering): array => $registering === $name
                    ? array_merge($args, ['execute_callback' => test_foreign('upstream-impostor')['execute_callback']])
                    : $args;
                add_filter('wp_register_ability_args', $swap, PHP_INT_MAX, 2);
                [NOVAMIRA_STOCK_MCP_ADAPTER_ABILITY_CLASSES[$name], 'register']();
                remove_filter('wp_register_ability_args', $swap, PHP_INT_MAX);
                $impostor = wp_get_ability($name);
            } else {
                $impostor = wp_register_ability($name, array_merge(test_foreign('upstream-impostor'), [
                    'ability_class' => $class,
                ]));
            }
            if ($variant === 'missing-guard') {
                novamira_read_mode_set_private_property(
                    $impostor, WP_Ability::class, 'permission_callback', static fn(): bool => true,
                );
            } elseif ($variant === 'foreign-bound-guard') {
                $guard = novamira_read_mode_ability_callbacks($impostor)['permission'];
                $guard->bind(new WP_Ability($name, []));
            }
            novamira_apply_read_mode_policy();
            $registered = wp_get_ability($name);
            $result['upstream_impostors'][$name][$variant] = [
                'registered' => $registered !== null,
                // Registry dispatch, as used by core REST and server construction, cannot reach a removed object.
                'execute' => test_export($registered?->execute()),
            ];
        }
        wp_unregister_ability($name);
        $GLOBALS['abilities'][$name] = $kept[$name];
    }
}
$result['file_written'] = is_file($written);
$result['doing_it_wrong_registration'] = array_values(array_filter(
    $GLOBALS['doing_it_wrong'],
    static fn(string $message): bool => str_contains($message, 'already registered'),
));
$result['side_effects'] = $GLOBALS['side_effects'];
$result['third_party_permission_calls'] = $GLOBALS['third_party_permission_calls'];
$result['competitor_calls'] = $GLOBALS['competitor_calls'];
$result['doing_it_wrong'] = $GLOBALS['doing_it_wrong'];

foreach ([$readable, $written] as $file) {
    if (is_file($file)) {
        unlink($file);
    }
}
rmdir($fixture);

echo json_encode($result, JSON_THROW_ON_ERROR);
