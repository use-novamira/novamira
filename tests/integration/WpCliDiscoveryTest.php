<?php
// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', '/');
}

// The explicit escape hatch documented in run-wp-cli.php. Defining it here also keeps the
// resolution tests independent of whatever WP-CLI (if any) exists on the machine running the suite.
if (!defined('NOVAMIRA_WP_CLI_COMMAND')) {
    define('NOVAMIRA_WP_CLI_COMMAND', [
        '/opt/novamira-test/php',
        '-c',
        '/opt/novamira-test/php.ini',
        '/opt/novamira-test/wp-cli.phar',
    ]);
}

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}
if (!function_exists('wp_register_ability')) {
    function wp_register_ability(string $name, array $args): mixed
    {
        $GLOBALS['novamira_test_registered_abilities'][$name] = $args;
        return null;
    }
}
if (!function_exists('wp_has_ability')) {
    function wp_has_ability(string $name): bool
    {
        return isset($GLOBALS['novamira_test_registered_abilities'][$name]);
    }
}
// The suite shares one apply_filters stub across test files; whichever file loads first defines it.
// The shared stubs all pass the value through, so the filter override itself is asserted in an
// isolated process where this dispatching stub is guaranteed to be the active one.
if (!function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        /** @var array<string, callable> $filters */
        $filters = $GLOBALS['novamira_test_filters'] ?? [];
        if (isset($filters[$hook])) {
            return $filters[$hook]($value);
        }
        return $value;
    }
}

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/abilities/bootstrap.php';
require_once __DIR__ . '/../../includes/abilities/run-wp-cli.php';
novamira_register_wp_cli_abilities();

final class WpCliDiscoveryTest extends TestCase
{
    public function testNormalizationAcceptsArgvListsAndSingleExecutables(): void
    {
        self::assertSame(['/usr/local/bin/wp'], novamira_normalize_wp_cli_command('/usr/local/bin/wp'));
        self::assertSame(
            ['C:/php/php.exe', '-c', 'C:/site/php.ini', 'C:/local/wp-cli.phar'],
            novamira_normalize_wp_cli_command(['C:/php/php.exe', '-c', 'C:/site/php.ini', 'C:/local/wp-cli.phar']),
        );
        self::assertSame(['/usr/local/bin/wp'], novamira_normalize_wp_cli_command("  /usr/local/bin/wp\n"));
    }

    public function testNormalizationRejectsUnusableConfiguration(): void
    {
        self::assertNull(novamira_normalize_wp_cli_command(null));
        self::assertNull(novamira_normalize_wp_cli_command(''));
        self::assertNull(novamira_normalize_wp_cli_command([]));
        self::assertNull(novamira_normalize_wp_cli_command(['/usr/local/bin/wp', '']));
        self::assertNull(novamira_normalize_wp_cli_command(['/usr/local/bin/wp', 42]));
        self::assertNull(novamira_normalize_wp_cli_command(false));
    }

    public function testUnixEntryPointIsInvokedDirectly(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Extension-less launchers are not startable on Windows.');
        }

