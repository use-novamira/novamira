<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * WordPress 6.9's WP_Ability::execute() has no veto filter and does not check that the Ability is still
 * registered, so an Ability object kept by other code (for example another MCP adapter) before the
 * read-mode sweep is gated only by its own permission callback. Read mode guards the permission callback
 * of every Ability registered through the registry, and PHP execution and WP-CLI refuse in their execute
 * callbacks too. WordPress 7.1 adds filters that can override a permission result and short-circuit an
 * execution: read mode keeps the last word on the first and uses the second as a veto (the -wp71 modes).
 */
final class ReadModeRetainedAbilityTest extends TestCase
{
    /** @var array<string, array<string, mixed>> */
    private static array $results = [];

    public function testKeptMutatingAbilitiesAreRefusedWithoutSideEffects(): void
    {
        $result = self::scenarioResult('on');

        self::assertSame(
            [
                'novamira/read-file',
                'novamira-mcp-adapter/discover-abilities',
                'novamira-mcp-adapter/get-ability-info',
                'novamira-mcp-adapter/execute-ability',
                'novamira/get-options',
                'mcp-adapter/discover-abilities',
                'mcp-adapter/get-ability-info',
                'mcp-adapter/execute-ability',
            ],
            $result['registered_after_sweep'],
        );
        foreach (['execute_php', 'write_file', 'third_party', 'third_party_widened'] as $call) {
            self::assertSame('ability_invalid_permissions', $result[$call]['error'], $call);
        }
        // false rather than a WP_Error, which WP_Ability::execute() would report with _doing_it_wrong().
        foreach ([
            'execute_php_permission',
            'write_file_permission',
            'run_wp_cli_permission',
            'third_party_permission',
        ] as $call) {
            self::assertFalse($result[$call], $call);
        }
        // execute-ability is allowed in read mode; its own permission refuses the target with the read-mode
        // wording, which the MCP adapter passes on to the agent.
        self::assertSame('novamira_read_mode', $result['execute_ability_permission']['error']);
        self::assertStringContainsString("'novamira/execute-php'", $result['execute_ability_permission']['message']);
        // The only notice is WordPress refusing Novamira's list-directory after the impostor took the name.
        self::assertSame($result['doing_it_wrong_registration'], $result['doing_it_wrong']);
        self::assertFalse($result['file_written']);
        self::assertSame([], $result['side_effects']);
        // The guard refuses before the wrapped third-party callback runs. That callback is the one a later
        // plugin's PHP_INT_MAX filter put in place: the guard filter moved after it when Abilities began
        // registering, so it wrapped the replacement instead of being replaced.
        self::assertSame(0, $result['competitor_calls']);
        self::assertSame(0, $result['third_party_permission_calls']);
    }

    /**
     * The built-ins of an unprefixed MCP Adapter another plugin bundles, registered through that adapter's
     * own classes, stay registered in read mode so its default server finds them, and are refused: no
     * write Ability, Novamira's or another plugin's, is reached through execute-ability or the others.
     */
    public function testUpstreamInfrastructureStaysRegisteredButCannotExecute(): void
    {
        foreach (['on', 'on-wp71'] as $mode) {
            $result = self::scenarioResult($mode);
            self::assertCount(3, $result['upstream']);
            foreach ($result['upstream'] as $name => $ability) {
                $label = $mode . ': ' . $name;
                self::assertTrue($ability['registered'], $label);
                self::assertTrue($ability['retained'], $label);
                self::assertTrue($ability['guarded'], $label);
                self::assertTrue($ability['bound'], $label);
                self::assertFalse($ability['permitted'], $label);
                self::assertCount(3, $ability['calls'], $label);
                foreach ($ability['calls'] as $target => $call) {
                    self::assertFalse($call['permission'], $label . ' -> ' . $target);
                    self::assertSame(
                        $mode === 'on' ? 'ability_invalid_permissions' : 'novamira_read_mode',
                        $call['execute']['error'],
                        $label . ' -> ' . $target,
                    );
                }
            }
            self::assertNotContains('acme/write-thing', $result['registered_after_sweep']);
            self::assertFalse($result['file_written']);
            self::assertSame(0, $result['third_party_permission_calls']);
        }
    }

