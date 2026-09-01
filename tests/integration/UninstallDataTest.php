<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class UninstallDataTest extends TestCase
{
    public function testLegacyDefaultsStillDeleteChatAndOauthButPreserveUserContent(): void
    {
        $result = $this->runUninstall(null);

        self::assertContains('DROP TABLE IF EXISTS `wp_novamira_chat_sessions`', $result['queries']);
        self::assertContains('DROP TABLE IF EXISTS `wp_novamira_oauth_clients`', $result['queries']);
        self::assertContains(
            'DROP TABLE IF EXISTS `wp_novamira_oauth_pending_authorizations`',
            $result['queries'],
        );
        self::assertSame([], $result['deleted_posts']);
    }

    public function testExplicitPreservationKeepsChatSkillsMemoriesAndOauth(): void
    {
        $result = $this->runUninstall([
            'delete_oauth' => false,
            'delete_application_passwords' => false,
            'delete_memories' => false,
            'delete_user_skills' => false,
            'delete_chat_sessions' => false,
        ]);

        self::assertSame([], $result['queries']);
        self::assertSame([], $result['deleted_posts']);
        self::assertNotContains('novamira_chat_schema_version', $result['deleted_options']);
    }

    public function testSelectedContentIsPermanentlyDeleted(): void
    {
        $result = $this->runUninstall([
            'delete_oauth' => false,
            'delete_application_passwords' => false,
            'delete_memories' => true,
            'delete_user_skills' => true,
            'delete_chat_sessions' => true,
        ]);

        self::assertContains('DROP TABLE IF EXISTS `wp_novamira_chat_sessions`', $result['queries']);
        self::assertSame([101, 202], $result['deleted_posts']);
        self::assertContains('novamira_chat_schema_version', $result['deleted_options']);
    }

    /**
     * @param array<string, bool>|null $plan
     * @return array{queries: list<string>, deleted_posts: list<int>, deleted_options: list<string>}
     */
    private function runUninstall(?array $plan): array
    {
        $root = dirname(__DIR__, levels: 2);
        $script = <<<'PHP'
            define('WP_UNINSTALL_PLUGIN', true);

            $GLOBALS['stored_plan'] = json_decode($argv[2], true);
            $GLOBALS['events'] = ['queries' => [], 'deleted_posts' => [], 'deleted_options' => []];

            class wpdb
            {
                public string $prefix = 'wp_';
                public string $posts = 'wp_posts';
                public string $usermeta = 'wp_usermeta';

                public function prepare(string $query, mixed ...$args): string
                {
                    foreach ($args as $arg) {
                        if (str_contains($query, '%i')) {
                            $query = preg_replace('/%i/', '`' . (string) $arg . '`', $query, limit: 1) ?? $query;
                            continue;
                        }
                        $query = preg_replace('/%s/', "'" . (string) $arg . "'", $query, limit: 1) ?? $query;
                    }
                    return $query;
                }

                /** @return list<int> */
                public function get_col(string $query): array
                {
                    if (str_contains($query, "'novamira_skill'")) {
                        return [101];
                    }
                    if (str_contains($query, "'novamira_memory'")) {
                        return [202];
                    }
                    return [];
                }

                public function query(string $query): void
                {
                    $GLOBALS['events']['queries'][] = $query;
                }
            }

            $GLOBALS['wpdb'] = new wpdb();

            function get_site_option(string $name, mixed $default_value = false): mixed
            {
                unset($name, $default_value);
                return $GLOBALS['stored_plan'];
            }

            function delete_site_option(string $name): void
            {
                unset($name);
            }

            function delete_option(string $name): void
            {
                $GLOBALS['events']['deleted_options'][] = $name;
            }

            function wp_clear_scheduled_hook(string $hook): void
            {
                unset($hook);
            }

            function wp_delete_post(int $post_id, bool $force_delete = false): void
            {
                unset($force_delete);
                $GLOBALS['events']['deleted_posts'][] = $post_id;
            }

            function is_multisite(): bool
            {
                return false;
            }

            require $argv[1] . '/uninstall.php';
            echo json_encode($GLOBALS['events'], JSON_THROW_ON_ERROR);
            PHP;

        $command = sprintf(
            '%s -r %s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($script),
            escapeshellarg($root),
            escapeshellarg(json_encode($plan, JSON_THROW_ON_ERROR)),
        );
        $output = (string) shell_exec($command);
        $decoded = json_decode($output, true);

        self::assertIsArray($decoded, 'Uninstall subprocess failed: ' . $output);
        return $decoded;
    }
}
