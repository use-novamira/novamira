<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SkillSourcesCacheTest extends TestCase
{
    public function testSourceRegistryAndResultsAreCachedPerBlog(): void
    {
        $root = dirname(__DIR__, levels: 2);
        $script = <<<'PHP'
            define('ABSPATH', '/');
            $GLOBALS['blog_id'] = 1;
            $GLOBALS['loads'] = [];

            function get_current_blog_id(): int { return $GLOBALS['blog_id']; }
            function apply_filters(string $hook, mixed $value, mixed ...$args): mixed {
                if ($hook !== 'novamira_skill_lookup_sources') { return $value; }
                $blogId = get_current_blog_id();
                return [
                    'external' => [
                        'id' => 'external',
                        'priority' => 10,
                        'label' => 'Site ' . $blogId,
                        'loader' => 'test_load_skills',
                    ],
                ];
            }
            function test_load_skills(): array {
                $blogId = get_current_blog_id();
                $GLOBALS['loads'][$blogId] = ($GLOBALS['loads'][$blogId] ?? 0) + 1;
                return [[
                    'slug' => 'site-' . $blogId,
                    'name' => 'Site ' . $blogId,
                    'description' => 'Test',
                    'content' => 'Test',
                ]];
            }

            require $argv[1] . '/includes/skills/sources.php';

            $siteOne = \Novamira\Skills\Sources\all();
            $GLOBALS['blog_id'] = 2;
            $siteTwo = \Novamira\Skills\Sources\all();
            $GLOBALS['blog_id'] = 1;
            $siteOneAgain = \Novamira\Skills\Sources\all();

            echo json_encode([
                'site_one' => $siteOne,
                'site_two' => $siteTwo,
                'site_one_again' => $siteOneAgain,
                'loads' => $GLOBALS['loads'],
            ]);
            PHP;
        $command = sprintf(
            '%s -r %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($script),
            escapeshellarg($root),
        );
        $output = (string) shell_exec($command);
        $result = json_decode($output, associative: true);
        self::assertIsArray($result, $output);

        self::assertSame('site-1', $result['site_one'][0]['slug']);
        self::assertSame('Site 1', $result['site_one'][0]['source_label']);
        self::assertSame('site-2', $result['site_two'][0]['slug']);
        self::assertSame('Site 2', $result['site_two'][0]['source_label']);
        self::assertSame($result['site_one'], $result['site_one_again']);
        self::assertSame(['1' => 1, '2' => 1], $result['loads']);
    }
}
