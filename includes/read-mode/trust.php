<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Read mode's trusted implementations: the allowlist bound to callbacks, not to names.
 *
 * An Ability is usable in read mode only when its name is on the allowlist AND the registered object
 * is the implementation the allowlist names: exactly WP_Ability (no custom ability_class), with the
 * execute callback and permission callback listed for that name. The list starts from the abilities
 * Novamira ships (NOVAMIRA_READ_MODE_BASE_ABILITIES) and is read once through the single
 * novamira_read_mode_abilities filter, so neither the first plugin to register a name nor a later
 * wp_register_ability_args filter can redefine the callbacks a name stands for.
 * ReadModePolicyTest checks the constant against the real registration files.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The abilities Novamira ships for read mode and, for each name, the callbacks of its implementation.
 *
 * Callbacks are named functions or static methods written as 'Class::method'. skill-get is included and
 * serves only the skills that declare read_mode. Excluded on purpose: skill prompts and Novamira Design,
 * which read mode does not offer; every Gutenberg
 * (Block Editor Queue) Ability, including the block type reads gutenberg-list-block-types and
 * gutenberg-get-block-type, which read mode does not offer either, and among them get-content, get-pending-batch, list-pending-batches, get-finalization-url and
 * get-finalizer-runtime abilities (they mark stale batches, release leases, or create the finalizer
 * poll token); and get-wp-cli-job (WP-CLI). Further read abilities, such as those of Novamira Pro,
 * join through the novamira_read_mode_abilities filter.
 */
const NOVAMIRA_READ_MODE_BASE_ABILITIES = [
    // Context and MCP discovery and dispatch.
    'novamira/agent-context' => [
        'execute' => 'novamira_build_agent_context',
        'permission' => 'novamira_permission_callback',
    ],
    'novamira-mcp-adapter/discover-abilities' => [
        'execute' => 'novamira_execute_discover_abilities',
        'permission' => 'novamira_discover_abilities_permission',
    ],
    'novamira-mcp-adapter/get-ability-info' => [
        'execute' => \Novamira\Vendor\WP\MCP\Abilities\GetAbilityInfoAbility::class . '::execute',
        'permission' => 'novamira_get_ability_info_permission',
    ],
    'novamira-mcp-adapter/execute-ability' => [
        'execute' => \Novamira\Vendor\WP\MCP\Abilities\ExecuteAbilityAbility::class . '::execute',
        'permission' => 'novamira_execute_ability_permission',
    ],
    // Filesystem reads.
    'novamira/read-file' => [
        'execute' => 'novamira_read_file',
        'permission' => 'novamira_permission_callback',
    ],
    'novamira/list-directory' => [
        'execute' => 'novamira_list_directory',
        'permission' => 'novamira_permission_callback',
    ],
    // Skills that declare read_mode.
    'novamira/skill-get' => [
        'execute' => 'Novamira\\Skills\\Abilities\\SkillGet\\execute',
        'permission' => 'novamira_permission_callback',
    ],
];

/** The longest guidance line an ability can add to the read-mode agent instructions. */
const NOVAMIRA_READ_MODE_MAX_GUIDANCE_LENGTH = 300;

/**
 * The guidance line each base ability adds to the read-mode agent instructions. The discovery and
 * dispatch abilities have none: the instructions describe them together.
 *
 * @return array<string, string>
 */
function novamira_read_mode_base_guidance(): array
{
    return [
        'novamira/agent-context' => __('this guidance and site details.', domain: 'novamira'),
        'novamira/read-file' => __('read a file inside the WordPress installation.', domain: 'novamira'),
        'novamira/list-directory' => __('list a folder inside the WordPress installation.', domain: 'novamira'),
        'novamira/skill-get' => __('load one of the skills listed below.', domain: 'novamira'),
    ];
}

/**
 * Keep the entries of a filtered ability list that are usable: a name, and an execute and permission
 * callback written as a named function or 'Class::method'. Anything else is dropped. Guidance is plain
 * text on one line. An entry marked optional is allowed like any other and is listed by whatever lets
 * the administrator choose it, not with the abilities read mode always offers.
 *
 * @return array<string, array{execute: string, permission: string, guidance: string, optional: bool}>
 */
