<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

require_once dirname(__DIR__) . '/ability-metadata.php';

/** @return array{available: bool, reason: string} */
function novamira_wp_cli_status(): array
{
    /** @var array{available: bool, reason: string}|null $status */
    static $status = null;
    if ($status !== null) {
        return $status;
    }

    if (!function_exists('proc_open') || !function_exists('exec')) {
        return $status = [
            'available' => false,
            'reason' => __('PHP process execution is disabled by the server configuration.', domain: 'novamira'),
        ];
    }

    // Keep the large runner lazy: ordinary WordPress requests do not need to parse it.
    require_once __DIR__ . '/run-wp-cli.php';
    if (novamira_find_wp_cli_command() === null) {
        return $status = [
            'available' => false,
            'reason' => novamira_wp_cli_constant_problem() ?? __(
                'WP-CLI was not found on this server.',
                domain: 'novamira',
            ),
        ];
    }

    return $status = ['available' => true, 'reason' => ''];
}

function novamira_wp_cli_unavailable_message(string $ability_name): ?string
{
    if (!in_array($ability_name, ['novamira/run-wp-cli', 'novamira/get-wp-cli-job'], strict: true)) {
        return null;
    }
    $status = novamira_wp_cli_status();
    if ($status['available']) {
        return null;
    }

    return sprintf(
        /* translators: 1: ability name, 2: reason WP-CLI is unavailable */
        __("Ability '%1\$s' is unavailable: %2\$s", domain: 'novamira'),
        $ability_name,
        $status['reason'],
    );
}

/**
 * Load the Ability execution REST shim only when this WordPress version supports Abilities.
 */
function novamira_boot_ability_rest_surface(): bool
{
    if (!novamira_wordpress_abilities_supported()) {
        return false;
    }

    novamira_register_ability_metadata_rest_filter();
    require_once dirname(__DIR__) . '/rest-shim.php';
    add_action('rest_api_init', callback: 'novamira_register_ability_run_rest_shim');

    return true;
}

/**
 * Register Novamira Ability hooks independently of protocol-adapter initialization.
 */
function novamira_register_ability_hooks(): bool
{
    if (!novamira_wordpress_abilities_supported()) {
        return false;
    }

    add_action('wp_abilities_api_categories_init', callback: 'novamira_register_ability_categories', priority: 20);
    add_action('wp_abilities_api_init', callback: 'novamira_register_mcp_adapter_builtin_abilities', priority: 10);
    add_action('wp_abilities_api_init', callback: 'novamira_register_builtin_abilities', priority: 20);
    add_action(
        'novamira_mcp_adapter_init',
        callback: 'novamira_own_mcp_adapter_builtin_abilities',
        priority: PHP_INT_MIN,
    );

    return true;
}

/**
 * Register the MCP Adapter's built-in Abilities when the adapter itself no longer can.
 *
 * The bundled adapter attaches its own `wp_abilities_api_init` callback (priority 10) only from its
 * `init()`, which runs on `rest_api_init:15` (or `init:20` under WP-CLI). WordPress fires that action
 * exactly once, when the Abilities registry is first used, so any earlier use of the Abilities API —
 * the public MCP Adapter plugin, another plugin calling wp_get_abilities() — boots the registry before
 * the adapter's callback exists and its Abilities are never registered.
 *
 * Novamira hooks this callback at plugin load, at the adapter's own priority 10. Whenever the adapter's
 * callback is still attached (the normal ordering: `init()` ran, then the registry booted) this does
 * nothing, and the adapter registers the built-ins exactly as before — last among the priority-10
 * callbacks, since it is attached from `init()`. Only when the adapter's callback is absent, because
 * the action fired before `init()` or because novamira_own_mcp_adapter_builtin_abilities() removed it,
 * does Novamira register whichever built-in is missing. Novamira's discover-abilities replacement and
 * the get-ability-info / execute-ability patchers still run on top at priority 20, exactly as before.
 *
 * The category is guaranteed by novamira_register_ability_categories(): WordPress boots the categories
 * registry before firing this action. The `novamira_mcp_adapter_create_default_server` filter is
 * deliberately not consulted here: the adapter applies it once, in its `init()`, and Novamira must not
 * second-guess that decision from a different context. On a site that disables the default server the
 * three Abilities exist but no server exposes them — the same as Novamira's own discover-abilities
 * replacement, which has always been registered regardless of that filter.
 */
