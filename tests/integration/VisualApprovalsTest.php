<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Backend tool calls from Novamira Visual follow the approvals as MCP and Novamira Chat do, decided by the site: a call
 * that needs a person waits and runs only through that person's decision, once, while the checks still see what the
 * card showed. Everything that runs is recorded in the AI change history.
 */
final class VisualApprovalsTest extends TestCase
{
    private static array $result;

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2);
        $command =
            escapeshellarg(PHP_BINARY)
            . ' '
            . escapeshellarg($root . '/tests/fixtures/visual-approvals-runner.php')
            . ' '
            . escapeshellarg($root)
            . ' 2>&1';
        $output = (string) shell_exec($command);
        $decoded = json_decode($output, associative: true);
        self::assertIsArray($decoded, $output);
        self::$result = $decoded;
    }

    public function testSkippedApprovalsRunAndRecordTheCall(): void
    {
        $r = self::$result['skipped'];
        self::assertSame(1, $r['ran']);
        self::assertSame(['novamira/execute-php'], $r['recorded']);
        self::assertSame('visual', $r['session']);
    }

    public function testTheResultReachesTheAgentWithoutTheSitesSecrets(): void
    {
        self::assertSame(['redacted' => true, 'done' => true], self::$result['clear']['result']['result']);
        self::assertSame(['redacted' => true, 'done' => true], self::$result['approved']['result']['result']);
    }

    public function testACallTheChecksLetThroughRunsAtOnce(): void
    {
        self::assertSame(1, self::$result['clear']['ran']);
        self::assertSame(['novamira/execute-php'], self::$result['clear']['recorded']);
    }

    public function testARefusedCallDoesNotRun(): void
    {
        $r = self::$result['refused'];
        self::assertSame(['error' => 'Refused for a reason.', 'status' => 409], $r['result']);
        self::assertSame(0, $r['ran']);
    }

    public function testACallThatNeedsAPersonWaitsWithTheChecksWords(): void
    {
        $r = self::$result['pending'];
        self::assertSame('pending_approval', $r['result']['status']);
        self::assertSame('Changes users.', $r['result']['why']);
        self::assertSame('return 1;', $r['result']['code']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $r['result']['approval_id']);
        self::assertSame(0, $r['ran']);
    }

    public function testOnlyThePersonWhoseWorkspaceAskedCanDecide(): void
    {
        self::assertSame(404, self::$result['other_user']['result']['status']);
        self::assertSame(0, self::$result['other_user']['ran']);
    }

    public function testAnApprovalRunsTheCallOnce(): void
    {
        self::assertSame(1, self::$result['approved']['ran']);
        self::assertSame(['novamira/execute-php'], self::$result['approved']['recorded']);
        // The change history says what the AI said it would do, and who approved it.
        self::assertSame([['Returns one.', 1]], self::$result['approved']['said']);
        self::assertSame([['Returns one.', 0]], self::$result['clear']['said']);
        self::assertSame(404, self::$result['approved_again']['result']['status']);
        self::assertSame(1, self::$result['approved_again']['ran']);
    }

    public function testADenialDropsTheCall(): void
    {
        self::assertSame(['status' => 'denied'], self::$result['denied']['result']);
        self::assertSame(0, self::$result['denied']['ran']);
        self::assertSame(0, self::$result['denied_left']);
    }

    public function testAnApprovalOfSomethingThatChangedRunsNothing(): void
    {
        self::assertSame(409, self::$result['changed']['result']['status']);
        self::assertSame(0, self::$result['changed']['ran']);
    }

    public function testApprovalsSkippedAfterTheCardLetTheApprovalRun(): void
    {
        self::assertSame(1, self::$result['skipped_after_card']['ran']);
    }

    public function testAnUnknownRequestRunsNothing(): void
    {
        self::assertSame(404, self::$result['unknown_id']['result']['status']);
        self::assertSame(0, self::$result['unknown_id']['ran']);
    }

    public function testTheModelsAttentionReachesTheChecks(): void
    {
        self::assertSame([7, null, null], self::$result['attention']);
    }

    public function testTheWorkspaceLearnsTheModeAndCutoff(): void
    {
        self::assertSame(
            ['mode' => 'novamira', 'cutoff' => 5, 'instructions' => 'Rate cancelling an event 10.'],
            self::$result['workspace_approvals'],
        );
        self::assertSame('', self::$result['workspace_approvals_skipped']['instructions']);
    }
}
