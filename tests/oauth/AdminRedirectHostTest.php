<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', '/');
}
if (!function_exists('home_url')) {
    function home_url(string $path = ''): string
    {
        return rtrim((string) ($GLOBALS['novamira_test_home'] ?? 'https://example.test'), characters: '/') . $path;
    }
}
if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        return home_url('/wp-admin/' . ltrim($path, characters: '/'));
    }
}
if (!function_exists('wp_parse_url')) {
    function wp_parse_url(string $url, int $component = -1): mixed
    {
        return parse_url($url, $component);
    }
}

use Novamira\OAuth\Endpoints\Authorize;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/oauth/endpoints/authorize.php';

/**
 * wp_validate_redirect() seeds `allowed_redirect_hosts` with the home_url() host only, while every
 * page of the authorization flow is an admin_url() (siteurl) address. The shared stubs build
 * admin_url() on `novamira_test_home`, so that global plays the siteurl here and the list passed to
 * the filter plays core's home-host seed.
 */
final class AdminRedirectHostTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['novamira_test_home'], $_SERVER['HTTP_HOST']);
    }

    public function testAllowsTheAdminHostWhenItDiffersFromTheHomeHost(): void
    {
        $GLOBALS['novamira_test_home'] = 'https://www.example.test';

        self::assertSame(['example.test', 'www.example.test'], Authorize\allow_admin_host(['example.test']));
    }

    public function testLeavesTheListUntouchedWhenTheHostsMatch(): void
    {
        $GLOBALS['novamira_test_home'] = 'https://example.test';

        self::assertSame(['example.test'], Authorize\allow_admin_host(['example.test']));
    }

    public function testNeverTrustsTheRequestHost(): void
    {
        $GLOBALS['novamira_test_home'] = 'https://example.test';
        $_SERVER['HTTP_HOST'] = 'attacker.test';

        self::assertSame(['example.test'], Authorize\allow_admin_host(['example.test']));
    }

    public function testKeepsHostsAddedByOtherFilters(): void
    {
        $GLOBALS['novamira_test_home'] = 'https://admin.example.test';

        self::assertSame(
            ['example.test', 'cdn.example.test', 'admin.example.test'],
            Authorize\allow_admin_host(['example.test', 'cdn.example.test']),
        );
    }
}
