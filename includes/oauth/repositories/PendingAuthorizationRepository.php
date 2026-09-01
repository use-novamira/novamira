<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\OAuth\Repositories;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Database-backed storage for the short-lived state between the authorization request and the
 * browser consent page.
 *
 * This state deliberately does not use WordPress transients. With an external object cache,
 * transients may be written to a different cache node from the next wp-admin request and disappear
 * immediately. The OAuth tables are already the durable source of truth for the rest of the flow.
 */
final class PendingAuthorizationRepository
{
    public const TTL = 600;

    /**
     * @param array{
     *     client_id: string,
     *     redirect_uri: string,
     *     code_challenge: string,
     *     code_challenge_method: string,
     *     scope: string,
     *     state: string,
     *     user_id: int,
     * } $pending
     */
    public function create(string $token, array $pending): bool
    {
        // @mago-expect lint:no-global
        global $wpdb;
        /** @var \wpdb $wpdb */
        $inserted = $wpdb->insert($wpdb->prefix . 'novamira_oauth_pending_authorizations', [
            'token_hash' => hash('sha256', $token),
            'client_id' => $pending['client_id'],
            'redirect_uri' => $pending['redirect_uri'],
            'code_challenge' => $pending['code_challenge'],
            'code_challenge_method' => $pending['code_challenge_method'],
            'scope' => $pending['scope'],
            'state' => $pending['state'],
            'user_id' => $pending['user_id'],
            'expires_at' => gmdate('Y-m-d H:i:s', time() + self::TTL),
        ]);
        return $inserted === 1;
    }

    /**
     * @return array{
     *     client_id: string,
     *     redirect_uri: string,
     *     code_challenge: string,
     *     code_challenge_method: string,
     *     scope: string,
     *     state: string,
     *     user_id: int,
     *     expires_at: string,
     * }|null
     */
    public function find(string $token): ?array
    {
        // @mago-expect lint:no-global
        global $wpdb;
        /** @var \wpdb $wpdb */
        $table = $wpdb->prefix . 'novamira_oauth_pending_authorizations';
        // @mago-expect analysis:possibly-invalid-argument
        $sql = $wpdb->prepare(
            "SELECT client_id, redirect_uri, code_challenge, code_challenge_method, scope, state, user_id, expires_at
             FROM `{$table}` WHERE token_hash = %s",
            hash('sha256', $token),
        );
        if (!is_string($sql)) {
            return null;
        }
        // @mago-expect analysis:non-existent-constant
        // @mago-expect analysis:mixed-argument
        $row = $wpdb->get_row($sql, ARRAY_A);
        if (!is_array($row)) {
            return null;
        }
        return [
            'client_id' => (string) $row['client_id'],
            'redirect_uri' => (string) $row['redirect_uri'],
            'code_challenge' => (string) $row['code_challenge'],
            'code_challenge_method' => (string) $row['code_challenge_method'],
            'scope' => (string) $row['scope'],
            'state' => (string) $row['state'],
            'user_id' => (int) $row['user_id'],
            'expires_at' => (string) $row['expires_at'],
        ];
    }

    public function is_expired(string $expires_at): bool
    {
        $deadline = strtotime($expires_at . ' UTC');
        return $deadline === false || $deadline <= time();
    }

    /** Atomically consume a pending authorization so two consent submissions cannot approve it. */
    public function consume(string $token): bool
    {
        // @mago-expect lint:no-global
        global $wpdb;
        /** @var \wpdb $wpdb */
        $deleted = $wpdb->delete($wpdb->prefix . 'novamira_oauth_pending_authorizations', ['token_hash' => hash(
            'sha256',
            $token,
        )]);
        return $deleted === 1;
    }

    public function delete(string $token): void
    {
        // @mago-expect lint:no-global
        global $wpdb;
        /** @var \wpdb $wpdb */
        $wpdb->delete($wpdb->prefix . 'novamira_oauth_pending_authorizations', ['token_hash' => hash(
            'sha256',
            $token,
        )]);
    }
}
