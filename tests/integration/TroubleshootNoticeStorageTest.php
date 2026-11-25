<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class TroubleshootNoticeStorageTest extends TestCase
{
    public function testFreshHttpSitesDoNotQueryMissingOauthTables(): void
    {
        $result = $this->regressions(false, false);
        self::assertSame([], $result['found']);
        self::assertSame(0, $result['queries']);
    }

    public function testExistingConnectionsStillWarnAfterHttpsIsLost(): void
    {
        $result = $this->regressions(true, false);
        self::assertCount(1, $result['found']);
        self::assertSame(1, $result['queries']);
    }

    public function testWorkingHttpsTransportDoesNotNeedAConnectionQuery(): void
    {
        $result = $this->regressions(true, true);
        self::assertSame([], $result['found']);
        self::assertSame(0, $result['queries']);
    }

    private function regressions(bool $installed, bool $allowed): array
    {
        $script = <<<'PHP'
            namespace Novamira\OAuth\ClientValidation {
                function active_client_count(): int { $GLOBALS['queries']++; return 1; }
            }
            namespace {
                define('ABSPATH', '/');
                $GLOBALS['queries'] = 0;
                function novamira_app_passwords_status(): array { return ['available' => true, 'reason' => 'supported']; }
                function novamira_oauth_transport_allowed(): bool { return $GLOBALS['allowed']; }
                function get_option(string $name, mixed $default_value = false): mixed { return $GLOBALS['installed'] ? 1 : $default_value; }
                function __(string $text, string $domain): string { return $text; }
                $GLOBALS['installed'] = $argv[2] === '1';
                $GLOBALS['allowed'] = $argv[3] === '1';
                require $argv[1] . '/includes/troubleshoot/notice.php';
                echo json_encode(['found' => \Novamira\Troubleshoot\Notice\regressions(), 'queries' => $GLOBALS['queries']], JSON_THROW_ON_ERROR);
            }
            PHP;
        $command = sprintf('%s -r %s %s %s %s 2>&1',
            escapeshellarg(PHP_BINARY), escapeshellarg($script), escapeshellarg(dirname(__DIR__, 2)),
            $installed ? '1' : '0', $allowed ? '1' : '0');
        $output = (string) shell_exec($command);
        $result = json_decode($output, true);
        self::assertIsArray($result, $output);
        return $result;
    }
}
