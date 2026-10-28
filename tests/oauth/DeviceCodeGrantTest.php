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
        return 'https://example.test/wp-json/' . ltrim($path, characters: '/');
    }
}

if (!function_exists('wp_login_url')) {
    function wp_login_url(string $redirect = ''): string
    {
        return home_url('/wp-login.php');
    }
}

use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use Novamira\OAuth\Endpoints\Device;
use Novamira\OAuth\Grants\DeviceCodeGrant;
use Novamira\OAuth\Repositories\AccessTokenEntity;
use Novamira\OAuth\Repositories\ClientEntity;
use Novamira\OAuth\Repositories\DeviceCodeRepository;
use Novamira\OAuth\Repositories\DeviceCodeStore;
use Novamira\OAuth\Repositories\RefreshTokenEntity;
use Novamira\OAuth\Repositories\ScopeRepository;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/oauth/bootstrap.php';
require_once __DIR__ . '/../../includes/oauth/repositories/client-repository.php';
require_once __DIR__ . '/../../includes/oauth/repositories/access-token-repository.php';
require_once __DIR__ . '/../../includes/oauth/repositories/refresh-token-repository.php';
require_once __DIR__ . '/../../includes/oauth/repositories/scope-repository.php';
require_once __DIR__ . '/../../includes/oauth/repositories/device-code-repository.php';
require_once __DIR__ . '/../../includes/oauth/endpoints/device.php';
require_once __DIR__ . '/../../includes/oauth/grants/device-code-grant.php';

/**
 * In-memory stand-in for the device-code table, with the same compare-and-swap semantics the SQL
 * relies on: a claim succeeds only from the approved state, and only once.
 */
final class InMemoryDeviceCodes implements DeviceCodeStore
{
    /** @var array<string, array<string, mixed>> */
    public array $rows = [];

    public function add(
        string $device_code,
        string $client_id,
        string $status,
        int $expires_in = 600,
        int $user_id = 73,
        ?string $last_polled_at = null,
    ): void {
        $this->rows[hash('sha256', $device_code)] = [
            'device_code_hash' => hash('sha256', $device_code),
            'client_id' => $client_id,
            'user_id' => $user_id,
            'scopes' => 'mcp',
            'status' => $status,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + $expires_in),
            'last_polled_at' => $last_polled_at,
        ];
    }

    public function find_by_device_code(string $device_code): ?array
    {
        /** @var array{device_code_hash: string, client_id: string, user_id: int, scopes: string, status: string, expires_at: string, last_polled_at: string|null}|null $row */
        $row = $this->rows[hash('sha256', $device_code)] ?? null;
        return $row;
    }

    public function is_expired(string $expires_at): bool
    {
        // The deadline second itself is expired, exactly as DeviceCodeRepository judges it.
        return strtotime($expires_at . ' UTC') <= time();
    }

    public function touch_polled(string $device_code_hash): void
    {
        $this->rows[$device_code_hash]['last_polled_at'] = gmdate('Y-m-d H:i:s');
    }

    public function claim(string $device_code_hash): bool
    {
        if (($this->rows[$device_code_hash]['status'] ?? '') !== DeviceCodeRepository::STATUS_APPROVED) {
            return false;
        }
        $this->rows[$device_code_hash]['status'] = DeviceCodeRepository::STATUS_REDEEMED;
        return true;
    }
}

final class DeviceCodeGrantTest extends TestCase
{
    public function testApprovedDeviceCodeIssuesFullAccessTokensExactlyOnce(): void
    {
        $codes = new InMemoryDeviceCodes();
        $codes->add('device-code', 'device-client', DeviceCodeRepository::STATUS_APPROVED);
        $server = $this->serverHarness($codes);

        $body = $this->exchange($server, 'device-code');
        self::assertSame('Bearer', $body['token_type']);
        self::assertNotSame('', (string) $body['refresh_token']);
        // The device grant must converge on the same full-access token as the browser flow, minted
        // for the WordPress user who approved it.
        self::assertSame(['full_access'], $this->jwtClaim((string) $body['access_token'], 'scopes'));
        self::assertSame('73', $this->jwtClaim((string) $body['access_token'], 'sub'));

        // Replaying the same device code must not mint a second grant. The replay is dated past the
        // polling interval so it is judged on the redeemed code, not throttled as a fast poll.
        $codes->rows[hash('sha256', 'device-code')]['last_polled_at'] = gmdate('Y-m-d H:i:s', time() - 60);
        self::assertSame('invalid_grant', $this->exchangeError($server, 'device-code'));
    }

