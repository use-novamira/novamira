<?php
// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

if (!defined('ABSPATH')) {
    define('ABSPATH', '/');
}

require_once __DIR__ . '/../../includes/oauth/endpoints/discovery.php';

use function Novamira\OAuth\Endpoints\Discovery\discovery_paths;
use function Novamira\OAuth\Endpoints\Discovery\document_for_path;
use function Novamira\OAuth\Endpoints\Discovery\discovery_probes;

final class DiscoveryPathsTest extends TestCase
{
    public function testRootInstallServesTheClassicRootPaths(): void
    {
        $paths = discovery_paths(
            'https://example.test',
            'https://example.test/wp-json/mcp/novamira-oauth',
        );

        self::assertContains('/.well-known/oauth-protected-resource', $paths['protected_resource']);
        self::assertContains(
            '/.well-known/oauth-protected-resource/wp-json/mcp/novamira-oauth',
            $paths['protected_resource'],
        );
        self::assertContains('/.well-known/oauth-authorization-server', $paths['authorization_server']);
        self::assertContains('/.well-known/openid-configuration', $paths['authorization_server']);
    }

    public function testSubdirectoryInstallServesTheAdvertisedAppendForm(): void
    {
        $paths = discovery_paths(
            'https://example.com/subsite',
            'https://example.com/subsite/wp-json/mcp/novamira-oauth',
        );

        // The exact URL the WWW-Authenticate challenge advertises (home_url() + the well-known path)
        // must be answered, or the client's first discovery request 404s.
        self::assertContains('/subsite/.well-known/oauth-protected-resource', $paths['protected_resource']);
        // The canonical RFC 9728 insert form, at the domain root, is also offered.
        self::assertContains(
            '/.well-known/oauth-protected-resource/subsite/wp-json/mcp/novamira-oauth',
            $paths['protected_resource'],
        );
    }

    public function testSubdirectoryInstallServesOidcAppendWithoutABridge(): void
    {
        $paths = discovery_paths(
            'https://example.com/subsite',
            'https://example.com/subsite/wp-json/mcp/novamira-oauth',
        );

        // A spec-compliant client falls back to the OIDC append form, which this install can serve
        // directly — no root-level bridge required.
        self::assertContains('/subsite/.well-known/openid-configuration', $paths['authorization_server']);
        // The RFC 8414 insert forms live at the domain root (bridge territory) but are still listed.
        self::assertContains(
            '/.well-known/oauth-authorization-server/subsite',
            $paths['authorization_server'],
        );
        self::assertContains(
            '/.well-known/openid-configuration/subsite',
            $paths['authorization_server'],
        );
    }

    public function testUnrelatedSuffixesAreNotMatched(): void
    {
        $paths = discovery_paths(
            'https://example.com/subsite',
            'https://example.com/subsite/wp-json/mcp/novamira-oauth',
        );

        // Exact matching, not str_starts_with: a metadata URL for some other resource on the same
        // host must not be answered with this server's document.
        self::assertNotContains(
            '/.well-known/oauth-protected-resource/some-other-tenant',
            $paths['protected_resource'],
        );
        self::assertNotContains(
            '/subsite/.well-known/oauth-protected-resource/extra',
            $paths['protected_resource'],
        );
    }

