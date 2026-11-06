<?php
// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!function_exists('add_action')) {
    function add_action(string $hook, callable|string $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        return true;
    }
}

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
// Hosting\Detector reads these; the stubs return the value unfiltered, so detection follows only the
// runtime signals each test sets (a Cloudflare request header, here).
if (!function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        return $value;
    }
}
if (!function_exists('wp_parse_url')) {
    function wp_parse_url(string $url, int $component = -1): mixed
    {
        return parse_url($url, $component);
    }
}

if (!function_exists('wp_remote_get')) {
    function wp_remote_get(string $url, array $args = []): mixed
    {
        $GLOBALS['novamira_test_discovery_requests'][$url] = $args;
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
require_once __DIR__ . '/../../includes/hosting/detector.php';

use function Novamira\Troubleshoot\Checks\check_discovery;
use function Novamira\Troubleshoot\Checks\discovery_edge_block_failure;
use function Novamira\Troubleshoot\Checks\has_discovery_passthrough;
use function Novamira\Troubleshoot\Checks\well_known_htaccess_failure;

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
    private const SITEGROUND = [
        'id' => 'siteground',
        'name' => 'SiteGround',
        'recommendation' => 'password',
        'edge' => 'SiteGround IDS/IPS and custom NGINX security layer',
    ];

    private const PASSTHROUGH = "RewriteEngine On\nRewriteRule ^(oauth-protected-resource|oauth-authorization-server|openid-configuration)(/.*)?$ /index.php [L]\n";

    /** A throwaway document root holding a physical `.well-known/` directory, when a test needs one. */
    private string $root = '';

    protected function setUp(): void
    {
        $GLOBALS['novamira_test_home'] = 'https://example.test';
        $GLOBALS['novamira_test_discovery_requests'] = [];
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['novamira_test_home'],
            $GLOBALS['novamira_test_http'],
            $GLOBALS['novamira_test_discovery_requests'],
            $_SERVER['HTTP_CF_RAY'],
        );
        if ($this->root !== '') {
            @unlink($this->root . '/.well-known/.htaccess');
            @rmdir($this->root . '/.well-known');
            @rmdir($this->root);
            $this->root = '';
        }
    }

    public function testAllDocumentsPresentPasses(): void
    {
        self::assertSame('ok', $this->run_discovery($this->valid_map()));
    }

    public function testDiscoveryRequestsExplicitlyAcceptJsonWithoutFollowingRedirects(): void
    {
        $this->run_discovery($this->valid_map());
        foreach ($GLOBALS['novamira_test_discovery_requests'] as $options) {
            self::assertSame('application/json', $options['headers']['Accept']);
            self::assertSame(0, $options['redirection']);
        }
        self::assertCount(4, $GLOBALS['novamira_test_discovery_requests']);
    }

    public function testPhpOutputGetsItsOwnDiagnosisWithoutExposingTheBody(): void
    {
        $map = $this->valid_map();
        $map[self::PR_APPEND]['body'] = '<br /><b>Deprecated</b>: private-path-and-secret<br />' . $map[self::PR_APPEND]['body'];
        $result = $this->run_check($map);
        self::assertSame('fail', $result['status']);
        self::assertStringContainsString('PHP diagnostic output', $result['message']);
        self::assertStringContainsString('keeping error logging enabled', $result['remedy']);
        self::assertStringNotContainsString('private-path-and-secret', implode(' ', $result));
        self::assertStringNotContainsString('firewall', $result['remedy']);
    }

    public function testCachedPhpOutputReportsBothProblemsAndPreservesHeaders(): void
    {
        $map = $this->valid_map();
        $map[self::PR_APPEND]['body'] = 'PHP Warning: private details' . $map[self::PR_APPEND]['body'];
        $map[self::PR_APPEND]['headers']['X-Kinsta-Cache'] = 'HIT';
        $GLOBALS['novamira_test_http'] = $map;
        $headers = [];
        $result = check_discovery($headers);
        self::assertSame('HIT', $headers['x-kinsta-cache']);
        self::assertStringContainsString('PHP diagnostic output', $result['message']);
        self::assertStringContainsString('served from cache (x-kinsta-cache)', $result['message']);
        self::assertStringContainsString('Purge the cached discovery URLs', $result['remedy']);
        self::assertStringContainsString('PHP error log', $result['remedy']);
    }

    public function testCached404IsNotMisdiagnosedAsPhpOutput(): void
    {
        $map = $this->valid_map();
        $map[self::PR_APPEND] = ['code' => 404, 'content-type' => 'text/html', 'body' => 'Page not found', 'headers' => ['x-kinsta-cache' => 'HIT']];
        $result = $this->run_check($map);
        self::assertSame('fail', $result['status']);
        self::assertStringContainsString('HTTP 404', $result['message']);
        self::assertStringContainsString('served from cache', $result['message']);
        self::assertStringNotContainsString('PHP diagnostic output', $result['message']);
    }

    public function testMalformedJsonWithoutPhpOutputIsReportedPrecisely(): void
    {
        $map = $this->valid_map();
        $map[self::PR_APPEND]['body'] = '{broken';
        $map[self::PR_APPEND]['headers']['x-kinsta-cache'] = 'BYPASS';
        $result = $this->run_check($map);
        self::assertSame('fail', $result['status']);
        self::assertStringContainsString('not a JSON object', $result['message']);
        self::assertStringNotContainsString('served from cache', $result['message']);
        self::assertStringNotContainsString('PHP diagnostic output', $result['message']);
    }

    public function testHtmlResponseIsNotAutomaticallyBlamedOnACache(): void
    {
        $map = $this->valid_map();
        $map[self::PR_APPEND] = ['code' => 200, 'content-type' => 'text/html', 'body' => '<html>Sign in</html>', 'headers' => []];
        $result = $this->run_check($map);
        self::assertStringContainsString('Content-Type is not application/json', $result['message']);
        self::assertStringNotContainsString('served from cache', $result['message']);
    }

    public function testPhpWordsInsideValidMetadataAreNotTreatedAsDiagnosticOutput(): void
    {
        $map = $this->valid_map();
        $map[self::PR_APPEND] = $this->json(['resource' => self::RESOURCE, 'description' => 'Warning: <b>Deprecated</b>']);
        self::assertSame('ok', $this->run_discovery($map));
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

    public function testServerTrailingSlashRedirectGetsItsOwnDiagnosis(): void
    {
        // The server adds a trailing slash before WordPress runs. The site owner can fix that with a
        // one-line exclusion, so sending them to hosting support is the wrong remedy.
        $map = $this->valid_map();
        $map[self::PR_APPEND] = $this->redirect(location: self::PR_APPEND . '/');
        $result = $this->run_check($map);

        self::assertSame('fail', $result['status']);
        self::assertStringContainsString('trailing slash', $result['message']);
        self::assertStringContainsString('.well-known', $result['remedy']);
        self::assertSame("RewriteCond %{REQUEST_URI} !^/\\.well-known/\n", $result['copy']);
    }

    public function testRelativeTrailingSlashLocationIsRecognized(): void
    {
        $map = $this->valid_map();
        $map[self::PR_APPEND] = $this->redirect(location: '/.well-known/oauth-protected-resource/');
        $result = $this->run_check($map);

        self::assertStringContainsString('trailing slash', $result['message']);
    }

    public function testTrailingSlashExclusionCarriesTheSubdirectory(): void
    {
        $GLOBALS['novamira_test_home'] = 'https://example.test/subsite';
        $url = 'https://example.test/subsite/.well-known/oauth-protected-resource';
        $result = $this->run_check([$url => $this->redirect(location: $url . '/')]);

        self::assertSame("RewriteCond %{REQUEST_URI} !^/subsite/\\.well-known/\n", $result['copy']);
    }

    public function testRedirectToAnotherPathIsNotMistakenForATrailingSlash(): void
    {
        $map = $this->valid_map();
        $map[self::PR_APPEND] = $this->redirect(location: self::PR_APPEND . '/other/');
        $result = $this->run_check($map);

        self::assertStringContainsString('redirected by the server', $result['message']);
        self::assertStringContainsString('hosting support', $result['remedy']);
        self::assertSame('', $result['copy']);
    }

    public function testForbiddenBehindCloudflareReadsAsAHostingBlock(): void
    {
        $result = discovery_edge_block_failure(self::PR_APPEND, 'OAuth discovery', 403, [
            'id' => 'cloudflare',
            'name' => 'Cloudflare-protected hosting',
            'recommendation' => 'password',
            'edge' => 'Cloudflare WAF or bot protection',
        ]);

        self::assertNotNull($result);
        self::assertStringContainsString('Cloudflare WAF or bot protection', $result['message']);
        self::assertStringContainsString('or whoever manages Cloudflare for this site', $result['remedy']);
        self::assertStringNotContainsString('Cloudflare-protected hosting support', $result['remedy']);
    }

    public function testTrailingSlashRedirectFromWordPressStillBlamesThePlugin(): void
    {
        $map = $this->valid_map();
        $map[self::PR_APPEND] = $this->redirect(redirect_by: 'WordPress', location: self::PR_APPEND . '/');
        $result = $this->run_check($map);

        self::assertStringContainsString('from inside WordPress', $result['message']);
        self::assertSame('', $result['copy']);
    }

    public function testForbiddenWithoutDetectedHostingKeepsTheGenericDiagnosis(): void
    {
        $map = $this->valid_map();
        $map[self::PR_APPEND] = ['code' => 403, 'content-type' => 'text/html', 'body' => 'Forbidden', 'headers' => []];
        $result = $this->run_check($map);

        self::assertSame('fail', $result['status']);
        self::assertStringContainsString('did not return HTTP 200', $result['message']);
    }

    public function testForbiddenOnAKnownEdgePointsAtHostingSupport(): void
    {
        $result = discovery_edge_block_failure(self::PR_APPEND, 'OAuth discovery', 403, self::SITEGROUND);

        self::assertNotNull($result);
        self::assertSame('fail', $result['status']);
        self::assertStringContainsString('SiteGround IDS/IPS', $result['message']);
        self::assertStringContainsString('Ask your hosting support to keep', $result['remedy']);
        self::assertStringNotContainsString('Cloudflare', $result['remedy']);
        self::assertSame('', $result['copy']);
    }

    public function testEdgeDiagnosisIsLimitedToForbiddenOnAKnownEdge(): void
    {
        self::assertNull(discovery_edge_block_failure(self::PR_APPEND, 'OAuth discovery', 404, self::SITEGROUND));
        self::assertNull(discovery_edge_block_failure(self::PR_APPEND, 'OAuth discovery', 403, null));
        self::assertNull(discovery_edge_block_failure(
            self::PR_APPEND,
            'OAuth discovery',
            403,
            ['id' => 'custom', 'name' => 'Custom', 'recommendation' => 'oauth', 'edge' => ''],
        ));
    }

    public function testPassthroughIsRecognizedInTheSuggestedForms(): void
    {
        $rule = '^(oauth-protected-resource|oauth-authorization-server|openid-configuration)(/.*)?$';
        self::assertTrue(has_discovery_passthrough("RewriteEngine On\nRewriteRule {$rule} /index.php [L]\n"));
        self::assertTrue(has_discovery_passthrough("# certs\nRewriteEngine On\n  rewriterule {$rule} /blog/index.php [L]"));
    }

    public function testPassthroughIsNotRecognizedWhenAbsentOrCommentedOut(): void
    {
        $rule = '^(oauth-protected-resource|oauth-authorization-server|openid-configuration)(/.*)?$';
        self::assertFalse(has_discovery_passthrough("RewriteEngine On\n# RewriteRule {$rule} /index.php [L]\n"));
        self::assertFalse(has_discovery_passthrough("RewriteEngine On\nRewriteRule ^acme-challenge/ - [L]\n"));
        self::assertFalse(has_discovery_passthrough(''));
    }

    public function testPassthroughAcceptsAnchoredBareAndSubPathPatterns(): void
    {
        self::assertTrue(has_discovery_passthrough("RewriteRule ^oauth-protected-resource$ index.php [L]"));
        self::assertTrue(has_discovery_passthrough("RewriteRule oauth-protected-resource /index.php"));
        self::assertTrue(has_discovery_passthrough("RewriteRule ^oauth-protected-resource(/.*)?$ /sub/dir/index.php [L,QSA]\r\n"));
        self::assertTrue(has_discovery_passthrough('RewriteRule ^(?:openid-configuration|oauth-protected-resource)/?$ /index.php'));
        self::assertTrue(has_discovery_passthrough("REWRITERULE ^OAUTH-PROTECTED-RESOURCE /INDEX.PHP [L]"));
    }

    public function testPassthroughRejectsLookalikeDestinationsAndPatterns(): void
    {
        $rule = '^(oauth-protected-resource|oauth-authorization-server|openid-configuration)(/.*)?$';
        self::assertFalse(has_discovery_passthrough("RewriteRule {$rule} /index.php.bak [L]"));
        self::assertFalse(has_discovery_passthrough("RewriteRule {$rule} /index.php-old [L]"));
        self::assertFalse(has_discovery_passthrough("RewriteRule {$rule} /maintenance.php [L]"));
        self::assertFalse(has_discovery_passthrough("RewriteRule {$rule} /notindex.php [L]"));
        self::assertFalse(has_discovery_passthrough("RewriteRule {$rule} https://other.example/index.php [R=301,L]"));
        self::assertFalse(has_discovery_passthrough('RewriteRule ^not-oauth-protected-resource$ /index.php [L]'));
        self::assertFalse(has_discovery_passthrough('RewriteRule ^oauth-protected-resource-v2$ /index.php [L]'));
        self::assertFalse(has_discovery_passthrough('RewriteRule ^(openid-configuration|oauth-authorization-server)$ /index.php [L]'));
    }

    public function testPassthroughRejectsPatternsThatRequireATrailingSlash(): void
    {
        self::assertFalse(has_discovery_passthrough('RewriteRule ^oauth-protected-resource/$ /index.php [L]'));
        self::assertFalse(has_discovery_passthrough('RewriteRule ^oauth-protected-resource/.*$ /index.php [L]'));
        self::assertFalse(has_discovery_passthrough('RewriteRule ^(?:openid-configuration|oauth-protected-resource)/.*$ /index.php'));
        self::assertFalse(has_discovery_passthrough('RewriteRule ^/oauth-protected-resource$ /index.php [L]'));
    }

    public function testPassthroughAcceptsQuotedPatternsAndDestinations(): void
    {
        $rule = '^(oauth-protected-resource|oauth-authorization-server|openid-configuration)(/.*)?$';
        self::assertTrue(has_discovery_passthrough("RewriteRule \"{$rule}\" /index.php [L]"));
        self::assertTrue(has_discovery_passthrough("RewriteRule \"{$rule}\" /blog/index.php [L]"));
        self::assertTrue(has_discovery_passthrough("RewriteRule '{$rule}' \"/blog/index.php\" [L]"));
        self::assertTrue(has_discovery_passthrough("RewriteRule \"{$rule}\" \"/index.php\""));
        // Quotes must pair up.
        self::assertFalse(has_discovery_passthrough("RewriteRule \"{$rule}' /index.php [L]"));
        self::assertFalse(has_discovery_passthrough("RewriteRule \"{$rule} /index.php [L]"));
        self::assertFalse(has_discovery_passthrough("RewriteRule {$rule} \"/index.php [L]"));
    }

    public function testValidPassthroughOnDiskFallsThroughToTheResponseDiagnosis(): void
    {
        $root = $this->document_root(self::PASSTHROUGH);
        $map = $this->valid_map();
        $map[self::PR_APPEND] = ['code' => 404, 'content-type' => 'text/html', 'body' => 'Not Found', 'headers' => []];
        $GLOBALS['novamira_test_http'] = $map;

        self::assertNull(well_known_htaccess_failure(['url' => self::PR_APPEND], 'OAuth discovery', 404, $root));

        $headers = [];
        $result = check_discovery($headers, $root);
        self::assertSame('fail', $result['status']);
        self::assertStringContainsString('HTTP 404', $result['message']);
        self::assertStringNotContainsString('.htaccess', $result['message']);
        self::assertSame('', $result['copy']);
    }

    public function testMissingPassthroughOnDiskStillOffersTheHtaccessRemedy(): void
    {
        $root = $this->document_root("# certificate validation\nRewriteEngine On\nRewriteRule ^acme-challenge/ - [L]\n");
        $map = $this->valid_map();
        $map[self::PR_APPEND] = ['code' => 404, 'content-type' => 'text/html', 'body' => 'Not Found', 'headers' => []];
        $GLOBALS['novamira_test_http'] = $map;

        $headers = [];
        $result = check_discovery($headers, $root);
        self::assertSame('fail', $result['status']);
        self::assertStringContainsString($root . '/.well-known/.htaccess', $result['message']);
        self::assertStringContainsString('/index.php [L]', $result['copy']);
        self::assertTrue(has_discovery_passthrough($result['copy']));
    }

    public function testMissingPassthroughOutranksAKnownEdgeOnAForbidden(): void
    {
        // A detected host does not prove its edge produced the 403; a .well-known/.htaccess missing
        // the passthrough is a proven fault the site owner fixes, so its remedy wins.
        $root = $this->document_root("RewriteEngine On\n");
        $_SERVER['HTTP_CF_RAY'] = '8f00000000000000-MXP';
        $result = $this->run_forbidden($root, 'Forbidden');

        self::assertSame('fail', $result['status']);
        self::assertStringContainsString('.htaccess file', $result['message']);
        self::assertStringNotContainsString('Cloudflare', $result['message']);
        self::assertTrue(has_discovery_passthrough($result['copy']));
    }

    public function testKnownEdgeExplainsAForbiddenOnceThePassthroughIsInPlace(): void
    {
        $root = $this->document_root(self::PASSTHROUGH);
        $_SERVER['HTTP_CF_RAY'] = '8f00000000000000-MXP';
        $result = $this->run_forbidden($root, 'Forbidden');

        self::assertSame('fail', $result['status']);
        self::assertStringContainsString('Cloudflare WAF or bot protection', $result['message']);
        self::assertStringContainsString('whoever manages Cloudflare', $result['remedy']);
        self::assertSame('', $result['copy']);
    }

    public function testKnownEdgeExplainsAForbiddenWithoutAWellKnownDirectory(): void
    {
        $root = $this->document_root(null);
        $_SERVER['HTTP_CF_RAY'] = '8f00000000000000-MXP';
        $result = $this->run_forbidden($root, 'Forbidden');

        self::assertStringContainsString('Cloudflare WAF or bot protection', $result['message']);
    }

    public function testPhpGeneratedForbiddenKeepsTheResponseDiagnosisOnAKnownEdge(): void
    {
        // PHP diagnostic output proves WordPress answered, so the edge did not refuse the request.
        $root = $this->document_root(self::PASSTHROUGH);
        $_SERVER['HTTP_CF_RAY'] = '8f00000000000000-MXP';
        $result = $this->run_forbidden($root, '<br /><b>Warning</b>: something in a plugin<br />Forbidden');

        self::assertSame('fail', $result['status']);
        self::assertStringContainsString('PHP diagnostic output', $result['message']);
        self::assertStringNotContainsString('Cloudflare', $result['message']);
        self::assertStringNotContainsString('hosting support', $result['remedy']);
    }

    public function testTransportErrorOnRequiredFails(): void
    {
        $map = $this->valid_map();
        $map[self::PR_APPEND] = ['error' => 'cURL error 7: connection refused'];
        self::assertSame('fail', $this->run_discovery($map));
    }

    /**
     * Create a temporary document root whose physical `.well-known/.htaccess` holds $htaccess, or
     * that has no `.well-known/` directory at all when $htaccess is null.
     */
    private function document_root(?string $htaccess): string
    {
        $this->root = sys_get_temp_dir() . '/novamira-discovery-' . bin2hex(random_bytes(6));
        mkdir($this->root, recursive: true);
        if ($htaccess !== null) {
            mkdir($this->root . '/.well-known');
            file_put_contents($this->root . '/.well-known/.htaccess', $htaccess);
        }
        return $this->root;
    }

    /**
     * Run the discovery check with the required protected-resource URL answering HTTP 403.
     *
     * @return array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string}
     */
    private function run_forbidden(string $root, string $body): array
    {
        $map = $this->valid_map();
        $map[self::PR_APPEND] = ['code' => 403, 'content-type' => 'text/html', 'body' => $body, 'headers' => []];
        $GLOBALS['novamira_test_http'] = $map;
        $headers = [];
        return check_discovery($headers, $root);
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
    private function redirect(string $redirect_by = '', string $location = 'https://example.test/'): array
    {
        $response = [
            'code' => 301,
            'location' => $location,
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
