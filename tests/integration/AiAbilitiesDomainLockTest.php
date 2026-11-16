<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', '/');
}

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

if (!function_exists('home_url')) {
    function home_url(string $path = ''): string
    {
        return ((string) ($GLOBALS['novamira_test_home'] ?? 'https://example.test')) . $path;
    }
}
if (!function_exists('get_option')) {
    function get_option(string $name, mixed $default_value = false): mixed
    {
        return $GLOBALS['novamira_test_options'][$name] ?? $default_value;
    }
}
if (!function_exists('update_option')) {
    function update_option(string $option, mixed $value, string|bool|null $autoload = null): bool
    {
        $GLOBALS['novamira_test_options'][$option] = $value;
        return true;
    }
}
if (!function_exists('wp_parse_url')) {
    function wp_parse_url(string $url, int $component = -1): mixed
    {
        return parse_url($url, $component);
    }
}

if (!function_exists('delete_option')) {
    function delete_option(string $option): bool
    {
        unset($GLOBALS['novamira_test_options'][$option]);
        return true;
    }
}
if (!function_exists('is_multisite')) {
    function is_multisite(): bool
    {
        return false;
    }
}
if (!function_exists('current_user_can')) {
    function current_user_can(string $capability): bool
    {
        return (bool) ($GLOBALS['novamira_test_can_manage'] ?? true);
    }
}
if (!function_exists('check_admin_referer')) {
    function check_admin_referer(string $action): int
    {
        // WordPress dies on a bad nonce; the throw stops the handler the same way.
        if ($action !== 'novamira_settings' || !($GLOBALS['novamira_test_nonce_valid'] ?? true)) {
            throw new RuntimeException('nonce check failed');
        }
        return 1;
    }
}
if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        return 'https://example.test/wp-admin/' . $path;
    }
}
if (!function_exists('add_query_arg')) {
    /** @param array<string, string> $args */
    function add_query_arg(array $args, string $url): string
    {
        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
    }
}
if (!function_exists('wp_safe_redirect')) {
    function wp_safe_redirect(string $location): bool
    {
        // The handler exits right after redirecting; throwing keeps the test process alive.
        throw new NovamiraTestRedirect($location);
    }
}