function novamira_read_mode_normalize_abilities(mixed $abilities): array
{
    if (!is_array($abilities)) {
        return [];
    }

    $normalized = [];
    // @mago-expect analysis:mixed-assignment -- each entry is checked before it is used.
    foreach ($abilities as $name => $entry) {
        if (!is_string($name) || $name === '' || !is_array($entry)) {
            continue;
        }

        // @mago-expect analysis:mixed-assignment -- both are checked to be strings before they are used.
        $execute = $entry['execute'] ?? null;
        // @mago-expect analysis:mixed-assignment -- see above.
        $permission = $entry['permission'] ?? null;
        if (!is_string($execute) || $execute === '' || !is_string($permission) || $permission === '') {
            continue;
        }

        // @mago-expect analysis:mixed-assignment -- only a string is used.
        $guidance = $entry['guidance'] ?? '';
        $guidance = is_string($guidance)
            ? trim((string) preg_replace('/\s+/', replacement: ' ', subject: strip_tags($guidance)))
            : '';
        $normalized[$name] = [
            'execute' => $execute,
            'permission' => $permission,
            'guidance' => mb_substr($guidance, start: 0, length: NOVAMIRA_READ_MODE_MAX_GUIDANCE_LENGTH),
            'optional' => ($entry['optional'] ?? false) === true,
        ];
    }

    return $normalized;
}

/**
 * The abilities read mode allows and, for each name, the callbacks of its implementation.
 *
 * Starts from the abilities Novamira ships and passes them through one filter, once all plugins have
 * loaded: from then on the list does not change during the request, so an implementation cannot be
 * redefined after the first check. A filter result that is not an array leaves the base abilities, and
 * entries that are not usable are dropped.
 *
 * @return array<string, array{execute: string, permission: string, guidance: string, optional: bool}>
 */
function novamira_read_mode_trusted_abilities(): array
{
    /** @var array<string, array{execute: string, permission: string, guidance: string, optional: bool}>|null $frozen */
    static $frozen = null;
    if ($frozen !== null) {
        return $frozen;
    }

    $guidance = novamira_read_mode_base_guidance();
    $base = [];
    foreach (NOVAMIRA_READ_MODE_BASE_ABILITIES as $name => $callbacks) {
        $base[$name] = $callbacks + ['guidance' => $guidance[$name] ?? '', 'optional' => false];
    }

    /**
     * Filter the abilities read mode allows. Each entry maps an ability name to the callbacks of its
     * implementation, optionally to one line of guidance for the agent, and optionally marks the ability
     * as one the administrator chooses:
     * `name => ['execute' => callable-name, 'permission' => callable-name, 'guidance' => string, 'optional' => bool]`.
     * Callbacks are named functions or 'Class::method'. Entries can be added or removed. An ability is
     * usable only when it is registered with exactly these callbacks, and only the list as it stands
     * once all plugins have loaded counts.
     *
     * @param array<string, array{execute: string, permission: string, guidance?: string, optional?: bool}> $abilities
     */
    // @mago-expect analysis:mixed-assignment -- WordPress filters are untyped until their result is checked.
    $filtered = apply_filters('novamira_read_mode_abilities', $base);
    $abilities = is_array($filtered) ? novamira_read_mode_normalize_abilities($filtered) : $base;

    if (function_exists('did_action') && did_action('plugins_loaded') > 0 && !doing_action('plugins_loaded')) {
        $frozen = $abilities;
    }

    return $abilities;
}

/**
 * Whether read mode allows any ability beyond the ones Novamira ships: Novamira Pro adds its own when it is
 * active and its license allows them. Without them read mode is on but cannot be used as intended.
 */
function novamira_read_mode_has_extra_abilities(): bool
{
    return count(novamira_read_mode_trusted_abilities()) > count(NOVAMIRA_READ_MODE_BASE_ABILITIES);
}

/**
 * A comparable identity for a named function or static method, or null for anything else (closures,
 * invokable objects, object-bound methods), which can never match a trusted implementation.
 */
function novamira_read_mode_callable_id(mixed $callback): ?string
{
    if (is_string($callback) && $callback !== '') {
        return strtolower(ltrim($callback, characters: '\\'));
    }

    if (
        !is_array($callback)
        || count($callback) !== 2
        || !is_string($callback[0] ?? null)
        || !is_string($callback[1] ?? null)
    ) {
        return null;
    }

    return strtolower(ltrim($callback[0], characters: '\\') . '::' . $callback[1]);
}

/**
 * Whether an execute callback and permission callback are Novamira's own implementation of an
 * allowlisted Ability. A read-mode PermissionGuard is unwrapped to the callback it guards.
 */
function novamira_read_mode_callbacks_match(string $ability_name, mixed $execute, mixed $permission): bool
{
    $expected = novamira_read_mode_trusted_abilities()[$ability_name] ?? null;
    if ($expected === null) {
        return false;
    }

    $permission_id = novamira_read_mode_callable_id($permission);
    if ($permission instanceof \Novamira\ReadMode\PermissionGuard) {
        if ($permission->ability_name !== $ability_name) {
            return false;
        }
        $permission_id = novamira_read_mode_callable_id($permission->wrapped_callback());
    }

    return (
        novamira_read_mode_callable_id($execute) === strtolower($expected['execute'])
        && $permission_id === strtolower($expected['permission'])
    );
}

