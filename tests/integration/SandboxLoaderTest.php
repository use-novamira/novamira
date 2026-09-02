<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SandboxLoaderTest extends TestCase
{
    private string $sandboxDirectory;

    protected function setUp(): void
    {
        $this->sandboxDirectory = sys_get_temp_dir() . '/novamira-sandbox-loader-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->sandboxDirectory));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->sandboxDirectory . '/*') ?: [] as $file) {
            unlink($file);
        }
        foreach (glob($this->sandboxDirectory . '/.*') ?: [] as $file) {
            if (basename($file) !== '.' && basename($file) !== '..') {
                unlink($file);
            }
        }
        rmdir($this->sandboxDirectory);
    }

    public function testPreparingSandboxAllowsOnlyStaticAssetsAndMigratesLegacyDisabledSource(): void
    {
        $legacy = $this->sandboxDirectory . '/extension.php.disabled';
        file_put_contents($legacy, "<?php echo 'sandbox-loaded';");

        [$output, $exitCode] = $this->runLoader('prepare');

        self::assertSame(0, $exitCode);
        self::assertSame('runner-complete', $output);
        $htaccess = (string) file_get_contents($this->sandboxDirectory . '/.htaccess');
        self::assertStringContainsString('(?:css|js)', $htaccess);
        self::assertStringContainsString('(?:php[0-9]?|phtml|phar)', $htaccess);
        self::assertStringContainsString('Require all denied', $htaccess);
        $webConfig = (string) file_get_contents($this->sandboxDirectory . '/web.config');
        self::assertStringContainsString('allowUnlisted="false"', $webConfig);
        self::assertStringContainsString('<clear />', $webConfig);
        self::assertStringContainsString('fileExtension=".css" allowed="true"', $webConfig);
        self::assertStringContainsString('fileExtension=".js" allowed="true"', $webConfig);
        self::assertFileExists($this->sandboxDirectory . '/index.html');
        self::assertFileExists($this->sandboxDirectory . '/extension.php');
        self::assertFileExists($this->sandboxDirectory . '/.extension.php.disabled');
        self::assertFileDoesNotExist($legacy);

        [$loaderOutput, $loaderExitCode] = $this->runLoader('request');
        self::assertSame(0, $loaderExitCode);
        self::assertSame('runner-complete', $loaderOutput);
    }

    public function testPreparingSandboxUpgradesOnlyNovamiraLegacyProtectionFiles(): void
    {
        $legacyHtaccess = "# Novamira sandbox: generated code and operational files are private.\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n";
        $legacyWebConfig = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n    <system.webServer>\n        <security>\n            <authorization>\n                <remove users=\"*\" roles=\"\" verbs=\"\" />\n                <add accessType=\"Deny\" users=\"*\" />\n            </authorization>\n        </security>\n    </system.webServer>\n</configuration>\n";
        file_put_contents($this->sandboxDirectory . '/.htaccess', $legacyHtaccess);
        file_put_contents($this->sandboxDirectory . '/web.config', $legacyWebConfig);

        [$output, $exitCode] = $this->runLoader('prepare');

        self::assertSame(0, $exitCode);
        self::assertSame('runner-complete', $output);
        self::assertStringContainsString(
            '(?:css|js)',
            (string) file_get_contents($this->sandboxDirectory . '/.htaccess'),
        );
        self::assertStringContainsString(
            'fileExtension=".css" allowed="true"',
            (string) file_get_contents($this->sandboxDirectory . '/web.config'),
        );
    }

    public function testPreparingSandboxPreservesCustomProtectionFiles(): void
    {
        file_put_contents($this->sandboxDirectory . '/.htaccess', "# custom\n");
        file_put_contents($this->sandboxDirectory . '/web.config', "<configuration><!-- custom --></configuration>\n");

        [$output, $exitCode] = $this->runLoader('prepare');

        self::assertSame(0, $exitCode);
        self::assertSame('runner-complete', $output);
        self::assertSame("# custom\n", file_get_contents($this->sandboxDirectory . '/.htaccess'));
        self::assertSame(
            "<configuration><!-- custom --></configuration>\n",
            file_get_contents($this->sandboxDirectory . '/web.config'),
        );
    }

    public function testGeneratedApacheRuleAllowsOnlyStaticAssetsAndTheDirectoryIndex(): void
    {
        [$output, $exitCode] = $this->runLoader('prepare');

        self::assertSame(0, $exitCode);
        self::assertSame('runner-complete', $output);
        $htaccess = (string) file_get_contents($this->sandboxDirectory . '/.htaccess');
        self::assertSame(1, preg_match('/<FilesMatch "(.+)">/', $htaccess, $matches));
        $rule = '#' . $matches[1] . '#';

        foreach (['style.css', 'app.js', 'STYLE.CSS', 'index.html'] as $served) {
            self::assertSame(0, preg_match($rule, $served), $served . ' should be served directly');
        }

        $denied = [
            'extension.php',
            'extension.php5',
            'extension.phtml',
            'archive.phar',
            'evil.php.css',
            'evil.PHP.css',
            'evil.phtml.js',
            'evil.phar.css',
            'evil.php.html',
            'notes.html',
            '.crashed',
            '.loading',
            '.htaccess',
            'web.config',
            '.extension.php.disabled',
        ];
        foreach ($denied as $blocked) {
            self::assertSame(1, preg_match($rule, $blocked), $blocked . ' should be blocked');
        }
    }

    public function testActivationProbeDoesNotLoadSandboxFiles(): void
    {
        file_put_contents($this->sandboxDirectory . '/extension.php', "<?php echo 'sandbox-loaded';");

        [$output, $exitCode] = $this->runLoader('activation');

        self::assertSame(0, $exitCode);
        self::assertSame('runner-complete', $output);
        self::assertFileDoesNotExist($this->sandboxDirectory . '/.crashed');
    }

    public function testExitDuringLoadingEnablesSafeModeForTheNextRequest(): void
    {
        file_put_contents($this->sandboxDirectory . '/extension.php', "<?php exit('Method not allowed');");

        [$firstOutput, $firstExitCode] = $this->runLoader('request');

        self::assertSame(0, $firstExitCode);
        self::assertSame('Method not allowed', $firstOutput);
        self::assertFileExists($this->sandboxDirectory . '/.crashed');
        self::assertStringContainsString(
            'Sandbox file terminated PHP during loading.',
            (string) file_get_contents($this->sandboxDirectory . '/.crashed'),
        );

        [$secondOutput, $secondExitCode] = $this->runLoader('request');

        self::assertSame(0, $secondExitCode);
        self::assertSame('runner-complete', $secondOutput);
    }

    public function testDisabledSidecarPreventsLoadingWithoutRenamingPhpSource(): void
    {
        $source = $this->sandboxDirectory . '/extension.php';
        file_put_contents($source, "<?php echo 'sandbox-loaded';");
        file_put_contents($this->sandboxDirectory . '/.extension.php.disabled', '');

        [$output, $exitCode] = $this->runLoader('request');

        self::assertSame(0, $exitCode);
        self::assertSame('runner-complete', $output);
        self::assertFileExists($source);
        self::assertFileDoesNotExist($source . '.disabled');
    }

    public function testStrayOutputFromASandboxFileStaysOutOfTheResponse(): void
    {
        file_put_contents($this->sandboxDirectory . '/extension.php', "<?php echo 'File OK: 42 chars';");

        [$output, $exitCode] = $this->runLoader('request');

        self::assertSame(0, $exitCode);
        self::assertSame('runner-complete', $output);
    }

    public function testSandboxFileLeavingAnOutputBufferOpenDoesNotSwallowTheResponse(): void
    {
        file_put_contents(
            $this->sandboxDirectory . '/extension.php',
            "<?php ob_start(); echo 'leftover';",
        );

        [$output, $exitCode] = $this->runLoader('buffered-request');

        self::assertSame(0, $exitCode);
        self::assertSame('response-body|ob-level:1|runner-complete', $output);
    }

    public function testSandboxFileClosingABufferItDidNotOpenDoesNotDestroyTheResponse(): void
    {
        file_put_contents($this->sandboxDirectory . '/extension.php', '<?php ob_end_clean();');

        [$output, $exitCode] = $this->runLoader('buffered-request');

        self::assertSame(0, $exitCode);
        self::assertSame('response-body|ob-level:1|runner-complete', $output);
    }

    public function testThrowableFromOneFileDoesNotStopTheOthersOrTheRequest(): void
    {
        file_put_contents($this->sandboxDirectory . '/a-throws.php', "<?php throw new \\Error('boom');");
        file_put_contents(
            $this->sandboxDirectory . '/b-loads.php',
            "<?php file_put_contents(__DIR__ . '/b-loaded', '1');",
        );

        [$output, $exitCode] = $this->runLoader('request');

        self::assertSame(0, $exitCode);
        self::assertSame('runner-complete', $output);
        self::assertFileExists($this->sandboxDirectory . '/b-loaded');
        self::assertFileExists($this->sandboxDirectory . '/.crashed');
        self::assertStringContainsString('boom', (string) file_get_contents($this->sandboxDirectory . '/.crashed'));
    }

    /** @return array{string, int} */
    private function runLoader(string $mode): array
    {
        $command = sprintf(
            '%s %s %s %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(__DIR__ . '/../fixtures/sandbox-loader-runner.php'),
            escapeshellarg($this->sandboxDirectory),
            escapeshellarg($mode),
        );
        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        return [implode("\n", $output), $exitCode];
    }
}
