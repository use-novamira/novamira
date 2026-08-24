<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AdminNoticesTest extends TestCase
{
    public function testPersistentNoticeUsesTheSharedRendererAndStoresDismissal(): void
    {
        $root = dirname(__DIR__, levels: 2);
        $script = <<<'PHP'
            define('ABSPATH', '/');
            define('NOVAMIRA_PLUGIN_URL', 'https://example.test/wp-content/plugins/novamira/');
            define('NOVAMIRA_VERSION', 'test');

            $GLOBALS['actions'] = [];
            $GLOBALS['meta'] = [];
            $GLOBALS['notices'] = [];
            $GLOBALS['scripts'] = [];

            function add_action(string $hook, callable|string $callback): bool
            {
                $GLOBALS['actions'][] = [$hook, $callback];
                return true;
            }

            function novamira_current_user_can_manage(): bool
            {
                return true;
            }

            function sanitize_key(string $key): string
            {
                return strtolower((string) preg_replace('/[^a-zA-Z0-9_\-]/', '', $key));
            }

            function check_admin_referer(string $action): void
            {
                $GLOBALS['nonce_action'] = $action;
            }

            function update_user_meta(int $user_id, string $key, string $value): void
            {
                $GLOBALS['meta'][$key] = $value;
            }

            function wp_die(mixed $message = '', mixed $title = '', array $args = []): never
            {
                throw new RuntimeException((string) $message);
            }

            function get_current_user_id(): int
            {
                return 7;
            }

            function get_user_meta(int $user_id, string $key, bool $single = false): mixed
            {
                return $GLOBALS['meta'][$key] ?? '';
            }

            function admin_url(string $path = ''): string
            {
                return 'https://example.test/wp-admin/' . $path;
            }

            function add_query_arg(array $args, string $url): string
            {
                return $url . '?' . http_build_query($args);
            }

            function wp_nonce_url(string $url, string $action): string
            {
                return $url . '&_wpnonce=' . rawurlencode($action);
            }

            function wp_admin_notice(string $message, array $args = []): void
            {
                $GLOBALS['notices'][] = [$message, $args];
            }

            function wp_enqueue_script(
                string $handle,
                string $src,
                array $deps = [],
                string|bool|null $ver = false,
                array|bool $args = [],
            ): void {
                $GLOBALS['scripts'][] = [$handle, $src, $ver, $args];
            }

            require $argv[1] . '/includes/admin-notices.php';

            novamira_render_persistent_admin_notice(
                'Message',
                'novamira_test_notice',
                'state',
                ['type' => 'warning'],
            );
            $rendered = $GLOBALS['notices'];

            $_GET = [
                'novamira_notice_dismiss' => 'novamira_test_notice',
                'novamira_notice_value' => 'state',
            ];
            try {
                novamira_handle_persistent_admin_notice_dismiss();
            } catch (RuntimeException) {
            }

            $GLOBALS['notices'] = [];
            novamira_render_persistent_admin_notice('Message', 'novamira_test_notice', 'state');

            echo json_encode([
                'actions' => $GLOBALS['actions'],
                'rendered' => $rendered,
                'scripts' => $GLOBALS['scripts'],
                'nonce_action' => $GLOBALS['nonce_action'] ?? '',
                'meta' => $GLOBALS['meta'],
                'rendered_after_dismissal' => $GLOBALS['notices'],
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
        self::assertSame('admin_init', $result['actions'][0][0]);
        self::assertSame('Message', $result['rendered'][0][0]);
        self::assertTrue($result['rendered'][0][1]['dismissible']);
        self::assertContains('novamira-persistent-notice', $result['rendered'][0][1]['additional_classes']);
        self::assertStringContainsString(
            'novamira_notice_dismiss=novamira_test_notice',
            $result['rendered'][0][1]['attributes']['data-novamira-dismiss-url'],
        );
        self::assertSame('novamira-admin-notices', $result['scripts'][0][0]);
        self::assertSame('novamira_dismiss_admin_notice_novamira_test_notice_state', $result['nonce_action']);
        self::assertSame('state', $result['meta']['novamira_test_notice']);
        self::assertSame([], $result['rendered_after_dismissal']);
    }
}