    #[DataProvider('requestPaths')]
    public function testResolvesTheDocumentAPathAsksFor(string $path, ?string $expected): void
    {
        $paths = discovery_paths(
            'https://example.com/subsite',
            'https://example.com/subsite/wp-json/mcp/novamira-oauth',
        );

        self::assertSame($expected, document_for_path($path, $paths));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function requestPaths(): iterable
    {
        yield 'protected resource' => ['/subsite/.well-known/oauth-protected-resource', 'protected_resource'];
        yield 'protected resource, trailing slash' => [
            '/subsite/.well-known/oauth-protected-resource/',
            'protected_resource',
        ];
        yield 'protected resource insert form, trailing slash' => [
            '/.well-known/oauth-protected-resource/subsite/wp-json/mcp/novamira-oauth/',
            'protected_resource',
        ];
        yield 'openid configuration' => ['/subsite/.well-known/openid-configuration', 'authorization_server'];
        yield 'openid configuration, trailing slash' => [
            '/subsite/.well-known/openid-configuration/',
            'authorization_server',
        ];
        yield 'authorization server, trailing slash' => [
            '/subsite/.well-known/oauth-authorization-server/',
            'authorization_server',
        ];
        yield 'two trailing slashes' => ['/subsite/.well-known/oauth-protected-resource//', null];
        yield 'another resource, trailing slash' => ['/.well-known/oauth-protected-resource/some-other-tenant/', null];
        yield 'unrelated well-known file' => ['/.well-known/apple-app-site-association/', null];
        yield 'site root' => ['/', null];
        yield 'empty path' => ['', null];
    }

    public function testRootInstallDeduplicatesCollapsedForms(): void
    {
        $paths = discovery_paths(
            'https://example.test',
            'https://example.test/wp-json/mcp/novamira-oauth',
        );

        // With an empty home path the append and insert forms of the authorization server collapse
        // onto the same string; the set must not carry duplicates.
        self::assertSame(
            array_values(array_unique($paths['authorization_server'])),
            $paths['authorization_server'],
        );
    }

    public function testProbesForRootInstallCoverAllFormsWithoutDoubling(): void
    {
        $probes = discovery_probes(
            'https://example.test',
            'https://example.test/wp-json/mcp/novamira-oauth',
        );

        $by_url = [];
        foreach ($probes as $probe) {
            self::assertStringStartsWith('https://example.test/.well-known/', $probe['url']);
            $by_url[$probe['url']] = $probe;
        }

        // The advertised protected-resource append form is the only hard requirement.
        self::assertSame('required', $by_url['https://example.test/.well-known/oauth-protected-resource']['requirement']);
        self::assertSame(
            'optional',
            $by_url['https://example.test/.well-known/oauth-protected-resource/wp-json/mcp/novamira-oauth']['requirement'],
        );
        // OAuth and OIDC authorization-server metadata are interchangeable members of one group.
        self::assertSame('any', $by_url['https://example.test/.well-known/oauth-authorization-server']['requirement']);
        self::assertSame('any', $by_url['https://example.test/.well-known/openid-configuration']['requirement']);
        self::assertSame(
            'authorization_server',
            $by_url['https://example.test/.well-known/openid-configuration']['group'],
        );

        // The collapsed append/insert forms must not leave duplicate probes.
        $urls = array_column($probes, column_key: 'url');
        self::assertSame($urls, array_values(array_unique($urls)));
    }

    public function testProbesForSubdirectoryRequireAppendAndGroupTheAuthServerForms(): void
    {
        $probes = discovery_probes(
            'https://example.com/subsite',
            'https://example.com/subsite/wp-json/mcp/novamira-oauth',
        );

        $by_url = [];
        foreach ($probes as $probe) {
            $by_url[$probe['url']] = $probe;
        }

        // Only the advertised protected-resource append form is required.
        self::assertSame(
            'required',
            $by_url['https://example.com/subsite/.well-known/oauth-protected-resource']['requirement'],
        );
        // Its canonical insert form lands on the domain root, so it is informational here.
        self::assertSame(
            'optional',
            $by_url['https://example.com/.well-known/oauth-protected-resource/subsite/wp-json/mcp/novamira-oauth'][
                'requirement'
            ],
        );

        // Every authorization-server form is an interchangeable member of one "any" group, so a
        // single blocked form (e.g. the non-standard OAuth append) is not a failure on its own.
        $auth_server_urls = [
            'https://example.com/subsite/.well-known/openid-configuration',
            'https://example.com/subsite/.well-known/oauth-authorization-server',
            'https://example.com/.well-known/oauth-authorization-server/subsite',
            'https://example.com/.well-known/openid-configuration/subsite',
        ];
        foreach ($auth_server_urls as $url) {
            self::assertSame('any', $by_url[$url]['requirement'], $url);
            self::assertSame('authorization_server', $by_url[$url]['group'], $url);
        }
    }

    public function testProbesNeverDoublePrefixTheSubdirectory(): void
    {
        $probes = discovery_probes(
            'https://example.com/subsite',
            'https://example.com/subsite/wp-json/mcp/novamira-oauth',
        );

        foreach ($probes as $probe) {
            // The old home_url($path) bug produced /subsite/.well-known/.../subsite; the origin-based
            // build must never repeat the subdirectory both before and after the well-known marker.
            self::assertStringNotContainsString(
                '/subsite/.well-known/oauth-protected-resource/subsite',
                $probe['url'],
            );
            self::assertStringNotContainsString('/subsite/.well-known/openid-configuration/subsite', $probe['url']);
            self::assertStringNotContainsString(
                '/subsite/.well-known/oauth-authorization-server/subsite',
                $probe['url'],
            );
        }
    }

    public function testProbesCarryExactExpectedResourceAndIssuer(): void
    {
        $probes = discovery_probes(
            'https://example.com/subsite',
            'https://example.com/subsite/wp-json/mcp/novamira-oauth',
        );

        foreach ($probes as $probe) {
            if ($probe['field'] === 'resource') {
                self::assertSame('https://example.com/subsite/wp-json/mcp/novamira-oauth', $probe['expected']);
                continue;
            }
            self::assertSame('issuer', $probe['field']);
            self::assertSame('https://example.com/subsite', $probe['expected']);
        }
    }
}
