<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * execute-php unwinds only the output buffers it opens, so code that leaves a buffer open cannot leak
 * its output ahead of the REST response, and code that closes a buffer it never opened cannot take
 * the buffer belonging to WordPress or the host.
 */
final class ExecutePhpOutputBuffersTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private static ?array $results = null;

    public static function tearDownAfterClass(): void
    {
        self::$results = null;
    }

    public function testBufferLeftOpenByTheCodeIsCapturedAndTheOuterBufferIsUntouched(): void
    {
        self::assertSame(
            [
                'output' => 'beforeinner',
                'error_message' => 'boom',
                'level' => 1,
                'outer' => 'response-body',
            ],
            self::results()['left_open'],
        );
    }

    public function testBufferClosedByTheCodeDoesNotTakeTheOuterBuffer(): void
    {
        self::assertSame(
            [
                'output' => '',
                'return_value' => 2,
                'level' => 1,
                'outer' => 'response-body',
            ],
            self::results()['closed_extra'],
        );
    }

    /** @return array<string, mixed> */
    private static function results(): array
    {
        if (self::$results !== null) {
            return self::$results;
        }

        $script = <<<'PHP'
            define('ABSPATH', sys_get_temp_dir() . '/');
            define('NOVAMIRA_MAX_EXECUTION_TIME', 30);

            function __(string $text, string $domain = 'default'): string { return $text; }
            function wp_register_ability(string $name, array $args): void {}

            require $argv[1] . '/includes/abilities/execute-php.php';

            $results = [];

            // Stands in for the buffer WordPress or the host holds around the REST response.
            ob_start();
            echo 'response-body';

            $left_open = novamira_execute_php([
                'code' => "echo 'before'; ob_start(); echo 'inner'; throw new RuntimeException('boom');",
            ]);
            $results['left_open'] = [
                'output' => $left_open['output'],
                'error_message' => $left_open['error_message'] ?? null,
                'level' => ob_get_level(),
                'outer' => ob_get_contents(),
            ];
            while (ob_get_level() > 1) {
                ob_end_clean();
            }

            $closed_extra = novamira_execute_php(['code' => 'ob_end_clean(); return 2;']);
            $level = ob_get_level();
            $results['closed_extra'] = [
                'output' => $closed_extra['output'],
                'return_value' => $closed_extra['return_value'],
                'level' => $level,
                'outer' => $level > 0 ? ob_get_contents() : null,
            ];

            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            echo json_encode($results, JSON_THROW_ON_ERROR);
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