/**
 * The execute and permission callbacks an Ability object was built with, or null when they cannot be
 * read (which read mode treats as untrusted).
 *
 * @return array{execute: mixed, permission: mixed}|null
 */
function novamira_read_mode_ability_callbacks(WP_Ability $ability): ?array
{
    $values = [];
    foreach (['execute' => 'execute_callback', 'permission' => 'permission_callback'] as $key => $property) {
        $value = novamira_read_mode_private_property($ability, WP_Ability::class, $property);
        if ($value === null) {
            return null;
        }
        $values[$key] = $value['value'];
    }

    return ['execute' => $values['execute'], 'permission' => $values['permission']];
}

/**
 * Read a non-public property declared by a class, or null when the class does not declare it.
 *
 * @param class-string $class The class that declares the property.
 * @return array{value: mixed}|null
 */
function novamira_read_mode_private_property(object $object, string $class, string $property): ?array
{
    if (!property_exists($class, $property)) {
        return null;
    }

    $read = \Closure::bind(
        // @mago-expect analysis:string-member-selector,ambiguous-object-property-access -- The property is named by the caller.
        static fn(object $target): mixed => $target->{$property},
        newThis: null,
        newScope: $class,
    );

    return $read instanceof \Closure ? ['value' => $read($object)] : null;
}

/**
 * Overwrite a non-public property declared by a class. Returns false when it cannot be written.
 *
 * @param class-string $class The class that declares the property.
 */
function novamira_read_mode_set_private_property(object $object, string $class, string $property, mixed $value): bool
{
    if (!property_exists($class, $property)) {
        return false;
    }

    $write = \Closure::bind(
        static function (object $target) use ($property, $value): void {
            // @mago-expect analysis:string-member-selector,ambiguous-object-property-access -- The property is named by the caller.
            $target->{$property} = $value;
        },
        newThis: null,
        newScope: $class,
    );
    if (!$write instanceof \Closure) {
        return false;
    }

    $write($object);

    return true;
}

/**
 * Whether read mode also allows the read abilities other plugins declare: the one place where a
 * `readonly` annotation, which any provider can set, decides what an agent can use. Off unless an
 * administrator turned it on, which Novamira Pro does through the novamira_read_mode_allow_declared_abilities
 * filter. Read once, when all plugins have loaded, like the list of abilities.
 */
function novamira_read_mode_declared_enabled(): bool
{
    /** @var bool|null $frozen */
    static $frozen = null;
    if ($frozen !== null) {
        return $frozen;
    }

    /**
     * Filter whether read mode allows abilities that other plugins register with a `readonly` annotation.
     * Novamira cannot verify that declaration, so this is for an administrator's explicit choice, and the
     * answer only counts as it stands once all plugins have loaded.
     *
     * @param bool $enabled
     */
    $enabled = apply_filters('novamira_read_mode_allow_declared_abilities', value: false) === true;

    if (function_exists('did_action') && did_action('plugins_loaded') > 0 && !doing_action('plugins_loaded')) {
        $frozen = $enabled;
    }

    return $enabled;
}

/** Whether a name can be a declared read ability: exclude Novamira's namespaces and upstream dispatchers. */
function novamira_read_mode_declared_name_ok(string $ability_name): bool
{
    return (
        $ability_name !== ''
        && !str_starts_with(strtolower($ability_name), 'novamira')
        && !novamira_read_mode_keeps_upstream_ability($ability_name)
    );
}

/**
 * Whether an Ability object is one another plugin declares read only: exactly WP_Ability, a name outside
 * Novamira's namespaces, and a `readonly` annotation of true.
 */
function novamira_read_mode_ability_is_declared_readonly(WP_Ability $ability): bool
{
    if (get_class($ability) !== WP_Ability::class || !novamira_read_mode_declared_name_ok($ability->get_name())) {
        return false;
    }

    $meta = $ability->get_meta();
    $annotations = is_array($meta['annotations'] ?? null) ? $meta['annotations'] : [];

    return ($annotations['readonly'] ?? null) === true;
}

/** Whether read mode allows an Ability name because another plugin declares it read only and that is turned on. */
function novamira_read_mode_declared_allowed(string $ability_name): bool
{
    if (
        !novamira_read_mode_declared_enabled()
        || !novamira_read_mode_declared_name_ok($ability_name)
        || !function_exists('wp_has_ability')
        || !wp_has_ability($ability_name)
    ) {
        return false;
    }

    $ability = wp_get_ability($ability_name);

    return $ability instanceof WP_Ability && novamira_read_mode_ability_is_declared_readonly($ability);
}

