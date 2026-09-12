<?php
// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!defined('ABSPATH')) {
    define('ABSPATH', '/');
}

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}
if (!function_exists('home_url')) {
    function home_url(string $path = ''): string
    {
        return ((string) ($GLOBALS['novamira_test_home'] ?? 'https://example.test')) . $path;
    }
}
if (!function_exists('rest_url')) {
    function rest_url(string $path = ''): string
    {
        return 'https://example.test/wp-json/' . ltrim($path, characters: '/');
    }
}
if (!function_exists('novamira_likely_self_signed_https')) {
    function novamira_likely_self_signed_https(): bool
    {
        return false;
    }
}

// The suite shares one WP_Error stub across test files, and whichever file loads first defines it.
// Keep the full accessor set here so the tests that read the code or data (MiddlewareTest) work
// regardless of load order.
if (!class_exists('WP_Error')) {
    class WP_Error
    {
        /** @param array<string, mixed> $data */
        public function __construct(
            private string $code = '',
            private string $message = '',
            private array $data = [],
        ) {
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }

        /** @return array<string, mixed> */
        public function get_error_data(): array
        {
            return $this->data;
        }
    }
}

// The self-probe HTTP layer, driven by $GLOBALS['novamira_test_http']: a URL => response-array map.
// A response-array carries code/content-type/body/headers; ['error' => msg] models a transport
// failure; a URL absent from the map answers HTTP 404, as an unconfigured domain root would.
if (!function_exists('wp_remote_get')) {
    function wp_remote_get(string $url, array $args = []): mixed
    {
        /** @var array<string, array<string, mixed>> $map */
        $map = $GLOBALS['novamira_test_http'] ?? [];
        if (!array_key_exists($url, $map)) {
            return ['code' => 404, 'content-type' => 'text/html', 'body' => 'not found', 'headers' => []];
        }
        $entry = $map[$url];
        if (array_key_exists('error', $entry)) {
            return new WP_Error('http_request_failed', (string) $entry['error']);
        }
        return $entry;
    }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error(mixed $thing): bool
    {
        return $thing instanceof WP_Error;
    }
}
if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code(mixed $response): int
    {
        return is_array($response) ? (int) ($response['code'] ?? 0) : 0;
    }
}
if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body(mixed $response): string
    {
        return is_array($response) ? (string) ($response['body'] ?? '') : '';
    }
}
if (!function_exists('wp_remote_retrieve_header')) {
    function wp_remote_retrieve_header(mixed $response, string $header = ''): string
    {
        return is_array($response) ? (string) ($response[strtolower($header)] ?? '') : '';
    }
}
if (!function_exists('wp_remote_retrieve_headers')) {
    function wp_remote_retrieve_headers(mixed $response): array
    {
        return is_array($response) && is_array($response['headers'] ?? null) ? $response['headers'] : [];
    }
}

require_once __DIR__ . '/../../includes/oauth/bootstrap.php';
require_once __DIR__ . '/../../includes/oauth/endpoints/discovery.php';
require_once __DIR__ . '/../../includes/troubleshoot/checks.php';

use function Novamira\Troubleshoot\Checks\check_discovery;

/**
 * Behavioral tests for check_discovery() on a root install, where the served forms line up with the
 * probe URLs. The subdirectory URL construction is covered separately by DiscoveryPathsTest.
 */
final class CheckDiscoveryTest extends TestCase
{
    private const PR_APPEND = 'https://example.test/.well-known/oauth-protected-resource';
    private const PR_INSERT = 'https://example.test/.well-known/oauth-protected-resource/wp-json/mcp/novamira-oauth';
    private const AS_OIDC = 'https://example.test/.well-known/openid-configuration';
    private const AS_OAUTH = 'https://example.test/.well-known/oauth-authorization-server';
    private const RESOURCE = 'https://example.test/wp-json/mcp/novamira-oauth';
    private const ISSUER = 'https://example.test';

