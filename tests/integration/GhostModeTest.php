<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class GhostModeTest extends TestCase
{
    private const ADMINS = [
        '1' => ['manage' => true, 'login' => 'agency'],
        '2' => ['manage' => true, 'login' => 'client'],
        '3' => ['manage' => false, 'login' => 'editor'],
    ];

    public function testAbsentOptionIsDisabled(): void
    {
        $result = $this->runScenario('normalize', ['args' => ['raw' => false]]);

        self::assertSame('absent', $result['result']['state']);
        self::assertFalse($result['result']['enabled']);
    }

    public function testNonArrayOrMissingEnabledIsUnreadable(): void
    {
        self::assertSame('unreadable', $this->runScenario('normalize', ['args' => ['raw' => 'on']])['result']['state']);
        self::assertSame(
            'unreadable',
            $this->runScenario('normalize', ['args' => ['raw' => ['visible_to' => [1]]]])['result']['state'],
        );
    }

    public function testNearlyValidOptionKeepsTheValidIdsAndCountsTheRest(): void
    {
        $result = $this->runScenario('normalize', ['args' => ['raw' => [
            'schema_version' => 99,
            'enabled' => true,
            'visible_to' => [1, '4', 1, 'x', -3, null],
        ]]]);

        self::assertSame('ok', $result['result']['state']);
        self::assertSame([1, 4], $result['result']['visible_to']);
        self::assertSame(3, $result['result']['malformed']);
        self::assertSame(99, $result['result']['schema_version']);
    }

    public function testSaveRequiresAValidOwnerIncludingTheSavingUser(): void
    {
        $world = ['users' => self::ADMINS];

        self::assertSame(
            'novamira_ghost_mode_no_owner',
            $this->runScenario('save', $world + ['args' => [
                'enabled' => true, 'visible_to' => [], 'user_id' => 1, 'source' => 'pro',
            ]])['result']['error'],
        );
        self::assertSame(
            'novamira_ghost_mode_self_missing',
            $this->runScenario('save', $world + ['args' => [
                'enabled' => true, 'visible_to' => [2], 'user_id' => 1, 'source' => 'pro',
            ]])['result']['error'],
        );
        self::assertSame(
            'novamira_ghost_mode_invalid_owner',
            $this->runScenario('save', $world + ['args' => [
                'enabled' => true, 'visible_to' => [1, 3], 'user_id' => 1, 'source' => 'pro',
            ]])['result']['error'],
        );
        self::assertSame(
            'novamira_ghost_mode_invalid_source',
            $this->runScenario('save', $world + ['args' => [
                'enabled' => true, 'visible_to' => [1], 'user_id' => 1, 'source' => 'filter',
            ]])['result']['error'],
        );
    }

    public function testSaveWritesTheFullRecord(): void
    {
        $result = $this->runScenario('save', ['users' => self::ADMINS, 'args' => [
            'enabled' => true, 'visible_to' => [1, '1'], 'user_id' => 1, 'source' => 'pro', 'pro_version' => '2.0.0',
        ]]);

        self::assertNull($result['result']['error']);
        $option = $result['result']['option'];
        self::assertSame(1, $option['schema_version']);
        self::assertTrue($option['enabled']);
        self::assertSame([1], $option['visible_to']);
        self::assertSame(1, $option['updated_by']);
        self::assertSame('pro', $option['source']);
        self::assertSame('2.0.0', $option['pro_version']);
        self::assertIsInt($option['updated_at']);
    }

    public function testDisableAlwaysWritesAndKeepsTheList(): void
    {
        $on = $this->runScenario('disable', [
            'users' => self::ADMINS,
            'option' => ['enabled' => true, 'visible_to' => [1]],
            'args' => ['user_id' => 0, 'source' => 'cli'],
        ])['result'];
        self::assertNull($on['error']);
        self::assertFalse($on['option']['enabled']);
        self::assertSame([1], $on['option']['visible_to']);

        $corrupt = $this->runScenario('disable', ['option' => 'garbage', 'args' => ['user_id' => 0, 'source' => 'cli']])['result'];
        self::assertNull($corrupt['error']);
        self::assertFalse($corrupt['option']['enabled']);
        self::assertSame([], $corrupt['option']['visible_to']);

        $absent = $this->runScenario('disable', ['args' => ['user_id' => 0, 'source' => 'cli']])['result'];
        self::assertNull($absent['error']);
        self::assertFalse($absent['option']);
    }

    public function testAddOwnerNeverEnablesAndRefusesUnreadableOrInvalid(): void
    {
        $off = $this->runScenario('add_owner', [
            'users' => self::ADMINS,
            'option' => ['enabled' => false, 'visible_to' => [1]],
            'args' => ['owner_id' => 2, 'user_id' => 0, 'source' => 'cli'],
        ])['result'];
        self::assertNull($off['error']);
        self::assertFalse($off['option']['enabled']);
        self::assertSame([1, 2], $off['option']['visible_to']);

        self::assertSame('novamira_ghost_mode_unreadable', $this->runScenario('add_owner', [
            'users' => self::ADMINS,
            'option' => 'garbage',
            'args' => ['owner_id' => 2, 'user_id' => 0, 'source' => 'cli'],
        ])['result']['error']);

        self::assertSame('novamira_ghost_mode_invalid_owner', $this->runScenario('add_owner', [
            'users' => self::ADMINS,
            'option' => ['enabled' => true, 'visible_to' => [1]],
            'args' => ['owner_id' => 3, 'user_id' => 0, 'source' => 'cli'],
        ])['result']['error']);
    }

    public function testBehaviourMatrix(): void
    {
        $on = ['enabled' => true, 'visible_to' => [1]];

        self::assertTrue($this->visible(['user' => 2]));
        self::assertTrue($this->visible(['user' => 2, 'option' => ['enabled' => false, 'visible_to' => [1]]]));
        self::assertTrue($this->visible(['user' => 1, 'option' => $on]));
        self::assertFalse($this->visible(['user' => 2, 'option' => $on]));
        self::assertFalse($this->visible(['user' => 3, 'option' => $on]));
        self::assertTrue($this->visible(['user' => 2, 'option' => $on, 'bypass' => true]));
        self::assertFalse($this->visible(['user' => 3, 'option' => $on, 'bypass' => true]));
        self::assertFalse($this->visible(['user' => 1, 'option' => 'garbage']));
        self::assertFalse($this->visible(['user' => 1, 'option' => ['enabled' => true, 'visible_to' => [9]]]));
        self::assertFalse($this->visible(['user' => 1, 'option' => $on, 'init' => false]));
    }

    public function testHiddenByPolicyOnlyForUsersWhoPassTheGate(): void
    {
        $on = ['enabled' => true, 'visible_to' => [1]];

        self::assertTrue($this->runScenario('visible', ['users' => self::ADMINS, 'user' => 2, 'option' => $on])['result']['hidden_by_policy']);
        self::assertFalse($this->runScenario('visible', ['users' => self::ADMINS, 'user' => 3, 'option' => $on])['result']['hidden_by_policy']);
        self::assertFalse($this->runScenario('visible', ['users' => self::ADMINS, 'user' => 1, 'option' => $on])['result']['hidden_by_policy']);
    }

    public function testOwnersLosingTheGateStopBeingOwners(): void
    {
        $users = self::ADMINS;
        $users['1']['manage'] = false;
        $users['4'] = ['manage' => true, 'login' => 'other'];

        self::assertTrue($this->runScenario('visible', [
            'users' => $users,
            'user' => 4,
            'option' => ['enabled' => true, 'visible_to' => [1, 4]],
        ])['result']['visible']);
        self::assertFalse($this->runScenario('visible', [
            'users' => $users,
            'user' => 4,
            'option' => ['enabled' => true, 'visible_to' => [1]],
        ])['result']['visible']);
        self::assertSame([4], $this->runScenario('status', [
            'users' => $users,
            'option' => ['enabled' => true, 'visible_to' => [1, 4]],
        ])['result']['owners']);
    }

    public function testMultisiteUsesSuperAdmins(): void
    {
        $users = ['1' => ['manage' => true, 'login' => 'super'], '2' => ['manage' => false, 'login' => 'siteadmin']];

        self::assertTrue($this->visible(['multisite' => true, 'users' => $users, 'user' => 1, 'option' => [
            'enabled' => true, 'visible_to' => [1],
        ]]));
        self::assertFalse($this->visible(['multisite' => true, 'users' => $users, 'user' => 2]));
    }

    public function testStatusReportsIgnoredEntriesAndFailClosed(): void
    {
        $status = $this->runScenario('status', ['users' => self::ADMINS, 'option' => [
            'enabled' => true, 'visible_to' => [1, 3, 9, 'x'], 'source' => 'pro', 'updated_by' => 1, 'updated_at' => 5,
        ]])['result'];

        self::assertSame('on', $status['state']);
        self::assertSame([1], $status['owners']);
        self::assertSame([3, 9], $status['ignored_ids']);
        self::assertSame(1, $status['malformed']);
        self::assertFalse($status['fail_closed']);

        self::assertTrue($this->runScenario('status', ['users' => self::ADMINS, 'option' => [
            'enabled' => true, 'visible_to' => [3],
        ]])['result']['fail_closed']);
        self::assertSame('unreadable', $this->runScenario('status', ['option' => 7])['result']['state']);
        self::assertSame('off', $this->runScenario('status', [])['result']['state']);
    }

    public function testMenusDisappearForExcludedAdminsOnly(): void
    {
        $args = [
            'menu' => [[0 => 'Posts', 2 => 'edit.php'], [0 => 'Novamira', 2 => 'novamira-connect']],
            'submenu' => [
                'novamira-connect' => [[0 => 'Configuration', 2 => 'novamira-connect'], [0 => 'Memory', 2 => 'novamira-pro-memory']],
                '' => [
                    [0 => 'Connections', 2 => 'novamira-connections'],
                    [0 => 'Consent', 2 => 'novamira-oauth-consent'],
                    [0 => 'Other', 2 => 'other-plugin-hidden'],
                ],
                'edit.php' => [[0 => 'All Posts', 2 => 'edit.php']],
            ],
        ];
        $on = ['enabled' => true, 'visible_to' => [1]];

        $hidden = $this->runScenario('menus', ['users' => self::ADMINS, 'user' => 2, 'option' => $on, 'args' => $args])['result'];
        self::assertSame([[0 => 'Posts', 2 => 'edit.php']], $hidden['menu']);
        self::assertArrayNotHasKey('novamira-connect', $hidden['submenu']);
        self::assertSame(
            ['novamira-oauth-consent', 'other-plugin-hidden'],
            array_column(array_values($hidden['submenu']['']), 2),
        );

        $owner = $this->runScenario('menus', ['users' => self::ADMINS, 'user' => 1, 'option' => $on, 'args' => $args])['result'];
        self::assertCount(2, $owner['menu']);
        self::assertArrayHasKey('novamira-connect', $owner['submenu']);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: bool}>
     */
    public static function guardedRequests(): iterable
    {
        yield 'Configuration page' => [['pagenow' => 'admin.php', 'request' => ['get' => ['page' => 'novamira-connect']]], true];
        yield 'Pro memory page' => [['pagenow' => 'admin.php', 'request' => ['get' => ['page' => 'novamira-pro-memory']]], true];
        yield 'Ghost Mode page' => [['pagenow' => 'admin.php', 'request' => ['get' => ['page' => 'novamira-ghost-mode']]], true];
        yield 'OAuth consent page' => [['pagenow' => 'admin.php', 'request' => ['get' => ['page' => 'novamira-oauth-consent']]], false];
        yield 'Other plugin page' => [['pagenow' => 'admin.php', 'request' => ['get' => ['page' => 'woocommerce']]], false];
        yield 'Admin bar toggle' => [['pagenow' => 'admin-post.php', 'request' => ['get' => ['action' => 'novamira_toggle_ai_abilities']]], true];
        yield 'Visual workspace' => [['pagenow' => 'admin-post.php', 'request' => ['get' => ['action' => 'novamira-visual']]], true];
        yield 'Visual set abilities' => [['pagenow' => 'admin-ajax.php', 'ajax' => true, 'request' => ['post' => ['action' => 'novamira_visual_set_abilities']]], true];
        yield 'Ability toggle' => [['pagenow' => 'admin-ajax.php', 'ajax' => true, 'request' => ['post' => ['action' => 'novamira_toggle_ability']]], true];
        yield 'Visual auth check' => [['pagenow' => 'admin-ajax.php', 'ajax' => true, 'request' => ['post' => ['action' => 'novamira_visual_auth_check']]], false];
        yield 'Backend tools call' => [['pagenow' => 'admin-ajax.php', 'ajax' => true, 'request' => ['post' => ['action' => 'novamira_visual_safe_backend_tools_call']]], false];
        yield 'Heartbeat' => [['pagenow' => 'admin-ajax.php', 'ajax' => true, 'request' => ['post' => ['action' => 'heartbeat']]], false];
        yield 'Feature update POST' => [['pagenow' => 'index.php', 'request' => ['post' => ['novamira_feature_action' => 'set']]], true];
        yield 'Notice dismiss' => [['pagenow' => 'index.php', 'request' => ['get' => ['novamira_notice_dismiss' => 'novamira_x']]], true];
        yield 'Dashboard' => [['pagenow' => 'index.php'], false];
    }

    /**
     * @param array<string, mixed> $request
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('guardedRequests')]
    public function testGuardAnswers403ToExcludedAdmins(array $request, bool $blocked): void
    {
        $on = ['enabled' => true, 'visible_to' => [1]];

        $excluded = $this->runScenario('guard', $request + ['users' => self::ADMINS, 'user' => 2, 'option' => $on]);
        self::assertSame($blocked ? 403 : null, $excluded['died'] ?? null);

        $owner = $this->runScenario('guard', $request + ['users' => self::ADMINS, 'user' => 1, 'option' => $on]);
        self::assertSame('passed', $owner['result']);

        $editor = $this->runScenario('guard', $request + ['users' => self::ADMINS, 'user' => 3, 'option' => $on]);
        self::assertSame('passed', $editor['result']);
    }

    private static function freeBasename(): string
    {
        return basename(dirname(__DIR__, levels: 2)) . '/novamira.php';
    }

    /**
     * @return array<string, array{}>
     */
    private static function plugins(): array
    {
        return ['akismet/akismet.php' => [], self::freeBasename() => [], 'novamira-pro/novamira-pro.php' => []];
    }

    public function testPluginsListHidesBothRowsForExcludedAdmins(): void
    {
        $world = ['users' => self::ADMINS, 'option' => ['enabled' => true, 'visible_to' => [1]], 'args' => ['plugins' => self::plugins()]];

        self::assertSame(['akismet/akismet.php'], $this->runScenario('plugins', $world + ['user' => 2])['result']);
        self::assertCount(3, $this->runScenario('plugins', $world + ['user' => 1])['result']);
    }

    public function testPluginSearchOverAjaxStaysFiltered(): void
    {
        $world = ['users' => self::ADMINS, 'user' => 2, 'option' => ['enabled' => true, 'visible_to' => [1]], 'args' => ['plugins' => self::plugins()]];

        self::assertSame(['akismet/akismet.php'], $this->runScenario('plugins', $world + [
            'ajax' => true, 'request' => ['post' => ['action' => 'search-plugins']],
        ])['result']);
        self::assertCount(3, $this->runScenario('plugins', $world + [
            'ajax' => true, 'request' => ['post' => ['action' => 'heartbeat']],
        ])['result']);
    }

    public function testPluginsListReturnsNonArrayUnchanged(): void
    {
        $world = ['users' => self::ADMINS, 'user' => 2, 'option' => ['enabled' => true, 'visible_to' => [1]]];

        self::assertNull($this->runScenario('plugins_raw', $world + ['args' => ['plugins' => null]])['result']);
        self::assertFalse($this->runScenario('plugins_raw', $world + ['args' => ['plugins' => false]])['result']);
    }

    public function testUpdatesAreProjectedOnlyInInteractiveRequestsAndNeverMutated(): void
    {
        $world = [
            'users' => self::ADMINS,
            'user' => 2,
            'option' => ['enabled' => true, 'visible_to' => [1]],
            'args' => [
                'response' => self::plugins(),
                'no_update' => ['novamira-pro/novamira-pro.php' => []],
            ],
        ];

        $projected = $this->runScenario('transient', $world)['result'];
        self::assertSame(['akismet/akismet.php'], $projected['response']);
        self::assertSame([], $projected['no_update']);
        self::assertCount(3, $projected['original_response']);

        self::assertCount(3, $this->runScenario('transient', $world + ['cron' => true])['result']['response']);
        self::assertCount(3, $this->runScenario('transient', $world + ['constants' => ['REST_REQUEST' => true]])['result']['response']);
        self::assertCount(3, $this->runScenario('transient', $world + ['constants' => ['WP_CLI' => true]])['result']['response']);
        self::assertCount(3, $this->runScenario('transient', $world + ['constants' => ['XMLRPC_REQUEST' => true]])['result']['response']);
        self::assertCount(3, $this->runScenario('transient', $world + ['init' => false])['result']['response']);
    }

    public function testProjectionIsSuspendedWhileCoreRefreshesUpdates(): void
    {
        $world = [
            'users' => self::ADMINS,
            'user' => 2,
            'option' => ['enabled' => true, 'visible_to' => [1]],
            'args' => ['response' => [self::freeBasename() => []], 'no_update' => [], 'suspend' => true],
        ];

        self::assertSame([self::freeBasename()], $this->runScenario('transient', $world)['result']['response']);
    }

    public function testBypassNoticeShowsOnlyWhileGhostModeIsActive(): void
    {
        $world = ['users' => self::ADMINS, 'user' => 2, 'bypass' => true];

        self::assertStringContainsString(
            'NOVAMIRA_GHOST_MODE_BYPASS',
            $this->runScenario('bypass_notice', $world + ['option' => ['enabled' => true, 'visible_to' => [1]]])['output'],
        );
        self::assertSame('', $this->runScenario('bypass_notice', $world)['output']);
        self::assertSame('', $this->runScenario('bypass_notice', ['users' => self::ADMINS, 'user' => 3, 'bypass' => true, 'option' => ['enabled' => true, 'visible_to' => [1]]])['output']);
    }

    public function testFreePagePresentsTheFeatureAndInvitesToProWhenOff(): void
    {
        $output = $this->runScenario('page', ['users' => self::ADMINS, 'user' => 1])['output'];

        self::assertStringContainsString('Ghost Mode', $output);
        self::assertStringContainsString('not a security feature', $output);
        self::assertStringContainsString('It hides the Novamira menu', $output);
        self::assertStringContainsString('utm_medium=ghost_mode', $output);
        self::assertStringNotContainsString('[nonce:', $output);
        self::assertStringNotContainsString('<input type="checkbox"', $output);
    }

    public function testFreePageShowsStatusAndDisableWhenOnWithoutPro(): void
    {
        $output = $this->runScenario('page', ['users' => self::ADMINS, 'user' => 1, 'option' => [
            'enabled' => true, 'visible_to' => [1, 9],
        ]])['output'];

        self::assertStringContainsString('agency', $output);
        self::assertStringContainsString('1 entry', $output);
        self::assertStringContainsString('[nonce:novamira_ghost_mode_disable]', $output);
        self::assertStringContainsString('Novamira Pro', $output);
        self::assertStringNotContainsString('utm_medium=ghost_mode', $output);
    }

    public function testProPanelTakesOverThePage(): void
    {
        $result = $this->runScenario('page', ['users' => self::ADMINS, 'user' => 1, 'pro' => true, 'args' => ['panel' => true]]);

        self::assertSame([['do_action', 'novamira_ghost_mode_panel']], $result['calls']);
        self::assertSame('', $result['output']);
    }

    public function testOldProWithoutPanelDoesNotGetTheUpsell(): void
    {
        $output = $this->runScenario('page', ['users' => self::ADMINS, 'user' => 1, 'pro' => true])['output'];

        self::assertStringNotContainsString('utm_medium=ghost_mode', $output);
        self::assertStringContainsString('newer version of Novamira Pro', $output);
    }

    public function testStatusPageWithProActiveOffersUpdateInsteadOfActivate(): void
    {
        $output = $this->runScenario('page', ['users' => self::ADMINS, 'user' => 1, 'pro' => true, 'option' => [
            'enabled' => true, 'visible_to' => [1],
        ]])['output'];

        self::assertStringContainsString('Update Novamira Pro to change who sees Novamira.', $output);
        self::assertStringNotContainsString('activate Novamira Pro', $output);
        self::assertStringContainsString('[nonce:novamira_ghost_mode_disable]', $output);
    }

    public function testDisabledNoticeShowsOnlyWithTheGetParameter(): void
    {
        $world = ['users' => self::ADMINS, 'user' => 1, 'option' => ['enabled' => true, 'visible_to' => [1]]];

        $withParam = $this->runScenario('page', $world + ['request' => ['get' => ['novamira_ghost_mode' => 'disabled']]])['output'];
        self::assertStringContainsString('Ghost Mode is off. Every administrator sees Novamira again.', $withParam);

        $withoutParam = $this->runScenario('page', $world)['output'];
        self::assertStringNotContainsString('Ghost Mode is off. Every administrator sees Novamira again.', $withoutParam);
    }

    public function testDisableNeedsNonceAndConfirmation(): void
    {
        $on = ['enabled' => true, 'visible_to' => [1]];
        $base = ['users' => self::ADMINS, 'user' => 1, 'option' => $on, 'pagenow' => 'admin-post.php'];

        self::assertSame(403, $this->runScenario('disable_post', $base + ['request' => ['post' => ['_wpnonce' => 'x', 'confirm' => '1']]])['died']);
        self::assertSame(400, $this->runScenario('disable_post', $base + ['request' => ['post' => ['_wpnonce' => 'novamira_ghost_mode_disable']]])['died']);

        $done = $this->runScenario('disable_post', $base + ['request' => ['post' => ['_wpnonce' => 'novamira_ghost_mode_disable', 'confirm' => '1']]]);
        self::assertContains(
            ['redirect', 'https://example.test/wp-admin/admin.php?page=novamira-ghost-mode&novamira_ghost_mode=disabled'],
            $done['calls'],
        );
        self::assertSame('exited', $done['result']);
        self::assertFalse($done['option']['enabled']);
        self::assertSame('free-ui', $done['option']['source']);
    }

    public function testExcludedAdminCannotDisable(): void
    {
        $result = $this->runScenario('disable_post', [
            'users' => self::ADMINS,
            'user' => 2,
            'option' => ['enabled' => true, 'visible_to' => [1]],
            'request' => ['post' => ['_wpnonce' => 'novamira_ghost_mode_disable', 'confirm' => '1']],
        ]);

        self::assertSame(403, $result['died']);
    }

    public function testCliHasNoEnableAndDisableNeedsConfirmation(): void
    {
        $world = ['users' => self::ADMINS, 'constants' => ['WP_CLI' => true], 'option' => ['enabled' => true, 'visible_to' => [1]]];

        $unconfirmed = $this->runScenario('cli', $world + ['args' => ['command' => 'disable']])['result'];
        self::assertSame(
            ['novamira ghost-mode status', 'novamira ghost-mode disable', 'novamira ghost-mode owners add'],
            $unconfirmed['commands'],
        );
        self::assertTrue($unconfirmed['option']['enabled']);

        $confirmed = $this->runScenario('cli', $world + ['args' => ['command' => 'disable', 'assoc' => ['yes' => true]]])['result'];
        self::assertFalse($confirmed['option']['enabled']);
        self::assertSame('cli', $confirmed['option']['source']);
    }

    public function testConnectedUsersListsOauthClientsAndNovamiraPasswords(): void
    {
        $result = $this->runScenario('connected_users', [
            'oauth_rows' => [
                ['user_id' => '2', 'client_name' => 'Claude'],
                ['user_id' => '2', 'client_name' => 'ChatGPT'],
                ['user_id' => '4', 'client_name' => 'Claude'],
            ],
            'app_passwords' => [
                '2' => [['name' => 'Novamira Claude'], ['name' => 'Other tool']],
                '5' => [['name' => 'Other tool']],
            ],
        ])['result'];

        self::assertSame(['Claude', 'ChatGPT'], $result['2']['oauth']);
        self::assertSame(1, $result['2']['app_passwords']);
        self::assertSame(['Claude'], $result['4']['oauth']);
        self::assertSame(0, $result['4']['app_passwords']);
        self::assertArrayNotHasKey('5', $result);
    }

    public function testConnectedUsersWithoutOauthTables(): void
    {
        $result = $this->runScenario('connected_users', [
            'oauth' => false,
            'oauth_rows' => [['user_id' => '2', 'client_name' => 'Claude']],
            'app_passwords' => ['3' => [['name' => 'Novamira']]],
        ])['result'];

        self::assertSame(['3'], array_map('strval', array_keys($result)));
    }

    public function testSecurityCalloutComesFirst(): void
    {
        $output = $this->runScenario('page', ['users' => self::ADMINS, 'user' => 1])['output'];

        self::assertLessThan(
            strpos($output, 'Updates are up to you.'),
            strpos($output, 'Ghost Mode is not a security feature.'),
        );
    }

    public function testCliStatusAndOwnersAdd(): void
    {
        $world = ['users' => self::ADMINS, 'constants' => ['WP_CLI' => true], 'option' => ['enabled' => true, 'visible_to' => [9]]];

        $status = $this->runScenario('cli', $world + ['args' => ['command' => 'status']])['result']['out'];
        self::assertContains(['warning', 'No administrator on the list can manage Novamira, so it is hidden from everyone. Run: wp novamira ghost-mode owners add <user>'], $status);

        $added = $this->runScenario('cli', $world + ['args' => ['command' => 'owners add', 'user' => 'client']])['result'];
        self::assertSame([9, 2], $added['option']['visible_to']);
        self::assertTrue($added['option']['enabled']);

        $refused = $this->runScenario('cli', $world + ['args' => ['command' => 'owners add', 'user' => 'editor']])['result'];
        self::assertSame('error', $refused['out'][0][0]);
    }

    /**
     * @param array<string, mixed> $world
     */
    private function visible(array $world): bool
    {
        return (bool) $this->runScenario('visible', $world + ['users' => self::ADMINS])['result']['visible'];
    }

    /**
     * @param array<string, mixed> $world
     * @return array<string, mixed>
     */
    private function runScenario(string $scenario, array $world): array
    {
        $root = dirname(__DIR__, levels: 2);
        $command = [
            PHP_BINARY,
            $root . '/tests/fixtures/ghost-mode-runner.php',
            $root,
            $scenario,
            (string) json_encode($world),
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

        return $decoded;
    }
}
