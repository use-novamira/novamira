<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\OAuth\Repositories;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\Traits\ClientTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;

if (!defined('ABSPATH')) {
    exit();
}

// @mago-expect lint:single-class-per-file
// ClientEntity and ClientRepository are tightly coupled and intentionally in the same file.
final class ClientEntity implements ClientEntityInterface
{
    use ClientTrait;
    use EntityTrait;

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    /** @param list<string> $uris */
    public function setRedirectUri(array $uris): void
    {
        $this->redirectUri = $uris;
    }

    public function setIsConfidential(bool $v): void
    {
        $this->isConfidential = $v;
    }
}

final class ClientRepository implements ClientRepositoryInterface
{
    /** The grants a client registered before Novamira recorded them, and the default for new ones. */
    public const DEFAULT_GRANT_TYPES = ['authorization_code', 'refresh_token'];

    /**
     * Registers a client and returns its identifier, or null when the row was not written.
     *
     * The caller must treat null as a failed registration: handing back an identifier the table
     * never accepted leaves the client holding a client_id that no authorization request can
     * resolve, and nothing on the site to show why. The database error is logged because only the
     * server can see it — a clients table whose migration never applied its newest column refuses
     * every insert here while the reads that do not name that column keep answering.
     *
     * @param list<string> $redirect_uris
     * @param list<string>|null $grant_types
     */
    // @mago-expect lint:no-boolean-flag-parameter
    public function create(
        string $client_name,
        array $redirect_uris,
        string $registered_by_ip,
        bool $admin_created = false,
        ?array $grant_types = null,
    ): ?string {
        // @mago-expect lint:no-global
        global $wpdb;
        /** @var \wpdb $wpdb */
        $client_id = bin2hex(random_bytes(16));
        $inserted = $wpdb->insert($wpdb->prefix . 'novamira_oauth_clients', [
            'client_id' => $client_id,
            'client_name' => $client_name,
            'redirect_uris' => wp_json_encode($redirect_uris),
            'is_confidential' => 0,
            'client_secret_hash' => null,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'last_used_at' => null,
            'registered_by_ip_hash' => hash('sha256', $registered_by_ip),
            'admin_created' => $admin_created ? 1 : 0,
            'grant_types' => (string) wp_json_encode($grant_types ?? self::DEFAULT_GRANT_TYPES),
        ]);
        if ($inserted !== 1) {
            error_log(sprintf('Novamira OAuth: failed to store client %s. %s', $client_id, $wpdb->last_error));
            return null;
        }
        return $client_id;
    }

    /**
     * Whether this client registered for a grant. Rows written before the column existed carry no
     * list and keep the grants every client had then, so an upgrade never invalidates a live
     * connection.
     */
    public function supports_grant(string $client_id, string $grant_type): bool
    {
        // @mago-expect lint:no-global
        global $wpdb;
        /** @var \wpdb $wpdb */
        // @mago-expect analysis:possibly-invalid-argument
        $sql = $wpdb->prepare(
            "SELECT grant_types FROM {$wpdb->prefix}novamira_oauth_clients WHERE client_id = %s",
            $client_id,
        );
        if (!is_string($sql)) {
            return false;
        }
        $raw = $wpdb->get_var($sql);
        if (!is_string($raw) || $raw === '') {
            return in_array($grant_type, self::DEFAULT_GRANT_TYPES, strict: true);
        }
        // @mago-expect analysis:mixed-assignment
        $decoded = json_decode($raw, associative: true);
        if (!is_array($decoded)) {
            return in_array($grant_type, self::DEFAULT_GRANT_TYPES, strict: true);
        }
        return in_array($grant_type, $decoded, strict: true);
    }