    public function testPendingDeniedExpiredAndForeignCodesEachFailInTheirOwnWay(): void
    {
        $codes = new InMemoryDeviceCodes();
        $codes->add('pending-code', 'device-client', DeviceCodeRepository::STATUS_PENDING);
        $codes->add('denied-code', 'device-client', DeviceCodeRepository::STATUS_DENIED);
        $codes->add('expired-code', 'device-client', DeviceCodeRepository::STATUS_APPROVED, expires_in: -1);
        $codes->add('other-client-code', 'someone-else', DeviceCodeRepository::STATUS_APPROVED);
        $server = $this->serverHarness($codes);

        // The client keeps polling on this one; anything else would end the login.
        self::assertSame('authorization_pending', $this->exchangeError($server, 'pending-code'));
        self::assertSame('access_denied', $this->exchangeError($server, 'denied-code'));
        self::assertSame('expired_token', $this->exchangeError($server, 'expired-code'));
        // A code minted for another client is indistinguishable from one that never existed.
        self::assertSame('invalid_grant', $this->exchangeError($server, 'other-client-code'));
        self::assertSame('invalid_grant', $this->exchangeError($server, 'never-issued'));
    }

    public function testPollingFasterThanTheAdvertisedIntervalIsSlowedNotDenied(): void
    {
        $codes = new InMemoryDeviceCodes();
        $codes->add(
            'device-code',
            'device-client',
            DeviceCodeRepository::STATUS_APPROVED,
            last_polled_at: gmdate('Y-m-d H:i:s'),
        );
        $server = $this->serverHarness($codes);

        self::assertSame('slow_down', $this->exchangeError($server, 'device-code'));
        // Still approved: an impatient client is throttled, and its grant survives.
        self::assertSame(
            DeviceCodeRepository::STATUS_APPROVED,
            $codes->rows[hash('sha256', 'device-code')]['status'],
        );
    }

    public function testUserCodesAreUnambiguousAndForgiveFormatting(): void
    {
        $code = Device\generate_user_code();
        self::assertMatchesRegularExpression('/^[' . Device\USER_CODE_ALPHABET . ']{4}-[' . Device\USER_CODE_ALPHABET . ']{4}$/', $code);
        // No vowels and no digits, so a code never spells a word and never collides with 0/O or 1/I.
        self::assertSame(0, preg_match('/[AEIOU0-9]/', $code));

        $normalized = Device\normalize_user_code($code);
        self::assertSame(Device\USER_CODE_LENGTH, strlen($normalized));
        // However the operator retypes it, the same authorization is found.
        self::assertSame($normalized, Device\normalize_user_code(strtolower($code)));
        self::assertSame($normalized, Device\normalize_user_code(' ' . str_replace('-', ' ', $code) . ' '));
        self::assertSame('', Device\normalize_user_code('12345678'));
    }

    public function testTheAdvertisedGrantIdentifierIsTheOneTheGrantAnswersTo(): void
    {
        self::assertSame('urn:ietf:params:oauth:grant-type:device_code', DeviceCodeGrant::GRANT_IDENTIFIER);
        self::assertSame(
            DeviceCodeGrant::GRANT_IDENTIFIER,
            \Novamira\OAuth\Endpoints\Discovery\authorization_server_document()['grant_types_supported'][2] ?? null,
        );
    }

