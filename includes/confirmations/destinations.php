<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Confirmations;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Addresses a person approved for the AI's own requests. What leaves the site cannot be brought back, so a request
 * from the AI's code to an address not approved yet asks whatever the chosen level, GET included: a GET carries data
 * in its address. Approving such a call adds its addresses here; a person removes them under Settings. The option
 * starts with novamira_, so the AI's own code cannot add to it.
 */
const DESTINATIONS_OPTION = 'novamira_confirmation_destinations';

/** At most this many addresses are kept. */
const DESTINATIONS_LIMIT = 200;

/** @return list<string> */
function approved_destinations(): array
{
    /** @var mixed $stored */
    $stored = get_option(DESTINATIONS_OPTION, []);
    if (!is_array($stored)) {
        return [];
    }
    $hosts = [];
    // @mago-expect analysis:mixed-assignment -- Stored values are checked one by one.
    foreach ($stored as $host) {
        if (!is_string($host) || $host === '') {
            continue;
        }
        $hosts[] = $host;
    }
    return $hosts;
}

/**
 * A person removes approved addresses under Settings, never adds them there: the next request to one asks again.
 *
 * @param array<array-key, mixed> $hosts What the Settings form sent: only the strings count.
 */
function forget_destinations(array $hosts): void
{
    $approved = approved_destinations();
    $kept = array_values(array_filter(
        $approved,
        static fn(string $host): bool => !in_array($host, $hosts, strict: true),
    ));
    if ($kept !== $approved) {
        update_option(DESTINATIONS_OPTION, $kept, autoload: false);
    }
}

/**
 * Whether a host needs no approval: this site itself, and WordPress.org, where updates and translations come from.
 */
function own_destination(string $host): bool
{
    foreach ([home_url(), site_url()] as $url) {
        /** @var mixed $own */
        $own = wp_parse_url($url, PHP_URL_HOST);
        if (is_string($own) && strtolower($own) === $host) {
            return true;
        }
    }
    foreach (['wordpress.org', 'w.org'] as $domain) {
        if ($host === $domain || str_ends_with($host, '.' . $domain)) {
            return true;
        }
    }
    return false;
}

/**
 * The addresses the code check found that are not approved yet: hosts, and \"?\" for an address known only once the
 * code runs, which no approval can cover.
 *
 * @param array{score: int, rules: list<string>, reject: bool}|null $analysis
 * @return list<string>
 */
function new_destinations(?array $analysis): array
{
    $approved = approved_destinations();
    $new = [];
    foreach ($analysis['rules'] ?? [] as $rule) {
        if (!str_starts_with($rule, 'send:')) {
            continue;
        }
        $host = substr($rule, offset: 5);
        if ($host !== '?' && (own_destination($host) || in_array($host, $approved, strict: true))) {
            continue;
        }
        $new[] = $host;
    }
    return array_values(array_unique($new));
}

/** @param array{score: int, rules: list<string>, reject: bool}|null $analysis */
function sends_to_new_destination(?array $analysis): bool
{
    return new_destinations($analysis) !== [];
}

/**
 * What a request to an address means to the person approving: nothing once approved.
 */
function destination_words(string $host): string
{
    if ($host === '?') {
        return 'Sends data to an address known only once it runs';
    }
    if (own_destination($host) || in_array($host, approved_destinations(), strict: true)) {
        return '';
    }
    return sprintf('Sends data to %s, an address not approved yet: approving allows it from now on', $host);
}

/**
 * A person approved a call: the addresses it sends to are approved from now on. An address known only once the code
 * runs is not: there is nothing to remember.
 *
 * @param array<array-key, mixed> $parameters
 */
function remember_destinations_of(string $ability, array $parameters): void
{
    $analysis = ability_check(call_policy($ability, $parameters), [
        'ability_name' => $ability,
        'parameters' => $parameters,
    ]);
    $hosts = array_values(array_filter(new_destinations($analysis), static fn(string $host): bool => $host !== '?'));
    if ($hosts === []) {
        return;
    }
    $all = array_values(array_unique([...approved_destinations(), ...$hosts]));
    update_option(DESTINATIONS_OPTION, array_slice($all, offset: -DESTINATIONS_LIMIT), autoload: false);
}
