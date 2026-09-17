<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Exercises the OAuth discovery cache invalidation under a minimal WordPress emulation and prints
 * what happened, as JSON.
 *
 * A separate process per scenario is what makes the "no cache plugin is installed" case meaningful:
 * the purge APIs are global functions, actions and classes, so whether they exist has to be decided
 * before anything loads and cannot be undone inside a shared PHPUnit process.
 *
 * The emulation models the parts of WordPress this code depends on: `did_action()` counts,
 * `has_action()` answers for a named callback, and the option hooks fire the way core fires them,
 * that is only when the stored value actually changed.
 *
 * Scenarios (second argument):
 * - all-caches:          every supported cache plugin present; one effective option change.
 * - no-caches:           none of them present; one effective option change.
 * - unrelated-option:    a write to an option that is none of ours.
 * - multiple-writes:     the disable path, which stores one option and deletes the other.
 * - early-write:         the option changes before `plugins_loaded`, when caches have not hooked yet.
 * - discovery-request:   a request for a discovery path, with abilities off (nothing serves it).
 * - subdirectory-request: the same, on an install living under a path.
 * - unrelated-request:   a request for another path.
 *
 * Usage: php cache-purge-runner.php <plugin-root> <scenario>
 */

namespace {
    const NOVAMIRA_TEST_SCENARIOS = [
        'all-caches',
        'no-caches',
        'unrelated-option',
        'multiple-writes',
        'early-write',
        'discovery-request',
        'subdirectory-request',
        'unrelated-request',
    ];

    if ($argc !== 3 || !in_array($argv[2], NOVAMIRA_TEST_SCENARIOS, strict: true)) {
        fwrite(
            STDERR,
            'Usage: php cache-purge-runner.php <plugin-root> <' . implode('|', NOVAMIRA_TEST_SCENARIOS) . ">\n",
        );
        exit(2);
    }

    define('ABSPATH', '/');

    $GLOBALS['novamira_hooks'] = [];
    $GLOBALS['novamira_did'] = [];
    $GLOBALS['novamira_options'] = [];
    $GLOBALS['novamira_purge_calls'] = [];
    $GLOBALS['novamira_headers'] = [];
    $GLOBALS['novamira_home_url'] = 'https://example.test';

    function add_action(string $hook, callable|string $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        $GLOBALS['novamira_hooks'][$hook][] = ['priority' => $priority, 'callback' => $callback];

        return true;
    }

    function has_action(string $hook, callable|string|false $callback = false): bool|int
    {
        if (!isset($GLOBALS['novamira_hooks'][$hook])) {
            return false;
        }
        if ($callback === false) {
            return true;
        }
        foreach ($GLOBALS['novamira_hooks'][$hook] as $registered) {
            if ($registered['callback'] === $callback) {
                return $registered['priority'];
            }
        }

        return false;
    }

    function did_action(string $hook): int
    {
        return $GLOBALS['novamira_did'][$hook] ?? 0;
    }

    function do_action(string $hook, mixed ...$args): void
    {
        $GLOBALS['novamira_did'][$hook] = ($GLOBALS['novamira_did'][$hook] ?? 0) + 1;
        $callbacks = $GLOBALS['novamira_hooks'][$hook] ?? [];
        usort($callbacks, static fn(array $a, array $b): int => $a['priority'] <=> $b['priority']);
        foreach ($callbacks as $registered) {
            ($registered['callback'])(...$args);
        }
    }

    function home_url(string $path = ''): string
    {
        return $GLOBALS['novamira_home_url'] . $path;
    }
}

namespace Novamira\OAuth {
    function resource_identifier(): string
    {
        return \home_url('/wp-json/mcp/novamira-oauth');
    }
}

namespace Novamira\CachePurge {
    // Namespaced overrides: the production code calls these unqualified, so these definitions win
    // over the global ones and the runner can record what a real request would have sent.
    function headers_sent(): bool
    {
        return false;
    }

    function header(string $header): void
    {
        $GLOBALS['novamira_headers'][] = $header;
    }
}