    /** @return array<string, mixed> */
    private function exchange(AuthorizationServer $server, string $device_code): array
    {
        $request = (new ServerRequest('POST', 'https://example.test/token'))->withParsedBody([
            'grant_type' => DeviceCodeGrant::GRANT_IDENTIFIER,
            'client_id' => 'device-client',
            'device_code' => $device_code,
        ]);
        $response = $server->respondToAccessTokenRequest($request, (new Psr17Factory())->createResponse());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), associative: true);
        return $body;
    }

    private function exchangeError(AuthorizationServer $server, string $device_code): string
    {
        try {
            $this->exchange($server, $device_code);
        } catch (\League\OAuth2\Server\Exception\OAuthServerException $e) {
            return $e->getErrorType();
        }
        self::fail('The token request should not have succeeded.');
    }

    /** @return mixed */
    private function jwtClaim(string $jwt, string $claim): mixed
    {
        $parts = explode('.', $jwt);
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), strict: false), associative: true);
        return $payload[$claim] ?? null;
    }

    private function serverHarness(DeviceCodeStore $codes): AuthorizationServer
    {
        // A device client registers no redirect URI at all, which is what keeps it out of the
        // authorization-code flow.
        $client = new ClientEntity();
        $client->setIdentifier('device-client');
        $client->setName('Novamira CLI');
        $client->setRedirectUri([]);
        $client->setIsConfidential(false);

        $clients = new class ($client) implements ClientRepositoryInterface {
            public function __construct(private ClientEntityInterface $client)
            {
            }

            public function getClientEntity(mixed $clientIdentifier): ?ClientEntityInterface
            {
                return $clientIdentifier === $this->client->getIdentifier() ? $this->client : null;
            }

            public function validateClient(mixed $clientIdentifier, mixed $clientSecret, mixed $grantType): bool
            {
                return $clientIdentifier === $this->client->getIdentifier();
            }
        };

        $access = new class implements AccessTokenRepositoryInterface {
            /** @var array<string, bool> */
            private array $revoked = [];

            public function getNewToken(
                ClientEntityInterface $clientEntity,
                array $scopes,
                mixed $userIdentifier = null,
            ): AccessTokenEntityInterface {
                $token = new AccessTokenEntity();
                $token->setClient($clientEntity);
                foreach ($scopes as $scope) {
                    $token->addScope($scope);
                }
                $token->setUserIdentifier($userIdentifier);
                return $token;
            }

            public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
            {
                $this->revoked[$accessTokenEntity->getIdentifier()] = false;
            }

            public function revokeAccessToken(mixed $tokenId): void
            {
                $this->revoked[(string) $tokenId] = true;
            }

            public function isAccessTokenRevoked(mixed $tokenId): bool
            {
                return $this->revoked[(string) $tokenId] ?? true;
            }
        };

        $refresh = new class implements RefreshTokenRepositoryInterface {
            /** @var array<string, bool> */
            private array $revoked = [];

            public function getNewRefreshToken(): ?RefreshTokenEntityInterface
            {
                return new RefreshTokenEntity();
            }

            public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity): void
            {
                $this->revoked[$refreshTokenEntity->getIdentifier()] = false;
            }

            public function revokeRefreshToken(mixed $tokenId): void
            {
                $this->revoked[(string) $tokenId] = true;
            }

            public function isRefreshTokenRevoked(mixed $tokenId): bool
            {
                return $this->revoked[(string) $tokenId] ?? true;
            }
        };

        $keyResource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($keyResource);
        $privatePem = '';
        self::assertTrue(openssl_pkey_export($keyResource, $privatePem));
        $privateKey = new CryptKey($privatePem, passPhrase: null, keyPermissionsCheck: false);

        $server = new AuthorizationServer(
            $clients,
            $access,
            new ScopeRepository(),
            $privateKey,
            base64_encode(random_bytes(32)),
        );
        $grant = new DeviceCodeGrant($codes, $refresh, Device\POLL_INTERVAL);
        $grant->setRefreshTokenTTL(new DateInterval('P14D'));
        $server->enableGrantType($grant, new DateInterval('PT1H'));
        return $server;
    }
}