    protected function setUp(): void
    {
        $GLOBALS['novamira_test_home'] = 'https://example.test';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['novamira_test_home'], $GLOBALS['novamira_test_http']);
    }

    public function testAllDocumentsPresentPasses(): void
    {
        self::assertSame('ok', $this->run_discovery($this->valid_map()));
    }

    public function testContentTypeWithoutCharsetIsAccepted(): void
    {
        $map = $this->valid_map();
        $map[self::PR_APPEND] = $this->json(['resource' => self::RESOURCE], content_type: 'application/json');
        self::assertSame('ok', $this->run_discovery($map));
    }

    public function testRequiredProtectedResourceMissingFails(): void
    {
        $map = $this->valid_map();
        unset($map[self::PR_APPEND]); // answers 404
        self::assertSame('fail', $this->run_discovery($map));
    }

    public function testOptionalInsertFormMissingStillPasses(): void
    {
        $map = $this->valid_map();
        unset($map[self::PR_INSERT]); // 404 on the informational canonical insert form
        self::assertSame('ok', $this->run_discovery($map));
    }

    public function testWrongContentTypeOnRequiredFails(): void
    {
        $map = $this->valid_map();
        $map[self::PR_APPEND] = $this->json(['resource' => self::RESOURCE], content_type: 'text/html');
        self::assertSame('fail', $this->run_discovery($map));
    }

    public function testWrongResourceValueFails(): void
    {
        $map = $this->valid_map();
        $map[self::PR_APPEND] = $this->json(['resource' => 'https://evil.example/wp-json/mcp/novamira-oauth']);
        self::assertSame('fail', $this->run_discovery($map));
    }

    public function testWrongIssuerValueFails(): void
    {
        // Both authorization-server forms carry a foreign issuer, so the whole group is unsatisfied.
        $map = $this->valid_map();
        $map[self::AS_OIDC] = $this->json(['issuer' => 'https://evil.example']);
        $map[self::AS_OAUTH] = $this->json(['issuer' => 'https://evil.example']);
        self::assertSame('fail', $this->run_discovery($map));
    }

    public function testAuthServerFallbackWhenOidcFormBlocked(): void
    {
        // A compliant client can still finish through the OAuth form, so a blocked OIDC form alone is
        // not a failure.
        $map = $this->valid_map();
        unset($map[self::AS_OIDC]); // 404
        self::assertSame('ok', $this->run_discovery($map));
    }

    public function testAuthServerFallbackWhenOauthFormBlocked(): void
    {
        // The mirror case: the non-standard OAuth append form blocked, OIDC append answering.
        $map = $this->valid_map();
        unset($map[self::AS_OAUTH]); // 404
        self::assertSame('ok', $this->run_discovery($map));
    }

    public function testAuthServerFailsWhenAllFormsBlocked(): void
    {
        $map = $this->valid_map();
        unset($map[self::AS_OIDC], $map[self::AS_OAUTH]); // both 404
        self::assertSame('fail', $this->run_discovery($map));
    }

    public function testRedirectOnRequiredFails(): void
    {
        $map = $this->valid_map();
        $map[self::PR_APPEND] = $this->redirect();
        self::assertSame('fail', $this->run_discovery($map));
    }

    public function testEdgeRedirectPointsAtHosting(): void
    {
        $map = $this->valid_map();
        $map[self::PR_APPEND] = $this->redirect();
        $result = $this->run_check($map);

        self::assertSame('fail', $result['status']);
        self::assertStringContainsString('redirected by the server', $result['message']);
        self::assertStringContainsString('hosting support', $result['remedy']);
    }

    public function testWordPressRedirectPointsAtTheSiteNotHosting(): void
    {
        // wp_redirect() stamps X-Redirect-By, so the rule lives in a plugin on the site. Telling the
        // owner to call hosting here sends them after a rule hosting cannot see.
        $map = $this->valid_map();
        $map[self::PR_APPEND] = $this->redirect(redirect_by: 'WordPress');
        $result = $this->run_check($map);

        self::assertSame('fail', $result['status']);
        self::assertStringContainsString('from inside WordPress', $result['message']);
        self::assertStringContainsString('WordPress"', $result['message']);
        self::assertStringContainsString('redirect rules', $result['remedy']);
        self::assertStringNotContainsString('hosting support', $result['remedy']);
    }

    public function testTransportErrorOnRequiredFails(): void
    {
        $map = $this->valid_map();
        $map[self::PR_APPEND] = ['error' => 'cURL error 7: connection refused'];
        self::assertSame('fail', $this->run_discovery($map));
    }

    /**
     * @param array<string, array<string, mixed>> $http
     */
    private function run_discovery(array $http): string
    {
        return $this->run_check($http)['status'];
    }

    /**
     * @param array<string, array<string, mixed>> $http
     * @return array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string}
     */
    private function run_check(array $http): array
    {
        $GLOBALS['novamira_test_http'] = $http;
        $headers = [];
        return check_discovery($headers);
    }

    /**
     * A redirect response, optionally carrying the X-Redirect-By header wp_redirect() stamps.
     *
     * @return array<string, mixed>
     */
    private function redirect(string $redirect_by = ''): array
    {
        $response = [
            'code' => 301,
            'location' => 'https://example.test/',
            'content-type' => 'text/html',
            'body' => '',
            'headers' => [],
        ];
        if ($redirect_by !== '') {
            $response['x-redirect-by'] = $redirect_by;
        }
        return $response;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function valid_map(): array
    {
        return [
            self::PR_APPEND => $this->json(['resource' => self::RESOURCE]),
            self::PR_INSERT => $this->json(['resource' => self::RESOURCE]),
            self::AS_OIDC => $this->json(['issuer' => self::ISSUER]),
            self::AS_OAUTH => $this->json(['issuer' => self::ISSUER]),
        ];
    }

    /**
     * @param array<string, string> $body
     * @return array<string, mixed>
     */
    private function json(array $body, string $content_type = 'application/json; charset=UTF-8'): array
    {
        return [
            'code' => 200,
            'content-type' => $content_type,
            'body' => (string) json_encode($body),
            'headers' => ['content-type' => $content_type],
        ];
    }
}
