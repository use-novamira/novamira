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
if (!function_exists('get_bloginfo')) {
    function get_bloginfo(string $show = ''): string
    {
        return $show === 'version' ? (string) ($GLOBALS['wp_version'] ?? '') : '';
    }
}
if (!function_exists('home_url')) {
    function home_url(string $path = ''): string
    {
        return rtrim((string) ($GLOBALS['novamira_test_home'] ?? 'https://example.test'), characters: '/') . $path;
    }
}
if (!function_exists('rest_url')) {
    function rest_url(string $path = ''): string
    {
        return home_url('/wp-json/' . ltrim($path, characters: '/'));
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
if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}
if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}
if (!function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return $text;
    }
}
if (!function_exists('novamira_current_user_can_manage')) {
    function novamira_current_user_can_manage(): bool
    {
        $GLOBALS['novamira_test_current_user_can_manage_calls'] =
            ($GLOBALS['novamira_test_current_user_can_manage_calls'] ?? 0) + 1;
        return (bool) ($GLOBALS['novamira_test_current_user_can_manage'] ?? false);
    }
}
if (!function_exists('wp_admin_notice')) {
    /** @param array<string, mixed> $args */
    function wp_admin_notice(string $message, array $args = []): void
    {
        $GLOBALS['novamira_test_notices'][] = [$message, $args];
    }
}

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/compatibility.php';
require_once __DIR__ . '/../../includes/oauth/bootstrap.php';
require_once __DIR__ . '/../../includes/oauth/endpoints/discovery.php';
require_once __DIR__ . '/../../includes/abilities/bootstrap.php';

final class CompatibilityStartupTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wp_version'] = '6.9.2';
        $GLOBALS['novamira_test_home'] = 'https://example.test';
        $GLOBALS['novamira_test_actions'] = [];
        $GLOBALS['novamira_test_filters'] = [];
        $GLOBALS['novamira_test_notices'] = [];
        $GLOBALS['novamira_test_current_user_can_manage'] = true;
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['wp_version'],
            $GLOBALS['novamira_test_home'],
            $GLOBALS['novamira_test_actions'],
            $GLOBALS['novamira_test_filters'],
            $GLOBALS['novamira_test_notices'],
            $GLOBALS['novamira_test_current_user_can_manage'],
        );
    }

    public function testPluginHeaderAndInternalVersionsAgree(): void
    {
        $plugin = file_get_contents(__DIR__ . '/../../novamira.php');
        self::assertIsString($plugin);
        self::assertMatchesRegularExpression('/^ \* Version: ' . preg_quote(NOVAMIRA_VERSION, '/') . '$/m', $plugin);
        self::assertMatchesRegularExpression(
            '/^ \* Requires at least: ' . preg_quote(NOVAMIRA_MINIMUM_WORDPRESS_VERSION, '/') . '$/m',
            $plugin,
        );
    }

    public function testCompatibilityMetadataHasTheFrozenShapeAndCurrentValues(): void
    {
        $metadata = \Novamira\OAuth\Endpoints\Discovery\protected_resource_document();

        self::assertSame('https://example.test/wp-json/mcp/novamira-oauth', $metadata['resource']);
        self::assertSame(['https://example.test'], $metadata['authorization_servers']);
        self::assertSame(['header'], $metadata['bearer_methods_supported']);
        self::assertSame(['mcp'], $metadata['scopes_supported']);
        self::assertSame(
            ['mcp'],
            \Novamira\OAuth\Endpoints\Discovery\authorization_server_document()['scopes_supported'],
        );
        self::assertSame(
            [
                // Derived, not pinned: the shape is what is frozen here, and
                // testPluginHeaderAndInternalVersionsAgree covers the value
                'plugin_version' => NOVAMIRA_VERSION,
                'rest_api_version' => 1,
                'wordpress_version' => '6.9.2',
                'minimum_wordpress_version' => '6.9',
                'features' => [
                    'abilities_bearer_auth' => true,
                    'agent_context' => true,
                    'rest_skills' => true,
                    'generalized_execution_shim' => true,
                ],
            ],
            $metadata['novamira'],
        );
    }

    #[DataProvider('wellKnownPaths')]
    public function testWellKnownDiscoveryUsesTheWordPressInstallationBase(
        string $home,
        string $role,
        string $expected,
    ): void {
        $GLOBALS['novamira_test_home'] = $home;

        $paths = \Novamira\OAuth\Endpoints\Discovery\discovery_paths(
            home_url(),
            \Novamira\OAuth\resource_identifier(),
        );

        self::assertContains($expected, $paths[$role]);
    }

    /** @return array<string, array{string, string, string}> */
    public static function wellKnownPaths(): array
    {
        return [
            'root protected resource' => [
                'https://example.test',
                'protected_resource',
                '/.well-known/oauth-protected-resource',
            ],
            'subdirectory protected resource' => [
                'https://example.test/blog',
                'protected_resource',
                '/blog/.well-known/oauth-protected-resource',
            ],
            'subdirectory authorization server' => [
                'https://example.test/wordpress',
                'authorization_server',
                '/wordpress/.well-known/oauth-authorization-server',
            ],
        ];
    }

    public function testSupportedWordPressRegistersRestAndAbilitiesWithoutInitializingTheAdapter(): void
    {
        self::assertTrue(novamira_boot_ability_rest_surface());
        self::assertTrue(novamira_register_ability_hooks());
        self::assertTrue(novamira_register_ability_policy_hook());

        self::assertContains(
            ['rest_api_init', 'novamira_register_ability_run_rest_shim', 10, 1],
            $GLOBALS['novamira_test_actions'],
        );
        self::assertContains(
            ['rest_request_before_callbacks', 'novamira_prevent_restricted_rest_ability_run', 10, 3],
            $GLOBALS['novamira_test_filters'],
        );
        self::assertContains(
            ['rest_request_after_callbacks', 'novamira_filter_rest_ability_metadata', 10, 3],
            $GLOBALS['novamira_test_filters'],
        );
        self::assertContains(
            ['wp_abilities_api_categories_init', 'novamira_register_ability_categories', 20, 1],
            $GLOBALS['novamira_test_actions'],
        );
        self::assertContains(
            ['wp_abilities_api_init', 'novamira_register_builtin_abilities', 20, 1],
            $GLOBALS['novamira_test_actions'],
        );
        self::assertContains(
            ['wp_abilities_api_init', 'novamira_apply_ability_policy', PHP_INT_MAX, 1],
            $GLOBALS['novamira_test_actions'],
        );
    }

    public function testWordPress68WarnsAndRegistersNoAbilitySurface(): void
    {
        $GLOBALS['wp_version'] = '6.8.3';

        novamira_register_wordpress_compatibility_notice();
        self::assertFalse(novamira_boot_ability_rest_surface());
        self::assertFalse(novamira_register_ability_hooks());
        self::assertFalse(novamira_register_ability_policy_hook());

        $hooks = array_column($GLOBALS['novamira_test_actions'], 0);
        self::assertSame(['admin_notices', 'network_admin_notices'], $hooks);
        self::assertSame([], $GLOBALS['novamira_test_filters']);

        novamira_render_wordpress_compatibility_notice();
        self::assertCount(1, $GLOBALS['novamira_test_notices']);
        self::assertStringContainsString('WordPress 6.9 or newer', $GLOBALS['novamira_test_notices'][0][0]);
        self::assertStringContainsString('WordPress 6.8.3 is installed', $GLOBALS['novamira_test_notices'][0][0]);
    }
}
