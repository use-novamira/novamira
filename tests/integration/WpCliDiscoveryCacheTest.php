<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * WP-CLI discovery runs on every request that registers abilities (every REST and MCP request),
 * and discovery launches processes. These tests put a logging `which` first on the PATH, so every
 * discovery launch leaves a line in a log file, and assert when discovery may and may not run.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class WpCliDiscoveryCacheTest extends TestCase
{
    private string $tools = '';

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('The logging `which` wrapper is a POSIX shell script.');
        }

        require_once __DIR__ . '/fixtures/wp-cli-discovery-stubs.php';
        require_once __DIR__ . '/../../includes/abilities/bootstrap.php';
        require_once __DIR__ . '/../../includes/abilities/run-wp-cli.php';

        $this->tools = self::createTools();
        putenv('PATH=' . $this->tools);
    }

    protected function tearDown(): void
    {
        if ($this->tools !== '') {
            self::removeTools($this->tools);
        }
    }

    public function testLaterRequestsReuseTheDiscoveryWithoutLaunchingProcesses(): void
    {
        $store = $this->tools . '/transients.ser';

        $first = self::request($this->tools, $store);
        self::assertSame(['available' => true, 'command' => [$this->tools . '/wp']], $first);
        self::assertSame(1, self::launches($this->tools));

        // A separate PHP process shares nothing with the first but the transient store.
        $second = self::request($this->tools, $store);
        self::assertSame($first, $second);
        self::assertSame(1, self::launches($this->tools));

        $stored = unserialize((string) file_get_contents($store));
        self::assertIsArray($stored);
        self::assertCount(1, $stored);
        $entry = array_values($stored)[0];
        self::assertSame(['command' => [$this->tools . '/wp']], $entry['value']);
        self::assertSame(DAY_IN_SECONDS, $entry['expiration']);
    }

    public function testLaterRequestsReuseANotFoundResultWithoutLaunchingProcesses(): void
    {
        self::skipIfWpCliIsInstalledSystemWide();
        $this->removeWp();
        $store = $this->tools . '/transients.ser';

        self::assertSame(['available' => false, 'command' => null], self::request($this->tools, $store));
        self::assertSame(1, self::launches($this->tools));
        self::assertSame(['available' => false, 'command' => null], self::request($this->tools, $store));
        self::assertSame(1, self::launches($this->tools));
    }

    public function testNotFoundIsCachedForAShorterTime(): void
    {
        self::skipIfWpCliIsInstalledSystemWide();
        $this->removeWp();

        self::assertNull(novamira_find_wp_cli_command());
        self::assertFalse(novamira_wp_cli_status()['available']);
        self::assertSame(1, self::launches($this->tools));

        $stored = $GLOBALS['novamira_test_transients'][novamira_wp_cli_discovery_transient()];
        self::assertNull($stored['value']['command']);
        self::assertSame(15 * MINUTE_IN_SECONDS, $stored['expiration']);
        self::assertLessThan(DAY_IN_SECONDS, $stored['expiration']);
    }

    public function testChangedPathDiscoversAgain(): void
    {
        self::assertSame([$this->tools . '/wp'], novamira_find_wp_cli_command());

        $other = self::createTools();
        try {
            putenv('PATH=' . $other);
            self::assertSame([$other . '/wp'], novamira_find_wp_cli_command());
            self::assertSame(1, self::launches($other));

            // Each environment keeps its own entry, so switching back launches nothing.
            putenv('PATH=' . $this->tools);
            self::assertSame([$this->tools . '/wp'], novamira_find_wp_cli_command());
            self::assertSame(1, self::launches($this->tools));
        } finally {
            self::removeTools($other);
        }
    }

    public function testWorkingDirectoryChangeDiscoversAgainWhenPathHasARelativeEntry(): void
    {
        putenv('PATH=' . $this->tools . ':bin');
        chdir($this->tools);
        novamira_find_wp_cli_command();
        novamira_find_wp_cli_command();
        self::assertSame(1, self::launches($this->tools));
        self::assertSame(getcwd(), novamira_wp_cli_discovery_inputs()['cwd']);

        chdir(sys_get_temp_dir());
        novamira_find_wp_cli_command();
        self::assertSame(2, self::launches($this->tools));
    }

    public function testWorkingDirectoryIsIgnoredWhenPathIsAbsolute(): void
    {
        chdir($this->tools);
        novamira_find_wp_cli_command();
        self::assertNull(novamira_wp_cli_discovery_inputs()['cwd']);

        // Another entry script, another working directory: the same entry still applies.
        chdir(sys_get_temp_dir());
        novamira_find_wp_cli_command();
        self::assertSame(1, self::launches($this->tools));
    }

    public function testWorkingDirectoryDependsOnPlatformAndPath(): void
    {
        self::assertTrue(novamira_wp_cli_discovery_depends_on_cwd(windows: true, path: 'C:\\Windows'));
        self::assertFalse(novamira_wp_cli_discovery_depends_on_cwd(windows: false, path: '/usr/local/bin:/usr/bin'));
        self::assertTrue(novamira_wp_cli_discovery_depends_on_cwd(windows: false, path: '/usr/bin:bin'));
        self::assertTrue(novamira_wp_cli_discovery_depends_on_cwd(windows: false, path: '/usr/bin:./vendor/bin'));
        // An empty entry, leading, trailing or doubled, is the working directory to a POSIX shell.
        self::assertTrue(novamira_wp_cli_discovery_depends_on_cwd(windows: false, path: '/usr/bin:'));
        self::assertTrue(novamira_wp_cli_discovery_depends_on_cwd(windows: false, path: ':/usr/bin'));
        self::assertTrue(novamira_wp_cli_discovery_depends_on_cwd(windows: false, path: '/usr/bin::/bin'));
        self::assertTrue(novamira_wp_cli_discovery_depends_on_cwd(windows: false, path: ''));
    }

    public function testChangedPathextDiscoversAgain(): void
    {
        putenv('PATHEXT=.COM;.EXE');
        novamira_find_wp_cli_command();
        novamira_find_wp_cli_command();
        self::assertSame(1, self::launches($this->tools));

        putenv('PATHEXT=.COM;.EXE;.BAT');
        novamira_find_wp_cli_command();
        self::assertSame(2, self::launches($this->tools));
    }

    public function testHostIdentityIsPartOfTheCacheKey(): void
    {
        // The host name cannot be changed from inside a test, so the key inputs are checked
        // instead: nodes that share a database or object cache must not share an entry.
        $inputs = novamira_wp_cli_discovery_inputs();
        self::assertSame(gethostname(), $inputs['host']);
        self::assertSame(php_uname('n'), $inputs['node']);
        self::assertArrayHasKey('PATHEXT', $inputs['env']);
        self::assertStringEndsWith(
            md5(serialize($inputs)),
            novamira_wp_cli_discovery_transient(),
        );
    }

    public function testNonExecutableCachedProgramIsDiscoveredAgain(): void
    {
        novamira_find_wp_cli_command();
        self::assertSame(1, self::launches($this->tools));

        chmod($this->tools . '/wp', 0o644);
        clearstatcache();
        self::assertNull(novamira_find_wp_cli_command());
        self::assertSame(2, self::launches($this->tools));
    }

    public function testUnusableFreshResultIsCachedAsNotFound(): void
    {
        // `which` names a file that cannot be started; nothing could run it, so it is "not found"
        // and later requests do not search again.
        chmod($this->tools . '/wp', 0o644);
        $store = $this->tools . '/transients.ser';

        self::assertSame(['available' => false, 'command' => null], self::request($this->tools, $store));
        self::assertSame(['available' => false, 'command' => null], self::request($this->tools, $store));
        self::assertSame(1, self::launches($this->tools));

        $entry = array_values((array) unserialize((string) file_get_contents($store)))[0];
        self::assertSame(['command' => null], $entry['value']);
        self::assertSame(15 * MINUTE_IN_SECONDS, $entry['expiration']);

        // The run path applies the same checks and reports WP-CLI as missing.
        self::assertNull(novamira_find_fresh_wp_cli_command());
    }

    public function testRelativeResultIsMadeAbsoluteAndCached(): void
    {
        // `which` reports a relative path for a relative PATH entry. It names a file in the
        // directory discovery ran in, which a run starting from ABSPATH would not find.
        $this->useRelativeWhich();
        $store = $this->tools . '/transients.ser';
        $path = $this->tools . ':bin';

        $expected = ['available' => true, 'command' => [$this->tools . '/bin/wp']];
        self::assertSame($expected, self::request($this->tools, $store, $path));
        self::assertSame(1, self::launches($this->tools));
        self::assertSame($expected, self::request($this->tools, $store, $path));
        self::assertSame(1, self::launches($this->tools));

        // The run path resolves it the same way.
        putenv('PATH=' . $path);
        chdir($this->tools);
        self::assertSame([$this->tools . '/bin/wp'], novamira_find_fresh_wp_cli_command());
    }

    public function testRelativeEntryInTheStoreIsNotTrusted(): void
    {
        $this->useRelativeWhich();
        putenv('PATH=' . $this->tools . ':bin');
        chdir($this->tools);

        set_transient(novamira_wp_cli_discovery_transient(), ['command' => ['bin/wp']], DAY_IN_SECONDS);
        self::assertSame([$this->tools . '/bin/wp'], novamira_find_wp_cli_command());
        self::assertSame(1, self::launches($this->tools));
    }

    public function testDisabledLookupFunctionsDoNotStopTheRequest(): void
    {
        // disable_functions removes these outright in PHP 8, so an unguarded call is a fatal
        // error during ability registration.
        $disabled = ['-d', 'disable_functions=gethostname,php_uname,getcwd,php_ini_loaded_file,ini_get'];
        $store = $this->tools . '/transients.ser';

        $expected = ['available' => true, 'command' => [$this->tools . '/wp']];
        self::assertSame($expected, self::request($this->tools, $store, php_options: $disabled));
        self::assertSame($expected, self::request($this->tools, $store, php_options: $disabled));
        self::assertSame(1, self::launches($this->tools));

        // Without getcwd() a relative result cannot be placed, so it is "not found".
        $this->useRelativeWhich();
        $expected = ['available' => false, 'command' => null];
        $relative_store = $this->tools . '/relative.ser';
        $path = $this->tools . ':bin';
        self::assertSame($expected, self::request($this->tools, $relative_store, $path, $disabled));
        self::assertSame($expected, self::request($this->tools, $relative_store, $path, $disabled));
        self::assertSame(2, self::launches($this->tools));
    }

    public function testAbsolutizingKeepsTheTextualPath(): void
    {
        self::assertSame(
            ['/srv/site/bin/wp'],
            novamira_absolutize_wp_cli_command(['bin/wp'], '/srv/site', windows: false),
        );
        self::assertSame(
            ['/srv/site/./vendor/bin/wp'],
            novamira_absolutize_wp_cli_command(['./vendor/bin/wp'], '/srv/site/', windows: false),
        );
        self::assertSame(['/bin/wp'], novamira_absolutize_wp_cli_command(['bin/wp'], '/', windows: false));
        self::assertSame(
            ['/usr/local/bin/wp'],
            novamira_absolutize_wp_cli_command(['/usr/local/bin/wp'], null, windows: false),
        );
        self::assertNull(novamira_absolutize_wp_cli_command(['bin/wp'], null, windows: false));

        // Only the program and the entry point are paths; "-c" and the php.ini stay as they are.
        self::assertSame(
            ['C:\\site\\php\\php.exe', '-c', 'php.ini', 'C:\\tools\\wp-cli.phar'],
            novamira_absolutize_wp_cli_command(
                ['php\\php.exe', '-c', 'php.ini', 'C:\\tools\\wp-cli.phar'],
                'C:\\site',
                windows: true,
            ),
        );
        self::assertSame(
            ['C:\\wp.exe'],
            novamira_absolutize_wp_cli_command(['wp.exe'], 'C:\\', windows: true),
        );
        // Drive-relative and root-relative paths depend on state that is not visible here.
        self::assertNull(novamira_absolutize_wp_cli_command(['C:wp.exe'], 'D:\\site', windows: true));
        self::assertNull(novamira_absolutize_wp_cli_command(['\\tools\\wp.exe'], 'D:\\site', windows: true));
    }

    public function testReusableInvocationShapes(): void
    {
        $php = $this->tools . '/php.exe';
        $phar = $this->tools . '/wp-cli.phar';
        file_put_contents($php, '');
        file_put_contents($phar, '');
        chmod($php, 0o644);

        // Windows has no execute bit: php.exe -c php.ini wp-cli.phar needs two existing files. A
        // leading "//" makes these temporary POSIX paths read as UNC paths, which Windows rules
        // accept as absolute, while the filesystem still resolves them.
        $unc_php = '/' . $php;
        $unc_phar = '/' . $phar;
        self::assertTrue(novamira_wp_cli_command_is_reusable([$unc_php, '-c', 'php.ini', $unc_phar], windows: true));
        self::assertFalse(novamira_wp_cli_command_is_reusable(
            [$unc_php, '-c', 'php.ini', $unc_phar . '.gone'],
            windows: true,
        ));
        self::assertFalse(novamira_wp_cli_command_is_reusable([$php, '-c', 'php.ini', $phar], windows: true));
        // Elsewhere the program must be executable as well.
        self::assertFalse(novamira_wp_cli_command_is_reusable([$php, $phar], windows: false));
        self::assertTrue(novamira_wp_cli_command_is_reusable([$this->tools . '/wp'], windows: false));
        self::assertFalse(novamira_wp_cli_command_is_reusable(['wp'], windows: false));

        foreach (['C:/php/php.exe', 'C:\\php\\php.exe', '\\\\server\\share\\wp.exe', '//server/share/wp.exe'] as $path) {
            self::assertTrue(novamira_is_absolute_windows_path($path), $path);
        }
        foreach (['php.exe', 'bin\\wp.exe', 'C:php.exe', '\\php\\php.exe', '/php/php.exe', ''] as $path) {
            self::assertFalse(novamira_is_absolute_windows_path($path), $path);
        }
        self::assertFalse(novamira_wp_cli_command_is_reusable(['C:/php/php.exe'], windows: false));
    }

    public function testCachedInvocationWhoseFilesAreGoneIsDiscoveredAgain(): void
    {
        novamira_find_wp_cli_command();
        self::assertSame(1, self::launches($this->tools));

        unlink($this->tools . '/wp');
        clearstatcache();
        novamira_find_wp_cli_command();
        self::assertSame(2, self::launches($this->tools));
    }

    public function testRunDiscoversAgainInsteadOfTrustingTheCache(): void
    {
        // A cached invocation that still exists on disk but is no longer the WP-CLI to run.
        $stale = $this->tools . '/stale-wp';
        file_put_contents($stale, "#!/bin/sh\nexit 3\n");
        chmod($stale, 0o755);
        set_transient(novamira_wp_cli_discovery_transient(), ['command' => [$stale]], DAY_IN_SECONDS);

        self::assertTrue(novamira_wp_cli_status()['available']);
        self::assertSame(0, self::launches($this->tools));

        $result = novamira_run_wp_cli(['args' => ['cli', 'info']]);

        self::assertIsArray($result);
        self::assertTrue($result['success']);
        self::assertStringContainsString('fake-wp', $result['stdout']);
        self::assertStringContainsString('cli info', $result['stdout']);
        self::assertSame(1, self::launches($this->tools));
        self::assertSame(
            [$this->tools . '/wp'],
            $GLOBALS['novamira_test_transients'][novamira_wp_cli_discovery_transient()]['value']['command'],
        );
    }

    public function testConstantSkipsDiscoveryAndTheCache(): void
    {
        // A real executable, so the constant also passes the filesystem validation it gets on every request.
        $configured = [$this->tools . '/wp', '--path=/srv/site'];
        define('NOVAMIRA_WP_CLI_COMMAND', $configured);

        self::assertNull(novamira_wp_cli_constant_problem());
        self::assertSame($configured, novamira_find_wp_cli_command());
        self::assertSame($configured, novamira_find_fresh_wp_cli_command());
        self::assertTrue(novamira_wp_cli_status()['available']);
        self::assertSame(0, self::launches($this->tools));
        self::assertSame([], $GLOBALS['novamira_test_transient_reads'] ?? []);
        self::assertSame([], $GLOBALS['novamira_test_transients'] ?? []);
    }

    public function testInvalidConstantIsReportedWithoutDiscoveryOrTheCache(): void
    {
        // The `which` on the PATH would find a working WP-CLI; an invalid constant must not fall back to it.
        $missing = $this->tools . '/missing-wp';
        define('NOVAMIRA_WP_CLI_COMMAND', $missing);

        self::assertNull(novamira_find_wp_cli_command());
        self::assertNull(novamira_find_fresh_wp_cli_command());
        $status = novamira_wp_cli_status();
        self::assertFalse($status['available']);
        self::assertStringContainsString('NOVAMIRA_WP_CLI_COMMAND', $status['reason']);
        self::assertStringContainsString($missing, $status['reason']);

        $run = novamira_run_wp_cli(['args' => ['cli', 'info']]);
        self::assertInstanceOf(WP_Error::class, $run);
        self::assertStringContainsString($missing, $run->get_error_message());

        self::assertSame(0, self::launches($this->tools));
        self::assertSame([], $GLOBALS['novamira_test_transient_reads'] ?? []);
        self::assertSame([], $GLOBALS['novamira_test_transients'] ?? []);
    }

    public function testDiscoveredPathOutsideOpenBasedirStaysFoundAndRuns(): void
    {
        // open_basedir limits what PHP may inspect, not what a process may start.
        $outside = self::createTools();
        try {
            $this->useWhichReporting($outside . '/wp');
            $store = $this->tools . '/transients.ser';
            $found = ['available' => true, 'command' => [$outside . '/wp']];

            self::assertSame($found, self::requestUnderOpenBasedir($this->tools, $store, $this->tools));
            self::assertSame(1, self::launches($this->tools));

            $entry = array_values((array) unserialize((string) file_get_contents($store)))[0];
            self::assertSame(['command' => [$outside . '/wp']], $entry['value']);
            self::assertSame(DAY_IN_SECONDS, $entry['expiration']);

            // A separate process reuses the entry, silently and without launching anything.
            self::assertSame($found, self::requestUnderOpenBasedir($this->tools, $store, $this->tools));
            self::assertSame(1, self::launches($this->tools));

            // A run searches again and really starts the program.
            $run = self::requestUnderOpenBasedir($this->tools, $store, $this->tools, mode: 'run');
            self::assertTrue($run['success']);
            self::assertStringContainsString('fake-wp', $run['stdout']);
            self::assertStringContainsString('cli info', $run['stdout']);
            self::assertSame(2, self::launches($this->tools));
        } finally {
            self::removeTools($outside);
        }
    }

    public function testStaleCachedPathOutsideOpenBasedirOnlyAffectsListing(): void
    {
        // PHP cannot see that this file is gone, so the entry is accepted; the run finds the real one.
        $outside = self::createTools();
        try {
            $this->useWhichReporting($outside . '/wp');
            $store = $this->tools . '/transients.ser';
            $gone = $outside . '/gone-wp';

            self::assertSame(
                ['available' => true, 'command' => [$gone]],
                self::requestUnderOpenBasedir($this->tools, $store, $this->tools, seed: $gone),
            );
            self::assertSame(0, self::launches($this->tools));

            $run = self::requestUnderOpenBasedir($this->tools, $store, $this->tools, mode: 'run');
            self::assertTrue($run['success']);
            self::assertStringContainsString('fake-wp', $run['stdout']);
            self::assertSame(1, self::launches($this->tools));

            $entry = array_values((array) unserialize((string) file_get_contents($store)))[0];
            self::assertSame(['command' => [$outside . '/wp']], $entry['value']);
        } finally {
            self::removeTools($outside);
        }
    }

    public function testConfirmedMissingCachedPathInsideOpenBasedirIsDiscoveredAgain(): void
    {
        $store = $this->tools . '/transients.ser';

        self::assertSame(
            ['available' => true, 'command' => [$this->tools . '/wp']],
            self::requestUnderOpenBasedir($this->tools, $store, $this->tools, seed: $this->tools . '/gone-wp'),
        );
        self::assertSame(1, self::launches($this->tools));
    }

    public function testNonExecutableResultInsideOpenBasedirIsStillRejected(): void
    {
        chmod($this->tools . '/wp', 0o644);
        $store = $this->tools . '/transients.ser';
        $not_found = ['available' => false, 'command' => null];

        self::assertSame($not_found, self::requestUnderOpenBasedir($this->tools, $store, $this->tools));
        self::assertSame($not_found, self::requestUnderOpenBasedir($this->tools, $store, $this->tools));
        self::assertSame(1, self::launches($this->tools));
    }

    public function testRelativeCachedPathIsNotTrustedUnderOpenBasedir(): void
    {
        // Only absolute paths may skip inspection; this one must not be accepted as refused.
        $store = $this->tools . '/transients.ser';

        self::assertSame(
            ['available' => true, 'command' => [$this->tools . '/wp']],
            self::requestUnderOpenBasedir($this->tools, $store, $this->tools, seed: '../outside/wp'),
        );
        self::assertSame(1, self::launches($this->tools));
    }

    public function testDiscoveredPathOutsideOpenBasedirWorksWithoutIniGet(): void
    {
        // Without ini_get() the restriction cannot be read, and the warning must still be swallowed.
        $outside = self::createTools();
        try {
            $this->useWhichReporting($outside . '/wp');
            $store = $this->tools . '/transients.ser';
            $found = ['available' => true, 'command' => [$outside . '/wp']];
            $disabled = ['-d', 'disable_functions=ini_get'];

            self::assertSame($found, self::requestUnderOpenBasedir(
                $this->tools,
                $store,
                $this->tools,
                php_options: $disabled,
            ));
            self::assertSame($found, self::requestUnderOpenBasedir(
                $this->tools,
                $store,
                $this->tools,
                php_options: $disabled,
            ));
            self::assertSame(1, self::launches($this->tools));
        } finally {
            self::removeTools($outside);
        }
    }

    public function testWindowsDiscoveryKeepsTreatingARefusedWhereResultAsAbsent(): void
    {
        // The Windows search has always checked what `where` reports before using it. A stub `where`
        // stands in for the real one, since this branch only reads the lines it prints.
        $outside = self::createTools();
        try {
            file_put_contents($outside . '/wp.exe', '');
            file_put_contents($this->tools . '/wp.exe', '');
            file_put_contents($this->tools . '/where', "#!/bin/sh\n[ \"\$1\" = wp ] && echo '{$outside}/wp.exe'\nexit 0\n");
            chmod($this->tools . '/where', 0o755);
            $store = $this->tools . '/transients.ser';

            self::assertSame(
                ['command' => null],
                self::requestUnderOpenBasedir($this->tools, $store, $this->tools, mode: 'windows'),
            );

            file_put_contents($this->tools . '/where', "#!/bin/sh\n[ \"\$1\" = wp ] && echo '{$this->tools}/wp.exe'\nexit 0\n");
            self::assertSame(
                ['command' => [$this->tools . '/wp.exe']],
                self::requestUnderOpenBasedir($this->tools, $store, $this->tools, mode: 'windows'),
            );
        } finally {
            self::removeTools($outside);
        }
    }

    public function testPathInsideOpenBasedirIsStillReused(): void
    {
        $store = $this->tools . '/transients.ser';
        $found = ['available' => true, 'command' => [$this->tools . '/wp']];

        self::assertSame($found, self::requestUnderOpenBasedir($this->tools, $store, $this->tools));
        self::assertSame($found, self::requestUnderOpenBasedir($this->tools, $store, $this->tools));
        self::assertSame(1, self::launches($this->tools));
    }

    public function testChangedOpenBasedirDiscoversAgain(): void
    {
        $store = $this->tools . '/transients.ser';

        self::requestUnderOpenBasedir($this->tools, $store, $this->tools);
        self::requestUnderOpenBasedir($this->tools, $store, $this->tools . PATH_SEPARATOR . sys_get_temp_dir());
        self::assertSame(2, self::launches($this->tools));
        self::assertCount(2, (array) unserialize((string) file_get_contents($store)));
    }

    /**
     * Each function the open_basedir probe installs its warning handler with, disabled on its own.
     *
     * @return array<string, array{string}>
     */
    public static function handlerFunctions(): array
    {
        return [
            'set_error_handler' => ['set_error_handler'],
            'restore_error_handler' => ['restore_error_handler'],
            'str_contains' => ['str_contains'],
        ];
    }

    #[DataProvider('handlerFunctions')]
    public function testFreshDiscoveryUnderOpenBasedirSurvivesADisabledHandlerFunction(string $function): void
    {
        // Calling a function disable_functions removed is a fatal error, so the probe must not install
        // its handler without every function it needs.
        $store = $this->tools . '/transients.ser';
        $disabled = ['-d', 'disable_functions=' . $function];
        $found = ['available' => true, 'command' => [$this->tools . '/wp']];

        self::assertSame($found, self::requestUnderOpenBasedir($this->tools, $store, $this->tools, php_options: $disabled));
        self::assertSame(1, self::launches($this->tools));

        $run = self::requestUnderOpenBasedir($this->tools, $store, $this->tools, mode: 'run', php_options: $disabled);
        self::assertTrue($run['success']);
        self::assertStringContainsString('fake-wp', $run['stdout']);
        self::assertSame(2, self::launches($this->tools));
    }

    #[DataProvider('handlerFunctions')]
    public function testCachedReuseUnderOpenBasedirSurvivesADisabledHandlerFunction(string $function): void
    {
        // Without the handler the probe does not touch the path, so every cached entry is unknown and
        // kept: none is hidden and nothing is launched. Only a run checks for real, and it searches again.
        $disabled = ['-d', 'disable_functions=' . $function];
        $outside = self::createTools();
        try {
            $cases = [
                'valid' => [$this->tools, $this->tools . '/wp'],
                // The PHP binary itself, with every path allowed: open_basedir is still set.
                'binary' => ['/', PHP_BINARY],
                'missing' => [$this->tools, $this->tools . '/gone-wp'],
                'outside' => [$this->tools, $outside . '/wp'],
            ];
            foreach ($cases as $name => [$open_basedir, $seed]) {
                self::assertSame(
                    ['available' => true, 'command' => [$seed]],
                    self::requestUnderOpenBasedir(
                        $this->tools,
                        $this->tools . '/' . $name . '.ser',
                        $open_basedir,
                        seed: $seed,
                        php_options: $disabled,
                    ),
                    $name,
                );
            }
            self::assertSame(0, self::launches($this->tools));
        } finally {
            self::removeTools($outside);
        }
    }

    public function testCachedReuseUnderOpenBasedirSurvivesAllHandlerFunctionsDisabled(): void
    {
        $disabled = ['-d', 'disable_functions=set_error_handler,restore_error_handler,str_contains'];
        foreach ([$this->tools . '/wp', $this->tools . '/gone-wp'] as $index => $seed) {
            self::assertSame(
                ['available' => true, 'command' => [$seed]],
                self::requestUnderOpenBasedir(
                    $this->tools,
                    $this->tools . '/all-' . $index . '.ser',
                    $this->tools,
                    seed: $seed,
                    php_options: $disabled,
                ),
            );
        }
        self::assertSame(0, self::launches($this->tools));
    }

    /**
     * An error handler another plugin installed before the probe, with and without the probe's own handler.
     *
     * @return array<string, array{string, string}>
     */
    public static function installedHandlers(): array
    {
        $cases = [];
        foreach (['echo', 'throw', 'consume'] as $handler) {
            foreach (['', 'restore_error_handler', 'str_contains'] as $function) {
                $cases[$handler . ($function === '' ? ' with the probe handler' : ' without ' . $function)] = [
                    $handler,
                    $function,
                ];
            }
        }

        return $cases;
    }

    #[DataProvider('installedHandlers')]
    public function testInstalledErrorHandlerNeitherSeesNorDecidesARefusal(string $handler, string $function): void
    {
        // A handler that prints a warning would put it into the response, one that throws would end the
        // request, and one that swallows it would turn a refusal into an absence and hide a working
        // program. The probe either catches the refusal with its own handler or does not probe at all.
        $options = $function === '' ? [] : ['-d', 'disable_functions=' . $function];
        clearstatcache();

        // requestUnderOpenBasedir() fails on a non-zero exit, on stderr, and on any open_basedir text.
        self::assertSame(
            ['available' => true, 'command' => [PHP_BINARY]],
            self::requestUnderOpenBasedir(
                $this->tools,
                $this->tools . '/handler.ser',
                $this->tools,
                seed: PHP_BINARY,
                php_options: $options,
                handler: $handler,
            ),
        );
        self::assertSame(0, self::launches($this->tools));
    }

    private function removeWp(): void
    {
        unlink($this->tools . '/wp');
        file_put_contents($this->tools . '/which', "#!/bin/sh\necho \"\$@\" >> '{$this->tools}/launches.log'\nexit 1\n");
    }

    private function useWhichReporting(string $path): void
    {
        file_put_contents($this->tools . '/which', "#!/bin/sh\necho \"\$@\" >> '{$this->tools}/launches.log'\necho '{$path}'\n");
    }

    private function useRelativeWhich(): void
    {
        mkdir($this->tools . '/bin');
        rename($this->tools . '/wp', $this->tools . '/bin/wp');
        file_put_contents($this->tools . '/which', "#!/bin/sh\necho \"\$@\" >> '{$this->tools}/launches.log'\necho bin/wp\n");
    }

    private static function skipIfWpCliIsInstalledSystemWide(): void
    {
        foreach (['/usr/local/bin/wp', '/usr/bin/wp', '/bin/wp', '/usr/local/sbin/wp', '/usr/sbin/wp'] as $path) {
            if (is_file($path)) {
                self::markTestSkipped('WP-CLI is installed at a well-known location on this machine.');
            }
        }
    }

    /**
     * Resolve WP-CLI in a separate PHP process, as a new request would.
     *
     * The process starts in $tools and gets $path (by default just $tools) as its PATH.
     *
     * @param list<string> $php_options Extra PHP command line options, such as -d settings.
     * @return array{available: bool, command: list<string>|null}
     */
    private static function request(string $tools, string $store, string $path = '', array $php_options = []): array
    {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, ...$php_options, __DIR__ . '/fixtures/wp-cli-discovery-request.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $tools,
            ['PATH' => $path === '' ? $tools : $path, 'NOVAMIRA_TEST_TRANSIENT_FILE' => $store],
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stderr);

        /** @var array{available: bool, command: list<string>|null} */
        return json_decode($stdout, associative: true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Resolve WP-CLI in a separate PHP process restricted by open_basedir, with warnings displayed.
     *
     * As in a web response, a warning would land in the output ahead of the JSON, so the output is
     * checked for it before decoding.
     *
     * @param string|null  $seed        Path to store as the cached invocation before resolving.
     * @param string       $mode        cached resolves as a request does, run executes through
     *                                  novamira_run_wp_cli(), windows runs the Windows search.
     * @param list<string> $php_options Extra PHP command line options, such as -d settings.
     * @param string       $handler     Error handler to install before resolving: echo, throw or consume.
     * @return array<string, mixed>
     */
    private static function requestUnderOpenBasedir(
        string $tools,
        string $store,
        string $open_basedir,
        ?string $seed = null,
        string $mode = 'cached',
        array $php_options = [],
        string $handler = '',
    ): array {
        $root = (string) realpath(__DIR__ . '/../..');
        $pipes = [];
        $process = proc_open(
            [
                PHP_BINARY,
                '-d',
                'open_basedir=' . $open_basedir . PATH_SEPARATOR . $root,
                '-d',
                'display_errors=1',
                '-d',
                'error_reporting=' . E_ALL,
                ...$php_options,
                __DIR__ . '/fixtures/wp-cli-discovery-open-basedir-request.php',
                $mode,
                (string) $seed,
                $handler,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $tools,
            ['PATH' => $tools, 'NOVAMIRA_TEST_TRANSIENT_FILE' => $store],
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stdout . $stderr);
        self::assertSame('', $stderr);
        self::assertStringNotContainsString('open_basedir', $stdout);
        self::assertStringNotContainsString('Warning', $stdout);
        self::assertStringNotContainsString('Handled:', $stdout);

        /** @var array<string, mixed> */
        return json_decode($stdout, associative: true, flags: JSON_THROW_ON_ERROR);
    }

    private static function createTools(): string
    {
        $tools = sys_get_temp_dir() . '/novamira-wpcli-cache-' . bin2hex(random_bytes(6));
        mkdir($tools);
        // getcwd() reports the resolved directory (macOS keeps temporary files behind a symlink),
        // so expected paths are built from the same spelling.
        $tools = (string) realpath($tools);

        file_put_contents($tools . '/which', "#!/bin/sh\necho \"\$@\" >> '{$tools}/launches.log'\necho '{$tools}/wp'\n");
        file_put_contents($tools . '/wp', "#!/bin/sh\necho fake-wp \"\$@\"\n");
        chmod($tools . '/which', 0o755);
        chmod($tools . '/wp', 0o755);

        return $tools;
    }

    private static function removeTools(string $tools): void
    {
        foreach (glob($tools . '/*') ?: [] as $file) {
            is_dir($file) ? self::removeTools($file) : unlink($file);
        }
        rmdir($tools);
    }

    private static function launches(string $tools): int
    {
        $log = $tools . '/launches.log';

        return is_file($log) ? count(file($log, FILE_IGNORE_NEW_LINES) ?: []) : 0;
    }
}
