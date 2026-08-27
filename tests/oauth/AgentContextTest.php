<?php
// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', '/');
}
$GLOBALS['novamira_test_skill_source_loads'] = 0;
if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}
if (!function_exists('wp_register_ability')) {
    function wp_register_ability(string $name, array $args): mixed
    {
        $GLOBALS['novamira_test_registered_abilities'][$name] = $args;
        return null;
    }
}
if (!function_exists('wp_has_ability')) {
    function wp_has_ability(string $name): bool
    {
        return false;
    }
}
if (!function_exists('wp_get_ability')) {
    function wp_get_ability(string $name): mixed
    {
        return $GLOBALS['novamira_test_abilities'][$name] ?? null;
    }
}
if (!function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        if ($hook === 'novamira_skill_lookup_sources') {
            return [
                'test-source' => [
                    'id' => 'test-source',
                    'priority' => 1,
                    'label' => 'Test',
                    'loader' => static function (): array {
                        $GLOBALS['novamira_test_skill_source_loads']++;
                        return [[
                            'slug' => 'theme-maintenance',
                            'name' => 'Theme Maintenance',
                            'description' => 'Safely maintain the active theme.',
                            'content' => 'Instructions',
                            'enable_agentic' => true,
                        ]];
                    },
                ],
            ];
        }
        if ($hook === 'novamira_discover_abilities_instructions') {
            return 'Filtered: ' . (string) $value;
        }
        return $value;
    }
}
if (!function_exists('do_action')) {
    function do_action(string $hook, mixed ...$args): void
    {
    }
}
if (!function_exists('novamira_build_server_instructions')) {
    function novamira_build_server_instructions(): string
    {
        return 'Shared server instructions';
    }
}
if (!function_exists('get_bloginfo')) {
    function get_bloginfo(string $show = ''): string
    {
        return $show === 'version' ? (string) ($GLOBALS['wp_version'] ?? '6.9.2') : '';
    }
}
if (!function_exists('get_locale')) {
    function get_locale(): string
    {
        return 'en_US';
    }
}
if (!function_exists('get_option')) {
    function get_option(string $name, mixed $default_value = false): mixed
    {
        return $GLOBALS['novamira_test_options'][$name] ?? $default_value;
    }
}
if (!function_exists('get_site_option')) {
    function get_site_option(string $name, mixed $default_value = false): mixed
    {
        return $default_value;
    }
}
if (!function_exists('get_current_blog_id')) {
    function get_current_blog_id(): int
    {
        return 1;
    }
}
if (!function_exists('is_multisite')) {
    function is_multisite(): bool
    {
        return false;
    }
}

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/compatibility.php';
require_once __DIR__ . '/../../includes/features/api.php';
\Novamira\Features\initialize_features();
require_once __DIR__ . '/../../includes/skills/sources.php';
require_once __DIR__ . '/../../includes/abilities/agent-context.php';
require_once __DIR__ . '/../../includes/skills/abilities/skill-get.php';
require_once __DIR__ . '/../../includes/skills/abilities/skill-write.php';
require_once __DIR__ . '/../../includes/skills/abilities/skill-edit.php';
require_once __DIR__ . '/../../includes/skills/abilities/skill-delete.php';

final class AgentContextTest extends TestCase
{
    public function testAgentContextIsReadonlyRestVisibleAndCompatibilityConsistent(): void
    {
        $registration = $GLOBALS['novamira_test_registered_abilities']['novamira/agent-context'];
        self::assertTrue($registration['meta']['show_in_rest']);
        self::assertSame(
            ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            $registration['meta']['annotations'],
        );
        self::assertSame('novamira_permission_callback', $registration['permission_callback']);

        $context = novamira_build_agent_context();
        self::assertSame(novamira_server_compatibility(), $context['server']);
        self::assertSame('Filtered: Shared server instructions', $context['instructions']);
        self::assertSame([[
            'slug' => 'theme-maintenance',
            'description' => 'Safely maintain the active theme.',
            'source' => 'test-source',
        ]], $context['skills']);
        self::assertSame('6.9.2', $context['environment']['wordpress_version']);
        self::assertSame(PHP_VERSION, $context['environment']['php_version']);
        self::assertSame('en_US', $context['environment']['locale']);
        self::assertSame(1, $GLOBALS['novamira_test_skill_source_loads']);
    }

    public function testAllFourSkillAbilitiesAreRestVisibleWithoutChangingPermissions(): void
    {
        \Novamira\Skills\Abilities\SkillGet\register();
        \Novamira\Skills\Abilities\SkillWrite\register();
        \Novamira\Skills\Abilities\SkillEdit\register();
        \Novamira\Skills\Abilities\SkillDelete\register();

        foreach (['skill-get', 'skill-write', 'skill-edit', 'skill-delete'] as $slug) {
            $registration = $GLOBALS['novamira_test_registered_abilities']['novamira/' . $slug];
            self::assertTrue($registration['meta']['show_in_rest']);
            self::assertSame('novamira_permission_callback', $registration['permission_callback']);
        }
    }
}