    /**
     * Anything else registered under those names is removed in read mode, whatever it looks like: another
     * plugin's own exact WP_Ability with a properly bound guard, a custom class, a missing or foreign-bound
     * guard, or the adapter's own registration whose execute callback a later filter replaced.
     */
    public function testUpstreamImpostorsAreUnregisteredAndCannotExecuteThroughTheRegistry(): void
    {
        foreach (['on', 'on-wp71'] as $mode) {
            $result = self::scenarioResult($mode);
            self::assertCount(3, $result['upstream_impostors']);
            foreach ($result['upstream_impostors'] as $name => $variants) {
                self::assertSame(
                    ['foreign-callbacks', 'permissive-class', 'own-execute-class', 'missing-guard', 'foreign-bound-guard', 'swapped-execute'],
                    array_keys($variants),
                    $name,
                );
                foreach ($variants as $variant => $impostor) {
                    self::assertFalse($impostor['registered'], $mode . ': ' . $name . ': ' . $variant);
                    self::assertNull($impostor['execute'], $mode . ': ' . $name . ': ' . $variant);
                }
            }
            self::assertNotContains('upstream-impostor', $result['side_effects']);
            self::assertSame($mode === 'on' ? [] : ['own-execute'], $result['side_effects']);
        }
    }

    public function testPhpAndWpCliExecuteCallbacksRefuseWhenCalledDirectly(): void
    {
        $result = self::scenarioResult('on');

        foreach (['direct_execute_php', 'direct_run_wp_cli'] as $call) {
            self::assertSame('novamira_read_mode', $result[$call]['error'], $call);
            self::assertStringStartsWith('Read mode is active on this site', $result[$call]['message'], $call);
        }
        self::assertNotContains('direct-execute-php', $result['side_effects']);
    }

    public function testAllowlistedAbilitiesStillExecuteInReadMode(): void
    {
        $result = self::scenarioResult('on');

        self::assertSame(['content' => 'readable content'], $result['read_file']);
        self::assertTrue($result['execute_ability_target_allowed']);
    }

    public function testTheAllowlistCannotBeWidened(): void
    {
        // A plugin adding acme/report to novamira_read_mode_allowed_abilities changes nothing.
        self::assertSame('ability_invalid_permissions', self::scenarioResult('on')['third_party_widened']['error']);
        self::assertSame('reported', self::scenarioResult('off')['third_party_widened']);
    }

    /**
     * Read mode binds the allowlist to Novamira's implementation, not to the name: an impostor registered
     * first, a re-registration, a swapped execute callback, a replaced guard, a custom ability_class, and
     * a squatter in Novamira's namespace are all refused, whether reached through the registry or kept.
     */
    public function testImpostorsUnderAllowlistedNamesAreRefused(): void
    {
        $result = self::scenarioResult('on');

        foreach (['impostor_first', 're_registered', 'swapped_execute', 'custom_class', 'namespace_squatter'] as $call) {
            self::assertSame('ability_invalid_permissions', $result[$call]['error'], $call);
        }
        foreach ([
            'novamira/list-directory',
            'novamira/get-object',
            'novamira/query-objects',
            'novamira/list-options',
            'novamira/describe-schema',
            'novamira/acme-helper',
        ] as $name) {
            self::assertNotContains($name, $result['registered_after_sweep'], $name);
        }
        self::assertSame([], $result['side_effects']);
        // WordPress refused Novamira's own list-directory: the first registrant is not trusted for it.
        self::assertSame(['Ability "novamira/list-directory" is already registered.'], $result['doing_it_wrong_registration']);

        $off = self::scenarioResult('off');
        self::assertSame('foreign impostor-first', $off['impostor_first']);
        self::assertSame('foreign swapped-execute', $off['swapped_execute']);
    }

    public function testEveryRegisteredAbilityIsGuarded(): void
    {
        $expected = [
            'novamira/execute-php' => true,
            'novamira/write-file' => true,
            'novamira/read-file' => true,
            'novamira/run-wp-cli' => true,
            'novamira-mcp-adapter/execute-ability' => true,
            'acme/write-thing' => true,
            'acme/report' => true,
        ];
        self::assertSame($expected, self::scenarioResult('on')['guarded']);
        self::assertSame($expected, self::scenarioResult('off')['guarded']);
    }

