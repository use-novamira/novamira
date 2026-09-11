<?php
// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', value: '/');
}

if (!function_exists('wp_parse_url')) {
    function wp_parse_url(string $url, int $component = -1): mixed
    {
        return parse_url($url, $component);
    }
}

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/connect-page.php';

final class VisualServerNameTest extends TestCase
{
    private const VISUAL_PREFIX = 'novamira-visual-';

    /**
     * @return array<string, array{string}>
     */
    public static function rootSiteUrls(): array
    {
        return [
            'long host' => ['https://www.averylongexample.com/'],
            'no trailing slash' => ['https://www.averylongexample.com'],
            'uppercase www' => ['https://WWW.Example.COM/'],
            'ip and port' => ['http://192.0.2.1:8080/'],
            'hyphen at cut' => ['https://abcdefgh-ijk.example/'],
            'punycode' => ['https://xn--mnchen-3ya.de/'],
            'short host' => ['https://a.io/'],
        ];
    }

    #[DataProvider('rootSiteUrls')]
    public function testRootSiteMatchesLegacyVisualName(string $site_url): void
    {
        $expected = self::legacyVisualName($site_url);

        self::assertSame($expected, self::visualNameForUrl($site_url));
        self::assertSame($expected, self::runWorkspace($site_url, $site_url, '')['serverName']);
    }

    public function testRootSiteLiteralNames(): void
    {
        self::assertSame('novamira-visual-averylong', self::visualNameForUrl('https://www.averylongexample.com/'));
        self::assertSame('novamira-visual-example-c', self::visualNameForUrl('https://www.example.com/'));
    }

    public function testShortSubdirectoryIsIncluded(): void
    {
        self::assertSame('novamira-visual-a-io-x', self::visualNameForUrl('https://a.io/x/'));
    }

    public function testLongSubdirectoriesOnSameHostAreDistinctAndBounded(): void
    {
        $first = self::visualNameForUrl('https://www.example.com/very-long-storefront-one/');
        $second = self::visualNameForUrl('https://www.example.com/very-long-storefront-two/');

        self::assertSame('novamira-visual-ex-850e59', $first);
        self::assertSame('novamira-visual-ex-af6550', $second);
        self::assertNotSame($first, $second);
        self::assertNotSame(
            self::visualNameForUrl('https://www.example.com/'),
            self::visualNameForUrl('https://www.example.com/shop/'),
        );
        foreach ([$first, $second, self::visualNameForUrl('https://www.example.com/shop/')] as $name) {
            self::assertLessThanOrEqual(25, strlen($name));
            self::assertMatchesRegularExpression('/^novamira-visual-[a-z0-9]+(?:-[a-z0-9]+)*$/', $name);
        }
        self::assertSame($first, self::visualNameForUrl('https://www.example.com/very-long-storefront-one'));
    }

    public function testSubdirectoryNameIgnoresSchemeWwwAndDefaultPorts(): void
    {
        $expected = self::visualNameForUrl('http://example.com/very-long-storefront-one/');

        self::assertSame($expected, self::visualNameForUrl('https://example.com/very-long-storefront-one/'));
        self::assertSame($expected, self::visualNameForUrl('https://www.example.com/very-long-storefront-one/'));
        self::assertSame($expected, self::visualNameForUrl('https://WWW.Example.com:443/very-long-storefront-one/'));
        self::assertSame($expected, self::visualNameForUrl('http://example.com:80/very-long-storefront-one/'));
        self::assertNotSame($expected, self::visualNameForUrl('https://example.com:8443/very-long-storefront-one/'));
    }

    public function testPunycodeHostWithPathHasNoDoubleHyphen(): void
    {
        $name = self::visualNameForUrl('http://xn--mnchen-3ya.de/blog');

        self::assertSame('novamira-visual-xn-e61052', $name);
        self::assertStringNotContainsString('--', $name);
    }

    public function testEmptyCutSlugEmitsPrefixAndHash(): void
    {
        self::assertMatchesRegularExpression(
            '/^novamira-visual-[0-9a-f]{6}$/',
            novamira_build_mcp_server_name_default(
                "\u{00FC}",
                site_port: null,
                site_path: "/\u{00FC}/",
                prefix: self::VISUAL_PREFIX,
            ),
        );
    }

    public function testDefaultPrefixKeepsBaseNames(): void
    {
        foreach (['https://www.averylongexample.com/', 'https://www.example.com/shop/', 'http://xn--mnchen-3ya.de/blog'] as $url) {
            $site = novamira_parse_mcp_server_site_url($url);
            self::assertNotNull($site);
            self::assertSame(
                novamira_build_mcp_server_name_default($site['host'], $site['port'], $site['path']),
                novamira_build_mcp_server_name_default($site['host'], $site['port'], $site['path'], prefix: 'novamira-'),
            );
        }
    }

    public function testWorkspaceUsesStoredHomeAndSharedHelper(): void
    {
        $result = self::runWorkspace(
            'https://www.example.com/very-long-storefront-one/',
            'https://www.example.com/very-long-storefront-one/de/',
            '',
        );

        self::assertSame('novamira-visual-ex-850e59', $result['serverName']);
        self::assertSame($result['serverName'], $result['manifestName']);
        self::assertSame('novamira-visual-a-io-x', self::runWorkspace('', 'https://a.io/x/', '')['serverName']);
    }

    public function testManifestDisplayNameUsesPlainSiteName(): void
    {
        self::assertSame('Novamira Visual: A & B', self::runWorkspace('https://a.io/', '', ' A &amp; B ')['displayName']);
        self::assertSame('Novamira Visual', self::runWorkspace('https://a.io/', '', '&nbsp;')['displayName']);
    }

    public function testManifestDisplayNameDecodesStoredTitleOnce(): void
    {
        // A title whose intended text is literally "&amp;" is stored escaped as "&amp;amp;".
        self::assertSame(
            'Novamira Visual: &amp;',
            self::runWorkspace('https://a.io/', '', '&amp;amp;')['displayName'],
        );
    }

    private static function visualNameForUrl(string $site_url): string
    {
        $site = novamira_parse_mcp_server_site_url($site_url);
        self::assertNotNull($site);
        return novamira_build_mcp_server_name_default(
            strtolower($site['host']),
            $site['port'],
            $site['path'],
            self::VISUAL_PREFIX,
        );
    }

    /**
     * The host-only Visual name every existing root-site install already uses.
     */
    private static function legacyVisualName(string $site_url): string
    {
        $host = (string) parse_url($site_url, PHP_URL_HOST);
        $host = (string) preg_replace('/^www\./', replacement: '', subject: strtolower($host !== '' ? $host : 'wordpress'));
        $slug = trim((string) preg_replace('/[^a-z0-9-]+/', replacement: '-', subject: $host), characters: '-');
        return self::VISUAL_PREFIX . rtrim(substr($slug, offset: 0, length: 9), characters: '-');
    }

    /**
     * @return array{serverName: string, manifestName: string, displayName: string}
     */
    private static function runWorkspace(string $stored_home, string $filtered_home, string $site_name): array
    {
        $command = sprintf(
            '%s %s %s %s %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(__DIR__ . '/../fixtures/visual-server-name-runner.php'),
            escapeshellarg($stored_home),
            escapeshellarg($filtered_home),
            escapeshellarg($site_name),
        );
        /** @var array{serverName: string, manifestName: string, displayName: string} */
        return json_decode((string) shell_exec($command), associative: true, flags: JSON_THROW_ON_ERROR);
    }
}
