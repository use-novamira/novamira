<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SecretsRedactionSettingTest extends TestCase
{
    /** @var array<string, mixed> */
    private static array $result;

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2);
        $command =
            escapeshellarg(PHP_BINARY)
            . ' '
            . escapeshellarg($root . '/tests/fixtures/secrets-setting.php')
            . ' '
            . escapeshellarg($root);
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stderr);
        self::$result = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
    }

    public function testSecretsAreHiddenWhenTheSettingWasNeverSaved(): void
    {
        $this->assertHidden(self::$result['default']);
    }

    public function testTurnedOffEveryPlaceThatHidesSecretsReturnsValuesUnchanged(): void
    {
        $off = self::$result['off'];
        self::assertFalse($off['enabled']);
        self::assertSame([
            'content' => [['type' => 'text', 'text' => "define('AUTH_KEY', 'site-auth-key-value-123');"]],
            'structuredContent' => ['hash' => '$P$Babcdefghijklmnopqrstuvwxyz1234', 'password' => 'Gen3rated!Pass#xy'],
        ], $off['mcp']);
        self::assertSame([['Could not connect with database-password-456'], ['status' => 500]], $off['mcp_error']);
        self::assertTrue($off['mcp_error_is_same']);
        self::assertSame(['output' => 'db database-password-456'], $off['rest_wp_abilities']);
        self::assertSame(['output' => 'db database-password-456'], $off['rest_novamira']);
        self::assertSame(
            ['result' => 'key site-auth-key-value-123', 'nested' => ['Gen3rated!Pass#xy']],
            $off['chat_and_log'],
        );
        self::assertSame('key site-auth-key-value-123', $off['text']);
        self::assertSame("APP_KEY=abc123\nDB=database-password-456\n", $off['env_file']);
        self::assertSame("define('AUTH_KEY', 'site-auth-key-value-123');", $off['php_file']);
    }

    public function testTurnedBackOnSecretsAreHiddenAgain(): void
    {
        $this->assertHidden(self::$result['back_on']);
    }

    public function testOnlyAnExplicitOffTurnsHidingOff(): void
    {
        self::assertSame(['0', '0', '0', '1', '1', '1', '1', '1', '1'], self::$result['sanitized']);
        self::assertTrue(self::$result['unknown_value']);
    }

    /** @param array<string, mixed> $r */
    private function assertHidden(array $r): void
    {
        self::assertTrue($r['enabled']);
        self::assertSame([
            'content' => [['type' => 'text', 'text' => "define('AUTH_KEY', '[redacted]');"]],
            'structuredContent' => [
                'hash' => '[redacted]',
                'password' => '[redacted] (a password set in this call)',
            ],
        ], $r['mcp']);
        self::assertSame([['Could not connect with [redacted]'], ['status' => 500]], $r['mcp_error']);
        self::assertFalse($r['mcp_error_is_same']);
        self::assertSame(['output' => 'db [redacted]'], $r['rest_wp_abilities']);
        self::assertSame(['output' => 'db [redacted]'], $r['rest_novamira']);
        self::assertSame(
            ['result' => 'key [redacted]', 'nested' => ['[redacted] (a password set in this call)']],
            $r['chat_and_log'],
        );
        self::assertSame('key [redacted]', $r['text']);
        self::assertSame("APP_KEY=[redacted]\nDB=[redacted]\n", $r['env_file']);
        self::assertSame("define('AUTH_KEY', '[redacted]');", $r['php_file']);
    }
}
