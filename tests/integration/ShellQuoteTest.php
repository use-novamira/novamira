<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', '/');
}

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ShellQuoteTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../../includes/helpers.php';
    }

    #[DataProvider('shellValues')]
    public function testShellQuoteMatchesPosixEscapeshellarg(string $value, string $expected): void
    {
        $quoted = novamira_shell_quote($value);

        self::assertSame($expected, $quoted);
        if (DIRECTORY_SEPARATOR === '/' && function_exists('escapeshellarg')) {
            self::assertSame(escapeshellarg($value), $quoted);
        }
    }

    /** @return array<string, array{string, string}> */
    public static function shellValues(): array
    {
        return [
            'plain URL' => [
                'https://example.com/wp-json/novamira/v1/admin-access',
                "'https://example.com/wp-json/novamira/v1/admin-access'",
            ],
            'query arguments' => [
                'https://example.com/upload?foo=bar&baz=qux',
                "'https://example.com/upload?foo=bar&baz=qux'",
            ],
            'single quote' => ["https://example.com/o'brien", "'https://example.com/o'\\''brien'"],
            'double quote' => ['https://example.com/"quoted"', "'https://example.com/\"quoted\"'"],
            'space' => ['https://example.com/path with space', "'https://example.com/path with space'"],
            'dollar and backtick' => [
                'https://example.com/$value/`command`',
                "'https://example.com/\$value/`command`'",
            ],
            'empty string' => ['', "''"],
        ];
    }

    public function testShellQuoteWorksWhenEscapeshellargIsDisabled(): void
    {
        if (!function_exists('proc_open') || PHP_BINARY === '') {
            self::markTestSkipped('needs a shell-free child PHP process');
        }

        $helpers = realpath(__DIR__ . '/../../includes/helpers.php');
        self::assertIsString($helpers);

        $code = 'define("ABSPATH", "/");'
            . 'require ' . var_export($helpers, true) . ';'
            . 'echo json_encode(['
            . '"callable" => function_exists("escapeshellarg"),'
            . '"quoted" => novamira_shell_quote("https://example.com/o\'brien"),'
            . ']);';
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            [PHP_BINARY, '-d', 'disable_functions=escapeshellarg', '-r', $code],
            $descriptors,
            $pipes,
        );
        self::assertIsResource($process);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit_code = proc_close($process);

        self::assertSame(0, $exit_code, $stderr);
        self::assertSame('', trim($stderr));
        self::assertSame(
            ['callable' => false, 'quoted' => "'https://example.com/o'\\''brien'"],
            json_decode($stdout, associative: true, flags: JSON_THROW_ON_ERROR),
        );
    }
}
