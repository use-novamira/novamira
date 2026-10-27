<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class WindowsPathResolutionTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private static ?array $results = null;

    public static function tearDownAfterClass(): void
    {
        self::$results = null;
    }

    public function testWindowsDrivePathsAndUncPathsAreAbsolute(): void
    {
        self::assertSame(
            [
                'C:\\site\\wp-content' => true,
                'C:/site/wp-content' => true,
                'd:\\site' => true,
                '\\\\server\\share\\site' => true,
                '/var/www/site' => true,
                'wp-content/uploads' => false,
                'C:site' => false,
                '' => false,
            ],
            self::results()['is_absolute'],
        );
    }

    public function testWindowsDrivePathDoesNotGetAbspathPrepended(): void
    {
        $resolved = self::results()['resolved_drive_path'];
        self::assertIsString($resolved);

        $abspath = self::results()['abspath'];
        self::assertIsString($abspath);

        self::assertStringNotContainsString(rtrim($abspath, '/'), $resolved);
        self::assertStringEndsWith(
            str_replace('/', DIRECTORY_SEPARATOR, 'C:/site/wp-content/uploads/file.txt'),
            $resolved,
        );
    }

    public function testNormalizationKeepsTheDriveWithMixedSeparators(): void
    {
        $separator = DIRECTORY_SEPARATOR;

        self::assertSame(
            [
                'mixed' => "C:{$separator}site{$separator}wp-content{$separator}uploads{$separator}file.txt",
                'above_drive' => "C:{$separator}file.txt",
                'posix' => "{$separator}var{$separator}www",
            ],
            self::results()['normalized'],
        );
    }

    public function testDirectoryBoundaryAcceptsDrivePathsWithEitherSeparator(): void
    {
        self::assertSame(
            ['inside' => true, 'sibling' => false],
            self::results()['within'],
        );
    }

    /** @return array<string, mixed> */
    private static function results(): array
    {
        if (self::$results !== null) {
            return self::$results;
        }

        $script = <<<'PHP'
            $fixture = sys_get_temp_dir() . '/novamira-windows-paths-' . bin2hex(random_bytes(8));
            define('ABSPATH', $fixture . '/');

            class WP_Error {
                public function __construct(
                    private string $code,
                    private string $message,
                    private mixed $data = null,
                ) {}
            }

            function __(string $text, string $domain = 'default'): string { return $text; }
            function apply_filters(string $hook, mixed $value, mixed ...$args): mixed {
                return $hook === 'novamira_filesystem_base_dir' ? false : $value;
            }

            require $argv[1] . '/includes/helpers.php';

            $is_absolute = [];
            foreach ([
                'C:\\site\\wp-content',
                'C:/site/wp-content',
                'd:\\site',
                '\\\\server\\share\\site',
                '/var/www/site',
                'wp-content/uploads',
                'C:site',
                '',
            ] as $path) {
                $is_absolute[$path] = novamira_path_is_absolute($path);
            }

            $resolved = novamira_resolve_path('C:/site/wp-content/uploads/file.txt');

            echo json_encode([
                'abspath' => ABSPATH,
                'is_absolute' => $is_absolute,
                'resolved_drive_path' => $resolved instanceof WP_Error ? null : $resolved,
                'normalized' => [
                    'mixed' => novamira_normalize_absolute_path('C:\\site\\wp-content/../wp-content\\uploads/./file.txt'),
                    'above_drive' => novamira_normalize_absolute_path('C:\\..\\file.txt'),
                    'posix' => novamira_normalize_absolute_path('/var/../var/www/'),
                ],
                'within' => [
                    'inside' => novamira_path_is_within_directory('C:/site/wp-content/file.txt', 'C:\\site'),
                    'sibling' => novamira_path_is_within_directory('C:/site-other/file.txt', 'C:\\site'),
                ],
            ], JSON_THROW_ON_ERROR);
            PHP;

        $command = [
            PHP_BINARY,
            '-d',
            'display_errors=stderr',
            '-d',
            'error_reporting=-1',
            '-r',
            $script,
            dirname(__DIR__, levels: 2),
        ];
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
        self::$results = $decoded;

        return self::$results;
    }
}