function novamira_register_mcp_adapter_builtin_abilities(): void
{
    if (!novamira_is_mcp_adapter_available() || !wp_has_ability_category('novamira-mcp-adapter')) {
        return;
    }

    $adapter_registrar = [\Novamira\Vendor\WP\MCP\Core\McpAdapter::instance(), 'register_default_abilities'];
    if (has_action('wp_abilities_api_init', $adapter_registrar) !== false) {
        return;
    }

    if (!wp_has_ability('novamira-mcp-adapter/discover-abilities')) {
        \Novamira\Vendor\WP\MCP\Abilities\DiscoverAbilitiesAbility::register();
    }

    if (!wp_has_ability('novamira-mcp-adapter/get-ability-info')) {
        \Novamira\Vendor\WP\MCP\Abilities\GetAbilityInfoAbility::register();
    }

    if (!wp_has_ability('novamira-mcp-adapter/execute-ability')) {
        \Novamira\Vendor\WP\MCP\Abilities\ExecuteAbilityAbility::register();
    }
}

/**
 * Take over the registration of the adapter's built-in Abilities when the adapter initializes too late.
 *
 * Runs first on `novamira_mcp_adapter_init`, which the adapter fires from `init()` right after deciding
 * whether to attach its `register_default_abilities` callback and right before DefaultServerFactory
 * (priority 10 of this action) builds the default server. While `wp_abilities_api_init` has not fired
 * yet (the normal and WP-CLI orderings) nothing is done: the adapter's callback stays attached and
 * registers the built-ins itself when the registry boots. Once the action has fired or is running, that
 * callback can only ever double-register what novamira_register_mcp_adapter_builtin_abilities() did
 * or is about to do, so it is removed. The removal is also how Novamira learns what the adapter
 * decided: it returns true only when the adapter attached the callback, i.e. when it is going to build
 * the default server, and is a harmless no-op returning false when the default server is disabled.
 *
 * When `init()` is triggered from inside a `wp_abilities_api_init` callback, the server is about to be
 * created from whatever is registered at this instant, and McpTool keeps the WP_Ability objects it was
 * built from. Novamira's registration is therefore completed right here (both callbacks are idempotent
 * and the priority-10 / priority-20 runs that follow become no-ops), so the server always sees the
 * three built-ins with Novamira's discover-abilities replacement and patchers applied. What remains
 * uncovered is a server created before this action fires, i.e. by code other than the adapter's own
 * `init()`, which the adapter does not support (create_server() refuses it). For such a nested `init()`
 * the server is also built before the higher-priority callbacks of the still-running action, such as
 * the Ability policy, have run. Outside read mode its auto-discovered resources and prompts are
 * therefore those public at that instant; in read mode its tools, resources, and prompts are all taken
 * from the read-mode allowlist (novamira_mcp_server_components()), so a blocked Ability cannot be
 * snapshotted, and Novamira's executors refuse non-allowlisted Abilities again at execution time.
 */
function novamira_own_mcp_adapter_builtin_abilities(mixed $adapter): void
{
    if (!$adapter instanceof \Novamira\Vendor\WP\MCP\Core\McpAdapter) {
        return;
    }

    if (!did_action('wp_abilities_api_init') && !doing_action('wp_abilities_api_init')) {
        return;
    }

    $adapter_registers_builtins = remove_action(
        'wp_abilities_api_init',
        [$adapter, 'register_default_abilities'],
        priority: 10,
    );

    if (!$adapter_registers_builtins || !doing_action('wp_abilities_api_init')) {
        return;
    }

    novamira_register_mcp_adapter_builtin_abilities();
    novamira_register_builtin_abilities();
}

