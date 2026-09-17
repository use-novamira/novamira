<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The OAuth discovery documents only exist while AI abilities are enabled and locked to the current
 * domain. A page cache that stored the answer from before an option changed keeps serving it: the
 * observed failure is a 404 cached while abilities were off, still served after enabling them, so
 * the AI client concludes the site does not support OAuth.
 *
 * Each scenario runs in its own PHP process, because whether a cache plugin is installed is decided
 * by which global functions, actions and classes exist before anything loads.
 */
final class CachePurgeTest extends TestCase
{
    private const DISCOVERY_URLS = [
        'https://example.test/.well-known/oauth-protected-resource',
        'https://example.test/.well-known/oauth-protected-resource/wp-json/mcp/novamira-oauth',
        'https://example.test/.well-known/openid-configuration',
        'https://example.test/.well-known/oauth-authorization-server',
    ];

    private const CACHES = [
        'litespeed-cache',
        'cache-enabler',
        'wp-fastest-cache',
        'wp-rocket',
        'w3-total-cache',
        'wp-super-cache',
        'nginx-helper',
        'sg-cachepress',
    ];

    public function testEnablingInvalidatesEveryDiscoveryUrlOnEveryCache(): void
    {
        $result = $this->runScenario('all-caches');

        self::assertSame(self::DISCOVERY_URLS, $result['urls']);
        foreach (self::CACHES as $cache) {
            self::assertSame(self::DISCOVERY_URLS, $result['calls'][$cache] ?? [], $cache);
        }
        // The escape hatch for a cache this list does not cover receives the same URLs.
        self::assertSame(self::DISCOVERY_URLS, $result['calls']['novamira_purged_discovery_urls']);
    }

    /**
     * Two writes of the same value: core's update_option() returns early when the stored value is
     * unchanged, so the second one purges nothing. Together with the single purge event this is
     * what keeps the cache from being dropped on requests that changed nothing.
     */
    public function testAnEffectiveChangePurgesExactlyOnce(): void
    {
        $result = $this->runScenario('all-caches');

        self::assertSame(1, $result['purge_events']);
    }

    public function testWritingAnUnrelatedOptionPurgesNothing(): void
    {
        $result = $this->runScenario('unrelated-option');

        self::assertSame(0, $result['purge_events']);
        self::assertSame([], $result['calls']);
    }

    /**
     * Disabling stores `novamira_ai_abilities_enabled` and deletes `novamira_ai_abilities_domain`.
     * Both are covered — deletion included — and the two writes share a single purge.
     */
    public function testDisablingCoversBothOptionsInOnePurge(): void
    {
        $result = $this->runScenario('multiple-writes');

        self::assertSame(1, $result['purge_events']);
        self::assertSame(self::DISCOVERY_URLS, $result['calls']['litespeed-cache']);
    }

    public function testAWriteBeforePluginsLoadedWaitsForTheCachesToRegister(): void
    {
        $result = $this->runScenario('early-write');

        self::assertTrue($result['deferred_before_plugins_loaded']);
        self::assertSame(1, $result['purge_events']);
        self::assertSame(self::DISCOVERY_URLS, $result['calls']['litespeed-cache']);
    }

    public function testNoCachePluginMeansNoCallsAndNoError(): void
    {
        $result = $this->runScenario('no-caches');

        self::assertSame(1, $result['purge_events']);
        self::assertSame(['novamira_purged_discovery_urls'], array_keys($result['calls']));
    }

    /**
     * The other half of the fix: while abilities are off the discovery paths 404, and a cache that
     * stores that 404 is the reason a purge is needed at all. Refusing to cache these paths means
     * there is nothing stale to purge, including on caches that expose no per-URL purge.
     */
    public function testDiscoveryRequestsAreKeptOutOfPageCaches(): void
    {
        $result = $this->runScenario('discovery-request');

        self::assertTrue($result['donotcachepage']);
        self::assertSame(1, $result['litespeed_nocache']);
        self::assertSame(['Cache-Control: no-store, max-age=0'], $result['headers']);
    }

    public function testDiscoveryRequestsAreRecognizedOnASubdirectoryInstall(): void
    {
        $result = $this->runScenario('subdirectory-request');

        self::assertTrue($result['donotcachepage']);
        self::assertSame(['Cache-Control: no-store, max-age=0'], $result['headers']);
    }

    public function testOtherRequestsKeepTheirCaching(): void
    {
        $result = $this->runScenario('unrelated-request');

        self::assertFalse($result['donotcachepage']);
        self::assertSame(0, $result['litespeed_nocache']);
        self::assertSame([], $result['headers']);
    }

    /** @return array<string, mixed> */
    private function runScenario(string $scenario): array
    {
        $root = dirname(__DIR__, levels: 2);
        $command = [PHP_BINARY, $root . '/tests/fixtures/cache-purge-runner.php', $root, $scenario];
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
