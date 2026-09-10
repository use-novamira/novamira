<?php
// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use League\OAuth2\Server\Exception\OAuthServerException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

if (!function_exists('novamira_current_user_can_manage')) {
    function novamira_current_user_can_manage(): bool
    {
        $GLOBALS['novamira_test_current_user_can_manage_calls'] =
            ($GLOBALS['novamira_test_current_user_can_manage_calls'] ?? 0) + 1;
        return (bool) ($GLOBALS['novamira_test_current_user_can_manage'] ?? false);
    }
}
if (!function_exists('home_url')) {
    function home_url(string $path = ''): string
    {
        return ((string) ($GLOBALS['novamira_test_home'] ?? 'https://example.test')) . $path;
    }
}
if (!function_exists('add_filter')) {
    function add_filter(
        string $hook_name,
        callable|string $callback,
        int $priority = 10,
        int $accepted_args = 1,
    ): bool {
        $GLOBALS['novamira_test_filters'][] = [$hook_name, $callback, $priority, $accepted_args];
        return true;
    }
}
if (!function_exists('wp_set_current_user')) {
    function wp_set_current_user(int $user_id): int
    {
        $GLOBALS['novamira_test_current_user_id'] = $user_id;
        return $user_id;
    }
}
if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int
    {
        return (int) ($GLOBALS['novamira_test_current_user_id'] ?? 0);
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
if (!class_exists('WP_Ability')) {
    class WP_Ability
    {
        /** @param array<string, mixed> $meta */
        public function __construct(
            private array $meta,
            private mixed $result = null,
            private string $name = '',
            private string $category = '',
        ) {}

        public function get_name(): string
        {
            return $this->name;
        }

        public function get_category(): string
        {
            return $this->category;
        }

        public function get_meta_item(string $key, mixed $default = null): mixed
        {
            return $this->meta[$key] ?? $default;
        }

        public function execute(mixed $input = null): mixed
        {
            return $this->result instanceof Closure ? ($this->result)($input) : $this->result;
        }
    }
}
if (!function_exists('wp_get_ability')) {
    function wp_get_ability(string $name): mixed
    {
        return $GLOBALS['novamira_test_abilities'][$name] ?? null;
    }
}
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request implements ArrayAccess
    {
        /** @param array<string, mixed>|null $json */
        public function __construct(
            private string $method = 'GET',
            private string $route = '',
            private string $body = '',
            private ?array $json = null,
            private array $params = [],
        ) {
        }

        public function get_route(): string
        {
            return $this->route;
        }

        public function get_method(): string
        {
            return $this->method;
        }

        public function get_body(): string
        {
            return $this->body;
        }

        /** @return array<string, mixed>|null */
        public function get_json_params(): ?array
        {
            return $this->json;
        }

        /** @return array<string, mixed> */
        public function get_query_params(): array
        {
            return $this->params;
        }

        public function offsetExists(mixed $offset): bool
        {
            return is_string($offset) && array_key_exists($offset, $this->params);
        }

        public function offsetGet(mixed $offset): mixed
        {
            return is_string($offset) ? ($this->params[$offset] ?? null) : null;
        }

        public function offsetSet(mixed $offset, mixed $value): void
        {
            if (is_string($offset)) {
                $this->params[$offset] = $value;
            }
        }

        public function offsetUnset(mixed $offset): void
        {
            if (is_string($offset)) {
                unset($this->params[$offset]);
            }
        }
    }
}
if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        /** @var array<string, string> */
        public array $headers = [];

        public function __construct(public mixed $data = null, public int $status = 200)
        {
        }

        public function header(string $name, string $value, bool $replace = true): void
        {
            if (!$replace && isset($this->headers[$name])) {
                $this->headers[$name] .= ', ' . $value;
                return;
            }

            $this->headers[$name] = $value;
        }

        public function link_header(string $rel, string $link): void
        {
            $this->header('Link', '<' . $link . '>; rel="' . $rel . '"', replace: false);
        }

        /** @return array<string, string> */
        public function get_headers(): array
        {
            return $this->headers;
        }

        /** @param array<string, string> $headers */
        public function set_headers(array $headers): void
        {
            $this->headers = $headers;
        }

        public function get_data(): mixed
        {
            return $this->data;
        }

        public function set_data(mixed $data): void
        {
            $this->data = $data;
        }

        public function get_status(): int
        {
            return $this->status;
        }
    }
}
if (!function_exists('rest_convert_error_to_response')) {
    function rest_convert_error_to_response(WP_Error $error): WP_REST_Response
    {
        $data = $error->get_error_data();

        return new WP_REST_Response([
            'code' => $error->get_error_code(),
            'message' => $error->get_error_message(),
            'data' => $data,
        ], isset($data['status']) ? (int) $data['status'] : 500);
    }
}

