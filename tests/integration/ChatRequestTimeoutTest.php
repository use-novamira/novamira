<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Chat model requests wait longer than the AI Client's 30-second default, and a request that times out
 * reports a readable message instead of the raw transport error.
 */
final class ChatRequestTimeoutTest extends TestCase
{
    private const TIMEOUT_MESSAGE = 'The AI provider took too long to respond. The steps already completed are saved: send another message to continue.';

    public function testChatRaisesTheDefaultTimeoutOnlyForItsOwnBuilder(): void
    {
        $result = self::runScenario('default');

        self::assertSame(120, (int) $result['timeout']);
        // Builders created after the Chat one keep the core default.
        self::assertSame(30, (int) $result['after']);
    }

    /**
     * Any site callback on the hook, at any priority and whatever it returns (even the core default, or
     * 0 for no timeout), keeps the site in control: Chat does not attach its own callback.
     */
    public function testAnySiteFilterKeepsTheSiteTimeout(): void
    {
        $cases = [
            'site-earliest-30' => 30,
            'site-earliest-45' => 45,
            'site-earliest-0' => 0,
            'site-earliest-300' => 300,
            'site-default-45' => 45,
            'site-latest-300' => 300,
        ];

        foreach ($cases as $scenario => $expected) {
            $result = self::runScenario($scenario);
            self::assertSame($expected, (int) $result['timeout'], $scenario);
            self::assertFalse($result['chat_attached'], $scenario);
        }
    }

    public function testTheFilterIsRemovedWhenTheBuilderThrows(): void
    {
        self::assertSame(30, (int) self::runScenario('throws')['after']);
    }

    public function testOnlyNetworkTimeoutsAreReworded(): void
    {
        $result = self::runScenario('errors');

        foreach (['wp_error_timeout', 'wp_error_timed_out', 'exception_timeout'] as $case) {
            self::assertTrue($result[$case]['timeout'], $case);
            self::assertSame(self::TIMEOUT_MESSAGE, $result[$case]['message'], $case);
        }

        self::assertFalse($result['wp_error_network_other']['timeout']);
        self::assertStringContainsString('cURL error 6', $result['wp_error_network_other']['message']);
        self::assertFalse($result['wp_error_other_code']['timeout']);
        self::assertSame('Request timed out on the client side.', $result['wp_error_other_code']['message']);
        self::assertFalse($result['exception_other']['timeout']);
        self::assertSame('cURL error 28: Operation timed out', $result['exception_other']['message']);

        // The raw transport error stays in the PHP error log for debugging.
        self::assertStringContainsString('cURL error 28: Operation timed out after 30001 milliseconds', $result['log']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function runScenario(string $scenario): array
    {
        $root = dirname(__DIR__, levels: 2);
        $command = [PHP_BINARY, $root . '/tests/fixtures/chat-request-timeout-runner.php', $root, $scenario];
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
        self::assertArrayNotHasKey('unknown_scenario', $decoded);

        return $decoded;
    }
}
