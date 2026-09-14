<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

use function Novamira\OAuth\Schema\column_report;
use function Novamira\OAuth\Schema\declared_column;
use function Novamira\OAuth\Schema\maybe_install;
use function Novamira\OAuth\Schema\repair_missing_columns;
use function Novamira\OAuth\Schema\required_columns;
use function Novamira\OAuth\Schema\required_tables;
use function Novamira\OAuth\Schema\schedule_gc;
use function Novamira\OAuth\Schema\table_definitions;

use const Novamira\OAuth\Schema\CURRENT_SCHEMA_VERSION;
use const Novamira\OAuth\Schema\SCHEMA_VERSION_OPTION;

/**
 * What the installer does, once, and what it must never do.
 *
 * A site that has recorded the current version pays for one option read per request and nothing
 * else. Every other site gets exactly one pass: an install from nothing submits the table
 * definitions as it always did, while a site the previous release installed only has its provably
 * missing columns added. Either way the version is then recorded whatever the ALTER statements
 * achieved, so a database user who may not ALTER does not turn into an attempt on every request —
 * the columns are added on demand instead, by registration and by the storage check.
 *
 * Repairing a table must only add: dbDelta changes an installed column whose declared type differs
 * (and can change a differing default), so the repair issues one ADD COLUMN per absent column. A
 * site recorded at the version whose tables are complete, with every table present, never has a
 * table definition submitted again, and neither do the on-demand repairs; an unrecorded or older
 * install, and a recorded site with a table missing, keep the installer's dbDelta behaviour.
 *
 * Stubs, the wpdb double and the dbDelta stand-in live in fixtures/, loaded at runtime in setUp()
 * so PHPUnit discovery never declares them in the parent process.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SchemaColumnGuardTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/fixtures/client-store-stubs.php';
        require_once __DIR__ . '/../../includes/oauth/schema.php';
        nm_test_reset_state();
    }

    /** The per-request cost of a current site is exactly what it was before any of this: one read. */
    public function testACurrentSiteRunsNoSchemaQueryAtAll(): void
    {
        maybe_install();
        nm_test_forget_schema_activity();

        maybe_install();

        self::assertSame([SCHEMA_VERSION_OPTION], $GLOBALS['nm_option_reads']);
        self::assertSame([], $this->schemaReads());
        self::assertSame([], $this->schemaWrites());
        self::assertSame([], $GLOBALS['nm_transient_calls']);
        self::assertSame([], $GLOBALS['nm_dbdelta_calls']);
    }

    public function testAFreshInstallCreatesEveryTableAndRecordsTheVersion(): void
    {
        maybe_install();

        self::assertCount(6, $GLOBALS['nm_dbdelta_calls']);
        self::assertSame(CURRENT_SCHEMA_VERSION, get_option(SCHEMA_VERSION_OPTION));
        self::assertSame(['missing' => [], 'unreadable' => []], column_report(nm_test_oauth_prefix()));
        self::assertSame([], $this->schemaWrites());
    }

    /**
     * An established decision of this installer: while a table is missing, nothing is recorded, so
     * the next request runs the installer again rather than leaving a site without its tables.
     */
    public function testAFreshInstallMissingATableIsNotRecordedAndRunsAgain(): void
    {
        $GLOBALS['nm_dbdelta_cannot_create'] = [nm_test_oauth_prefix() . 'device_codes'];

        maybe_install();
        maybe_install();

        self::assertFalse(get_option(SCHEMA_VERSION_OPTION));
        self::assertCount(12, $GLOBALS['nm_dbdelta_calls']);
    }

    /**
     * A site with nothing recorded but an old clients table dbDelta could not complete gets the
     * additive pass after the definitions, and is recorded whatever that pass achieved.
     */
    public function testAnInstallAddsWhatDbDeltaCouldNotAndRecordsTheVersion(): void
    {
        nm_test_seed_incomplete_clients_table(['grant_types'], recorded: false);

        maybe_install();

        self::assertCount(6, $GLOBALS['nm_dbdelta_calls']);
        self::assertSame(
            ['ALTER TABLE `' . nm_test_clients_table() . "` ADD COLUMN grant_types VARCHAR(191) NOT NULL DEFAULT ''"],
            $this->schemaWrites(),
        );
        self::assertSame(CURRENT_SCHEMA_VERSION, get_option(SCHEMA_VERSION_OPTION));
    }

    /** An install from nothing still keeps the rows it finds. */
    public function testTheInstallerKeepsTheRowsItAlreadyHas(): void
    {
        maybe_install();
        nm_test_store_client('existingclient00000000000000000a');
        delete_option(SCHEMA_VERSION_OPTION);

        maybe_install();

        /** @var wpdb $wpdb */
        $wpdb = $GLOBALS['wpdb'];
        self::assertCount(1, $wpdb->rows[nm_test_clients_table()]);
        self::assertSame('existingclient00000000000000000a', $wpdb->rows[nm_test_clients_table()][0]['client_id']);
        self::assertSame(CURRENT_SCHEMA_VERSION, get_option(SCHEMA_VERSION_OPTION));
    }

    /** The upgrade from the previous release adds a missing column, without dbDelta, and records. */
    public function testTheUpgradeAddsAMissingColumnAndRecordsTheVersion(): void
    {
        nm_test_seed_incomplete_clients_table(['grant_types']);
        $GLOBALS['nm_test_can_alter'] = true;

        maybe_install();

        self::assertSame([], $GLOBALS['nm_dbdelta_calls']);
        self::assertArrayHasKey('grant_types', nm_test_columns(nm_test_clients_table()));
        self::assertSame(CURRENT_SCHEMA_VERSION, get_option(SCHEMA_VERSION_OPTION));
    }

    /**
     * A denied ALTER is recorded as a finished pass, so it does not become an attempt on every
     * request: the very next request is back to one option read.
     */
    public function testTheUpgradeRecordsTheVersionEvenWhenTheAlterIsDenied(): void
    {
        nm_test_seed_incomplete_clients_table(['grant_types']);

        maybe_install();

        self::assertSame(CURRENT_SCHEMA_VERSION, get_option(SCHEMA_VERSION_OPTION));
        self::assertArrayNotHasKey('grant_types', nm_test_columns(nm_test_clients_table()));

        nm_test_forget_schema_activity();
        maybe_install();

        self::assertSame([], $this->schemaReads());
        self::assertSame([], $this->schemaWrites());
    }

    public function testTheUpgradeOfACompleteSiteIssuesNoStatement(): void
    {
        maybe_install();
        update_option(SCHEMA_VERSION_OPTION, '4');
        nm_test_forget_schema_activity();

        maybe_install();

        self::assertSame([], $GLOBALS['nm_dbdelta_calls']);
        self::assertSame([], $this->schemaWrites());
        self::assertSame(CURRENT_SCHEMA_VERSION, get_option(SCHEMA_VERSION_OPTION));
    }

    /**
     * A table that exists but whose columns the database would not describe is not written to on a
     * guess, and the pass still ends. (An absent table is different: see the installer tests above.)
     */
    public function testTheUpgradeRecordsTheVersionWithoutGuessingAtAnUnreadableTable(): void
    {
        maybe_install();
        update_option(SCHEMA_VERSION_OPTION, '4');
        $GLOBALS['nm_unreadable_tables'] = [nm_test_clients_table()];
        nm_test_forget_schema_activity();

        maybe_install();

        self::assertSame([], $GLOBALS['nm_dbdelta_calls']);
        self::assertSame([], $this->schemaWrites());
        self::assertSame(CURRENT_SCHEMA_VERSION, get_option(SCHEMA_VERSION_OPTION));
    }

    /**
     * A table dropped after '4' was recorded (or a prefix changed since) leaves a site that is not
     * complete. Recording '5' there would let the fast path suppress the installer for good, so the
     * site goes through install() like an unrecorded one: the table is created, then '5' recorded.
     */
    public function testTheUpgradeCreatesATableThatIsMissingThroughTheInstaller(): void
    {
        $table = nm_test_oauth_prefix() . 'device_codes';
        $this->installThenRecordFourWithout($table);

        maybe_install();

        self::assertCount(6, $GLOBALS['nm_dbdelta_calls']);
        self::assertArrayHasKey($table, $this->tables());
        self::assertSame(CURRENT_SCHEMA_VERSION, get_option(SCHEMA_VERSION_OPTION));
    }

    /**
     * While the missing table cannot be created, nothing is recorded and the next request tries
     * again — the installer's established decision for a partial install — and '5' is recorded only
     * on the request that finally finds every table.
     */
    public function testTheUpgradeKeepsTheOldVersionWhileAMissingTableCannotBeCreated(): void
    {
        $table = nm_test_oauth_prefix() . 'device_codes';
        $this->installThenRecordFourWithout($table);
        $GLOBALS['nm_dbdelta_cannot_create'] = [$table];

        maybe_install();

        self::assertSame('4', get_option(SCHEMA_VERSION_OPTION));
        self::assertArrayNotHasKey($table, $this->tables());
        self::assertCount(6, $GLOBALS['nm_dbdelta_calls']);

        maybe_install();

        self::assertSame('4', get_option(SCHEMA_VERSION_OPTION));
        self::assertCount(12, $GLOBALS['nm_dbdelta_calls']);

        $GLOBALS['nm_dbdelta_cannot_create'] = [];
        maybe_install();

        self::assertArrayHasKey($table, $this->tables());
        self::assertSame(CURRENT_SCHEMA_VERSION, get_option(SCHEMA_VERSION_OPTION));
    }

    /** The repair reports what was missing before it acted, so a caller knows there was something. */
    public function testTheRepairReturnsWhatWasMissingBeforeItActed(): void
    {
        nm_test_seed_incomplete_clients_table(['admin_created', 'grant_types']);
        $GLOBALS['nm_test_can_alter'] = true;

        $report = repair_missing_columns(nm_test_oauth_prefix());

        self::assertSame(['clients' => ['admin_created', 'grant_types']], $report['missing']);
        self::assertSame([
            ['table' => nm_test_clients_table(), 'column' => 'admin_created'],
            ['table' => nm_test_clients_table(), 'column' => 'grant_types'],
        ], $GLOBALS['nm_added_columns']);
        self::assertSame(['missing' => [], 'unreadable' => []], column_report(nm_test_oauth_prefix()));
    }

    /**
     * The repair can only add a column whose definition its own table declares, so every column
     * required_columns() names has to be declared by that statement.
     */
    public function testEveryRequiredColumnIsDeclaredByItsTableStatement(): void
    {
        $definitions = table_definitions(nm_test_oauth_prefix());

        foreach (required_columns() as $suffix => $columns) {
            self::assertArrayHasKey($suffix, $definitions);
            foreach ($columns as $column) {
                self::assertNotNull(
                    declared_column($definitions[$suffix], $column),
                    sprintf('%s does not declare %s', $suffix, $column),
                );
            }
        }
        self::assertCount(6, required_tables(nm_test_oauth_prefix()));
    }

    /** An index can share a column's name, and the column's own line is the one that is declared. */
    public function testAColumnIsNotConfusedWithAnIndexOfTheSameName(): void
    {
        $definitions = table_definitions(nm_test_oauth_prefix());

        self::assertSame('client_id VARCHAR(64) NOT NULL', declared_column($definitions['clients'], 'client_id'));
        self::assertNull(declared_column($definitions['clients'], 'no_such_column'));
    }

    public function testTheAddedColumnCarriesItsDeclaredDefinition(): void
    {
        nm_test_seed_incomplete_clients_table(['grant_types']);
        $GLOBALS['nm_test_can_alter'] = true;

        maybe_install();

        self::assertSame("VARCHAR(191) NOT NULL DEFAULT ''", nm_test_columns(nm_test_clients_table())['grant_types']);
    }

    /**
     * The repair adds and can do nothing else. A column of the repaired table that had drifted —
     * wider, or with a different default — is left exactly as it is, because the statement that adds
     * the missing column does not name it.
     */
    public function testRepairingATableLeavesADriftedColumnAlone(): void
    {
        nm_test_seed_incomplete_clients_table(['grant_types']);
        $table = nm_test_clients_table();
        /** @var wpdb $wpdb */
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->tables[$table]['client_name'] = 'VARCHAR(255) NOT NULL';
        $wpdb->tables[$table]['is_confidential'] = 'TINYINT(1) NOT NULL DEFAULT 1';
        $GLOBALS['nm_test_can_alter'] = true;

        maybe_install();

        self::assertArrayHasKey('grant_types', nm_test_columns($table));
        self::assertSame([], $GLOBALS['nm_column_changes']);
        self::assertSame('VARCHAR(255) NOT NULL', nm_test_columns($table)['client_name']);
        self::assertSame('TINYINT(1) NOT NULL DEFAULT 1', nm_test_columns($table)['is_confidential']);
    }

    /**
     * Losing a race to a concurrent request means the database refuses the duplicate column. That
     * refusal is expected, so it must not be printed into the page of a site that displays database
     * errors — and that site's own setting has to come back afterwards.
     */
    public function testTheRepairDoesNotDisplayARefusedColumnStatement(): void
    {
        nm_test_seed_incomplete_clients_table(['grant_types']);
        /** @var wpdb $wpdb */
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->show_errors = true;

        maybe_install();

        self::assertSame([false], $wpdb->query_showing_errors);
        self::assertTrue($wpdb->show_errors);
    }

    /**
     * The harness refuses any schema change that is not an addition, so a repair that regressed to
     * a CHANGE COLUMN — its own, or one from a table definition — could not pass unnoticed here.
     */
    public function testTheHarnessRefusesASchemaChangeThatIsNotAnAddition(): void
    {
        maybe_install();
        /** @var wpdb $wpdb */
        $wpdb = $GLOBALS['wpdb'];

        $this->expectException(RuntimeException::class);
        $wpdb->query(sprintf(
            'ALTER TABLE `%s` CHANGE COLUMN `client_name` client_name VARCHAR(191) NOT NULL',
            nm_test_clients_table(),
        ));
    }

    /**
     * Loading the file for its schema definitions must leave nothing behind: the Troubleshoot page
     * loads it on sites where boot() deliberately did not, and boot() is what schedules the cleanup.
     */
    public function testLoadingTheFileSchedulesNothingUntilBootAsksForIt(): void
    {
        self::assertSame([], $GLOBALS['nm_scheduled'] ?? []);

        schedule_gc();

        self::assertSame([['novamira_oauth_gc']], array_map(
            static fn(array $event): array => [$event[2]],
            $GLOBALS['nm_scheduled'],
        ));
    }

    /** A complete install recorded at '4', with one table since gone. */
    private function installThenRecordFourWithout(string $table): void
    {
        maybe_install();
        /** @var wpdb $wpdb */
        $wpdb = $GLOBALS['wpdb'];
        unset($wpdb->tables[$table], $wpdb->rows[$table]);
        update_option(SCHEMA_VERSION_OPTION, '4');
        nm_test_forget_schema_activity();
    }

    /** @return array<string, array<string, string>> */
    private function tables(): array
    {
        /** @var wpdb $wpdb */
        $wpdb = $GLOBALS['wpdb'];
        return $wpdb->tables;
    }

    /** @return list<string> */
    private function schemaReads(): array
    {
        /** @var wpdb $wpdb */
        $wpdb = $GLOBALS['wpdb'];
        return $wpdb->reads;
    }

    /** @return list<string> */
    private function schemaWrites(): array
    {
        /** @var wpdb $wpdb */
        $wpdb = $GLOBALS['wpdb'];
        return $wpdb->queries;
    }
}
