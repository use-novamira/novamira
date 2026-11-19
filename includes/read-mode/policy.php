<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Read mode: the Ability allowlist, its enforcement, and the agent-facing wording.
 *
 * Read mode protects a site from mistakes of the administrator's own agent while it only inspects
 * the site. It is not a security boundary against an administrator: the agent still authenticates
 * as one, and the sandbox loader keeps running. The state and its API are in state.php: Novamira Pro
 * turns read mode on, and Novamira turns it off from the Read Mode page or WP-CLI.
 */

if (!defined('ABSPATH')) {
    exit();
}

require_once dirname(__DIR__) . '/php-version-notice.php';

require_once __DIR__ . '/state.php';
require_once __DIR__ . '/PermissionGuard.php';
require_once __DIR__ . '/trust.php';
require_once __DIR__ . '/mcp.php';
require_once __DIR__ . '/page.php';
require_once __DIR__ . '/cli.php';
require_once dirname(__DIR__) . '/abilities/stock-mcp-adapter.php';

/** REST route prefixes whose handlers read the Ability registry: core Abilities, Novamira, and MCP. */
const NOVAMIRA_READ_MODE_REGISTRY_ROUTES = ['/wp-abilities/v1', '/novamira/v1', '/mcp/'];

/**
 * Whether read mode is active on the current site.
 *
 * Read from the options table through $wpdb (see state.php), so option filters and the object cache
 * cannot make it read as off. There is deliberately no filter on this state.
 */
function novamira_is_read_mode(): bool
{
    return \Novamira\ReadMode\is_active(\Novamira\ReadMode\read_state());
}

/**
 * The Abilities that stay usable in read mode: the names in the trusted list (see trust.php).
 *
 * This list is the authorization decision: `readonly` annotations are metadata that any provider can
 * set, so they are not used to derive it, with one exception an administrator turns on
 * (novamira_read_mode_declared_allowed()). It starts from the abilities Novamira ships and is read
 * once through the novamira_read_mode_abilities filter, which is how Novamira Pro adds its own.
 *
 * @return list<string>
 */
function novamira_read_mode_allowed_abilities(): array
{
    return array_keys(novamira_read_mode_trusted_abilities());
}

/**
 * Whether read mode itself requires an Ability and keeps it on: it is in the allowlist and the administrator
 * does not choose it (an optional entry, such as the read abilities of the Novamira Pro specializations, is
 * chosen). Such an ability cannot be turned off in the Abilities Hub.
 */
function novamira_read_mode_is_mandatory_ability(string $ability_name): bool
{
    $entry = novamira_read_mode_trusted_abilities()[$ability_name] ?? null;

    return $entry !== null && !$entry['optional'];
}

/**
 * Whether the administrator can turn an Ability off and on by itself in the Abilities Hub while read mode is
 * on: read mode allows it and does not require it.
 */
function novamira_read_mode_ability_is_choosable(string $ability_name): bool
{
    return novamira_read_mode_allows_ability($ability_name) && !novamira_read_mode_is_mandatory_ability($ability_name);
}

/** Whether read mode allows an Ability name. */
function novamira_read_mode_allows_ability(string $ability_name): bool
{
    return (
        !novamira_read_mode_keeps_upstream_ability($ability_name)
        && (
            in_array($ability_name, novamira_read_mode_allowed_abilities(), strict: true)
            || novamira_read_mode_declared_allowed($ability_name)
        )
    );
}

/** Whether the administrator turned an Ability off in the Abilities Hub: one read mode does not require. */
function novamira_read_mode_turned_off_in_hub(string $ability_name): bool
{
    return !novamira_read_mode_is_mandatory_ability($ability_name) && novamira_ability_has_disabled_rule($ability_name);
}

/**
 * Whether an Ability can run in read mode right now: read mode allows it and the administrator has not turned
 * it off in the Abilities Hub. Every check made when an Ability is about to run asks this, whichever door the
 * call comes through, so an Ability that is off is refused even when it is registered or held by other code.
 */
