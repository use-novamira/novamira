<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\OAuth\ClientValidation;

if (!defined('ABSPATH') && PHP_SAPI !== 'cli') {
    exit();
}

const ALLOWED_SCHEMES = ['https', 'claude', 'cursor', 'ms-onboarding-claude-code', 'chatgpt'];

const DCR_RATE_LIMIT_PER_HOUR = 10;

const MAX_CLIENTS_PER_SITE = 50;

/**
 * Connection-slot cap actually enforced. MAX_CLIENTS_PER_SITE guards the anonymous registration
 * endpoint against floods and is generous for real use (a slot is a connection active within the
 * refresh-token lifetime); sites that legitimately run more simultaneous AI connections can raise
 * it with the `novamira_oauth_max_clients` filter.
 */
function max_clients_per_site(): int
{
    // @mago-expect analysis:mixed-assignment
    $cap = apply_filters('novamira_oauth_max_clients', MAX_CLIENTS_PER_SITE);
    return is_int($cap) && $cap > 0 ? $cap : MAX_CLIENTS_PER_SITE;
}

const MAX_CLIENTS_PER_IP = 10;

const STALE_UNUSED_CLIENT_TTL = 86_400;

// A client counts as an active connection only while it has been used within the refresh-token
// lifetime (14 days, matching P14D in server-factory.php). Past that its grant has expired, so it is
// pruned and frees its slot instead of occupying it forever.
const ACTIVE_CLIENT_TTL = 14 * 86_400;

const MAX_REDIRECT_URI_LENGTH = 2048;

/**
 * Returns true if a redirect_uri may be registered.
 * Rejects schemes not in ALLOWED_SCHEMES and https URIs whose host resolves
 * to a blocked IP range (RFC 1918, loopback, link-local, reserved).
 * Loopback is allowed when $dev_mode is true (e.g. WP_DEBUG = true).
 */
// @mago-expect lint:no-boolean-flag-parameter
function is_allowed_redirect_uri(string $uri, bool $dev_mode = false): bool
{
    // RFC 6749 §3.1.2: the redirection endpoint URI MUST NOT include a fragment component. A literal
    // '#' is always the fragment delimiter (a '#' inside a path or query would be percent-encoded).
    if (str_contains($uri, '#')) {
        return false;
    }
    $parsed = parse_url($uri);
    // parse_url always returns an array. isset() is used here to check key existence.
    // @mago-expect lint:no-isset
    if (!is_array($parsed) || !isset($parsed['scheme'])) {
        return false;
    }
    $scheme = strtolower($parsed['scheme']);
    // Allow http only for loopback addresses — standard for native apps (RFC 8252).
    if ($scheme === 'http') {
        $host = normalize_uri_host($parsed);
        return $host === 'localhost' || is_loopback_ip($host);
    }
    if (!in_array($scheme, ALLOWED_SCHEMES, strict: true)) {
        return false;
    }
    // Non-https custom schemes (claude://, cursor://) do not have a resolvable host.
    if ($scheme !== 'https') {
        return true;
    }
    $host = normalize_uri_host($parsed);
    if ($host === '') {
        return false;
    }
    // Raw IP literal in host — check blocked ranges without DNS.
    $raw_ip = filter_var($host, FILTER_VALIDATE_IP);
    if ($raw_ip !== false) {
        return !is_blocked_ip($raw_ip, $dev_mode);
    }
    // Block loopback/mDNS hostnames before DNS.
    if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
        return $dev_mode;
    }
    // Resolve hostname and reject if any A/AAAA answer maps to a blocked range.
    // SSRF is not a concern here: redirect_uris are used only in 302 responses
    // to the user's browser — our server never fetches them. The DNS check
    // prevents redirect_uris with private-IP hostnames (e.g. "internal.corp").
    $resolved_ips = resolve_host_ips($host);
    if ($resolved_ips === null) {
        return false;
    }
    foreach ($resolved_ips as $ip) {
        if (is_blocked_ip($ip, $dev_mode)) {
            return false;
        }
    }
    return true;
}

