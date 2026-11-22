<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A recursive listing without include_hidden skips everything inside hidden directories, not only
 * the directories themselves, and a subdirectory that cannot be opened does not abort the listing.
 */
final class ListDirectoryRecursiveTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private static ?array $results = null;

    private static ?string $fixture = null;

    public static function setUpBeforeClass(): void
    {
        self::$fixture = sys_get_temp_dir() . '/novamira-list-directory-' . bin2hex(random_bytes(8));
        if (!mkdir(self::$fixture)) {
            throw new RuntimeException('Could not create the list-directory fixture.');
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$fixture !== null) {
            self::removeTree(self::$fixture);
        }

        self::$fixture = null;
        self::$results = null;
    }

    public function testHiddenDirectoryContentsAreSkippedByDefault(): void
    {
        self::assertSame(['inc', 'inc/setup.php', 'style.css'], self::results()['default']);
    }

    public function testIncludeHiddenStillListsHiddenDirectoryContents(): void
    {
        self::assertSame(
            [
                '.editorconfig',
                '.git',
                '.git/HEAD',
                '.git/objects',
                '.git/objects/ab',
                'inc',
                'inc/setup.php',
                'style.css',
            ],
            self::results()['include_hidden'],
        );
    }

    public function testUnreadableSubdirectoryIsListedWithoutAbortingTheListing(): void
    {
        $result = self::results()['unreadable'];
        if ($result === null) {
            self::markTestSkipped('Directory permissions cannot make a directory unreadable here.');
        }

        self::assertSame(['inc', 'inc/setup.php', 'private', 'style.css'], $result);
    }

    /** @return array<string, mixed> */
    private static function results(): array
    {
        if (self::$results !== null) {
            return self::$results;
        }
        if (self::$fixture === null) {
            throw new RuntimeException('The list-directory fixture is unavailable.');
        }

        $script = <<<'PHP'
            $fixture = $argv[2];
            $sandbox = $fixture . '/wp-content/novamira-sandbox';
            mkdir($sandbox, permissions: 0755, recursive: true);

            define('ABSPATH', $fixture . '/');
            define('WP_CONTENT_DIR', $fixture . '/wp-content');
            define('NOVAMIRA_SANDBOX_DIR', $sandbox . '/');

            class WP_Error {
                public function __construct(
                    private string $code,
                    private string $message,
                    private mixed $data = null,
                ) {}
                public function get_error_code(): string { return $this->code; }
            }

            function __(string $text, string $domain = 'default'): string { return $text; }
            function apply_filters(string $hook, mixed $value, mixed ...$args): mixed { return $value; }
            function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
            function wp_register_ability(string $name, array $args): void {}

            require $argv[1] . '/includes/helpers.php';
            require $argv[1] . '/includes/abilities/list-directory.php';

            function relative_paths(mixed $listing): array|string {
                if ($listing instanceof WP_Error) {
                    return $listing->get_error_code();
                }

                $paths = [];
                foreach ($listing['entries'] as $entry) {
                    $paths[] = str_replace('\\', '/', substr($entry['path'], strlen($listing['path']) + 1));
                }
                sort($paths);
                return $paths;
            }

            $theme = $fixture . '/wp-content/themes/demo';
            mkdir($theme . '/.git/objects/ab', permissions: 0755, recursive: true);
            mkdir($theme . '/inc', permissions: 0755, recursive: true);
            file_put_contents($theme . '/.git/HEAD', 'ref: refs/heads/main');
            file_put_contents($theme . '/.editorconfig', 'root = true');
            file_put_contents($theme . '/inc/setup.php', '<?php');
            file_put_contents($theme . '/style.css', '');

            $results = [];
            $results['default'] = relative_paths(novamira_list_directory(['path' => $theme, 'recursive' => true]));
            $results['include_hidden'] = relative_paths(novamira_list_directory([
                'path' => $theme,
                'recursive' => true,
                'include_hidden' => true,
            ]));

            $private = $theme . '/private';
            mkdir($private);
            file_put_contents($private . '/secret.php', '<?php');
            chmod($private, 0);
            $unreadable = !is_readable($private);
            try {
                $results['unreadable'] = $unreadable
                    ? relative_paths(novamira_list_directory(['path' => $theme, 'recursive' => true]))
                    : null;
            } finally {
                chmod($private, 0755);
            }

            echo json_encode($results, JSON_THROW_ON_ERROR);
            PHP;

        $command = [
            PHP_BINARY,
            '-d',
            'display_errors=stderr',
            '-d',
            'error_reporting=-1',
            '-r',
            $script,
            dirname(__DIR__, levels: 2),
            self::$fixture,
        ];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit_code = proc_close($process);

        self::assertSame(0, $exit_code, "stdout:\n{$stdout}\nstderr:\n{$stderr}");
        self::assertSame('', $stderr, $stderr);

        $decoded = json_decode($stdout, associative: true);
        self::assertIsArray($decoded, $stdout);
        self::$results = $decoded;

        return self::$results;
    }

    private static function removeTree(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (!is_dir($path) || is_link($path)) {
            unlink($path);
            return;
        }

        $iterator = new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $item) {
            self::removeTree($item->getPathname());
        }
        rmdir($path);
    }
}
