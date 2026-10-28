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
if (!function_exists('add_query_arg')) {
    function add_query_arg(array|string $key, mixed $value = '', string $url = ''): string
    {
        $args = is_array($key) ? $key : [$key => $value];
        $target = is_array($key) ? (string) $value : $url;
        $query = [];
        parse_str((string) parse_url($target, PHP_URL_QUERY), $query);
        foreach ($args as $name => $argument) {
            $query[(string) $name] = $argument;
        }

        $base = strtok($target, '?');
        $base = $base === false ? $target : $base;
        return $query === [] ? $base : $base . '?' . http_build_query($query);
    }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash(mixed $value): mixed
    {
        return is_string($value) ? stripslashes($value) : $value;
    }
}

use Novamira\OAuth\Endpoints\Discovery;
use Novamira\OAuth\Endpoints\LegacyFallback;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/oauth/endpoints/discovery.php';
require_once __DIR__ . '/../../includes/oauth/endpoints/legacy-fallback.php';

final class HiddenLoginAuthorizeTest extends TestCase
{
    private const ADMIN_PAGE = 'https://example.test/wp-admin/admin.php?page=novamira-oauth-authorize';

    #[DataProvider('defaultLoginUrls')]
    public function testDefaultLoginKeepsTheWpAdminEndpoint(string $login_url): void
    {
        self::assertSame(self::ADMIN_PAGE, Discovery\authorization_endpoint($login_url));
    }

    /** @return iterable<string, array{string}> */
    public static function defaultLoginUrls(): iterable
    {
        yield 'root install' => ['https://example.test/wp-login.php'];
        yield 'subdirectory install' => ['https://example.test/wp/wp-login.php'];
        yield 'with a query' => ['https://example.test/wp-login.php?action=login'];
    }

    #[DataProvider('customLoginUrls')]
    public function testCustomLoginAdvertisesTheFrontEndEntry(string $login_url): void
    {
        self::assertSame(
            'https://example.test/?novamira_oauth=authorize',
            Discovery\authorization_endpoint($login_url),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function customLoginUrls(): iterable
    {
        yield 'WPS Hide Login' => ['https://example.test/secret-login/'];
        yield 'login without trailing slash' => ['https://example.test/my-login'];
        yield 'lookalike file name' => ['https://example.test/not-wp-login.php.html'];
    }

    #[DataProvider('frontEntryRequests')]
    public function testRecognizesOnlyTheFrontEndAuthorizeEntry(array $query, string $method, bool $expected): void
    {
        self::assertSame($expected, LegacyFallback\is_front_authorize_request($query, $method));
    }

    /** @return iterable<string, array{array<string, mixed>, string, bool}> */
    public static function frontEntryRequests(): iterable
    {
        yield 'entry' => [['novamira_oauth' => 'authorize', 'client_id' => 'x'], 'GET', true];
        yield 'lowercase method' => [['novamira_oauth' => 'authorize'], 'get', true];
        yield 'folded parameters' => [['novamira_oauth' => 'authorize?response_type=code'], 'GET', true];
        yield 'POST' => [['novamira_oauth' => 'authorize'], 'POST', false];
        yield 'other value' => [['novamira_oauth' => 'token'], 'GET', false];
        yield 'prefix lookalike' => [['novamira_oauth' => 'authorized'], 'GET', false];
        yield 'array value' => [['novamira_oauth' => ['authorize']], 'GET', false];
        yield 'absent' => [['client_id' => 'x'], 'GET', false];
    }

    public function testForwardsOnlyAuthorizationParametersIncludingFoldedOnes(): void
    {
        self::assertSame(
            [
                'response_type' => 'code',
                'client_id' => 'abc',
                'redirect_uri' => 'http://127.0.0.1:5555/cb',
                'state' => 's',
            ],
            LegacyFallback\authorization_parameters([
                'novamira_oauth' => 'authorize?response_type=code&client_id=ignored&state=s',
                'client_id' => 'abc',
                'redirect_uri' => 'http://127.0.0.1:5555/cb',
                'unrelated' => 'dropped',
            ]),
        );
    }
}
