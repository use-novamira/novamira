<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Regression: resource_identifier() must survive being called before wp-settings.php creates
 * $GLOBALS['wp_rewrite']. Plugins that resolve the current user on an early hook (e.g.
 * sanitize_comment_cookies) trigger determine_current_user before the rewrite object exists;
 * core's get_rest_url() then dereferences null and fatals.
 *
 * Stubs and the WP_Rewrite class live in fixtures/early-rewrite-stubs.php, loaded at runtime in
 * setUp() so that PHPUnit discovery never declares them in the parent process.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class EarlyRewriteResourceIdentifierTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/fixtures/early-rewrite-stubs.php';
        require_once __DIR__ . '/../../includes/oauth/bootstrap.php';
        $GLOBALS['novamira_test_realistic_rest_url'] = true;
    }

    public function testPrettyPermalinksWithoutRewriteObjectDoNotCrash(): void
    {
        $GLOBALS['novamira_test_permalink_structure'] = '/%postname%/';
        $GLOBALS['novamira_test_options']['permalink_structure'] = '/%postname%/';
        unset($GLOBALS['wp_rewrite']);

        $result = \Novamira\OAuth\resource_identifier();

        self::assertSame('https://example.test/wp-json/mcp/novamira-oauth', $result);
    }

    public function testIndexPermalinksWithoutRewriteObjectDoNotCrash(): void
    {
        $GLOBALS['novamira_test_permalink_structure'] = '/index.php/%postname%/';
        $GLOBALS['novamira_test_options']['permalink_structure'] = '/index.php/%postname%/';
        unset($GLOBALS['wp_rewrite']);

        $result = \Novamira\OAuth\resource_identifier();

        self::assertSame('https://example.test/index.php/wp-json/mcp/novamira-oauth', $result);
    }

    #[DataProvider('permalinkStructures')]
    public function testValueIdentityWithAndWithoutRewriteObject(string $structure, string $expected): void
    {
        $GLOBALS['novamira_test_permalink_structure'] = $structure;
        $GLOBALS['novamira_test_options']['permalink_structure'] = $structure;

        $GLOBALS['wp_rewrite'] = new \WP_Rewrite();
        $withRewrite = \Novamira\OAuth\resource_identifier();
        self::assertSame($expected, $withRewrite);

        unset($GLOBALS['wp_rewrite']);
        $withoutRewrite = \Novamira\OAuth\resource_identifier();

        self::assertSame(
            $withRewrite,
            $withoutRewrite,
            'URL must be identical whether $wp_rewrite was pre-existing or seeded by the fix',
        );
    }

    /**
     * Probe: with empty permalink_structure the guard is inert — resource_identifier() does not
     * seed $wp_rewrite even though the WP_Rewrite class exists in this process.
     */
    public function testEmptyPermalinkStructureDoesNotSeedRewriteObject(): void
    {
        $GLOBALS['novamira_test_options']['permalink_structure'] = '';
        $GLOBALS['novamira_test_permalink_structure'] = '';
        unset($GLOBALS['wp_rewrite']);
        $GLOBALS['novamira_test_realistic_rest_url'] = false;

        $result = \Novamira\OAuth\resource_identifier();

        self::assertFalse(
            isset($GLOBALS['wp_rewrite']) && $GLOBALS['wp_rewrite'] instanceof \WP_Rewrite,
            'Guard must not seed $wp_rewrite when permalink_structure is empty',
        );
        self::assertNotEmpty($result);
    }

    public function testFalsePermalinkStructureDoesNotSeedRewriteObject(): void
    {
        $GLOBALS['novamira_test_options']['permalink_structure'] = false;
        unset($GLOBALS['wp_rewrite']);
        $GLOBALS['novamira_test_realistic_rest_url'] = false;

        \Novamira\OAuth\resource_identifier();

        self::assertFalse(
            isset($GLOBALS['wp_rewrite']) && $GLOBALS['wp_rewrite'] instanceof \WP_Rewrite,
            'Guard must not seed $wp_rewrite when permalink_structure is false',
        );
    }

    public function testZeroStringPermalinkStructureDoesNotSeedRewriteObject(): void
    {
        $GLOBALS['novamira_test_options']['permalink_structure'] = '0';
        unset($GLOBALS['wp_rewrite']);
        $GLOBALS['novamira_test_realistic_rest_url'] = false;

        \Novamira\OAuth\resource_identifier();

        self::assertFalse(
            isset($GLOBALS['wp_rewrite']) && $GLOBALS['wp_rewrite'] instanceof \WP_Rewrite,
            'Guard must not seed $wp_rewrite when permalink_structure is "0" (falsy in PHP)',
        );
    }

    public function testMultisiteBlogOptionTruthySeedsRewriteObject(): void
    {
        $GLOBALS['novamira_test_is_multisite'] = true;
        $GLOBALS['novamira_test_blog_options']['permalink_structure'] = '/%postname%/';
        $GLOBALS['novamira_test_options']['permalink_structure'] = '';
        $GLOBALS['novamira_test_permalink_structure'] = '/%postname%/';
        unset($GLOBALS['wp_rewrite']);

        $result = \Novamira\OAuth\resource_identifier();

        self::assertInstanceOf(
            \WP_Rewrite::class,
            $GLOBALS['wp_rewrite'] ?? null,
            'Guard must seed $wp_rewrite when multisite blog option is truthy',
        );
        self::assertSame('https://example.test/wp-json/mcp/novamira-oauth', $result);
    }

    public function testMultisiteBothFalsyDoesNotSeedRewriteObject(): void
    {
        $GLOBALS['novamira_test_is_multisite'] = true;
        $GLOBALS['novamira_test_blog_options']['permalink_structure'] = '';
        $GLOBALS['novamira_test_options']['permalink_structure'] = '';
        $GLOBALS['novamira_test_permalink_structure'] = '';
        unset($GLOBALS['wp_rewrite']);
        $GLOBALS['novamira_test_realistic_rest_url'] = false;

        \Novamira\OAuth\resource_identifier();

        self::assertFalse(
            isset($GLOBALS['wp_rewrite']) && $GLOBALS['wp_rewrite'] instanceof \WP_Rewrite,
            'Guard must not seed when both multisite and single-site options are falsy',
        );
    }

    /** @return array<string, array{string, string}> */
    public static function permalinkStructures(): array
    {
        return [
            'pretty' => ['/%postname%/', 'https://example.test/wp-json/mcp/novamira-oauth'],
            'index-based' => [
                '/index.php/%postname%/',
                'https://example.test/index.php/wp-json/mcp/novamira-oauth',
            ],
        ];
    }
}