/**
 * Keep policy enforcement active even when Novamira is disabled so independently registered skill
 * or extension Abilities are removed according to the existing rules.
 *
 * The sweep itself is attached from the last priority before PHP_INT_MAX, not at plugin load: callbacks
 * of the same priority run in the order they were added, so a provider attached at PHP_INT_MAX by a
 * plugin loaded after Novamira, or by any earlier callback of the running action, would otherwise run
 * after the sweep and keep its Abilities. What even that cannot order (a provider attached to
 * PHP_INT_MAX from PHP_INT_MAX - 1 after this callback, or registered straight into the registry
 * later) is swept again by novamira_enforce_read_mode_registry() at the entry of every registry-backed
 * surface in read mode.
 */
function novamira_register_ability_policy_hook(): bool
{
    if (!novamira_wordpress_abilities_supported()) {
        return false;
    }

    add_action('wp_abilities_api_init', callback: 'novamira_attach_ability_policy_sweep', priority: PHP_INT_MAX - 1);

    return true;
}

/**
 * Append the Ability policy sweep to PHP_INT_MAX while `wp_abilities_api_init` runs, after every
 * callback attached there so far, including those attached by earlier priorities of this run.
 * WordPress takes the callbacks of a priority when it reaches that priority, so the sweep added here
 * still runs in this pass, last, and only once.
 */
function novamira_attach_ability_policy_sweep(): void
{
    remove_action('wp_abilities_api_init', callback: 'novamira_apply_ability_policy', priority: PHP_INT_MAX);
    add_action('wp_abilities_api_init', callback: 'novamira_apply_ability_policy', priority: PHP_INT_MAX);
}

/**
 * Register categories owned by Novamira's built-in Abilities.
 */
function novamira_register_ability_categories(): void
{
    wp_register_ability_category('code-execution', [
        'label' => __('Code Execution', domain: 'novamira'),
        'description' => __('Abilities that execute code on the WordPress server.', domain: 'novamira'),
    ]);

    wp_register_ability_category('context', [
        'label' => __('Context', domain: 'novamira'),
        'description' => __(
            'Read-only site guidance, environment, and skill summaries for agents.',
            domain: 'novamira',
        ),
    ]);

    wp_register_ability_category('filesystem', [
        'label' => __('Filesystem', domain: 'novamira'),
        'description' => __('Server filesystem operations.', domain: 'novamira'),
    ]);

    wp_register_ability_category('admin-access', [
        'label' => __('Admin Access', domain: 'novamira'),
        'description' => __('Temporary browser access to WordPress admin.', domain: 'novamira'),
    ]);

    if (!wp_has_ability_category('novamira-mcp-adapter')) {
        wp_register_ability_category('novamira-mcp-adapter', [
            'label' => __('MCP Adapter', domain: 'novamira'),
            'description' => __('Meta-abilities for MCP protocol bridging.', domain: 'novamira'),
        ]);
    }
}

function novamira_register_gutenberg_ability_category(): void
{
    wp_register_ability_category('gutenberg', [
        'label' => __('Gutenberg', domain: 'novamira'),
        'description' => __(
            'Gutenberg content abilities, including the Block Editor Queue for native/static blocks that need browser JS finalization. At the start of Gutenberg work, check the queue runtime and ask the user to keep the Block Editor Queue page open when static/native blocks may be queued.',
            domain: 'novamira',
        ),
    ]);
}

/**
 * Register every built-in Ability. The optional adapter may consume these registrations later but
 * is not a prerequisite for them.
 */
function novamira_register_builtin_abilities(): void
{
    $dir = __DIR__ . '/';
    require_once $dir . 'execute-php.php';
    require_once $dir . 'read-file.php';
    require_once $dir . 'write-file.php';
    require_once $dir . 'edit-file.php';
    require_once $dir . 'delete-file.php';
    require_once $dir . 'create-upload-link.php';
    require_once $dir . 'create-admin-access-link.php';
    require_once $dir . 'disable-file.php';
    require_once $dir . 'enable-file.php';
    require_once $dir . 'list-directory.php';
    require_once $dir . 'discover-abilities.php';
    require_once $dir . 'agent-context.php';
    if (novamira_wp_cli_status()['available']) {
        novamira_register_wp_cli_abilities();
    }
}
