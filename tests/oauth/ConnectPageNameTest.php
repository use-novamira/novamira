<?php
// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', '/');
}

if (!function_exists('wp_parse_url')) {
    function wp_parse_url(string $url, int $component = -1): mixed
    {
        return parse_url($url, $component);
    }
}

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/connect-page.php';

final class ConnectPageNameTest extends TestCase
{
    public function testRootSiteRetainsHostOnlyDefault(): void
    {
        self::assertSame(
            'novamira-averylongexample',
            self::serverNameForUrl('https://www.averylongexample.com/'),
        );
        self::assertSame(
            'novamira-averylongexample',
            self::serverNameForUrl('https://www.averylongexample.com'),
        );
    }

    public function testRootSiteRetainsLegacyNormalizationEdgeCases(): void
    {
        self::assertSame('novamira-www-example-com', self::serverNameForUrl('https://WWW.Example.COM/'));
        self::assertSame('novamira-192-0-2-1', self::serverNameForUrl('http://192.0.2.1:8080/'));
        self::assertSame('novamira-abcdefghijklmno', self::serverNameForUrl('https://abcdefghijklmno.example/'));
        self::assertSame('novamira-xn--mnchen-3ya-d', self::serverNameForUrl('https://xn--mnchen-3ya.de/'));
    }

    public function testShortSubdirectoryIsIncludedInDefault(): void
    {
        self::assertSame('novamira-example-com-shop', self::serverNameForUrl('https://www.example.com/shop/'));
    }

    public function testLongSubdirectoriesUseDifferentBoundedDefaults(): void
    {
        $first = self::serverNameForUrl('https://www.example.com/very-long-storefront-one/');
        $second = self::serverNameForUrl('https://www.example.com/very-long-storefront-two/');

        self::assertNotSame($first, $second);
        self::assertLessThanOrEqual(25, strlen($first));
        self::assertLessThanOrEqual(25, strlen($second));
        self::assertMatchesRegularExpression('/^novamira-[a-z0-9]+(?:-[a-z0-9]+)*$/', $first);
        self::assertMatchesRegularExpression('/^novamira-[a-z0-9]+(?:-[a-z0-9]+)*$/', $second);
        self::assertSame($first, self::serverNameForUrl('https://www.example.com/very-long-storefront-one'));
    }

    public function testLongSubdirectoryHashIgnoresSchemeAndWwwPrefix(): void
    {
        $expected = self::serverNameForUrl('http://example.com/very-long-storefront-one/');

        self::assertSame($expected, self::serverNameForUrl('https://example.com/very-long-storefront-one/'));
        self::assertSame($expected, self::serverNameForUrl('https://www.example.com/very-long-storefront-one/'));
    }

    public function testLongSubdirectoryHashNormalizesDefaultPorts(): void
    {
        $expected = self::serverNameForUrl('https://example.com/very-long-storefront-one/');

        self::assertSame($expected, self::serverNameForUrl('https://example.com:443/very-long-storefront-one/'));
        self::assertSame($expected, self::serverNameForUrl('http://example.com:80/very-long-storefront-one/'));
    }

    public function testLongSubdirectoryHashIncludesNonDefaultExplicitPort(): void
    {
        self::assertNotSame(
            self::serverNameForUrl('https://example.com:8443/very-long-storefront-one/'),
            self::serverNameForUrl('https://example.com:9443/very-long-storefront-one/'),
        );
    }

    public function testSubdirectorySlugCollapsesHyphenRuns(): void
    {
        self::assertSame('novamira-ex-com-my-shop', self::serverNameForUrl('http://ex.com/my-_shop'));
        self::assertSame(
            'novamira-xn-mnchen-e61052',
            self::serverNameForUrl('http://xn--mnchen-3ya.de/blog'),
        );
    }

    public function testStoredHomeOptionWinsOverFilteredHomeUrl(): void
    {
        self::assertSame(
            'novamira-example-com',
            self::serverNameFromWordPressUrls('https://example.com/', 'https://example.com/de/'),
        );
        self::assertSame(
            'novamira-example-com-shop',
            self::serverNameFromWordPressUrls('not a URL', 'https://example.com/shop/'),
        );
        self::assertSame(
            'novamira-example-com-shop',
            self::serverNameFromWordPressUrls('', 'https://example.com/shop/'),
        );
        self::assertSame(
            'novamira-example-com',
            self::serverNameFromWordPressUrls('https://example.com/', 'https://example.com'),
        );
    }

    public function testPlainSiteNameDecodesHtmlEntities(): void
    {
        self::assertSame('A & B', novamira_plain_site_name('A &amp; B'));
        self::assertSame('', novamira_plain_site_name('&nbsp;'));
    }

    private static function serverNameForUrl(string $site_url): string
    {
        $site = novamira_parse_mcp_server_site_url($site_url);
        self::assertNotNull($site);
        return novamira_build_mcp_server_name_default($site['host'], $site['port'], $site['path']);
    }

    private static function serverNameFromWordPressUrls(string $stored_home, string $filtered_home): string
    {
        $command = sprintf(
            '%s %s %s %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(__DIR__ . '/../fixtures/connect-page-name-runner.php'),
            escapeshellarg($stored_home),
            escapeshellarg($filtered_home),
        );
        return trim((string) shell_exec($command));
    }
}
