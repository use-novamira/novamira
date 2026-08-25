<?php
// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/connect-methods.php';

final class CliSetupTest extends TestCase
{
    public function testAgentManifestUsesSafeUniqueInstallerKeys(): void
    {
        $agents = novamira_cli_agents();

        self::assertGreaterThan(60, count($agents));
        foreach ($agents as $key => $details) {
            self::assertMatchesRegularExpression('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $key);
            self::assertNotSame('', $details['label']);
            self::assertContains($details['scope'], ['global', 'project']);
        }
    }

    public function testMcpClientAliasesResolveToInstallerKeys(): void
    {
        self::assertSame('codex', novamira_cli_agent_for_client('codex-cli'));
        self::assertSame('kilo', novamira_cli_agent_for_client('kilo-code'));
        self::assertSame('roo', novamira_cli_agent_for_client('roo-code'));
        self::assertSame('cursor', novamira_cli_agent_for_client('cursor'));
        self::assertNull(novamira_cli_agent_for_client('claude-desktop'));
        self::assertNull(novamira_cli_agent_for_client('chatgpt'));
    }

    public function testGlobalInstallerPassesAgentToTheInstallerShell(): void
    {
        self::assertSame(
            'curl -fsSL https://raw.githubusercontent.com/use-novamira/novamira-cli/main/install.sh'
            . " | env NOVAMIRA_AGENT='codex' sh",
            novamira_cli_unix_install_command('codex', 'global'),
        );
        self::assertSame(
            '$env:NOVAMIRA_AGENT = \'codex\'; '
            . 'irm https://raw.githubusercontent.com/use-novamira/novamira-cli/main/install.ps1 | iex',
            novamira_cli_windows_install_command('codex', 'global'),
        );
    }

    public function testProjectOnlyInstallerDoesNotRequestGlobalSkillScope(): void
    {
        $unix = novamira_cli_unix_install_command('promptscript', 'project');
        $windows = novamira_cli_windows_install_command('promptscript', 'project');

        self::assertStringContainsString('npm install --global --ignore-scripts @novamira/cli', $unix);
        self::assertStringContainsString("--agent 'promptscript' --yes", $unix);
        self::assertStringNotContainsString('--skill novamira --global', $unix);
        self::assertStringContainsString("--agent 'promptscript' --yes", $windows);
        self::assertStringNotContainsString('--skill novamira --global', $windows);
    }

    public function testLoginCommandsCarryOnlyRequestedLocalOverrides(): void
    {
        self::assertSame(
            "novamira auth login 'https://example.com/'",
            novamira_cli_login_command('https://example.com/'),
        );
        self::assertSame(
            "NOVAMIRA_ALLOW_INSECURE_HTTP='1' NODE_TLS_REJECT_UNAUTHORIZED='0' novamira auth login 'http://site.test/'",
            novamira_cli_login_command('http://site.test/', [
                'NOVAMIRA_ALLOW_INSECURE_HTTP' => '1',
                'NODE_TLS_REJECT_UNAUTHORIZED' => '0',
            ]),
        );
        self::assertSame(
            '$env:NOVAMIRA_ALLOW_INSECURE_HTTP = \'1\'; $env:NODE_TLS_REJECT_UNAUTHORIZED = \'0\'; '
            . 'novamira auth login \'http://site.test/\'',
            novamira_cli_windows_login_command('http://site.test/', [
                'NOVAMIRA_ALLOW_INSECURE_HTTP' => '1',
                'NODE_TLS_REJECT_UNAUTHORIZED' => '0',
            ]),
        );
    }

    public function testCliTransportAllowsOnlyHttpsOrExplicitLocalDevelopment(): void
    {
        self::assertTrue(novamira_cli_transport_allowed('https://example.com/', 'production'));
        self::assertTrue(novamira_cli_transport_allowed('http://localhost:8888/', 'production'));
        self::assertTrue(novamira_cli_transport_allowed('http://127.0.0.1/', 'production'));
        self::assertTrue(novamira_cli_transport_allowed('http://site.test/', 'local'));
        self::assertFalse(novamira_cli_transport_allowed('http://example.com/', 'production'));

        self::assertFalse(novamira_cli_needs_insecure_http_override('https://site.test/', 'local'));
        self::assertFalse(novamira_cli_needs_insecure_http_override('http://localhost/', 'local'));
        self::assertTrue(novamira_cli_needs_insecure_http_override('http://site.test/', 'local'));
    }

    public function testShellValuesAreQuoted(): void
    {
        self::assertSame("'site'\\''s name'", novamira_cli_shell_quote("site's name"));
    }
}
