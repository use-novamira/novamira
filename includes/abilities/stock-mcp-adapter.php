<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Coexistence with the unprefixed MCP Adapter (`WP\MCP`) that other plugins may bundle next to Novamira's
 * scoped copy: its built-in Abilities are registered however the Abilities registry was booted, and none
 * of its servers reaches Novamira's Abilities.
 */

if (!defined('ABSPATH')) {
    exit();
}

/** The built-in Ability category of the unprefixed MCP Adapter that other plugins may bundle. */
const NOVAMIRA_STOCK_MCP_ADAPTER_CATEGORY = 'mcp-adapter';

/** The built-in Abilities of the unprefixed MCP Adapter that other plugins may bundle. */
const NOVAMIRA_STOCK_MCP_ADAPTER_ABILITIES = [
    'mcp-adapter/discover-abilities',
    'mcp-adapter/get-ability-info',
    'mcp-adapter/execute-ability',
];

/** The class of the unprefixed MCP Adapter that registers each of its built-in Abilities. */
const NOVAMIRA_STOCK_MCP_ADAPTER_ABILITY_CLASSES = [
    'mcp-adapter/discover-abilities' => 'WP\\MCP\\Abilities\\DiscoverAbilitiesAbility',
    'mcp-adapter/get-ability-info' => 'WP\\MCP\\Abilities\\GetAbilityInfoAbility',
    'mcp-adapter/execute-ability' => 'WP\\MCP\\Abilities\\ExecuteAbilityAbility',
];

/**
 * Attach the coexistence hooks. Attached whether or not Novamira is enabled, because Novamira's admin
 * screens read the Abilities registry and Novamira Pro may register Abilities either way.
 */
function novamira_register_stock_mcp_adapter_hook(): bool
{
    if (!novamira_wordpress_abilities_supported()) {
        return false;
    }

    add_action('mcp_adapter_init', callback: 'novamira_catch_up_stock_mcp_adapter_abilities', priority: PHP_INT_MIN);
    // Before read mode's guard, which wraps whatever permission callback is final at PHP_INT_MAX.
    add_filter(
        'wp_register_ability_args',
        callback: 'novamira_fence_stock_mcp_adapter_ability',
        priority: PHP_INT_MAX - 1,
        accepted_args: 2,
    );
    // Every server of the unprefixed adapter, whoever created it, loses the components that run a Novamira
    // Ability once every server exists, and again when a request reaches the servers.
    add_action('mcp_adapter_init', callback: 'novamira_attach_stock_mcp_server_pruning', priority: PHP_INT_MAX - 1);
    add_filter(
        'rest_request_before_callbacks',
        callback: 'novamira_prune_stock_mcp_servers_on_entry',
        priority: PHP_INT_MIN,
    );
    add_filter(
        'mcp_adapter_enable_stdio_transport',
        callback: 'novamira_prune_stock_mcp_servers_on_entry',
        priority: PHP_INT_MIN,
    );
    // Defence in depth for a component that is still held by a server: refused right before it runs.
    foreach (['mcp_adapter_pre_tool_call', 'mcp_adapter_pre_resource_read', 'mcp_adapter_pre_prompt_get'] as $hook) {
        add_filter(
            $hook,
            callback: 'novamira_refuse_novamira_stock_mcp_component',
            priority: PHP_INT_MAX,
            accepted_args: 3,
        );
    }

    return true;
}

/**
 * Register the built-in category and Abilities of an unprefixed MCP Adapter that initialized too late.
 *
 * That adapter attaches `register_default_category` to `wp_abilities_api_categories_init` and
 * `register_default_abilities` to `wp_abilities_api_init` only from its own `init()` (`rest_api_init:15`,
 * or `init:20` under WP-CLI), and only when it builds its default server. WordPress fires each action once,
 * when its registry is first used. When a registry was used before that `init()` (by Novamira's adapter on
 * `rest_api_init:15`, its admin screens, or its REST routes in the same request), or while the action runs
 * at or past the registrar's priority, WordPress never calls the registrar: the built-ins are missing and
 * the adapter's default server logs each of them as missing.
 *
 * Runs first on `mcp_adapter_init`, which the adapter fires from `init()` after deciding whether to build
 * its default server and before building it. For each registrar the adapter attached to an action that has
 * fired or is running, the pending registrar is removed, so it can never register a second time, and the
 * adapter's own registration runs now, as a callback of that action. The category is repaired on its own:
 * the categories registry can boot before the Abilities registry, which then registers the built-ins only
 * if the category exists. Nothing is done for an adapter that decided against its default server (its
 * registrars are absent), nor for an action that has not fired yet (WordPress calls the registrar). Late
 * Abilities miss the policy sweep that ends `wp_abilities_api_init`, so it is applied again: Hub rules and
 * read mode treat them exactly as if they had been registered in time.
 */