    public function testReadModeOffBehavesExactlyAsWithoutTheGuard(): void
    {
        $guarded = self::scenarioResult('off');
        $baseline = self::scenarioResult('off-unguarded');

        self::assertSame(['return_value' => 42], $guarded['execute_php']);
        self::assertTrue($guarded['execute_php_permission']);
        self::assertSame('written', $guarded['third_party']);
        self::assertTrue($guarded['third_party_permission']);
        self::assertSame('reported', $guarded['third_party_widened']);
        self::assertTrue($guarded['file_written']);
        self::assertSame(
            [
                'execute-php',
                'acme/write-thing',
                'acme/report',
                'impostor-first',
                're-registered',
                'swapped-execute',
                'custom-class',
                'namespace-squatter',
                'direct-execute-php',
            ],
            $guarded['side_effects'],
        );
        // Outside read mode the later plugin's replacement answers, as it would without the guard.
        self::assertSame(2, $guarded['competitor_calls']);
        self::assertSame(0, $guarded['third_party_permission_calls']);
        self::assertSame($guarded['doing_it_wrong_registration'], $guarded['doing_it_wrong']);
        self::assertSame(array_fill_keys(array_keys($guarded['guarded']), false), $baseline['guarded']);

        unset($guarded['guarded'], $baseline['guarded']);
        self::assertSame($baseline, $guarded);
    }

    /**
     * WordPress 6.9 applies no filter around a kept object's permission callback, so an object whose
     * guard a late wp_register_ability_args filter replaced answers with the replacement. Documented.
     */
    public function testWordPress69KeptObjectWithReplacedGuardAnswersWithTheReplacement(): void
    {
        self::assertTrue(self::scenarioResult('on')['replaced_guard_permission']);
    }

    /**
     * WordPress 7.1 lets wp_ability_permission_result filters override the permission callback, and a
     * well-meaning filter that elevates permissions would turn the guard's false into true. Read mode's
     * own filter has the last word (it moves after a plugin's PHP_INT_MAX filter when Abilities start
     * registering), and wp_pre_execute_ability refuses before WordPress checks permissions at all.
     */
    public function testWordPress71PermissionFiltersCannotLetARefusedAbilityRun(): void
    {
        $result = self::scenarioResult('on-wp71');

        foreach (['write_file', 'third_party', 'third_party_widened', 'execute_php'] as $call) {
            self::assertSame('novamira_read_mode', $result[$call]['error'], $call);
        }
        foreach (['write_file_permission', 'execute_php_permission', 'run_wp_cli_permission', 'third_party_permission'] as $call) {
            self::assertFalse($result[$call], $call);
        }
        self::assertFalse($result['file_written']);
        // The elevation filters did run, and read mode overrode them.
        self::assertGreaterThan(0, $result['wp71']['elevations']);
        self::assertFalse($result['wp71']['needs_elevation_permission']);
        self::assertSame('novamira_read_mode', $result['wp71']['needs_elevation']['error']);

        // On 7.1 the residuals documented for 6.9 close: a kept object with a replaced guard, and a class
        // that overrides check_permissions(), are refused.
        self::assertFalse($result['replaced_guard_permission']);
        self::assertSame('novamira_read_mode', $result['wp71']['replaced_guard_execute']['error']);
        self::assertSame('novamira_read_mode', $result['wp71']['permissive_class']['error']);
        self::assertSame('novamira_read_mode', $result['wp71']['outside_registry']['error']);
        // A class that overrides execute() itself bypasses every WordPress filter: still documented.
        self::assertSame('executed on its own', $result['wp71']['own_execute']);
        self::assertSame(['own-execute'], $result['side_effects']);

        // Allowlisted Abilities keep their results: the read still runs, and a denial stays a denial.
        self::assertSame(['content' => 'readable content'], $result['read_file']);
        self::assertFalse($result['wp71']['get_options_denied']);
        // No notices beyond WordPress refusing the duplicate list-directory registration.
        self::assertSame($result['doing_it_wrong_registration'], $result['doing_it_wrong']);
    }

    public function testWordPress71ReadModeOffBehavesExactlyAsWithoutTheGuard(): void
    {
        $guarded = self::scenarioResult('off-wp71');
        $baseline = self::scenarioResult('off-wp71-unguarded');

        self::assertSame('foreign needs-elevation', $guarded['wp71']['needs_elevation']);
        self::assertSame('foreign outside-registry', $guarded['wp71']['outside_registry']);
        self::assertTrue($guarded['file_written']);
        unset($guarded['guarded'], $baseline['guarded']);
        self::assertSame($baseline, $guarded);
    }

    /** @return array<string, mixed> */
    private static function scenarioResult(string $mode): array
    {
        if (isset(self::$results[$mode])) {
            return self::$results[$mode];
        }

        $root = dirname(__DIR__, levels: 2);
        $command = [PHP_BINARY, $root . '/tests/fixtures/read-mode-retained-runner.php', $root, $mode];
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

        return self::$results[$mode] = $decoded;
    }
}
