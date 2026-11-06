<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Discovery documents are sent from `init` and end the request, so any output buffer a page-cache
 * plugin opened earlier would otherwise wrap them. Served in a child process: serve() exits and
 * closes buffers, neither of which may happen inside the test runner.
 */
final class DiscoveryServeOutputBuffersTest extends TestCase
{
    #[DataProvider('bufferSetups')]
    public function testOpenOutputBuffersCannotCorruptTheDocument(string $mode): void
    {
        [$output, $exitCode] = self::serve($mode);

        self::assertSame(0, $exitCode);
        self::assertSame('{"issuer":"https:\/\/example.test","scopes_supported":["full"]}', $output);
        self::assertSame(
            ['issuer' => 'https://example.test', 'scopes_supported' => ['full']],
            json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function bufferSetups(): iterable
    {
        yield 'removable buffers with appending callbacks' => ['removable'];
        yield 'under a non-removable buffer' => ['non-removable'];
    }

    /**
     * @return array{string, int}
     */
    private static function serve(string $mode): array
    {
        $command = sprintf(
            '%s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(__DIR__ . '/../fixtures/discovery-serve-runner.php'),
            escapeshellarg($mode),
        );
        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        return [implode("\n", $output), $exitCode];
    }
}
