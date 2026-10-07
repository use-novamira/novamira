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

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AiAbilitiesDomainLockTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['novamira_test_options'] = [];
        $GLOBALS['novamira_test_home'] = 'https://example.test';

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
}
