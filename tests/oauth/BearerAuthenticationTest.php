<?php
// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', '/');
}
if (!function_exists('add_action')) {
    function add_action(
        string $hook_name,
        callable|string $callback,
        int $priority = 10,
        int $accepted_args = 1,
    ): bool {
        $GLOBALS['novamira_test_actions'][] = [$hook_name, $callback, $priority, $accepted_args];
        return true;
    }
}
if (!function_exists('rest_url')) {
    function rest_url(string $path = ''): string
    {
        if (($GLOBALS['novamira_test_rest_url_throws'] ?? false) === true) {
            throw new RuntimeException('REST URL generation failed.');
        }
        return 'https://example.test/wp-json/' . ltrim($path, characters: '/');
    }
}
if (!function_exists('home_url')) {
    function home_url(string $path = ''): string
    {
        return 'https://example.test' . $path;
    }
}
if (!function_exists('get_option')) {
    function get_option(string $name, mixed $default_value = false): mixed
    {
        return $GLOBALS['novamira_test_options'][$name] ?? $default_value;
    }
}
if (!function_exists('is_multisite')) {
    function is_multisite(): bool
    {
        return false;
    }
}
if (!function_exists('wp_set_current_user')) {
    function wp_set_current_user(int $user_id): int
    {
        $GLOBALS['novamira_test_current_user_id'] = $user_id;
        return $user_id;
    }
}
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

use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\ResourceServer;
use Novamira\OAuth\Repositories\AccessTokenEntity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/oauth/bootstrap.php';
require_once __DIR__ . '/../../includes/oauth/bridge.php';
require_once __DIR__ . '/../../includes/oauth/endpoints/discovery.php';
require_once __DIR__ . '/../../includes/oauth/repositories/access-token-repository.php';
require_once __DIR__ . '/../../includes/oauth/middleware.php';

final class BearerAuthenticationTest extends TestCase
{
    private string $privateKey;
    private string $publicKey;

    protected function setUp(): void
    {
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($resource);
        $private = '';
        self::assertTrue(openssl_pkey_export($resource, $private));
        $details = openssl_pkey_get_details($resource);
        self::assertIsArray($details);

        $this->privateKey = $private;
        $this->publicKey = (string) $details['key'];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'example.test';
        $_SERVER['REQUEST_URI'] = '/wp-json/wp-abilities/v1/abilities';
        $GLOBALS['novamira_test_current_user_id'] = 0;
        \Novamira\OAuth\Middleware\reset_request_context();
    }

    protected function tearDown(): void
    {
        unset(
            $_SERVER['REQUEST_METHOD'],
            $_SERVER['HTTPS'],
            $_SERVER['HTTP_HOST'],
            $_SERVER['REQUEST_URI'],
            $GLOBALS['novamira_test_current_user_id'],
            $GLOBALS['novamira_test_rest_url_throws'],
        );
        \Novamira\OAuth\Middleware\reset_request_context();
    }

    #[DataProvider('issuedScopeProvider')]
    public function testValidTokenReturnsBoundSubjectAndExactIssuedScopes(array $scopes): void
    {
        $token = $this->accessToken(new \DateTimeImmutable('+1 hour'), $scopes);

        self::assertSame(
            ['user_id' => 73, 'scopes' => $scopes],
            \Novamira\OAuth\Middleware\validate_bearer_credential(
                'Bearer ' . $token,
                $this->resourceServer(revoked: false),
            ),
        );
    }

    public function testValidNovamiraTokenEstablishesItsSubjectIdentity(): void
    {
        $token = $this->accessToken(new \DateTimeImmutable('+1 hour'), ['mcp']);
        $resolved = \Novamira\OAuth\Middleware\resolve_bearer_identity_using(
            false,
            'Bearer ' . $token,
            fn(string $authorization): array => \Novamira\OAuth\Middleware\validate_bearer_credential(
                $authorization,
                $this->resourceServer(revoked: false),
            ),
        );

        self::assertSame(73, $resolved);
        self::assertSame(73, $GLOBALS['novamira_test_current_user_id']);
        self::assertSame(
            ['user_id' => 73, 'scopes' => ['mcp']],
            \Novamira\OAuth\Middleware\request_oauth_identity(),
        );
        self::assertNull(\Novamira\OAuth\Middleware\reject_invalid_bearer(null));
    }

