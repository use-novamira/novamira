<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ChatCallsTest extends TestCase
{
    private static array $result;

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2);
        $command =
            escapeshellarg(PHP_BINARY)
            . ' '
            . escapeshellarg($root . '/tests/fixtures/chat-calls-runner.php')
            . ' '
            . escapeshellarg($root)
            . ' 2>&1';
        $output = (string) shell_exec($command);
        $decoded = json_decode($output, associative: true);
        self::assertIsArray($decoded, $output);
        self::$result = $decoded;
    }

    public function testACallWithoutTheModelsWordsStaysRefusedWhenItComesToRun(): void
    {
        $r = self::$result['no_words'];
        self::assertSame('approved', $r['status']);
        self::assertTrue($r['refused']);
        self::assertSame(['status' => 'approved', 'ran' => false, 'refused' => true], $r['at_run_time']);
    }

    public function testACheckThatWantsAPersonGivesNoCardWithoutTheModelsWords(): void
    {
        $r = self::$result['no_words_check'];
        self::assertSame('approved', $r['status']);
        self::assertFalse($r['must_ask']);
        self::assertSame(['status' => 'approved', 'ran' => false, 'refused' => true], $r['at_run_time']);
    }

    public function testACallTheChecksRefusedDoesNotComeBack(): void
    {
        self::assertSame(['status' => 'approved', 'ran' => false, 'refused' => true], self::$result['check_refused']);
    }

    public function testACallWithItsWordsWaitsForAPersonAndRunsOnceApproved(): void
    {
        self::assertSame('pending_approval', self::$result['with_words_status']);
        self::assertSame(['status' => 'approved', 'ran' => true, 'refused' => false], self::$result['with_words_at_run_time']);
    }

    public function testAReadNeedsNoWords(): void
    {
        self::assertSame('approved', self::$result['read']['status']);
        self::assertSame(['status' => 'approved', 'ran' => true, 'refused' => false], self::$result['read']['at_run_time']);
    }

    public function testWithApprovalsOnChatAsksOnlyWhatReachesTheCutoff(): void
    {
        self::assertSame(['small_write' => 'approved', 'over_cutoff' => 'pending_approval'], self::$result['aligned']);
        self::assertSame('approved', self::$result['skipped']);
    }

    public function testOneRequestOfAConversationRunsAtATime(): void
    {
        $lock = self::$result['lock'];
        self::assertSame(['reached' => true, 'status' => 200, 'waits' => true, 'released' => 1], $lock['held']);
        self::assertSame(['reached' => false, 'status' => 409, 'waits' => true, 'released' => 0], $lock['busy']);
        self::assertTrue($lock['released_after_throw']);
    }

    public function testARequestGoesAheadWhereTheDatabaseGivesNoAnswerAboutTheLock(): void
    {
        self::assertSame(
            ['reached' => true, 'status' => 200, 'waits' => true, 'released' => 0],
            self::$result['lock']['no_answer'],
        );
    }
}
