<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The bundled MCP Adapter registers its built-in Abilities from a `wp_abilities_api_init` callback
 * that it only attaches during its own initialization. WordPress fires that action exactly once, when
 * the Abilities registry is first used, so any earlier caller of the Abilities API left the adapter's
 * get-ability-info and execute-ability unregistered and the Novamira MCP server with a single tool.
 * In the normal and WP-CLI orderings the adapter's own registrar still registers the built-ins and
 * Novamira does nothing. Only when the registry boots before that registrar is hooked, or the adapter
 * is initialized while Abilities are still being registered, does Novamira's priority-10 fallback
 * register the missing built-ins and its take-over hook remove the adapter's registrar.
 */
final class McpAdapterBuiltinAbilitiesRaceTest extends TestCase
{
    private const ADAPTER_ABILITIES = [
        'novamira-mcp-adapter/discover-abilities',
        'novamira-mcp-adapter/get-ability-info',
        'novamira-mcp-adapter/execute-ability',
    ];

    private const STOCK_BUILTINS = [
        'mcp-adapter/discover-abilities',
        'mcp-adapter/get-ability-info',
        'mcp-adapter/execute-ability',
    ];

    private const NOVAMIRA_SERVER_TOOLS = [
        'mcp-adapter-discover-abilities',
        'mcp-adapter-execute-ability',
        'mcp-adapter-get-ability-info',
    ];

    /** @return iterable<string, array{string}> */
    public static function scenarios(): iterable
    {
        yield 'normal ordering: the adapter boots the registry on rest_api_init:15' => ['normal'];
        yield 'WP-CLI ordering: the adapter boots the registry on init:20' => ['wp-cli'];
        yield 'registry booted before rest_api_init' => ['race'];
        yield 'foreign wp_abilities_api_init callback below the built-in registration' => ['foreign-below'];
        yield 'foreign wp_abilities_api_init callback below the built-in registration initializes the adapter' => [
            'init-between',
        ];
        yield 'foreign wp_abilities_api_init callback above priority 20 initializes the adapter' => ['init-above'];
        yield 'create_default_server filter true for the adapter, false inside wp_abilities_api_init' => [
            'filter-context',
        ];
        yield 'unprefixed adapter copy created after Novamira boots the registry on rest_api_init:15' => [
            'foreign-adapter',
        ];
        yield 'unprefixed adapter copy created after Novamira boots the registry on init:20 under WP-CLI' => [
            'foreign-adapter-wp-cli',
        ];
        yield 'registry read before rest_api_init with an unprefixed adapter copy' => ['stock-adapter-registry-read'];
        yield 'unprefixed adapter copy initialized on rest_api_init:20' => ['stock-adapter-late-priority'];
        yield 'unprefixed adapter copy initialized while wp_abilities_api_init runs' => [
            'stock-adapter-init-during-action',
        ];
        yield 'only the categories registry booted before rest_api_init' => ['stock-adapter-categories-first'];
    }

    /**
     * Read mode with an unprefixed adapter copy whose built-ins are registered after the registry booted:
     * a write Ability registered late is swept, the copy's own built-ins stay registered so its default
     * server finds them, and they are refused, so its execute-ability reaches no write Ability. Novamira's
     * server keeps its read-mode tools.
     */
    public function testReadModeStillSweepsWithAStockAdapterCopy(): void
    {
        $result = $this->runBootScenario('read-mode-stock-adapter');

        self::assertContains('stock adapter: init, registry booted=true', $result['trace']);
        self::assertContains('unregister late/write', $result['trace']);
        self::assertNotContains('late/write', $result['registered_after_policy']);
        foreach (self::STOCK_BUILTINS as $name) {
            self::assertNotContains('unregister ' . $name, $result['trace']);
            self::assertContains($name, $result['registered_after_policy']);
        }
        self::assertSame(array_fill_keys(self::STOCK_BUILTINS, true), $result['stock_adapter']['server_abilities']);
        self::assertSame(array_fill_keys(self::STOCK_BUILTINS, false), $result['stock_adapter']['permissions']);
        self::assertSame(
            ['late/write' => 'ability_invalid_permissions', 'novamira/execute-php' => 'ability_invalid_permissions'],
            $result['stock_adapter']['dispatch'],
        );
        self::assertContains('novamira/read-query', $result['registered_after_policy']);
        self::assertSame(self::NOVAMIRA_SERVER_TOOLS, array_keys($result['tools']));
    }

    /**
     * A filter that throws while Novamira registers the copy's built-ins late must not leave
     * wp_abilities_api_init, or the filter, on WordPress's stack of running hooks.
     */
    public function testLateRegistrationRestoresTheRunningHooksWhenAFilterThrows(): void
    {
        $result = $this->runBootScenario('stock-adapter-throwing-filter');

        self::assertSame(
            [
                'exception' => 'filter failed',
                'doing_wp_abilities_api_init' => false,
                'doing_wp_abilities_api_categories_init' => false,
                'doing_wp_register_ability_args' => false,
            ],
            $result['after_throw'],
        );
    }

    /** @return iterable<string, array{string}> */
    public static function noStockBuiltinsScenarios(): iterable
    {
        yield 'no unprefixed adapter copy' => ['race'];
        yield 'unprefixed adapter copy with its default server disabled' => ['stock-adapter-server-disabled'];
    }

    /**
     * Novamira registers the copy's built-ins only for a copy that initializes and builds its default
     * server, never on its own.
     */
    #[DataProvider('noStockBuiltinsScenarios')]
    public function testNoStockBuiltinsWithoutACopyThatBuildsItsDefaultServer(string $scenario): void
    {
        $result = $this->runBootScenario($scenario);

        foreach ($result['registered_after_policy'] as $ability_name) {
            self::assertStringStartsNotWith('mcp-adapter/', $ability_name);
        }
        foreach (self::ADAPTER_ABILITIES as $ability_name) {
            self::assertTrue($result['registered'][$ability_name], $ability_name . ' is not registered.');
        }
        self::assertSame(self::NOVAMIRA_SERVER_TOOLS, array_keys($result['tools']));
        self::assertSame([], $result['notices'], implode("\n", $result['notices']));
    }

