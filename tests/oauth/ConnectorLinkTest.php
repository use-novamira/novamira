<?php
// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/connect-methods.php';

final class ConnectorLinkTest extends TestCase
{
    public function testInstallLinkEncodesParams(): void
    {
        $link = novamira_build_connector_install_link(
            mcp_url: 'https://example.com/wp-json/mcp/novamira',
            connector_name: 'Novamira - My Site',
        );
        self::assertSame(
            'https://claude.ai/customize/connectors?modal=add-custom-connector'
            . '&connectorName=Novamira%20-%20My%20Site'
            . '&connectorUrl=https%3A%2F%2Fexample.com%2Fwp-json%2Fmcp%2Fnovamira',
            $link,
        );
    }

    public function testBridgeServerCarriesTlsBypassAndUrl(): void
    {
        $server = novamira_oauth_bridge_server(
            mcp_url: 'https://test.local:8890/wp-json/mcp/novamira',
            env: ['NODE_TLS_REJECT_UNAUTHORIZED' => '0'],
        );
        self::assertSame('npx', $server['command']);
        self::assertSame(['-y', 'mcp-remote', 'https://test.local:8890/wp-json/mcp/novamira'], $server['args']);
        self::assertSame('0', $server['env']['NODE_TLS_REJECT_UNAUTHORIZED']);
    }

    public function testBridgeServerWithoutEnvOmitsEnvKey(): void
    {
        $server = novamira_oauth_bridge_server(
            mcp_url: 'https://example.com/wp-json/mcp/novamira',
            env: [],
        );
        self::assertArrayNotHasKey('env', $server);
    }

    public function testDisplayNameWithAndWithoutSiteName(): void
    {
        self::assertSame('Novamira - My Blog', novamira_build_connector_display_name('My Blog'));
        self::assertSame('Novamira - A & B', novamira_build_connector_display_name('A &amp; B'));
        self::assertSame('Novamira', novamira_build_connector_display_name('   '));
        self::assertSame('Novamira', novamira_build_connector_display_name('&nbsp;'));
    }

    public function testInvalidUtf8SiteNameFallsBackToByteSafeTrim(): void
    {
        $site_name = "Caf\xE9";

        self::assertNotSame('', novamira_plain_site_name($site_name));
        self::assertSame(trim($site_name), novamira_plain_site_name($site_name));
        self::assertNotSame('Novamira', novamira_build_connector_display_name($site_name));
        self::assertSame("ok\xC3", novamira_plain_site_name(" ok\xC3 "));
    }
}