namespace {
    /**
     * Core's own behaviour: an update that does not change the stored value fires nothing.
     */
    function novamira_test_update_option(string $option, string $value): void
    {
        $existing = $GLOBALS['novamira_options'][$option] ?? null;
        if ($existing === $value) {
            return;
        }
        $GLOBALS['novamira_options'][$option] = $value;
        do_action($existing === null ? 'added_option' : 'updated_option', $option);
    }

    function novamira_test_delete_option(string $option): void
    {
        if (!array_key_exists($option, $GLOBALS['novamira_options'])) {
            return;
        }
        unset($GLOBALS['novamira_options'][$option]);
        do_action('deleted_option', $option);
    }

    /**
     * @param string|list<string> $urls
     */
    function novamira_test_record(string $api, string|array $urls): void
    {
        foreach ((array) $urls as $url) {
            $GLOBALS['novamira_purge_calls'][$api][] = $url;
        }
    }

    function novamira_test_install_caches(): void
    {
        add_action('litespeed_purge_url', static fn(string $url) => novamira_test_record('litespeed-cache', $url));
        add_action(
            'cache_enabler_clear_page_cache_by_url',
            static fn(string $url) => novamira_test_record('cache-enabler', $url),
        );
        add_action('wpfc_clear_cache_by_url', static fn(string $url) => novamira_test_record('wp-fastest-cache', $url));

        require_once __DIR__ . '/cache-purge-plugins.php';

        $GLOBALS['nginx_purger'] = new NovamiraTestNginxPurger();
    }

    $novamira_scenario = $argv[2];

    if ($novamira_scenario === 'all-caches') {
        novamira_test_install_caches();
    }
    if ($novamira_scenario === 'subdirectory-request') {
        $GLOBALS['novamira_home_url'] = 'https://example.test/subsite';
    }

    require $argv[1] . '/includes/cache-purge.php';
    \Novamira\CachePurge\register();

    add_action(
        'novamira_purged_discovery_urls',
        static fn(array $urls) => novamira_test_record('novamira_purged_discovery_urls', $urls),
    );

    $novamira_deferred = false;

    if ($novamira_scenario === 'early-write') {
        // Abilities are enabled while plugins are still loading, before the cache plugins have
        // published their purge API.
        novamira_test_update_option('novamira_ai_abilities_enabled', '1');
        $novamira_deferred = did_action('novamira_purged_discovery_urls') === 0
            && has_action('plugins_loaded', 'Novamira\\CachePurge\\purge_discovery_urls') !== false;
        novamira_test_install_caches();
    }

    do_action('plugins_loaded');

    switch ($novamira_scenario) {
        case 'all-caches':
        case 'no-caches':
            novamira_test_update_option('novamira_ai_abilities_enabled', '1');
            // A second write of the same value: core fires nothing, so neither do we.
            novamira_test_update_option('novamira_ai_abilities_enabled', '1');
            break;
        case 'unrelated-option':
            novamira_test_update_option('blogname', 'Something else');
            break;
        case 'multiple-writes':
            novamira_test_install_caches();
            $GLOBALS['novamira_options']['novamira_ai_abilities_enabled'] = '1';
            $GLOBALS['novamira_options']['novamira_ai_abilities_domain'] = 'example.test';
            novamira_test_update_option('novamira_ai_abilities_enabled', '0');
            novamira_test_delete_option('novamira_ai_abilities_domain');
            break;
        case 'discovery-request':
            $_SERVER['REQUEST_URI'] = '/.well-known/oauth-protected-resource?ignored=1';
            do_action('init');
            break;
        case 'subdirectory-request':
            $_SERVER['REQUEST_URI'] = '/subsite/.well-known/openid-configuration';
            do_action('init');
            break;
        case 'unrelated-request':
            $_SERVER['REQUEST_URI'] = '/.well-known/apple-app-site-association';
            do_action('init');
            break;
    }

    echo json_encode([
        'purge_events' => did_action('novamira_purged_discovery_urls'),
        'calls' => $GLOBALS['novamira_purge_calls'],
        'deferred_before_plugins_loaded' => $novamira_deferred,
        'urls' => \Novamira\CachePurge\discovery_urls(),
        'donotcachepage' => defined('DONOTCACHEPAGE'),
        'litespeed_nocache' => did_action('litespeed_control_set_nocache'),
        'headers' => $GLOBALS['novamira_headers'],
    ], flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
