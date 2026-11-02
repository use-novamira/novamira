<?php

/**
 * SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use Novamira\OAuth\ClientValidation;
use Novamira\OAuth\Consent;
use Novamira\OAuth\Endpoints\Authorize;
use Novamira\OAuth\Endpoints\Register;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class RedirectDnsValidationTest extends TestCase
{
    private const URI = 'https://client.example/cb';

    protected function setUp(): void
    {
        require_once __DIR__ . '/fixtures/client-store-stubs.php';
        require_once __DIR__ . '/fixtures/redirect-dns-stubs.php';
        require_once __DIR__ . '/../../includes/oauth/bootstrap.php';
        require_once __DIR__ . '/../../includes/oauth/schema.php';
        require_once __DIR__ . '/../../includes/oauth/repositories/client-repository.php';
        require_once __DIR__ . '/../../includes/oauth/repositories/PendingAuthorizationRepository.php';
        require_once __DIR__ . '/../../includes/oauth/endpoints/register.php';
        require_once __DIR__ . '/../../includes/oauth/endpoints/authorize.php';
        require_once __DIR__ . '/../../includes/oauth/consent.php';
        nm_test_reset_state();
        \Novamira\OAuth\Schema\maybe_install();
        $_GET = $_POST = [];
        $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
        $GLOBALS['nm_dns_a'] = ['93.184.216.34'];
        $GLOBALS['nm_dns_aaaa'] = [];
        $GLOBALS['nm_dns_queries'] = [];
        $GLOBALS['nm_nonce_checks'] = [];
    }

    #[DataProvider('dnsAnswers')]
    public function testRegistrationValidatesDnsAnswers(array|false $a, array|false $aaaa, bool $allowed): void
    {
        $GLOBALS['nm_dns_a'] = $a;
        $GLOBALS['nm_dns_aaaa'] = $aaaa;

        $response = $this->registerClient();

        if ($allowed) {
            self::assertInstanceOf(WP_REST_Response::class, $response);
            self::assertSame(201, $response->get_status());
            self::assertCount(1, $this->rows('clients'));
        } else {
            self::assertInstanceOf(WP_Error::class, $response);
            self::assertSame('invalid_redirect_uri', $response->get_error_code());
            self::assertSame(400, $response->get_error_data()['status']);
            self::assertSame([], $this->rows('clients'));
        }
        self::assertSame([['A', 'client.example'], ['AAAA', 'client.example']], $GLOBALS['nm_dns_queries']);
    }

    public static function dnsAnswers(): iterable
    {
        yield 'DNS unavailable' => [false, false, false];
        yield 'no address records' => [false, [], false];
        yield 'empty answers' => [[], [], false];
        yield 'malformed addresses' => [['invalid'], [['ipv6' => 'invalid']], false];
        yield 'public IPv4' => [['93.184.216.34'], [], true];
        yield 'public IPv6 only' => [false, [['ipv6' => '2001:4860:4860::8888']], true];
        yield 'both public' => [['93.184.216.34'], [['ipv6' => '2001:4860:4860::8888']], true];
        yield 'private IPv4 among public' => [['93.184.216.34', '10.0.0.5'], [], false];
        yield 'private IPv6 with public IPv4' => [['93.184.216.34'], [['ipv6' => 'fd00::1']], false];
        yield 'link local' => [['169.254.169.254'], [], false];
        yield 'loopback' => [['127.0.0.1'], [], false];
    }

    public function testUnresolvableHostIsAlsoRefusedInDevMode(): void
    {
        $GLOBALS['nm_dns_a'] = $GLOBALS['nm_dns_aaaa'] = false;

        self::assertFalse(ClientValidation\is_allowed_redirect_uri(self::URI, dev_mode: true));
    }

    #[DataProvider('changedDnsAnswers')]
    public function testAuthorizeRechecksRegisteredHostname(array|false $a, array|false $aaaa): void
    {
        $client = $this->registerClient();
        self::assertInstanceOf(WP_REST_Response::class, $client);
        $this->setAuthorizationRequest($client->get_data()['client_id'], self::URI);
        $GLOBALS['nm_dns_a'] = $a;
        $GLOBALS['nm_dns_aaaa'] = $aaaa;

        $response = $this->capture(Authorize\handle(...));

        $this->assertLocalRefusal($response);
        self::assertSame([], $this->rows('pending_authorizations'));
        self::assertSame([], $this->rows('auth_codes'));
    }

    public static function changedDnsAnswers(): iterable
    {
        yield 'unavailable' => [false, false];
        yield 'private IPv4' => [['10.0.0.5'], []];
        yield 'link local' => [['169.254.169.254'], []];
        yield 'private IPv6 with public IPv4' => [['93.184.216.34'], [['ipv6' => 'fd00::1']]];
    }

    #[DataProvider('consentDnsAnswers')]
    public function testConsentRechecksHostname(array|false $a, array|false $aaaa, string $decision): void
    {
        [$token, $pending] = $this->authorize(self::URI);
        $GLOBALS['nm_dns_a'] = $a;
        $GLOBALS['nm_dns_aaaa'] = $aaaa;
        $_POST = [$decision => '1'];

        $response = $this->capture(static fn() => Consent\render_post($token, $pending, self::URI, 'state'));

        $this->assertLocalRefusal($response);
        self::assertSame(['novamira_oauth_consent_' . $token], $GLOBALS['nm_nonce_checks']);
        self::assertCount(1, $this->rows('pending_authorizations'));
        self::assertSame([], $this->rows('auth_codes'));
    }

    public static function consentDnsAnswers(): iterable
    {
        foreach (self::changedDnsAnswers() as $name => [$a, $aaaa]) {
            foreach (['approve', 'deny'] as $decision) {
                yield $name . ' ' . $decision => [$a, $aaaa, $decision];
            }
        }
    }

    #[DataProvider('allowedRedirects')]
    public function testAllowedRedirectsCompleteDenial(string $registered_uri, string $requested_uri): void
    {
        [$token, $pending] = $this->authorize($registered_uri, $requested_uri);
        $_POST = ['deny' => '1'];

        $response = $this->capture(static fn() => Consent\render_post($token, $pending, $requested_uri, 'state'));

        self::assertSame('redirect', $response->kind);
        self::assertSame(302, $response->getCode());
        self::assertSame($requested_uri . '?error=access_denied&state=state', $response->getMessage());
        self::assertSame([], $this->rows('pending_authorizations'));
        self::assertSame([], $this->rows('auth_codes'));
        self::assertCount($requested_uri === self::URI ? 6 : 0, $GLOBALS['nm_dns_queries']);
    }

    public static function allowedRedirects(): iterable
    {
        yield 'public HTTPS hostname' => [self::URI, self::URI];
        yield 'desktop IPv4 ephemeral port' => ['http://127.0.0.1:1234/cb', 'http://127.0.0.1:5678/cb'];
        yield 'desktop IPv6 ephemeral port' => ['http://[::1]:1234/cb', 'http://[::1]:5678/cb'];
        yield 'custom scheme' => ['claude://callback', 'claude://callback'];
    }

    private function registerClient(string $uri = self::URI): WP_REST_Response|WP_Error
    {
        return Register\handle(new WP_REST_Request(['client_name' => 'Test Client', 'redirect_uris' => [$uri]]));
    }

    private function setAuthorizationRequest(string $client_id, string $uri): void
    {
        $_GET = [
            'response_type' => 'code',
            'client_id' => $client_id,
            'redirect_uri' => $uri,
            'code_challenge' => str_repeat('a', 43),
            'code_challenge_method' => 'S256',
            'scope' => 'full_access',
            'state' => 'state',
        ];
    }

    private function authorize(string $registered_uri, ?string $requested_uri = null): array
    {
        $client = $this->registerClient($registered_uri);
        self::assertInstanceOf(WP_REST_Response::class, $client);
        $this->setAuthorizationRequest($client->get_data()['client_id'], $requested_uri ?? $registered_uri);
        $response = $this->capture(Authorize\handle(...));
        self::assertSame('redirect', $response->kind);
        parse_str((string) parse_url($response->getMessage(), PHP_URL_QUERY), $query);
        self::assertSame('novamira-oauth-consent', $query['page']);
        $pending = $this->rows('pending_authorizations');
        self::assertCount(1, $pending);
        return [$query['token'], $pending[0]];
    }

    private function rows(string $table): array
    {
        return $GLOBALS['wpdb']->rows['wp_novamira_oauth_' . $table] ?? [];
    }

    private function capture(callable $callback): RedirectDnsResponse
    {
        try {
            $callback();
        } catch (RedirectDnsResponse $response) {
            return $response;
        }
        self::fail('Expected an HTTP response.');
    }

    private function assertLocalRefusal(RedirectDnsResponse $response): void
    {
        self::assertSame('error', $response->kind);
        self::assertSame(400, $response->getCode());
        self::assertSame('redirect_uri not allowed.', $response->getMessage());
    }
}
