<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Runs the change journal against a real MySQL database. Skipped unless NOVAMIRA_TEST_MYSQL is set to
 * "host:port:user:password:database", for example with a throwaway container:
 *
 *   docker run -d --rm --name novamira-journal-mysql -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=wp -p 33306:3306 mysql:8.0
 *   NOVAMIRA_TEST_MYSQL=127.0.0.1:33306:root:root:wp vendor/bin/phpunit --filter JournalMysqlTest
 *
 * The database is wiped of the test tables on every run.
 */
final class JournalMysqlTest extends TestCase
{
    private static array $result;

    public static function setUpBeforeClass(): void
    {
        $dsn = getenv('NOVAMIRA_TEST_MYSQL');
        if (!is_string($dsn) || $dsn === '') {
            return;
        }
        $root = dirname(__DIR__, 2);
        $command =
            escapeshellarg(PHP_BINARY)
            . ' '
            . escapeshellarg($root . '/tests/fixtures/journal-mysql.php')
            . ' '
            . escapeshellarg($root);
        foreach (explode(':', $dsn, 5) as $part) {
            $command .= ' ' . escapeshellarg($part);
        }
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stderr . $stdout);
        self::$result = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
    }

    protected function setUp(): void
    {
        if (!isset(self::$result)) {
            self::markTestSkipped('Set NOVAMIRA_TEST_MYSQL=host:port:user:password:database to run against MySQL.');
        }
    }

    public function testAnExecutionIsRecordedWithItsEmailsAndRequestsHeld(): void
    {
        self::assertSame(['Hello'], self::$result['held_view']);
        self::assertSame(1, self::$result['held_count']);
        $r = self::$result;
        self::assertSame(5, $r['update_rows']);
        self::assertSame(1, $r['delete_rows']);
        self::assertTrue($r['mail_return']);
        self::assertTrue($r['http_post_untouched']);
        self::assertTrue($r['http_get_untouched']);
        self::assertSame(
            [
                'state' => 'completed',
                'changed_rows' => ['wp_posts' => 7, 'wp_options' => 1, 'wp_nopk' => 2],
                'unverified' => 0,
                'held_emails' => 1,
                'sent_requests' => 1,
            ],
            $r['summary'],
        );
        self::assertTrue($r['filter_removed']);
        self::assertTrue($r['changed_on_site']);
        self::assertSame('cache2', $r['transient_changed']);
    }

    public function testAStopEndsTheExecutionBeforeTheQueryAndEverythingAfterIt(): void
    {
        $r = self::$result;
        self::assertTrue($r['halt_thrown']);
        self::assertFalse($r['halt_swallowed_by_exception']);
        self::assertNull($r['read_after_halt']);
        self::assertTrue($r['halt_is_error']);
        self::assertStringContainsString('Nothing from this query ran', $r['halt_message']);
        self::assertStringContainsString('running the same code again would repeat them', $r['halt_message']);
        self::assertTrue($r['nothing_changed_by_halt']);
        self::assertSame(6, $r['approved_rows']);
        self::assertSame([true, true], $r['escaped_reply']);
        self::assertSame('7', $r['query_after_escaped_stop']);
    }

    public function testTheJournalProtectsItselfAndSkipsReadOnlyAbilities(): void
    {
        $r = self::$result;
        self::assertTrue($r['journal_protected']);
        self::assertTrue($r['read_ability_not_journaled']);
        self::assertTrue($r['wp_cli_change_journaled']);
        self::assertTrue($r['wp_cli_change_says_so']);
        self::assertSame([true, false], $r['release']);
        self::assertSame('Body', $r['held_shows_text']);
        self::assertSame(['to' => 'b@example.test', 'subject' => 'Release me'], $r['sent_text_forgotten']);
        self::assertFalse($r['attachment_same_flag']);
        self::assertTrue($r['attachment_same_sent']);
        self::assertSame([true, false], $r['attachment_other']);
        self::assertSame([true, false], $r['attachment_gone']);
        self::assertTrue($r['attachment_gone_can_be_discarded']);
        self::assertSame(['to', 'subject'], $r['discarded_text_forgotten']);
        self::assertSame(['Attachment same'], $r['attachment_mails_sent']);
        self::assertSame(['Release me'], $r['mails_sent']);
    }

    public function testSecretsAreNeverKept(): void
    {
        $r = self::$result;
        self::assertSame(0, $r['secrets_in_journal']);
    }

    public function testWithoutConfirmationsTheJournalRecordsButNeverBrakes(): void
    {
        $r = self::$result;
        self::assertSame(7, $r['unbraked_rows']);
        self::assertNull($r['unbraked_mail']);
        self::assertSame(['changed_rows' => ['wp_posts' => 7], 'held_emails' => 0], $r['unbraked_summary']);
        // One measure, one read before, one journal insert and one query giving the AI its insert id back, however
        // many rows.
        self::assertSame(4, $r['extra_queries_for_one_update']);
    }

    public function testEmptyingTheJournalRemovesRowsAndTableCopies(): void
    {
        $r = self::$result;
        self::assertSame(0, $r['cleared_rows']);
        self::assertSame(0, $r['cleared_backups']);
    }

    public function testEmailsFromEventsTheAiScheduledAreHeld(): void
    {
        $r = self::$result;
        self::assertSame(1, $r['watched_events']);
        self::assertSame(['Digest 7'], $r['cron_held']);
        self::assertSame([], $r['cron_setting_off_holds_nothing']);
        self::assertTrue($r['cron_unmarked_sent']);
        self::assertSame(1, $r['cron_marked_sent_once_later']);
    }

    public function testAWriteIsCountedBeforeItRunsWithoutChangingAnything(): void
    {
        $r = self::$result;
        self::assertSame(
            [
                ['operation' => 'DELETE', 'table' => 'wp_posts', 'rows' => 5, 'capped' => false],
                ['operation' => 'UPDATE', 'table' => 'wp_posts', 'rows' => 2, 'capped' => false],
                ['operation' => 'INSERT', 'table' => 'wp_posts', 'rows' => 3, 'capped' => false],
                ['operation' => 'TRUNCATE', 'table' => 'wp_nopk', 'rows' => 2, 'capped' => false],
                null,
            ],
            $r['measured'],
        );
        self::assertSame(5, $r['measure_changed_nothing']);
        self::assertSame(2_097_152, $r['parse_limit']);
        self::assertNull($r['big_query_measured']);
        self::assertTrue($r['big_placeholder_stopped']);
        self::assertStringContainsString('larger than it can read', $r['big_placeholder_message']);
        self::assertTrue($r['big_placeholder_kept']);
        self::assertTrue($r['big_plain_ran']);
    }

    public function testWhatDidNotHappenDoesNotCountAndAddingToATableKeepsNoCopy(): void
    {
        $r = self::$result;
        self::assertSame([
            'failed_alter_counts_nothing' => [],
            'failed_alter_keeps_no_copy' => true,
            'adding_an_index_runs' => true,
            'adding_an_index_counts_nothing' => [],
            'adding_an_index_keeps_no_copy' => true,
        ], $r['approval_cap']);
        self::assertSame([true, true, true, false, false, false, false, false], $r['alter_only_adds']);
        self::assertSame([
            'one_transient' => true,
            'transient_then_setting' => false,
            'parenthesis_in_a_value' => true,
            'columns_the_other_way_round' => false,
            'name_second_with_backticks' => false,
        ], $r['untracked_insert']);
    }

    public function testTheCardSaysWhatAWriteTouchesInWords(): void
    {
        $words = self::$result['card_words'];
        self::assertSame(
            'Deletes 3 items in wp_posts: 2 post, 1 product. For example: "card one", "card two", "card three".',
            $words['known_table'],
        );
        self::assertSame('Deletes 10 row(s) in wp_cap.', $words['other_table']);
        self::assertSame('Empties wp_cap: all 10 row(s).', $words['whole_table']);
        self::assertSame('Adds 1 row(s) to wp_cap.', $words['inserts']);
    }

    public function testRecordingNeverChangesWhatAQueryDoes(): void
    {
        $r = self::$result;
        self::assertSame(3, $r['ordered_update_rows']);
        self::assertFalse($r['failed_insert']);
        self::assertSame(2001, $r['bulk_rows']);
        self::assertSame(1, $r['recording']['unverified']);
        self::assertTrue($r['cli_said']);
        self::assertSame([$r['recording']['kept_id']], $r['recording']['inserted']);
        self::assertSame(0, $r['recording']['bulk_saved']);
    }

    public function testCopiesKeepNoSecretsAndUnsavableWritesAreListed(): void
    {
        $r = self::$result;
        self::assertSame(1, $r['users_copies']);
        self::assertSame(0, $r['secrets_in_table_copies']);
        self::assertSame(1, $r['passwords_in_users_copy']);
        self::assertSame(0, $r['sessions_in_copies']);
        self::assertGreaterThan(0, $r['unsaved_listed']);
        self::assertSame('trash', $r['unsaved_ran']);
    }

    public function testTheHistoryCannotBeChangedCountingRunsNoCodeAndInsertIdsAreKept(): void
    {
        $r = self::$result;
        self::assertTrue($r['history_read_runs']);
        self::assertTrue($r['history_read_runs_unconfirmed']);
        self::assertTrue($r['history_write_blocked_unconfirmed']);
        self::assertTrue($r['novamira_option_own_write']);
        self::assertTrue($r['novamira_option_ai_write_blocked']);
        self::assertSame('own', $r['novamira_option_kept']);
        self::assertTrue($r['other_option_ai_write_runs']);
        self::assertTrue($r['novamira_option_named_in_message']);
        self::assertTrue($r['value_mentioning_novamira_runs']);
        self::assertSame(
            [
                'plugin_list_value' => null,
                'insert_value' => null,
                'quote_inside_value' => null,
                'update' => 'the option novamira_confirmation_settings',
                'like' => 'the option novamira%',
                'in_list' => 'the option novamira_read_mode',
                'second_row' => 'the option novamira_features',
                'columns_the_other_way_round' => 'the option novamira_x',
                'renamed_into' => 'the option novamira_a',
                'big_value' => 'the option novamira_x',
                'transient' => null,
                'transient_timeout' => null,
                'autoload_only' => null,
                'comment' => null,
                'line_comment' => null,
                'executable_comment' => 'the option novamira_x',
            ],
            $r['novamira_option_named'],
        );
        self::assertTrue($r['by_id_blocked']);
        self::assertSame('kept', $r['by_id_kept']);
        self::assertTrue($r['by_id_other_runs']);
        self::assertTrue($r['transient_cleanup_runs']);
        self::assertSame(0, $r['transients_left']);
        self::assertTrue($r['history_quoted_runs']);
        self::assertTrue($r['history_maintenance_runs']);
        self::assertTrue($r['history_run_comment_blocked']);
        self::assertTrue($r['history_other_plugin_not_stopped']);
        self::assertTrue($r['history_write_blocked']);
        self::assertNull($r['measure_with_sleep']);
        self::assertNull($r['measure_with_subquery']);
        self::assertSame(1, $r['measure_with_in_list']);
        self::assertSame(['wpdb' => true, 'sql' => true], $r['insert_id_kept']);
    }

    public function testAConditionThatCannotBeReadTwiceIsNeverEvaluatedAhead(): void
    {
        $r = self::$result;
        self::assertSame(3, $r['variable_evaluated_once']);
        self::assertSame(['unverified' => 2, 'changed' => ['wp_big' => 2], 'said_so' => true], $r['unread']);
        self::assertSame(1, $r['unreadable_listed']);
        self::assertSame(13, $r['unreadable_rows_kept']);
    }

    public function testRowsAreMeasuredBeforeTheyAreLoaded(): void
    {
        $r = self::$result;
        self::assertSame(1, $r['big_unverified']);
        self::assertLessThan(8, $r['big_peak_mb']);
    }

    public function testAWriteRunsEvenWhenItCannotBeSaved(): void
    {
        $r = self::$result;
        self::assertSame(10, $r['approved_big_rows']);
        self::assertSame(['changed' => ['wp_big' => 10], 'unverified' => 0], $r['approved_big']);
        self::assertSame(1, $r['too_large_listed']);
        self::assertGreaterThan(0, $r['too_large_ran']);
    }

    public function testWritesTheHistoryCannotFullySeeAreListedAsNotRecorded(): void
    {
        $r = self::$result;
        self::assertSame(['unverified' => 1, 'changed' => []], $r['replace']);
        self::assertSame(['changed' => ['wp_race' => 2], 'unverified' => 1], $r['race']);
    }

    public function testTheAiCodeSeesItsOwnQueryResults(): void
    {
        $r = self::$result;
        self::assertTrue($r['wpdb_state_kept']);
        self::assertSame(0, $r['insert_id_zero']);
        self::assertTrue($r['history_readable_unbraked']);
    }

    public function testAnEmailWhoseSendingDiedIsOnlyClosed(): void
    {
        $r = self::$result;
        self::assertSame([false, true], $r['died_mail']);
        self::assertFalse($r['died_mail_sent']);
    }

    public function testOnlyTheScheduledEventTheAiMarkedIsHeld(): void
    {
        $r = self::$result;
        self::assertSame(['Twin the AI'], $r['twin_held']);
        self::assertTrue($r['twin_sent']);
    }

    public function testACompositeKeyIsRecorded(): void
    {
        $r = self::$result;
        self::assertSame(['changed' => ['wp_term_relationships' => 3], 'unverified' => 0], $r['composite_summary']);
        self::assertSame(['before' => '0', 'now' => '5'], $r['composite_saved']);
    }

    public function testTheCallIsRecordedAndWritesWithNothingSavedAreListed(): void
    {
        $r = self::$result;
        self::assertSame('{"code":"echo \"[redacted]\";"}', $r['call_recorded']);
        self::assertTrue($r['not_restorable']);
    }

    public function testRestoringOneOperationAsksAboutWorkInBetween(): void
    {
        self::assertSame([
            'operation' => true,
            'request' => true,
            'latest' => 0,
            'guide_asks' => true,
            'guide_hand_edits' => true,
            'unknown' => null,
            'after_one_more' => 1,
        ], self::$result['operation_detail']);
    }

    public function testThePageShowsEachOperationOnItsOwn(): void
    {
        self::assertSame([
            'count' => 2,
            'own_ids' => true,
            'second_changes' => ['Item "Grouped page": Elementor layout changed', 'Setting "site_tagline": created'],
            'first_changes' => ['Item "Grouped page": created'],
            'one_step_each' => [1, 1],
            'request_description' => 'Added the [redacted] Contacts page',
        ], self::$result['operations']);
    }

    public function testCallsOfOneRequestAreOneGroupInPlainWords(): void
    {
        $r = self::$result;
        self::assertTrue($r['group']['same_group']);
        self::assertTrue($r['group']['other_group']);
        self::assertTrue($r['group']['joins_latest']);
        self::assertSame(2, $r['group']['steps']);
        self::assertSame(
            ['Item "Grouped page": created, Elementor layout changed', 'Setting "site_tagline": created'],
            $r['group']['changes'],
        );
        self::assertSame(1, $r['group']['technical']);
        self::assertSame(0, $r['group']['request_not_kept']);
        self::assertTrue($r['reads_not_listed']);
    }

    public function testTheAiDescribesWhatItDidWithSecretsHidden(): void
    {
        $r = self::$result;
        self::assertSame('Added the [redacted] Contacts page', $r['description']['description']);
        self::assertTrue($r['search']);
        self::assertNull($r['nothing_to_describe']);
        self::assertSame($r['approved_group'], $r['described_by_approval']);
        self::assertNull($r['other_users_approval']);
    }

    public function testAnyAiCanReadWhatWasDoneWithoutBeingToldWhoseItWas(): void
    {
        $r = self::$result['other_admin'];
        self::assertTrue($r['listed']);
        self::assertTrue($r['whose_hidden']);
        self::assertSame(1, $r['on_the_page']);
        self::assertSame(1, $r['steps']);
        self::assertSame('novamira/execute-php', $r['call']);
        self::assertTrue($r['details_whose_hidden']);
        self::assertSame(1, $r['saved_rows_total']);
        self::assertTrue($r['how_to_restore']);
        self::assertNull($r['no_group']);
    }

    public function testThePlaceholderOfASecretNeverReplacesARealValue(): void
    {
        $r = self::$result;
        self::assertSame(['stopped' => true, 'error' => true, 'value' => 'key SECRET-KEY'], $r['mask_real']);
        self::assertSame(['stopped' => true, 'error' => true, 'value' => 'key SECRET-KEY'], $r['mask_real_alias']);
        self::assertSame(['stopped' => false, 'error' => false, 'value' => 'nothing secret, the word [redacted]'], $r['mask_word']);
        self::assertSame(['stopped' => true, 'error' => true, 'value' => '[redacted] and SECRET-KEY'], $r['mask_both']);
        self::assertSame(['stopped' => false, 'error' => false, 'value' => 'still [redacted] there'], $r['mask_already']);
        self::assertSame(['stopped' => false, 'error' => false, 'value' => 'about [redacted]'], $r['mask_empty']);
        self::assertSame(['stopped' => false, 'error' => false, 'value' => 'key SECRET-KEY'], $r['mask_other_column']);
        self::assertSame(['stopped' => false, 'error' => false, 'value' => 'new [redacted]'], $r['mask_new_row']);
        self::assertSame(['stopped' => false, 'error' => false, 'value' => 'key changed'], $r['mask_normal']);
        self::assertSame(['stopped' => true, 'error' => true, 'value' => 'b'], $r['mask_not_kept']);
    }

    public function testTextInAnOldCharsetIsKept(): void
    {
        $r = self::$result['charset'];
        self::assertTrue($r['mail_result']);
        self::assertSame('Città', $r['subject']);
        self::assertSame('Conferma è ok', $r['message']);
        self::assertSame(1, $r['held_count']);
        self::assertTrue(self::$result['charset_call']);
    }

    public function testAValueThatCannotBeStoredCostsOnlyThatValue(): void
    {
        $r = self::$result['binary_recording'];
        self::assertSame(0, $r['other_unverified']);
        self::assertSame(['wp_bin' => 2], $r['other_changed']);
        self::assertSame('one', $r['before_text']);
        self::assertFalse($r['before_has_binary']);
        self::assertSame(['b'], $r['not_saved_columns']);
        self::assertSame(1, $r['written_unverified']);
        self::assertSame(['wp_bin' => 2], $r['written_changed']);
        self::assertSame(1, $r['deleted_unverified']);
    }

    public function testWithConfirmationsAWriteThatDestroysTheValueRunsAndIsListed(): void
    {
        self::assertSame([
            'other_column_listed' => 0,
            'other_column_value' => 'braked2',
            'same_column_listed' => 1,
            'delete_listed' => 1,
            'delete_rows' => 0,
        ], self::$result['binary_confirming']);
    }

    public function testThePlaceholderOnMoreRowsThanCanBeCheckedRunsAndIsReported(): void
    {
        self::assertSame([
            'few_recorded_all' => true,
            'many_note' => true,
            'many_changed' => 300,
            'few_note' => false,
        ], self::$result['placeholder_many']);
    }

    public function testANovamiraChatCallIsRecordedLikeAnMcpCall(): void
    {
        self::assertSame([
            'recorded' => ['wp_chat' => 1],
            'same_message_same_group' => true,
            'other_message_other_group' => true,
            'session' => 'chat-conv-1',
            'read_untouched' => true,
            'runs_with_confirmations' => true,
            'value' => 'ok',
        ], self::$result['chat_calls']);
    }

    public function testWholeTablesAreListedAndAnyOfThemRuns(): void
    {
        self::assertSame([
            'runs' => true,
            'emptied' => 0,
            'counted' => ['wp_whole_small' => 2],
            'listed_in_words' => true,
            'empty_table_listed' => 1,
            'too_large_runs' => true,
            'too_large_not_recorded' => 1,
        ], self::$result['whole_tables']);
    }

    public function testEmailsCanGoOutWhileTheAiWorks(): void
    {
        $r = self::$result;
        self::assertNull($r['no_hold_mail']);
        self::assertSame(['held' => 0, 'sent' => 1, 'watched' => 0], $r['no_hold_summary']);
    }

    public function testTheHistorySaysWhatItCannotSee(): void
    {
        self::assertSame([
            'sent_emails' => 1,
            'sent_requests' => 1,
            'sent_listed' => 1,
            'group_sent' => 1,
            'no_false_comfort' => true,
            'says_not_in_history' => true,
            'plain_read_not_listed' => 0,
            'file_ability_listed' => 1,
            'file_ability_says_so' => true,
            'file_code_listed' => 1,
            'group_files' => 1,
        ], self::$result['honest_summary']);
    }

    public function testOnlyAWriteNamingACacheRowStaysOutOfTheHistory(): void
    {
        self::assertSame([
            'delete_transient' => true,
            'update_transient' => true,
            'insert_queue' => true,
            'like_transient' => true,
            'narrowed' => true,
            'real_option_next_to_transient' => false,
            'transient_then_or' => false,
            'word_in_a_value' => false,
            'like_everything' => false,
        ], self::$result['untracked_options']);
    }

    public function testAWriteThatCannotBeSavedLeavesEarlierRowsAlone(): void
    {
        self::assertSame(['first_write_kept' => 1, 'second_not_saved' => 1], self::$result['nokey']);
    }

    public function testALargeChangeCanBeReadToTheEnd(): void
    {
        self::assertSame([
            'total' => 250,
            'first_page' => 200,
            'second_page' => 50,
            'no_overlap' => true,
            'read_at_once' => 3,
            'not_found' => [999999999],
            'before' => 'old',
            'protected' => true,
            'names_hq_shows' => [],
            'password_hidden' => true,
            'one_note' => true,
            'guide' => true,
            'guide_cases' => [true, true, true],
        ], self::$result['many_rows']);
    }

    public function testAnExecutionThatNeverClosedReadsAsInterrupted(): void
    {
        $r = self::$result;
        self::assertSame('running', $r['running_now']);
        self::assertSame('interrupted', $r['running_long_ago']);
    }

    public function testARestoreIsANormalChangeTheAiMakes(): void
    {
        self::assertSame(
            [
                'before' => 'first',
                'now_at_read' => 'second',
                'value' => 'first',
                'other_kept' => 'person',
                'new_item' => true,
                'its_before' => 'second',
                'engine_gone' => true,
            ],
            self::$result['restore'],
        );
    }

    public function testTheAiCanFindATablesCopyAndRestoreFromIt(): void
    {
        $r = self::$result;
        self::assertSame(
            ['kind' => 'table', 'operation' => 'TRUNCATE', 'in_copy' => 3, 'read' => 3, 'rows_now' => 4, 'listed' => 1],
            $r['copy_restore'],
        );
        self::assertNull($r['copy_gone']);
    }

    public function testTheAiReadsWhatIsOnTheSiteButNotSecrets(): void
    {
        $r = self::$result['ai_view'];
        self::assertSame('customer@example.test', $r['recipient']);
        self::assertSame('Private subject', $r['subject']);
        self::assertNotNull($r['before']);
        self::assertNotSame('Person Name', $r['before']);
        self::assertSame('Person Name', $r['now']);
        self::assertFalse($r['password_shown']);
        self::assertFalse($r['marker_shown']);
        self::assertTrue($r['hidden']);
        self::assertNull($r['unknown']);
    }

    public function testAWriteThatDidNotHappenIsNotReportedAsAChange(): void
    {
        $r = self::$result;
        self::assertSame(['rows' => [], 'saved' => 0, 'facts' => []], $r['no_change']);
        self::assertSame(['rows' => [], 'saved' => 0], $r['failed_write']);
    }

    public function testGroupsSurviveNoSessionAndReadsKeepNothingOpen(): void
    {
        $r = self::$result;
        self::assertTrue($r['no_session_group']);
        self::assertTrue($r['read_keeps_nothing_open']);
    }

    public function testAWaitingEmailIsNeverDroppedOnItsOwn(): void
    {
        $r = self::$result;
        self::assertSame([true, true, 0], $r['waiting_after_prune']);
        self::assertTrue($r['waiting_row_before_clear']);
        self::assertSame([true, true, 0], $r['waiting_after_clear']);
        self::assertSame([], $r['waiting_not_in_history']);
        self::assertSame(0, $r['nothing_waits']);
    }

    public function testADirectCallKeepsAListAndAStopHasAStatus(): void
    {
        $r = self::$result;
        self::assertSame(['a', 'b'], $r['direct_list']);
        self::assertTrue($r['direct_object_has_summary']);
        self::assertSame(409, $r['halt_status']);
    }

    public function testAFailedUpgradeIsTriedAgain(): void
    {
        $r = self::$result;
        self::assertSame(1, $r['upgrade_failed_version']);
        self::assertSame(['version' => 5, 'grp' => 'abc', 'new_hash_gone' => true, 'waiting_key' => true], $r['upgrade_retried']);
    }

    public function testEachCallKeepsWhatTheAiSaidAndWhoApprovedIt(): void
    {
        self::assertSame([
            ['Adds the words_one setting.', ''],
            ['Adds the words_two setting, as approved.', 'Ada'],
        ], self::$result['call_words']);
    }
}