        self::assertSame(
            ['/usr/local/bin/wp'],
            novamira_wp_cli_command_for_path('/usr/local/bin/wp', null, null),
        );
    }

    public function testPharIsInvokedThroughPhpBinaryWithSiteIni(): void
    {
        self::assertSame(
            ['C:/php/php.exe', '-c', 'C:/site/conf/php/php.ini', 'C:/local/wp-cli.phar'],
            novamira_wp_cli_command_for_path('C:/local/wp-cli.phar', 'C:/php/php.exe', 'C:/site/conf/php/php.ini'),
        );

        self::assertSame(
            ['/usr/bin/php', '/opt/wp-cli.phar'],
            novamira_wp_cli_command_for_path('/opt/wp-cli.phar', '/usr/bin/php', null),
        );

        // A phar without a PHP binary is not an invocation at all.
        self::assertNull(novamira_wp_cli_command_for_path('C:/local/wp-cli.phar', null, 'C:/site/php.ini'));
    }

    public function testBatchLauncherResolvesToThePharBesideIt(): void
    {
        $tools = sys_get_temp_dir() . '/novamira-wpcli-' . bin2hex(random_bytes(6));
        mkdir($tools);

        try {
            $launcher = $tools . '/wp.bat';
            file_put_contents($launcher, '@php "%~dp0wp-cli.phar" %*');

            // No phar beside it: the launcher is skipped rather than started through cmd.exe, so
            // resolution moves on to the next candidate instead of accepting an unsafe invocation.
            self::assertNull(novamira_wp_cli_command_for_path($launcher, '/usr/bin/php', null));

            // With the phar present the same program is reached without cmd.exe in the picture.
            $phar = $tools . '/wp-cli.phar';
            file_put_contents($phar, '');
            self::assertSame(
                ['/usr/bin/php', '-c', '/site/php.ini', $phar],
                novamira_wp_cli_command_for_path($launcher, '/usr/bin/php', '/site/php.ini'),
            );

            // A phar still needs a PHP binary; without one there is nothing safe to run.
            self::assertNull(novamira_wp_cli_command_for_path($launcher, null, null));

            // No resolved command may name a batch file or an interpreter: every argument after
            // the program is ability input, and cmd.exe re-parses everything it is handed.
            $command = novamira_wp_cli_command_for_path($launcher, '/usr/bin/php', null);
            self::assertIsArray($command);
            foreach ($command as $element) {
                self::assertDoesNotMatchRegularExpression('/\.(bat|cmd)$/i', $element);
                self::assertDoesNotMatchRegularExpression('/(^|[\\\\\\/])cmd\.exe$/i', $element);
            }
            self::assertNotContains('/c', $command);
        } finally {
            foreach (glob($tools . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($tools);
        }

        self::assertSame(['C:/tools/wp.exe'], novamira_wp_cli_command_for_path('C:/tools/wp.exe', null, null));
    }

    public function testBackgroundResultsOmitValuesTheOutputSchemaCannotCarry(): void
    {
        // The output schema types job_id as a string and pid as an integer, and neither is
        // required. A null therefore fails output validation and costs the caller the whole
        // result - including the job_id of a job that did start.
        $schema = $GLOBALS['novamira_test_registered_abilities']['novamira/run-wp-cli']['output_schema'];

        foreach (['job_id' => 'string', 'pid' => 'integer'] as $field => $type) {
            self::assertSame($type, $schema['properties'][$field]['type']);
            self::assertNotContains($field, $schema['required']);
        }

        // Every literal in the file that returns one of these fields must carry a real value.
        // Windows reports no PID for a detached start, which is exactly where the null was.
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/abilities/run-wp-cli.php');
        self::assertStringNotContainsString("'pid' => null", $source);
        self::assertStringNotContainsString("'job_id' => null", $source);
    }

    public function testConstantOverridesHostDiscovery(): void
    {
        self::assertSame(
            [
                '/opt/novamira-test/php',
                '-c',
                '/opt/novamira-test/php.ini',
                '/opt/novamira-test/wp-cli.phar',
            ],
            novamira_find_wp_cli_command(),
        );
    }

    public function testConfiguredWpCliIsAvailableForAbilityRegistration(): void
    {
        self::assertTrue(novamira_wp_cli_status()['available']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFilterReplacesTheResolvedInvocation(): void
    {
        $GLOBALS['novamira_test_filters'] = [
            'novamira_wp_cli_command' => static fn(mixed $command): array => ['C:/php/php.exe', 'C:/wp-cli.phar'],
        ];

        self::assertSame(['C:/php/php.exe', 'C:/wp-cli.phar'], novamira_find_wp_cli_command());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFilterReceivesTheResolvedInvocationAndCanReportItUnavailable(): void
    {
        $seen = null;
        $GLOBALS['novamira_test_filters'] = [
            'novamira_wp_cli_command' => static function (mixed $command) use (&$seen): mixed {
                $seen = $command;
                return null;
            },
        ];

        self::assertNull(novamira_find_wp_cli_command());
        self::assertFalse(novamira_wp_cli_status()['available']);
        self::assertSame(
            "Ability 'novamira/run-wp-cli' is unavailable: WP-CLI was not found on this server.",
            novamira_wp_cli_unavailable_message('novamira/run-wp-cli'),
        );
        self::assertNull(novamira_wp_cli_unavailable_message('novamira/read-file'));

        require_once dirname(__DIR__, levels: 2) . '/includes/helpers.php';
        $enriched = novamira_enrich_unavailable_wp_cli_error(
            ['success' => false, 'error' => "Ability 'novamira/run-wp-cli' not found"],
            ['ability_name' => 'novamira/run-wp-cli'],
        );
        self::assertSame(
            "Ability 'novamira/run-wp-cli' is unavailable: WP-CLI was not found on this server.",
            $enriched['error'],
        );

        require_once dirname(__DIR__, levels: 2) . '/includes/admin-page.php';
        $groups = novamira_append_unavailable_wp_cli_rows([]);
        $names = array_column($groups['novamira'], 'name');
        sort($names);
        self::assertSame(['novamira/get-wp-cli-job', 'novamira/run-wp-cli'], $names);
        foreach ($groups['novamira'] as $row) {
            self::assertSame('Unavailable', $row['status']);
            self::assertSame('WP-CLI was not found on this server.', $row['description']);
            self::assertTrue($row['disabled']);
            self::assertFalse($row['individually_manageable']);
        }
        self::assertSame(
            [
                '/opt/novamira-test/php',
                '-c',
                '/opt/novamira-test/php.ini',
                '/opt/novamira-test/wp-cli.phar',
            ],
            $seen,
        );
    }

    public function testNotFoundMessageNamesSearchedLocationsAndOverrides(): void
    {
        $windows = novamira_wp_cli_not_found_message('Windows');
        self::assertStringContainsString('where', $windows);
        self::assertStringContainsString('wp-cli.phar', $windows);
        self::assertStringContainsString('Local', $windows);
        self::assertStringContainsString('NOVAMIRA_WP_CLI_COMMAND', $windows);
        self::assertStringContainsString('novamira_wp_cli_command', $windows);
        self::assertStringContainsString('NOVAMIRA_WP_CLI_PHP_INI', $windows);

        $unix = novamira_wp_cli_not_found_message('Linux');
        self::assertStringContainsString('which wp', $unix);
        self::assertStringContainsString('/usr/local/bin', $unix);
        self::assertStringContainsString('NOVAMIRA_WP_CLI_COMMAND', $unix);
        self::assertStringContainsString('novamira_wp_cli_command', $unix);

        // The message is surfaced verbatim by the CLI, so it must not disclose install paths:
        // only generic system locations and placeholders are named.
        $home = (string) getenv('HOME');
        foreach ([$windows, $unix] as $message) {
            if ($home !== '' && $home !== '/') {
                self::assertStringNotContainsString($home, $message);
            }
            self::assertDoesNotMatchRegularExpression('#[A-Za-z]:[\\\\/]#', $message);
            self::assertStringNotContainsString('Users', $message);
        }
    }

    public function testBackgroundCommandKeepsEveryElementEscaped(): void
    {
        $legacy = escapeshellarg('/usr/local/bin/wp')
            . ' '
            . implode(' ', array_map('escapeshellarg', ['plugin', 'list', '--format=json']));

        self::assertSame(
            $legacy,
            novamira_build_wp_cli_shell_command(['/usr/local/bin/wp'], ['plugin', 'list', '--format=json']),
        );

        $hostile = '; rm -rf / #';
        $command = novamira_build_wp_cli_shell_command(
            ['C:/php/php.exe', '-c', 'C:/site/php.ini', 'C:/local/wp-cli.phar'],
            ['eval', $hostile],
        );

        self::assertStringContainsString(escapeshellarg($hostile), $command);
        self::assertStringNotContainsString(' ' . $hostile, $command);
        foreach (['C:/php/php.exe', '-c', 'C:/site/php.ini', 'C:/local/wp-cli.phar', 'eval'] as $part) {
            self::assertStringContainsString(escapeshellarg($part), $command);
        }
    }

    public function testWindowsBackgroundCommandEscapesWithoutLosingCharacters(): void
    {
        // escapeshellarg() is documented to replace percent signs, exclamation marks and double
        // quotes with spaces on Windows, so the async path used to run a different query than the
        // synchronous one for the same input. Nothing may be dropped or turned into a space.
        $query = "SELECT ID FROM wp_posts WHERE post_title LIKE '%draft%'";
        $command = novamira_build_wp_cli_shell_command(
            ['C:/Program Files/php/php.exe', '-c', 'C:/site/php.ini', 'C:/wp-cli.phar'],
            ['db', 'query', $query],
            windows: true,
        );

        // The executable keeps real quotes: cmd.exe locates the program with its own rules and a
        // program directory can contain a space.
        self::assertStringStartsWith('"C:/Program Files/php/php.exe" ', $command);
        // Every literal character of the query survives; only the percent signs are doubled,
        // which is how a batch file writes a literal percent.
        self::assertStringContainsString("LIKE '%%draft%%'", $command);
        self::assertStringNotContainsString(' draft ', $command);
        foreach (['db', 'query', 'SELECT ID FROM wp_posts'] as $fragment) {
            self::assertStringContainsString($fragment, $command);
        }

        // A password or search term containing an exclamation mark or a double quote survives too.
        self::assertSame('^"pa^!ss^"', novamira_escape_windows_batch_arg('pa!ss'));
        self::assertSame('^"a\\^"b^"', novamira_escape_windows_batch_arg('a"b'));
        // Trailing backslashes are doubled, or they would escape the closing quote for the C
        // runtime and swallow the next argument.
        self::assertSame('^"C:\\dir\\\\^"', novamira_escape_windows_batch_arg('C:\\dir\\'));
    }

    public function testWindowsBackgroundCommandLeavesNoUnescapedShellSyntax(): void
    {
        $hostile = '& calc.exe & echo %USERPROFILE% | more > out.txt ^ (x) "q" !DELAYED!';
        $command = novamira_build_wp_cli_shell_command(['C:/wp.exe'], [$hostile], windows: true);

        // Every cmd.exe metacharacter in the argument must carry a caret, and every percent sign
        // must be doubled; anything else is a command the ability input could start.
        $tail = substr($command, strlen('"C:/wp.exe" '));
        $length = strlen($tail);
        $index = 0;
        // Read the token the way cmd.exe does: a caret consumes the character after it, and a
        // literal percent sign is written as two. Whatever is left must be inert.
        while ($index < $length) {
            $character = $tail[$index];
            if ($character === '^') {
                $index += 2;
                continue;
            }
            if ($character === '%') {
                self::assertSame('%%', substr($tail, $index, 2), 'a lone percent sign expands');
                $index += 2;
                continue;
            }
            self::assertFalse(
                in_array($character, ['&', '|', '<', '>', '(', ')', '"', '!'], true),
                'unescaped ' . $character,
            );
            $index++;
        }
        // `%%USERPROFILE%%` is how a batch file writes the literal text `%USERPROFILE%`: the
        // parser turns each `%%` into one `%` and never sees a variable reference.
        self::assertStringContainsString('%%USERPROFILE%%', $tail);
    }

    public function testWindowsJobScriptRecordsTheRealExitCode(): void
    {
        $script = novamira_build_windows_job_script(
            '"C:/php.exe" ^"cli^"',
            'C:\\site\\',
            'C:\\jobs\\job_a.log',
            'C:\\jobs\\job_a.status',
        );

        // cmd.exe reads a digit immediately before `>` as the handle number to redirect, so
        // `echo %ERRORLEVEL%> "file"` records no exit code for any value: 1 redirects stdout and
        // leaves "ECHO is off.", 0 redirects stdin and leaves the file empty. The redirection has
        // to come first.
        self::assertStringNotContainsString('%ERRORLEVEL%>', $script);
        self::assertStringContainsString(
            '> "C:\\jobs\\job_a.status" echo %ERRORLEVEL%',
            $script,
        );
        self::assertStringContainsString('setlocal DisableDelayedExpansion', $script);
        self::assertStringContainsString('cd /d "C:\\site\\"', $script);
        self::assertStringContainsString('> "C:\\jobs\\job_a.log" 2>&1', $script);
    }

    public function testWindowsJobPathsKeepPercentSignsLiteral(): void
    {
        $script = novamira_build_windows_job_script(
            'CMD',
            'C:\\sites\\100% real\\',
            'C:\\jobs\\job_a.log',
            'C:\\jobs\\job_a.status',
        );

        self::assertStringContainsString('cd /d "C:\\sites\\100%% real\\"', $script);
    }

    public function testWindowsJobScriptSelectsUtf8BeforeReadingAnyPath(): void
    {
        $script = novamira_build_windows_job_script(
            'CMD',
            'C:\\sites\\København\\',
            'C:\\jobs\\José\\job_a.log',
            'C:\\jobs\\José\\job_a.status',
        );

        // cmd.exe decodes a batch file with the console code page, which on a Danish or Spanish
        // Windows is an OEM page such as 850. Without the switch these UTF-8 paths are read as
        // mojibake and the job runs somewhere else, or not at all.
        self::assertStringContainsString("chcp 65001 > nul\r\n", $script);

        // The switch has to precede every line that carries a non-ASCII byte, because cmd reads
        // the remainder of the file with whatever page is active when it gets there.
        $chcp = strpos($script, 'chcp 65001');
        self::assertIsInt($chcp);
        foreach (['cd /d', 'job_a.log', 'job_a.status'] as $needle) {
            self::assertGreaterThan($chcp, strpos($script, $needle), $needle);
        }

        // A byte order mark on line one would be executed as a command.
        self::assertStringStartsWith('@echo off', $script);

        // The paths themselves stay verbatim UTF-8; nothing transliterates them.
        self::assertStringContainsString('C:\\sites\\København\\', $script);
        self::assertStringContainsString('C:\\jobs\\José\\job_a.log', $script);
    }

    public function testPhpBinaryDetectionRejectsServerSapiBinaries(): void
    {
        // PHP_BINDIR is the build-time prefix on Windows ("C:\php" for the official builds), so
        // the FastCGI binary used to be selected instead - and php-cgi.exe writes CGI headers
        // before any script output, which corrupts every WP-CLI result. A prefix test on "php"
        // accepts it; an exact name test does not.
        foreach (['php', 'php.exe', 'C:/bin/PHP.EXE', '/usr/bin/php8', '/usr/bin/php8.2'] as $path) {
            self::assertTrue(novamira_is_php_cli_binary($path), $path);
        }
        foreach (
            [
                '',
                'php-cgi',
                'C:/lightning-services/php-8.2/bin/win64/php-cgi.exe',
                '/usr/sbin/php-fpm',
                'C:/php/php-win.exe',
                '/usr/sbin/apache2',
            ] as $path
        ) {
            self::assertFalse(novamira_is_php_cli_binary($path), $path);
        }
    }
}