function novamira_read_mode_permits_ability(string $ability_name): bool
{
    return novamira_read_mode_allows_ability($ability_name) && !novamira_read_mode_turned_off_in_hub($ability_name);
}

/**
 * Whether a name is one of the built-in Abilities of the unprefixed MCP Adapter that other plugins may
 * bundle. Read mode never allows, lists, or declares these names; it keeps them registered, and refused,
 * only for the adapter's own registrations (novamira_read_mode_retains_upstream_ability()).
 */
function novamira_read_mode_keeps_upstream_ability(string $ability_name): bool
{
    return in_array($ability_name, NOVAMIRA_STOCK_MCP_ADAPTER_ABILITIES, strict: true);
}

/**
 * Unregister every Ability that read mode does not allow, and every Ability registered under an
 * allowed name that is not Novamira's own implementation of it. Idempotent.
 * The built-in Abilities of an unprefixed MCP Adapter another plugin bundles remain registered when that
 * adapter's own classes registered them (novamira_read_mode_retains_upstream_ability()), so its default
 * server finds them and logs no missing Abilities. Their read-mode guards refuse them, and Novamira's
 * read-mode surfaces neither permit nor list them. Anything else registered under those names is removed.
 *
 * Runs from novamira_apply_ability_policy() at the end of `wp_abilities_api_init`, and again from
 * novamira_enforce_read_mode_registry() when a registry-backed surface is entered. It also binds each
 * object's read-mode guard to the object, so the guard keeps verifying that object if other code holds
 * on to it.
 */
function novamira_apply_read_mode_policy(): void
{
    // The Abilities Hub lists what it turned off, so its screens keep those registered.
    $keeps_turned_off = novamira_is_ability_management_screen();
    foreach (wp_get_abilities() as $ability) {
        novamira_read_mode_bind_guard($ability);
        novamira_read_mode_declared_candidates($ability);
        $ability_name = $ability->get_name();
        if (novamira_read_mode_retains_upstream_ability($ability)) {
            continue;
        }
        $allowed = $keeps_turned_off
            ? novamira_read_mode_allows_ability($ability_name)
            : novamira_read_mode_permits_ability($ability_name);
        if ($allowed && novamira_read_mode_trusts_ability($ability)) {
            continue;
        }

        wp_unregister_ability($ability_name);
    }
}

/**
 * Sweep the Ability registry against the read-mode allowlist now, when read mode is on.
 *
 * Every surface that lists, checks, or executes Abilities from the registry calls this on entry, before
 * any listing, permission check, or execution. Hook ordering therefore cannot leave a non-allowlisted
 * Ability reachable: one registered after the `wp_abilities_api_init` sweep is removed here, except the
 * upstream infrastructure names retained with execution guards by novamira_apply_read_mode_policy().
 */
function novamira_enforce_read_mode_registry(): void
{
    if (!novamira_is_read_mode() || !function_exists('wp_get_abilities') || !function_exists('wp_unregister_ability')) {
        return;
    }

    novamira_apply_read_mode_policy();
}

/**
 * Sweep the registry before WordPress runs the permission callback and handler of a REST request to
 * core `wp-abilities/v1`, Novamira's routes, or an MCP server, all of which read the registry.
 *
 * Filters rest_request_before_callbacks, which WordPress applies before the route's permission
 * callback; the response is returned unchanged.
 */
