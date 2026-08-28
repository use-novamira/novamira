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

    /** @return array<string, mixed> */
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