function novamira_catch_up_stock_mcp_adapter_abilities(mixed $adapter): void
{
    if (!$adapter instanceof \WP\MCP\Core\McpAdapter) {
        return;
    }

    if (novamira_take_over_stock_mcp_adapter_registrar('wp_abilities_api_categories_init', [
        $adapter,
        'register_default_category',
    ])) {
        if (!wp_has_ability_category(NOVAMIRA_STOCK_MCP_ADAPTER_CATEGORY)) {
            novamira_run_as_action_callback('wp_abilities_api_categories_init', static function () use (
                $adapter,
            ): void {
                $adapter->register_default_category();
            });
        }
    }

    if (!novamira_take_over_stock_mcp_adapter_registrar('wp_abilities_api_init', [
        $adapter,
        'register_default_abilities',
    ])) {
        return;
    }

    foreach (NOVAMIRA_STOCK_MCP_ADAPTER_ABILITIES as $ability_name) {
        if (wp_has_ability($ability_name)) {
            return;
        }
    }

    novamira_run_as_action_callback('wp_abilities_api_init', static function () use ($adapter): void {
        $adapter->register_default_abilities();
    });

    if (function_exists('novamira_apply_ability_policy')) {
        novamira_apply_ability_policy();
    }
}

/**
 * Remove a registrar the adapter attached to an action that has fired or is running, and report whether it
 * did. WordPress would never call it there, or, while the action runs below its priority, would call it
 * after the adapter's server has been built.
 *
 * @param array{0: object, 1: string} $registrar
 */
function novamira_take_over_stock_mcp_adapter_registrar(string $hook_name, array $registrar): bool
{
    if (did_action($hook_name) === 0) {
        return false;
    }

    $priority = has_action($hook_name, $registrar);
    if (!is_int($priority)) {
        return false;
    }

    return remove_action($hook_name, $registrar, $priority);
}

/**
 * Run one callback of an action as WordPress runs it: with the action on the current-filter stack, which
 * is what doing_action() reads. The action itself is not fired, so no other callback runs and did_action()
 * is unchanged. The stack is restored as it was, even when the callback throws from a nested hook.
 */
function novamira_run_as_action_callback(string $hook_name, Closure $callback): void
{
    // @mago-expect lint:no-global -- doing_action() reads the running hooks from this WordPress global.
    global $wp_current_filter;
    if (!is_array($wp_current_filter)) {
        return;
    }

    $running = $wp_current_filter;
    $wp_current_filter[] = $hook_name;
    try {
        $callback();
    } finally {
        $wp_current_filter = $running;
    }
}

/**
 * Whether an Ability belongs to Novamira: its own namespaces, or an Ability a Novamira feature owns, which
 * includes those Novamira Pro registers as features.
 */
function novamira_is_novamira_owned_ability(string $ability_name): bool
{
    if (str_starts_with($ability_name, 'novamira/') || str_starts_with($ability_name, 'novamira-mcp-adapter/')) {
        return true;
    }

    if (!function_exists('Novamira\Features\features')) {
        return false;
    }

    $ability = wp_has_ability($ability_name) ? wp_get_ability($ability_name) : null;

    return \Novamira\Features\features()->feature_for_ability($ability_name, $ability?->get_category() ?? '') !== null;
}

