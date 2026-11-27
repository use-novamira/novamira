<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SecretsRedactionTest extends TestCase
{
    public function testSecretsAreRedactedFromWhatReachesTheAgent(): void
    {
        $root = dirname(__DIR__, 2);
        $command =
            escapeshellarg(PHP_BINARY)
            . ' -d memory_limit=64M '
            . escapeshellarg($root . '/tests/fixtures/secrets.php')
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
        $r = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(['novamira_mcp_adapter_tool_call_result', 'rest_post_dispatch'], $r['hooks']);
        self::assertSame(['wp_set_password', 'user_register', 'profile_update'], $r['actions']);
        self::assertSame(
            "define('AUTH_KEY', '[redacted]'); db=[redacted]",
            $r['tool_result']['content'][0]['text'],
        );
        self::assertSame(['hash' => '[redacted]', 'note' => 'ok'], $r['tool_result']['structuredContent']);
        self::assertSame([['Could not connect with [redacted]'], ['status' => 500]], $r['error_redacted']);
        self::assertSame(['output' => 'key [redacted]'], $r['rest_run']);
        self::assertSame(['output' => 'key [redacted]'], $r['rest_run_any_case']);
        self::assertSame(['output' => 'key oauth-private-key-value'], $r['rest_other']);
        self::assertSame(['password' => 'Gen3rated!Pass#xy'], $r['before_set']);
        self::assertSame(
            '{"password":"[redacted] (a password set in this call)","other":"[redacted] (a password set in this call)"}',
            $r['set_passwords']['content'][0]['text'],
        );
        self::assertSame([
            'password' => '[redacted] (a password set in this call)',
            'other' => '[redacted] (a password set in this call)',
            'short' => 'short1',
            'hash' => '[redacted]',
        ], $r['set_passwords']['structuredContent']);
        self::assertSame([true, true, true, false], $r['env_files']);
        self::assertSame(
            "# comment\nAPP_KEY=[redacted]\nexport STRIPE_SECRET = [redacted]\nEMPTY=\n",
            $r['env'],
        );
        foreach ($r['pem_forms'] as $name => [$actual, $expected, $repeated]) {
            self::assertSame($expected, $actual, $name);
            self::assertSame($actual, $repeated, $name . ' is idempotent');
        }
        self::assertSame(['return_value' => [[
            'option_name' => 'novamira_oauth_private_key',
            'option_value' => '[redacted]',
            'nested' => ['row' => ['secret' => '[redacted]', 'note' => 'ordinary text']],
        ]]], $r['object_rows']);
        self::assertSame([true, true, true, true, true], $r['object_clones']);
        self::assertSame([true, true], $r['other_objects_unchanged']);
        self::assertSame([true, true, true, true, true, true], $r['object_cycle']);
        self::assertSame(['[redacted]', '[redacted]'], $r['shared_rows']);
    }
}
