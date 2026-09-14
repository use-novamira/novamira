<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FileAbilityHttpStatusesTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private static ?array $results = null;

    private static ?string $fixture = null;

    public static function setUpBeforeClass(): void
    {
        self::$fixture = sys_get_temp_dir() . '/novamira-file-statuses-' . bin2hex(random_bytes(8));
        if (!mkdir(self::$fixture)) {
            throw new RuntimeException('Could not create the filesystem-status fixture.');
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

    public function testEditNoChangeIsBadRequest(): void
    {
        self::assertSame(
            ['code' => 'no_change', 'status' => 400],
            self::results()['no_change'],
        );
    }

    public function testMissingPathRemainsStatuslessBecausePathProvenanceVaries(): void
    {
        self::assertSame(
            ['code' => 'path_not_found', 'status' => null],
            self::results()['missing_path'],
        );
    }

    public function testMissingParentDirectoryIsNotFound(): void
    {
        self::assertSame(
            ['code' => 'directory_not_found', 'status' => 404],
            self::results()['directory_not_found'],
        );
    }

    public function testNonEmptyDirectoryIsConflict(): void
    {
        self::assertSame(
            ['code' => 'directory_not_empty', 'status' => 409],
            self::results()['directory_not_empty'],
        );
    }

    public function testNotAFileIsBadRequestAtEveryCallSite(): void
    {
        $expected = ['code' => 'not_a_file', 'status' => 400];

        self::assertSame($expected, self::results()['edit_not_a_file']);
        self::assertSame($expected, self::results()['read_not_a_file']);
        self::assertSame($expected, self::results()['disable_not_a_file']);
        self::assertSame($expected, self::results()['enable_not_a_file']);
    }

    public function testUnknownFilesystemTypeIsBadRequest(): void
    {
        $result = self::results()['unknown_type'];
        if ($result === null) {
            self::markTestSkipped('posix_mkfifo is unavailable.');
        }

        self::assertSame(['code' => 'unknown_type', 'status' => 400], $result);
    }

    public function testSymlinkWriteIsForbidden(): void
    {
        $result = self::results()['symlink_write_rejected'];
        if ($result === null) {
            self::markTestSkipped('Symbolic links are unavailable.');
        }

        self::assertSame(['code' => 'symlink_write_rejected', 'status' => 403], $result);
    }

    public function testOtherClassifiedRefusalsCarryExpectedStatuses(): void
    {
        $expected = [
            'base64_not_supported' => ['code' => 'base64_not_supported', 'status' => 400],
            'unsupported_encoding' => ['code' => 'unsupported_encoding', 'status' => 400],
            'path_outside_base' => ['code' => 'path_outside_base', 'status' => 403],
            'outside_sandbox' => ['code' => 'outside_sandbox', 'status' => 403],
            'php_sandbox_required' => ['code' => 'php_sandbox_required', 'status' => 403],
            'no_match' => ['code' => 'no_match', 'status' => 400],
            'multiple_matches' => ['code' => 'multiple_matches', 'status' => 400],
            'protected_path' => ['code' => 'protected_path', 'status' => 403],
            'not_a_php_file' => ['code' => 'not_a_php_file', 'status' => 400],
            'disabled_marker_exists' => ['code' => 'disabled_marker_exists', 'status' => 409],
            'enabled_file_exists' => ['code' => 'enabled_file_exists', 'status' => 409],
            'not_a_directory' => ['code' => 'not_a_directory', 'status' => 400],
        ];

        foreach ($expected as $case => $result) {
            self::assertSame($result, self::results()[$case], $case);
        }
    }

    public function testEnvironmentFailuresRemainStatusless(): void
    {
        self::assertSame(
            ['code' => 'open_failed', 'status' => null],
            self::results()['open_failed'],
        );
        self::assertSame(
            ['code' => 'sandbox_not_found', 'status' => null],
            self::results()['sandbox_not_found'],
        );
    }

    public function testMissingSandboxDoesNotBecomeForbiddenForSandboxCandidate(): void
    {
        self::assertTrue(self::results()['missing_sandbox_php_path_allowed']);
    }

    public function testMissingSandboxDoesNotBecomeForbiddenThroughSymlinkedDocumentRoot(): void
    {
        $result = self::symlinkedSandboxResult();
        if (!$result['symlink_created']) {
            self::markTestSkipped('Symbolic links are unavailable.');
        }

        self::assertTrue($result['document_root_is_link']);
        self::assertFalse($result['sandbox_exists']);
        self::assertTrue($result['resolved_uses_real_root']);
        self::assertTrue($result['allowed']);
    }

    public function testUnresolvedCanonicalSandboxPathsUseTheirBoundary(): void
    {
        self::assertTrue(self::results()['unresolved_inside_sandbox_allowed']);
        self::assertSame(
            ['code' => 'outside_sandbox', 'status' => 403],
            self::results()['unresolved_outside_sandbox'],
        );
    }

    public function testIoErrorsRemainStatuslessInSource(): void
    {
        $abilities = dirname(__DIR__, levels: 2) . '/includes/abilities/';
        $files_by_code = [
            'not_readable' => ['read-file.php', 'list-directory.php'],
            'read_failed' => ['edit-file.php', 'read-file.php'],
            'write_failed' => ['edit-file.php', 'write-file.php'],
            'delete_failed' => ['delete-file.php'],
            'scan_failed' => ['delete-file.php'],
            'rename_failed' => ['enable-file.php'],
            'marker_create_failed' => ['disable-file.php'],
            'marker_delete_failed' => ['enable-file.php'],
            'not_writable' => ['edit-file.php'],
        ];

        foreach ($files_by_code as $code => $files) {
            $constructors = [];
            $pattern = sprintf("/new\\s+WP_Error\\s*\\(\\s*'%s'.*?\\);/s", preg_quote($code, '/'));

            foreach ($files as $file) {
                $source = file_get_contents($abilities . $file);
                if ($source === false) {
                    throw new RuntimeException(sprintf('Could not read %s.', $file));
                }

                preg_match_all($pattern, $source, $matches);
                array_push($constructors, ...$matches[0]);
            }

            self::assertNotSame([], $constructors, sprintf('No %s constructor was found.', $code));
            foreach ($constructors as $constructor) {
                self::assertDoesNotMatchRegularExpression(
                    '/[\'\"]status[\'\"]\\s*=>/',
                    $constructor,
                    sprintf('%s must remain statusless.', $code),
                );
            }
        }
    }

    /** @return array<string, mixed> */
    private static function results(): array
    {
        if (self::$results !== null) {
            return self::$results;
        }
        if (self::$fixture === null) {
            throw new RuntimeException('The filesystem-status fixture is unavailable.');
        }

        $root = dirname(__DIR__, levels: 2);
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
                public function get_error_data(): mixed { return $this->data; }
            }

            function __(string $text, string $domain = 'default'): string { return $text; }
            function apply_filters(string $hook, mixed $value, mixed ...$args): mixed { return $value; }
            function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
            function wp_register_ability(string $name, array $args): void {}

            require $argv[1] . '/includes/helpers.php';
            require $argv[1] . '/includes/abilities/edit-file.php';
            require $argv[1] . '/includes/abilities/write-file.php';
            require $argv[1] . '/includes/abilities/read-file.php';
            require $argv[1] . '/includes/abilities/delete-file.php';
            require $argv[1] . '/includes/abilities/disable-file.php';
            require $argv[1] . '/includes/abilities/enable-file.php';
            require $argv[1] . '/includes/abilities/list-directory.php';

            function error_result(mixed $result): array {
                if (!$result instanceof WP_Error) {
                    throw new RuntimeException('Expected WP_Error.');
                }

                $data = $result->get_error_data();
                return [
                    'code' => $result->get_error_code(),
                    'status' => is_array($data) ? ($data['status'] ?? null) : null,
                ];
            }

            function remove_tree(string $path): void {
                if (!file_exists($path) && !is_link($path)) {
                    return;
                }
                if (!is_dir($path) || is_link($path)) {
                    unlink($path);
                    return;
                }

                $iterator = new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS);
                foreach ($iterator as $item) {
                    remove_tree($item->getPathname());
                }
                rmdir($path);
            }

            $text_file = $fixture . '/content.txt';
            file_put_contents($text_file, 'alpha alpha');
            $results = [];
            $results['no_change'] = error_result(novamira_edit_file([
                'path' => $text_file,
                'old_string' => 'alpha',
                'new_string' => 'alpha',
            ]));
            $results['missing_path'] = error_result(novamira_read_file(['path' => $fixture . '/missing.txt']));

            $non_empty = $fixture . '/non-empty';
            mkdir($non_empty);
            file_put_contents($non_empty . '/child.txt', 'content');
            $results['directory_not_empty'] = error_result(novamira_delete_file([
                'path' => $non_empty,
                'recursive' => false,
            ]));
            $results['edit_not_a_file'] = error_result(novamira_edit_file([
                'path' => $non_empty,
                'old_string' => '',
                'new_string' => 'replacement',
            ]));
            $results['read_not_a_file'] = error_result(novamira_read_file(['path' => $non_empty]));

            $results['base64_not_supported'] = error_result(novamira_decode_write_content('', 'base64'));
            $results['unsupported_encoding'] = error_result(novamira_decode_write_content('', 'binary'));
            $results['directory_not_found'] = error_result(novamira_write_file([
                'path' => $fixture . '/missing-parent/content.txt',
                'content' => '',
                'create_directories' => false,
            ]));
            $results['path_outside_base'] = error_result(novamira_resolve_path(dirname($fixture) . '/outside.txt'));
            $results['outside_sandbox'] = error_result(novamira_validate_sandbox_path($text_file));
            $results['php_sandbox_required'] = error_result(novamira_check_php_sandbox($fixture . '/outside.php'));
            $canonical_sandbox = realpath($sandbox);
            if ($canonical_sandbox === false) {
                throw new RuntimeException('Could not resolve the test sandbox.');
            }
            $results['unresolved_inside_sandbox_allowed'] = novamira_validate_sandbox_path(
                $canonical_sandbox . '/missing.php',
            ) === true;
            $results['unresolved_outside_sandbox'] = error_result(novamira_validate_sandbox_path(
                $fixture . '/missing.php',
            ));

            set_error_handler(static fn(): bool => true);
            try {
                $symlink = $fixture . '/content-link.txt';
                $symlink_created = function_exists('symlink') && symlink($text_file, $symlink);
            } finally {
                restore_error_handler();
            }
            $results['symlink_write_rejected'] = $symlink_created
                ? error_result(novamira_reject_final_path_symlink($symlink))
                : null;

            $results['no_match'] = error_result(novamira_edit_file([
                'path' => $text_file,
                'old_string' => 'missing',
                'new_string' => 'replacement',
            ]));
            $results['multiple_matches'] = error_result(novamira_edit_file([
                'path' => $text_file,
                'old_string' => 'alpha',
                'new_string' => 'replacement',
            ]));
            $results['protected_path'] = error_result(novamira_delete_file(['path' => $fixture]));

            set_error_handler(static fn(): bool => true);
            try {
                $fifo = $fixture . '/named-pipe';
                $fifo_created = function_exists('posix_mkfifo') && posix_mkfifo($fifo, 0600);
            } finally {
                restore_error_handler();
            }
            $results['unknown_type'] = $fifo_created
                ? error_result(novamira_delete_file(['path' => $fifo]))
                : null;

            $sandbox_text = $sandbox . '/content.txt';
            file_put_contents($sandbox_text, 'content');
            $results['not_a_php_file'] = error_result(novamira_disable_file(['path' => $sandbox_text]));

            $sandbox_directory = $sandbox . '/directory.php';
            mkdir($sandbox_directory);
            $results['disable_not_a_file'] = error_result(novamira_disable_file(['path' => $sandbox_directory]));
            $results['enable_not_a_file'] = error_result(novamira_enable_file(['path' => $sandbox_directory]));

            $blocked_php = $sandbox . '/blocked.php';
            file_put_contents($blocked_php, '<?php');
            mkdir(novamira_sandbox_disabled_marker_path($blocked_php));
            $results['disabled_marker_exists'] = error_result(novamira_disable_file(['path' => $blocked_php]));

            $legacy_php = $sandbox . '/legacy.php';
            file_put_contents($legacy_php, '<?php');
            file_put_contents($legacy_php . '.disabled', '<?php');
            $results['enabled_file_exists'] = error_result(novamira_enable_file(['path' => $legacy_php . '.disabled']));
            $results['not_a_directory'] = error_result(novamira_list_directory(['path' => $text_file]));

            set_error_handler(static fn(): bool => true);
            try {
                $results['open_failed'] = error_result(novamira_collect_flat_entries(
                    $fixture . '/absent-directory',
                    '*',
                    false,
                ));
            } finally {
                restore_error_handler();
            }

            remove_tree($sandbox);
            $results['sandbox_not_found'] = error_result(novamira_validate_sandbox_path($text_file));
            $missing_sandbox_candidate = novamira_resolve_path('wp-content/novamira-sandbox/new.php');
            if ($missing_sandbox_candidate instanceof WP_Error) {
                throw new RuntimeException('Could not resolve the missing-sandbox candidate.');
            }
            $results['missing_sandbox_php_path_allowed'] = novamira_check_php_sandbox($missing_sandbox_candidate) === true;

            echo json_encode($results, JSON_THROW_ON_ERROR);
            PHP;

        $decoded = self::runChild($script, [$root, self::$fixture]);
        self::$results = $decoded;

        return self::$results;
    }

    /** @return array<string, bool> */
    private static function symlinkedSandboxResult(): array
    {
        if (self::$fixture === null) {
            throw new RuntimeException('The filesystem-status fixture is unavailable.');
        }

        $root = dirname(__DIR__, levels: 2);
        $script = <<<'PHP'
            $fixture = $argv[2] . '/symlinked-document-root';
            $real_root = $fixture . '/real';
            $linked_root = $fixture . '/link';
            mkdir($real_root . '/wp-content', permissions: 0755, recursive: true);

            set_error_handler(static fn(): bool => true);
            try {
                $symlink_created = function_exists('symlink') && symlink($real_root, $linked_root);
            } finally {
                restore_error_handler();
            }

            if (!$symlink_created) {
                echo json_encode(['symlink_created' => false], JSON_THROW_ON_ERROR);
                return;
            }

            define('ABSPATH', $linked_root . '/');
            define('WP_CONTENT_DIR', $linked_root . '/wp-content');
            define('NOVAMIRA_SANDBOX_DIR', $linked_root . '/wp-content/novamira-sandbox/');

            class WP_Error {
                public function __construct(
                    private string $code,
                    private string $message,
                    private mixed $data = null,
                ) {}
            }

            function __(string $text, string $domain = 'default'): string { return $text; }
            function apply_filters(string $hook, mixed $value, mixed ...$args): mixed { return $value; }
            function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }

            require $argv[1] . '/includes/helpers.php';

            $resolved = novamira_resolve_path('wp-content/novamira-sandbox/my-feature.php');
            if ($resolved instanceof WP_Error) {
                throw new RuntimeException('Could not resolve the sandbox candidate.');
            }

            $result = novamira_check_php_execution_sandbox($resolved);
            echo json_encode([
                'symlink_created' => true,
                'document_root_is_link' => is_link(rtrim(ABSPATH, '/')),
                'sandbox_exists' => is_dir(NOVAMIRA_SANDBOX_DIR),
                'resolved_uses_real_root' => str_starts_with($resolved, realpath($real_root) . DIRECTORY_SEPARATOR),
                'allowed' => $result === true,
            ], JSON_THROW_ON_ERROR);
            PHP;

        return self::runChild($script, [$root, self::$fixture]);
    }

    /**
     * @param list<string> $arguments
     * @return array<string, mixed>
     */
    private static function runChild(string $script, array $arguments): array
    {
        $command = [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=-1', '-r', $script, ...$arguments];
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

        return $decoded;
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