/**
 * The registered abilities read mode allows because other plugins declare them read only, or none when
 * that is turned off.
 *
 * @return list<string>
 */
function novamira_read_mode_declared_abilities(): array
{
    if (!novamira_read_mode_declared_enabled() || !function_exists('wp_get_abilities')) {
        return [];
    }

    $names = [];
    foreach (wp_get_abilities() as $ability) {
        if (!novamira_read_mode_ability_is_declared_readonly($ability)) {
            continue;
        }

        $names[] = $ability->get_name();
    }

    return $names;
}

/**
 * The read abilities other plugins declare, public to MCP, that read mode has seen: name and description.
 *
 * Read mode removes an ability it does not allow from the registry, so a screen that lists what could be
 * allowed reads them from here. Pass an Ability to record it; without one, the list so far.
 *
 * @return array<string, string>
 */
function novamira_read_mode_declared_candidates(?WP_Ability $seen = null): array
{
    /** @var array<string, string> $candidates */
    static $candidates = [];

    if ($seen !== null && novamira_read_mode_ability_is_declared_readonly($seen)) {
        $meta = $seen->get_meta();
        $mcp = is_array($meta['mcp'] ?? null) ? $meta['mcp'] : [];
        if (($mcp['public'] ?? false) === true && ($mcp['type'] ?? 'tool') === 'tool') {
            $candidates[$seen->get_name()] = $seen->get_description();
        }
    }

    return $candidates;
}

/**
 * Whether an Ability object is an implementation read mode trusts: exactly WP_Ability, and either
 * Novamira's own implementation of an allowlisted name (the trusted execute and permission callbacks), or
 * a read ability another plugin declares, when that is turned on.
 */
function novamira_read_mode_trusts_ability(WP_Ability $ability): bool
{
    if (get_class($ability) !== WP_Ability::class) {
        return false;
    }

    if (!array_key_exists($ability->get_name(), novamira_read_mode_trusted_abilities())) {
        return novamira_read_mode_declared_enabled() && novamira_read_mode_ability_is_declared_readonly($ability);
    }

    $callbacks = novamira_read_mode_ability_callbacks($ability);

    return $callbacks !== null
    && novamira_read_mode_callbacks_match($ability->get_name(), $callbacks['execute'], $callbacks['permission']);
}

/**
 * The read-mode refusal for an Ability object, or null when read mode is off or the object is a trusted
 * implementation of an allowlisted name.
 */
function novamira_read_mode_ability_refusal(WP_Ability $ability): ?WP_Error
{
    if (!novamira_is_read_mode()) {
        return null;
    }

    $ability_name = $ability->get_name();
    if (novamira_read_mode_permits_ability($ability_name) && novamira_read_mode_trusts_ability($ability)) {
        return null;
    }

    return novamira_read_mode_blocked_error(sprintf("the ability '%s'", $ability_name));
}

/** Whether read mode hides an Ability object from Novamira's discovery lists. */
function novamira_read_mode_hides_ability(WP_Ability $ability): bool
{
    return novamira_read_mode_ability_refusal($ability) !== null;
}

/** Bind a registered Ability's read-mode guard to the object, so the guard can verify that object later. */
function novamira_read_mode_bind_guard(WP_Ability $ability): void
{
    $callbacks = novamira_read_mode_ability_callbacks($ability);
    if ($callbacks === null || !$callbacks['permission'] instanceof \Novamira\ReadMode\PermissionGuard) {
        return;
    }

    $callbacks['permission']->bind($ability);
}

/**
 * Whether read mode keeps a built-in Ability of the unprefixed MCP Adapter registered, so the default
 * server of a plugin bundling that adapter finds it: only an exact WP_Ability that the adapter's own class
 * registered (novamira_stock_mcp_adapter_ability_is_genuine()), whose permission callback is read mode's
 * guard bound to that same object, which refuses it. Anything else registered under these names is removed
 * like any Ability read mode does not allow.
 */
function novamira_read_mode_retains_upstream_ability(WP_Ability $ability): bool
{
    $ability_name = $ability->get_name();
    if (!novamira_read_mode_keeps_upstream_ability($ability_name) || get_class($ability) !== WP_Ability::class) {
        return false;
    }

    $callbacks = novamira_read_mode_ability_callbacks($ability);
    if (
        $callbacks === null
        || !$callbacks['permission'] instanceof \Novamira\ReadMode\PermissionGuard
        || !$callbacks['permission']->is_bound_to($ability)
    ) {
        return false;
    }

    return novamira_stock_mcp_adapter_ability_is_genuine(
        $ability_name,
        $callbacks['permission']->wrapped_callback(),
        $callbacks['execute'],
    );
}