function novamira_read_mode_rest_sweep(mixed $response, mixed $handler, mixed $request): mixed
{
    /** @var mixed $route */
    $route = is_object($request) && method_exists($request, 'get_route') ? $request->get_route() : '';
    $reads_registry =
        is_string($route)
        && array_filter(NOVAMIRA_READ_MODE_REGISTRY_ROUTES, static fn(string $prefix): bool => str_starts_with(
            $route,
            $prefix,
        )) !== [];
    if (!$reads_registry) {
        return $response;
    }

    novamira_enforce_read_mode_registry();
    if (str_starts_with($route, '/mcp/')) {
        // Filters a plugin attached since keep running before read mode's list check.
        novamira_read_mode_reattach_guard_filter();
    }
    if (
        str_starts_with($route, '/mcp/')
        && (!novamira_read_mode_reconcile_mcp_servers() || !novamira_read_mode_check_http_lists($handler))
    ) {
        return novamira_read_mode_blocked_error(__(
            'MCP, because its server components could not be verified',
            domain: 'novamira',
        ));
    }

    return $response;
}

/**
 * When the adapter starts serving over STDIO (WP-CLI), the one adapter hook that runs before that
 * transport handles any request: from then on the long-running process reads the read-mode state on
 * every check, so read mode turned on by another process applies to the next call (and the list, call,
 * get, and read hooks reconcile again, see mcp.php). Then sweep and reconcile now; serving is turned off
 * when the servers cannot be verified in read mode, otherwise the filtered value is returned unchanged.
 */
function novamira_read_mode_stdio_sweep(mixed $enabled): mixed
{
    \Novamira\ReadMode\read_fresh_from_now_on();
    \Novamira\ReadMode\watch_for_change('novamira_read_mode_end_stdio_session');
    novamira_read_mode_reattach_guard_filter();

    return novamira_read_mode_enforce_mcp() && novamira_read_mode_check_stdio_lists() ? $enabled : false;
}

/**
 * End the MCP STDIO session because read mode was turned on or off while it served.
 *
 * Called by the first check that notices the change, before anything that check guards runs. The
 * process was set up for the mode it started in (its registry, its servers' components, the read-mode
 * data abilities), so instead of serving on in a mix of both modes it tells the client, with an MCP log
 * notification on stdout and a line on stderr, to restart the session, and exits.
 */
// @mago-expect lint:no-boolean-flag-parameter -- the state the watch found, passed by watch_for_change().
function novamira_read_mode_end_stdio_session(bool $read_mode_active): void
{
    $message = sprintf(
        /* translators: %s: "on" or "off" */
        __(
            'Novamira read mode was turned %s while this MCP session was running. The session has ended so that nothing runs in a mix of both modes: restart the MCP server (reconnect your AI client) to continue.',
            domain: 'novamira',
        ),
        $read_mode_active ? __('on', domain: 'novamira') : __('off', domain: 'novamira'),
    );
    $notification = [
        'jsonrpc' => '2.0',
        'method' => 'notifications/message',
        'params' => ['level' => 'error', 'logger' => 'novamira', 'data' => $message],
    ];
    if (defined('STDOUT')) {
        fwrite(STDOUT, (string) wp_json_encode($notification) . "\n");
        fflush(STDOUT);
    }
    if (defined('STDERR')) {
        fwrite(STDERR, '[Novamira] ' . $message . "\n");
    }

    exit(1);
}

/** The sentence every read-mode refusal and the agent context share. */
function novamira_read_mode_no_workaround_text(): string
{
    return __(
        'Do NOT try to make the change by other means: no curl or other HTTP requests to the WordPress REST API, admin-ajax.php or wp-admin, no WP-CLI or database clients through a shell, and no other tools or plugins writing on your behalf. If the task requires a change, stop and tell the user that a site administrator must turn off Novamira read mode first.',
        domain: 'novamira',
    );
}

/**
 * The complete agent instructions while read mode is active.
 *
 * In read mode agents receive only this guidance: no environment inventory, no development or page
 * building guidance, no design guidance, only the skills that declare read_mode, and nothing added
 * through the novamira_discover_abilities_instructions filter. The list of available abilities is built
 * from the abilities read mode allows right now, with the guidance line each one carries, so it never
 * names an ability that is not there.
 *
 * @return list<string>
 */