    /**
     * The copy's built-ins registered late go through helpers.php's real Ability policy: an Abilities Hub
     * rule that turns one of them off is honoured as for an Ability registered in time.
     */
    public function testHubRuleTurnsOffALateStockBuiltin(): void
    {
        self::assertSame(
            [
                'category' => true,
                'registered' => ['mcp-adapter/discover-abilities', 'mcp-adapter/get-ability-info'],
                'unregistered' => ['mcp-adapter/execute-ability'],
            ],
            $this->runPolicyScenario('hub-disabled'),
        );
        self::assertSame(
            [
                'category' => true,
                'registered' => [
                    'mcp-adapter/discover-abilities',
                    'mcp-adapter/get-ability-info',
                    'mcp-adapter/execute-ability',
                ],
                'unregistered' => [],
            ],
            $this->runPolicyScenario('hub-enabled'),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function fenceScenarios(): iterable
    {
        yield 'built-ins registered late' => ['stock-adapter-fence'];
        yield 'built-ins registered in time' => ['stock-adapter-fence-native'];
    }

    /**
     * The copy's built-in Abilities are a gateway to every MCP-public Ability. Through them, neither an
     * administrator nor an editor can list, describe, or run a Novamira Ability, or one a Novamira feature
     * owns; another plugin's Abilities work as before. Novamira's own discovery and a direct call still reach
     * Novamira's Abilities.
     */
    #[DataProvider('fenceScenarios')]
    public function testStockBuiltinsDoNotReachNovamiraAbilities(string $scenario): void
    {
        $result = $this->runBootScenario($scenario);
        $fence = $result['fence'];

        foreach (['administrator', 'editor'] as $role) {
            self::assertSame(['acme/ping'], $fence[$role]['discover'], $role);
            foreach (['novamira/stand-in-tool', 'novamira/skill-prompt-demo', 'pro-addon/feature-ability'] as $target) {
                foreach (['info', 'execute'] as $kind) {
                    self::assertSame('ability_not_found', $fence[$role][$kind][$target]['permission'], "{$role} {$kind} {$target}");
                    self::assertSame('ability_invalid_permissions', $fence[$role][$kind][$target]['result']['wp_error']);
                }
            }
            self::assertSame(['permission' => true, 'result' => 'described'], $fence[$role]['info']['acme/ping']);
            self::assertSame(
                ['permission' => true, 'result' => ['success' => true, 'data' => 'ran acme/ping']],
                $fence[$role]['execute']['acme/ping'],
            );
        }
        // Only the direct call ran the Novamira Ability.
        self::assertSame(
            ['executed acme/ping', 'executed novamira/stand-in-tool', 'executed acme/ping'],
            array_values(array_filter($result['trace'], static fn(string $line): bool => str_starts_with($line, 'executed '))),
        );
        self::assertSame('ran novamira/stand-in-tool', $fence['direct']);
        self::assertContains('novamira/stand-in-tool', $fence['own_discover']);
        self::assertContains('pro-addon/feature-ability', $fence['own_discover']);
        self::assertSame(self::NOVAMIRA_SERVER_TOOLS, array_keys($result['tools']));
        self::assertSame([], $result['notices'], implode("\n", $result['notices']));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function stockAdapterScenarios(): iterable
    {
        yield 'created after Novamira, init() on rest_api_init:15' => ['foreign-adapter', false];
        yield 'created after Novamira, init() on init:20 under WP-CLI' => ['foreign-adapter-wp-cli', false];
        yield 'registry read before rest_api_init, as by a Novamira admin screen' => [
            'stock-adapter-registry-read',
            true,
        ];
        yield 'init() moved to rest_api_init:20' => ['stock-adapter-late-priority', true];
        yield 'init() run by a priority-20 wp_abilities_api_init callback' => ['stock-adapter-init-during-action', true];
        yield 'categories registry booted alone before rest_api_init' => ['stock-adapter-categories-first', false];
    }

    /**
     * Another plugin bundles an unprefixed copy of the MCP Adapter, which attaches its built-in Ability
     * registration only from its own init(). Whatever boots the one-shot Abilities registry first, and
     * whenever that init() runs, the copy's default server must find its own built-in Abilities, and
     * Novamira's own three must exist. With the same hook and priority, the bundled adapter initializes
     * after the copy, so the registry is not booted yet when the copy initializes.
     */
    #[DataProvider('stockAdapterScenarios')]
    public function testStockAdapterCopyFindsItsBuiltinAbilities(string $scenario, bool $registry_booted_first): void
    {
        $result = $this->runBootScenario($scenario);

        self::assertContains(
            'stock adapter: init, registry booted=' . var_export($registry_booted_first, return: true),
            $result['trace'],
        );
        $all_registered = [
            'mcp-adapter/discover-abilities' => true,
            'mcp-adapter/get-ability-info' => true,
            'mcp-adapter/execute-ability' => true,
        ];
        self::assertSame($all_registered, $result['stock_adapter']['server_abilities']);
        foreach (array_keys($all_registered) as $ability_name) {
            self::assertSame(1, count(array_keys($result['trace'], 'register ' . $ability_name, strict: true)));
        }
        foreach (self::ADAPTER_ABILITIES as $ability_name) {
            self::assertTrue($result['registered'][$ability_name], $ability_name . ' is not registered.');
        }
        self::assertSame(self::NOVAMIRA_SERVER_TOOLS, array_keys($result['tools']));
        self::assertSame([], $result['notices'], implode("\n", $result['trace']));
    }

    #[DataProvider('scenarios')]
    public function testBuiltinToolsAreCompleteAndNothingIsRegisteredTwice(string $scenario): void
    {
        $result = $this->runBootScenario($scenario);

        self::assertSame(1, $result['wp_abilities_api_init_count']);
        self::assertTrue($result['category_registered']);
        foreach (self::ADAPTER_ABILITIES as $ability_name) {
            self::assertTrue($result['registered'][$ability_name], $ability_name . ' is not registered.');
        }
        self::assertSame(self::NOVAMIRA_SERVER_TOOLS, array_keys($result['tools']));

        // Novamira's replacement and patchers apply on top of the adapter's definitions, and the
        // server was built from the patched Abilities.
        self::assertSame('novamira_get_ability_info_permission', $result['get_ability_info_permission_callback']);
        self::assertTrue($result['execute_ability_parameters']['additionalProperties']);
        self::assertSame([], $result['execute_ability_parameters']['properties']);
        self::assertTrue($result['tools']['mcp-adapter-execute-ability']['parameters']['additionalProperties']);
        self::assertSame([], $result['tools']['mcp-adapter-execute-ability']['parameters']['properties']);
        self::assertStringContainsString(
            'Novamira environment instructions',
            $result['tools']['mcp-adapter-discover-abilities']['description'],
        );

        // No duplicate registration, no registration outside wp_abilities_api_init, no missing category,
        // no tool registration against a missing Ability.
        self::assertSame([], $result['notices'], implode("\n", $result['trace']));
    }

    /**
     * An adapter init() nested inside wp_abilities_api_init builds the server before the read-mode policy
     * runs at PHP_INT_MAX. The server keeps the Ability objects it was built from, so in read mode its
     * resources and prompts must come from the allowlist, not from the public Abilities registered at that
     * moment, and its tools are the same three meta-tools as outside read mode.
     */
    public function testNestedInitInReadModeSnapshotsOnlyAllowedAbilities(): void
    {
        $result = $this->runBootScenario('read-mode-init-between');

        self::assertContains('foreign callback: server created=true', $result['trace']);
        self::assertSame([], $result['resources']);
        self::assertSame([], $result['prompts']);
        self::assertSame(self::NOVAMIRA_SERVER_TOOLS, array_keys($result['tools']));
        foreach (['foreign/write-tool', 'foreign/secret-resource', 'foreign/write-prompt'] as $blocked) {
            self::assertNotContains($blocked, $result['registered_after_policy']);
        }
        self::assertNotContains('foreign ability executed', $result['trace']);
        self::assertSame([], $result['notices'], implode("\n", $result['trace']));
    }

    /**
     * WordPress runs callbacks of the same priority in the order they were added. The policy sweep is
     * therefore appended to PHP_INT_MAX while the action runs, after a provider that a plugin loaded after
     * Novamira attached there statically.
     */
    public function testReadModeSweepRunsAfterALateMaximumPriorityProvider(): void
    {
        $result = $this->runBootScenario('read-mode-late-provider');

        $provider = array_search('late provider registers late/write', $result['trace'], strict: true);
        $sweep = array_search('policy sweep', $result['trace'], strict: true);
        self::assertIsInt($provider);
        self::assertIsInt($sweep);
        self::assertGreaterThan($provider, $sweep);
        self::assertContains('unregister late/write', $result['trace']);
        self::assertNotContains('late/write', $result['registered_after_policy']);
        self::assertContains('novamira/read-query', $result['registered_after_policy']);
        self::assertSame(self::NOVAMIRA_SERVER_TOOLS, array_keys($result['tools']));
        self::assertSame([], $result['notices'], implode("\n", $result['notices']));
    }

    public function testLateMaximumPriorityProviderIsUntouchedOutsideReadMode(): void
    {
        $result = $this->runBootScenario('late-provider-off');

        self::assertContains('policy sweep', $result['trace']);
        self::assertContains('late/write', $result['registered_after_policy']);
        self::assertContains('novamira/execute-php', $result['registered_after_policy']);
        self::assertNotContains('novamira/read-query', $result['registered_after_policy']);
        self::assertSame(
            ['mcp-adapter-discover-abilities', 'mcp-adapter-execute-ability', 'mcp-adapter-get-ability-info'],
            array_keys($result['tools']),
        );
        self::assertSame([], $result['notices'], implode("\n", $result['notices']));
    }

    /**
     * A normal priority-10 callback attaches a provider to PHP_INT_MAX while wp_abilities_api_init runs.
     * The sweep is appended from PHP_INT_MAX - 1, after it, so the write Ability is gone when the action
     * ends. A provider that still lands after the sweep (attached from PHP_INT_MAX - 1 after Novamira), and
     * an Ability registered straight into the registry after the action, are removed when any
     * registry-backed surface is entered, before it lists, checks permissions, or executes.
     */
    public function testReadModeSweepsDynamicallyAttachedAndLateAbilitiesAtEverySurface(): void
    {
        $result = $this->runBootScenario('read-mode-dynamic-providers');

        self::assertSame(['dynamic' => false, 'escaping' => true], $result['after_action']);
        $provider = array_search('dynamic provider registers late/dynamic-write', $result['trace'], strict: true);
        self::assertIsInt($provider);
        self::assertGreaterThan($provider, array_search('policy sweep', $result['trace'], strict: true));

        foreach (['core_abilities_list', 'core_ability_run', 'mcp_http_route', 'novamira_route', 'mcp_stdio_start', 'discover_abilities', 'get_ability_info_permission'] as $surface) {
            self::assertFalse($result['surfaces'][$surface]['still_registered'], $surface);
        }
        self::assertFalse($result['surfaces']['discover_abilities']['outcome']);
        self::assertSame('novamira_read_mode', $result['surfaces']['get_ability_info_permission']['outcome']);
        // Routes that do not read the Ability registry are left alone.
        self::assertTrue($result['surfaces']['unrelated_route']['still_registered']);
        self::assertSame(['dynamic' => false, 'escaping' => false], $result['surfaces']['after_all']);
        self::assertSame([], $result['notices'], implode("\n", $result['notices']));
    }

    /**
     * The bundled adapter's tools/call, prompts/get, and resources/read handlers on the canonical server,
     * a mirror, and a foreign server that snapshotted Abilities registered after the sweep.
     */
    /**
     * A plugin can attach its own list filter at PHP_INT_MAX while the list is being built; in that same
     * pass WordPress runs it after every callback already attached there, read mode's included. Read mode
     * checks the final list where the request router returns it, after every filter, so nothing it does
     * not allow is listed over HTTP. Outside read mode the lists are as before.
     */
    public function testListsBuiltWithFiltersAddedDuringThePassShowOnlyWhatReadModeAllows(): void
    {
        $on = $this->runBootScenario('read-mode-dynamic-providers')['handlers']['http'];
        self::assertNull($on['entry']);
        self::assertSame(
            [
                'tools' => ['mcp-adapter-discover-abilities', 'mcp-adapter-get-ability-info', 'mcp-adapter-execute-ability'],
                'resources' => [],
                'prompts' => [],
            ],
            $on['lists'],
        );

        $off = $this->runBootScenario('dynamic-providers-off')['handlers']['http'];
        self::assertSame(
            [
                'tools' => ['mcp-adapter-discover-abilities', 'mcp-adapter-get-ability-info', 'mcp-adapter-execute-ability', 'acme-callable', 'acme-added-during-pass'],
                'resources' => ['late://escaping', 'acme://added-during-pass'],
                'prompts' => ['late-escaping-prompt', 'acme-added-during-pass'],
            ],
            $off['lists'],
        );
    }

    public function testReadModeMcpHandlersRefuseAbilitiesOutsideTheAllowlist(): void
    {
        $result = $this->runBootScenario('read-mode-dynamic-providers');

        foreach (['novamira', 'novamira-mirror'] as $server_id) {
            $server = $result['handlers'][$server_id];
            self::assertIsArray($server, $server_id);
            self::assertNotContains('late-escaping-write', $server['tool_names'], $server_id);
            self::assertSame('Tool not found: late-escaping-write', $server['tools_call']['error']['message'], $server_id);
            self::assertSame('Prompt not found: late-escaping-prompt', $server['prompts_get']['error']['message'], $server_id);
            self::assertSame('Resource not found: late://escaping', $server['resources_read']['error']['message'], $server_id);
            self::assertTrue($server['ability_info_blocked']['isError'], $server_id);
            self::assertStringStartsWith('Read mode is active on this site', $server['ability_info_blocked']['content'][0]['text']);
            self::assertFalse($server['ability_info_allowed']['isError'] ?? false, $server_id);
            self::assertStringContainsString('"name":"novamira\\/read-file"', $server['ability_info_allowed']['content'][0]['text']);
            self::assertSame(0, $server['late_permission_calls'], $server_id);
            self::assertSame(0, $server['late_executions'], $server_id);
        }

        // Every MCP entry reconciles each server's components with the trusted allowlist, so a foreign
        // server that snapshotted late Abilities and a callable-backed tool has nothing left to call, and
        // a later pre-call filter that tries to undo Novamira's refusal changes nothing.
        $foreign = $result['handlers']['foreign-snapshot'];
        self::assertSame(
            [
                'tools' => ['late-escaping-write', 'acme-foreign-callable'],
                'resources' => ['late://escaping'],
                'prompts' => ['late-escaping-prompt'],
            ],
            $result['foreign_components_before'],
        );
        self::assertSame([], $foreign['tool_names']);
        self::assertSame('Tool not found: late-escaping-write', $foreign['tools_call']['error']['message']);
        self::assertSame('Prompt not found: late-escaping-prompt', $foreign['prompts_get']['error']['message']);
        self::assertSame('Resource not found: late://escaping', $foreign['resources_read']['error']['message']);
        self::assertSame('Tool not found: acme-foreign-callable', $foreign['foreign_callable_tool_call']['error']['message']);
        foreach (['novamira', 'novamira-mirror', 'foreign-snapshot'] as $server_id) {
            $server = $result['handlers'][$server_id];
            // A callable-backed tool a later configuration filter added to the default server is not there.
            self::assertSame('Tool not found: acme-callable', $server['callable_tool_call']['error']['message'], $server_id);
            self::assertSame(0, $server['late_permission_calls'], $server_id);
            self::assertSame(0, $server['late_executions'], $server_id);
            self::assertSame(0, $server['callable_calls'], $server_id);
        }
    }

    /**
     * The MCP tool list is the same with read mode on and off, so clients configured once keep working, and
     * execute-ability dispatches only to trusted allowlisted Abilities in read mode.
     */
    public function testReadModeKeepsTheToolListAndExecuteAbilityRunsOnlyAllowedAbilities(): void
    {
        $on = $this->runBootScenario('read-mode-dynamic-providers');
        $off = $this->runBootScenario('dynamic-providers-off');

        $meta_tools = ['mcp-adapter-discover-abilities', 'mcp-adapter-get-ability-info', 'mcp-adapter-execute-ability'];
        foreach (['novamira', 'novamira-mirror'] as $server_id) {
            self::assertSame($meta_tools, $on['handlers'][$server_id]['tool_names'], $server_id);
            self::assertSame(
                $meta_tools,
                array_values(array_intersect($off['handlers'][$server_id]['tool_names'], $meta_tools)),
                $server_id,
            );

            $read_query = $on['handlers'][$server_id]['execute_read_query'];
            self::assertFalse($read_query['isError'] ?? false, $server_id);
            self::assertTrue($read_query['structuredContent']['success'], $server_id);
            self::assertSame([['one' => '1']], $read_query['structuredContent']['data']['rows'], $server_id);

            foreach (['execute_php' => 'novamira/execute-php', 'execute_late' => 'late/escaping-write'] as $call => $target) {
                $refusal = $on['handlers'][$server_id][$call];
                self::assertTrue($refusal['isError'], $server_id . ' ' . $call);
                self::assertStringStartsWith(
                    "Read mode is active on this site, so the ability '{$target}' is unavailable",
                    $refusal['content'][0]['text'],
                );
            }
        }

        // Outside read mode execute-ability behaves as before: execute-php runs, and read-query, a read-mode
        // Ability, does not exist.
        self::assertSame(42, $off['handlers']['novamira']['execute_php']['structuredContent']['data']['return_value']);
        self::assertSame(
            "Ability 'novamira/read-query' not found",
            $off['handlers']['novamira']['execute_read_query']['content'][0]['text'],
        );
    }

    public function testDynamicAndLateAbilitiesAreUntouchedOutsideReadMode(): void
    {
        $result = $this->runBootScenario('dynamic-providers-off');

        self::assertSame(['dynamic' => true, 'escaping' => true], $result['after_action']);
        foreach ($result['surfaces'] as $surface => $outcome) {
            if ($surface === 'after_all') {
                self::assertSame(['dynamic' => true, 'escaping' => true], $outcome);
                continue;
            }
            self::assertTrue($outcome['still_registered'], $surface);
        }
        self::assertTrue($result['surfaces']['discover_abilities']['outcome']);
        // Only the adapter's own checks apply: the runner's request is not signed in.
        self::assertSame('authentication_required', $result['surfaces']['get_ability_info_permission']['outcome']);

        $foreign = $result['handlers']['foreign-snapshot'];
        self::assertFalse($foreign['tools_call']['isError']);
        self::assertSame(['late-escaping-write', 'acme-foreign-callable'], $foreign['tool_names']);
        self::assertFalse($foreign['foreign_callable_tool_call']['isError']);
        self::assertSame(3, $foreign['late_executions']);
        // The default server keeps the callable-backed tool a configuration filter added.
        self::assertContains('acme-callable', $result['handlers']['novamira']['tool_names']);
        self::assertFalse($result['handlers']['novamira']['callable_tool_call']['isError']);
        self::assertContains('mcp-adapter-execute-ability', $result['handlers']['novamira']['tool_names']);
        self::assertContains('mcp-adapter-execute-ability', $result['handlers']['novamira-mirror']['tool_names']);
        // The adapter's own lookups of the read-mode-only read-query, which does not exist outside read mode.
        self::assertSame(
            array_fill(0, 2, 'WP_Abilities_Registry::get_registered: Ability "novamira/read-query" not found.'),
            $result['notices'],
        );
    }

    public function testDisabledDefaultServerLeavesTheAbilitiesButBuildsNoServer(): void
    {
        $result = $this->runBootScenario('server-disabled');

        // The adapter never attaches its registrar; Novamira still registers the three Abilities
        // (as it always did for discover-abilities), so they exist in the registry...
        foreach (self::ADAPTER_ABILITIES as $ability_name) {
            self::assertTrue($result['registered'][$ability_name], $ability_name . ' is not registered.');
        }
        self::assertSame('novamira_get_ability_info_permission', $result['get_ability_info_permission_callback']);
        // ...but no server exposes them, and taking over a registrar that was never attached is a no-op.
        self::assertContains('server novamira: not created', $result['trace']);
        self::assertSame([], $result['tools']);
        self::assertSame([], $result['rest_routes']);
        self::assertNotContains('remove_action wp_abilities_api_init:10 register_default_abilities', $result['trace']);
        self::assertSame([], $result['notices'], implode("\n", $result['trace']));
    }

    public function testRaceScenariosBootTheRegistryBeforeTheAdapterInitializes(): void
    {
        $race = $this->runBootScenario('race');
        self::assertIsArray($race['abilities_before_rest_api_init']);
        self::assertContains('novamira/execute-php', $race['abilities_before_rest_api_init']);
        self::assertContains('/mcp/novamira', $race['rest_routes']);

        $normal = $this->runBootScenario('normal');
        self::assertNull($normal['abilities_before_rest_api_init']);
        self::assertContains('/mcp/novamira', $normal['rest_routes']);
        self::assertSame($race['ability_count'], $normal['ability_count']);

        // Normal ordering: the adapter keeps its own registrar (nothing is removed) and registers the
        // built-ins itself, last among the priority-10 callbacks because it is attached from init() —
        // so a foreign priority-10 callback attached at plugin load still observes them as absent,
        // exactly as on releases before this change.
        foreach ([$normal, $this->runBootScenario('wp-cli')] as $result) {
            self::assertNotContains('remove_action wp_abilities_api_init:10 register_default_abilities', $result['trace']);
            self::assertContains('probe@10: execute-ability registered=false', $result['trace']);
            self::assertLessThan(
                array_search('register novamira-mcp-adapter/execute-ability', $result['trace'], strict: true),
                array_search('probe@10: execute-ability registered=false', $result['trace'], strict: true),
            );
        }

        // Race ordering: the registrar does not exist yet when the action fires, so Novamira registers
        // the built-ins at priority 10 in its own attachment order (before the probe) and the adapter's
        // later-attached registrar is removed as useless. The probe therefore observes them as present —
        // acceptable, since before this change it observed them absent and the tools then never existed.
        self::assertContains('probe@10: execute-ability registered=true', $race['trace']);
        self::assertContains('remove_action wp_abilities_api_init:10 register_default_abilities', $race['trace']);
        self::assertGreaterThan(
            array_search('probe@10: execute-ability registered=true', $race['trace'], strict: true),
            array_search('remove_action wp_abilities_api_init:10 register_default_abilities', $race['trace'], strict: true),
        );
    }

    public function testRegistryAccessBeforeInitDoesNotConsumeTheBoot(): void
    {
        $result = $this->runBootScenario('pre-init');

        self::assertContains('before init: execute-ability registered=false', $result['trace']);
        self::assertSame(1, $result['wp_abilities_api_init_count']);
        self::assertSame(self::NOVAMIRA_SERVER_TOOLS, array_keys($result['tools']));
        self::assertContains('probe@10: execute-ability registered=false', $result['trace']);
        // Only core's own notice for the premature access; nothing from the registration itself.
        self::assertCount(1, $result['notices'], implode("\n", $result['notices']));
        self::assertStringContainsString('should not be initialized before the init action', $result['notices'][0]);
    }

    public function testForeignCallbacksRunWhereTheScenarioPlacesThem(): void
    {
        $below = $this->runBootScenario('foreign-below');
        self::assertContains('foreign callback: execute-ability registered=false', $below['trace']);
        self::assertContains('register foreign/ping', $below['trace']);

        $between = $this->runBootScenario('init-between');
        self::assertContains('foreign callback: adapter init()', $between['trace']);
        self::assertContains('foreign callback: server created=true', $between['trace']);
        // The server was created mid-action, after Novamira's replacement and patchers were applied.
        $init_index = array_search('foreign callback: adapter init()', $between['trace'], strict: true);
        $server_index = array_search('foreign callback: server created=true', $between['trace'], strict: true);
        $replacement_index = array_search('unregister novamira-mcp-adapter/discover-abilities', $between['trace'], strict: true);
        self::assertGreaterThan($init_index, $replacement_index);
        self::assertLessThan($server_index, $replacement_index);

        $above = $this->runBootScenario('init-above');
        self::assertContains('foreign callback: server created=true', $above['trace']);

        $filtered = $this->runBootScenario('filter-context');
        self::assertContains('filter novamira_mcp_adapter_create_default_server -> true', $filtered['trace']);
        self::assertNotContains('filter novamira_mcp_adapter_create_default_server -> false', $filtered['trace']);
    }

    public function testMcpDiscoveryMetadataVisibilityRemainsNamespaceScoped(): void
    {
        $result = $this->runBootScenario('foreign-below');

        self::assertContains('foreign/ping', $result['subscriber_discovery']);
        self::assertNotContains('novamira/read-file', $result['subscriber_discovery']);
        self::assertContains('foreign/ping', $result['manager_discovery']);
        self::assertContains('novamira/read-file', $result['manager_discovery']);
    }

    public function testHookEmulationFollowsWpHookIteration(): void
    {
        $result = $this->runBootScenario('hook-conformance');

        self::assertSame(
            [
                // First run: mutations while priority 10 executes.
                '5',
                '10: mutating',
                'remove 15 -> true',
                'remove unknown -> false',
                '12: added while 10 runs',
                '20',
                // Second run: everything that survived, in priority order; the mutating callback
                // (attached first at 10) adds a second, distinct closure at 12 that runs in-pass too.
                '3: added while 10 runs',
                '5',
                '10: mutating',
                'remove 15 -> false',
                'remove unknown -> false',
                '10: added while 10 runs',
                '12: added while 10 runs',
                '12: added while 10 runs',
                '20',
            ],
            $result['ran'],
        );
    }

    /**
     * The STDIO server is one long-running process. When read mode is turned on by another process while
     * it serves (the test rewrites the stored state between calls, as Novamira Pro's web request would),
     * the next call that reaches read mode ends the session before anything runs, and tells the client to
     * restart: nothing runs in a mix of both modes. Through the adapter's own StdioServerBridge::serve().
     */
    public function testStdioSessionEndsBeforeAnythingRunsWhenReadModeTurnsOn(): void
    {
        $session = $this->startStdioSession('0');
        $trio = ['mcp-adapter-discover-abilities', 'mcp-adapter-get-ability-info', 'mcp-adapter-execute-ability'];

        // Read mode off: lists and writes as without read mode, later plugins' list items included.
        self::assertSame(
            [...$trio, 'acme-callable', 'acme-listed-tool', 'acme-added-during-pass'],
            array_column($session->rpc('tools/list')['result']['tools'], 'name'),
        );
        self::assertSame(
            ['late://escaping', 'acme://listed', 'acme://added-during-pass'],
            array_column($session->rpc('resources/list')['result']['resources'], 'uri'),
        );
        self::assertFalse($session->execute('late/escaping-write')['result']['isError']);

        $session->setState('1');
        $ended = $session->execute('late/escaping-write');
        [$exit_code, $rest, $stderr] = $session->finish();

        self::assertSame('notifications/message', $ended['method']);
        self::assertSame('error', $ended['params']['level']);
        self::assertStringContainsString('read mode was turned on while this MCP session was running', $ended['params']['data']);
        self::assertStringContainsString('restart', $ended['params']['data']);
        self::assertSame('', $rest);
        self::assertSame(1, $exit_code);
        self::assertStringContainsString('[Novamira] Novamira read mode was turned on', $stderr);
        $counters = self::counters($stderr);
        // Only the call made before read mode turned on reached the ability (three permission checks and
        // one execution, as a session without the change shows); the call after it reached nothing.
        self::assertSame(['permission' => 3, 'execute' => 1, 'callable' => 0], $counters['calls']);
        self::assertContains('late/escaping-write', $counters['registered'], 'The session ended before any sweep.');
    }

    /**
     * A session that started in read mode ends when read mode is turned off, so it never keeps serving the
     * read-mode component set (and read-mode-only abilities) in a session where read mode is off.
     */
    public function testStdioSessionEndsWhenReadModeTurnsOff(): void
    {
        $session = $this->startStdioSession('1');
        $trio = ['mcp-adapter-discover-abilities', 'mcp-adapter-get-ability-info', 'mcp-adapter-execute-ability'];

        // Read mode on from the start: later plugins' list items are dropped, including those of a filter
        // attached while the list is built; read-query runs, writes do not.
        self::assertSame($trio, array_column($session->rpc('tools/list')['result']['tools'], 'name'));
        self::assertSame($trio, array_column($session->rpc('tools/list')['result']['tools'], 'name'));
        self::assertSame([], $session->rpc('resources/list')['result']['resources']);
        self::assertSame([], $session->rpc('prompts/list')['result']['prompts']);
        self::assertFalse($session->execute('novamira/read-query', ['sql' => 'SELECT 1 AS one'])['result']['isError']);
        $refused = $session->execute('late/escaping-write')['result'];
        self::assertTrue($refused['isError']);
        self::assertStringContainsString("the ability 'late/escaping-write' is unavailable", $refused['content'][0]['text']);

        $session->setState('0');
        $ended = $session->rpc('tools/list');
        [$exit_code, $rest, $stderr] = $session->finish();

        self::assertSame('notifications/message', $ended['method']);
        self::assertStringContainsString('read mode was turned off while this MCP session was running', $ended['params']['data']);
        self::assertSame('', $rest);
        self::assertSame(1, $exit_code);
        $counters = self::counters($stderr);
        self::assertSame(0, $counters['calls']['execute']);
        self::assertContains('novamira/read-query', $counters['registered']);
    }

    /** A session started after the change runs in the new mode only, and a session without a change ends normally. */
    public function testStdioSessionsStartCleanInEitherMode(): void
    {
        $off = $this->startStdioSession('0');
        self::assertSame(
            ['mcp-adapter-discover-abilities', 'mcp-adapter-get-ability-info', 'mcp-adapter-execute-ability', 'acme-callable', 'acme-listed-tool', 'acme-added-during-pass'],
            array_column($off->rpc('tools/list')['result']['tools'], 'name'),
        );
        self::assertSame(
            ['late-escaping-prompt', 'acme-listed-prompt', 'acme-added-during-pass'],
            array_column($off->rpc('prompts/list')['result']['prompts'], 'name'),
        );
        self::assertTrue($off->execute('novamira/read-query', ['sql' => 'SELECT 1'])['result']['isError']);
        [$exit_code, $rest, $stderr] = $off->finish();
        self::assertSame(0, $exit_code, $stderr);
        self::assertSame('', $rest);
        self::assertNotContains('novamira/read-query', self::counters($stderr)['registered']);
        self::assertStringNotContainsString('[Novamira]', $stderr);
    }

    /** Start the canonical server over STDIO in a child process, with read mode stored as the given value. */
    private function startStdioSession(string $state): object
    {
        $root = dirname(__DIR__, levels: 2);
        $state_file = (string) tempnam(sys_get_temp_dir(), 'novamira-read-mode-state-');
        file_put_contents($state_file, $state);
        $process = proc_open(
            [PHP_BINARY, $root . '/tests/fixtures/mcp-adapter-boot-runner.php', $root, 'stdio-session'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            env_vars: array_merge(getenv(), ['NOVAMIRA_TEST_STATE_FILE' => $state_file]),
        );
        self::assertIsResource($process);

        return new class($process, $pipes, $state_file) {
            private int $id = 0;

            /** @param array<int, resource> $pipes */
            public function __construct(
                private mixed $process,
                private array $pipes,
                private string $state_file,
            ) {}

            /** @return array<string, mixed> */
            public function rpc(string $method, array $params = []): array
            {
                $request = ['jsonrpc' => '2.0', 'id' => ++$this->id, 'method' => $method, 'params' => $params];
                fwrite($this->pipes[0], json_encode($request) . "\n");
                fflush($this->pipes[0]);
                $response = json_decode((string) fgets($this->pipes[1]), associative: true);
                TestCase::assertIsArray($response);
                return $response;
            }

            /** @return array<string, mixed> */
            public function execute(string $ability, array $parameters = []): array
            {
                return $this->rpc('tools/call', [
                    'name' => 'mcp-adapter-execute-ability',
                    'arguments' => ['ability_name' => $ability, 'parameters' => $parameters],
                ]);
            }

            public function setState(string $state): void
            {
                file_put_contents($this->state_file, $state);
            }

            /** @return array{0: int, 1: string, 2: string} Exit code, remaining stdout, stderr. */
            public function finish(): array
            {
                fclose($this->pipes[0]);
                $rest = (string) stream_get_contents($this->pipes[1]);
                $stderr = (string) stream_get_contents($this->pipes[2]);
                fclose($this->pipes[1]);
                fclose($this->pipes[2]);
                $exit_code = proc_close($this->process);
                unlink($this->state_file);
                return [$exit_code, $rest, $stderr];
            }
        };
    }

    /** @return array{calls: array<string, int>, registered: list<string>} */
    private static function counters(string $stderr): array
    {
        self::assertSame(1, preg_match('/^COUNTERS (.+)$/m', $stderr, $match), $stderr);
        $counters = json_decode($match[1], associative: true);
        self::assertIsArray($counters);
        return $counters;
    }

    /** @return array<string, mixed> */
    /** @return iterable<string, array{string, bool}> */
    public static function realStockServerScenarios(): iterable
    {
        yield 'built-ins registered in time' => ['stock-server-real', false];
        yield 'registry read before rest_api_init' => ['stock-server-real-late', false];
        yield 'later filters and a server created after the pruning' => ['stock-server-real-hostile', true];
    }

    /**
     * Every server of the real unprefixed adapter loses the components that run a Novamira Ability before
     * anything is listed, checked, or run: its default server under another plugin's partial configuration
     * (whose merged defaults bring back Novamira's resource and skill prompt), a server that plugin creates
     * with create_server() holding them by name and as component objects, and, with the later filters, a
     * server created after the pruning. Through the adapter's real handlers, as an administrator and as an
     * editor, tools/call, resources/read, and prompts/get answer exactly as for a name never registered, also
     * when a later filter undoes refusals and lists Novamira's names; execute-ability and discover-abilities
     * do not reach them. The other plugin's components work as before, and Novamira's own server is unchanged.
     */
    #[DataProvider('realStockServerScenarios')]
    public function testNoServerOfTheStockAdapterReachesNovamiraAbilities(string $scenario, bool $hostile): void
    {
        $result = $this->runBootScenario($scenario);
        $owned = ['novamira/stand-in-tool', 'novamira/stand-in-resource', 'novamira/skill-prompt-demo', 'pro-addon/feature-ability'];
        $absent = [
            'tool' => [['novamira-stand-in-tool', 'pro-addon-feature-ability'], 'acme-never-registered', 'tools/list'],
            'resource' => [['novamira://stand-in'], 'acme://never-registered', 'resources/list'],
            'prompt' => [['novamira-skill-prompt-demo'], 'acme-never-registered', 'prompts/list'],
        ];

        // What the servers held when they were created.
        $created = $result['components_at_creation'];
        self::assertSame('novamira/stand-in-resource', $created['mcp-adapter-default-server']['resource novamira://stand-in']);
        self::assertSame('novamira/skill-prompt-demo', $created['mcp-adapter-default-server']['prompt novamira-skill-prompt-demo']);
        self::assertSame('novamira/stand-in-tool', $created['acme-server']['tool novamira-stand-in-tool']);
        self::assertSame('pro-addon/feature-ability', $created['acme-server']['tool pro-addon-feature-ability']);
        self::assertSame('novamira/stand-in-resource', $created['acme-server']['resource novamira://stand-in']);
        self::assertSame($hostile, isset($created['acme-late-server']['tool novamira-stand-in-tool']));

        foreach ($result['stock_servers'] as $role => $servers) {
            foreach ($servers as $server_id => $report) {
                if ($report === null) {
                    self::assertSame('acme-late-server', $server_id);
                    self::assertFalse($hostile);
                    continue;
                }
                foreach ($absent as $kind => [$names, $missing, $list]) {
                    foreach ($names as $name) {
                        $context = "{$role} {$server_id} {$kind} {$name}";
                        self::assertSame($report['absent'][$kind][$missing], $report['absent'][$kind][$name], $context);
                        if (!$hostile) {
                            self::assertNotContains($name, $report[$list], $context);
                        }
                    }
                }
                $calls = ['tool' => ['tools/list', 'tools/call'], 'resource' => ['resources/list', 'resources/read'], 'prompt' => ['prompts/list', 'prompts/get']];
                foreach ($report['components'] as $component => $ability_name) {
                    self::assertNotContains($ability_name, $owned, "{$role} {$server_id} {$component}");
                    if (!str_starts_with((string) $ability_name, 'acme/')) {
                        continue;
                    }
                    [$kind, $name] = explode(' ', $component, limit: 2);
                    [$list, $call] = $calls[$kind];
                    self::assertContains($name, $report[$list]);
                    self::assertSame(['executed' => [$ability_name], 'error' => false], $report[$call][$name]);
                }
                if (isset($report['execute-ability'])) {
                    foreach (['novamira/stand-in-tool', 'pro-addon/feature-ability'] as $target) {
                        self::assertSame(['executed' => [], 'error' => true], $report['execute-ability'][$target]);
                    }
                    self::assertSame(['executed' => ['acme/ping'], 'error' => false], $report['execute-ability']['acme/ping']);
                }
                if (isset($report['discover-abilities'])) {
                    self::assertSame(['acme/ping'], $report['discover-abilities'], "{$role} {$server_id}");
                }
            }
        }

        $executed = array_filter($result['trace'], static fn(string $line): bool => str_starts_with($line, 'executed '));
        self::assertSame([], array_values(array_filter(
            $executed,
            static fn(string $line): bool => in_array(substr($line, strlen('executed ')), $owned, strict: true),
        )));
        self::assertSame(self::NOVAMIRA_SERVER_TOOLS, array_keys($result['tools']));
        self::assertContains('novamira/stand-in-tool', $result['manager_discovery']);
        self::assertSame([], $result['notices'], implode("\n", $result['notices']));
    }

    /**
     * A component of the unprefixed adapter whose layout Novamira does not know counts as running a Novamira
     * Ability unless it reports another plugin's Ability or a callable or builder source, and inspecting it
     * raises no PHP warning.
     */
    public function testUnknownComponentLayoutsFailClosedWithoutWarnings(): void
    {
        $root = dirname(__DIR__, levels: 2);
        $process = proc_open(
            [PHP_BINARY, $root . '/tests/fixtures/stock-mcp-component-runner.php', $root],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), "stdout:\n{$stdout}\nstderr:\n{$stderr}");

        self::assertSame(
            [
                'runs_novamira' => [
                    'tool reporting a Novamira Ability' => true,
                    "tool reporting another plugin's Ability" => false,
                    'tool reporting an Ability without its name' => true,
                    'tool built from a callable' => false,
                    'tool reporting no source' => true,
                    'tool reporting an unknown source' => true,
                    'resource built from a callable, with a renamed field' => false,
                    'resource reporting no source, with a renamed field' => true,
                    'prompt built from a builder' => false,
                    'prompt holding something other than an Ability' => true,
                ],
                'warnings' => [],
            ],
            json_decode($stdout, associative: true),
        );
    }

    /** @return array<string, mixed> */
    private function runPolicyScenario(string $scenario): array
    {
        $root = dirname(__DIR__, levels: 2);
        $command = [PHP_BINARY, $root . '/tests/fixtures/stock-mcp-adapter-policy-runner.php', $root, $scenario];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), "stdout:\n{$stdout}\nstderr:\n{$stderr}");
        self::assertSame('', $stderr, $stderr);

        $decoded = json_decode($stdout, associative: true);
        self::assertIsArray($decoded, $stdout);

        return $decoded;
    }

    private function runBootScenario(string $scenario): array
    {
        $root = dirname(__DIR__, levels: 2);
        $command = [PHP_BINARY, $root . '/tests/fixtures/mcp-adapter-boot-runner.php', $root, $scenario];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit_code = proc_close($process);

        self::assertSame(0, $exit_code, "stdout:\n{$stdout}\nstderr:\n{$stderr}");
        self::assertSame('', $stderr, $stderr);

        $decoded = json_decode($stdout, associative: true);
        self::assertIsArray($decoded, $stdout);

        return $decoded;
    }
}
