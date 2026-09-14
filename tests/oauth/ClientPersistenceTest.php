<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Novamira\OAuth\Repositories\ClientRepository;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Dynamic client registration must not report a client it did not store.
 *
 * A clients table that refuses the insert — the shape a host leaves behind when the migration that
 * added a column never applied — used to produce HTTP 201 with a brand-new client_id that existed
 * nowhere, so the client's very next authorization request was refused as an unknown client_id
 * while the site reported nothing wrong.
 *
 * Stubs and the wpdb double live in fixtures/client-store-stubs.php, loaded at runtime in setUp()
 * so PHPUnit discovery never declares them in the parent process.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ClientPersistenceTest extends TestCase
{
    private const CLIENT_IP = '203.0.113.5';

    protected function setUp(): void
    {
        require_once __DIR__ . '/fixtures/client-store-stubs.php';
        require_once __DIR__ . '/../../includes/oauth/bootstrap.php';
        require_once __DIR__ . '/../../includes/oauth/schema.php';
        require_once __DIR__ . '/../../includes/oauth/repositories/client-repository.php';
        require_once __DIR__ . '/../../includes/oauth/endpoints/register.php';
        nm_test_reset_state();
        $_SERVER['REMOTE_ADDR'] = self::CLIENT_IP;
    }

    public function testAuthorizationCodeRegistrationRefusesWhenTheClientCannotBeStored(): void
    {
        $this->installSchemaWithoutClientGrantTypes();

        $result = \Novamira\OAuth\Endpoints\Register\handle($this->request([
            'client_name' => 'Refused Client',
            'redirect_uris' => ['claude://callback'],
        ]));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame(500, $result->get_error_data()['status']);
        self::assertSame([], $this->storedClients());
        self::assertStringContainsString('failed to store client', nm_test_error_log());
        // One repair was attempted and refused, and the insert was retried exactly once.
        self::assertCount(1, $this->alterStatements());
        self::assertCount(2, $this->insertAttempts());
    }

    public function testDeviceRegistrationRefusesWhenTheClientCannotBeStored(): void
    {
        $this->installSchemaWithoutClientGrantTypes();

        $result = \Novamira\OAuth\Endpoints\Register\handle($this->request([
            'client_name' => 'Refused Device',
            'grant_types' => [\Novamira\OAuth\DEVICE_CODE_GRANT_TYPE],
        ]));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame(500, $result->get_error_data()['status']);
        self::assertSame([], $this->storedClients());
        self::assertCount(1, $this->alterStatements());
        self::assertCount(2, $this->insertAttempts());
    }

    /**
     * The site in the field: the upgrade recorded its version although its ADD COLUMN was denied, and
     * the host has since granted ALTER. The registration that fails for want of the column repairs
     * the table once and retries once — and succeeds, with no one having to do anything else.
     */
    public function testRegistrationRepairsTheClientStoreOnceAndRetries(): void
    {
        $this->installSchemaWithoutClientGrantTypes();
        $GLOBALS['nm_test_can_alter'] = true;

        $result = \Novamira\OAuth\Endpoints\Register\handle($this->request([
            'client_name' => 'Repaired Client',
            'redirect_uris' => ['claude://callback'],
        ]));

        self::assertInstanceOf(WP_REST_Response::class, $result);
        self::assertSame(201, $result->get_status());
        /** @var array<string, mixed> $data */
        $data = $result->get_data();
        self::assertIsString($data['client_id']);
        self::assertNotNull((new ClientRepository())->getClientEntity($data['client_id']));
        self::assertArrayHasKey('grant_types', nm_test_columns(nm_test_clients_table()));
        self::assertCount(1, $this->alterStatements());
        self::assertCount(2, $this->insertAttempts());
    }

    public function testDeviceRegistrationRepairsTheClientStoreOnceAndRetries(): void
    {
        $this->installSchemaWithoutClientGrantTypes();
        $GLOBALS['nm_test_can_alter'] = true;

        $result = \Novamira\OAuth\Endpoints\Register\handle($this->request([
            'client_name' => 'Repaired Device',
            'grant_types' => [\Novamira\OAuth\DEVICE_CODE_GRANT_TYPE],
        ]));

        self::assertInstanceOf(WP_REST_Response::class, $result);
        self::assertSame(201, $result->get_status());
        /** @var array<string, mixed> $data */
        $data = $result->get_data();
        self::assertIsString($data['client_id']);
        self::assertTrue(
            (new ClientRepository())->supports_grant($data['client_id'], \Novamira\OAuth\DEVICE_CODE_GRANT_TYPE),
        );
        self::assertCount(2, $this->insertAttempts());
    }

    /**
     * wpdb::insert() reports rows affected, so a write that did not land can come back as 0 rather
     * than as false. Both mean the same thing here: there is no client to answer with.
     */
    public function testRegistrationRefusesWhenTheInsertReportsNoRows(): void
    {
        \Novamira\OAuth\Schema\maybe_install();
        nm_test_forget_schema_activity();
        $GLOBALS['nm_insert_result'] = 0;

        $result = \Novamira\OAuth\Endpoints\Register\handle($this->request([
            'client_name' => 'Refused Client',
            'redirect_uris' => ['claude://callback'],
        ]));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame(500, $result->get_error_data()['status']);
        self::assertSame([], $this->storedClients());
        self::assertStringContainsString('failed to store client', nm_test_error_log());
        // Nothing was missing, so there was nothing to repair and nothing to retry.
        self::assertSame([], $this->alterStatements());
        self::assertCount(1, $this->insertAttempts());
    }

    public function testSuccessfulRegistrationStillAnswers201WithAClientThatReadsBack(): void
    {
        \Novamira\OAuth\Schema\maybe_install();

        $result = \Novamira\OAuth\Endpoints\Register\handle($this->request([
            'client_name' => 'Stored Client',
            'redirect_uris' => ['claude://callback'],
        ]));

        self::assertInstanceOf(WP_REST_Response::class, $result);
        self::assertSame(201, $result->get_status());
        /** @var array<string, mixed> $data */
        $data = $result->get_data();
        $client_id = $data['client_id'];
        self::assertIsString($client_id);

        $entity = (new ClientRepository())->getClientEntity($client_id);
        self::assertNotNull($entity);
        self::assertSame($client_id, $entity->getIdentifier());
        self::assertSame(['claude://callback'], $entity->getRedirectUri());
    }

    public function testSuccessfulDeviceRegistrationStillAnswers201WithAClientThatReadsBack(): void
    {
        \Novamira\OAuth\Schema\maybe_install();

        $result = \Novamira\OAuth\Endpoints\Register\handle($this->request([
            'client_name' => 'Stored Device',
            'grant_types' => [\Novamira\OAuth\DEVICE_CODE_GRANT_TYPE],
        ]));

        self::assertInstanceOf(WP_REST_Response::class, $result);
        self::assertSame(201, $result->get_status());
        /** @var array<string, mixed> $data */
        $data = $result->get_data();
        $client_id = $data['client_id'];
        self::assertIsString($client_id);

        $clients = new ClientRepository();
        self::assertNotNull($clients->getClientEntity($client_id));
        self::assertTrue($clients->supports_grant($client_id, \Novamira\OAuth\DEVICE_CODE_GRANT_TYPE));
    }

    /**
     * A row written before the grant_types column existed carries no list, and must keep the grants
     * every client had then: the verified write must not invalidate a live connection.
     */
    public function testLegacyClientRowWithoutGrantTypesKeepsTheGrantsItRegisteredWith(): void
    {
        \Novamira\OAuth\Schema\maybe_install();
        /** @var wpdb $wpdb */
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->rows[nm_test_clients_table()][] = [
            'client_id' => 'legacyclient0000000000000000000a',
            'client_name' => 'Legacy Client',
            'redirect_uris' => (string) json_encode(['claude://callback']),
            'is_confidential' => 0,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'registered_by_ip_hash' => hash('sha256', self::CLIENT_IP),
            'admin_created' => 0,
            'grant_types' => '',
        ];

        $clients = new ClientRepository();
        self::assertTrue($clients->supports_grant('legacyclient0000000000000000000a', 'authorization_code'));
        self::assertTrue($clients->supports_grant('legacyclient0000000000000000000a', 'refresh_token'));
        self::assertFalse(
            $clients->supports_grant('legacyclient0000000000000000000a', \Novamira\OAuth\DEVICE_CODE_GRANT_TYPE),
        );
        self::assertNotNull($clients->getClientEntity('legacyclient0000000000000000000a'));
    }

    /** @param array<string, mixed> $body */
    private function request(array $body): WP_REST_Request
    {
        return new WP_REST_Request($body);
    }

    /**
     * The site the defect reports come from: a clients table an earlier version created, which the
     * installer cannot complete because the database user may not ALTER it.
     */
    private function installSchemaWithoutClientGrantTypes(): void
    {
        nm_test_seed_incomplete_clients_table(['grant_types']);
        \Novamira\OAuth\Schema\maybe_install();
        self::assertArrayNotHasKey('grant_types', nm_test_columns(nm_test_clients_table()));
        self::assertSame(
            \Novamira\OAuth\Schema\CURRENT_SCHEMA_VERSION,
            get_option(\Novamira\OAuth\Schema\SCHEMA_VERSION_OPTION),
        );
        nm_test_forget_schema_activity();
    }

    /** @return list<string> */
    private function alterStatements(): array
    {
        /** @var wpdb $wpdb */
        $wpdb = $GLOBALS['wpdb'];
        return array_values(array_filter($wpdb->queries, static fn(string $q): bool => str_starts_with($q, 'ALTER TABLE')));
    }

    /** @return list<string> */
    private function insertAttempts(): array
    {
        /** @var wpdb $wpdb */
        $wpdb = $GLOBALS['wpdb'];
        return array_values(array_filter($wpdb->inserts, static fn(string $t): bool => $t === nm_test_clients_table()));
    }

    /** @return list<array<string, mixed>> */
    private function storedClients(): array
    {
        /** @var wpdb $wpdb */
        $wpdb = $GLOBALS['wpdb'];
        return $wpdb->rows[nm_test_clients_table()] ?? [];
    }
}