/** @param array<array-key, mixed> $parsed */
function normalize_uri_host(array $parsed): string
{
    $host = strtolower((string) ($parsed['host'] ?? ''));
    if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
        $inner = substr($host, offset: 1, length: -1);
        if (filter_var($inner, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return $inner;
        }
    }
    return $host;
}

/** @return list<string>|null */
function resolve_host_ips(string $host): ?array
{
    $ips = [];

    $ipv4 = gethostbynamel($host);
    if (is_array($ipv4)) {
        foreach ($ipv4 as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                continue;
            }
            $ips[] = $ip;
        }
    }

    if (function_exists('dns_get_record')) {
        /** @var array<int, array{ipv6?: string}>|false $records */
        $records = dns_get_record($host, DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                $ip = $record['ipv6'] ?? null;
                if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
                    $ips[] = $ip;
                }
            }
        }
    }

    $ips = array_values(array_unique($ips));
    return $ips === [] ? null : $ips;
}

// @mago-expect lint:no-boolean-flag-parameter
// The logic is necessary to detect all blocked IP ranges (RFC 1918, link-local, loopback).
// Splitting into smaller functions would obscure the IP validation intent.
function is_blocked_ip(string $ip, bool $dev_mode): bool
{
    $ip = normalize_ip_literal($ip);
    if ($ip === '') {
        return true;
    }

    if ($dev_mode && is_loopback_ip($ip)) {
        return false;
    }

    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

function normalize_ip_literal(string $ip): string
{
    $ip = strtolower(trim($ip));
    if (str_starts_with($ip, '[') && str_ends_with($ip, ']')) {
        $ip = substr($ip, offset: 1, length: -1);
    }
    return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '';
}

function is_loopback_ip(string $ip): bool
{
    $ip = normalize_ip_literal($ip);
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        return str_starts_with($ip, '127.');
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
        return $ip === '::1';
    }
    return false;
}

const RATE_LIMIT_EPOCH_OPTION = 'novamira_oauth_dcr_epoch';

/**
 * Counter generation, part of every DCR transient key. Bumping it retires every per-IP counter at
 * once, which is how the reset clears the hourly limits: the keys cannot be enumerated (with a
 * persistent object cache they are not rows in wp_options at all), so the alternative would be
 * flushing the whole site cache to release one counter. The retired transients expire on their own.
 */
function rate_limit_epoch(): int
{
    return (int) get_option(RATE_LIMIT_EPOCH_OPTION, default_value: 0);
}

/**
 * Increments a per-IP DCR counter (SHA-256 of IP, stored in WP transient).
 * Returns false if the hourly cap is already reached.
 */
function check_and_increment_rate_limit(string $client_ip): bool
{
    $key = 'novamira_oauth_dcr_' . rate_limit_epoch() . '_' . hash('sha256', $client_ip);
    $current = (int) get_transient($key);
    if ($current >= DCR_RATE_LIMIT_PER_HOUR) {
        return false;
    }
    set_transient($key, $current + 1, HOUR_IN_SECONDS);
    return true;
}

/**
 * Clear the registration limits an administrator can be locked out by: the hourly per-IP budget and
 * the registrations that never completed sign-in, which occupy the per-IP client allowance until
 * they age out. Live connections are left alone, so this is safe to run while clients are connected.
 * Returns how many incomplete registrations were removed.
 */