    public function testNovamiraShapedTokenWithInvalidSignatureIsRejected(): void
    {
        $token = $this->accessToken(new \DateTimeImmutable('+1 hour'), ['mcp']);
        $parts = explode('.', $token);
        self::assertCount(3, $parts);
        $parts[2] = ($parts[2][0] === 'A' ? 'B' : 'A') . substr($parts[2], 1);

        $resolved = \Novamira\OAuth\Middleware\resolve_bearer_identity_using(
            false,
            'Bearer ' . implode('.', $parts),
            fn(string $authorization): array => \Novamira\OAuth\Middleware\validate_bearer_credential(
                $authorization,
                $this->resourceServer(revoked: false),
            ),
        );

        self::assertFalse($resolved);
        self::assertSame(0, $GLOBALS['novamira_test_current_user_id']);
        self::assertNull(\Novamira\OAuth\Middleware\request_oauth_identity());
        $error = \Novamira\OAuth\Middleware\reject_invalid_bearer(null);
        self::assertInstanceOf(WP_Error::class, $error);
        self::assertSame('rest_oauth_error', $error->get_error_code());
        self::assertSame(401, $error->get_error_data()['status']);
    }

    /** @return iterable<string, array{list<string>}> */
    public static function issuedScopeProvider(): iterable
    {
        yield 'old readonly alias' => [['abilities:read']];
        yield 'old full alias' => [['abilities']];
        yield 'full access' => [['mcp']];
    }

    #[DataProvider('invalidCredentialProvider')]
    public function testMalformedExpiredRevokedAndWrongAudienceTokensAreRejected(string $kind): void
    {
        $server = $this->resourceServer(revoked: $kind === 'revoked');
        $token = match ($kind) {
            'malformed' => 'not-a-jwt',
            'expired' => $this->accessToken(new \DateTimeImmutable('-1 hour'), ['mcp']),
            'revoked' => $this->accessToken(new \DateTimeImmutable('+1 hour'), ['mcp']),
            'wrong audience' => $this->jwtForAudience('https://evil.test/wp-json/mcp/novamira-oauth'),
        };

        $this->expectException(OAuthServerException::class);
        \Novamira\OAuth\Middleware\validate_bearer_credential('Bearer ' . $token, $server);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidCredentialProvider(): iterable
    {
        yield 'malformed' => ['malformed'];
        yield 'expired' => ['expired'];
        yield 'revoked' => ['revoked'];
        yield 'wrong audience' => ['wrong audience'];
    }

    /** @param list<string> $scopes */
    private function accessToken(\DateTimeImmutable $expiry, array $scopes): string
    {
        $entity = new AccessTokenEntity();
        $entity->setPrivateKey(new CryptKey($this->privateKey, passPhrase: null, keyPermissionsCheck: false));
        $entity->setIdentifier('token-' . bin2hex(random_bytes(8)));
        $entity->setUserIdentifier('73');
        $entity->setExpiryDateTime($expiry);
        foreach ($scopes as $scope) {
            $entity->addScope($this->scope($scope));
        }

        return (string) $entity;
    }

    private function jwtForAudience(string $audience): string
    {
        $configuration = Configuration::forAsymmetricSigner(
            new Sha256(),
            InMemory::plainText($this->privateKey),
            InMemory::plainText($this->publicKey),
        );
        $now = new \DateTimeImmutable();
        $token = $configuration
            ->builder()
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt(new \DateTimeImmutable('+1 hour'))
            ->permittedFor($audience)
            ->identifiedBy('wrong-audience-token')
            ->relatedTo('73')
            ->withClaim('scopes', ['mcp'])
            ->getToken($configuration->signer(), $configuration->signingKey());

        return $token->toString();
    }

    private function resourceServer(bool $revoked): ResourceServer
    {
        $repository = new class ($revoked) implements AccessTokenRepositoryInterface {
            public function __construct(private bool $revoked)
            {
            }

            public function getNewToken(
                ClientEntityInterface $clientEntity,
                array $scopes,
                mixed $userIdentifier = null,
            ): AccessTokenEntityInterface {
                throw new LogicException('Not used by resource validation.');
            }

            public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
            {
            }

            public function revokeAccessToken(mixed $tokenId): void
            {
            }

            public function isAccessTokenRevoked(mixed $tokenId): bool
            {
                return $this->revoked;
            }
        };

        return new ResourceServer(
            $repository,
            new CryptKey($this->publicKey, passPhrase: null, keyPermissionsCheck: false),
        );
    }

    private function scope(string $identifier): ScopeEntityInterface
    {
        return new class ($identifier) implements ScopeEntityInterface {
            public function __construct(private string $identifier)
            {
            }

            public function getIdentifier(): string
            {
                return $this->identifier;
            }

            public function jsonSerialize(): mixed
            {
                return $this->identifier;
            }
        };
    }
}
