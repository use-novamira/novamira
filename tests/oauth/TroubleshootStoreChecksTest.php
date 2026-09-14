<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

use function Novamira\OAuth\Schema\declared_column;
use function Novamira\OAuth\Schema\maybe_install;
use function Novamira\OAuth\Schema\table_definitions;
use function Novamira\Troubleshoot\Checks\check_registration;
use function Novamira\Troubleshoot\Checks\check_schema;
use function Novamira\Troubleshoot\Checks\schema_columns_result;
use function Novamira\Troubleshoot\Checks\schema_repair_statements;

/**
 * The two diagnostics that claimed to cover client storage must actually cover it — and must not
 * overshoot in the other direction.
 *
 * Both used to report OK on a site where no client could register: the storage check asserted table
 * existence and never columns, and the registration check asserted HTTP 201 and never read the
 * client back. A check that reports a working site as broken is the same defect inverted, so what
 * each one claims has to match what it actually established.
 *
 * Stubs and the wpdb double live in fixtures/client-store-stubs.php, loaded at runtime in setUp()
 * so PHPUnit discovery never declares them in the parent process.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class TroubleshootStoreChecksTest extends TestCase
{
    private const PROBE_CLIENT_ID = 'probeclient000000000000000000000a';

    protected function setUp(): void
    {
        require_once __DIR__ . '/fixtures/client-store-stubs.php';
        require_once __DIR__ . '/../../includes/oauth/bootstrap.php';
        require_once __DIR__ . '/../../includes/oauth/schema.php';
        require_once __DIR__ . '/../../includes/oauth/repositories/client-repository.php';
        require_once __DIR__ . '/../../includes/troubleshoot/checks.php';
        nm_test_reset_state();
    }

    public function testStorageCheckPassesOnACompleteSchema(): void
    {
        maybe_install();

        $result = check_schema();

        self::assertSame('ok', $result['status']);
        self::assertSame('', $result['copy']);
    }

    /**
     * An administrator cannot act on "add the missing columns" and a host needs a concrete request,
     * so the result carries a message ready to send to hosting support: the site, what is wrong, and the
     * exact statement per missing column, with the site's real table name.
     */
    public function testStorageCheckOffersTheExactStatementForAMissingColumn(): void
    {
        nm_test_seed_incomplete_clients_table(['grant_types']);
        $table = nm_test_clients_table();

        $copy = check_schema()['copy'];

        self::assertStringStartsWith('Hello, my WordPress site https://example.test runs Novamira', $copy);
        self::assertStringContainsString('wp_novamira_oauth_clients', $table);
        self::assertStringContainsString(
            "ALTER TABLE `{$table}` ADD COLUMN grant_types VARCHAR(191) NOT NULL DEFAULT '';",
            $copy,
        );
        self::assertStringContainsString('grant the ALTER privilege', $copy);
        self::assertStringContainsString(
            'Novamira then adds the missing columns itself the next time an AI client registers or its Troubleshoot checks run.',
            $copy,
        );
        self::assertStringNotContainsString('minutes', $copy);
    }

    /**
     * The statement is not a copy kept in the check: it is the declared definition the installer
     * itself submits, so the SQL a host runs can never drift from what Novamira would have run.
     */
    public function testTheOfferedStatementIsTheInstallersOwnDeclaredDefinition(): void
    {
        nm_test_seed_incomplete_clients_table(['admin_created', 'grant_types']);
        $prefix = nm_test_oauth_prefix();
        $declared = table_definitions($prefix)['clients'];

        $copy = check_schema()['copy'];

        foreach (['admin_created', 'grant_types'] as $column) {
            self::assertStringContainsString(
                sprintf('ALTER TABLE `%sclients` ADD COLUMN %s;', $prefix, (string) declared_column($declared, $column)),
                $copy,
            );
        }
    }

    /** A column its table's statement does not declare gets no statement, never a guessed one. */
    public function testAColumnTheStatementDoesNotDeclareProducesNoStatement(): void
    {
        maybe_install();
        $prefix = nm_test_oauth_prefix();

        self::assertSame([], schema_repair_statements($prefix, ['clients' => ['no_such_column']]));
        self::assertSame(
            ["ALTER TABLE `{$prefix}clients` ADD COLUMN grant_types VARCHAR(191) NOT NULL DEFAULT '';"],
            schema_repair_statements($prefix, ['clients' => ['no_such_column', 'grant_types']]),
        );

        $result = schema_columns_result('OAuth storage', $prefix, [
            'missing' => ['clients' => ['no_such_column']],
            'unreadable' => [],
        ]);
        self::assertStringNotContainsString('no_such_column', $result['copy']);
        self::assertStringNotContainsString('ALTER TABLE', $result['copy']);
        self::assertStringContainsString('grant the ALTER privilege', $result['copy']);
    }

    /** The remedy is a sequence an administrator can follow, ending with remaking the connection. */
    public function testStorageCheckRemedyEndsWithReconnectingTheClient(): void
    {
        nm_test_seed_incomplete_clients_table(['grant_types']);

        $remedy = check_schema()['remedy'];

        self::assertStringContainsString('1. Send the message below', $remedy);
        self::assertStringContainsString('2. Once they have done either, run these checks again', $remedy);
        self::assertStringContainsString('3. Once this check passes, remove the AI connector', $remedy);
        self::assertStringContainsString('was never stored', $remedy);
        self::assertStringNotContainsString('minutes', $remedy);
    }

    /**
     * The check repairs what it finds before it reports: once the host has granted ALTER, running the
     * checks adds the column, and the result describes the storage as it is afterwards.
     */
    public function testStorageCheckAddsAMissingColumnItIsAllowedToAndReportsInstalled(): void
    {
        nm_test_seed_incomplete_clients_table(['grant_types']);
        $GLOBALS['nm_test_can_alter'] = true;

        $result = check_schema();

        self::assertSame('ok', $result['status']);
        self::assertSame('', $result['copy']);
        self::assertArrayHasKey('grant_types', nm_test_columns(nm_test_clients_table()));
        self::assertSame([
            'ALTER TABLE `' . nm_test_clients_table() . "` ADD COLUMN grant_types VARCHAR(191) NOT NULL DEFAULT ''",
        ], $this->schemaWrites());
    }

    /** Denied, the same check reports the failure with the SQL for the host — after one attempt. */
    public function testStorageCheckReportsTheFailureWithTheSqlWhenTheAlterIsDenied(): void
    {
        nm_test_seed_incomplete_clients_table(['grant_types']);

        $result = check_schema();

        self::assertSame('fail', $result['status']);
        self::assertStringContainsString('ADD COLUMN grant_types', $result['copy']);
        self::assertCount(1, $this->schemaWrites());
    }

    /** A complete schema costs the check its metadata reads and nothing else: no statement is issued. */
    public function testStorageCheckIssuesNoStatementOnACompleteSchema(): void
    {
        maybe_install();
        nm_test_forget_schema_activity();

        check_schema();

        self::assertSame([], $this->schemaWrites());
    }

    public function testStorageCheckFailsWhenTheClientsTableIsMissingARequiredColumn(): void
    {
        nm_test_seed_incomplete_clients_table(['grant_types']);

        $result = check_schema();

        self::assertSame('fail', $result['status']);
        self::assertStringContainsString(nm_test_clients_table(), $result['message']);
        self::assertStringContainsString('grant_types', $result['message']);
        self::assertStringContainsString('No AI client can register', $result['message']);
        self::assertStringContainsString('ALTER', $result['remedy']);
    }

    /**
     * A column missing from a table other than clients does not stop a registration: it breaks the
     * step that writes to that table. The failure and the named columns stay; the claim narrows.
     */
    public function testStorageCheckNarrowsTheClaimForATableOtherThanClients(): void
    {
        maybe_install();
        /** @var wpdb $wpdb */
        $wpdb = $GLOBALS['wpdb'];
        unset($wpdb->tables[nm_test_oauth_prefix() . 'access_tokens']['revoked']);
        $GLOBALS['nm_test_can_alter'] = false;

        $result = check_schema();

        self::assertSame('fail', $result['status']);
        self::assertStringContainsString('access_tokens (revoked)', $result['message']);
        self::assertStringContainsString('still succeeds', $result['message']);
        self::assertStringNotContainsString('No AI client can register', $result['message']);
    }

    /** An unverified schema is reported as unverified, never as installed. */
    public function testStorageCheckReportsASchemaItCouldNotVerify(): void
    {
        maybe_install();
        $GLOBALS['nm_unreadable_tables'] = [nm_test_clients_table()];

        $result = check_schema();

        self::assertSame('fail', $result['status']);
        self::assertStringContainsString('could not be verified', $result['message']);
        self::assertStringContainsString(nm_test_clients_table(), $result['message']);
        self::assertSame('', $result['copy']);
    }

    public function testStorageCheckStillReportsTablesThatWereNeverCreated(): void
    {
        $result = check_schema();

        self::assertSame('fail', $result['status']);
        self::assertStringContainsString('missing', $result['message']);
    }

    /**
     * The false positive from the field: the endpoint answered 201 for a client the store never
     * accepted, and the check called that a successful registration.
     */
    public function testRegistrationCheckFailsWhenTheReportedClientIsNotInTheStore(): void
    {
        maybe_install();
        $GLOBALS['nm_registration_response'] = $this->created(self::PROBE_CLIENT_ID);

        $result = check_registration();

        self::assertSame('fail', $result['status']);
        self::assertStringContainsString('201', $result['message']);
        self::assertStringContainsString('not in the OAuth storage', $result['message']);
        self::assertStringContainsString('OAuth storage', $result['remedy']);
        self::assertStringContainsString('remove the AI connector from your AI client and add it again', $result['remedy']);
    }

    public function testRegistrationCheckPassesWhenTheClientReadsBackAndLeavesNoClientBehind(): void
    {
        maybe_install();
        $GLOBALS['nm_registration_response'] = $this->created(self::PROBE_CLIENT_ID, store: true);

        $result = check_registration();

        self::assertSame('ok', $result['status']);
        self::assertSame([], $this->storedClients());
    }

    /**
     * The registration was written by a separate request. Where reads are answered by a replica,
     * the row can arrive a moment later, and one missed read must not be reported as a broken store.
     */
    public function testRegistrationCheckToleratesAStoreThatAnswersTheSecondRead(): void
    {
        maybe_install();
        $GLOBALS['nm_registration_response'] = $this->created(self::PROBE_CLIENT_ID, store: true);
        $GLOBALS['nm_lagging_reads'] = 1;

        $result = check_registration();

        self::assertSame('ok', $result['status']);
        self::assertSame([], $this->storedClients());
    }

    /** Whatever the verdict, the probe's client is deleted: the check leaves no client behind. */
    public function testRegistrationCheckDeletesTheProbeClientWhenItReportsAFailure(): void
    {
        maybe_install();
        $GLOBALS['nm_registration_response'] = $this->created(self::PROBE_CLIENT_ID, store: true);
        $GLOBALS['nm_lagging_reads'] = 10;

        $result = check_registration();

        self::assertSame('fail', $result['status']);
        self::assertSame([], $this->storedClients());
    }

    /** The rate-limit branch is about the probe's own address and stays a warning, not a failure. */
    public function testRegistrationCheckStillWarnsWhenTheProbeIsRateLimited(): void
    {
        maybe_install();
        $GLOBALS['nm_registration_response'] = ['code' => 429, 'body' => '{"code":"rate_limited"}'];

        $result = check_registration();

        self::assertSame('warning', $result['status']);
        self::assertSame('registration', $result['action']);
    }

    /**
     * A registration that could not be stored now answers 500, so the generic diagnosis would name
     * a cause this check has not established. The status does not establish one either: both
     * possibilities are named, neither is asserted, and the remedy says which check settles it.
     */
    public function testRegistrationCheckDoesNotAssertACauseBehindA500(): void
    {
        maybe_install();
        $GLOBALS['nm_registration_response'] = ['code' => 500, 'body' => '{"code":"server_error"}'];

        $result = check_registration();

        self::assertSame('fail', $result['status']);
        self::assertStringContainsString('500', $result['message']);
        self::assertStringContainsString('could not store the client', $result['message']);
        self::assertStringContainsString('security plugin', $result['message']);
        self::assertStringNotContainsString('is likely intercepting', $result['message']);
        self::assertStringContainsString('OAuth storage', $result['remedy']);
        self::assertStringContainsString('remove the AI connector from your AI client and add it again', $result['remedy']);
    }

    public function testRegistrationCheckStillReportsAnUnexpectedStatus(): void
    {
        maybe_install();
        $GLOBALS['nm_registration_response'] = ['code' => 403, 'body' => 'forbidden'];

        $result = check_registration();

        self::assertSame('fail', $result['status']);
        self::assertStringContainsString('403', $result['message']);
        self::assertStringContainsString('firewall', $result['message']);
    }

    /** @return array<string, mixed> */
    private function created(string $client_id, bool $store = false): array
    {
        return [
            'code' => 201,
            'body' => (string) json_encode(['client_id' => $client_id]),
            'store_client' => $store,
        ];
    }

    /** @return list<string> */
    private function schemaWrites(): array
    {
        /** @var wpdb $wpdb */
        $wpdb = $GLOBALS['wpdb'];
        return $wpdb->queries;
    }

    /** @return list<array<string, mixed>> */
    private function storedClients(): array
    {
        /** @var wpdb $wpdb */
        $wpdb = $GLOBALS['wpdb'];
        return $wpdb->rows[nm_test_clients_table()] ?? [];
    }
}