// @mago-expect lint:no-global
// WordPress core requires global $wpdb for database access.
function reset_registration_limits(): int
{
    update_option(RATE_LIMIT_EPOCH_OPTION, rate_limit_epoch() + 1, autoload: true);

    global $wpdb;
    /** @var \wpdb $wpdb */
    $deleted = $wpdb->query("DELETE FROM {$wpdb->prefix}novamira_oauth_clients
         WHERE last_used_at IS NULL AND admin_created = 0");
    return is_int($deleted) ? $deleted : 0;
}

const ENDPOINT_RATE_LIMIT_PER_MINUTE = 30;

/**
 * Fixed-window per-IP throttle for the unauthenticated token and revoke endpoints, so a cheap flood
 * cannot tie PHP up on the deliberately expensive token crypto. Returns false once the per-minute
 * cap for this bucket + IP is reached. An empty IP (a proxy stripping REMOTE_ADDR) is not throttled,
 * since the request cannot be attributed to a source.
 */
function within_endpoint_rate_limit(string $bucket, string $client_ip): bool
{
    if ($client_ip === '') {
        return true;
    }
    $key = 'novamira_oauth_rl_' . $bucket . '_' . hash('sha256', $client_ip);
    $now = time();
    // @mago-expect analysis:mixed-assignment
    $window = get_transient($key);
    if (
        !is_array($window)
        || !is_int($window['started_at'] ?? null)
        || !is_int($window['count'] ?? null)
        || $window['started_at'] > $now
        || ($now - $window['started_at']) >= MINUTE_IN_SECONDS
    ) {
        $window = ['started_at' => $now, 'count' => 0];
    }
    if ($window['count'] >= ENDPOINT_RATE_LIMIT_PER_MINUTE) {
        return false;
    }
    // Keep the expiry anchored to the first request, not extended by every poll.
    set_transient(
        $key,
        ['started_at' => $window['started_at'], 'count' => $window['count'] + 1],
        max(1, MINUTE_IN_SECONDS - ($now - $window['started_at'])),
    );
    return true;
}

/**
 * Number of active connections: clients that completed a token exchange within the refresh-token
 * lifetime. `last_used_at` is only set after the admin-approved authorize/consent flow, so an
 * anonymous registration flood — which never reaches a token exchange — cannot inflate this count.
 */
// @mago-expect lint:no-global
// WordPress core requires global $wpdb for database access.
function active_client_count(): int
{
    global $wpdb;
    /** @var \wpdb $wpdb */
    $cutoff = gmdate('Y-m-d H:i:s', time() - ACTIVE_CLIENT_TTL);
    // @mago-expect analysis:possibly-invalid-argument
    $sql = $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}novamira_oauth_clients
         WHERE last_used_at IS NOT NULL AND last_used_at > %s", $cutoff);
    return is_string($sql) ? (int) $wpdb->get_var($sql) : 0;
}

// @mago-expect lint:no-global
// WordPress core requires global $wpdb for database access.
function client_count_for_ip(string $client_ip): int
{
    global $wpdb;
    /** @var \wpdb $wpdb */
    // @mago-expect analysis:possibly-invalid-argument
    $sql = $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}novamira_oauth_clients WHERE registered_by_ip_hash = %s",
        hash('sha256', $client_ip),
    );
    return is_string($sql) ? (int) $wpdb->get_var($sql) : 0;
}

/**
 * Delete clients that no longer hold a live grant so they stop occupying connection slots: pending
 * registrations that never completed a token exchange (older than STALE_UNUSED_CLIENT_TTL, except
 * admin-created client IDs, which stay until used or deleted from Connections), and clients not
 * used within the refresh-token lifetime (their tokens have all expired or been revoked).
 */
// @mago-expect lint:no-global
// WordPress core requires global $wpdb for database access.
function prune_dead_clients(): void
{
    global $wpdb;
    /** @var \wpdb $wpdb */
    $pending_cutoff = gmdate('Y-m-d H:i:s', time() - STALE_UNUSED_CLIENT_TTL);
    $used_cutoff = gmdate('Y-m-d H:i:s', time() - ACTIVE_CLIENT_TTL);
    // @mago-expect analysis:possibly-invalid-argument
    $sql = $wpdb->prepare(
        "DELETE FROM {$wpdb->prefix}novamira_oauth_clients
         WHERE (last_used_at IS NULL AND created_at < %s AND admin_created = 0)
            OR (last_used_at IS NOT NULL AND last_used_at < %s)",
        $pending_cutoff,
        $used_cutoff,
    );
    if (is_string($sql)) {
        $wpdb->query($sql);
    }
}