/**
 * Keep the built-in Abilities of every unprefixed MCP Adapter away from Novamira's Abilities.
 *
 * Those Abilities are a generic gateway: any server of that adapter that lists them lets a client list
 * every MCP-public Ability (discover-abilities), read its schemas (get-ability-info), and run it
 * (execute-ability), with only the `read` capability checked by the adapter itself. Novamira's Abilities
 * are reachable through Novamira's own MCP servers, which run Novamira's connection checks, and through
 * WordPress's Abilities REST API, Novamira's REST API, WP-CLI, and PHP. Another plugin's server is none of
 * those, so for a Novamira Ability, execute-ability and get-ability-info answer as for an Ability that does
 * not exist, and discover-abilities leaves it out. Every other Ability is handled by the adapter unchanged.
 *
 * Applied to the registration arguments, so it holds whichever copy of the adapter registers these names,
 * whenever it registers them. Novamira's own copy registers its built-ins under `novamira-mcp-adapter/`.
 * When the callbacks are the adapter's own classes', both are wrapped and the pair is remembered as the
 * provenance read mode checks before it keeps these names registered.
 */
function novamira_fence_stock_mcp_adapter_ability(mixed $args, mixed $ability_name): mixed
{
    if (
        !is_array($args)
        || !is_string($ability_name)
        || !in_array($ability_name, NOVAMIRA_STOCK_MCP_ADAPTER_ABILITIES, strict: true)
    ) {
        return $args;
    }

    // @mago-expect analysis:mixed-assignment -- Registration arguments are untyped until checked below.
    $permission_callback = $args['permission_callback'] ?? null;
    // @mago-expect analysis:mixed-assignment -- Registration arguments are untyped until checked below.
    $execute_callback = $args['execute_callback'] ?? null;
    if (!is_callable($permission_callback) || !is_callable($execute_callback)) {
        return $args;
    }

    $fenced = novamira_fence_stock_mcp_adapter_callbacks($ability_name, $permission_callback, $execute_callback);
    $args['permission_callback'] = $fenced['permission'];
    $args['execute_callback'] = $fenced['execute'];
    if (novamira_stock_mcp_adapter_callbacks_are_its_own($ability_name, $permission_callback, $execute_callback)) {
        novamira_remember_stock_mcp_adapter_ability($ability_name, $fenced['permission'], $fenced['execute']);
    }

    return $args;
}

/**
 * The callbacks novamira_fence_stock_mcp_adapter_ability() registers in place of a built-in's: closures made
 * for this registration only. discover-abilities leaves Novamira's Abilities out of its result; the other
 * two answer for a Novamira Ability as for one that does not exist.
 *
 * @return array{permission: Closure, execute: Closure}
 */
function novamira_fence_stock_mcp_adapter_callbacks(
    string $ability_name,
    callable $permission_callback,
    callable $execute_callback,
): array {
    if ($ability_name === 'mcp-adapter/discover-abilities') {
        return [
            'permission' => static fn(mixed ...$arguments): mixed => $permission_callback(...$arguments),
            'execute' => static fn(mixed ...$arguments): mixed => novamira_hide_novamira_abilities($execute_callback(
                ...$arguments,
            )),
        ];
    }

    return [
        'permission' => static fn(mixed ...$arguments): mixed => (
            novamira_stock_mcp_adapter_refusal($arguments[0] ?? null) ?? $permission_callback(...$arguments)
        ),
        'execute' => static fn(mixed ...$arguments): mixed => (
            novamira_stock_mcp_adapter_refusal($arguments[0] ?? null) ?? $execute_callback(...$arguments)
        ),
    ];
}

/**
 * Whether registration callbacks are the ones the unprefixed adapter's own class for that built-in
 * Ability passes, as its static register() does: the class's check_permission() and execute().
 */
function novamira_stock_mcp_adapter_callbacks_are_its_own(
    string $ability_name,
    mixed $permission_callback,
    mixed $execute_callback,
): bool {
    $class = NOVAMIRA_STOCK_MCP_ADAPTER_ABILITY_CLASSES[$ability_name] ?? null;

    return (
        $class !== null
        && $permission_callback === [$class, 'check_permission']
        && $execute_callback === [$class, 'execute']
    );
}

/**
 * The callbacks novamira_fence_stock_mcp_adapter_ability() built for each registration of a built-in by the
 * unprefixed adapter's own class.
 *
 * This is the provenance read mode relies on to keep those Abilities registered: the callbacks are
 * closures created for one registration only, so an Ability holds the same objects only when it was
 * registered through the adapter's own classes, whether WordPress called the adapter's registrar or
 * novamira_catch_up_stock_mcp_adapter_abilities() did, and no later filter replaced them. Any other
 * registration under the same names, whatever its class or callbacks, is not recognised.
 *
 * @return array<string, list<array{Closure, Closure}>>
 */