    public function getClientEntity(mixed $clientIdentifier): ?ClientEntityInterface
    {
        // League's 8.5 ClientRepositoryInterface declares this parameter untyped, so the
        // signature must widen to mixed; narrow back to string for the query below.
        $clientIdentifier = (string) $clientIdentifier;
        // @mago-expect lint:no-global
        global $wpdb;
        /** @var \wpdb $wpdb */
        // @mago-expect analysis:possibly-invalid-argument
        // @mago-expect analysis:non-existent-constant
        // @mago-expect analysis:mixed-argument
        // @mago-expect analysis:possibly-invalid-argument
        $row = $wpdb->get_row($wpdb->prepare("SELECT client_id, client_name, redirect_uris, is_confidential
                 FROM {$wpdb->prefix}novamira_oauth_clients
                 WHERE client_id = %s", $clientIdentifier), ARRAY_A);
        if ($row === null) {
            return null;
        }
        $entity = new ClientEntity();
        // @mago-expect analysis:invalid-array-access
        $entity->setIdentifier($row['client_id']);
        // @mago-expect analysis:invalid-array-access
        // @mago-expect analysis:mixed-argument
        $entity->setName($row['client_name']);
        /** @var list<string> $uris */
        // @mago-expect analysis:invalid-array-access
        // @mago-expect analysis:mixed-argument
        $uris = json_decode($row['redirect_uris'], associative: true);
        $entity->setRedirectUri($uris);
        // @mago-expect analysis:invalid-array-access
        // @mago-expect analysis:mixed-operand
        $entity->setIsConfidential((bool) $row['is_confidential']);
        return $entity;
    }

    public function validateClient(mixed $clientIdentifier, mixed $clientSecret, mixed $grantType): bool
    {
        return $this->getClientEntity($clientIdentifier) !== null;
    }

    public function touchLastUsed(string $client_id): void
    {
        // @mago-expect lint:no-global
        global $wpdb;
        /** @var \wpdb $wpdb */
        $wpdb->update(
            $wpdb->prefix . 'novamira_oauth_clients',
            ['last_used_at' => gmdate('Y-m-d H:i:s')],
            ['client_id' => $client_id],
        );
    }

    public function revoke(string $client_id): void
    {
        // @mago-expect lint:no-global
        global $wpdb;
        /** @var \wpdb $wpdb */
        $wpdb->delete($wpdb->prefix . 'novamira_oauth_clients', ['client_id' => $client_id]);
    }

    /**
     * Drop a client that no user is connected through any more, so revoking a connection frees its
     * slot immediately instead of leaving the row to occupy one until it ages out. The live-token
     * test spans every user, since a revoke only covers the user who asked for it and the same
     * client_id may still carry another user's connection. Manually created IDs are exempt: they
     * are deleted from Connections by hand, the same way prune_dead_clients() leaves them alone.
     */
    // @mago-expect lint:no-global
    // WordPress core requires global $wpdb for database access.
    public function delete_if_unused(string $client_id): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $t = $wpdb->prefix . 'novamira_oauth_access_tokens';
        $r = $wpdb->prefix . 'novamira_oauth_refresh_tokens';
        // @mago-expect analysis:possibly-invalid-argument
        // @mago-expect analysis:possibly-invalid-argument
        $live = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
               FROM `{$r}` rt
               JOIN `{$t}` at ON at.identifier_hash = rt.access_token_hash
              WHERE at.client_id = %s AND rt.revoked = 0 AND rt.expires_at > %s",
            $client_id,
            gmdate('Y-m-d H:i:s'),
        ));
        if ($live > 0) {
            return;
        }
        $wpdb->delete($wpdb->prefix . 'novamira_oauth_clients', [
            'client_id' => $client_id,
            'admin_created' => 0,
        ]);
    }

    /**
     * @return list<array{client_id: string, client_name: string, created_at: string, last_used_at: string|null}>
     */
    public function list_recent(int $limit = 50): array
    {
        // @mago-expect lint:no-global
        global $wpdb;
        /** @var \wpdb $wpdb */
        // @mago-expect analysis:possibly-invalid-argument
        // @mago-expect analysis:non-existent-constant
        // @mago-expect analysis:mixed-argument
        // @mago-expect analysis:possibly-invalid-argument
        $rows = $wpdb->get_results($wpdb->prepare("SELECT client_id, client_name, created_at, last_used_at
                 FROM {$wpdb->prefix}novamira_oauth_clients
                 ORDER BY created_at DESC LIMIT %d", $limit), ARRAY_A);
        // @mago-expect analysis:invalid-return-statement
        return $rows === null ? [] : $rows;
    }

    /**
     * Most recent admin-created client for this client_name, so regenerating from the
     * troubleshooter reuses the row instead of stacking new ones.
     */
    public function find_admin_client_id(string $client_name): ?string
    {
        // @mago-expect lint:no-global
        global $wpdb;
        /** @var \wpdb $wpdb */
        // @mago-expect analysis:possibly-invalid-argument
        // @mago-expect analysis:mixed-argument
        // @mago-expect analysis:possibly-invalid-argument
        $id = $wpdb->get_var($wpdb->prepare("SELECT client_id FROM {$wpdb->prefix}novamira_oauth_clients
                 WHERE admin_created = 1 AND client_name = %s
                 ORDER BY created_at DESC LIMIT 1", $client_name));
        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * @return list<array{client_id: string, client_name: string, created_at: string, last_used_at: string|null}>
     */
    public function list_admin_clients(): array
    {
        // @mago-expect lint:no-global
        global $wpdb;
        /** @var \wpdb $wpdb */
        // @mago-expect analysis:non-existent-constant
        $rows = $wpdb->get_results("SELECT client_id, client_name, created_at, last_used_at
                 FROM {$wpdb->prefix}novamira_oauth_clients
                 WHERE admin_created = 1
                 ORDER BY created_at DESC", ARRAY_A);
        // @mago-expect analysis:invalid-return-statement
        return $rows === null ? [] : $rows;
    }
}