function novamira_read_mode_instruction_lines(): array
{
    $skill_lines = novamira_read_mode_skill_lines();
    $php_notice_lines = novamira_php_version_notice_lines();
    $available = [];
    foreach (novamira_read_mode_trusted_abilities() as $ability_name => $ability) {
        if (
            $ability['guidance'] === ''
            || novamira_is_read_mode() && !novamira_read_mode_permits_ability($ability_name)
            || $ability_name === 'novamira/skill-get' && $skill_lines === []
        ) {
            continue;
        }
        $available[] = sprintf('- %s: %s', $ability_name, $ability['guidance']);
    }

    return [
        '## READ MODE IS ACTIVE',
        '',
        __(
            'A site administrator has put Novamira in read mode. You can inspect this site, but you cannot change it.',
            domain: 'novamira',
        ),
        '',
        __('Available, all read-only:', domain: 'novamira'),
        ...$available,
        __(
            'Over MCP, find them with mcp-adapter-discover-abilities, read their input with mcp-adapter-get-ability-info, and run them with mcp-adapter-execute-ability. Discovery may list further read abilities.',
            domain: 'novamira',
        ),
        '',
        __(
            'Not available: running PHP or WP-CLI, creating, editing, or deleting files, uploads, admin access links, changing content, settings, users, plugins, or themes, Gutenberg and page-builder editing, the Block Editor Queue abilities (including block type reads), design systems, and skills other than those listed here.',
            domain: 'novamira',
        ),
        ...novamira_read_mode_availability_lines(),
        ...$skill_lines,
        '',
        novamira_read_mode_no_workaround_text(),
        ...($php_notice_lines === [] ? [] : ['', ...$php_notice_lines]),
    ];
}

/**
 * The read-mode skill catalog: the skills that declare read_mode, or no lines when there are none or
 * read mode does not allow novamira/skill-get.
 *
 * @return list<string>
 */
function novamira_read_mode_skill_lines(): array
{
    if (
        !function_exists('Novamira\\Skills\\Sources\\discoverable')
        || novamira_is_read_mode() && !novamira_read_mode_permits_ability('novamira/skill-get')
    ) {
        return [];
    }

    $lines = [];
    foreach (\Novamira\Skills\Sources\discoverable('agentic') as $skill) {
        if (($skill['read_mode'] ?? false) !== true) {
            continue;
        }
        $lines[] = sprintf('- %s: %s', (string) ($skill['slug'] ?? ''), trim((string) ($skill['description'] ?? '')));
    }
    if ($lines === []) {
        return [];
    }

    return [
        '',
        '## Skills',
        '',
        __(
            'If one of these skills matches the request, load it with novamira/skill-get before starting the work. When a skill includes steps that change the site, tell the user before you start which parts you cannot do while Read Mode is on, and do the rest.',
            domain: 'novamira',
        ),
        ...$lines,
    ];
}

/**
 * What the agent is told about the abilities that are not there. Normally they are unavailable because read
 * mode is active, not because of licensing. When only the abilities Novamira ships remain, read mode cannot
 * be used as intended: Novamira Pro or its license is missing, and the agent says so to the user. The
 * wording is Novamira's own: no plugin adds text to the instructions in read mode.
 *
 * @return list<string>
 */
function novamira_read_mode_availability_lines(): array
{
    if (!novamira_read_mode_has_extra_abilities()) {
        return [
            __(
                'Read mode cannot be used as intended right now: the Novamira Pro read abilities are not available, because Novamira Pro is not active or its license is not active.',
                domain: 'novamira',
            ),
            __(
                'Tell the user. A site administrator can turn off read mode to use Novamira without it.',
                domain: 'novamira',
            ),
        ];
    }

    return [
        __(
            'An ability that discovery does not list is unavailable because read mode is active, not because of licensing: do not suggest activating or renewing a license to get it.',
            domain: 'novamira',
        ),
    ];
}

