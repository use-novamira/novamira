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

    public function testPreparingSandboxProtectsItAndMigratesLegacyDisabledSource(): void
    {
        $legacy = $this->sandboxDirectory . '/extension.php.disabled';
        file_put_contents($legacy, "<?php echo 'sandbox-loaded';");

        [$output, $exitCode] = $this->runLoader('prepare');

        self::assertSame(0, $exitCode);
        self::assertSame('runner-complete', $output);
        self::assertFileExists($this->sandboxDirectory . '/.htaccess');
        self::assertStringContainsString(
            'Require all denied',
            (string) file_get_contents($this->sandboxDirectory . '/.htaccess'),
        );
        self::assertFileExists($this->sandboxDirectory . '/web.config');
        self::assertFileExists($this->sandboxDirectory . '/index.html');
        self::assertFileExists($this->sandboxDirectory . '/extension.php');
        self::assertFileExists($this->sandboxDirectory . '/.extension.php.disabled');
        self::assertFileDoesNotExist($legacy);

        [$loaderOutput, $loaderExitCode] = $this->runLoader('request');
        self::assertSame(0, $loaderExitCode);
        self::assertSame('runner-complete', $loaderOutput);
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
