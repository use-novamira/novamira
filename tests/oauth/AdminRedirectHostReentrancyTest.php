<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * allow_admin_host() calls admin_url(), which runs the `site_url` filter, and a filter that consults
 * the referer validates it through wp_validate_redirect(), which applies `allowed_redirect_hosts`
 * again. These scenarios need an admin_url() that runs filters and fresh guard state, neither of
 * which the shared stubs of this process can offer, so they run in a child process.
 */
final class AdminRedirectHostReentrancyTest extends TestCase
{
    public function testTheCycleTerminatesAndTheRedirectIsStillAllowed(): void
    {
        $location = 'https://admin.example.test/wp-admin/admin.php?page=novamira-oauth-consent';

        $result = self::runScenario('validate_admin_redirect');

        self::assertSame($location, $result['validated']);
        self::assertSame(1, $result['admin_url_calls']);
        self::assertSame(1, $result['site_url_filter_calls']);
    }

    public function testTheReenteredCallDoesNotPoisonTheOuterResult(): void
    {
        $result = self::runScenario('validate_admin_redirect');

        // In order of completion: the re-entered run leaves the seed alone, the outer one adds the host.
        self::assertSame([['example.test'], ['example.test', 'admin.example.test']], $result['host_lists']);
    }

    public function testAdminUrlCalledFromAnywhereStaysBounded(): void
    {
        $result = self::runScenario('admin_url_with_admin_referer');

        self::assertSame('https://admin.example.test/wp-admin/admin.php', $result['url']);
        // The caller's own call, plus the one the callback makes to read the host.
        self::assertSame(2, $result['admin_url_calls']);
        self::assertSame(2, $result['site_url_filter_calls']);
        self::assertSame([['example.test'], ['example.test', 'admin.example.test']], $result['host_lists']);
    }

    public function testTheFilterStillSeesAnAdminHostRefererOutsideTheReenteredCall(): void
    {
        $result = self::runScenario('admin_url_with_admin_referer');

        // In order of completion. Only the validation nested inside the callback's own admin_url()
        // runs without the admin host; the validation the filter started from the caller's call has it.
        self::assertSame([false, 'https://admin.example.test/wp-admin/edit.php'], $result['referers']);
    }

    public function testTheRequestNeverAddsAHost(): void
    {
        $result = self::runScenario('foreign_referer');

        self::assertSame([['example.test'], ['example.test', 'admin.example.test']], $result['host_lists']);
        self::assertSame([false, false], $result['referers']);
    }

    public function testTheListIsUnchangedWhenTheHostsMatch(): void
    {
        $result = self::runScenario('hosts_match');

        self::assertSame('https://example.test/page/', $result['validated']);
        self::assertSame(1, $result['admin_url_calls']);
        self::assertSame([['example.test'], ['example.test']], $result['host_lists']);
    }

    public function testASecondCallComputesTheHostAfresh(): void
    {
        $result = self::runScenario('admin_host_changes_between_calls');

        self::assertSame(['example.test', 'admin.example.test'], $result['first']);
        self::assertSame(['example.test', 'other-admin.example.test'], $result['second']);
        self::assertSame(2, $result['admin_url_calls']);
    }

    public function testTheGuardResetsWhenAdminUrlThrows(): void
    {
        $result = self::runScenario('admin_url_throws_then_recovers');

        self::assertSame('admin_url failed', $result['thrown']);
        self::assertSame(['example.test', 'admin.example.test'], $result['after']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function runScenario(string $scenario): array
    {
        $command = sprintf(
            '%s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(__DIR__ . '/../fixtures/admin-host-reentrancy-runner.php'),
            escapeshellarg($scenario),
        );
        $output = (string) shell_exec($command);

        $result = json_decode($output, associative: true);
        self::assertIsArray($result, 'The runner did not print JSON: ' . $output);
        self::assertArrayNotHasKey('error', $result, (string) ($result['error'] ?? ''));

        return $result;
    }
}