/** The complete agent instructions while read mode is active, as one string. */
function novamira_read_mode_instructions(): string
{
    return implode("\n", novamira_read_mode_instruction_lines());
}

/** The refusal wording for something read mode blocks, such as an Ability name or a route. */
function novamira_read_mode_blocked_message(string $subject): string
{
    return sprintf(
        /* translators: 1: the blocked ability or action, 2: the instruction not to work around read mode */
        __(
            'Read mode is active on this site, so %1$s is unavailable: modifications are unavailable while read mode is on. %2$s',
            domain: 'novamira',
        ),
        $subject,
        novamira_read_mode_no_workaround_text(),
    );
}

/** A 403 refusal for something read mode blocks. */
function novamira_read_mode_blocked_error(string $subject): WP_Error
{
    return new WP_Error('novamira_read_mode', novamira_read_mode_blocked_message($subject), ['status' => 403]);
}

/**
 * The one execution-time read-mode check shared by Novamira's executors: the refusal for an Ability
 * read mode does not allow, or null when read mode is off or allows it.
 *
 * Executors call it before looking the Ability up, so an Ability registered after the read-mode sweep
 * (for example by a later provider on the same hook priority) is refused as well.
 */
function novamira_read_mode_unavailable_ability_error(string $ability_name): ?WP_Error
{
    if ($ability_name === '' || !novamira_is_read_mode() || novamira_read_mode_permits_ability($ability_name)) {
        return null;
    }

    return novamira_read_mode_blocked_error(sprintf("the ability '%s'", $ability_name));
}

/**
 * The refusal of an execute callback that must never run in read mode, such as PHP execution or
 * WP-CLI, whatever the allowlist says; null when read mode is off.
 *
 * Defence in depth for callers that reach the callback without the registry or a permission check.
 */
function novamira_read_mode_execution_refusal(string $ability_name): ?WP_Error
{
    return novamira_is_read_mode()
        ? novamira_read_mode_blocked_error(sprintf("the ability '%s'", $ability_name))
        : null;
}

/**
 * Wrap the permission callback of every Ability registered through the registry in a read-mode guard.
 *
 * Filters wp_register_ability_args, which WordPress applies to every registration, Novamira's, core's,
 * and other plugins', before the Ability object is built. The guard decides when it is called, so it is
 * attached whether or not read mode is on, and an Ability kept as an object before read mode was turned
 * on or before the sweep is still refused. Only the permission callback is wrapped: the execute callback
 * and ability_class are left as registered, and the guard records them to verify the implementation.
 */
function novamira_read_mode_guard_ability_args(mixed $args, mixed $ability_name): mixed
{
    if (!is_array($args) || !is_string($ability_name)) {
        return $args;
    }

    /** @var mixed $permission_callback */
    $permission_callback = $args['permission_callback'] ?? null;
    if (!is_callable($permission_callback) || $permission_callback instanceof \Novamira\ReadMode\PermissionGuard) {
        return $args;
    }

    /** @var mixed $meta */
    $meta = $args['meta'] ?? null;
    /** @var mixed $annotations */
    $annotations = is_array($meta) ? $meta['annotations'] ?? null : null;
    $args['permission_callback'] = new \Novamira\ReadMode\PermissionGuard(
        $ability_name,
        $permission_callback,
        $args['execute_callback'] ?? null,
        ($args['ability_class'] ?? WP_Ability::class) !== WP_Ability::class,
        is_array($annotations) && ($annotations['readonly'] ?? null) === true,
    );

    return $args;
}

/**
 * Move read mode's last filters (NOVAMIRA_READ_MODE_LAST_FILTERS) to the end of their hooks when
 * Abilities start registering.
 *
 * Callbacks of the same priority run in the order they were added, so a plugin loaded after Novamira
 * that filters at PHP_INT_MAX would otherwise run after them: it could replace the permission callback
 * the guard set, or elevate a permission result read mode refused.
 */
