<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ConfirmationsTest extends TestCase
{
    private static array $result;

    public function testAbilityAndExactArgumentsAreLoggedAndAvailableOnlyToTheBrowser(): void
    {
        $r = self::$result;
        self::assertSame('novamira/execute-php', $r['queued']['ability_name']);
        $expected = [
            'tool' => 'mcp-adapter-execute-ability',
            'arguments' => ['ability_name' => 'novamira/execute-php', 'parameters' => ['code' => 'echo "test";']],
        ];
        self::assertSame($expected, $r['pending_call_data']);
        self::assertSame($expected, $r['completed_call_data']);
        self::assertSame($expected, $r['ended_session_call_data']);
        self::assertSame(404, $r['purged_call_data_status']);
        self::assertSame(404, $r['missing_call_data_status']);
        self::assertSame(404, $r['invalid_call_data_status']);
        self::assertSame('POST', $r['call_data_method']);
        self::assertTrue($r['call_data_requires_cookie']);
        self::assertTrue($r['call_data_requires_nonce']);
        self::assertTrue($r['call_data_browser_allowed']);
        self::assertSame(['novamira_decision_nonce', 'novamira_decision_nonce'], $r['decision_needs_its_nonce']);
    }

    public function testDirectRestRunsFollowTheSameRules(): void
    {
        $r = self::$result;
        self::assertSame(['novamira/execute-php', 'novamira/execute-php', '', ''], $r['rest_routes']);
        self::assertSame(
            ['novamira/execute-php', 'novamira/execute-php', 'novamira/execute-php', 'NOVAMIRA/execute-php'],
            $r['rest_routes_any_case'],
        );
        self::assertSame(['from the query', 'from the query', 'from the body', []], $r['rest_fields']);
        self::assertSame(['novamira_confirmation_review_required', true], $r['rest_no_review']);
        self::assertFalse($r['rest_error_without_oauth']);
        self::assertTrue($r['rest_result_with_oauth']);
        self::assertSame(
            [
                'novamira_confirmation' => [
                    'executed' => false,
                    'code' => 'novamira_confirmation_pending',
                    'message' => 'Waiting. Nothing has executed.',
                    'request_id' => 'abc',
                ],
            ],
            $r['rest_stopped_result'],
        );
        self::assertSame(
            [
                'Create a link to upload a file to wp-content/upgrade/x.zip.',
                'Delete wp-content/old and everything inside it.',
                'Enable the sandbox file tool.php, so that it runs.',
                "Create a link that lets your AI open this site's admin in a browser, signed in as its WordPress user for 30 minutes. In the admin it has that user's access, approvals included.",
                null,
                null,
            ],
            $r['fixed_rule_summaries'],
        );
        self::assertSame('Create a link to upload a file to a.zip.', $r['fixed_rule_review']);
        self::assertSame('Uploads the backup.', $r['fixed_rule_ai_wins']);
        self::assertNull($r['rest_low_runs']);
        self::assertSame(['novamira_confirmation_review_required', true], $r['rest_review_problem']);
        self::assertSame(['novamira_confirmation_pending', 409, 9], $r['rest_raised']);
        self::assertSame([true, true], $r['rest_says_check_again']);
        self::assertSame(0, $r['rest_nothing_ran']);
        self::assertTrue($r['rest_same_call_same_request']);
        self::assertTrue($r['rest_lists_open']);
        self::assertSame(['pending_confirmation', 0], $r['rest_pending']);
        self::assertSame(['completed', 1, "update_option('active_plugins', []);"], $r['rest_approved']);
        self::assertSame(['completed', 1], $r['rest_runs_once']);
        self::assertSame(['req-cli'], $r['rest_approved_keeps_code']);
        self::assertTrue($r['rest_other_user']);
        self::assertSame('Refused for a reason.', $r['rest_refusal_text']);
        self::assertNull($r['rest_read_passes']);
        self::assertSame('novamira_confirmation_review_required', $r['rest_other_plugin_needs_summary']);
        self::assertNull($r['rest_other_plugin_passes']);
        self::assertNull($r['rest_closed_page_low_runs']);
        self::assertSame('novamira_confirmation_pending', $r['rest_closed_page_waits']);
        self::assertSame('completed', $r['rest_closed_page_runs_when_approved']);
        self::assertNull($r['rest_read_mode_passes']);
    }

    public function testApprovalsCanBeSkippedWithoutLeavingLiveMode(): void
    {
        $r = self::$result;
        self::assertSame([true, false, true], $r['skip_saved']);
        self::assertSame('canceled', $r['skip_cancels_pending']);
        self::assertFalse($r['skip_mcp_no_review']);
        self::assertSame(1, $r['skip_mcp_ran']);
        self::assertTrue($r['skip_tool_has_no_review']);
        self::assertNull($r['skip_rest_no_review']);
        self::assertNull($r['skip_always_asks_no_more']);
        self::assertSame(8, $r['skip_keeps_threshold']);
        self::assertTrue($r['skip_stays_when_not_sent']);
        self::assertTrue($r['skip_off_asks_again']);
        self::assertFalse($r['default']['skip_approvals']);
    }

    public function testTheActivityPageCountsWhatWaitsAndOpensTheRightTab(): void
    {
        $r = self::$result;
        self::assertSame(0, $r['waiting_none']);
        self::assertSame(1, $r['waiting_one']);
        self::assertTrue($r['waiting_link']);
        self::assertSame(0, $r['waiting_decided']);
        self::assertSame([1, ['Approval waiting'], true], $r['bar_waiting']);
        self::assertSame(['count' => 0, 'items' => []], $r['bar_decided']);
        self::assertSame(['Posts', 'Novamira <b>3</b>', ''], $r['top_menu_badge']);
        self::assertSame('history', $r['tab_history']);
        self::assertSame('approvals', $r['tab_unknown']);
        self::assertSame('approvals', $r['tab_default']);
    }

    public function testRequestsFromBeforeTheyBelongedToTheUserAreCanceled(): void
    {
        self::assertSame(
            ['canceled' => 'canceled', 'generation_changed' => true, 'threshold_kept' => true, 'only_once' => true],
            self::$result['old_requests'],
        );
    }

    public function testEmailsAreNotHeldWithoutTheChangeHistory(): void
    {
        self::assertSame(
            ['history_on' => false, 'holds' => false, 'setting_stays' => true, 'ai_email_asks' => true],
            self::$result['no_history_email'],
        );
    }

    public function testARequestBelongsToTheUserNotTheConnection(): void
    {
        $r = self::$result;
        self::assertTrue($r['session_required']);
        self::assertTrue($r['dashboard_does_not_renew_session']);
        self::assertSame('pending_confirmation', $r['ended_session_keeps_request']);
        self::assertTrue($r['dead_session_cannot_poll']);
        self::assertTrue($r['new_session_lists_request']);
        self::assertTrue($r['new_session_same_request']);
        self::assertTrue($r['pending_stays_pending']);
        self::assertTrue($r['pending_says_stop_polling']);
        self::assertSame([true, true], $r['pending_says_check_again']);
        self::assertTrue($r['new_session_runs_approval']);
        self::assertTrue($r['new_session_result_once']);
        self::assertTrue($r['finished_not_listed']);
        self::assertTrue($r['finished_call_queues_again']);
        self::assertSame('pending_confirmation', $r['deleted_session_map']);
        self::assertSame('captured-session', $r['captured_rest_header']);
        self::assertSame('', $r['rest_context_resets']);
    }

    public function testLogsPersistForFifteenDaysAndNeverRetryInterruptedExecution(): void
    {
        $r = self::$result;
        self::assertTrue($r['retention_from_completion']);
        self::assertTrue($r['retained_after_two_weeks']);
        self::assertTrue($r['retention_expired_poll']);
        self::assertTrue($r['retention_deleted']);
        self::assertSame('executing', $r['executing_not_canceled']);
        self::assertSame('execution_unknown', $r['interrupted_ended_session']['state']);
        self::assertArrayHasKey('parameters', $r['interrupted_ended_session']['arguments']);
        self::assertArrayNotHasKey('result', $r['interrupted_ended_session']);
        self::assertSame('completed', $r['ended_during_execution']['state']);
        self::assertNotNull($r['ended_during_execution']['tool_result']);
        self::assertTrue($r['ended_during_execution_once']);
        self::assertTrue($r['ended_during_execution_logged_result']);
    }

    public function testSlowExecutionsKeepTheirResult(): void
    {
        $r = self::$result;
        self::assertSame(120, $r['timeout_short_limit']);
        self::assertSame(360, $r['timeout_long_limit']);
        self::assertSame(3600, $r['timeout_unlimited']);
        self::assertSame('execution_unknown', $r['late_marked_unknown']);
        self::assertSame('completed', $r['late_result_kept']);
        self::assertSame('completed', $r['late_stored_state']);
        self::assertSame('execution_unknown', $r['other_run_not_overwritten']);
    }

    public function testIdenticalCallsShareOneRequestAndPresenceBelongsToTheUser(): void
    {
        $r = self::$result;
        self::assertFalse($r['other_user_page_not_online']);
        self::assertSame('pending_confirmation', $r['closed_page_queues']);
        self::assertTrue($r['own_page_online']);
        self::assertSame([2, 1], $r['other_user_heartbeat_kept']);
        self::assertTrue($r['same_call_other_score']);
        self::assertSame(1, $r['one_key_per_call']);
        self::assertTrue($r['key_not_a_request']);
        self::assertTrue($r['first_claim']);
        self::assertTrue($r['gone_request_taken_over']);
        self::assertTrue($r['gone_points_to_new']);
        self::assertTrue($r['live_request_returned']);
        self::assertTrue($r['other_caller_not_returned']);
        self::assertTrue($r['duplicate_not_stored']);
        self::assertTrue($r['ended_request_new_one']);
        self::assertTrue($r['pruned_gone']);
        self::assertTrue($r['kept_live_key']);
    }

    public function testAFindingReadsAsPlainWordsOrNotAtAll(): void
    {
        self::assertSame(['', 'Changes users or their permissions'], self::$result['rule_words']);
    }

    public function testAnApprovalDoesNotCoverAFileThatChangedAfterTheCard(): void
    {
        self::assertSame(
            ['state' => 'failed', 'ran' => 0, 'says_file_changed' => true],
            self::$result['changed_after_approval'],
        );
        self::assertSame(
            ['state' => 'completed', 'ran' => 1, 'says_file_changed' => false],
            self::$result['unchanged_after_approval'],
        );
    }

    public function testFinishedRequestsAreStoredWithoutSecretsAndEveryScoreIsLogged(): void
    {
        $r = self::$result;
        self::assertTrue($r['pending_keeps_exact_code']);
        self::assertSame('completed', $r['secret_call_completed']);
        self::assertTrue($r['stored_no_hash']);
        self::assertTrue($r['stored_no_site_secret']);
        self::assertTrue($r['stored_no_api_key']);
        self::assertTrue($r['stored_marked']);
        self::assertSame(8, $r['stored_review']['attention']);
        self::assertStringNotContainsString('sk_live_', $r['stored_review']['summary']);
        self::assertStringNotContainsString('site-auth-key', $r['stored_review']['details']);
        self::assertTrue($r['agent_result_redacted']);
        self::assertSame('{"h":"[redacted]"}', $r['redact_json_escaped']);
        self::assertSame(['nested' => ['x [redacted] y']], $r['redact_site_option']);
        self::assertSame('just a normal sentence with $ signs', $r['redact_leaves_plain']);
        self::assertSame(2, $r['score_log_lines']);
        self::assertSame(['ability' => 'novamira/execute-php', 'score' => 5, 'queued' => false], $r['score_log_last']);
        self::assertTrue($r['score_log_no_code']);
    }

    public function testApprovalsLastTenMinutesAndSurviveAnOAuthTokenRefresh(): void
    {
        $r = self::$result;
        self::assertSame('approved', $r['late_approval_has_time']);
        self::assertSame('expired', $r['stale_approval_expires']);
        self::assertTrue($r['credential_change_same_owner']);
    }

    public function testCodeCheckSetsAFloor(): void
    {
        $r = self::$result;
        self::assertSame(['pending_confirmation', 10, true], $r['enforce_asks_about_dynamic']);
        self::assertTrue($r['enforce_refuses_internals']);
        self::assertTrue($r['enforce_refused_not_run']);
        self::assertSame('pending_confirmation', $r['enforce_raises_to_confirmation']);
        self::assertSame(9, $r['enforce_raised_score']);
        self::assertSame(9, $r['refund_over_mcp_score']);
        self::assertTrue($r['enforce_raised_summary']);
        self::assertTrue($r['enforce_read_runs']);
        self::assertNull($r['analysis_other_ability']);
        self::assertSame([
            'read' => 0,
            'interpolated_delete' => 9,
            'concat_delete' => 5,
            'loop_delete' => 9,
            'single_delete' => 5,
            'callback_by_name' => 9,
            'include' => 9,
            'require_wordpress_admin' => 0,
            'require_wpinc' => 0,
            'require_climbs_out' => 9,
            'require_twice_fatal' => 9,
            'require_wp_load' => 0,
            'require_acts_on_load' => 9,
            'require_subfolder' => 9,
            'parse_error' => 0,
            'truncate' => 9,
            'role' => 9,
        ], $r['analysis_cases']);
    }

    public function testNovamiraChatFollowsTheSameChecks(): void
    {
        $r = self::$result['chat_check'];
        $clear = ['refused' => '', 'must_ask' => false, 'why' => '', 'fingerprint' => ''];
        self::assertSame($clear, $r['read']);
        self::assertSame($clear, $r['small']);
        self::assertTrue($r['refused']);
        self::assertTrue($r['unreadable_asks']);
        self::assertTrue($r['no_attention_refused']);
        self::assertTrue($r['model_raises']);
        self::assertTrue($r['model_low_runs']);
        self::assertTrue($r['model_cannot_lower']);
        self::assertSame('novamira', $r['mode']);
        self::assertTrue($r['high']);
        self::assertStringContainsString('Changes a setting the whole site depends on.', $r['high_why']);
        self::assertStringNotContainsString(':', $r['high_why']);
        self::assertTrue($r['always']);
        self::assertSame(['same_card_same_print' => true, 'other_code_other_print' => true], self::$result['chat_print']);
        self::assertSame([
            'ai_request_asks' => true,
            'parser_asks_a_person' => true,
            'ability_code_is_not_read' => false,
            'row_count_alone_asks_nothing' => false,
        ], self::$result['refund_check']);
    }

    public function testEachAbilityGetsItsOwnTreatment(): void
    {
        $r = self::$result;
        self::assertTrue($r['policy_read_runs_without_score_offline']);
        self::assertSame('pending_confirmation', $r['policy_enable_always']['state']);
        self::assertStringContainsString('runs on every request', implode(' ', $r['policy_enable_always']['found']));
        self::assertSame('pending_confirmation', $r['policy_admin_link_always']['state']);
        self::assertTrue($r['policy_unknown_runs_unasked']);
        self::assertSame([true, true, false], $r['ours']);
        // Another plugin's ability, and one of Novamira's that declares nothing, follow the level with the AI's rating.
        self::assertSame(['never', 'above+score', 'always', 'above+score', 'above+score'], $r['declared_policies']);
        self::assertTrue($r['forced_other_ability_asks']);
        self::assertTrue($r['forced_chat_asks']['must_ask']);
        self::assertSame(['never', 'above'], $r['never_ask_still_recorded']);
        self::assertStringContainsString('An administrator chose', $r['forced_chat_asks']['why']);
        self::assertSame([
            'no_for_read' => true,
            'read_policy' => 'never',
            'no_for_php' => false,
            'php_policy' => 'above',
            'yes_for_php' => true,
            'php_now' => 'always',
            'admin_link' => [[], 'yes'],
            'enable_file' => ['level', 'yes'],
        ], $r['choices']);
        self::assertSame(
            ['read' => 'never', 'write' => 'above', 'php_listed' => 'above', 'read_always' => 'always'],
            $r['readonly'],
        );
        self::assertSame(
            ['read' => 'never', 'write' => 'above', 'hint_wins' => 'above', 'read_always' => 'always'],
            $r['read_only_hint'],
        );
        self::assertTrue($r['unforced_runs_again']);
        self::assertSame([], $r['forced_list_empty']);
        self::assertTrue($r['policy_delete_plugin_asks']);
        self::assertTrue($r['policy_delete_upload_runs']);
        self::assertTrue($r['enable_running_does_not_ask']);
        self::assertTrue($r['enable_running_chat_does_not_ask']);
        self::assertSame(
            ['new' => 0, 'existing_no_overwrite' => 0, 'replaces' => 5, 'replaces_in_sandbox' => 0],
            $r['upload_link_scores'],
        );
        self::assertSame([
            'plugin_dir' => 9,
            'sandbox_file' => 5,
            'sandbox_htaccess' => 9,
            'sandbox_web_config' => 9,
            'upload' => 5,
            'other_htaccess' => 5,
            'recursive' => 9,
        ], $r['delete_scores']);
        self::assertSame([
            'list' => 0,
            'eval' => 'refuse',
            'exec_flag' => 'refuse',
            'package' => 'refuse',
            'plugin_install' => 9,
            'option_critical' => 9,
            'option_other' => 0,
            'option_get' => 0,
            'db_query_delete' => 0,
            'db_query_select' => 0,
            'search_replace' => 9,
            'search_replace_dry' => 0,
            'post_delete' => 0,
            'post_delete_several' => 9,
            'post_delete_several_in_one' => 9,
            'post_delete_force' => 9,
            'post_update' => 0,
            'user_create' => 9,
        ], $r['wp_cli_scores']);
        self::assertSame([], array_keys(array_filter($r['wp_cli_forbidden'], static fn(bool $refused): bool => !$refused)));
        self::assertCount(22, $r['wp_cli_forbidden']);
        self::assertSame([true, true, true, true, true], $r['wp_cli_allowed']);
        self::assertSame([
            'alias_read' => 0,
            'user_meta_read' => 0,
            'user_meta_write' => 9,
            'plugin_install_alias' => 9,
            'db_import' => 9,
            'dry_export' => 9,
            'no_dry_run' => 9,
            'unknown' => 0,
            'db_export' => 0,
            'insert' => 0,
            'history_text' => 0,
            'minus_minus' => 0,
        ], $r['wp_cli_more_scores']);
        self::assertTrue($r['wp_cli_read_runs']);
        self::assertStringContainsString('review.summary', $r['wp_cli_needs_words']['structuredContent']['error']);
        self::assertTrue($r['wp_cli_small_change_runs']);
        self::assertSame('pending_confirmation', $r['wp_cli_ai_raises']['state']);
        self::assertSame('Deletes article 12.', $r['wp_cli_ai_raises']['summary']);
        self::assertSame('Moves to the trash: #13.', $r['wp_cli_ai_raises']['means']);
        self::assertSame('pending_confirmation', $r['wp_cli_rules_raise']['state']);
        self::assertSame(9, $r['wp_cli_rules_raise']['attention']);
        self::assertSame('Creates the user "bob".', $r['wp_cli_rules_raise']['means']);
        $s = $r['check_scores'];
        self::assertTrue($r['check_available']);
        self::assertSame(9, $s['callback_in_array_map']);
        self::assertSame($s['foreach_same_work'], $s['callback_in_array_map']);
        self::assertSame(9, $s['closure_in_array_map']);
        self::assertSame(5, $s['closure_elsewhere']);
        self::assertSame(5, $s['callback_kept_rule']);
        self::assertSame(5, $s['order_delete']);
        self::assertSame(9, $s['products_saved_in_loop']);
        self::assertSame(['medium:wpdb_write'], $s['wpdb_delete']);
        self::assertSame(['medium:files'], $s['filesystem_delete']);
        self::assertSame(9, $s['app_password']);
        self::assertSame(0, $s['plain_static']);
        self::assertSame(9, $s['upgrader']);
        self::assertSame(9, $s['network_site']);
        self::assertSame(9, $s['db_delta']);
        self::assertSame(5, $s['stock']);
        self::assertContains('medium:runs_later', $s['shutdown_hook']);
        self::assertSame(5, $s['shutdown_function']);
        self::assertSame(['refuse:novamira_internals'], $s['removes_query_filter']);
        self::assertSame(5, $s['removes_other_filter']);
        self::assertGreaterThanOrEqual(5, $s['password_email']);
        self::assertSame('Deletes content, many times', $r['loop_words']);
        $o = $r['on_off_scores'];
        // Turning a plugin or a theme on is a big change: it asks from the Balanced level up.
        self::assertSame(8, $o['activate']);
        self::assertSame(8, $o['switch_theme']);
        self::assertSame(9, $o['delete_plugins']);
        self::assertContains('access:novamira_off', $o['deactivate_novamira']);
        self::assertContains('big:plugins_on', $o['cli_activate']);
        self::assertContains('big:plugins_on', $o['cli_theme_activate']);
        self::assertContains('high:plugins_and_themes', $o['cli_install']);
        self::assertContains('high:plugins_and_themes', $o['cli_delete']);
        $c = $r['callback_fixes'];
        self::assertSame([], $c['meta_key_system']);
        self::assertSame(0, $c['option_named_mail']);
        self::assertContains('refuse:shell', $c['filter_with_system']);
        self::assertContains('access:novamira_off', $c['deactivate_via_map']);
        self::assertSame(['refuse:novamira_internals'], $c['option_callback_novamira']);
        self::assertContains('access:site_address', $c['option_callback_siteurl']);
        self::assertContains('high:critical_option', $c['option_callback_siteurl']);
        self::assertSame(['high:option_in_loop'], $c['option_callback_plain']);
        self::assertSame(['refuse:novamira_internals'], $c['internal_callback']);
        self::assertSame(9, $c['static_callback']);
        self::assertSame(9, $c['named_callback']);
        self::assertSame(['medium:removes_hooks'], $c['shutdown_flush_off']);
        self::assertSame(['refuse:novamira_internals'], $c['shutdown_all_off']);
        self::assertSame(['refuse:novamira_internals'], $c['shutdown_novamira_off']);
        self::assertSame(['medium:removes_hooks'], $c['query_one_off']);
        self::assertContains('access:novamira_off', $c['cli_toggle_novamira']);
        self::assertNotContains('access:novamira_off', $c['cli_toggle_other']);
        self::assertSame('Calls delete() or trash() on an object', $c['object_delete_words']);
        self::assertSame('Calls save() on an object, many times', $c['object_save_words']);
        self::assertSame(9, $c['spread_literal']);
        self::assertSame(['high:unknown_callback_in_loop'], $c['spread_variable']);
        self::assertSame(['high:unknown_callback_in_loop'], $c['variable_in_map']);
        self::assertSame(['medium:unknown_callback'], $c['variable_in_hook']);
        self::assertSame(['high:unknown_callback_in_loop'], $c['object_in_map']);
        self::assertSame(['medium:unknown_callback'], $c['object_method_in_hook']);
        self::assertSame(9, $c['first_class_in_map']);
        self::assertSame([], $c['null_in_map']);
        self::assertContains('refuse:shell', $c['callback_array']);
        self::assertContains('medium:deletes_content', $c['udiff_last']);
        self::assertContains('medium:deletes_content', $c['rest_route']);
        self::assertContains('medium:deletes_content', $c['error_handler_named']);
        self::assertSame(['high:option_in_loop'], $c['option_value_not_name']);
        self::assertSame(['medium:option'], $c['hook_name_not_option']);
        self::assertSame(['medium:removes_hooks'], $c['other_plugin_query_filter_off']);
        self::assertSame(['refuse:novamira_internals'], $c['novamira_query_filter_off']);
        $access = $r['access_rules'];
        self::assertContains('access:site_address', $access[0]);
        self::assertNotContains('access:site_address', $access[1]);
        self::assertContains('access:site_address', $access[2]);
        self::assertContains('access:novamira_off', $access[3]);
        self::assertContains('access:novamira_off', $access[4]);
        self::assertNotContains('access:novamira_off', $access[5]);
        self::assertContains('access:site_address', $access[6]);
        self::assertContains('access:novamira_off', $access[7]);
        self::assertNotContains('access:novamira_off', $access[8]);
        self::assertContains('access:novamira_off', $access[9]);
        self::assertSame(
            ['It changes the site\'s address. Novamira is tied to the address, so the AI may lose access to this site.'],
            $r['access_lines'],
        );
        self::assertSame([
            ['refuse:novamira_internals'],
            ['refuse:novamira_internals'],
            false,
            ['forbid:wp_cli_novamira_settings'],
            [],
            ['forbid:wp_cli_novamira_settings'],
        ], $r['novamira_settings_refused']);
        self::assertSame([
            'Installs the plugin "seo-tools" and turns it on.',
            'Reads the plugins: changes nothing.',
            'Creates the user "bob" with the role "editor".',
            'Changes the site setting "blogname" to "Nuovo nome".',
            'Deletes for good, with no trash: #13.',
            'Replaces "http://old.test" with "https://new.test" everywhere in the database, as a test: changes nothing.',
            'Reads data straight from the database: changes nothing.',
            'Empties the cache, so pages are built again.',
            'A command with no plain description here: rely on the AI\'s words.',
        ], $r['wp_cli_meanings']);
        self::assertTrue($r['wp_cli_unknown_runs']);
        self::assertSame('pending_confirmation', $r['wp_cli_insert_ai_raises']);
        self::assertTrue($r['wp_cli_forbidden_call']['isError']);
        self::assertStringContainsString('no approval changes this', $r['wp_cli_forbidden_call']['structuredContent']['error']);
        self::assertTrue($r['wp_cli_forbidden_not_run']);
        self::assertTrue($r['wp_cli_forbidden_in_chat']);
        self::assertTrue($r['wp_cli_scored']);
        self::assertSame(['php_file' => 9, 'php_append' => 9, 'php_edit' => 9, 'css_outside' => 5, 'css_sandbox' => 0], $r['write_scores']);
        self::assertSame('pending_confirmation', $r['write_php_refused']);
    }

    public function testReadModeTurnsConfirmationsOff(): void
    {
        $r = self::$result;
        self::assertFalse($r['read_mode_no_score']['isError'] ?? false);
        self::assertTrue($r['read_mode_executed']);
        self::assertTrue($r['read_mode_no_status_tool']);
        self::assertTrue($r['read_mode_no_review_schema']);
        self::assertTrue($r['read_mode_runtime']);
        self::assertSame('canceled', $r['read_mode_pending_canceled']);
    }

    public function testPromptsAndResourcesCannotSkipTheScore(): void
    {
        $r = self::$result;
        self::assertSame([true, true], $r['component_hooked']);
        self::assertSame('novamira_confirmation_component', $r['component_untrusted']);
        self::assertSame('allowed', $r['component_of_another_plugin']);
        self::assertSame('novamira_confirmation_component', $r['component_forced']);
        self::assertSame('novamira_confirmation_component', $r['component_no_ability']);
        self::assertSame('novamira_confirmation_component', $r['component_lookalike_skill']);
        self::assertSame('allowed', $r['component_trusted']);
        self::assertTrue($r['component_keeps_earlier_error']);
        self::assertSame('allowed', $r['component_read_mode']);
    }

    public function testPreviousRequestsAreSeparateAndPaginated(): void
    {
        $r = self::$result;
        self::assertSame([], array_diff($r['default_active_states'], ['pending_confirmation', 'approved', 'executing']));
        self::assertTrue($r['expired_in_history']);
        self::assertTrue($r['expired_not_active']);
        self::assertTrue($r['ended_in_history']);
        self::assertNotNull($r['finished_timestamp']);
        self::assertSame(25, $r['history_page_size']);
        self::assertTrue($r['history_has_more']);
        self::assertTrue($r['history_page_distinct']);
        self::assertTrue($r['history_page_newest_first']);
        self::assertSame(['requests' => [], 'has_more' => false], $r['history_page_past_end']);
    }

    public function testARetryWithTheSameCallIdReturnsTheFirstResultInsteadOfRunningAgain(): void
    {
        $r = self::$result;
        self::assertSame(1, $r['retry_runs_once']);
        self::assertFalse($r['retry_first_error']);
        self::assertSame([true, 'completed'], $r['retry_replayed']);
        self::assertSame(['ran' => 0, 'replayed' => true], $r['retry_after_reconnect']);
        self::assertTrue($r['retry_same_result']);
        self::assertSame([true, true], $r['retry_other_call_refused']);
        self::assertTrue($r['retry_bad_id']);
        self::assertSame('running', $r['retry_still_running']);
        self::assertSame([true, true], $r['retry_stale_unknown']);
        self::assertSame(2, $r['without_id_runs_twice']);
    }

    public function testWritesWrittenOutInTheCodeAreFoundForCounting(): void
    {
        self::assertSame(
            [
                "DELETE FROM wp_posts WHERE post_title LIKE '%test%'",
                "UPDATE wp_options SET option_value = 'x' WHERE option_name = 'a'",
            ],
            self::$result['known_writes'],
        );
    }

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2);
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tests/fixtures/confirmations.php') . ' ' . escapeshellarg($root);
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stderr);
        self::$result = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
    }

    public function testLevelsSaveTheirCutoffAndCountRecentCalls(): void
    {
        $r = self::$result['levels'];
        self::assertSame(['most', 'big', 'almost_none', null], $r['of']);
        self::assertTrue($r['waiting_says_any_admin']);
        self::assertSame([7, false], $r['number_kept']);
        self::assertSame('invalid_settings', $r['invalid']);
        self::assertSame([5, true], $r['most']);
        self::assertSame(8, $r['big']);
        self::assertStringContainsString('Balanced', $r['asks_before']['big']);
        self::assertStringContainsString('id="novamira-level-change"', $r['asks_before']['big']);
        self::assertStringNotContainsString('Payments always.', $r['asks_before']['big']);
        self::assertStringContainsString('Your earlier setting (7)', $r['asks_before']['legacy']);
        self::assertSame(5, $r['counts']['total']);
        self::assertSame(['most' => 3, 'big' => 2, 'almost_none' => 1], $r['counts']['levels']);
        self::assertNull($r['no_counts']);
    }

    public function testAReportedFailureOrABackgroundCommandStopsTheRestOfTheBlock(): void
    {
        [$failure, $background] = self::$result['blocks']['reported_failure'];
        self::assertSame(['completed', 'failed', 'not_run'], $failure);
        self::assertSame(['completed', 'completed', 'not_run'], $background);
    }

    public function testADecisionCutHalfwayCanBeTakenAgainLater(): void
    {
        [$stale_error, $stale_states, $fresh_error] = self::$result['blocks']['stale_seal'];
        self::assertFalse($stale_error);
        self::assertSame(['rejected'], $stale_states);
        self::assertSame('decision_raced', $fresh_error);
    }

    public function testAReconnectedConversationJoinsTheDecisionItHasWaiting(): void
    {
        [$other_own_block, $joined, $small_block, $waiting] = self::$result['blocks']['reconnect'];
        self::assertTrue($other_own_block);
        self::assertTrue($joined);
        self::assertSame($waiting, $small_block);
    }

    public function testDeletingUsersAlwaysAsksInChatAndThroughDirectSql(): void
    {
        $r = self::$result['deletes_users'];
        self::assertTrue($r['chat']);
        foreach (['sql', 'concat', 'prefix', 'method'] as $case) {
            self::assertContains('high:deletes_user', $r[$case], $case);
        }
        self::assertNotContains('high:deletes_user', $r['usermeta']);
        self::assertNotContains('high:deletes_user', $r['posts']);
    }

    public function testMoneyAlwaysAsksWhateverTheLevel(): void
    {
        $r = self::$result['money'];
        self::assertContains('money:refund', $r['refund_rule']);
        self::assertContains('money:payment', $r['payment_rule']);
        self::assertSame(['high:order_status'], $r['status_rule']);
        self::assertSame(['money:refund'], $r['cli_refund']);
        self::assertSame([], $r['cli_list']);
        self::assertSame('pending_confirmation', $r['mcp_waits']);
        self::assertSame('novamira_confirmation_pending', $r['rest_waits']);
        self::assertNull($r['rest_plain_runs']);
        self::assertTrue($r['chat_asks']);
    }

    public function testCardsSayWhetherTheAiCouldUndoIt(): void
    {
        $r = self::$result['undo'];
        self::assertSame(['undo' => 'history'], $r['option']);
        self::assertSame(['undo' => 'history'], $r['option_in_loop']);
        self::assertSame(['undo' => 'no', 'undo_reason' => 'Issues a refund'], $r['refund']);
        self::assertSame(['undo' => 'no', 'undo_reason' => 'Deletes a file'], $r['file']);
        self::assertSame(['undo' => 'no', 'undo_reason' => 'Deletes a site of the network and its uploaded files'], $r['site']);
        self::assertSame(['undo' => 'history'], $r['site_update']);
        self::assertSame(['high:deletes_site'], $r['cli_site']);
        self::assertSame([], $r['unknown']);
        self::assertSame([], $r['nothing']);
        self::assertSame(['undo' => 'history'], $r['held_email']);
        self::assertSame(['undo' => 'no', 'undo_reason' => 'Sends email'], $r['sent_email']);
        self::assertSame('no', $r['card']);
        self::assertSame('history', $r['record']);
        // Who asked and who decided, said as "you" for the person looking; a user gone has no name.
        self::assertSame(
            ['asked_by' => '', 'asked_by_you' => true, 'decided_by' => '', 'decided_by_you' => false],
            $r['names'],
        );
        self::assertSame('Cursor', $r['connection']['title']);
        self::assertSame('claude-ai', $r['connection']['name']);
        self::assertSame('', $r['connection']['none']);
        self::assertStringContainsString('asked through Cursor', $r['connection']['waiting']);
    }

    public function testReviewCanComeInsideTheParameters(): void
    {
        $r = self::$result['review_inside'];
        self::assertSame('pending_confirmation', $r['state']);
        self::assertSame('Refunds five.', $r['summary']);
        self::assertTrue($r['stored_clean']);
        self::assertSame(['ability_name' => 'x', 'parameters' => ['a' => 1]], $r['stripped']);
        self::assertSame('outside', $r['outside_wins']);
        self::assertSame(
            ['input' => ['code' => 'echo 1;'], 'review' => ['attention' => 1, 'summary' => 'Says one.']],
            $r['rest'],
        );
        self::assertNull($r['rest_empty_input']);
        self::assertSame('req-42', $r['rest_request_id']);
    }

    public function testContractAndCutoffBoundaries(): void
    {
        $r = self::$result;
        self::assertArrayNotHasKey('enabled', $r['default']);
        self::assertSame(8, $r['default']['cutoff']);
        self::assertTrue($r['default']['hold_emails']);
        self::assertSame([
            'saved' => false,
            'ai_email_asks' => true,
            'same_generation' => true,
            'held_again' => true,
            'direct_mail_asks' => true,
            'ai_email_waits_later' => false,
        ], $r['email_setting']);
        self::assertSame([true, false, true], $r['cutoff_boundaries']);
        self::assertSame("\$a = 1;\nif (\$a) {\n    return 'x';\n}\n// done", $r['readable_code']['one_line']);
        self::assertSame(['attention' => 9, 'summary' => 'Deletes article 12.'], $r['no_details']);
        self::assertSame(['attention' => 9, 'summary' => 'Deletes article 12.'], $r['empty_details']);
        self::assertSame("\$a = 1;\nreturn \$a;", $r['readable_code']['own_lines']);
        self::assertSame("\$a = 1;\nif (\$a) {\n    return 'x';\n}", $r['readable_code']['packed_lines']);
        self::assertSame('if ( {', $r['readable_code']['broken']);
        self::assertTrue($r['fresh_site_needs_review']['isError']);
        self::assertTrue($r['missing_review']['isError']);
        self::assertNotContains(false, $r['invalid_reviews']);
        self::assertFalse($r['below_cutoff']['isError']);
        self::assertArrayNotHasKey('review', $r['below_cutoff']['structuredContent']['received']);
        self::assertTrue($r['text_required']['isError']);
        self::assertSame(['attention' => 1], $r['below_only_score']);
        self::assertTrue($r['long_summary']);
        self::assertTrue($r['long_details']);
        self::assertSame(240, mb_strlen($r['unicode_summary']['summary']));
        self::assertSame(8, $r['queued']['attention']);
        self::assertFalse($r['new_cutoff_immediate']['isError']);
        self::assertSame('pending_confirmation', $r['zero_cutoff_read']['state']);
    }

    public function testOnlyAbilityExecutionRequiresAReviewAndAClosedPageStopsNothing(): void
    {
        $r = self::$result;
        self::assertFalse($r['closed_page_low_score_runs']['isError']);
        self::assertTrue($r['status_forbidden']['isError']);
        self::assertFalse($r['status_lower_privilege']['isError']);
        foreach (['discovery_offline', 'info_offline', 'status_offline', 'zero_cutoff_discovery'] as $key) {
            self::assertFalse($r[$key]['isError']);
        }
        $enabled = array_column($r['tools_enabled']['tools'], null, 'name');
        self::assertArrayHasKey('review', $enabled['mcp-adapter-execute-ability']['inputSchema']['properties']);
        self::assertNotContains('review', $enabled['mcp-adapter-execute-ability']['inputSchema']['required'] ?? []);
        self::assertStringContainsString("Novamira's reads, discovery", $enabled['mcp-adapter-execute-ability']['description']);
        self::assertStringContainsString('Never approve or reject', $enabled['mcp-adapter-execute-ability']['description']);
        foreach (['mcp-adapter-discover-abilities', 'mcp-adapter-get-ability-info', 'novamira-confirmation-status'] as $name) {
            self::assertArrayNotHasKey('review', $enabled[$name]['inputSchema']['properties']);
        }
        $disabled = array_column($r['tools_disabled']['tools'], null, 'name');
        self::assertArrayNotHasKey('review', $disabled['mcp-adapter-execute-ability']['inputSchema']['properties']);
    }

    public function testApprovalExecutesTheStoredCallOnceAndReturnsItsResult(): void
    {
        $r = self::$result;
        self::assertTrue($r['not_executed_while_pending']);
        self::assertTrue($r['duplicate_pending']);
        self::assertTrue($r['stored_no_review']);
        self::assertTrue($r['record_without_review_ignored']);
        self::assertSame(['review' => ['domain_data' => true]], $r['preserve_other_tool_review']);
        self::assertTrue($r['not_autoloaded']);
        self::assertSame('pending_confirmation', $r['pending_poll']['state']);
        self::assertSame('approved', $r['approve']['state']);
        self::assertTrue($r['double_approve']);
        self::assertSame('completed', $r['completed']['state']);
        self::assertSame('echo "test";', $r['completed']['tool_result']['structuredContent']['received']['parameters']['code']);
        self::assertSame($r['completed']['tool_result'], $r['repeated_poll']['tool_result']);
        self::assertTrue($r['executed_once']);
        self::assertTrue($r['stale_cas']);
        self::assertSame('completed', $r['queue_erased_by_action']['state']);
        self::assertNotNull($r['queue_erased_by_action']['tool_result']);
        self::assertTrue($r['queue_erased_no_replay']);
        self::assertSame('image', $r['image_result']['content'][1]['type']);
        self::assertSame(base64_encode('test-image-bytes'), $r['image_result']['content'][1]['data']);
    }

    public function testDecisionsCannotLeakAcrossUsersServersOrSettings(): void
    {
        $r = self::$result;
        self::assertSame('rejected', $r['rejected']['state']);
        self::assertSame('failed', $r['permission_revoked']['state']);
        self::assertTrue($r['permission_revoked']['tool_result']['isError']);
        foreach (['other_server', 'other_user'] as $key) self::assertTrue($r[$key]['isError']);
        self::assertSame('canceled', $r['toggle_canceled']['state']);
        self::assertSame('canceled', $r['cutoff_canceled']['state']);
        self::assertSame('expired', $r['expired']['state']);
        self::assertTrue($r['expired_can_resend']);
        self::assertSame(['pending_confirmation', true, true], $r['resent_is_new']);
        self::assertTrue($r['resent_while_waiting']);
        self::assertTrue($r['wait_token_ok']);
        self::assertTrue($r['wait_token_wrong']);
        self::assertTrue($r['wait_token_other_request']);
        self::assertTrue($r['wait_token_old']);
        self::assertSame(['request_id', 'state', 'waiting', 'next_step'], array_keys($r['wait_pending']));
        self::assertTrue($r['wait_pending']['waiting']);
        self::assertTrue($r['wait_command_shape'][0]);
        self::assertTrue($r['wait_command_shape'][1]);
        self::assertStringContainsString('do not ask the user to tell you', $r['wait_command_shape'][2]);
        self::assertSame([true, true, true], $r['stream_reconnects']);
        self::assertSame([true, 4, 'approved', false, true], $r['stream_done']);
        self::assertSame(['completed', true], $r['stream_then_runs']);
        self::assertSame(['pending_confirmation', true, $r['held_poll'][2]], $r['held_poll']);
        self::assertSame(['approved', false, true, true], $r['wait_approved']);
        self::assertSame(['completed', true], $r['approved_runs_once']);
        self::assertTrue($r['rejected_not_resent']);
        self::assertSame(['pending_confirmation', false, true], $r['wait_stops_after_limit']);
        self::assertSame(['completed', true, true, true], $r['page_runs']);
        self::assertSame(['completed', true, true], $r['page_result_waits']);
        self::assertSame(['completed', false, true], $r['wait_after_page']);
        self::assertSame(['completed', true], $r['page_second_run']);
        self::assertSame(['novamira_card_changed', 'pending_confirmation', true], $r['card_changed']);
        self::assertTrue($r['card_changed_then_approved']);
        self::assertSame('canceled', $r['deactivation_canceled']['state']);
        self::assertFalse($r['deactivation_offline']);
        self::assertSame('execution_unknown', $r['unknown']['state']);
        self::assertStringContainsString('do not automatically retry', $r['unknown']['warning']);
        self::assertFalse($r['browser_without_cookie']);
        self::assertFalse($r['browser_without_nonce']);
        self::assertTrue($r['browser_valid']);
        self::assertFalse($r['browser_identity_mismatch']);
        self::assertTrue($r['cleaned']);
        self::assertSame([
            'first_waits' => 'pending_confirmation',
            // A request alone is its own block of one: the fields are additive, nothing else changes.
            'single_payload' => [true, 0, 1, false],
            'second_joined' => [false, 'pending_confirmation', true, 1, false],
            'small_joined' => ['pending_confirmation', 2, true, 3],
            'nothing_ran' => true,
            'one_decision' => [1, 3],
            'same_call_same_request' => true,
            'resent_member_same_request' => true,
            'read_passes' => true,
            // Another plugin's ability waits with the decision of its conversation too.
            'other_plugin_joins' => 3,
            // A direct REST run is another conversation: it asks on its own.
            'rest_own_connection' => 'novamira_confirmation_pending',
            'chat_not_refused' => '',
            'ai_cannot_cancel' => true,
            'no_cancel_in_schema' => true,
            'card' => [2, [0, 1, 2, 3], [false, false, true, true]],
            'page_rejects' => ['rejected'],
            'next_after_reject' => ['pending_confirmation', true],
            'expired_frees' => true,
        ], $r['one_at_a_time']);
    }

    public function testCallsOfOneConversationAreDecidedTogetherAndRunInOrder(): void
    {
        $r = self::$result['blocks'];
        self::assertSame(['pending_confirmation', true, 1], $r['other_session']);
        self::assertSame([true, true, 3], $r['small_flagged']);
        self::assertSame([true, ['approved', 'approved', 'approved']], $r['approved']);
        self::assertSame(['echo 10;', 'echo 11;', 'echo 12;'], $r['ran_in_order']);
        self::assertCount(1, $r['one_history_item']);
        self::assertStringStartsWith('approval-', (string) $r['one_history_item'][0]);
        self::assertSame(
            ['completed', true, 'completed', true, ['size' => 3, 'ran' => 3, 'failed_at' => null, 'not_run' => 0]],
            $r['own_results'],
        );
        self::assertSame([$r['one_history_item'][0], ''], $r['history_group']);
        self::assertSame([
            ['echo 30;'],
            ['completed', 'failed', 'not_run'],
            ['size' => 3, 'ran' => 1, 'failed_at' => 1, 'not_run' => 1],
            true,
            true,
        ], $r['failure']);
        self::assertSame(
            ['novamira_card_changed', ['pending_confirmation', 'pending_confirmation'], true],
            $r['card_changed'],
        );
        self::assertSame([true, true, 3], $r['racing']);
        self::assertSame([10, true, true], $r['eleventh']);
        self::assertTrue($r['waits_for_earlier']);
        self::assertSame([
            ['completed', 'approved', 'approved'],
            ['echo 60;', 'echo 61;', 'echo 62;'],
            ['completed', 'completed', 'completed'],
        ], $r['cut_then_driven']);
        self::assertTrue($r['running_refuses']);
        self::assertSame([['completed', 'not_run', 'not_run'], [], 2], $r['step_deadline']);
        self::assertSame([true, true, true], $r['rest_scope']);
        self::assertSame('', $r['chat_while_waiting']);
        self::assertSame(0, $r['slots_pruned']);
    }
    public function testACardSaysWhenNovamiraCannotReadTheCodeAndOffersTheCode(): void
    {
        $r = self::$result;
        self::assertSame([
            'unreadable_flagged' => true,
            'unreadable_score' => 10,
            'readable_not_flagged' => true,
            'public_flag' => true,
        ], $r['code_card']);
        self::assertSame(['kind' => 'php', 'path' => '', 'text' => 'echo 1;'], $r['code_view']['php']);
        self::assertSame(['kind' => 'command', 'path' => '', 'text' => 'wp user create bob b@x.it'], $r['code_view']['command']);
        self::assertSame(['kind' => 'file', 'path' => 'wp-content/x.php', 'text' => '<?php echo 1;'], $r['code_view']['file']);
        self::assertSame(['kind' => 'edit', 'path' => 'a.php', 'text' => "- a\n+ b"], $r['code_view']['edit']);
        self::assertSame('call', $r['code_view']['other']['kind']);
        self::assertSame(['id' => 4], json_decode($r['code_view']['other']['text'], associative: true));
        self::assertSame(['method' => 'GET', 'needs_nonce' => true, 'missing' => 404], $r['code_route']);
    }
    public function testWhatWordPressRecoversDoesNotGrowInALoop(): void
    {
        self::assertSame([
            'trash_in_loop' => 5,
            'trash_in_array_map' => 5,
            'plugins_off_in_loop' => 5,
            'plugins_off_unknown_in_loop' => 5,
            'plugins_off_novamira_in_loop' => 5,
            'plugins_off_array_map' => 5,
            'single_trash' => 5,
            'trash_post_comments_in_loop' => 9,
            'cli_already_trashed' => 9,
            'cli_one_already_trashed' => 5,
            'delete_in_loop' => 9,
            'cli_posts_to_trash' => 5,
            'cli_product_not_trash' => 9,
            'cli_force' => 9,
        ], self::$result['recoverable_trash_on']);
        self::assertSame([
            'trash_in_loop' => 9,
            'trash_in_array_map' => 9,
            'plugins_off_in_loop' => 5,
            'plugins_off_unknown_in_loop' => 5,
            'plugins_off_novamira_in_loop' => 5,
            'plugins_off_array_map' => 5,
            'single_trash' => 5,
            'trash_post_comments_in_loop' => 9,
            'cli_already_trashed' => 9,
            'cli_one_already_trashed' => 5,
            'delete_in_loop' => 9,
            'cli_posts_to_trash' => 9,
            'cli_product_not_trash' => 9,
            'cli_force' => 9,
        ], self::$result['recoverable_trash_off']);
    }
    public function testInstructionsKeepTheAiOffTheApprovalPage(): void
    {
        self::assertSame(['no_browser_approval' => true, 'eval_refused' => true], self::$result['instructions_say']);
    }
    public function testCodeGivenAsTextIsRefusedInsideExecutePhpOnly(): void
    {
        self::assertSame([
            'eval_refused' => true,
            'create_function_refused' => true,
            'message' => true,
            'dynamic_call_still_asks' => true,
            'eval_in_a_file_asks' => true,
            'refusal_keeps_other_findings' => ['refuse:code_as_text', 'medium:files'],
        ], self::$result['code_as_text']);
    }
    public function testTheAisRequestsToANewAddressAskOnce(): void
    {
        self::assertSame([
            'get_literal' => ['send:api.example.com'],
            'post_variable_once' => ['send:hooks.example.com'],
            'variable_twice' => ['send:?'],
            'host_unknown' => ['send:?'],
            'interpolated_host_unknown' => ['send:?'],
            'host_cut_short' => ['send:?'],
            'reader_local_file' => [],
            'reader_url' => ['send:feeds.example.com'],
            'curl' => ['send:crm.example.com'],
            'callback' => ['send:?'],
            'socket' => ['send:mail.example.com'],
        ], self::$result['destinations']);
        self::assertSame([
            'new_asks_at_100' => true,
            'why_names_it' => true,
            'own_site_free' => false,
            'wordpress_org_free' => false,
            'remembered' => ['api.example.com'],
            'approved_runs' => true,
            'other_address_asks' => true,
            'unknown_still_asks' => true,
            'unknown_not_remembered' => ['api.example.com'],
            'forgotten' => [],
            'asks_again' => true,
            'skip_asks_nothing' => false,
            'mcp_waits' => 'pending_confirmation',
            'instructions' => true,
        ], self::$result['destinations_ask']);
    }
    public function testSkippingApprovalsIsOneOfTheLevels(): void
    {
        self::assertSame([
            'skipped' => true,
            'cutoff_kept' => 8,
            'chosen' => true,
            'level_turns_it_off' => false,
            'cutoff_now' => 5,
        ], self::$result['skip_as_level']);
    }

    public function testTheSiteInstructionsReachTheAi(): void
    {
        $r = self::$result['site_instructions'];
        self::assertSame('Ticket statuses notify customers.', $r['saved']);
        self::assertStringContainsString("These abilities always ask (read an ability's info for its full description):\n- acme/change-ticket-status", $r['text']);
        self::assertSame(['Changes a ticket.', 'No dot here', str_repeat('a', 157) . '...'], $r['first_sentence']);
        self::assertStringContainsString('reaches the same effect as one of them, rate it 10.', $r['text']);
        self::assertStringContainsString("Rate any call that does one of them 10:\nTicket statuses notify customers.", $r['text']);
        self::assertTrue($r['in_scoring']);
        self::assertTrue($r['in_rest_help']);
        self::assertSame('', $r['empty']);
    }

    public function testARatedCallNeedsTheCodeOfTheRulesInForce(): void
    {
        $r = self::$result['rules_gate'];
        self::assertMatchesRegularExpression('/^[a-z]{3}$/', $r['code']);
        self::assertTrue($r['refused']);
        self::assertStringContainsString('Changing the site tagline.', $r['text']);
        self::assertStringContainsString("Rules code: {$r['code']}.", $r['text']);
        self::assertStringContainsString('Nothing has executed.', $r['text']);
        self::assertSame(0, $r['ran_without']);
        self::assertSame(1, $r['ran_with']);
        self::assertTrue($r['changed']);
        self::assertStringContainsString('Changing the site title.', $r['old_code_text']);
        self::assertSame(1, $r['old_code_ran']);
        self::assertTrue($r['in_scoring']);
        self::assertTrue($r['in_rest_help']);
        self::assertNotFalse($r['in_context']);
        self::assertSame(['', ''], $r['none']);
    }

    public function testChangingThePermalinksIsTheTopOfTheScale(): void
    {
        self::assertSame([10, 10, ['max:permalinks'], []], self::$result['permalinks']);
    }

    public function testApprovalsAreExplainedToAPersonOnce(): void
    {
        self::assertSame([true, ''], self::$result['first_hint']);
        self::assertSame([true, true, true], self::$result['decision_rule']);
    }
}
