<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BackendToolsSystemAbilitiesTest extends TestCase
{
    public function testSystemAbilitiesAreNotPublicBackendTools(): void
    {
        $result = $this->runIntegrationScript();

        self::assertFalse($result['scoped_adapter']);
        self::assertFalse($result['legacy_adapter']);
        self::assertTrue($result['novamira_tool']);
    }

    /** @return array<string, bool> */
    private function runIntegrationScript(): array
    {
        $root = dirname(__DIR__, levels: 2);
        $script = <<<'PHP'
            define('ABSPATH', '/');

            class WP_Ability {
                public function __construct(private string $name) {}
                public function get_name(): string { return $this->name; }
                public function get_meta(): array {
                    return ['mcp' => ['public' => true, 'type' => 'tool']];
                }
            }

            function add_action(string $hook, callable|string $callback): bool { return true; }

            require $argv[1] . '/novamira-visual/includes/BackendTools.php';

            echo json_encode([
                'scoped_adapter' => \Novamira\Visual\BackendTools\is_public_backend_tool(
                    new WP_Ability('novamira-mcp-adapter/discover-abilities'),
                ),
                'legacy_adapter' => \Novamira\Visual\BackendTools\is_public_backend_tool(
                    new WP_Ability('mcp-adapter/discover-abilities'),
                ),
                'novamira_tool' => \Novamira\Visual\BackendTools\is_public_backend_tool(
                    new WP_Ability('novamira/execute-php'),
                ),
            ], JSON_THROW_ON_ERROR);
            PHP;

        $command = [PHP_BINARY, '-r', $script, $root];
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

        return $decoded;
    }
}