function novamira_read_mode_reattach_guard_filter(): void
{
    foreach (NOVAMIRA_READ_MODE_LAST_FILTERS as [$hook, $callback, $accepted_args]) {
        remove_filter($hook, $callback, priority: PHP_INT_MAX);
        add_filter($hook, $callback, priority: PHP_INT_MAX, accepted_args: $accepted_args);
    }
}

/**
 * The filters read mode keeps last at PHP_INT_MAX: the guard on Ability registration; on WordPress 7.1+
 * the final say on an Ability's permission and the veto on its execution (WordPress 6.9 never applies
 * those two, so there they do nothing); and the check of what MCP servers list. They are moved back to
 * the end when Abilities start registering, when the MCP adapter initializes, when an MCP route is
 * entered, and when the STDIO server starts.
 */
const NOVAMIRA_READ_MODE_LAST_FILTERS = [
    ['wp_register_ability_args',            'novamira_read_mode_guard_ability_args',  2],
    ['wp_ability_permission_result',        'novamira_read_mode_permission_result',   4],
    ['wp_pre_execute_ability',              'novamira_read_mode_pre_execute_ability', 4],
    ['novamira_mcp_adapter_tools_list',     'novamira_read_mode_filter_mcp_list',     2],
    ['novamira_mcp_adapter_resources_list', 'novamira_read_mode_filter_mcp_list',     2],
    ['novamira_mcp_adapter_prompts_list',   'novamira_read_mode_filter_mcp_list',     2],
];

/**
 * WordPress 7.1+: the final permission result of an Ability, after every wp_ability_permission_result
 * filter. In read mode an Ability that is not a trusted implementation of an allowlisted name is
 * refused, so a well-meaning filter that elevates permissions (which may turn the guard's false into
 * true) cannot let it run. Allowlisted results, and every result outside read mode, are left as they are.
 */
function novamira_read_mode_permission_result(
    mixed $permission,
    mixed $ability_name = null,
    mixed $input = null,
    mixed $ability = null,
): mixed {
    return novamira_read_mode_object_refusal($ability_name, $ability) === null ? $permission : false;
}

/**
 * WordPress 7.1+: refuse to execute an Ability read mode does not allow, before WordPress normalizes its
 * input, checks its permission, or calls it. This holds even for an Ability object kept from before the
 * read-mode sweep whose permission callback was replaced, or whose class overrides check_permissions().
 * Otherwise the filtered value is returned unchanged.
 */
function novamira_read_mode_pre_execute_ability(
    mixed $pre,
    mixed $ability_name = null,
    mixed $input = null,
    mixed $ability = null,
): mixed {
    return novamira_read_mode_object_refusal($ability_name, $ability) ?? $pre;
}

/** The read-mode refusal for an Ability object (or, without one, its name), or null when it may run. */
function novamira_read_mode_object_refusal(mixed $ability_name, mixed $ability): ?WP_Error
{
    if (!novamira_is_read_mode()) {
        return null;
    }
    if ($ability instanceof WP_Ability) {
        return novamira_read_mode_ability_refusal($ability);
    }

    return novamira_read_mode_unavailable_ability_error(is_string($ability_name) ? $ability_name : '');
}

/**
 * Show that read mode is active on Novamira's own admin screens, Novamira Chat included, to the
 * administrators who see Novamira (Ghost Mode decides who does). The Read Mode page shows it itself.
 */