function &novamira_stock_mcp_adapter_provenance(): array
{
    /** @var array<string, list<array{Closure, Closure}>> $genuine */
    static $genuine = [];

    return $genuine;
}

/** Remember the callbacks the fence built for a registration by the adapter's own class. */
function novamira_remember_stock_mcp_adapter_ability(string $ability_name, Closure $permission, Closure $execute): void
{
    $genuine = &novamira_stock_mcp_adapter_provenance();
    $genuine[$ability_name][] = [$permission, $execute];
}

/** Whether a built-in's callbacks are ones the fence built for a registration by the adapter's own class. */
function novamira_stock_mcp_adapter_ability_is_genuine(
    string $ability_name,
    mixed $permission_callback,
    mixed $execute_callback,
): bool {
    foreach (novamira_stock_mcp_adapter_provenance()[$ability_name] ?? [] as [$permission, $execute]) {
        if ($permission === $permission_callback && $execute === $execute_callback) {
            return true;
        }
    }

    return false;
}

/** The answer of the adapter's get-ability-info and execute-ability for a Novamira Ability, or null. */
function novamira_stock_mcp_adapter_refusal(mixed $input): ?WP_Error
{
    /** @var mixed $target */
    $target = is_array($input) ? $input['ability_name'] ?? null : null;
    if (!is_string($target) || !novamira_is_novamira_owned_ability($target)) {
        return null;
    }

    return new WP_Error('ability_not_found', sprintf("Ability '%s' not found", $target));
}

/** Leave Novamira's Abilities out of a discover-abilities result of the adapter. */
function novamira_hide_novamira_abilities(mixed $result): mixed
{
    if (!is_array($result) || !is_array($result['abilities'] ?? null)) {
        return $result;
    }

    $result['abilities'] = array_values(array_filter($result['abilities'], static function (mixed $entry): bool {
        /** @var mixed $name */
        $name = is_array($entry) ? $entry['name'] ?? null : null;

        return !is_string($name) || !novamira_is_novamira_owned_ability($name);
    }));

    return $result;
}

/**
 * Whether a component of the unprefixed adapter runs a Novamira Ability, failing closed.
 *
 * McpTool, McpResource, and McpPrompt::fromAbility() keep the Ability they wrap in a private `ability`, which
 * is what check_permission() and execute() call; the same classes built from a callable or a prompt builder
 * leave it null and report that source in their observability context. When the field holds an Ability, its
 * owner decides. When it is null, or when the class does not declare it (another layout of the adapter), the
 * component counts as Novamira's unless it reports an Ability another plugin owns, or a callable or builder
 * source: a component that wraps an Ability Novamira cannot identify is never kept.
 */
function novamira_stock_mcp_component_runs_novamira_ability(mixed $component): bool
{
    if (
        !$component instanceof \WP\MCP\Domain\Tools\McpTool
        && !$component instanceof \WP\MCP\Domain\Resources\McpResource
        && !$component instanceof \WP\MCP\Domain\Prompts\McpPrompt
    ) {
        return false;
    }

    $class = get_class($component);
    if (property_exists($class, 'ability')) {
        $read = Closure::bind(
            // @mago-expect analysis:ambiguous-object-property-access -- Declared by the component class, checked above.
            static fn(object $target): mixed => $target->ability,
            newThis: null,
            newScope: $class,
        );
        /** @var mixed $ability */
        $ability = $read instanceof Closure ? $read($component) : false;
        if ($ability instanceof WP_Ability) {
            return novamira_is_novamira_owned_ability($ability->get_name());
        }
        if ($ability !== null) {
            return true;
        }
    }

    $context = $component->get_observability_context();
    /** @var mixed $source */
    $source = $context['source'] ?? null;
    if ($source === 'ability') {
        /** @var mixed $ability_name */
        $ability_name = $context['ability_name'] ?? null;

        return !is_string($ability_name) || novamira_is_novamira_owned_ability($ability_name);
    }

    return !in_array($source, ['array', 'builder'], strict: true);
}