final class NovamiraTestRedirect extends RuntimeException
{
    public function __construct(
        public readonly string $location,
    ) {
        parent::__construct('redirect: ' . $location);
    }
}

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AiAbilitiesDomainLockTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['novamira_test_options'] = [];
        $GLOBALS['novamira_test_home'] = 'https://example.test';
        $GLOBALS['novamira_test_can_manage'] = true;
        $GLOBALS['novamira_test_nonce_valid'] = true;
        $_POST = [];
        $_GET = [];

        require_once __DIR__ . '/../../includes/helpers.php';
        require_once __DIR__ . '/../../includes/connect-page.php';
    }

    public function testHostComesFromTheStoredHomeOptionNotTheFilteredHomeUrl(): void
    {
        $GLOBALS['novamira_test_options']['home'] = 'https://stored.example/';
        $GLOBALS['novamira_test_home'] = 'https://green-lion.hostingersite.com';

        self::assertSame('stored.example', novamira_ai_abilities_site_host());
    }

    public function testHostFallsBackToHomeUrlWhenTheStoredHomeHasNoHost(): void
    {
        $GLOBALS['novamira_test_home'] = 'https://filtered.example';

        self::assertSame('filtered.example', novamira_ai_abilities_site_host());

        $GLOBALS['novamira_test_options']['home'] = 'stored.example';

        self::assertSame('filtered.example', novamira_ai_abilities_site_host());
    }

    public function testEnabledAbilitiesStayOnWhenHomeUrlIsFilteredOnALaterRequest(): void
    {
        $GLOBALS['novamira_test_options']['home'] = 'https://stored.example';
        $GLOBALS['novamira_test_home'] = 'https://green-lion.hostingersite.com';

        self::assertTrue(novamira_enable_ai_abilities());
        self::assertSame('stored.example', $GLOBALS['novamira_test_options']['novamira_ai_abilities_domain']);
        self::assertTrue(novamira_is_enabled());

        $GLOBALS['novamira_test_home'] = 'https://another.example';

        self::assertTrue(novamira_is_enabled());
        self::assertFalse(novamira_is_domain_mismatch());
    }

    public function testLockSavedFromAFilteredHomeUrlKeepsMatchingWhileTheFilterIsStable(): void
    {
        $GLOBALS['novamira_test_options']['home'] = 'https://stored.example';
        $GLOBALS['novamira_test_options']['novamira_ai_abilities_enabled'] = '1';
        $GLOBALS['novamira_test_options']['novamira_ai_abilities_domain'] = 'mapped.example';
        $GLOBALS['novamira_test_home'] = 'https://mapped.example';

        self::assertTrue(novamira_is_enabled());
        self::assertFalse(novamira_is_domain_mismatch());

        $GLOBALS['novamira_test_home'] = 'https://another.example';

        self::assertFalse(novamira_is_enabled());
        self::assertTrue(novamira_is_domain_mismatch());
    }

    public function testChangingTheStoredHomeStillTurnsAbilitiesOffUntilTheyAreEnabledAgain(): void
    {
        $GLOBALS['novamira_test_options']['home'] = 'https://old.example';
        novamira_enable_ai_abilities();

        $GLOBALS['novamira_test_options']['home'] = 'https://new.example';

        self::assertFalse(novamira_is_enabled());
        self::assertTrue(novamira_is_domain_mismatch());

        novamira_enable_ai_abilities();

        self::assertTrue(novamira_is_enabled());
        self::assertFalse(novamira_is_domain_mismatch());
    }

    public function testASingleOnSaveAfterADomainChangeRelocksAndRedirects(): void
    {
        $GLOBALS['novamira_test_options']['home'] = 'https://a.example';
        novamira_enable_ai_abilities();
        $GLOBALS['novamira_test_options']['home'] = 'https://b.example';
        self::assertTrue(novamira_is_domain_mismatch());

        $location = $this->submitSettingsForm(enabled: true);

        self::assertSame(
            'https://example.test/wp-admin/admin.php?page=novamira-connect&novamira_settings_result=saved',
            $location,
        );
        self::assertSame('1', $GLOBALS['novamira_test_options']['novamira_ai_abilities_enabled']);
        self::assertSame('b.example', $GLOBALS['novamira_test_options']['novamira_ai_abilities_domain']);
        // The redirected request builds its notice, admin bar and hooks from these options.
        self::assertTrue(novamira_is_enabled());
        self::assertFalse(novamira_is_domain_mismatch());
    }

    public function testAnOffSaveThenAnOnSaveAfterADomainChangeStillWorks(): void
    {
        $GLOBALS['novamira_test_options']['home'] = 'https://a.example';
        novamira_enable_ai_abilities();
        $GLOBALS['novamira_test_options']['home'] = 'https://b.example';

        $off = $this->submitSettingsForm(enabled: false);

        self::assertStringEndsWith('novamira_settings_result=saved', $off);
        self::assertSame('0', $GLOBALS['novamira_test_options']['novamira_ai_abilities_enabled']);
        self::assertArrayNotHasKey('novamira_ai_abilities_domain', $GLOBALS['novamira_test_options']);
        self::assertFalse(novamira_is_enabled());
        self::assertFalse(novamira_is_domain_mismatch());

        $on = $this->submitSettingsForm(enabled: true);

        self::assertStringEndsWith('novamira_settings_result=saved', $on);
        self::assertSame('b.example', $GLOBALS['novamira_test_options']['novamira_ai_abilities_domain']);
        self::assertTrue(novamira_is_enabled());
        self::assertFalse(novamira_is_domain_mismatch());
    }

    public function testAnOnSaveWithoutADomainChangeLocksToTheCurrentHost(): void
    {
        $GLOBALS['novamira_test_options']['home'] = 'https://a.example';

        $location = $this->submitSettingsForm(enabled: true);

        self::assertStringEndsWith('novamira_settings_result=saved', $location);
        self::assertSame('1', $GLOBALS['novamira_test_options']['novamira_ai_abilities_enabled']);
        self::assertSame('a.example', $GLOBALS['novamira_test_options']['novamira_ai_abilities_domain']);
        self::assertTrue(novamira_is_enabled());

        $again = $this->submitSettingsForm(enabled: true);

        self::assertStringEndsWith('novamira_settings_result=saved', $again);
        self::assertSame('a.example', $GLOBALS['novamira_test_options']['novamira_ai_abilities_domain']);
        self::assertTrue(novamira_is_enabled());
    }

    public function testADomainChangeIsNotReenabledWithoutAnExplicitSave(): void
    {
        $GLOBALS['novamira_test_options']['home'] = 'https://a.example';
        novamira_enable_ai_abilities();
        $GLOBALS['novamira_test_options']['home'] = 'https://b.example';

        // A plain page load of the Configuration page: no form submission.
        novamira_handle_settings_form();

        self::assertSame('1', $GLOBALS['novamira_test_options']['novamira_ai_abilities_enabled']);
        self::assertSame('a.example', $GLOBALS['novamira_test_options']['novamira_ai_abilities_domain']);
        self::assertFalse(novamira_is_enabled());
        self::assertTrue(novamira_is_domain_mismatch());
    }

    public function testABadNonceLeavesTheOptionsUnchanged(): void
    {
        $GLOBALS['novamira_test_options']['home'] = 'https://a.example';
        novamira_enable_ai_abilities();
        $GLOBALS['novamira_test_options']['home'] = 'https://b.example';
        $before = $GLOBALS['novamira_test_options'];
        $GLOBALS['novamira_test_nonce_valid'] = false;

        try {
            $this->submitSettingsForm(enabled: true);
            self::fail('The nonce check should stop the save.');
        } catch (NovamiraTestRedirect $redirect) {
            self::fail('No redirect expected, got ' . $redirect->location);
        } catch (RuntimeException $exception) {
            self::assertSame('nonce check failed', $exception->getMessage());
        }

        self::assertSame($before, $GLOBALS['novamira_test_options']);
        self::assertTrue(novamira_is_domain_mismatch());
    }

    public function testAUserWhoCannotManageLeavesTheOptionsUnchanged(): void
    {
        $GLOBALS['novamira_test_options']['home'] = 'https://a.example';
        novamira_enable_ai_abilities();
        $GLOBALS['novamira_test_options']['home'] = 'https://b.example';
        $before = $GLOBALS['novamira_test_options'];
        $GLOBALS['novamira_test_can_manage'] = false;

        $_POST = ['novamira_submit' => '1', 'novamira_ai_abilities_enabled' => '1'];
        novamira_handle_settings_form();

        self::assertSame($before, $GLOBALS['novamira_test_options']);
        self::assertTrue(novamira_is_domain_mismatch());
    }

    /**
     * Submit the Configuration page toggle form and return the redirect location.
     */
    private function submitSettingsForm(bool $enabled): string
    {
        $_POST = ['novamira_submit' => '1', '_wpnonce' => 'nonce'];
        if ($enabled) {
            $_POST['novamira_ai_abilities_enabled'] = '1';
        }

        try {
            novamira_handle_settings_form();
        } catch (NovamiraTestRedirect $redirect) {
            return $redirect->location;
        } finally {
            $_POST = [];
        }

        self::fail('The settings save should redirect.');
    }
}
