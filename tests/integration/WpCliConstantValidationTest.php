<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Validation of the NOVAMIRA_WP_CLI_COMMAND constant.
 *
 * Every configuration runs in its own child process, twice: once with a logging `which` as the only
 * thing on the PATH, which shows whether discovery ran, and once with every process function
 * disabled, where any launch ends the child with a fatal error.
 */
final class WpCliConstantValidationTest extends TestCase
{
    private const PROCESS_FUNCTIONS = ['exec', 'shell_exec', 'system', 'passthru', 'popen', 'proc_open'];

    private const GUARDED_FUNCTIONS = [
        'array_filter',
        'array_slice',
        'basename',
        'file_exists',
        'in_array',
        'ini_get',
        'is_executable',
        'is_file',
        'pathinfo',
        'preg_match',
        'restore_error_handler',
        'rtrim',
        'set_error_handler',
        'sprintf',
        'str_contains',
        'str_ends_with',
        'str_starts_with',
        'strpbrk',
        'strpos',
        'strtolower',
        'substr',
    ];

    private string $base;

    private string $site;

    private string $tools;

    private string $discovered;

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('The logging `which` wrapper and the file modes are POSIX ones.');
        }

        // Resolved, so paths compare equal where the temporary directory is a symlink, as on macOS.
        $this->base = realpath(sys_get_temp_dir()) . '/novamira-constant-' . bin2hex(random_bytes(6));
        $this->site = $this->base . '/site';
        $this->tools = $this->base . '/tools';
        mkdir($this->site . '/bin', permissions: 0777, recursive: true);
        mkdir($this->tools);

        $this->discovered = self::executable($this->base . '/discovered-wp');
        file_put_contents($this->tools . '/which', <<<SH
            #!/bin/sh
            echo "\$*" >> "{$this->tools}/launches.log"
            echo "{$this->discovered}"
            SH);
        chmod($this->tools . '/which', 0755);
    }

    protected function tearDown(): void
    {
        if (isset($this->base)) {
            self::removeTree($this->base);
        }
    }

    public function testMissingAbsoluteProgramIsReportedWithoutDiscovery(): void
    {
        $path = $this->base . '/nope/wp';
        $result = $this->resolve(['constant' => $path]);

        self::assertUnavailable($result, $path);
        self::assertSame(
            "The NOVAMIRA_WP_CLI_COMMAND constant names a program that does not exist or is not a file: {$path}. Correct the constant, or remove it to search this server for WP-CLI.",
            $result['status']['reason'],
        );
        self::assertSame(
            "Ability 'novamira/run-wp-cli' is unavailable: {$result['status']['reason']}",
            $result['unavailable_message'],
        );
        self::assertSame($result['status']['reason'], $result['run_error']);
    }

    public function testDirectoryAsProgramIsReported(): void
    {
        self::assertUnavailable($this->resolve(['constant' => $this->site . '/bin']), $this->site . '/bin');
    }

    public function testNonExecutableProgramIsReported(): void
    {
        $path = $this->base . '/wp';
        file_put_contents($path, '');
        chmod($path, 0644);

        $result = $this->resolve(['constant' => $path]);

        self::assertUnavailable($result, $path);
        self::assertStringContainsString('not executable', $result['status']['reason']);
    }

    public function testRelativeProgramIsValidatedAgainstAbspath(): void
    {
        self::executable($this->site . '/bin/wp');

        $result = $this->resolve(['constant' => 'bin/wp']);

        self::assertAvailable($result, ['bin/wp']);
    }

    public function testRelativeProgramMissingUnderAbspathIsReportedEvenIfPresentInTheWorkingDirectory(): void
    {
        // The child's working directory is the base directory, where this relative path does exist.
        mkdir($this->base . '/elsewhere');
        self::executable($this->base . '/elsewhere/wp');

        self::assertUnavailable($this->resolve(['constant' => 'elsewhere/wp']), $this->site . '/elsewhere/wp');
    }

    public function testArgvWithMissingPharIsReported(): void
    {
        $phar = $this->base . '/nope/wp-cli.phar';
        $result = $this->resolve(['constant' => [self::php(), '-c', $this->ini(), $phar]]);

        self::assertUnavailable($result, $phar);
        self::assertStringContainsString('a phar that does not exist', $result['status']['reason']);
    }

    public function testBarePharNameResolvesAgainstAbspath(): void
    {
        $result = $this->resolve(['constant' => ['php', 'wp-cli.phar']]);
        self::assertUnavailable($result, $this->site . '/wp-cli.phar');

        file_put_contents($this->site . '/wp-cli.phar', '');
        self::assertAvailable($this->resolve(['constant' => ['php', 'wp-cli.phar']]), ['php', 'wp-cli.phar']);
    }

    public function testOptionValuesAreNotEntryPoints(): void
    {
        $phar = $this->phar();
        $value = $this->base . '/nope/value.phar';
        $missing = $this->base . '/nope.phar';

        // The value does not exist and ends in .phar, so only its position keeps it out, and the
        // real entry point after it is still found in either direction.
        foreach ($this->valueOptionForms($value) as $form) {
            self::assertSame(
                [null, $missing],
                [
                    $this->check([self::php(), ...$form, $phar]),
                    $this->missingPath($this->check([self::php(), ...$form, $missing])),
                ],
                implode(' ', $form),
            );
        }

        // A missing php.ini is not a reason to hide anything: PHP runs without it.
        $command = [self::php(), '-c', $this->base . '/nope/php.ini', $phar];
        self::assertAvailable($this->resolve(['constant' => $command]), $command);
    }

    public function testEveryFormOfTheFileOptionIsAnEntryPoint(): void
    {
        $missing = $this->base . '/nope.phar';

        foreach ($this->fileOptionForms($missing) as $form) {
            $result = $this->resolve(['constant' => [self::php(), ...$form]]);
            self::assertUnavailable($result, $missing);
            self::assertStringContainsString('a phar that does not exist', $result['status']['reason']);
        }

        // Flags before the option do not end the search, script arguments after it are not read,
        // and a script that is not a phar is not checked.
        self::assertNull($this->check([self::php(), '-f', $this->phar(), $missing]));
        self::assertNull($this->check([self::php(), '-f', $this->phar(), '--', $missing]));
        self::assertNull($this->check([self::php(), '-f', $this->base . '/nope.php']));
        self::assertSame($missing, $this->missingPath($this->check([self::php(), '-n', '-f', $missing])));
        self::assertSame($missing, $this->missingPath($this->check([self::php(), '-f', $missing, '--', 'x'])));
    }

    public function testFormsThePharParserAcceptsReallyRunTheScript(): void
    {
        // Pins the parser's reading of PHP to this PHP: each accepted form must start the script.
        file_put_contents($this->site . '/script.phar', '<?php echo "ran in ", getcwd();');

        $forms = [...$this->fileOptionForms('script.phar'), ['-n', 'script.phar'], ['-c', 'x', 'script.phar']];
        foreach ([...$forms, ['-dx=1', 'script.phar'], ['--define=x=1', 'script.phar']] as $form) {
            // The trailing `--` keeps the --allow-root the run path adds for a root user out of PHP's options.
            $result = $this->runChild(['constant' => [self::php(), ...$form, '--'], 'execute' => []]);
            self::assertSame('ran in ' . $this->site, $result['execution']['stdout'] ?? null, implode(' ', $form));
        }
    }

    public function testUnderstoodFlagsDoNotEndTheSearch(): void
    {
        $missing = $this->base . '/nope.phar';

        foreach (['-n', '--no-php-ini', '-q', '-e', '-H', '-dx=1'] as $flag) {
            self::assertSame($missing, $this->missingPath($this->check([self::php(), $flag, $missing])), $flag);
        }
    }

    public function testOptionsThatAreNotUnderstoodLeaveTheEntryPointUnchecked(): void
    {
        $missing = $this->base . '/nope.phar';

        // Every option `php -h` lists besides the understood ones, then forms PHP also accepts but
        // this parser does not model, then anything else that starts with a dash.
        $options = [
            '-a', '-h', '-i', '-l', '-m', '-r', '-B', '-R', '-F', '-E', '-S', '-t', '-s', '-v', '-w',
            '--ini', '--ini=diff', '--rf', '--rc', '--re', '--rz', '--ri', '--repeat',
            '-recho 1;', '-nf', '-nr', '-', '--unknown', '--unknown=1', '-X',
        ];
        foreach ($options as $option) {
            foreach (
                [
                    [self::php(), $option, $missing],
                    [self::php(), $option, 'echo 1;', $missing],
                    [self::php(), '-n', $option, $missing],
                    [self::php(), '-c', 'php.ini', $option, $missing],
                ] as $command
            ) {
                self::assertNull($this->check($command), implode(' ', $command));
            }
        }

        // An option that is not understood also leaves the value unchecked when it follows `-f`.
        self::assertNull($this->check([self::php(), '-f', $missing, '-r', 'echo 1;']));
    }

    public function testNothingAfterTheScriptIsReadAsAnOption(): void
    {
        $missing = $this->base . '/nope.phar';
        $phar = $this->phar();

        // Everything after the script belongs to the script, even when it looks like a PHP option.
        foreach (['--path=/x', '-r', '--unknown'] as $argument) {
            self::assertSame($missing, $this->missingPath($this->check([self::php(), $missing, $argument])), $argument);
        }
        self::assertNull($this->check([self::php(), $phar, '-f', $missing]));
        self::assertNull($this->check([self::php(), $phar, '--file=' . $missing]));
    }

    public function testInlineCodeMentioningAPharStillRuns(): void
    {
        $code = 'echo "ok"; // wrapper.phar';
        self::assertNull($this->check([self::php(), '-r', $code]));

        // The trailing `--` keeps the --allow-root the run path adds for a root user out of PHP's options.
        $command = [self::php(), '-r', $code, '--'];
        $result = $this->runChild(['constant' => $command, 'execute' => []]);

        self::assertSame($command, $result['command']);
        self::assertSame('ok', $result['execution']['stdout']);
    }

    public function testOnlyTheScriptPositionOfAPhpProgramIsAnEntryPoint(): void
    {
        $missing = $this->base . '/nope.phar';

        // Script arguments, stdin mode, a non-phar script, and wrappers whose arguments may not be
        // local files.
        foreach (
            [
                [self::php(), $this->phar(), $missing],
                [self::php(), '--', $missing],
                [self::php(), $this->base . '/script.php', $missing],
                [$this->discovered, $missing],
                ['docker', 'exec', 'wordpress', 'php', $missing],
            ] as $command
        ) {
            self::assertAvailable($this->resolve(['constant' => $command]), $command);
        }
    }

    public function testRelativeProgramRunsFromAbspathThroughTheRealRunPath(): void
    {
        $program = $this->site . '/bin/fake-wp';
        file_put_contents($program, "#!/bin/sh\npwd\necho \"args: \$*\"\n");
        chmod($program, 0755);

        $result = $this->runChild(['constant' => 'bin/fake-wp', 'execute' => ['plugin', 'list']]);

        self::assertSame(['bin/fake-wp'], $result['command']);
        self::assertSame(0, $result['execution']['exit_code'], (string) json_encode($result['execution']));
        self::assertStringStartsWith($this->site . "\nargs: ", $result['execution']['stdout']);
        self::assertStringEndsWith("plugin list\n", $result['execution']['stdout']);
    }

    public function testDisabledFunctionsLeaveTheValueUnchecked(): void
    {
        $root = dirname(__DIR__, levels: 2);
        // Inside open_basedir, so the open_basedir probe runs and the program is really examined.
        $open_basedir = 'open_basedir=' . implode(PATH_SEPARATOR, [$this->base, $root]);
        $php = self::executable($this->base . '/php');

        // A passing value reaches every stat and parser function; a failing one reaches sprintf too.
        $valid = [$php, '-c', $this->ini(), '-dx=1', $this->phar()];
        $failing = [$php, $this->base . '/nope.phar'];

        // trim() is left out: novamira_normalize_wp_cli_command() needs it before validation starts.
        foreach (self::GUARDED_FUNCTIONS as $function) {
            foreach ([$valid, $failing] as $command) {
                $result = $this->runChild(['constant' => $command, 'minimal' => true], [
                    $open_basedir,
                    'disable_functions=' . $function,
                ]);

                self::assertSame($command, $result['command'], $function);
                self::assertNull($result['problem'], $function);
            }
        }

        // Control: with nothing disabled, the failing value is reported by the same harness.
        $result = $this->runChild(['constant' => $failing, 'minimal' => true], [$open_basedir]);
        self::assertNull($result['command']);
        self::assertIsString($result['problem']);
    }

    public function testNonExecutablePharRunThroughPhpIsAvailable(): void
    {
        $phar = $this->phar();
        chmod($phar, 0644);

        self::assertAvailable($this->resolve(['constant' => [self::php(), $phar]]), [self::php(), $phar]);
    }

    public function testBareCommandNameIsLeftUnchecked(): void
    {
        self::assertAvailable($this->resolve(['constant' => 'wp']), ['wp']);
    }

    public function testCorrectStringAndArgvAreAvailable(): void
    {
        self::assertAvailable($this->resolve(['constant' => $this->discovered]), [$this->discovered]);

        $argv = [self::php(), '-c', $this->ini(), $this->phar()];
        self::assertAvailable($this->resolve(['constant' => $argv]), $argv);
    }

    public function testBlankOrWronglyTypedConstantsStillFallBackToDiscovery(): void
    {
        foreach (['', '   ', [], 42, false, ['/nope/wp', '']] as $constant) {
            $result = $this->runChild(['constant' => $constant]);

            self::assertSame([$this->discovered], $result['command']);
            self::assertNull($result['problem']);
            self::assertTrue($result['status']['available']);
            self::assertContains('wp', $this->launches(), (string) json_encode($constant));
            unlink($this->tools . '/launches.log');
        }
    }

    public function testDiscoveryRunsWithoutTheConstant(): void
    {
        // Control for the launch assertions: this harness does see discovery when it happens.
        $result = $this->runChild([]);

        self::assertSame([$this->discovered], $result['command']);
        self::assertContains('wp', $this->launches());
        // And the cache assertions: discovery stores its result, a constant never reaches it.
        self::assertContains('set', $result['transient_calls']);
    }

    public function testFilterCanOverrideAFailedConstantAndIsNotValidated(): void
    {
        $filter = [$this->base . '/nope/from-filter'];
        $result = $this->runChild(['constant' => $this->base . '/nope/wp', 'filter' => $filter]);

        self::assertNull($result['filter_received']);
        self::assertSame($filter, $result['command']);
        self::assertTrue($result['status']['available']);
        self::assertSame([], $this->launches());
    }

    public function testFilterReturningNullKeepsTheReason(): void
    {
        $path = $this->base . '/nope/wp';
        $result = $this->runChild(['constant' => $path, 'filter' => null]);

        self::assertNull($result['command']);
        self::assertStringContainsString($path, $result['status']['reason']);
    }

    public function testFilterReceivesAValidConstant(): void
    {
        $result = $this->runChild(['constant' => 'wp', 'filter' => null]);

        self::assertSame(['wp'], $result['filter_received']);
        self::assertSame('WP-CLI was not found on this server.', $result['status']['reason']);
    }

    public function testPathsOutsideOpenBasedirAreLeftUncheckedWithoutWarnings(): void
    {
        $root = dirname(__DIR__, levels: 2);
        $open_basedir = implode(PATH_SEPARATOR, [$this->site, $root]);
        $settings = ['open_basedir=' . $open_basedir, 'display_errors=1', 'error_reporting=-1', 'log_errors=0'];

        // open_basedir does not bind a started process, so this value would work at run time.
        $command = [$this->discovered, $this->base . '/nope.phar'];
        $result = $this->runChild(['constant' => $command], $settings);
        self::assertSame($command, $result['command']);

        $php_outside = [$this->base . '/php', $this->base . '/nope.phar'];
        $result = $this->runChild(['constant' => $php_outside], $settings);
        self::assertSame($php_outside, $result['command']);

        // Inside open_basedir the check still applies, also to a phar.
        $missing = $this->site . '/nope';
        $result = $this->runChild(['constant' => $missing], $settings);
        self::assertNull($result['command']);
        self::assertStringContainsString($missing, $result['status']['reason']);

        $result = $this->runChild(['constant' => ['php', $missing . '.phar']], $settings);
        self::assertNull($result['command']);
        self::assertStringContainsString($missing . '.phar', $result['status']['reason']);
    }

    public function testWindowsRulesCheckExistenceOnly(): void
    {
        // A drive-rooted path is a relative one on POSIX, so with the child's working directory as
        // the base these are real files reached through the real Windows path checks.
        mkdir($this->base . '/C:/tools', permissions: 0777, recursive: true);
        foreach (['wp.exe', 'php.exe', 'launcher.cmd', 'wp-cli.phar', 'shell.com', 'run.bat'] as $name) {
            file_put_contents($this->base . '/C:/tools/' . $name, '');
            chmod($this->base . '/C:/tools/' . $name, 0644);
        }
        mkdir($this->base . '/C:/tools/folder');
        $phar = 'C:/tools/wp-cli.phar';

        $problems = $this->windowsProblems([
            'non-executable exe' => ['C:/tools/wp.exe'],
            'non-executable batch file' => ['C:/tools/launcher.cmd'],
            'extension-less program started as .exe' => ['C:/tools/wp'],
            'extension-less program started as .cmd' => ['C:/tools/launcher'],
            'extension-less program started as .com' => ['C:/tools/shell'],
            'extension-less program started as .bat' => ['C:/tools/run'],
            'bare name' => ['wp.exe'],
            'relative program, resolved by CreateProcess against another directory' => ['bin\\missing.exe'],
            'php.exe by path with a phar' => ['C:/tools/php.exe', $phar],
            'php.exe with an upper-case extension' => ['PHP.EXE', '-c', 'C:/nope/php.ini', $phar],
        ]);
        foreach ($problems as $label => $problem) {
            self::assertNull($problem, $label);
        }

        $problems = $this->windowsProblems([
            'C:/nope/wp.exe' => ['C:/nope/wp.exe'],
            'C:\\nope\\php.exe' => ['C:\\nope\\php.exe', '-c', 'C:\\nope\\php.ini', $phar],
            'C:/tools/folder' => ['C:/tools/folder'],
            '\\\\server\\share\\wp.exe' => ['\\\\server\\share\\wp.exe'],
            '//server/share/wp.exe' => ['//server/share/wp.exe'],
            'C:\\nope\\wp-cli.phar' => ['php.exe', '-c', 'C:\\nope\\php.ini', 'C:\\nope\\wp-cli.phar'],
            '\\\\server\\share\\wp-cli.phar' => ['php.exe', '\\\\server\\share\\wp-cli.phar'],
            $this->site . '/nope.PHAR' => ['PHP.EXE', 'nope.PHAR'],
        ]);
        foreach ($problems as $path => $problem) {
            self::assertIsString($problem, $path);
            self::assertStringContainsString('NOVAMIRA_WP_CLI_COMMAND', $problem);
            self::assertStringContainsString($path, $problem);
        }
    }

    public function testWindowsPathsMissingADriveOrARootAreLeftUnchecked(): void
    {
        // These resolve against the child's drive, which is ABSPATH's and may differ from PHP's own.
        $problems = $this->windowsProblems([
            'program rooted with a slash' => ['/tools/wp.exe'],
            'program rooted with a backslash' => ['\\tools\\wp.exe'],
            'drive-relative program' => ['C:missing.exe'],
            'phar rooted with a slash' => ['php.exe', '/tools/wp-cli.phar'],
            'phar rooted with a backslash' => ['php.exe', '\\tools\\wp-cli.phar'],
            'drive-relative phar' => ['php.exe', 'C:missing.phar'],
            'phar attached to -f, rooted' => ['php.exe', '-f/tools/wp-cli.phar'],
        ]);
        foreach ($problems as $label => $problem) {
            self::assertNull($problem, $label);
        }

        // A relative phar is opened by PHP from ABSPATH, so it is still checked there.
        $problems = $this->windowsProblems(['relative phar' => ['php.exe', 'tools\\nope.phar']]);
        self::assertIsString($problems['relative phar']);
        self::assertStringContainsString($this->site . '/tools\\nope.phar', $problems['relative phar']);
    }

    public function testValidationNeverLaunchesAProcessInAnyConfiguration(): void
    {
        // Covered in every test through resolve(); this one guards the helper itself, whose
        // process-free child must fail if the code under it reaches a disabled function.
        $result = $this->runChild(['constant' => $this->base . '/nope/wp'], [
            'disable_functions=' . implode(',', self::PROCESS_FUNCTIONS),
        ]);

        self::assertNull($result['command']);
        self::assertArrayNotHasKey('status', $result);
    }

    /**
     * Run a configuration with discovery observable, then with every process function disabled.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function resolve(array $input): array
    {
        $result = $this->runChild($input);
        self::assertSame([], $this->launches(), 'A valid or failed constant must not launch discovery.');
        self::assertSame([], $result['transient_calls'], 'A valid or failed constant must not touch the discovery cache.');

        $without_processes = $this->runChild($input, ['disable_functions=' . implode(',', self::PROCESS_FUNCTIONS)]);
        self::assertSame($result['command'], $without_processes['command']);
        self::assertSame($result['problem'], $without_processes['problem']);
        self::assertSame([], $without_processes['transient_calls']);

        return $result;
    }

    /** @param list<string> $command */
    private function check(array $command): ?string
    {
        return $this->runChild(['constant' => 'wp', 'checks' => [$command]])['checks'][0];
    }

    /** Pull the path out of a phar reason, so a wrong path or a wrong kind of reason fails. */
    private function missingPath(?string $problem): ?string
    {
        $prefix = 'The NOVAMIRA_WP_CLI_COMMAND constant names a phar that does not exist or is not a file: ';
        if ($problem === null || !str_starts_with($problem, $prefix)) {
            return null;
        }

        return substr($problem, strlen($prefix), strpos($problem, '. Correct') - strlen($prefix));
    }

    /** @return list<list<string>> Every form PHP accepts for a script given by option. */
    private function fileOptionForms(string $file): array
    {
        return [['-f', $file], ['-f' . $file], ['-f=' . $file], ['--file', $file], ['--file=' . $file]];
    }

    /** @return list<list<string>> Every form PHP accepts for the understood options taking a value. */
    private function valueOptionForms(string $value): array
    {
        return [
            ['-c', $value],
            ['-c' . $value],
            ['-c=' . $value],
            ['--php-ini', $value],
            ['--php-ini=' . $value],
            ['-d', 'x=' . $value],
            ['-dx=' . $value],
            ['--define', 'x=' . $value],
            ['--define=x=' . $value],
            ['-z', $value],
            ['-z' . $value],
            ['--zend-extension', $value],
            ['--zend-extension=' . $value],
        ];
    }

    /**
     * @param array<string, list<string>> $commands
     * @return array<string, string|null>
     */
    private function windowsProblems(array $commands): array
    {
        $result = $this->runChild(['constant' => 'wp', 'windows' => array_values($commands)]);

        return array_combine(array_keys($commands), $result['windows']);
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string>         $settings php.ini settings for the child.
     * @return array<string, mixed>
     */
    private function runChild(array $input, array $settings = []): array
    {
        $command = [PHP_BINARY];
        foreach ($settings as $setting) {
            $command[] = '-d';
            $command[] = $setting;
        }
        $command[] = dirname(__DIR__) . '/fixtures/wp-cli-constant-runner.php';
        $command[] = json_encode(['abspath' => $this->site . '/'] + $input, JSON_THROW_ON_ERROR);

        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->base,
            ['PATH' => $this->tools],
        );
        self::assertIsResource($process);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit_code = proc_close($process);

        self::assertSame(0, $exit_code, "stdout:\n{$stdout}\nstderr:\n{$stderr}");
        self::assertSame('', $stderr, $stderr);

        /** @var array<string, mixed>|null $result */
        $result = json_decode($stdout, associative: true);
        self::assertIsArray($result, $stdout);

        return $result;
    }

    /** @return list<string> */
    private function launches(): array
    {
        $log = $this->tools . '/launches.log';

        return is_file($log) ? (file($log, FILE_IGNORE_NEW_LINES) ?: []) : [];
    }

    /**
     * @param array<string, mixed> $result
     * @param list<string>         $command
     */
    private static function assertAvailable(array $result, array $command): void
    {
        self::assertSame($command, $result['command']);
        self::assertNull($result['problem']);
        self::assertSame(['available' => true, 'reason' => ''], $result['status']);
        self::assertNull($result['unavailable_message']);
    }

    /** @param array<string, mixed> $result */
    private static function assertUnavailable(array $result, string $path): void
    {
        self::assertNull($result['command']);
        self::assertFalse($result['status']['available']);
        self::assertStringContainsString('NOVAMIRA_WP_CLI_COMMAND', $result['status']['reason']);
        self::assertStringContainsString($path, $result['status']['reason']);
    }

    private static function php(): string
    {
        return PHP_BINARY;
    }

    private function ini(): string
    {
        $ini = $this->base . '/php.ini';
        file_put_contents($ini, '');

        return $ini;
    }

    private function phar(): string
    {
        $phar = $this->base . '/wp-cli.phar';
        file_put_contents($phar, '');

        return $phar;
    }

    private static function executable(string $path): string
    {
        file_put_contents($path, '');
        chmod($path, 0755);

        return $path;
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