/**
 * Prune the servers of the unprefixed adapter once every one of them exists.
 *
 * The adapter creates servers only while `mcp_adapter_init` runs (create_server() refuses otherwise), and a
 * server registers all its components when it is constructed. Attached from the last priority but one, the
 * pruning runs at PHP_INT_MAX after every callback attached there so far, so it sees every server created in
 * this run. A server created by a callback attached to PHP_INT_MAX after it is pruned when a request reaches
 * the servers.
 */
function novamira_attach_stock_mcp_server_pruning(): void
{
    remove_action('mcp_adapter_init', callback: 'novamira_prune_stock_mcp_servers', priority: PHP_INT_MAX);
    add_action('mcp_adapter_init', callback: 'novamira_prune_stock_mcp_servers', priority: PHP_INT_MAX);
}

/**
 * Prune the servers of the unprefixed adapter before a REST request or a STDIO session reaches them.
 * Filters rest_request_before_callbacks and mcp_adapter_enable_stdio_transport; the value is unchanged.
 */
function novamira_prune_stock_mcp_servers_on_entry(mixed $value): mixed
{
    if (did_action('mcp_adapter_init') > 0 && class_exists(\WP\MCP\Core\McpAdapter::class, autoload: false)) {
        novamira_prune_stock_mcp_servers(\WP\MCP\Core\McpAdapter::instance());
    }

    return $value;
}

/**
 * Remove every component that runs a Novamira Ability from every server of the unprefixed adapter.
 *
 * Those servers then hold no such component at all: tools/list, resources/list, and prompts/list do not
 * show it, and tools/call, resources/read, and prompts/get answer exactly as for a name that was never
 * registered, before any permission check, and whatever later filters return. Novamira's own servers belong
 * to the scoped copy and are not touched.
 */
function novamira_prune_stock_mcp_servers(mixed $adapter): void
{
    if (!$adapter instanceof \WP\MCP\Core\McpAdapter) {
        return;
    }

    foreach ($adapter->get_servers() as $server) {
        novamira_prune_stock_mcp_server($server);
    }
}

/** Remove the components that run a Novamira Ability from one server of the unprefixed adapter. */
function novamira_prune_stock_mcp_server(object $server): void
{
    $server_class = \WP\MCP\Core\McpServer::class;
    $registry_class = \WP\MCP\Core\McpComponentRegistry::class;
    if (!$server instanceof $server_class || !property_exists($server_class, 'component_registry')) {
        return;
    }

    $read_registry = Closure::bind(
        // @mago-expect analysis:ambiguous-object-property-access -- Declared by McpServer, checked above.
        static fn(object $target): mixed => $target->component_registry,
        newThis: null,
        newScope: $server_class,
    );
    /** @var mixed $registry */
    $registry = $read_registry instanceof Closure ? $read_registry($server) : null;
    if (!$registry instanceof $registry_class) {
        return;
    }

    $keep = static fn(mixed $component): bool => !novamira_stock_mcp_component_runs_novamira_ability($component);
    $prune = Closure::bind(
        static function (object $target) use ($keep): void {
            foreach (['tools', 'resources', 'prompts'] as $type) {
                $property = 'mcp_' . $type;
                if (!property_exists($target, $property)) {
                    continue;
                }
                // @mago-expect analysis:ambiguous-object-property-access,mixed-assignment -- A private array of McpComponentRegistry.
                $components = $target->{$property};
                if (!is_array($components)) {
                    continue;
                }
                // @mago-expect analysis:ambiguous-object-property-access -- A private array of McpComponentRegistry.
                $target->{$property} = array_filter($components, $keep);
            }
        },
        newThis: null,
        newScope: $registry_class,
    );
    if ($prune instanceof Closure) {
        $prune($registry);
    }
}

/**
 * Refuse a tools/call, resources/read, or prompts/get for a component that runs a Novamira Ability, should a
 * server still hold one. The servers are pruned before any request reaches them, so this only backs that up.
 */
function novamira_refuse_novamira_stock_mcp_component(mixed $arguments, mixed $name, mixed $component): mixed
{
    if (!novamira_stock_mcp_component_runs_novamira_ability($component)) {
        return $arguments;
    }

    return new WP_Error('ability_not_found', sprintf("'%s' not found", is_string($name) ? $name : ''));
}