if (!defined('ABSPATH')) {
    define('ABSPATH', '/');
}

require_once __DIR__ . '/../../includes/oauth/endpoints/discovery.php';
require_once __DIR__ . '/../../includes/oauth/middleware.php';

final class MiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['novamira_test_current_user_can_manage'] = true;
        $GLOBALS['novamira_test_current_user_id'] = 0;
        $GLOBALS['novamira_test_filters'] = [];
        $GLOBALS['novamira_test_abilities'] = [
            'novamira/read-file' => new WP_Ability([
                'show_in_rest' => true,
                'annotations' => ['readonly' => true],
            ]),
            'novamira/write-file' => new WP_Ability([
                'show_in_rest' => true,
                'annotations' => ['readonly' => false],
            ]),
            'novamira/unannotated' => new WP_Ability(['show_in_rest' => true]),
        ];
        \Novamira\OAuth\Middleware\reset_request_context();
    }

    protected function tearDown(): void
    {
        unset(
            $_GET['rest_route'],
            $_SERVER['REQUEST_URI'],
            $_SERVER['HTTP_AUTHORIZATION'],
            $_SERVER['REDIRECT_HTTP_AUTHORIZATION'],
            $GLOBALS['novamira_test_current_user_can_manage'],
            $GLOBALS['novamira_test_current_user_id'],
            $GLOBALS['novamira_test_filters'],
            $GLOBALS['novamira_test_home'],
            $GLOBALS['novamira_test_abilities'],
        );
        \Novamira\OAuth\Middleware\reset_request_context();
    }

    public function testWwwAuthenticateHeaderAdvertisesTheRootMetadataUrl(): void
    {
        $GLOBALS['novamira_test_home'] = 'https://example.test';
        self::assertSame(
            'Bearer resource_metadata="https://example.test/.well-known/oauth-protected-resource", scope="mcp"',
            \Novamira\OAuth\Middleware\www_authenticate_header(),
        );
    }

    public function testWwwAuthenticateHeaderCarriesTheSubdirectoryPath(): void
    {
        // The centralized URL must stay byte-for-byte what the site advertises: on a subdirectory
        // install the challenge points at /subsite/.well-known/..., which this install serves.
        $GLOBALS['novamira_test_home'] = 'https://example.com/subsite';
        self::assertSame(
            'Bearer resource_metadata="https://example.com/subsite/.well-known/oauth-protected-resource", scope="mcp"',
            \Novamira\OAuth\Middleware\www_authenticate_header(),
        );
    }

    public function testRegistersEarlyIdentityAndAuthoritativeLateAuthorizationHooks(): void
    {
        \Novamira\OAuth\Middleware\register();

        self::assertContains(
            ['determine_current_user', 'Novamira\\OAuth\\Middleware\\resolve_bearer_identity', 30, 1],
            $GLOBALS['novamira_test_filters'],
        );
        self::assertContains(
            ['rest_authentication_errors', 'Novamira\\OAuth\\Middleware\\reject_invalid_bearer', 5, 1],
            $GLOBALS['novamira_test_filters'],
        );
        self::assertContains(
            ['rest_request_before_callbacks', 'Novamira\\OAuth\\Middleware\\authorize_routed_request', 5, 3],
            $GLOBALS['novamira_test_filters'],
        );
        self::assertContains(
            ['rest_request_after_callbacks', 'Novamira\\OAuth\\Middleware\\attach_www_authenticate_challenge', 5, 3],
            $GLOBALS['novamira_test_filters'],
        );
        self::assertCount(4, $GLOBALS['novamira_test_filters']);
    }

    public function testMcpRouteMatchesOnlyTheOauthServer(): void
    {
        self::assertTrue(\Novamira\OAuth\Middleware\is_mcp_route('/mcp/novamira-oauth'));
        self::assertTrue(\Novamira\OAuth\Middleware\is_mcp_route('/mcp/novamira-oauth/tools/list'));
        self::assertFalse(\Novamira\OAuth\Middleware\is_mcp_route('/mcp/novamira'));
        self::assertFalse(\Novamira\OAuth\Middleware\is_mcp_route('/mcp/mcp-adapter-default-server'));
    }

    /** @param array{0: string, 1: string, 2: bool} $case */
    #[DataProvider('abilityRouteProvider')]
    public function testAbilityRouteAllowlistUsesExactRouteAndMethod(array $case): void
    {
        [$method, $route, $allowed] = $case;

        self::assertSame($allowed, \Novamira\OAuth\Middleware\oauth_identity_may_use_route($route, $method));
    }

    /** @return iterable<string, array{array{string, string, bool}}> */
    public static function abilityRouteProvider(): iterable
    {
        yield 'list get' => [['GET', '/wp-abilities/v1/abilities', true]];
        yield 'list head' => [['HEAD', '/wp-abilities/v1/abilities', true]];
        yield 'list post' => [['POST', '/wp-abilities/v1/abilities', false]];
        yield 'item' => [['GET', '/wp-abilities/v1/abilities/novamira/read-file', true]];
        yield 'nested item' => [['GET', '/wp-abilities/v1/abilities/vendor/group/name', true]];
        yield 'item head excluded' => [['HEAD', '/wp-abilities/v1/abilities/novamira/read-file', false]];
        yield 'shim' => [['POST', '/novamira/v1/abilities/novamira/read-file/run', true]];
        yield 'nested shim' => [['POST', '/novamira/v1/abilities/vendor/group/name/run', true]];
        yield 'shim get excluded' => [['GET', '/novamira/v1/abilities/novamira/read-file/run', false]];
        yield 'lookalike excluded' => [['POST', '/novamira/v1/abilities/novamira/read-file/run/extra', false]];
    }

    public function testMissingBearerLeavesWordPressAuthenticationUnchanged(): void
    {
        $called = false;
        $user = \Novamira\OAuth\Middleware\resolve_bearer_identity_using(
            false,
            '',
            static function () use (&$called): array {
                $called = true;
                return ['user_id' => 7, 'scopes' => ['mcp']];
            },
        );

        self::assertFalse($user);
        self::assertFalse($called);
        self::assertNull(\Novamira\OAuth\Middleware\request_authentication_error());
    }

    public function testMalformedBearerIsRejectedWithoutSettingAUser(): void
    {
        $user = \Novamira\OAuth\Middleware\resolve_bearer_identity_using(
            false,
            'Bearer   ',
            static fn(): array => ['user_id' => 7, 'scopes' => ['mcp']],
        );

        self::assertFalse($user);
        self::assertSame(0, get_current_user_id());
        $error = \Novamira\OAuth\Middleware\reject_invalid_bearer(null);
        self::assertInstanceOf(WP_Error::class, $error);
        self::assertSame(401, $error->get_error_data()['status']);
    }

    #[DataProvider('invalidTokenProvider')]
    public function testInvalidExpiredAndRevokedTokensFailClosed(string $kind): void
    {
        $user = \Novamira\OAuth\Middleware\resolve_bearer_identity_using(
            false,
            'Bearer ' . $kind,
            static function () use ($kind): array {
                throw OAuthServerException::accessDenied('Simulated ' . $kind . ' token.');
            },
        );

        self::assertFalse($user);
        self::assertSame(0, get_current_user_id());
        self::assertInstanceOf(WP_Error::class, \Novamira\OAuth\Middleware\request_authentication_error());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidTokenProvider(): iterable
    {
        yield 'invalid signature' => ['invalid'];
        yield 'expired' => ['expired'];
        yield 'revoked' => ['revoked'];
    }

    public function testValidBearerSetsIdentityBeforeRestHardeningRuns(): void
    {
        $user = $this->authenticateOauthUser();

        self::assertSame(73, $user);
        self::assertSame(73, get_current_user_id());
        self::assertTrue($this->hardeningCallbackAllowsOnlyAuthenticatedRequests());
        self::assertNull(\Novamira\OAuth\Middleware\reject_invalid_bearer(null));
    }

    public function testCookieAndApplicationPasswordIdentitiesAreNeverReplaced(): void
    {
        foreach ([41, 42] as $existingUser) {
            $called = false;
            $resolved = \Novamira\OAuth\Middleware\resolve_bearer_identity_using(
                $existingUser,
                'Bearer must-not-be-read',
                static function () use (&$called): array {
                    $called = true;
                    return ['user_id' => 73, 'scopes' => ['mcp']];
                },
            );
            self::assertSame($existingUser, $resolved);
            self::assertFalse($called);
            self::assertNull(\Novamira\OAuth\Middleware\request_oauth_identity());
        }
    }

    public function testQueryParameterCannotSpoofTheAuthoritativeRoute(): void
    {
        $_GET['rest_route'] = '/wp-abilities/v1/abilities';
        $_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/posts?rest_route=/wp-abilities/v1/abilities';
        $this->authenticateOauthUser();

        $result = \Novamira\OAuth\Middleware\authorize_routed_request(
            new WP_REST_Response(['preempted' => true]),
            null,
            new WP_REST_Request('GET', '/wp/v2/posts'),
        );

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('rest_oauth_route_forbidden', $result->get_error_code());
        self::assertSame(403, $result->get_error_data()['status']);
    }

    #[DataProvider('excludedRouteProvider')]
    public function testValidBearerIsRejectedOutsideTheAllowlist(string $method, string $route): void
    {
        $this->authenticateOauthUser();

        $result = \Novamira\OAuth\Middleware\authorize_routed_request(
            null,
            null,
            new WP_REST_Request($method, $route),
        );

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('rest_oauth_route_forbidden', $result->get_error_code());
    }

    /** @return iterable<string, array{string, string}> */
    public static function excludedRouteProvider(): iterable
    {
        yield 'upload' => ['POST', '/novamira/v1/upload/example'];
        yield 'temporary administrator' => ['POST', '/novamira/v1/admin-access'];
        yield 'chat' => ['POST', '/novamira/v1/chat'];
        yield 'browser runtime' => ['POST', '/novamira/v1/gutenberg/finalize'];
        yield 'canonical Application Password MCP' => ['POST', '/mcp/novamira'];
        yield 'legacy MCP' => ['POST', '/mcp/mcp-adapter-default-server'];
        yield 'unrelated REST' => ['GET', '/wp/v2/posts'];
    }

    public function testAllowedRouteStillRequiresCurrentManagementPermission(): void
    {
        $this->authenticateOauthUser();
        $GLOBALS['novamira_test_current_user_can_manage'] = false;

        $result = \Novamira\OAuth\Middleware\authorize_routed_request(
            null,
            null,
            new WP_REST_Request('GET', '/wp-abilities/v1/abilities'),
        );

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame(403, $result->get_error_data()['status']);
    }

    public function testFullAccessGrantAllowsMcpAndEveryAbilityRoute(): void
    {
        $this->authenticateOauthUser(['mcp']);

        foreach ([
            new WP_REST_Request('POST', '/mcp/novamira-oauth'),
            new WP_REST_Request('GET', '/wp-abilities/v1/abilities'),
            new WP_REST_Request('GET', '/wp-abilities/v1/abilities/novamira/read-file'),
            new WP_REST_Request('POST', '/novamira/v1/abilities/novamira/read-file/run'),
            new WP_REST_Request('POST', '/novamira/v1/abilities/novamira/write-file/run'),
            new WP_REST_Request('POST', '/novamira/v1/abilities/novamira/unannotated/run'),
        ] as $request) {
            self::assertNull(\Novamira\OAuth\Middleware\authorize_routed_request(null, null, $request));
        }
    }

    public function testAlreadyIssuedAbilityScopesAreFullAccessMigrationAliases(): void
    {
        foreach (['abilities', 'abilities:read'] as $scope) {
            foreach ([
                new WP_REST_Request('POST', '/mcp/novamira-oauth'),
                new WP_REST_Request('POST', '/novamira/v1/abilities/novamira/write-file/run'),
            ] as $request) {
                $this->authenticateOauthUser([$scope]);
                self::assertNull(\Novamira\OAuth\Middleware\authorize_routed_request(null, null, $request));
            }
        }
    }

    public function testEveryProtectedRouteChallengesForTheSingleFullAccessScope(): void
    {
        self::assertSame(
            'mcp',
            \Novamira\OAuth\Middleware\route_required_scope('/wp-abilities/v1/abilities', 'GET'),
        );
        self::assertSame(
            'mcp',
            \Novamira\OAuth\Middleware\route_required_scope(
                '/novamira/v1/abilities/novamira/read-file/run',
                'POST',
            ),
        );
        self::assertSame(
            'mcp',
            \Novamira\OAuth\Middleware\route_required_scope(
                '/novamira/v1/abilities/novamira/write-file/run',
                'POST',
            ),
        );
        self::assertSame('mcp', \Novamira\OAuth\Middleware\route_required_scope('/mcp/novamira-oauth', 'POST'));

        $response = $this->dispatchAgainstDenyingPermissionCallback(
            new WP_REST_Request('POST', '/novamira/v1/abilities/novamira/write-file/run'),
        );
        self::assertInstanceOf(WP_REST_Response::class, $response);
        self::assertStringContainsString('scope="mcp"', $response->headers['WWW-Authenticate']);
    }

    public function testUnauthenticatedChallengeSurvivesTheRoutePermissionCallback(): void
    {
        // A WP_REST_Response returned before callbacks does not short-circuit the route's own
        // permission callback, so core would deny again and drop the challenge with the response.
        $denial = \Novamira\OAuth\Middleware\challenge_unauthenticated(
            null,
            null,
            new WP_REST_Request('POST', '/mcp/novamira-oauth'),
        );
        self::assertInstanceOf(WP_Error::class, $denial);
        self::assertSame('rest_oauth_required', $denial->get_error_code());
        self::assertSame(401, $denial->get_error_data()['status']);

        $response = $this->dispatchAgainstDenyingPermissionCallback(new WP_REST_Request('POST', '/mcp/novamira-oauth'));
        self::assertInstanceOf(WP_REST_Response::class, $response);
        self::assertSame(401, $response->status);
        self::assertSame('rest_oauth_required', $response->data['code']);
        // RFC 6750 §3.1: a request that carried no credentials gets no `error` parameter.
        self::assertSame(
            'Bearer resource_metadata="https://example.test/.well-known/oauth-protected-resource", scope="mcp"',
            $response->headers['WWW-Authenticate'],
        );
    }

    public function testRoutedScopeDenialsCarryTheirChallengeOnTheResponseObject(): void
    {
        $this->authenticateOauthUser(['mcp']);
        $forbidden = $this->dispatchAgainstDenyingPermissionCallback(
            new WP_REST_Request('GET', '/wp/v2/posts'),
        );
        self::assertInstanceOf(WP_REST_Response::class, $forbidden);
        self::assertSame(403, $forbidden->status);
        self::assertStringContainsString('error="insufficient_scope"', $forbidden->headers['WWW-Authenticate']);

        $this->authenticateOauthUser(['unknown']);
        $insufficient = $this->dispatchAgainstDenyingPermissionCallback(
            new WP_REST_Request('POST', '/novamira/v1/abilities/novamira/write-file/run'),
        );
        self::assertInstanceOf(WP_REST_Response::class, $insufficient);
        self::assertSame('rest_oauth_error', $insufficient->data['code']);
        self::assertStringContainsString('error="insufficient_scope"', $insufficient->headers['WWW-Authenticate']);
        self::assertStringContainsString('scope="mcp"', $insufficient->headers['WWW-Authenticate']);
    }

    public function testChallengeAttachmentLeavesUnrelatedResultsUntouched(): void
    {
        $request = new WP_REST_Request('POST', '/mcp/novamira-oauth');

        $success = new WP_REST_Response(['ok' => true]);
        self::assertSame(
            $success,
            \Novamira\OAuth\Middleware\attach_www_authenticate_challenge($success, null, $request),
        );

        // Another plugin's denial, and Novamira's own pre-dispatch 401, must not be relabelled here.
        foreach ([
            new WP_Error('rest_forbidden', 'Sorry, you are not allowed to do that.', ['status' => 401]),
            new WP_Error('rest_oauth_error', 'Invalid OAuth token subject.', ['status' => 401]),
        ] as $error) {
            self::assertSame(
                $error,
                \Novamira\OAuth\Middleware\attach_www_authenticate_challenge($error, null, $request),
            );
        }
    }

    public function testChallengeSkipsApplicationPasswordAndLegacyRoutes(): void
    {
        self::assertNull(\Novamira\OAuth\Middleware\challenge_unauthenticated(
            null,
            null,
            new WP_REST_Request('POST', '/mcp/novamira'),
        ));
        self::assertNull(\Novamira\OAuth\Middleware\challenge_unauthenticated(
            null,
            null,
            new WP_REST_Request('POST', '/mcp/mcp-adapter-default-server'),
        ));
    }

    public function testBearerParsingAndChallengeContract(): void
    {
        self::assertTrue(\Novamira\OAuth\Middleware\has_bearer_scheme('Bearer'));
        self::assertTrue(\Novamira\OAuth\Middleware\has_bearer_authorization('bearer abc.def'));
        self::assertFalse(\Novamira\OAuth\Middleware\has_bearer_authorization('Basic abc.def'));
        self::assertSame(
            'Bearer abc.def',
            \Novamira\OAuth\Middleware\normalize_bearer_authorization('bearer abc.def'),
        );

        $challenge = \Novamira\OAuth\Middleware\www_authenticate_header('invalid_token');
        self::assertStringContainsString('resource_metadata=', $challenge);
        self::assertStringContainsString('error="invalid_token"', $challenge);
        self::assertStringContainsString('scope="mcp"', $challenge);
    }

    /**
     * Mirror WP_REST_Server::respond_to_request() for a route whose permission callback denies:
     * only a WP_Error short-circuits it, the generic rest_forbidden replaces anything else, and the
     * after-callbacks filter runs either way.
     */
    private function dispatchAgainstDenyingPermissionCallback(WP_REST_Request $request): mixed
    {
        $response = \Novamira\OAuth\Middleware\authorize_routed_request(null, null, $request);
        if (!$response instanceof WP_Error) {
            $response = new WP_Error('rest_forbidden', 'Sorry, you are not allowed to do that.', ['status' => 401]);
        }

        return \Novamira\OAuth\Middleware\attach_www_authenticate_challenge($response, null, $request);
    }

    /** @param list<string> $scopes */
    private function authenticateOauthUser(array $scopes = ['mcp']): mixed
    {
        return \Novamira\OAuth\Middleware\resolve_bearer_identity_using(
            false,
            'Bearer valid-token',
            static fn(): array => ['user_id' => 73, 'scopes' => $scopes],
        );
    }

    private function hardeningCallbackAllowsOnlyAuthenticatedRequests(): bool
    {
        return get_current_user_id() > 0;
    }
}