function novamira_render_read_mode_admin_notice(): void
{
    if (!novamira_is_read_mode() || !\Novamira\ReadMode\admin_ui_visible()) {
        return;
    }

    // The Read Mode page, the Abilities Hub and the Features page say it themselves, in their own words.
    $page = is_string($_GET['page'] ?? null) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    if (
        !str_starts_with($page, 'novamira')
        || in_array($page, [\Novamira\ReadMode\PAGE_SLUG, 'novamira-abilities', 'novamira-features'], strict: true)
    ) {
        return;
    }

    wp_admin_notice(
        sprintf(
            '%s <a href="%s">%s</a>',
            esc_html__(
                'Novamira Read Mode is on: AI agents have only read abilities, and upload and admin access links are refused.',
                domain: 'novamira',
            ),
            esc_url(\Novamira\ReadMode\page_url()),
            esc_html__('Read Mode settings', domain: 'novamira'),
        ),
        ['type' => 'warning', 'dismissible' => false, 'additional_classes' => ['novamira-keep']],
    );
}

/**
 * On a screen that manages abilities or features, say that nothing can be changed while read mode is on.
 * Prints nothing when read mode is off.
 */
function novamira_render_read_mode_management_notice(string $message): void
{
    if (!novamira_is_read_mode()) {
        return;
    }

    wp_admin_notice(
        sprintf(
            '%s <a href="%s">%s</a>',
            esc_html($message),
            esc_url(\Novamira\ReadMode\page_url()),
            esc_html__('Read Mode settings', domain: 'novamira'),
        ),
        ['type' => 'info', 'dismissible' => false, 'additional_classes' => ['inline', 'novamira-keep']],
    );
}

/** Register the read-mode hooks that do not depend on the Abilities API being initialized. */
function novamira_register_read_mode_hooks(): void
{
    add_filter(
        'wp_register_ability_args',
        callback: 'novamira_read_mode_guard_ability_args',
        priority: PHP_INT_MAX,
        accepted_args: 2,
    );
    foreach (NOVAMIRA_READ_MODE_LAST_FILTERS as [$hook, $callback, $accepted_args]) {
        if ($hook === 'wp_register_ability_args') {
            continue;
        }
        add_filter($hook, $callback, priority: PHP_INT_MAX, accepted_args: $accepted_args);
    }
    add_action('wp_abilities_api_init', callback: 'novamira_read_mode_reattach_guard_filter', priority: PHP_INT_MIN);
    add_filter(
        'novamira_mcp_adapter_default_server_config',
        callback: 'novamira_read_mode_default_server_config',
        priority: PHP_INT_MAX,
    );
    add_action(
        'novamira_mcp_adapter_init',
        callback: 'novamira_read_mode_reattach_server_config_filter',
        priority: PHP_INT_MIN,
    );
    foreach ([
        'novamira_mcp_adapter_pre_tool_call',
        'novamira_mcp_adapter_pre_prompt_get',
        'novamira_mcp_adapter_pre_resource_read',
    ] as $hook) {
        add_filter($hook, callback: 'novamira_read_mode_refuse_mcp_component', priority: PHP_INT_MAX, accepted_args: 3);
    }
    add_filter(
        'rest_request_before_callbacks',
        callback: 'novamira_read_mode_rest_sweep',
        priority: PHP_INT_MIN,
        accepted_args: 3,
    );
    add_filter(
        'novamira_mcp_adapter_enable_stdio_transport',
        callback: 'novamira_read_mode_stdio_sweep',
        priority: PHP_INT_MIN,
    );
    add_action('admin_notices', callback: 'novamira_render_read_mode_admin_notice');
    add_action('network_admin_notices', callback: 'novamira_render_read_mode_admin_notice');
    \Novamira\ReadMode\register_state_hooks();
    add_action('admin_menu', callback: 'Novamira\\ReadMode\\register_menu', priority: 91);
    add_action('admin_enqueue_scripts', callback: 'Novamira\\ReadMode\\enqueue_page_assets');
    // Then it moves next to the Abilities Hub, after every other menu change.
    add_action('admin_menu', callback: 'Novamira\\ReadMode\\move_menu_entry', priority: 999);
    add_action('admin_post_' . \Novamira\ReadMode\DISABLE_ACTION, callback: 'Novamira\\ReadMode\\handle_disable');
    \Novamira\ReadMode\Cli\register();
}
